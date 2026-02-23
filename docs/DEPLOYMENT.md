# PostfixMe (pfme) - Deployment Guide

This guide covers deploying the PostfixMe project components.

## Prerequisites

- Docker and Docker Compose
- Existing PostfixAdmin installation
- Valid SSL/TLS certificates (for production)
- Access to PostfixAdmin database

## Initial Setup

### 1. Generate JWT Keys

PostfixMe uses RS256 JWT tokens for authentication. For detailed information on JWT configuration and key generation, see [JWT-SETUP.md](JWT-SETUP.md).

Quick generation:

```bash
cd docker/secrets

# Generate private key (2048-bit RSA)
openssl genrsa -out pfme_jwt_private_key.txt 2048

# Generate public key from private key
openssl rsa -in pfme_jwt_private_key.txt -pubout -out pfme_jwt_public_key.txt

# Secure the files
chmod 600 pfme_jwt_private_key.txt
chmod 644 pfme_jwt_public_key.txt
```

### 2. Apply Database Schema

The PostfixMe API requires additional database tables. Apply the schema:

```bash
# The schema will be automatically applied if using the existing schema automation
# Otherwise, manually apply:
mysql -u root -p postfixadmin < schema/mysql/2026/01/001-pfme-initial.sql
```

The schema creates the following tables:

- `pfme_refresh_tokens` - Refresh token storage with revocation support
- `pfme_revoked_tokens` - Revoked access tokens (JTI tracking)
- `pfme_auth_log` - Authentication audit log
- `pfme_auth_log_summary` - Daily auth summary (mailbox + counts)
- `pfme_auth_log_archive` - Archived auth log records (optional)

### 3. Configure Environment

Set the required environment variables and secrets. For complete documentation of all configuration options, see [../README.md#configuration](../README.md#configuration).

Ensure the following secrets exist in `docker/secrets/`:

- `postfixadmin_db_user` - Database username (existing)
- `postfixadmin_db_password` - Database password (existing)
- `pfme_jwt_private_key.txt` - JWT RS256 private key (created in step 1)
- `pfme_jwt_public_key.txt` - JWT RS256 public key (created in step 1)

## Development Deployment

For local development:

```bash
# Build and start all services including pfme-api
./build.sh --clean --start

# The API will be available at:
# http://localhost:8071/api/v1/
```

Development configuration:

- TLS requirement is disabled
- Detailed error messages enabled
- Source code mounted for live reload
- Extended logging

## QA Deployment

For testing and CI:

```bash
# Build QA environment
docker-compose -f docker/docker-compose.yaml -f docker/docker-compose.qa.yaml build

# Run tests
docker-compose -f docker/docker-compose.qa.yaml run --rm pfme-api-tests
```

QA environment features:

- Isolated test database
- Shorter token lifetimes
- No automatic restart
- Test fixtures and mocks

## Production Deployment

### Production Prerequisites

1. Configure reverse proxy with TLS termination
2. Set trusted proxy CIDR ranges
3. Enable TLS requirement
4. Configure appropriate token lifetimes

For all configuration options, see [../README.md#configuration](../README.md#configuration).

**Example production environment settings:**

### Deploy

```bash
# Build production images
./build.sh --clean

# Start services
./start.sh

# Verify services
docker ps | grep pfme
```

### Reverse Proxy Configuration

Configure your reverse proxy (nginx, traefik, etc.) to:

1. Terminate TLS
2. Proxy `/api/v1/*` to the pfme-api service
3. Set appropriate headers:
   - `X-Forwarded-Proto: https`
   - `X-Real-IP: $remote_addr`
   - `X-Forwarded-For: $proxy_add_x_forwarded_for`

Example nginx configuration:

```nginx
location /api/v1/ {
    proxy_pass http://pfme-api:80/api/v1/;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    # API-specific settings
    proxy_buffering off;
    proxy_http_version 1.1;
}
```

#### Multi-Layer Proxy Setup

For infrastructures with multiple proxy layers (e.g., SNI head → hop proxies → Docker nginx → API), ensure:

1. **TLS Termination**: The outermost proxy (SNI head) terminates TLS and sets `X-Forwarded-Proto: https`
2. **Header Preservation**: Each intermediate proxy must preserve (not overwrite) the `X-Forwarded-Proto` header
3. **Trusted Proxy CIDR**: Configure `TRUSTED_PROXY_CIDR` to include the IP address/range of the proxy directly connecting to Docker nginx

The Docker nginx reverse proxy in this stack automatically preserves upstream `X-Forwarded-Proto` headers and falls back to `$scheme` for development mode.

## iOS App Configuration

### Development

1. Open `pfme/ios/PostfixMe.xcodeproj` in Xcode
2. Build and run in iOS Simulator
3. Configure server URL in app settings:
   - For simulator: `http://localhost:8071`
   - For device on same network: `http://<your-ip>:8071`

### Production

1. Update server URL to production API endpoint
2. Ensure TLS is properly configured
3. Build release version via Xcode
4. Archive and distribute via TestFlight or App Store

## Monitoring

### Health Checks

The API includes health checks:

```bash
# Check API health
curl -f http://localhost:8071/api/v1/health

# Check service status
docker ps | grep pfme-api
```

### Logs

View logs for troubleshooting:

```bash
# API logs
docker logs pfme-api

# Follow logs in real-time
docker logs -f pfme-api

# Last 100 lines
docker logs --tail 100 pfme-api
```

### Database Monitoring

Monitor authentication attempts and token usage:

```sql
-- Recent authentication attempts
SELECT * FROM pfme_auth_log
ORDER BY attempted_at DESC
LIMIT 100;

-- Failed login attempts by mailbox
SELECT mailbox, COUNT(*) as attempts
FROM pfme_auth_log
WHERE success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY mailbox
ORDER BY attempts DESC;

-- Active refresh tokens
SELECT COUNT(*) as active_tokens
FROM pfme_refresh_tokens
WHERE expires_at > NOW() AND revoked_at IS NULL;
```

## Maintenance

### Token Cleanup

Expired tokens should be cleaned up periodically:

```bash
# Run cleanup manually
docker exec pfme-api php -r "require 'vendor/autoload.php';
  (new \Pfme\Api\Services\TokenService())->cleanupExpiredTokens();"
```

Or add to cron:

```cron
# Clean up expired tokens daily at 2 AM
0 2 * * * docker exec pfme-api php /path/to/cleanup.php
```

### Auth Log Maintenance

Auth logs are aggregated, retained, and optionally archived by the maintenance job:

```bash
# Run auth log maintenance manually
./deploy.d/pfme-auth-log-maintenance.sh
```

Or add to cron:

```cron
# Aggregate and rotate auth logs daily at 2:15 AM
15 2 * * * /path/to/deploy.d/pfme-auth-log-maintenance.sh
```

### Backup

Back up the JWT keys:

```bash
# Backup JWT keys
cp docker/secrets/pfme_jwt_private_key.txt /secure/backup/location/
cp docker/secrets/pfme_jwt_public_key.txt /secure/backup/location/
```

**Important**: Losing the private key invalidates all issued tokens.

## Troubleshooting

### API Returns 401 Unauthorized

- Check that JWT keys are properly configured
- Verify access token hasn't expired
- Check authentication header format: `Authorization: Bearer <token>`

### TLS Required Error

- Verify `PFME_REQUIRE_TLS` setting matches your environment
- Check reverse proxy is setting `X-Forwarded-Proto: https`
- Verify `TRUSTED_PROXY_CIDR` includes the IP of the proxy directly connecting to Docker nginx
- For multi-layer proxies: Ensure ALL intermediate proxies preserve (not overwrite) the `X-Forwarded-Proto` header from upstream
- Debug by checking API logs for the actual header value received

### Database Connection Errors

- Verify database credentials in secrets files
- Check database service is running: `docker ps | grep db`
- Ensure schema has been applied
- Check database connectivity: `docker exec pfme-api php -r "new PDO(...)"`

### Rate Limiting / Lockout

- Check `pfme_auth_log` table for failed attempts
- Clear lockout by deleting old failed attempts:

  ```sql
  DELETE FROM pfme_auth_log
  WHERE mailbox = 'user@example.com'
  AND success = 0
  AND attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR);
  ```

## Security Considerations

For comprehensive security documentation including JWT configuration, rate limiting, TLS enforcement, and audit log privacy, see [../README.md#security-considerations](../README.md#security-considerations).

**Key deployment checklist:**

- ✓ JWT keys secure and never committed to version control
- ✓ TLS enabled in production (`PFME_REQUIRE_TLS=true`)
- ✓ Rate limiting configured appropriately for your user base
- ✓ Trusted proxy CIDR configured correctly
- ✓ Auth log retention policy set per compliance requirements
- ✓ Database backups in place

## Upgrading

When upgrading PostfixMe:

1. Backup JWT keys and database
2. Pull latest code: `git pull`
3. Apply any new schema changes
4. Rebuild images: `./build.sh --clean`
5. Restart services: `./stop.sh && ./start.sh`
6. Verify API health checks pass
7. Test with mobile app

## Support

For issues:

- Check logs: `docker logs pfme-api`
- Review API documentation: [../README.md](../README.md)
- Review security and configuration: [../README.md#configuration](../README.md#configuration)
- JWT configuration and troubleshooting: [JWT-SETUP.md](JWT-SETUP.md)
- Check GitHub issues

## License

See LICENSE file in project root.

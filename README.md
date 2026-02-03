# PostfixMe API

Mobile-friendly REST API for PostfixAdmin alias management.

## Overview

The PostfixMe API provides a secure JSON REST interface that allows mobile applications to manage email aliases through PostfixAdmin's database. It integrates directly with PostfixAdmin's password verification and configuration.

## Features

- **Authentication**: JWT-based (RS256) with access and refresh tokens
- **Security**: TLS enforcement, rate limiting, account lockout protection
- **Alias Management**: Create, read, update, delete email aliases
- **Scoped Access**: Users can only manage aliases that forward to their mailbox
- **Pagination**: Built-in pagination support for alias listings
- **Audit Logging**: All authentication attempts are logged

## Requirements

- PHP 8.1 or higher
- PostfixAdmin database (MySQL/MariaDB)
- OpenSSL extension
- PDO extension

## Installation

1. Install dependencies:

   ```bash
   cd /path/to/pfme/api
   composer install --no-dev --optimize-autoloader
   ```

2. Generate JWT keys:

   ```bash
   # Generate private key
   openssl genrsa -out pfme_jwt_private.pem 2048

   # Generate public key
   openssl rsa -in pfme_jwt_private.pem -pubout -out pfme_jwt_public.pem
   ```

3. Place keys in Docker secrets:

   ```bash
   cp pfme_jwt_private.pem docker/secrets/pfme_jwt_private_key
   cp pfme_jwt_public.pem docker/secrets/pfme_jwt_public_key
   ```

## Configuration

The API uses environment variables for configuration. All secrets follow the `*_FILE` pattern pointing to `/run/secrets/...`

### Required Environment Variables

| Variable | Default | Description |
| -------- | ------- | ----------- |
| `POSTFIXADMIN_DB_HOST` | `db` | Database hostname |
| `POSTFIXADMIN_DB_PORT` | `3306` | Database port |
| `POSTFIXADMIN_DB_NAME` | `postfixadmin` | Database name |
| `POSTFIXADMIN_DB_USER_FILE` | `/run/secrets/postfixadmin_db_user` | Database user secret file |
| `POSTFIXADMIN_DB_PASSWORD_FILE` | `/run/secrets/postfixadmin_db_password` | Database password secret file |

### JWT Configuration

| Variable | Default | Description |
| -------- | ------- | ----------- |
| `PFME_JWT_PRIVATE_KEY_FILE` | `/run/secrets/pfme_jwt_private_key` | RS256 private key file |
| `PFME_JWT_PUBLIC_KEY_FILE` | `/run/secrets/pfme_jwt_public_key` | RS256 public key file |
| `PFME_ACCESS_TOKEN_TTL` | `900` | Access token lifetime (seconds, default 15 min) |
| `PFME_REFRESH_TOKEN_TTL` | `157680000` | Refresh token lifetime (seconds, default 5 years) |
| `PFME_JWT_ISSUER` | `pfme-api` | JWT issuer claim |
| `PFME_JWT_AUDIENCE` | `pfme-mobile` | JWT audience claim |

### Security Configuration

| Variable | Default | Description |
| -------- | ------- | ----------- |
| `TRUSTED_PROXY_CIDR` | `` | Comma-separated CIDR blocks for trusted proxies |
| `TRUSTED_TLS_HEADER_NAME` | `X-Forwarded-Proto` | TLS proxy header name |
| `PFME_REQUIRE_TLS` | `true` | Enforce TLS connections |
| `PFME_RATE_LIMIT_ATTEMPTS` | `5` | Max failed auth attempts in window |
| `PFME_RATE_LIMIT_WINDOW` | `300` | Rate limit window (seconds, 5 min) |
| `PFME_LOCKOUT_THRESHOLD` | `10` | Failed attempts before lockout |
| `PFME_LOCKOUT_DURATION` | `1800` | Lockout duration (seconds, 30 min) |

### Optional Configuration

| Variable | Default | Description |
| -------- | ------- | ----------- |
| `APP_ENV` | `production` | Application environment (development/production) |

## API Endpoints

### Authentication

#### POST /api/v1/auth/login

Authenticate with mailbox credentials.

**Request:**

```json
{
  "mailbox": "user@example.com",
  "password": "secret",
  "device_id": "optional-device-identifier"
}
```

**Response (200):**

```json
{
  "access_token": "eyJ...",
  "refresh_token": "abc123...",
  "token_type": "Bearer",
  "expires_in": 900,
  "user": {
    "mailbox": "user@example.com",
    "domain": "example.com"
  }
}
```

#### POST /api/v1/auth/logout

Revoke current access token (requires authentication).

**Headers:** `Authorization: Bearer <token>`

**Response (200):**

```json
{
  "message": "Logged out successfully"
}
```

#### POST /api/v1/auth/refresh

Rotate tokens using refresh token (requires authentication).

**Headers:** `Authorization: Bearer <token>`

**Request:**

```json
{
  "refresh_token": "abc123..."
}
```

**Response (200):**

```json
{
  "access_token": "eyJ...",
  "refresh_token": "xyz789...",
  "token_type": "Bearer",
  "expires_in": 900
}
```

### Alias Management

All alias endpoints require authentication via `Authorization: Bearer <token>` header.

#### GET /api/v1/aliases

List aliases forwarding to authenticated user's mailbox.

**Query Parameters:**

- `q` - Search by local-part (optional)
- `status` - Filter by status: `active`, `inactive` (optional)
- `page` - Page number (default: 1)
- `per_page` - Results per page (default: 20, max: 100)
- `sort` - Sort by: `address`, `created`, `modified` (default: `address`)

**Response (200):**

```json
{
  "data": [
    {
      "id": 1,
      "local_part": "alias",
      "domain": "example.com",
      "address": "alias@example.com",
      "destinations": ["user@example.com", "other@example.com"],
      "active": true,
      "created": "2026-01-01 12:00:00",
      "modified": "2026-01-10 14:30:00"
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 45,
    "total_pages": 3
  }
}
```

#### POST /api/v1/aliases

Create a new alias.

**Request:**

```json
{
  "local_part": "newalias",
  "destinations": ["user@example.com", "other@example.com"]
}
```

**Constraints:**

- Authenticated user's mailbox MUST be in destinations
- Domain is implied from authenticated user
- Local part must not already exist

**Response (201):**

```json
{
  "id": 2,
  "local_part": "newalias",
  "domain": "example.com",
  "address": "newalias@example.com",
  "destinations": ["user@example.com", "other@example.com"],
  "active": true,
  "created": "2026-01-12 10:15:00",
  "modified": null
}
```

#### PUT /api/v1/aliases/{id}

Update an existing alias.

**Request:**

```json
{
  "local_part": "renamed",
  "destinations": ["user@example.com"],
  "active": false
}
```

All fields are optional. Provide only fields to update.

**Response (200):** Same format as create response.

#### DELETE /api/v1/aliases/{id}

Delete an alias.

**Constraints:**

- Alias must be inactive (active: false) before deletion
- Returns 409 Conflict if still active

**Response (200):**

```json
{
  "message": "Alias deleted successfully"
}
```

## Error Responses

All errors follow this format:

```json
{
  "code": "error_code",
  "message": "Human-readable error message",
  "details": {}
}
```

Common error codes:

- `invalid_input` - Missing or malformed request data
- `unauthorized` - Missing or invalid authentication
- `invalid_credentials` - Login failed
- `not_found` - Resource not found
- `tls_required` - TLS connection required
- `rate_limit_exceeded` - Too many requests

## Development

### Running Tests

```bash
composer test
```

### Static Analysis

```bash
composer phpstan
```

### Code Style

```bash
composer phpcs
```

## Database Schema

The API creates three additional tables in the PostfixAdmin database:

- `pfme_refresh_tokens` - Stores refresh tokens with revocation support
- `pfme_revoked_tokens` - Tracks revoked access tokens (JTI)
- `pfme_auth_log` - Audit log for authentication attempts

Schema files are located in `schema/mysql/2026/01/001-pfme-initial.sql` and are applied via the existing schema automation.

## Security Considerations

1. **TLS Only**: The API enforces TLS by default. Only disable for local development.
2. **Trusted Proxies**: Configure `TRUSTED_PROXY_CIDR` to validate TLS headers from reverse proxies.
3. **Rate Limiting**: Failed authentication attempts are rate-limited per mailbox.
4. **Account Lockout**: Accounts are temporarily locked after excessive failures.
5. **Token Revocation**: Both access and refresh tokens support server-side revocation.
6. **Audit Logging**: All authentication attempts are logged with IP and user agent.

## License

See LICENSE file in project root.

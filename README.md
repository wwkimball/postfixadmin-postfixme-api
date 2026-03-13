# PostfixMe API

Mobile-friendly REST API for [PostfixAdmin](https://github.com/postfixadmin/postfixadmin) alias and mailbox password
management.  This API was originally developed to support the PostfixMe mobile app but can be used by others.

This is a container-based overlay for the [PostfixAdmin Docker image](https://hub.docker.com/_/postfixadmin), not a
plugin for PostfixAdmin.  You cannot use PostfixMe without PostfixAdmin.

## Why Use the PostfixMe API

You may enjoy using the PostfixMe API when any of the following are true:

1. **Your mail server runs postfix.**  This isn't just for DIY (Do It Yourself) mail server administrators.  If you're
   running postfix, you should also be running PostfixAdmin, especially if you have more than one mailbox and/or domain.
   Adding the PostfixMe API adds additional capabilities to PostfixAdmin that are valuable for the other reasons, here.
2. **You have users.**  Users today are less inclined to sit at a PC to manage their own mailbox passwords and mailbox
   aliases.  Out of the box, PostfixAdmin requires users to log into its web-based interface, which performs poorly on
   small mobile devices.  At the time of this writing, that interface is rudimentary and incomplete for
   non-administrators, further driving non-administrators away from using it.  How much time can you take back if you
   aren't forced to log into the PostfixAdmin interface as an administrator to handle your user requests?  Paired with a
   mobile app that employs the PostfixMe API, this offloads that demand, enabling your users to self-manage their own
   mailbox passwords and aliases, freeing your mail server administrator(s) of that burden.
3. **You hate spam.**  Mailbox aliases are an anti-spam tool.  When you create and use a unique email alias for every
   point of contact, you take immediate and direct control over who can send you email and when.  The moment you see
   spam to any of your aliases, you can disable/delete the compromosed alias and know with irrefutible certainty who
   sold you out.  This is "throwaway email addresses" at scale, trivial to create, trivial to disable/destroy.

## Overview

The PostfixMe API provides a secure JSON REST interface that allows mobile applications to manage email aliases and
mailbox passwords for individual users through PostfixAdmin's database.  It directly utilizes PostfixAdmin's password
verification and configuration code.  This does **not** extend all of the capabilities of PostfixAdmin.  Rather, this
API enables end-users to self-manage their own mailbox aliases and mailbox passwords via mobile app, taking such load
off of your mail server administrator(s).

## Features

- **Authentication**:  JWT-based (RS256) with access and refresh tokens
- **Security**:  TLS enforcement, rate limiting, account lockout protection
- **Alias Management**:  Create, read, update, delete email aliases
- **Mailbox Password Management**:  Users can change their own mailbox password with adherence to
  administrator-specified password rules
- **Scoped Alias Access**:  Users can only manage aliases that forward to their own mailbox
- **Pagination**:  Built-in pagination support for alias listings
- **Audit Logging**:  All authentication attempts are logged

## Requirements

If you use Docker with Docker Compose, that's all you need.  This project is
tuned for container-based deployments along with PostfixAdmin's official Docker
image.  A [minimally viable Docker Compose stack](docker/) is provided as an
example for how to stand up the PostfixMe API alongside PostfixAdmin.  This is
the only supported deployment method.

If however, you'd rather do this your own way on a bare metal server or virtual
machine, you'll need at least:

- PHP 8.1 or higher to run the API
- A running PostfixAdmin database (PostgreSQL, MySQL/MariaDB, or SQLite)
- PDO extension for PHP so that the API can talk to the PostfixAdmin database
- OpenSSL extension for generating the mandatory JWT keys
- A reverse proxy and valid TLS certificates to handle HTTPS; the API will *not*
  handle TLS termination for you but it will check for and require TLS

This is not supported but with sufficient skill, you can probably get it to work.

## Installation

The PostfixMe API is intended for container-based deployment.  Environment variables, JWT key generation, and other
secret setup must be completed before deployment.

Manual installation outside of containers is not supported, though it should be reasonably simple to accomplish with
sufficient investment in automation scripts.

## Docker Build

This project provides an example Docker implementation, illustrating one possible way to deploy it along with
PostfixAdmin.  You can try out this sample Docker Compose stack very quickly by performing the following quickstart
steps:

1. Clone this project with Git submodule support (use the `--recursive` flag with your `clone` operation).  This project
   employs [a library of generally useful Bash shell scripts](https://github.com/wwkimball/shell-script-lib) which --
   among other things -- simplifies otherwise very complex Docker build and deployment tasks.
2. Run the [generate-sample-secrets.sh](docker/scripts/generate-sample-secrets.sh) shell script to automatically
   generate a set of environment variable (`.env`) files with minimum viable configuration including randomized secrets.
   Review the output files (`docker/*.env*`) to learn what values were generated for you.  These files are named for the
   service and deployment stage their values apply to.
3. Build and start the Docker Compose stack:  `./build.sh --start`.
4. Experiment with the RESTful API per the documentation below.  It will be accessible at `localhost` port `8080` unless
   you modify the Docker Compose YAML to change the port.  Test data will have been loaded already, which you can use to
   authenticate and otherwise explore any of several test user accounts.  The test data accounts [are documented with
   the seed data](test-data/seeds/README.md).

Do not publish these example Docker containers.  These are provided only for you to learn from in order to design and
deploy your own private implementation of this PostfixMe API.

The resuling example Docker Compose stack features:

1. PostfixAdmin (configured for use with a MariaDB database)
2. PostfixMe RESTful API server (shares code with the PostfixAdmin service)
3. MariaDB database server
4. NGINX reverse proxy (single entry point for both PostfixAdmin and PostfixMe)

You are free to use the example to learn how to configure PostfixMe API for use with your existing PostfixAdmin
installation, or for how to create your own Docker Compose stack which integrates them together.  The example provided
here is *not* a production-ready configuration!  Rather, it is merely a minimally-viable example for a development
environment.

## Development

### Testing

Unit tests are located in `tests/Unit/` and use PHPUnit.  The test suite validates API endpoints, authentication logic,
database operations, and security controls.

Unit and integration testing is available via a single command.  After you've run the initial setup above (at least
steps 1 and 2), you can run the full gammut of tests via the `run-tests.sh` shell script.

### Code Quality

The codebase uses PHPStan (static analysis) and PHPCS (code style checking) to maintain quality standards.  Configure
these tools in your development environment as needed.

### Development Notes

- JWT keys must be configured before running the API
- Database connection requires access to PostfixAdmin's database
- All endpoints expect and return JSON
- Rate limiting and account lockout features require persistent storage

## Database Schema

The API requires additional tables in the PostfixAdmin database:

- `pfme_refresh_tokens` - Stores refresh tokens with revocation support
- `pfme_revoked_tokens` - Tracks revoked access tokens (JTI)
- `pfme_auth_log` - Audit log for authentication attempts
- `pfme_auth_log_summary` - Daily auth summary (mailbox + counts)
- `pfme_auth_log_archive` - Archived auth log records (optional)

For complete schema documentation including table structures, indexes, and migration procedures, see:

- [Database Platform Support](docs/README-Supported_Database_Platforms.md)
- [MySQL/MariaDB Schema](docs/README-MySQL.md)
- [PostgreSQL Schema](docs/README-PostgreSQL.md)
- [SQLite Schema](docs/README-SQLite.md)

## Security Considerations

1. **TLS Only**:  The API enforces TLS by default.  Only disable for local development.
2. **Trusted Proxies**:  Configure `TRUSTED_PROXY_CIDR` to validate TLS headers from reverse proxies.
3. **Rate Limiting**:  Failed authentication attempts are rate-limited per mailbox.
4. **Account Lockout**:  Accounts are temporarily locked after excessive failures.
5. **Token Revocation**:  Both access and refresh tokens support server-side revocation.
6. **Audit Logging**:  All authentication attempts are logged with IP and user agent.

## Auth Log Retention & Privacy

PostfixMe logs authentication attempts to protect accounts (rate limiting, lockout, and incident investigation).
Detailed auth logs contain mailbox, timestamp, success/failure, IP address, and user agent.  To balance the need for a
high degree of specificity during security incident response with user privacy concerns, these logs are anonymized after
a configurable timespan and fully deleted after another timespan provided you implement the necessary scheduler and
cleansing script, which is documented in detail in the `docs/` directory.

**Behavior**:

- **Summary**:  Stores only mailbox + daily counts (no IP or user agent).
- **Retention**:  Deletes detailed logs older than the retention window.
- **Archive (optional)**:  Moves detailed logs into `pfme_auth_log_archive` before deletion and prunes the archive by
  its own retention window.

**Compliance Notes** (confirm with your compliance team):

- **GDPR**:  No fixed retention; use data minimization (typical 30–90 days detailed logs).
- **PCI DSS**:  12 months retention, 3 months immediately available (example: retention 90 days + archive 365 days).
- **HIPAA**:  No specific auth-log duration; many organizations align to 6 years for policy retention (archive 2190 days).
- **SOC 2 / ISO 27001**:  No prescriptive duration; adopt a documented policy (often 90–180 days detailed + summaries
  long-term).

## Configuration

The API uses environment variables for configuration.  All secrets follow the `*_FILE` pattern.

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
| `PFME_PASSWORD_MIN_LENGTH` | `10` | Minimum passphrase length (8-64 recommended) |
| `PFME_PASSWORD_REQUIRE_SPACE` | `true` | Require at least one space in passphrase |
| `PFME_PASSWORD_REQUIRE_GRAMMAR_SYMBOL` | `true` | Require grammar symbol (. , ! ? ; : ' " - etc.) |

### Auth Log Configuration

| Variable | Default | Description |
| -------- | ------- | ----------- |
| `PFME_AUTH_LOG_RETENTION_DAYS` | `90` | Detailed log retention (days) |
| `PFME_AUTH_LOG_SUMMARY_ENABLED` | `true` | Enable daily auth summary aggregation |
| `PFME_AUTH_LOG_SUMMARY_LAG_DAYS` | `1` | Days before aggregating logs into summary |
| `PFME_AUTH_LOG_ARCHIVE_ENABLED` | `false` | Enable archiving of detailed logs before deletion |
| `PFME_AUTH_LOG_ARCHIVE_RETENTION_DAYS` | `365` | Archive retention (days) |

### Optional Configuration

| Variable | Default | Description |
| -------- | ------- | ----------- |
| `DEPLOYMENT_STAGE` | `production` | Application environment (development/qa/lab/staging/production) |

## API Endpoints

### Health

#### GET /api/v1/health

Basic health check.

**Response (200):**

```json
{
  "status": "ok",
  "timestamp": 1700000000
}
```

### Authentication

#### POST /api/v1/auth/login

Authenticate with mailbox credentials.

**Request:**

```json
{
  "mailbox": "user@example.com",
  "password": "secret"
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

Revoke all tokens for the authenticated mailbox (requires authentication).

**Headers:** `Authorization: Bearer <token>`

**Response (200):**

```json
{
  "message": "Logged out successfully"
}
```

#### POST /api/v1/auth/refresh

Rotate tokens using refresh token (no access token required).

**Request:**

#### GET /api/v1/auth/password-policy

Returns the active password policy requirements.

**Response (200):**

```json
{
  "min_length": 10,
  "require_space": true,
  "require_grammar_symbol": true,
  "grammar_symbols": ". , ! ? ; : ' \" - ( ) [ ] { } @ # $ % ^ & *"
}
```

#### GET /api/v1/destinations

List available destination mailboxes for the authenticated user.

**Response (200):**

```json
{
  "data": ["user@example.com", "admin@example.com"]
}
```

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

All fields are optional.  Provide only fields to update.

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

## License

PostfixMe API is free software licensed under the GNU General Public License v2 or later (GPL-2.0-or-later).

Copyright (c) 2026 William W. Kimball, Jr., MBA, MSIS

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the LICENSE file for full details.

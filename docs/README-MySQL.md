# PostfixMe API Database Schema - MySQL/MariaDB

This document describes the required database schema for the PostfixMe API when using MySQL or MariaDB as the database backend.

## Overview

The PostfixMe API extends the standard PostfixAdmin database schema with additional tables to support:

- JWT-based authentication with access and refresh tokens
- Token rotation and revocation for enhanced security
- Authentication audit logging and rate limiting
- Password change tracking for token invalidation

## Prerequisites

- MySQL 5.7+ or MariaDB 10.3+
- An existing PostfixAdmin database installation
- The `postfixadmin` database must be accessible
- A database user with appropriate privileges

## Required Tables

### 1. pfme_refresh_tokens

Stores refresh tokens for JWT authentication with automatic refresh token rotation support.

```sql
CREATE TABLE IF NOT EXISTS pfme_refresh_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    mailbox VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    family_id VARCHAR(64) DEFAULT NULL,
    rotated_from VARCHAR(64) DEFAULT NULL,
    rotated_to VARCHAR(64) DEFAULT NULL,
    rotated_at DATETIME DEFAULT NULL,
    INDEX idx_mailbox (mailbox),
    INDEX idx_token (token),
    INDEX idx_expires (expires_at),
    INDEX idx_family_id (family_id),
    INDEX idx_last_used (last_used_at),
    INDEX idx_rotated_from (rotated_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Purpose:**

- Maintains long-lived refresh tokens for client authentication
- Supports token rotation chains via `family_id` for detecting token reuse attacks
- Tracks token lifecycle from creation through rotation to revocation

**Key Columns:**

- `token`: 64-character unique token identifier (SHA-256 hash)
- `mailbox`: The email address (mailbox) associated with this token
- `expires_at`: Token expiration timestamp (default: 5 years from creation)
- `created_at`: Timestamp when token was originally created
- `last_used_at`: Timestamp of most recent token use (updated on refresh)
- `revoked_at`: Timestamp when token was explicitly revoked (NULL if active)
- `family_id`: Links tokens in a rotation chain for replay attack detection
- `rotated_from`: Previous token in the rotation chain
- `rotated_to`: Next token that replaced this one
- `rotated_at`: Timestamp when this token was rotated

**Security Features:**

- Token rotation creates new tokens and invalidates old ones
- Family tracking enables detection of token reuse (replay attacks)
- Explicit revocation support for logout and security events

### 2. pfme_revoked_tokens

Stores JTI (JWT ID) values of explicitly revoked access tokens to prevent their use before natural expiration.

```sql
CREATE TABLE IF NOT EXISTS pfme_revoked_tokens (
    jti VARCHAR(32) PRIMARY KEY,
    revoked_at DATETIME NOT NULL,
    INDEX idx_revoked_at (revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Purpose:**

- Blacklists access tokens that have been explicitly revoked
- Enables immediate token invalidation without waiting for natural expiration
- Supports security events like forced logout or compromised token detection

**Key Columns:**

- `jti`: JWT ID claim from the access token (unique identifier)
- `revoked_at`: Timestamp when the token was revoked

**Maintenance:**

- Old entries (revoked tokens past their expiration time) should be periodically purged
- Recommended retention: 24 hours past the maximum access token TTL
- Default access token TTL: 900 seconds (15 minutes)

### 3. pfme_auth_log

Records all authentication attempts for audit purposes and rate limiting.

```sql
CREATE TABLE IF NOT EXISTS pfme_auth_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mailbox VARCHAR(255) NOT NULL,
    success BOOLEAN NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    attempted_at DATETIME NOT NULL,
    INDEX idx_mailbox (mailbox),
    INDEX idx_attempted (attempted_at),
    INDEX idx_success (success)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Purpose:**

- Maintains comprehensive audit trail of authentication events
- Supports rate limiting by tracking failed login attempts
- Enables account lockout protection after repeated failures
- Provides forensic data for security investigations

**Key Columns:**

- `mailbox`: The email address attempting authentication
- `success`: Boolean indicating whether authentication succeeded
- `ip_address`: Client IP address (IPv4 or IPv6, max 45 characters)
- `user_agent`: Client User-Agent string for device identification
- `attempted_at`: Timestamp of the authentication attempt

**Rate Limiting:**

- Tracks failed attempts within a configurable time window
- Default: 5 failed attempts in 300 seconds (5 minutes) triggers rate limiting
- Default: 10 failed attempts triggers account lockout for 1800 seconds (30 minutes)

**Maintenance:**

- This table can grow large over time and should be archived periodically
- See `pfme_auth_log_archive` and `pfme_auth_log_summary` for archival strategy

### 4. pfme_auth_log_summary

Aggregated summary of authentication attempts by mailbox and date for efficient historical reporting.

```sql
CREATE TABLE IF NOT EXISTS pfme_auth_log_summary (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mailbox VARCHAR(255) NOT NULL,
    summary_date DATE NOT NULL,
    failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    successful_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_mailbox_date (mailbox, summary_date),
    INDEX idx_summary_date (summary_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Purpose:**

- Provides daily aggregated authentication statistics
- Enables efficient historical reporting without scanning full audit log
- Reduces storage requirements by summarizing old log entries

**Key Columns:**

- `mailbox`: The email address for this summary
- `summary_date`: The date this summary covers
- `failed_attempts`: Total failed authentication attempts on this date
- `successful_attempts`: Total successful authentication attempts on this date
- `created_at`: Timestamp when this summary was first created
- `updated_at`: Timestamp of the last update to this summary

**Maintenance:**

- Summary records are created/updated by a maintenance script
- Typically run daily to summarize previous day's authentication activity
- See deployment documentation for scheduling maintenance tasks

### 5. pfme_auth_log_archive

Long-term storage for authentication log entries that have been summarized and removed from the active log.

```sql
CREATE TABLE IF NOT EXISTS pfme_auth_log_archive (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mailbox VARCHAR(255) NOT NULL,
    success BOOLEAN NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    attempted_at DATETIME NOT NULL,
    archived_at DATETIME NOT NULL,
    INDEX idx_mailbox (mailbox),
    INDEX idx_attempted (attempted_at),
    INDEX idx_success (success),
    INDEX idx_archived (archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Purpose:**

- Archives historical authentication log entries
- Preserves detailed audit trail while keeping active log table lean
- Supports long-term forensic investigations and compliance requirements

**Key Columns:**

- Same schema as `pfme_auth_log` with addition of:
- `archived_at`: Timestamp when the log entry was moved to archive

**Maintenance:**

- Entries are moved from `pfme_auth_log` to archive by maintenance script
- Recommended: Archive entries older than 90 days
- Archive retention policy should match organizational compliance requirements

### 6. pfme_mailbox_security

Tracks password change events to enable automatic invalidation of existing access tokens.

```sql
CREATE TABLE IF NOT EXISTS pfme_mailbox_security (
    mailbox VARCHAR(255) PRIMARY KEY,
    password_changed_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_password_changed (password_changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Purpose:**

- Records when mailbox passwords are changed
- Enables token validation to reject access tokens issued before password change
- Provides additional security layer beyond token expiration

**Key Columns:**

- `mailbox`: The email address (primary key)
- `password_changed_at`: Timestamp of the most recent password change
- `updated_at`: Timestamp when this record was last updated

**Usage:**

- Updated whenever a user changes their password
- Access token validation checks if token was issued before `password_changed_at`
- Forces re-authentication after password change without explicit token revocation

## Character Set and Collation

All PostfixMe tables use:

- **Character Set:** `utf8mb4` (full Unicode support including emoji)
- **Collation:** `utf8mb4_unicode_ci` (case-insensitive Unicode collation)

This ensures proper handling of international characters in email addresses and user agent strings.

## Storage Engine

All tables use **InnoDB** for:

- ACID transaction support
- Row-level locking for better concurrency
- Foreign key constraint support (if needed in future)
- Crash recovery capabilities

## Schema Versioning

The PostfixMe schema follows the same versioning approach as PostfixAdmin using Schema Description Files (DDL):

- Schema files are located in `/schema/mysql/YYYY/MM/`
- Files follow the naming convention: `YYYYMMDD-N.template.sql`
- Each forward migration has a corresponding rollback: `YYYYMMDD-N.rollback.template.sql`
- Schema version is tracked in the PostfixAdmin `settings` table

## Initial Schema Deployment

The PostfixMe schema tables are created automatically during Docker container initialization. The schema files are processed in chronological order:

1. `20260112-1.template.sql` - Creates core PostfixMe tables
2. `20260130-1.template.sql` - Adds token rotation support
3. `20260204-1.template.sql` - Adds auth log summary and archive tables
4. `20260206-1.template.sql` - Adds mailbox security tracking
5. `20260217-1.template.sql` - Removes unused device_id column

See `/schema/mysql/` in the project root for the complete schema history.

## Maintenance Tasks

### Token Cleanup

Remove expired refresh tokens and revoked access tokens that have passed their expiration time:

```bash
# See deploy.d/pfme-cleanup-tokens.sh for automated cleanup implementation
```

Recommended frequency: Daily

### Auth Log Maintenance

Summarize and archive old authentication log entries:

```bash
# See deploy.d/pfme-auth-log-maintenance.sh for automated maintenance implementation
```

Recommended frequency: Daily

## Database User Privileges

The PostfixMe API database user needs the following privileges on the PostfixAdmin database:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON postfixadmin.pfme_* TO 'pfme_user'@'%';
GRANT SELECT ON postfixadmin.mailbox TO 'pfme_user'@'%';
GRANT SELECT ON postfixadmin.alias TO 'pfme_user'@'%';
```

Note: The API uses the same database user as PostfixAdmin (`postfixadmin_db_user`) with full privileges by default.

## Index Strategy

Indexes are carefully chosen to optimize common query patterns:

1. **Refresh Token Queries:**
   - Lookup by token value (UNIQUE index on `token`)
   - Lookup by mailbox for token listing
   - Cleanup queries by expiration date
   - Token rotation chain traversal via `family_id`

2. **Auth Log Queries:**
   - Rate limiting checks by mailbox and time window
   - Audit queries by mailbox
   - Time-based cleanup and archival operations

3. **Revoked Token Queries:**
   - Fast JTI validation (PRIMARY KEY)
   - Time-based cleanup

## Performance Considerations

- **Connection Pooling:** Use persistent database connections when possible
- **Index Usage:** EXPLAIN queries periodically to verify index utilization
- **Table Growth:** Monitor `pfme_auth_log` size; implement archival before reaching 1M+ rows
- **Query Optimization:** Limit time window queries to specific date ranges
- **Partitioning:** Consider partitioning `pfme_auth_log_archive` by date for very large datasets

## Security Considerations

1. **Token Storage:** Refresh tokens are stored as SHA-256 hashes, never plaintext
2. **Access Control:** Ensure database user has minimal required privileges
3. **Connection Encryption:** Use TLS for database connections in production
4. **Backup Security:** Encrypt database backups (they contain sensitive auth data)
5. **Audit Retention:** Define clear data retention policies for compliance

## Migration from Earlier Versions

If upgrading from an earlier PostfixMe deployment, the schema migration scripts handle all necessary changes:

- **device_id Removal (20260217-1):** The `device_id` column is removed as it was never used
- **Token Rotation (20260130-1):** Existing tokens remain valid; rotation applies to new tokens

See rollback scripts (`*.rollback.template.sql`) for downgrade procedures if needed.

## Troubleshooting

### Connection Issues

Check database connectivity from the API container:

```bash
docker exec -it pfme-php-api mysql -h db -u postfixadmin_user -p postfixadmin
```

### Schema Verification

Verify all required tables exist:

```sql
USE postfixadmin;
SHOW TABLES LIKE 'pfme_%';
```

Expected output: Six tables (`pfme_refresh_tokens`, `pfme_revoked_tokens`, `pfme_auth_log`, `pfme_auth_log_summary`, `pfme_auth_log_archive`, `pfme_mailbox_security`)

### Index Verification

Check that all indexes are properly created:

```sql
SHOW INDEX FROM pfme_refresh_tokens;
SHOW INDEX FROM pfme_auth_log;
```

## Related Documentation

- [PostfixMe API Documentation](../README.md)
- [JWT Configuration](../../../docs/README-JWT.md)
- [Deployment Guide](../../../docs/DEPLOYMENT-PFME.md)
- [MySQL Schema Automation](../../../lib/database/README-MySQL.md)
- [PostfixAdmin Documentation](https://github.com/postfixadmin/postfixadmin)

## License

Copyright 2025, 2026 William W. Kimball, Jr. MBA MSIS

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

```text
http://www.apache.org/licenses/LICENSE-2.0
```

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.

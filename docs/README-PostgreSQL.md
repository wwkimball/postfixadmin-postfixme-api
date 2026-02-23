# PostfixMe API Database Schema - PostgreSQL

This document describes the required database schema for the PostfixMe API when using PostgreSQL as the database backend.

## Overview

The PostfixMe API extends the standard PostfixAdmin database schema with additional tables to support:

- JWT-based authentication with access and refresh tokens
- Token rotation and revocation for enhanced security
- Authentication audit logging and rate limiting
- Password change tracking for token invalidation

## Prerequisites

- PostgreSQL 10+
- An existing PostfixAdmin database installation
- The `postfixadmin` database must be accessible
- A database user with appropriate privileges

## Required Tables

### 1. pfme_refresh_tokens

Stores refresh tokens for JWT authentication with automatic refresh token rotation support.

```sql
CREATE TABLE IF NOT EXISTS pfme_refresh_tokens (
    id BIGSERIAL PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    mailbox VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    last_used_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NULL,
    revoked_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NULL,
    family_id VARCHAR(64) DEFAULT NULL,
    rotated_from VARCHAR(64) DEFAULT NULL,
    rotated_to VARCHAR(64) DEFAULT NULL,
    rotated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NULL
);

CREATE INDEX idx_pfme_refresh_tokens_mailbox ON pfme_refresh_tokens (mailbox);
CREATE INDEX idx_pfme_refresh_tokens_token ON pfme_refresh_tokens (token);
CREATE INDEX idx_pfme_refresh_tokens_expires ON pfme_refresh_tokens (expires_at);
CREATE INDEX idx_pfme_refresh_tokens_family_id ON pfme_refresh_tokens (family_id);
CREATE INDEX idx_pfme_refresh_tokens_last_used ON pfme_refresh_tokens (last_used_at);
CREATE INDEX idx_pfme_refresh_tokens_rotated_from ON pfme_refresh_tokens (rotated_from);
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
    revoked_at TIMESTAMP WITHOUT TIME ZONE NOT NULL
);

CREATE INDEX idx_pfme_revoked_tokens_revoked_at ON pfme_revoked_tokens (revoked_at);
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
    id BIGSERIAL PRIMARY KEY,
    mailbox VARCHAR(255) NOT NULL,
    success BOOLEAN NOT NULL DEFAULT FALSE,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    attempted_at TIMESTAMP WITHOUT TIME ZONE NOT NULL
);

CREATE INDEX idx_pfme_auth_log_mailbox ON pfme_auth_log (mailbox);
CREATE INDEX idx_pfme_auth_log_attempted ON pfme_auth_log (attempted_at);
CREATE INDEX idx_pfme_auth_log_success ON pfme_auth_log (success);
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
    id BIGSERIAL PRIMARY KEY,
    mailbox VARCHAR(255) NOT NULL,
    summary_date DATE NOT NULL,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    successful_attempts INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    CONSTRAINT uniq_mailbox_date UNIQUE (mailbox, summary_date)
);

CREATE INDEX idx_pfme_auth_log_summary_date ON pfme_auth_log_summary (summary_date);
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
    id BIGSERIAL PRIMARY KEY,
    mailbox VARCHAR(255) NOT NULL,
    success BOOLEAN NOT NULL DEFAULT FALSE,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    attempted_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    archived_at TIMESTAMP WITHOUT TIME ZONE NOT NULL
);

CREATE INDEX idx_pfme_auth_log_archive_mailbox ON pfme_auth_log_archive (mailbox);
CREATE INDEX idx_pfme_auth_log_archive_attempted ON pfme_auth_log_archive (attempted_at);
CREATE INDEX idx_pfme_auth_log_archive_success ON pfme_auth_log_archive (success);
CREATE INDEX idx_pfme_auth_log_archive_archived ON pfme_auth_log_archive (archived_at);
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
    password_changed_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL
);

CREATE INDEX idx_pfme_mailbox_security_password_changed ON pfme_mailbox_security (password_changed_at);
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

## Data Types

PostgreSQL-specific data type choices:

- **BIGSERIAL:** Auto-incrementing 64-bit integer (equivalent to MySQL's BIGINT UNSIGNED AUTO_INCREMENT)
- **VARCHAR(n):** Variable-length character string with maximum length
- **TEXT:** Variable-length character string without length limit
- **TIMESTAMP WITHOUT TIME ZONE:** Timestamp without timezone information (stores UTC)
- **BOOLEAN:** True/false values (PostgreSQL native boolean type)
- **INTEGER:** 32-bit signed integer (sufficient for count columns)
- **DATE:** Calendar date (no time component)

## Schema Versioning

The PostfixMe schema follows the same versioning approach as PostfixAdmin using Schema Description Files (DDL):

- Schema files are located in `/schema/postgresql/YYYY/MM/`
- Files follow the naming convention: `YYYYMMDD-N.ddl`
- Each forward migration has a corresponding rollback: `YYYYMMDD-N.rollback.ddl`
- Schema version is tracked in the PostfixAdmin `settings` table

**Note:** While MySQL schema files exist in this project, PostgreSQL schema files would need to be created following the same structure and conventions. The SQL syntax differences are minimal (primarily data type names and auto-increment syntax).

## Initial Schema Deployment

For PostgreSQL deployments, create equivalent DDL files based on the MySQL schema:

1. Convert `BIGINT UNSIGNED AUTO_INCREMENT` to `BIGSERIAL`
2. Convert `DATETIME` to `TIMESTAMP WITHOUT TIME ZONE`
3. Convert `INT UNSIGNED` to `INTEGER`
4. Convert `BOOLEAN NOT NULL DEFAULT 0` to `BOOLEAN NOT NULL DEFAULT FALSE`
5. Adjust index creation syntax (indexes are created separately, not inline)
6. Convert `ENGINE=InnoDB` clauses (not needed in PostgreSQL)
7. Remove `DEFAULT CHARSET` and `COLLATE` clauses (PostgreSQL uses database-level encoding)

See `/schema/postgresql/` in the project root for the schema organization structure.

## Maintenance Tasks

### Token Cleanup

Remove expired refresh tokens and revoked access tokens that have passed their expiration time:

```sql
-- Delete expired refresh tokens
DELETE FROM pfme_refresh_tokens 
WHERE expires_at < NOW() 
  AND (revoked_at IS NULL OR revoked_at < NOW() - INTERVAL '7 days');

-- Delete old revoked access tokens (past their natural expiration + 24 hours)
DELETE FROM pfme_revoked_tokens 
WHERE revoked_at < NOW() - INTERVAL '24 hours';
```

Recommended frequency: Daily

### Auth Log Maintenance

Summarize and archive old authentication log entries:

```sql
-- Summarize yesterday's log entries
INSERT INTO pfme_auth_log_summary (mailbox, summary_date, failed_attempts, successful_attempts, created_at, updated_at)
SELECT 
    mailbox,
    DATE(attempted_at) as summary_date,
    COUNT(*) FILTER (WHERE success = FALSE) as failed_attempts,
    COUNT(*) FILTER (WHERE success = TRUE) as successful_attempts,
    NOW() as created_at,
    NOW() as updated_at
FROM pfme_auth_log
WHERE attempted_at >= DATE(NOW() - INTERVAL '1 day')
  AND attempted_at < DATE(NOW())
GROUP BY mailbox, DATE(attempted_at)
ON CONFLICT (mailbox, summary_date) 
DO UPDATE SET
    failed_attempts = EXCLUDED.failed_attempts,
    successful_attempts = EXCLUDED.successful_attempts,
    updated_at = NOW();

-- Archive old log entries (older than 90 days)
WITH archived AS (
    DELETE FROM pfme_auth_log
    WHERE attempted_at < NOW() - INTERVAL '90 days'
    RETURNING id, mailbox, success, ip_address, user_agent, attempted_at
)
INSERT INTO pfme_auth_log_archive (id, mailbox, success, ip_address, user_agent, attempted_at, archived_at)
SELECT id, mailbox, success, ip_address, user_agent, attempted_at, NOW()
FROM archived;
```

Recommended frequency: Daily

## Database User Privileges

The PostfixMe API database user needs the following privileges on the PostfixAdmin database:

```sql
-- Grant table-specific privileges
GRANT SELECT, INSERT, UPDATE, DELETE ON pfme_refresh_tokens TO pfme_user;
GRANT SELECT, INSERT, UPDATE, DELETE ON pfme_revoked_tokens TO pfme_user;
GRANT SELECT, INSERT, UPDATE, DELETE ON pfme_auth_log TO pfme_user;
GRANT SELECT, INSERT, UPDATE, DELETE ON pfme_auth_log_summary TO pfme_user;
GRANT SELECT, INSERT, UPDATE, DELETE ON pfme_auth_log_archive TO pfme_user;
GRANT SELECT, INSERT, UPDATE, DELETE ON pfme_mailbox_security TO pfme_user;

-- Grant sequence privileges for auto-increment columns
GRANT USAGE, SELECT ON SEQUENCE pfme_refresh_tokens_id_seq TO pfme_user;
GRANT USAGE, SELECT ON SEQUENCE pfme_auth_log_id_seq TO pfme_user;
GRANT USAGE, SELECT ON SEQUENCE pfme_auth_log_summary_id_seq TO pfme_user;
GRANT USAGE, SELECT ON SEQUENCE pfme_auth_log_archive_id_seq TO pfme_user;

-- Grant read-only access to PostfixAdmin tables
GRANT SELECT ON mailbox TO pfme_user;
GRANT SELECT ON alias TO pfme_user;
```

Note: Adjust `pfme_user` to match your actual database username.

## Index Strategy

Indexes are carefully chosen to optimize common query patterns:

1. **Refresh Token Queries:**
   - Lookup by token value (UNIQUE constraint on `token`)
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

- **Connection Pooling:** Use PgBouncer or similar for connection pooling
- **Index Usage:** Use EXPLAIN ANALYZE to verify index utilization
- **Table Growth:** Monitor `pfme_auth_log` size; implement archival before reaching 1M+ rows
- **Query Optimization:** Use date ranges and indexes for time-based queries
- **VACUUM:** Run VACUUM ANALYZE periodically on frequently updated tables
- **Partitioning:** Consider table partitioning for `pfme_auth_log_archive` by date for very large datasets
- **Autovacuum:** Ensure autovacuum is properly configured for PostfixMe tables

### PostgreSQL-Specific Optimizations

```sql
-- Adjust autovacuum settings for high-traffic tables
ALTER TABLE pfme_auth_log SET (
    autovacuum_vacuum_scale_factor = 0.05,
    autovacuum_analyze_scale_factor = 0.02
);

-- Create partial indexes for common queries
CREATE INDEX idx_pfme_refresh_tokens_active 
ON pfme_refresh_tokens (mailbox, expires_at) 
WHERE revoked_at IS NULL;

-- Consider using BRIN indexes for timestamp columns on very large tables
CREATE INDEX idx_pfme_auth_log_archive_attempted_brin 
ON pfme_auth_log_archive USING BRIN (attempted_at);
```

## Security Considerations

1. **Token Storage:** Refresh tokens are stored as SHA-256 hashes, never plaintext
2. **Access Control:** Ensure database user has minimal required privileges
3. **Connection Encryption:** Use SSL/TLS for database connections in production
4. **Backup Security:** Encrypt database backups (they contain sensitive auth data)
5. **Audit Retention:** Define clear data retention policies for compliance
6. **Row-Level Security (RLS):** Consider PostgreSQL RLS for additional access control if needed

### Enabling SSL Connections

In `pg_hba.conf`:
```
hostssl all all 0.0.0.0/0 md5
```

In PostgreSQL configuration:
```
ssl = on
ssl_cert_file = '/path/to/server.crt'
ssl_key_file = '/path/to/server.key'
```

## PostgreSQL-Specific Features

### JSONB Support (Future Enhancement)

PostgreSQL's native JSONB support could be leveraged for storing structured metadata:

```sql
-- Example: Add metadata column to auth log
ALTER TABLE pfme_auth_log ADD COLUMN metadata JSONB DEFAULT NULL;
CREATE INDEX idx_pfme_auth_log_metadata ON pfme_auth_log USING GIN (metadata);
```

### Full-Text Search (Future Enhancement)

PostgreSQL's full-text search can be used for analyzing user agent strings:

```sql
-- Example: Add text search vector for user agents
ALTER TABLE pfme_auth_log ADD COLUMN user_agent_vector tsvector;
CREATE INDEX idx_pfme_auth_log_fts ON pfme_auth_log USING GIN (user_agent_vector);
```

## Migration from MySQL

If migrating from MySQL to PostgreSQL:

1. Use `pg_loader` or similar tool for bulk data migration
2. Convert schema differences (data types, indexes)
3. Rewrite any MySQL-specific syntax in stored procedures
4. Test all queries for PostgreSQL compatibility
5. Adjust application code to use PostgreSQL PDO driver

Key differences to address:
- `AUTO_INCREMENT` → `SERIAL` or `BIGSERIAL`
- `DATETIME` → `TIMESTAMP WITHOUT TIME ZONE`
- `BOOLEAN` values: `0/1` → `FALSE/TRUE`
- Index syntax: inline → separate CREATE INDEX statements

## Troubleshooting

### Connection Issues

Check database connectivity:

```bash
docker exec -it pfme-php-api psql -h db -U postfixadmin -d postfixadmin
```

### Schema Verification

Verify all required tables exist:

```sql
\dt pfme_*
```

Expected output: Six tables (`pfme_refresh_tokens`, `pfme_revoked_tokens`, `pfme_auth_log`, `pfme_auth_log_summary`, `pfme_auth_log_archive`, `pfme_mailbox_security`)

### Index Verification

Check that all indexes are properly created:

```sql
\d pfme_refresh_tokens
\d pfme_auth_log
```

### Performance Monitoring

Monitor query performance:

```sql
-- Enable query logging
ALTER DATABASE postfixadmin SET log_statement = 'all';
ALTER DATABASE postfixadmin SET log_duration = on;

-- Analyze slow queries
SELECT query, calls, total_time, mean_time
FROM pg_stat_statements
WHERE query LIKE '%pfme_%'
ORDER BY mean_time DESC
LIMIT 10;
```

## Related Documentation

- [PostfixMe API Documentation](../README.md)
- [JWT Configuration](../../../docs/README-JWT.md)
- [Deployment Guide](../../../docs/DEPLOYMENT-PFME.md)
- [PostgreSQL Schema Automation](../../../lib/database/README-PostgreSQL.md)
- [PostfixAdmin Documentation](https://github.com/postfixadmin/postfixadmin)

## License

Copyright 2025, 2026 William W. Kimball, Jr. MBA MSIS

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.

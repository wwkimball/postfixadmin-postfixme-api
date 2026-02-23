# PostfixMe API Database Schema - SQLite

This document describes the required database schema for the PostfixMe API when using SQLite as the database backend.

## Overview

The PostfixMe API extends the standard PostfixAdmin database schema with additional tables to support:

- JWT-based authentication with access and refresh tokens
- Token rotation and revocation for enhanced security
- Authentication audit logging and rate limiting
- Password change tracking for token invalidation

## Prerequisites

- SQLite 3.8.0+
- An existing PostfixAdmin database installation
- The PostfixAdmin SQLite database file must be accessible
- Appropriate file system permissions for database access

## Important SQLite Considerations

SQLite is a file-based, embedded database with unique characteristics:

- **No Network Access:** Database is accessed directly via file I/O
- **No User Management:** Security is enforced through file system permissions
- **Single Writer:** Only one write operation at a time (readers can be concurrent)
- **Type Affinity:** More flexible typing than traditional databases
- **Simpler Operations:** No separate server process or connection pooling

**Recommended Use Cases:**
- Development and testing environments
- Single-user or low-concurrency applications
- Embedded or mobile applications (iOS PostfixMe app uses SQLite for local caching)

**Not Recommended For:**
- High-traffic production deployments
- Applications requiring concurrent writes
- Multi-server or distributed architectures

## Required Tables

### 1. pfme_refresh_tokens

Stores refresh tokens for JWT authentication with automatic refresh token rotation support.

```sql
CREATE TABLE IF NOT EXISTS pfme_refresh_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT NOT NULL UNIQUE,
    mailbox TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    last_used_at TEXT DEFAULT NULL,
    revoked_at TEXT DEFAULT NULL,
    family_id TEXT DEFAULT NULL,
    rotated_from TEXT DEFAULT NULL,
    rotated_to TEXT DEFAULT NULL,
    rotated_at TEXT DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS idx_pfme_refresh_tokens_mailbox ON pfme_refresh_tokens (mailbox);
CREATE INDEX IF NOT EXISTS idx_pfme_refresh_tokens_token ON pfme_refresh_tokens (token);
CREATE INDEX IF NOT EXISTS idx_pfme_refresh_tokens_expires ON pfme_refresh_tokens (expires_at);
CREATE INDEX IF NOT EXISTS idx_pfme_refresh_tokens_family_id ON pfme_refresh_tokens (family_id);
CREATE INDEX IF NOT EXISTS idx_pfme_refresh_tokens_last_used ON pfme_refresh_tokens (last_used_at);
CREATE INDEX IF NOT EXISTS idx_pfme_refresh_tokens_rotated_from ON pfme_refresh_tokens (rotated_from);
```

**Purpose:**
- Maintains long-lived refresh tokens for client authentication
- Supports token rotation chains via `family_id` for detecting token reuse attacks
- Tracks token lifecycle from creation through rotation to revocation

**Key Columns:**
- `token`: 64-character unique token identifier (SHA-256 hash)
- `mailbox`: The email address (mailbox) associated with this token
- `expires_at`: Token expiration timestamp in ISO 8601 format (e.g., '2026-02-23 12:34:56')
- `created_at`: Timestamp when token was originally created (ISO 8601)
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

**SQLite Notes:**
- Timestamps stored as TEXT in ISO 8601 format ('YYYY-MM-DD HH:MM:SS')
- Use `datetime()` function for timestamp comparisons
- Token size limited to 64 characters (sufficient for SHA-256 hex encoding)

### 2. pfme_revoked_tokens

Stores JTI (JWT ID) values of explicitly revoked access tokens to prevent their use before natural expiration.

```sql
CREATE TABLE IF NOT EXISTS pfme_revoked_tokens (
    jti TEXT PRIMARY KEY,
    revoked_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_pfme_revoked_tokens_revoked_at ON pfme_revoked_tokens (revoked_at);
```

**Purpose:**
- Blacklists access tokens that have been explicitly revoked
- Enables immediate token invalidation without waiting for natural expiration
- Supports security events like forced logout or compromised token detection

**Key Columns:**
- `jti`: JWT ID claim from the access token (unique identifier, max 32 characters)
- `revoked_at`: Timestamp when the token was revoked (ISO 8601 format)

**Maintenance:**
- Old entries (revoked tokens past their expiration time) should be periodically purged
- Recommended retention: 24 hours past the maximum access token TTL
- Default access token TTL: 900 seconds (15 minutes)

### 3. pfme_auth_log

Records all authentication attempts for audit purposes and rate limiting.

```sql
CREATE TABLE IF NOT EXISTS pfme_auth_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mailbox TEXT NOT NULL,
    success INTEGER NOT NULL DEFAULT 0,
    ip_address TEXT DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    attempted_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_pfme_auth_log_mailbox ON pfme_auth_log (mailbox);
CREATE INDEX IF NOT EXISTS idx_pfme_auth_log_attempted ON pfme_auth_log (attempted_at);
CREATE INDEX IF NOT EXISTS idx_pfme_auth_log_success ON pfme_auth_log (success);
```

**Purpose:**
- Maintains comprehensive audit trail of authentication events
- Supports rate limiting by tracking failed login attempts
- Enables account lockout protection after repeated failures
- Provides forensic data for security investigations

**Key Columns:**
- `mailbox`: The email address attempting authentication
- `success`: Boolean stored as INTEGER (0=false, 1=true)
- `ip_address`: Client IP address (IPv4 or IPv6, max 45 characters)
- `user_agent`: Client User-Agent string for device identification
- `attempted_at`: Timestamp of the authentication attempt (ISO 8601 format)

**Rate Limiting:**
- Tracks failed attempts within a configurable time window
- Default: 5 failed attempts in 300 seconds (5 minutes) triggers rate limiting
- Default: 10 failed attempts triggers account lockout for 1800 seconds (30 minutes)

**Maintenance:**
- This table can grow large over time and should be archived periodically
- See `pfme_auth_log_archive` and `pfme_auth_log_summary` for archival strategy

**SQLite Notes:**
- Boolean values represented as INTEGER (0 or 1)
- Use `WHERE success = 0` for failed attempts, `success = 1` for successful

### 4. pfme_auth_log_summary

Aggregated summary of authentication attempts by mailbox and date for efficient historical reporting.

```sql
CREATE TABLE IF NOT EXISTS pfme_auth_log_summary (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mailbox TEXT NOT NULL,
    summary_date TEXT NOT NULL,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    successful_attempts INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CONSTRAINT uniq_mailbox_date UNIQUE (mailbox, summary_date)
);

CREATE INDEX IF NOT EXISTS idx_pfme_auth_log_summary_date ON pfme_auth_log_summary (summary_date);
```

**Purpose:**
- Provides daily aggregated authentication statistics
- Enables efficient historical reporting without scanning full audit log
- Reduces storage requirements by summarizing old log entries

**Key Columns:**
- `mailbox`: The email address for this summary
- `summary_date`: The date this summary covers (DATE format: 'YYYY-MM-DD')
- `failed_attempts`: Total failed authentication attempts on this date
- `successful_attempts`: Total successful authentication attempts on this date
- `created_at`: Timestamp when this summary was first created
- `updated_at`: Timestamp of the last update to this summary

**Maintenance:**
- Summary records are created/updated by a maintenance script
- Typically run daily to summarize previous day's authentication activity
- See deployment documentation for scheduling maintenance tasks

**SQLite Notes:**
- Date stored as TEXT in 'YYYY-MM-DD' format
- Use `date()` function for date operations
- UNIQUE constraint enforces one summary per mailbox per day

### 5. pfme_auth_log_archive

Long-term storage for authentication log entries that have been summarized and removed from the active log.

```sql
CREATE TABLE IF NOT EXISTS pfme_auth_log_archive (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mailbox TEXT NOT NULL,
    success INTEGER NOT NULL DEFAULT 0,
    ip_address TEXT DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    attempted_at TEXT NOT NULL,
    archived_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_pfme_auth_log_archive_mailbox ON pfme_auth_log_archive (mailbox);
CREATE INDEX IF NOT EXISTS idx_pfme_auth_log_archive_attempted ON pfme_auth_log_archive (attempted_at);
CREATE INDEX IF NOT EXISTS idx_pfme_auth_log_archive_success ON pfme_auth_log_archive (success);
CREATE INDEX IF NOT EXISTS idx_pfme_auth_log_archive_archived ON pfme_auth_log_archive (archived_at);
```

**Purpose:**
- Archives historical authentication log entries
- Preserves detailed audit trail while keeping active log table lean
- Supports long-term forensic investigations and compliance requirements

**Key Columns:**
- Same schema as `pfme_auth_log` with addition of:
- `archived_at`: Timestamp when the log entry was moved to archive (ISO 8601)

**Maintenance:**
- Entries are moved from `pfme_auth_log` to archive by maintenance script
- Recommended: Archive entries older than 90 days
- Archive retention policy should match organizational compliance requirements

### 6. pfme_mailbox_security

Tracks password change events to enable automatic invalidation of existing access tokens.

```sql
CREATE TABLE IF NOT EXISTS pfme_mailbox_security (
    mailbox TEXT PRIMARY KEY,
    password_changed_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_pfme_mailbox_security_password_changed ON pfme_mailbox_security (password_changed_at);
```

**Purpose:**
- Records when mailbox passwords are changed
- Enables token validation to reject access tokens issued before password change
- Provides additional security layer beyond token expiration

**Key Columns:**
- `mailbox`: The email address (primary key)
- `password_changed_at`: Timestamp of the most recent password change (ISO 8601)
- `updated_at`: Timestamp when this record was last updated (ISO 8601)

**Usage:**
- Updated whenever a user changes their password
- Access token validation checks if token was issued before `password_changed_at`
- Forces re-authentication after password change without explicit token revocation

## SQLite Data Types and Type Affinity

SQLite uses a dynamic type system with type affinity. The declared types guide storage class but don't strictly enforce them:

- **INTEGER:** Signed integer values (1, 2, 3, 4, 6, or 8 bytes)
- **TEXT:** Text string stored using database encoding (UTF-8)
- **REAL:** Floating point value (8-byte IEEE floating point)
- **BLOB:** Binary large object stored exactly as input
- **NULL:** Null value

**Type Affinity Rules:**
- Columns with `INTEGER PRIMARY KEY` are aliased to the ROWID (fast)
- TEXT affinity for columns without numeric types
- No separate DATE or DATETIME types (use TEXT, REAL, or INTEGER)

**Recommended Date/Time Storage:**
- **TEXT:** ISO 8601 strings ('YYYY-MM-DD HH:MM:SS')
- **REAL:** Julian day numbers
- **INTEGER:** Unix timestamps (seconds since 1970-01-01)

This schema uses TEXT for timestamps to maintain human-readability and compatibility with SQLite's date/time functions.

## Schema Versioning

SQLite schema versioning can follow the same patterns as MySQL and PostgreSQL:

- Schema files use `.sql` extension
- Files follow naming convention: `YYYYMMDD-N.sql`
- Each forward migration has a corresponding rollback: `YYYYMMDD-N.rollback.sql`
- Schema version tracked in PostfixAdmin `settings` table

**Note:** While the project's existing schema automation scripts support MySQL and PostgreSQL, SQLite support would need to be added. Manual schema management is straightforward for SQLite given its simplicity.

## Initial Schema Deployment

Create all tables in a single transaction for atomicity:

```sql
BEGIN TRANSACTION;

-- Create all PostfixMe tables (statements from above)
CREATE TABLE IF NOT EXISTS pfme_refresh_tokens (...);
CREATE INDEX IF NOT EXISTS idx_pfme_refresh_tokens_mailbox ON pfme_refresh_tokens (mailbox);
-- ... (all other tables and indexes)

-- Update schema version
INSERT OR REPLACE INTO settings (name, value) 
VALUES ('pfme_schema_version', '20260223-1');

COMMIT;
```

## Maintenance Tasks

### Token Cleanup

Remove expired refresh tokens and revoked access tokens:

```sql
-- Delete expired refresh tokens
DELETE FROM pfme_refresh_tokens 
WHERE datetime(expires_at) < datetime('now')
  AND (revoked_at IS NULL OR datetime(revoked_at) < datetime('now', '-7 days'));

-- Delete old revoked access tokens (past their natural expiration + 24 hours)
DELETE FROM pfme_revoked_tokens 
WHERE datetime(revoked_at) < datetime('now', '-24 hours');

-- Reclaim space after deletes
VACUUM;
```

Recommended frequency: Daily

### Auth Log Maintenance

Summarize and archive old authentication log entries:

```sql
BEGIN TRANSACTION;

-- Summarize yesterday's log entries
INSERT OR REPLACE INTO pfme_auth_log_summary (mailbox, summary_date, failed_attempts, successful_attempts, created_at, updated_at)
SELECT 
    mailbox,
    date(attempted_at) as summary_date,
    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_attempts,
    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_attempts,
    datetime('now') as created_at,
    datetime('now') as updated_at
FROM pfme_auth_log
WHERE date(attempted_at) = date('now', '-1 day')
GROUP BY mailbox, date(attempted_at);

-- Archive old log entries (older than 90 days)
INSERT INTO pfme_auth_log_archive (mailbox, success, ip_address, user_agent, attempted_at, archived_at)
SELECT mailbox, success, ip_address, user_agent, attempted_at, datetime('now')
FROM pfme_auth_log
WHERE datetime(attempted_at) < datetime('now', '-90 days');

DELETE FROM pfme_auth_log
WHERE datetime(attempted_at) < datetime('now', '-90 days');

-- Reclaim space
VACUUM;

COMMIT;
```

Recommended frequency: Daily

## File System Permissions

SQLite database security relies on file system permissions:

**Development/Single-User:**
```bash
# Database file permissions (owner read/write only)
chmod 600 /path/to/postfixadmin.db

# Directory permissions (owner full access)
chmod 700 /path/to/database/directory
```

**Multi-User (Apache/Nginx):**
```bash
# Database owned by web server user
chown www-data:www-data /path/to/postfixadmin.db
chmod 660 /path/to/postfixadmin.db

# Directory owned by web server user
chown www-data:www-data /path/to/database/directory
chmod 770 /path/to/database/directory
```

**Important:**
- The directory containing the database must be writable (SQLite creates temporary files)
- Never place the database in a web-accessible directory
- Use file system encryption for sensitive data at rest

## Index Strategy

Indexes optimize query performance:

1. **Refresh Token Queries:**
   - Lookup by token value (UNIQUE constraint creates automatic index)
   - Lookup by mailbox for token listing
   - Cleanup queries by expiration date
   - Token rotation chain traversal via `family_id`

2. **Auth Log Queries:**
   - Rate limiting checks by mailbox and time window
   - Audit queries by mailbox
   - Time-based cleanup and archival operations

3. **Revoked Token Queries:**
   - Fast JTI validation (PRIMARY KEY creates automatic index)
   - Time-based cleanup

**SQLite Indexing Notes:**
- `PRIMARY KEY` automatically creates a unique index
- `UNIQUE` constraints automatically create indexes
- Additional indexes must be created explicitly
- Use `EXPLAIN QUERY PLAN` to verify index usage

## Performance Considerations

SQLite has different performance characteristics than server-based databases:

**Optimization Tips:**
1. **Write-Ahead Logging (WAL):** Enable for better concurrency
   ```sql
   PRAGMA journal_mode=WAL;
   ```

2. **Synchronous Mode:** Adjust for performance vs. durability tradeoff
   ```sql
   PRAGMA synchronous=NORMAL;  -- Good balance
   -- PRAGMA synchronous=OFF;  -- Faster but risk of corruption
   ```

3. **Cache Size:** Increase for better performance
   ```sql
   PRAGMA cache_size=-64000;  -- 64MB cache
   ```

4. **Page Size:** Set before creating tables (default 4096)
   ```sql
   PRAGMA page_size=4096;
   ```

5. **Temp Store:** Use memory for temporary tables
   ```sql
   PRAGMA temp_store=MEMORY;
   ```

6. **Foreign Keys:** Enable if using foreign key constraints
   ```sql
   PRAGMA foreign_keys=ON;
   ```

**Transaction Best Practices:**
- Group multiple writes in a single transaction
- Explicit transactions are much faster than auto-commit
- Use `BEGIN IMMEDIATE` to avoid upgrade locks

**VACUUM:**
- Run periodically to reclaim space after DELETEs
- Use `VACUUM INTO` to create a compacted copy
- Enable `auto_vacuum` for automatic space reclamation

## SQLite-Specific Optimizations

### Analyzing Query Plans

```sql
-- Check if indexes are being used
EXPLAIN QUERY PLAN 
SELECT * FROM pfme_refresh_tokens WHERE mailbox = 'user@example.com';

-- Analyze database statistics
ANALYZE;
```

### Partial Indexes (SQLite 3.8.0+)

```sql
-- Index only active (non-revoked) tokens
CREATE INDEX IF NOT EXISTS idx_pfme_refresh_tokens_active 
ON pfme_refresh_tokens (mailbox, expires_at) 
WHERE revoked_at IS NULL;

-- Index only failed authentication attempts
CREATE INDEX IF NOT EXISTS idx_pfme_auth_log_failures
ON pfme_auth_log (mailbox, attempted_at)
WHERE success = 0;
```

### Database Compilation Options

Check compile-time options affecting performance:

```sql
PRAGMA compile_options;
```

Look for:
- `ENABLE_STAT4` (better query optimization)
- `ENABLE_FTS5` (full-text search)
- `ENABLE_JSON1` (JSON functions)

## Security Considerations

1. **Token Storage:** Refresh tokens are stored as SHA-256 hashes, never plaintext
2. **File Permissions:** Rely on file system access control
3. **Encryption at Rest:** Use SQLCipher or file system encryption
4. **Backup Security:** Encrypt database backups (they contain sensitive auth data)
5. **Audit Retention:** Define clear data retention policies for compliance
6. **SQL Injection:** Always use parameterized queries (PDO prepared statements)

### SQLCipher Integration (Optional)

For encrypted SQLite databases:

```bash
# Install SQLCipher
brew install sqlcipher  # macOS
apt-get install sqlcipher  # Ubuntu

# Create encrypted database
sqlcipher postfixadmin.db
sqlite> PRAGMA key = 'your-encryption-key';
sqlite> -- Create tables...
```

In PHP PDO:
```php
$pdo = new PDO('sqlite:/path/to/postfixadmin.db');
$pdo->exec("PRAGMA key = 'your-encryption-key'");
```

## Limitations and Considerations

SQLite is excellent for many use cases but has limitations:

**Concurrency:**
- Single writer at a time (readers can be concurrent with WAL mode)
- Not suitable for high write-concurrency applications
- Write timeout/busy handler should be configured

**Data Types:**
- Flexible typing can lead to data inconsistencies if not careful
- No native boolean, date, or time types
- Maximum database size: 281 TB (practical limit around 1 TB)
- Maximum row size: 1 GB

**Features:**
- No stored procedures or triggers (limited trigger support)
- No RIGHT JOIN or FULL OUTER JOIN
- Limited ALTER TABLE support (cannot drop columns in older versions)
- No user management or network access

**Best Practices:**
- Use CHECK constraints to enforce data types
- Always use prepared statements
- Enable foreign key constraints if needed
- Consider migrating to PostgreSQL/MySQL for production if concurrency demands increase

## Migration Between Platforms

### From MySQL/PostgreSQL to SQLite

```bash
# Using sqlite3 CLI and mysqldump
mysqldump --compatible=ansi postfixadmin | sqlite3 postfixadmin.db

# Manual conversion script
php convert-to-sqlite.php

# Or use third-party tools:
# - pgloader
# - db-converter
```

### From SQLite to MySQL/PostgreSQL

```bash
# Export to SQL
sqlite3 postfixadmin.db .dump > export.sql

# Convert SQL syntax for target platform
sed 's/INTEGER PRIMARY KEY AUTOINCREMENT/BIGINT AUTO_INCREMENT/g' export.sql > mysql-import.sql

# Import to MySQL
mysql postfixadmin < mysql-import.sql
```

## Troubleshooting

### Database Locked Errors

```sql
-- Increase timeout
PRAGMA busy_timeout = 10000;  -- 10 seconds
```

In PHP:
```php
$pdo->exec('PRAGMA busy_timeout = 10000');
```

### Corruption Detection

```sql
-- Check database integrity
PRAGMA integrity_check;

-- Quick check
PRAGMA quick_check;
```

### Schema Verification

```sql
-- List all tables
.tables

-- Show table schema
.schema pfme_refresh_tokens

-- List indexes
.indexes pfme_refresh_tokens

-- Show all PostfixMe tables
SELECT name FROM sqlite_master 
WHERE type='table' AND name LIKE 'pfme_%' 
ORDER BY name;
```

Expected output: Six tables (`pfme_auth_log`, `pfme_auth_log_archive`, `pfme_auth_log_summary`, `pfme_mailbox_security`, `pfme_refresh_tokens`, `pfme_revoked_tokens`)

### Performance Monitoring

```sql
-- Enable query profiling
PRAGMA vdbe_trace = ON;

-- Check query execution plan
EXPLAIN QUERY PLAN SELECT ...;

-- Database statistics
PRAGMA database_list;
PRAGMA page_count;
PRAGMA page_size;
PRAGMA freelist_count;
```

### Backup and Recovery

```bash
# Online backup (SQLite 3.27.0+)
sqlite3 postfixadmin.db ".backup '/backup/postfixadmin-backup.db'"

# Using command-line utility
sqlite3 postfixadmin.db .dump > postfixadmin-backup.sql

# WAL checkpoint before backup
sqlite3 postfixadmin.db "PRAGMA wal_checkpoint(TRUNCATE);"
```

## Development and Testing

SQLite is ideal for development and testing:

```php
// In-memory database for testing
$pdo = new PDO('sqlite::memory:');

// Temporary file database
$pdo = new PDO('sqlite:/tmp/test-postfixadmin.db');

// Load schema
$schema = file_get_contents('schema/sqlite/pfme-schema.sql');
$pdo->exec($schema);

// Run tests...
```

## Configuration in PostfixMe API

Update `pfme/api/config/config.php` for SQLite:

```php
'database' => [
    'driver' => 'sqlite',
    'path' => getenv('POSTFIXADMIN_DB_PATH') ?: '/var/lib/postfixadmin/postfixadmin.db',
],
```

Update `pfme/api/src/Core/Database.php`:

```php
if ($config['database']['driver'] === 'sqlite') {
    $dsn = "sqlite:{$config['database']['path']}";
    self::$connection = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Enable WAL mode for better concurrency
    self::$connection->exec('PRAGMA journal_mode=WAL');
    self::$connection->exec('PRAGMA synchronous=NORMAL');
    self::$connection->exec('PRAGMA busy_timeout=10000');
    self::$connection->exec('PRAGMA foreign_keys=ON');
}
```

## Related Documentation

- [PostfixMe API Documentation](../README.md)
- [JWT Configuration](../../../docs/README-JWT.md)
- [Deployment Guide](../../../docs/DEPLOYMENT-PFME.md)
- [PostfixAdmin Documentation](https://github.com/postfixadmin/postfixadmin)
- [SQLite Documentation](https://www.sqlite.org/docs.html)
- [SQLite PHP PDO Driver](https://www.php.net/manual/en/ref.pdo-sqlite.php)

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

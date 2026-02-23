# PostfixMe API - Multi-Database Support

## Overview

The PostfixMe API supports multiple database platforms:

- **MySQL 5.7+** / **MariaDB 10.3+** (default) - See [README-MySQL.md](README-MySQL.md)
- **PostgreSQL 9.6+** - See [README-PostgreSQL.md](README-PostgreSQL.md)
- **SQLite 3.8.0+** - See [README-SQLite.md](README-SQLite.md)

This allows for flexibility in deployment scenarios, from lightweight single-server deployments using SQLite to enterprise setups with dedicated database servers.

### Platform-Specific Documentation

Each database platform has comprehensive documentation covering schema requirements, initialization procedures, maintenance tasks, and platform-specific optimizations:

- **[README-MySQL.md](README-MySQL.md)** - Complete MySQL/MariaDB schema reference with setup and optimization guides
- **[README-PostgreSQL.md](README-PostgreSQL.md)** - Complete PostgreSQL schema reference with setup and optimization guides
- **[README-SQLite.md](README-SQLite.md)** - Complete SQLite schema reference with setup and optimization guides

## Configuration

### Database Type Selection

The database platform is controlled via the **`POSTFIXADMIN_DB_TYPE`** environment variable, which should be set in your Docker Compose `.env` files.

#### Supported Values

- `mysqli` - MySQL/MariaDB (default, port 3306)
- `pgsql` - PostgreSQL (port 5432)
- `sqlite` - SQLite file-based database

### Environment Variable Configuration

#### For MySQL/MariaDB

```bash
POSTFIXADMIN_DB_TYPE=mysqli
POSTFIXADMIN_DB_HOST=database
POSTFIXADMIN_DB_PORT=3306
POSTFIXADMIN_DB_NAME=postfixadmin
POSTFIXADMIN_DB_USER_FILE=/run/secrets/postfixadmin_db_user
POSTFIXADMIN_DB_PASSWORD_FILE=/run/secrets/postfixadmin_db_password
```

📖 **[See MySQL/MariaDB documentation →](README-MySQL.md)** for detailed schema information, setup procedures, and performance tuning.

#### For PostgreSQL

```bash
POSTFIXADMIN_DB_TYPE=pgsql
POSTFIXADMIN_DB_HOST=database
POSTFIXADMIN_DB_PORT=5432
POSTFIXADMIN_DB_NAME=postfixadmin
POSTFIXADMIN_DB_USER_FILE=/run/secrets/postfixadmin_db_user
POSTFIXADMIN_DB_PASSWORD_FILE=/run/secrets/postfixadmin_db_password
```

📖 **[See PostgreSQL documentation →](README-PostgreSQL.md)** for detailed schema information, setup procedures, and performance tuning.

#### For SQLite

```bash
POSTFIXADMIN_DB_TYPE=sqlite
POSTFIXADMIN_DB_PATH=/var/lib/postfixadmin/postfixadmin.db
```

Note: SQLite does not use credentials or network configuration.

📖 **[See SQLite documentation →](README-SQLite.md)** for detailed schema information, setup procedures, and SQLite-specific optimization strategies.

## Required Tables

The PostfixMe API requires six tables for authentication and audit logging:

1. **pfme_refresh_tokens** - Long-lived JWT refresh tokens with rotation support
2. **pfme_revoked_tokens** - Explicitly revoked access tokens (JTI list)
3. **pfme_auth_log** - Detailed authentication attempt audit log
4. **pfme_auth_log_summary** - Aggregated daily authentication statistics
5. **pfme_auth_log_archive** - Historical authentication logs (archived from main log)
6. **pfme_mailbox_security** - Password change tracking for token invalidation

## Database-Specific Implementation Details

### Query Abstraction

The API uses the `DatabaseHelper` class to generate database-agnostic SQL:

```php
use Pfme\Api\Core\DatabaseHelper;

// Get current timestamp in DB-appropriate format
$now = DatabaseHelper::now($dbType);

// Format a timestamp for insertion
$formattedTime = DatabaseHelper::formatTimestamp($timestamp, $dbType);

// Subtract seconds from current time (for rate limiting)
$timeComparison = DatabaseHelper::subtractSeconds(300, $dbType);

// Extract date from timestamp
$dateOnly = DatabaseHelper::extractDate('column_name', $dbType);
```

### Timestamp Handling

Different databases handle timestamps differently:

- **MySQL/MariaDB**: Uses `DATETIME` with `NOW()` function
- **PostgreSQL**: Uses `TIMESTAMP WITHOUT TIME ZONE` with `NOW()` function
- **SQLite**: Uses `TEXT` storing ISO 8601 format (YYYY-MM-DD HH:MM:SS)

The `DatabaseHelper::formatTimestamp()` method ensures consistency across platforms.

### INSERT ... ON CONFLICT Handling

The API handles unique constraint violations differently across platforms:

- **MySQL/MariaDB**: `ON DUPLICATE KEY UPDATE`
- **PostgreSQL**: `ON CONFLICT ... DO UPDATE`
- **SQLite**: `INSERT OR REPLACE` or `INSERT OR IGNORE`

Services automatically detect the database type via `Database::getType()` and use appropriate syntax.

## Deployment Scenarios

### Single-Server with SQLite

Perfect for small deployments or testing:

```yaml
services:
  pfme-api:
    environment:
      POSTFIXADMIN_DB_TYPE: sqlite
      POSTFIXADMIN_DB_PATH: /var/lib/postfixadmin/postfixadmin.db
    volumes:
      - pfme-data:/var/lib/postfixadmin
```

**Advantages:**

- Zero configuration
- No separate database service needed
- Automatic file-based persistence

**Limitations:**

- Single-writer concurrency model
- Not suitable for high-write scenarios

→ See [SQLite documentation](README-SQLite.md) for optimization tips for file-based deployments.

### Development with PostgreSQL

For development/testing that matches production:

```yaml
services:
  database:
    image: postgres:15
    environment:
      POSTGRES_DB: postfixadmin
      POSTGRES_PASSWORD: dev_password

  pfme-api:
    environment:
      POSTFIXADMIN_DB_TYPE: pgsql
      POSTFIXADMIN_DB_HOST: database
      POSTFIXADMIN_DB_PORT: 5432
      POSTFIXADMIN_DB_NAME: postfixadmin
```

→ See [PostgreSQL documentation](README-PostgreSQL.md) for development setup and advanced configuration options.

### Production with MySQL

For high-availability enterprise deployments:

```yaml
services:
  pfme-api:
    environment:
      POSTFIXADMIN_DB_TYPE: mysqli
      POSTFIXADMIN_DB_HOST: mysql.company.com
      POSTFIXADMIN_DB_PORT: 3306
      POSTFIXADMIN_DB_NAME: postfixadmin
```

→ See [MySQL documentation](README-MySQL.md) for production setup, replication, and advanced configuration.

## Performance Considerations

### MySQL/MariaDB

- Optimized for high concurrency
- Built-in replication for redundancy
- Suitable for large deployments

→ See [MySQL Performance Considerations](README-MySQL.md#performance-considerations) for detailed tuning guidance.

### PostgreSQL

- Excellent ACID compliance
- Advanced query optimization
- Better handling of complex queries
- Suitable for enterprise deployments

→ See [PostgreSQL Performance Monitoring](README-PostgreSQL.md#performance-monitoring) for detailed tuning guidance.

### SQLite

- Optimal file I/O performance for single-writer scenarios
- Automatic checkpointing with WAL mode
- Busy timeout configuration for retry logic
- Ideal for edge deployments or low-traffic scenarios

→ See [SQLite Performance Considerations](README-SQLite.md#performance-considerations) for detailed tuning guidance.

### Index Strategy

All table definitions include appropriate indexes for:

- **Token lookups**: By token value, mailbox, expiration
- **Auth log queries**: By mailbox and timestamp for rate limiting
- **Cleanup operations**: By date for retention management

## Troubleshooting

### Connection Failures

Verify environment variables are set correctly:

```bash
# Check MySQL connection
docker exec pfme-api php -r "
  require 'config/config.php';
  \$c = \Pfme\Api\Core\Database::getConnection();
  echo 'Connected to ' . \Pfme\Api\Core\Database::getType();
"
```

### Schema Issues

Check that tables were created:

```bash
# MySQL
mysql -u user -p postfixadmin -e "SHOW TABLES LIKE 'pfme_%';"

# PostgreSQL
psql -U user -d postfixadmin -c "\dt pfme_*"

# SQLite
sqlite3 postfixadmin.db ".schema pfme_"
```

### Timestamp Conversion Issues

Ensure timestamps are in ISO 8601 format (YYYY-MM-DD HH:MM:SS) when working with SQLite.

For platform-specific troubleshooting:

- [MySQL Troubleshooting](README-MySQL.md#troubleshooting)
- [PostgreSQL Troubleshooting](README-PostgreSQL.md#troubleshooting)
- [SQLite Troubleshooting](README-SQLite.md#troubleshooting)

## Configuration Files

This API reads database settings exclusively from environment variables (for example, `POSTFIXADMIN_DB_TYPE`, `POSTFIXADMIN_DB_HOST`, `POSTFIXADMIN_DB_PORT`).
Configure these values in the environment file or service configuration used by your deployment.

## Related Documentation

- **[README-MySQL.md](README-MySQL.md)** - MySQL/MariaDB schema and setup guide
- **[README-PostgreSQL.md](README-PostgreSQL.md)** - PostgreSQL schema and setup guide
- **[README-SQLite.md](README-SQLite.md)** - SQLite schema and setup guide

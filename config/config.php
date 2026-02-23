<?php
/**
 * PostfixMe API
 *
 * @package   PostfixMe API
 * @copyright Copyright (c) 2026 William Kimball, Jr., MBA, MSIS
 * @license   GPL-2.0-or-later
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

/**
 * PostfixMe API Configuration
 *
 * This file loads configuration from environment variables,
 * supporting multiple database platforms (MySQL, MariaDB, PostgreSQL, SQLite)
 * via the POSTFIXADMIN_DB_TYPE environment variable.
 *
 * The database type is controlled via Docker Compose environment variables.
 */

return [
    'database' => [
        // Database type: 'mysqli' (default), 'pgsql', or 'sqlite'
        // Supports PostfixAdmin value formats
        'type' => getenv('POSTFIXADMIN_DB_TYPE') ?: 'mysqli',

        // MySQL/MariaDB/PostgreSQL connection settings
        'host' => getenv('POSTFIXADMIN_DB_HOST') ?: 'db',
        'port' => getenv('POSTFIXADMIN_DB_PORT') ?: (
            (getenv('POSTFIXADMIN_DB_TYPE') === 'pgsql') ? '5432' : '3306'
        ),
        'name' => getenv('POSTFIXADMIN_DB_NAME') ?: 'postfixadmin',

        // SQLite database file path (for SQLite only)
        'path' => getenv('POSTFIXADMIN_DB_PATH') ?: '/var/lib/postfixadmin/postfixadmin.db',

        // Secret files for credentials (not used by SQLite)
        'user_file' => getenv('POSTFIXADMIN_DB_USER_FILE') ?: '/run/secrets/postfixadmin_db_user',
        'password_file' => getenv('POSTFIXADMIN_DB_PASSWORD_FILE') ?: '/run/secrets/postfixadmin_db_password',
    ],

    'postfixadmin' => [
        'source_path' => getenv('POSTFIXADMIN_SOURCE_PATH') ?: '/usr/src/postfixadmin',
    ],

    'jwt' => [
        'private_key_file' => getenv('PFME_JWT_PRIVATE_KEY_FILE') ?: '/run/secrets/pfme_jwt_private_key',
        'public_key_file' => getenv('PFME_JWT_PUBLIC_KEY_FILE') ?: '/run/secrets/pfme_jwt_public_key',
        'algorithm' => 'RS256',
        'access_token_ttl' => (int)(getenv('PFME_ACCESS_TOKEN_TTL') ?: 900), // 15 minutes
        'refresh_token_ttl' => (int)(getenv('PFME_REFRESH_TOKEN_TTL') ?: 157680000), // 5 years with sliding expiration
        'refresh_token_grace_period' => (int)(getenv('PFME_REFRESH_TOKEN_GRACE_PERIOD') ?: 604800), // 7 days
        'issuer' => getenv('PFME_JWT_ISSUER') ?: 'pfme-api',
        'audience' => getenv('PFME_JWT_AUDIENCE') ?: 'pfme-mobile',
    ],

    'security' => [
        'trusted_proxy_cidr' => getenv('TRUSTED_PROXY_CIDR') ?: '',
        'trusted_tls_header' => getenv('TRUSTED_TLS_HEADER_NAME') ?: 'X-Forwarded-Proto',
        'require_tls' => filter_var(getenv('PFME_REQUIRE_TLS') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        'rate_limit_attempts' => (int)(getenv('PFME_RATE_LIMIT_ATTEMPTS') ?: 5),
        'rate_limit_window' => (int)(getenv('PFME_RATE_LIMIT_WINDOW') ?: 300), // 5 minutes
        'lockout_threshold' => (int)(getenv('PFME_LOCKOUT_THRESHOLD') ?: 10),
        'lockout_duration' => (int)(getenv('PFME_LOCKOUT_DURATION') ?: 1800), // 30 minutes
        'auth_log_retention_days' => (int)(getenv('PFME_AUTH_LOG_RETENTION_DAYS') ?: 90),
        'auth_log_summary_enabled' => filter_var(getenv('PFME_AUTH_LOG_SUMMARY_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        'auth_log_summary_lag_days' => (int)(getenv('PFME_AUTH_LOG_SUMMARY_LAG_DAYS') ?: 1),
        'auth_log_archive_enabled' => filter_var(getenv('PFME_AUTH_LOG_ARCHIVE_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'auth_log_archive_retention_days' => (int)(getenv('PFME_AUTH_LOG_ARCHIVE_RETENTION_DAYS') ?: 365),
        'password_min_length' => (int)(getenv('PFME_PASSWORD_MIN_LENGTH') ?: 10),
        'password_require_space' => filter_var(getenv('PFME_PASSWORD_REQUIRE_SPACE') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        'password_require_grammar_symbol' => filter_var(getenv('PFME_PASSWORD_REQUIRE_GRAMMAR_SYMBOL') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    ],

    'pagination' => [
        'default_per_page' => 20,
        'max_per_page' => 100,
    ],
];

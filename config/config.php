<?php
/**
 * PostfixMe API Configuration
 *
 * This file loads configuration from environment variables,
 * following the existing Docker secret pattern (*_FILE -> /run/secrets/...)
 */

return [
    'database' => [
        'host' => getenv('POSTFIXADMIN_DB_HOST') ?: 'db',
        'port' => getenv('POSTFIXADMIN_DB_PORT') ?: '3306',
        'name' => getenv('POSTFIXADMIN_DB_NAME') ?: 'postfixadmin',
        'user_file' => getenv('POSTFIXADMIN_DB_USER_FILE') ?: '/run/secrets/postfixadmin_db_user',
        'password_file' => getenv('POSTFIXADMIN_DB_PASSWORD_FILE') ?: '/run/secrets/postfixadmin_db_password',
    ],

    'jwt' => [
        'private_key_file' => getenv('PFME_JWT_PRIVATE_KEY_FILE') ?: '/run/secrets/pfme_jwt_private_key',
        'public_key_file' => getenv('PFME_JWT_PUBLIC_KEY_FILE') ?: '/run/secrets/pfme_jwt_public_key',
        'algorithm' => 'RS256',
        'access_token_ttl' => (int)(getenv('PFME_ACCESS_TOKEN_TTL') ?: 900), // 15 minutes
        'refresh_token_ttl' => (int)(getenv('PFME_REFRESH_TOKEN_TTL') ?: 2592000), // 30 days
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
    ],

    'pagination' => [
        'default_per_page' => 20,
        'max_per_page' => 100,
    ],

    'postfixadmin' => [
        'config_path' => getenv('POSTFIXADMIN_CONFIG_PATH') ?: '/var/www/html/config.local.php',
    ],
];

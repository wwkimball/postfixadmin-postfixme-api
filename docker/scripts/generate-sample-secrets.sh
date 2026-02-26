#!/usr/bin/env bash
###############################################################################
# Generate example environment and secret files for pfme/api/docker
#
# Existing files are left untouched.
#
# Copyright (c) 2026 William W. Kimball, Jr., MBA, MSIS
# All rights reserved.
###############################################################################
set -eu

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BASE_DIR="${SCRIPT_DIR}/.."
SECRETS_DIR="${BASE_DIR}/secrets"

function _write_if_missing {
    local path="$1"; shift
    local content="$1"; shift || true
    if [ -f "$path" ]; then
        echo "Exists: $path"
        return 0
    fi
    echo "$content" > "$path"
    chmod 600 "$path" || true
    echo "Wrote: $path"
}

function _random_password {
    # 24-char base64-ish password
    openssl rand -base64 18 | tr -d '\n'
}

function _generate_rsa_keys_if_missing {
    local priv="$SECRETS_DIR/pfme_jwt_private_key.txt"
    local pub="$SECRETS_DIR/pfme_jwt_public_key.txt"
    if [ -f "$priv" ] && [ -f "$pub" ]; then
        echo "JWT keypair exists"
        return 0
    fi
    echo "Generating RSA keypair (2048 bits)"
    openssl genpkey -algorithm RSA -out "$priv" -pkeyopt rsa_keygen_bits:2048
    openssl rsa -pubout -in "$priv" -out "$pub"
    chmod 600 "$priv" || true
    chmod 644 "$pub" || true
    echo "Wrote: $priv and $pub"
}

# Human-editable templates for generated .env files.  Keep these templates
# short and clear so contributors can read and adjust them easily.
#
# docker/.env (base template for all environments)
BASE_ENV_TEMPLATE=$(cat <<EOF
# Settings for Docker Compose itself (not any of the service containers).
# These are independent of the build target (development, qa, production) and
# are the same for all of them; these constitute your network-specific settings.
#
# When using William Kimball's general shell script library for Docker builds,
# these environment variables must be set, though they don't have to be "real"
# unless you want to use image publishing capabilities that require private
# Docker registry access.
DOMAIN_NAME=localdomain.local

DOCKER_REGISTRY_SOCKET=docker-registry.\${DOMAIN_NAME}:443
DOCKER_REGISTRY_USERNAME=$(whoami)
DOCKER_REGISTRY_REPOSITORY=$(whoami)

NAS_HOST=nas.\${DOMAIN_NAME}
NAS_SHARE=my-docker-nfs-share
NAS_VOLUME=volume0
EOF
)

# docker/.env.development
ENV_DEV_TEMPLATE=$(cat <<'EOF'
# Development environment defaults
#
# Enable extended logging for development
APP_ENV=development

PFME_REQUIRE_TLS=false
PFME_JWT_PRIVATE_KEY_FILE=/run/secrets/pfme_jwt_private_key
PFME_JWT_PUBLIC_KEY_FILE=/run/secrets/pfme_jwt_public_key

TRUSTED_PROXY_CIDR=172.16.0.0/12,192.168.0.0/24,10.0.0.0/8

POSTFIXADMIN_ENCRYPT=dovecot:SHA512

POSTFIXADMIN_DB_TYPE=mysqli
POSTFIXADMIN_DB_NAME=postfixadmin
POSTFIXADMIN_DB_USER_FILE=/run/secrets/postfixadmin_db_user
POSTFIXADMIN_DB_PASSWORD_FILE=/run/secrets/postfixadmin_db_password
POSTFIXADMIN_DB_HOST=database
POSTFIXADMIN_DB_PORT=3306
POSTFIXADMIN_SOURCE_NETWORK=%

# For testing different database platforms in development, uncomment desired config:
# To test with PostgreSQL:
#   POSTFIXADMIN_DB_TYPE=pgsql
#   POSTFIXADMIN_DB_PORT=5432
# To test with SQLite:
#   POSTFIXADMIN_DB_TYPE=sqlite
#   POSTFIXADMIN_DB_PATH=/var/lib/postfixadmin/postfixadmin.db

MYSQL_HOST=$POSTFIXADMIN_DB_HOST
MYSQL_PORT=$POSTFIXADMIN_DB_PORT
MYSQL_DATABASE=$POSTFIXADMIN_DB_NAME
MYSQL_USER=root
MYSQL_DISABLE_TLS=true
MYSQL_PASSWORD_FILE=/run/secrets/postfixadmin_db_password

DBSCHEMA_SETTINGS_TABLE=dbschema_settings
EOF
)

# docker/.env.qa
ENV_QA_TEMPLATE=$(cat <<'EOF'
# QA environment defaults
APP_ENV=testing

PFME_REQUIRE_TLS=false
PFME_JWT_PRIVATE_KEY_FILE=/run/secrets/pfme_jwt_private_key
PFME_JWT_PUBLIC_KEY_FILE=/run/secrets/pfme_jwt_public_key

TRUSTED_PROXY_CIDR=172.16.0.0/12,192.168.0.0/24,10.0.0.0/8

POSTFIXADMIN_ENCRYPT=dovecot:SHA512

POSTFIXADMIN_DB_TYPE=mysqli
POSTFIXADMIN_DB_NAME=postfixadmin
POSTFIXADMIN_DB_USER_FILE=/run/secrets/postfixadmin_db_user
POSTFIXADMIN_DB_PASSWORD_FILE=/run/secrets/postfixadmin_db_password
POSTFIXADMIN_DB_HOST=database
POSTFIXADMIN_DB_PORT=3306
POSTFIXADMIN_SOURCE_NETWORK=%

MYSQL_HOST=$POSTFIXADMIN_DB_HOST
MYSQL_PORT=$POSTFIXADMIN_DB_PORT
MYSQL_DATABASE=$POSTFIXADMIN_DB_NAME
MYSQL_USER=root
MYSQL_DISABLE_TLS=true
MYSQL_PASSWORD_FILE=/run/secrets/postfixadmin_db_password

DBSCHEMA_SETTINGS_TABLE=dbschema_settings
EOF
)

# docker/.env.production
ENV_PROD_TEMPLATE=$(cat <<'EOF'
# Production example (replace values before deploying)
APP_ENV=production

PFME_REQUIRE_TLS=true
PFME_JWT_PRIVATE_KEY_FILE=/run/secrets/pfme_jwt_private_key
PFME_JWT_PUBLIC_KEY_FILE=/run/secrets/pfme_jwt_public_key

TRUSTED_PROXY_CIDR=172.16.0.0/12,192.168.0.0/24,10.0.0.0/8

POSTFIXADMIN_ENCRYPT=dovecot:SHA512

POSTFIXADMIN_DB_TYPE=mysqli
POSTFIXADMIN_DB_NAME=postfixadmin
POSTFIXADMIN_DB_USER_FILE=/run/secrets/postfixadmin_db_user
POSTFIXADMIN_DB_PASSWORD_FILE=/run/secrets/postfixadmin_db_password
POSTFIXADMIN_DB_HOST=your-db-host
POSTFIXADMIN_DB_PORT=3306
POSTFIXADMIN_SOURCE_NETWORK=%

MYSQL_HOST=$POSTFIXADMIN_DB_HOST
MYSQL_PORT=$POSTFIXADMIN_DB_PORT
MYSQL_DATABASE=$POSTFIXADMIN_DB_NAME
MYSQL_USER=root
MYSQL_DISABLE_TLS=true
MYSQL_PASSWORD_FILE=/run/secrets/postfixadmin_db_password

DBSCHEMA_SETTINGS_TABLE=dbschema_settings
EOF
)

# docker/.env.postfixadmin
ENV_POSTFIXADMIN_TEMPLATE=$(cat <<EOF
POSTFIXADMIN_ADMIN_SMTP_PASSWORD=$( _random_password )
EOF
)

# docker/.env.postfixadmin.development
ENV_POSTFIXADMIN_DEV_TEMPLATE=$(cat <<'EOF'
# Other PostfixAdmin configuration
POSTFIXADMIN_ADMIN_EMAIL=postmaster@development.localhost.localdomain
POSTFIXADMIN_SMTP_SERVER=mail.development.localhost.localdomain
EOF
)

# docker/.env.postfixadmin.qa
ENV_POSTFIXADMIN_QA_TEMPLATE=$(cat <<'EOF'
# Other PostfixAdmin configuration
POSTFIXADMIN_ADMIN_EMAIL=postmaster@qa.localhost.localdomain
POSTFIXADMIN_SMTP_SERVER=mail.qa.localhost.localdomain
EOF
)

# docker/.env.pfme-api
ENV_PFME_API_TEMPLATE=$(cat <<'EOF'
# JWT and token settings for PostfixMe API
PFME_ACCESS_TOKEN_TTL=900
PFME_REFRESH_TOKEN_TTL=157680000
PFME_JWT_ISSUER=pfme-api
PFME_JWT_AUDIENCE=pfme-mobile

# If behind a reverse proxy that terminates TLS, set this to the header that
# indicates the original protocol (e.g. X-Forwarded-Proto) and ensure your
# proxy is configured to set it.  This allows the API to correctly identify
# secure requests and enforce TLS requirements.
TRUSTED_TLS_HEADER_NAME=X-Forwarded-Proto
EOF
)

# docker/.env.pfme-api.development
ENV_PFME_API_DEV_TEMPLATE=$(cat <<'EOF'
# TLS is not possible in development (self-signed certs cause errors)
PFME_REQUIRE_TLS="false"

# Trusted proxies for development environment (Docker internal networks)
TRUSTED_PROXY_CIDR=172.16.0.0/12,192.168.0.0/24,10.0.0.0/8
EOF
)

# docker/.env.pfme-api.qa
ENV_PFME_API_QA_TEMPLATE=$(cat <<'EOF'
# TLS is not possible in QA (self-signed certs cause errors)
PFME_REQUIRE_TLS="false"

# Trusted proxies for QA environment (Docker internal networks)
TRUSTED_PROXY_CIDR=172.16.0.0/12,192.168.0.0/24,10.0.0.0/8

# Shorter for testing
PFME_ACCESS_TOKEN_TTL=300
PFME_REFRESH_TOKEN_TTL=3600

# Custom for testing
PFME_JWT_ISSUER=pfme-api-qa
PFME_JWT_AUDIENCE=pfme-mobile-qa

# Database Configuration
# Database type for PostfixMe API (values must match PostfixAdmin format)
POSTFIXADMIN_DB_TYPE=mysqli
EOF
)

# Ensure presence of the secrets directory
if [ ! -d "$SECRETS_DIR" ]; then
    mkdir -p "$SECRETS_DIR"
    echo "Created: $SECRETS_DIR"
fi

_write_if_missing "$SECRETS_DIR/postfixadmin_db_user.txt" "postfixadmin"
_write_if_missing "$SECRETS_DIR/postfixadmin_db_password.txt" "$( _random_password )"
_write_if_missing "$SECRETS_DIR/mysql_root_password.txt" "$( _random_password )"

_generate_rsa_keys_if_missing

# Create example .env files that reference the generated secrets
_write_if_missing "$BASE_DIR/.env" "$BASE_ENV_TEMPLATE"
_write_if_missing "$BASE_DIR/.env.development" "$ENV_DEV_TEMPLATE"
_write_if_missing "$BASE_DIR/.env.qa" "$ENV_QA_TEMPLATE"
_write_if_missing "$BASE_DIR/.env.production" "$ENV_PROD_TEMPLATE"
_write_if_missing "$BASE_DIR/.env.postfixadmin" "$ENV_POSTFIXADMIN_TEMPLATE"
_write_if_missing "$BASE_DIR/.env.postfixadmin.development" "$ENV_POSTFIXADMIN_DEV_TEMPLATE"
_write_if_missing "$BASE_DIR/.env.postfixadmin.qa" "$ENV_POSTFIXADMIN_QA_TEMPLATE"
_write_if_missing "$BASE_DIR/.env.pfme-api" "$ENV_PFME_API_TEMPLATE"
_write_if_missing "$BASE_DIR/.env.pfme-api.development" "$ENV_PFME_API_DEV_TEMPLATE"
_write_if_missing "$BASE_DIR/.env.pfme-api.qa" "$ENV_PFME_API_QA_TEMPLATE"

echo "Quick start secret and environment file generation is complete.  Adjust the new .env and secret files according to your needs."

#!/usr/bin/env bash
################################################################################
# Reload all test seed data
#
# This script reruns all seed SQL files using the mysql.sh schema manager.
# It should be run after purge-test-data.sh to restore the test environment
# to a known state with fresh seed data.
#
# Usage (from host):
#   ./compose.sh exec postfixadmin reload-test-data.sh
#
# Usage (inside container):
#   /usr/local/bin/reload-test-data.sh
#
# Copyright 2026 William W. Kimball, Jr., MBA, MSIS
################################################################################
set -euo pipefail

# Paths as they exist in the container (see Dockerfile)
SEED_DIR="/opt/postfixadmin/seeds"
SCHEMA_MANAGER="/opt/dbutils/lib/database/schema/mysql.sh"

# Database connection parameters (from environment variables set by Docker Compose)
DB_HOST="${POSTFIXADMIN_DB_HOST:-database}"
DB_PORT="${POSTFIXADMIN_DB_PORT:-3306}"
DB_NAME="${POSTFIXADMIN_DB_NAME:-email}"

echo "Reloading test seed data..."
echo ""

# Verify the schema manager exists
if [[ ! -f "${SCHEMA_MANAGER}" ]]; then
    echo "Error: Schema manager not found at ${SCHEMA_MANAGER}"
    exit 1
fi

# Verify seed directory exists
if [[ ! -d "${SEED_DIR}" ]]; then
    echo "Error: Seed directory not found at ${SEED_DIR}"
    exit 1
fi

# Verify password secret exists
if [[ ! -f /run/secrets/mysql_root_password ]]; then
    echo "Error: Database password secret not found at /run/secrets/mysql_root_password"
    exit 1
fi

# Run the schema manager to apply all seed files
# This uses the same approach as the entrypoint.sh script
"${SCHEMA_MANAGER}" \
	--stage production \
	--db-host "${DB_HOST}" \
	--db-port "${DB_PORT}" \
	--db-user root \
	--password-file /run/secrets/mysql_root_password \
	--default-db-name "${DB_NAME}" \
	--settings-table dbschema_settings \
	--version-key seed_version \
	--ddl-directory "${SEED_DIR}" \
	--ddl-extension sql

if [ 0 -ne $? ]; then
    echo "Error: Test seed data application failed"
    exit 1
fi

echo ""
echo "Test seed data reloaded successfully"
echo "  - All seed files have been applied"
echo "  - Seed version tracker updated"

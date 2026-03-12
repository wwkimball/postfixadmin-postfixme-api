#!/usr/bin/env bash
################################################################################
# Purge all test seed data from the database
#
# This script removes all data related to the test domains (acme.local and
# zenith.local) and resets the seed version tracker. This allows QA testers
# to completely reset the test environment to a clean state.
#
# Usage (from host):
#   ./compose.sh exec postfixadmin purge-test-data.sh
#
# Usage (inside container):
#   /usr/local/bin/purge-test-data.sh
#
# Copyright 2026 William W. Kimball, Jr., MBA, MSIS
################################################################################
set -euo pipefail

# Database connection parameters (from environment variables set by Docker Compose)
DB_HOST="${MYSQL_HOST:-database}"
DB_NAME="${POSTFIXADMIN_DB_NAME:-email}"
DB_USER="${MYSQL_USER:-root}"

# Read password from secret file (Docker Compose mounts secrets at /run/secrets)
if [[ -f /run/secrets/mysql_root_password ]]; then
    DB_PASSWORD="$(cat /run/secrets/mysql_root_password)"
else
    echo "Error: Database password secret not found at /run/secrets/mysql_root_password"
    exit 1
fi

# Settings table configuration (from environment)
SETTINGS_TABLE="${DBSCHEMA_SETTINGS_TABLE:-dbschema_settings}"
SETTINGS_NAME_COLUMN="${DBSCHEMA_SETTINGS_NAME_COLUMN:-name}"
SETTINGS_VALUE_COLUMN="${DBSCHEMA_SETTINGS_VALUE_COLUMN:-value}"

echo "Purging test seed data from database..."

# Execute SQL to purge all test data
mysql -h"${DB_HOST}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" <<SQL
-- Disable foreign key checks to allow deletion in any order
SET FOREIGN_KEY_CHECKS = 0;

-- Delete aliases for test domains
DELETE FROM alias WHERE domain IN ('acme.local', 'zenith.local');

-- Delete mailboxes for test domains
DELETE FROM mailbox WHERE domain IN ('acme.local', 'zenith.local');

-- Delete domain admin mappings
DELETE FROM domain_admins WHERE domain IN ('acme.local', 'zenith.local');

-- Delete admin accounts for test domains
DELETE FROM admin WHERE username IN ('admin@acme.local', 'admin@zenith.local');

-- Delete the test domains
DELETE FROM domain WHERE domain IN ('acme.local', 'zenith.local');

-- Remove the seed version tracker
DELETE FROM ${SETTINGS_TABLE} WHERE ${SETTINGS_NAME_COLUMN} = 'seed_version';

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;
SQL

echo "Test seed data purged successfully"
echo "  - Removed all data for acme.local and zenith.local"
echo "  - Reset seed version tracker"
echo ""
echo "To reload test data, run: ./reload-test-data.sh"

#!/usr/bin/env bash
################################################################################
# Purge all test seed data from the database
#
# This script removes all data related to the test domains (acme.local and
# zenith.local) and resets the seed version tracker. This allows QA testers
# to completely reset the test environment to a clean state.
#
# Usage (from host):
#   ./compose.sh exec pfme-api purge-test-data.sh
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
	echo "ERROR:  Database password secret not found at /run/secrets/mysql_root_password" >&2
	exit 1
fi

# Settings table configuration (from environment)
SETTINGS_TABLE="${DBSCHEMA_SETTINGS_TABLE:-dbschema_settings}"
SETTINGS_NAME_COLUMN="${DBSCHEMA_SETTINGS_NAME_COLUMN:-name}"
SETTINGS_VALUE_COLUMN="${DBSCHEMA_SETTINGS_VALUE_COLUMN:-value}"

# Detect the mysql/mariadb client command
_sqlCommand=""
if command -v mariadb &>/dev/null; then
	_sqlCommand=mariadb
elif command -v mysql &>/dev/null; then
	_sqlCommand=mysql
else
	echo "ERROR:  Neither 'mariadb' nor 'mysql' client was found." >&2
	exit 3
fi

###
# Detect the correct TLS disable option for the SQL client.
#
# Some clients require TLS by default; when the server does not support TLS the
# connection fails with ERROR 2026 (HY000).  This function probes a series of
# known TLS-disabling flags and returns the first one that produces a successful
# connection, or an empty string when no flag is needed (or none works).
#
# @return <integer> 0 on success; 1 when all probed options failed
# @return via STDOUT <string> The working TLS disable option, or empty string
##
function detectTLSOption {
	# First test whether TLS is already disabled by default (no option needed)
	local defaultTestResult
	defaultTestResult=$("$_sqlCommand" \
		--host="$DB_HOST" \
		--user="$DB_USER" \
		--password="$DB_PASSWORD" \
		--execute="SELECT 1" \
		2>/dev/null || true)

	if [[ "$defaultTestResult" =~ ^1$ ]] || [[ "$defaultTestResult" =~ $'\n'1$ ]]; then
		echo ""
		return 0
	fi

	# Default connection failed; probe known TLS-disabling options
	local testOptions=("--ssl-mode=DISABLED" "--skip-ssl" "--skip_ssl")
	local helpOutput
	helpOutput=$("$_sqlCommand" --help 2>/dev/null || true)

	for testOption in "${testOptions[@]}"; do
		# Confirm the option is recognized by this client before testing it
		local optionName="${testOption#--}"
		optionName="${optionName%%=*}"
		if [[ ! "$helpOutput" =~ $optionName ]]; then
			continue
		fi

		local testResult
		testResult=$("$_sqlCommand" \
			--host="$DB_HOST" \
			--user="$DB_USER" \
			--password="$DB_PASSWORD" \
			"$testOption" \
			--execute="SELECT 1" \
			2>/dev/null || true)

		if [[ "$testResult" =~ ^1$ ]] || [[ "$testResult" =~ $'\n'1$ ]]; then
			echo "$testOption"
			return 0
		fi
	done

	echo ""
	return 1
}

# Build the array of TLS options to pass to every SQL client invocation
declare -a _tlsOptions=()
_detectedTLSOption=$(detectTLSOption)
_detectExitCode=$?
if [ -n "$_detectedTLSOption" ]; then
	_tlsOptions=("$_detectedTLSOption")
elif [ $_detectExitCode -ne 0 ]; then
	echo "WARNING:  Could not establish a database connection; all TLS option probes failed." >&2
fi
unset _detectedTLSOption _detectExitCode

echo "Purging test seed data from database..."

# Execute SQL to purge all test data
"$_sqlCommand" \
	--host="$DB_HOST" \
	--user="$DB_USER" \
	--password="$DB_PASSWORD" \
	"${_tlsOptions[@]}" \
	"${DB_NAME}" <<SQL
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

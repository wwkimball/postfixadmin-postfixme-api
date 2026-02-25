#!/bin/bash
################################################################################
# Entrypoint for PFME API container:
# - fixes secret permissions for Apache/PHP access
# - performs database schema management tasks (if any) using the helper library
# - drops to CMD
################################################################################
set -eu

# Constants
INSTALL_DIRECTORY=/opt/dbutils
SECRETS_DIRECTORY=/run/secrets
PFME_WEB_ROOT=/var/www/pfme-api
LIB_DIRECTORY="${INSTALL_DIRECTORY}/lib"
readonly INSTALL_DIRECTORY SECRETS_DIRECTORY PFME_WEB_ROOT LIB_DIRECTORY

# Import the shell helpers
if ! source "${LIB_DIRECTORY}/shell-helpers.sh"; then
	echo "ERROR:  Failed to import shell helpers!" >&2
	exit 2
fi

if [ -d "$SECRETS_DIRECTORY" ]; then
    for f in "$SECRETS_DIRECTORY"/*; do
        if [ -f "$f" ]; then
            chown www-data:www-data "$f" || true
            chmod 600 "$f" || true
        fi
    done
fi

if [ -d "$PFME_WEB_ROOT" ]; then
    chown -R www-data:www-data "$PFME_WEB_ROOT" || true
fi

# Keep the database schema up-to-date.  Notes:
# - The deployment stage must be any supported value other than "development".
#   Otherwise, the script will incorrectly assume that it is running on the bare
#   developer's workstation rather than from within a Docker container.
"${LIB_DIRECTORY}"/database/schema/mysql.sh \
	--stage production \
	--db-host "${POSTFIXADMIN_DB_HOST}" \
	--db-port "${POSTFIXADMIN_DB_PORT}" \
	--db-user root \
	--password-file /run/secrets/mysql_root_password \
	--default-db-name "${POSTFIXADMIN_DB_NAME}" \
	--settings-table "${DBSCHEMA_SETTINGS_TABLE}" \
    --version-key 'pfme.schema.version' \
	--ddl-directory "${INSTALL_DIRECTORY}/schema/mysql" \
	--ddl-extension sql
if [ 0 -ne $? ]; then
	errorOut 1 "Database schema update failed; aborting bootstrap."
fi
logInfo "Database schema update completed successfully."

# Apply test seed data if present (development, lab, qa stages only)
# Seed files are excluded from staging and production images via multi-stage builds
if [ -d "${INSTALL_DIRECTORY}/test-data/" ]; then
	# Wait for PostfixAdmin tables to exist (by checking for the 'admin' table),
    # which is necessary for the seed data to load successfully.
	logInfo "Waiting for PostfixAdmin to initialize database tables..."

	# Determine which MySQL client is available (prefer mariadb over mysql)
	if command -v mariadb >/dev/null 2>&1; then
		MYSQL_CLIENT="mariadb"
	elif command -v mysql >/dev/null 2>&1; then
		MYSQL_CLIENT="mysql"
	else
		errorOut 1 "Neither mariadb nor mysql client is installed; cannot verify database readiness."
	fi

	MYSQL_ROOT_PASSWORD="$(cat /run/secrets/mysql_root_password)"
	while ! ${MYSQL_CLIENT} -h "${POSTFIXADMIN_DB_HOST}" \
		-P "${POSTFIXADMIN_DB_PORT}" \
		-u root \
		-p"${MYSQL_ROOT_PASSWORD}" \
		"${POSTFIXADMIN_DB_NAME}" \
		-e "SELECT 1 FROM admin LIMIT 1" >/dev/null 2>&1; do
		sleep 1
	done
	logInfo "PostfixAdmin database tables are ready."

	logInfo "Applying test seed data (idempotent operation)..."
	"${LIB_DIRECTORY}"/database/schema/mysql.sh \
		--stage production \
		--db-host "${POSTFIXADMIN_DB_HOST}" \
		--db-port "${POSTFIXADMIN_DB_PORT}" \
		--db-user root \
		--password-file /run/secrets/mysql_root_password \
		--default-db-name "${POSTFIXADMIN_DB_NAME}" \
		--settings-table "${DBSCHEMA_SETTINGS_TABLE}" \
		--version-key pfme.schema.seed.version \
		--ddl-directory "${INSTALL_DIRECTORY}/test-data" \
		--ddl-extension sql
	if [ 0 -ne $? ]; then
		errorOut 1 "Test seed data application failed; aborting bootstrap."
	fi
	logInfo "Test seed data applied successfully."
else
	logInfo "No test seed data directory found (staging/production mode)."
fi

exec "$@"

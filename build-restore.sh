#!/usr/bin/env bash
################################################################################
# Restore source files from shelved directories if a transient build was
# interrupted or failed.
#
# This script is normally not needed because build.sh automatically restores
# files on any exit.  However, if the build fails in an unexpected way, you can
# manually run this script to restore your source files from shelved backups.
#
# Usage:
#   ./build-restore.sh
#
# Copyright 2026 William W. Kimball, Jr. MBA MSIS
# All rights reserved.
################################################################################
# Constants
MY_DIRECTORY="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIB_DIRECTORY="${MY_DIRECTORY}/lib"
readonly MY_DIRECTORY LIB_DIRECTORY

# Import the shell helpers
if ! source "${LIB_DIRECTORY}/shell-helpers.sh"; then
	echo "ERROR:  Failed to import shell helpers!" >&2
	exit 2
fi

# Derived constants
SCHEMA_DIR="${MY_DIRECTORY}/schema"
SHELVED_SCHEMA_DIR="${MY_DIRECTORY}/shelved-schema"
TEST_DATA_DIR="${MY_DIRECTORY}/test-data"
SHELVED_TEST_DATA_DIR="${MY_DIRECTORY}/shelved-test-data"
PFA_CONF_DIR="${MY_DIRECTORY}/docker/postfixadmin/config"
PFA_CONF_LOCAL_FILE="${PFA_CONF_DIR}/config.local.php"
TRANSIENT_MARKER="${MY_DIRECTORY}/.transient-build-marker"

# Track whether any restoration happened
_didRestore=false

# Restore schema files if shelved
if [ -d "$SHELVED_SCHEMA_DIR" ]; then
	logInfo "Restoring shelved schema directory:  ${SHELVED_SCHEMA_DIR} -> ${SCHEMA_DIR}"
	rm -rf "$SCHEMA_DIR"
	if ! mv "$SHELVED_SCHEMA_DIR" "$SCHEMA_DIR"; then
		logWarning "Failed to rotate the shelved schema directory back to active!"
	else
		_didRestore=true
	fi
fi

# Restore test-data files if shelved
if [ -d "$SHELVED_TEST_DATA_DIR" ]; then
	logInfo "Restoring shelved test-data directory:  ${SHELVED_TEST_DATA_DIR} -> ${TEST_DATA_DIR}"
	rm -rf "$TEST_DATA_DIR"
	if ! mv "$SHELVED_TEST_DATA_DIR" "$TEST_DATA_DIR"; then
		logWarning "Failed to rotate the shelved test-data directory back to active!"
	else
		_didRestore=true
	fi
fi

# # Clean up processed PostfixAdmin configuration files
# if [ -f "$PFA_CONF_LOCAL_FILE" ]; then
# 	logInfo "Removing processed PostfixAdmin configuration file:  ${PFA_CONF_LOCAL_FILE}"
# 	if ! rm -f "$PFA_CONF_LOCAL_FILE"; then
# 		logWarning "Failed to delete the PostfixAdmin configuration file!"
# 	else
# 		_didRestore=true
# 	fi
# fi

# Report results
if $_didRestore; then
	logInfo "Source files have been restored."
else
	logInfo "No shelved files found to restore."
fi

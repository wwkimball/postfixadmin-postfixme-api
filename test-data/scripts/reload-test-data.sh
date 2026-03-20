#!/usr/bin/env bash
################################################################################
# Purge from and reload all test seed data into the database
#
# This script removes all data related to the test domains (acme.local and
# zenith.local), resets the seed version tracker, and then reloads the test seed
# data. This allows QA testers and demonstrators to completely reset the test
# environment to a known ready state.
#
# Usage (from host):
#   ./compose.sh exec pfme-api reload-test-data.sh
#
# Usage (inside container):
#   /usr/local/bin/reload-test-data.sh
#
# Copyright 2026 William W. Kimball, Jr., MBA, MSIS
################################################################################
set -euo pipefail

# Paths as they exist in the container (see Dockerfile)
SCRIPT_DIR="/usr/local/bin"
PURGE_SCRIPT="${SCRIPT_DIR}/purge-test-data.sh"
LOAD_SCRIPT="${SCRIPT_DIR}/load-test-data.sh"

echo "Reloading test seed data..."
echo ""

# Verify the purge script exists
if [[ ! -f "${PURGE_SCRIPT}" ]]; then
	echo "Error: Purge script not found at ${PURGE_SCRIPT}" >&2
	exit 2
fi

# Verify the load script exists
if [[ ! -f "${LOAD_SCRIPT}" ]]; then
	echo "Error: Load script not found at ${LOAD_SCRIPT}" >&2
	exit 2
fi

# Run the purge script to clear existing test data
echo "Purging existing test data..."
"${PURGE_SCRIPT}"
if [ 0 -ne $? ]; then
	echo "Error: Failed to purge test data" >&2
	exit 3
fi

# Run the load script to apply all seed files
echo "Loading fresh test seed data..."
"${LOAD_SCRIPT}"
if [ 0 -ne $? ]; then
	echo "Error: Failed to load test data" >&2
	exit 4
fi

echo ""
echo "Test seed data reloaded successfully. The test environment is now reset to a known state with fresh seed data."

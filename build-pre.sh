#!/usr/bin/env bash
################################################################################
# Prepare the filesystem for a fresh build.
#
# When running in development, the template files are preserved between builds.
#
# Copyright 2025 William W. Kimball, Jr. MBA MSIS
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

# Accept command-line arguments
_deploymentStage=${1:?"ERROR:  The name of the deployment stage must be the first command-line argument!"}
_bakedComposeFile=${2:?"ERROR:  The path to a baked Docker Compose file must be the second command-line argument!"}

# Check if running in a transient build mode
_isDevelopment=false
if [ "$_deploymentStage" == "development" ] \
	|| [ -f "${MY_DIRECTORY}/.transient-build-marker" ]
then
	_isDevelopment=true
fi

# Derived constants
DOCKER_DIR="${MY_DIRECTORY}/docker"
SCHEMA_DIR="${MY_DIRECTORY}/schema"
SHELVED_SCHEMA_DIR="${MY_DIRECTORY}/shelved-schema"
TEST_DATA_DIR="${MY_DIRECTORY}/test-data"
SHELVED_TEST_DATA_DIR="${MY_DIRECTORY}/shelved-test-data"
SECRETS_DIR="${DOCKER_DIR}/secrets"

# Load relevant environment variables
dynamicSourceEnvFiles "$DOCKER_DIR" "$_deploymentStage"

# Load the content of Docker secrets into the environment
dynamicExposeDockerSecretFiles "$SECRETS_DIR"

# Find directories containing database schema templates
declare -A schemaTemplateDirectories
while IFS= read -r -d '' templateFile; do
	templateDirectory="$(dirname "$templateFile")"
	schemaTemplateDirectories["$templateDirectory"]=1
done < <(find "$SCHEMA_DIR" "$TEST_DATA_DIR" -type f -iname '*template*' -print0)

# This step intermittently fails on MacOS with the multi-directory `find`
# command sometimes returning an empty result, which causes the templates to be
# copied verbatim rather than being processed.  To mitigate the resulting
# inevitable bad build, we abort here whenever there are no discovered schema
# template directories.
if [ ${#schemaTemplateDirectories[@]} -eq 0 ]; then
	errorOut 2 "No schema template files found, cannot proceed with build!  This
		may have been an ephemeral issue with the find command, so try running
		the build again.  If the issue persists, investigate potential problems
		with the find command on your system."
else
	logInfo "Discovered schema template directories:  ${!schemaTemplateDirectories[@]}"
fi

if $_isDevelopment; then
	# Rotate the schema files between the schema and shelved-schema directories;
	# but if the shelved-schema directory exists, then a previous build failed.
	# In that case, run the build-post.sh script to clean up the shelved-schema
	# directory.
	if [ -d "$SHELVED_SCHEMA_DIR" ]; then
		if ! "${MY_DIRECTORY}/build-post.sh" "$_deploymentStage" "$_bakedComposeFile"
		then
			errorOut 3 "Failed to clean up the shelved schema directory."
		fi
	fi

	mkdir -p "$SHELVED_SCHEMA_DIR"
	if [ -d "$SCHEMA_DIR" ]; then
		logInfo "Duplicating the schema directory:  ${SCHEMA_DIR} -> ${SHELVED_SCHEMA_DIR}"
		cp -R "$SCHEMA_DIR"/* "$SHELVED_SCHEMA_DIR"
	fi

	# Also rotate test-data files for seeded deployments
	if [ -d "$SHELVED_TEST_DATA_DIR" ]; then
		if ! "${MY_DIRECTORY}/build-post.sh" "$_deploymentStage" "$_bakedComposeFile"
		then
			errorOut 3 "Failed to clean up the shelved test-data directory."
		fi
	fi

	mkdir -p "$SHELVED_TEST_DATA_DIR"
	if [ -d "$TEST_DATA_DIR" ]; then
		logInfo "Duplicating the test-data directory:  ${TEST_DATA_DIR} -> ${SHELVED_TEST_DATA_DIR}"
		cp -R "$TEST_DATA_DIR"/* "$SHELVED_TEST_DATA_DIR"
	fi
fi

# Only process template directories if template files were actually found
if [ ${#schemaTemplateDirectories[@]} -eq 0 ]; then
	errorOut 4 "No mandatory schema template files found."
else
	for templateDirectory in "${!schemaTemplateDirectories[@]}"; do
		logInfo "Processing schema templates in:  ${templateDirectory}."
		if ! "${MY_DIRECTORY}/process-templates.sh" \
			"$_deploymentStage" \
			"$_bakedComposeFile" \
			--directory "$templateDirectory" \
			--force
		then
			errorOut 5 "Failed to process schema template(s) in:  ${templateDirectory}."
		fi
	done
fi

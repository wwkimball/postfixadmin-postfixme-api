#!/usr/bin/env bash
################################################################################
# Clean up from template processing in transient and development builds.
#
# This script delegates restoration to build-restore.sh to maintain DRY
# principle. It handles the logic of determining whether restoration should
# occur (for development or transient builds) and then calls the restoration
# script.
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
_deploymentStage=${1:?"ERROR:  DEPLOYMENT_STAGE must be the first command-line argument!"}
_bakedComposeFile=${2:?"ERROR:  DOCKER_COMPOSE_FILE must be the second command-line argument!"}

# Check if this is a transient build or development stage
_isTransientBuild=false
if [ "$_deploymentStage" == "development" ] \
	|| [ -f "${MY_DIRECTORY}/.transient-build-marker" ]
then
	_isTransientBuild=true
fi

# Bail out when not running a transient build or development mode
if ! $_isTransientBuild; then
	logInfo "Skipping cleanup for non-transient build mode:  ${_deploymentStage}"
	exit 0
fi

# Delegate to build-restore.sh to perform the actual restoration
"${MY_DIRECTORY}/build-restore.sh"

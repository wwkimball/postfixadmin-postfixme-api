#!/usr/bin/env bash
###############################################################################
# Build Docker image(s) for this project.
#
# Copyright 2025 William W. Kimball, Jr. MBA MSIS
# All rights reserved.
###############################################################################
# Constants
MY_DIRECTORY="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIB_DIRECTORY="${MY_DIRECTORY}/lib"
DOCKER_HELPER_DIRECTORY="${LIB_DIRECTORY}/docker"
readonly MY_DIRECTORY LIB_DIRECTORY DOCKER_HELPER_DIRECTORY

# Import the shell helpers
if ! source "${LIB_DIRECTORY}/shell-helpers.sh"; then
	echo "ERROR:  Failed to import shell helpers!" >&2
	exit 2
fi

# Restore shelved files (called on any exit in transient mode only)
function _restoreFromTransientBuild {
	# Clean up the marker file and restore shelved files, but suppress output
	# because this is a trap handler and may be running during an unexpected
	# exit.
	rm -f "${MY_DIRECTORY}/.transient-build-marker"
	"${MY_DIRECTORY}/build-restore.sh" &>/dev/null
}

# Process arguments to detect a transient build mode (used for local dev and QA
# iteration with source protection)
_transientBuild=false
_buildArgs=()
for arg in "$@"; do
	if [ "$arg" = "--transient-build" ]; then
		# Register trap handler ONLY in transient build mode
		_transientBuild=true
		touch "${MY_DIRECTORY}/.transient-build-marker"
		trap _restoreFromTransientBuild EXIT
	else
		_buildArgs+=("$arg")
	fi
done

# Pass control to the docker helper script (without --transient-build flag)
exec "${DOCKER_HELPER_DIRECTORY}/compose-build.sh" "${_buildArgs[@]}"

#!/usr/bin/env bash
###############################################################################
# Stop the environment.
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

# Pass control to the docker helper script
exec "${DOCKER_HELPER_DIRECTORY}/compose-stop.sh" "$@"

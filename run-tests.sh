#!/usr/bin/env bash
################################################################################
# Run one-off tests for this project.
#
# DO NOT RUN THIS SCRIPT AS-IS IN YOUR CI/CD PIPELINE!  This script is for local
# development and debugging only.  A CI/CD version of this script must down the
# stack when done.
#
# Includes PHP unit and integration tests for the API.
#
# Copyright 2026 William W. Kimball, Jr. MBA MSIS
# All rights reserved.
################################################################################
./stop.sh --clean ||:

if ! ./build.sh --clean --no-push --no-portable --stage qa
then
	echo "ERROR:  Build failed, cannot run tests!" >&2
	exit 1
fi

# Note that the whole stack will be started to support the tests, but the test
# container will run the test script and then exit, leaving the rest of the
# stack running for inspection if needed.
./compose.sh --stage qa run --rm pfme-api-tests run-phpunit-tests.sh

# Stop the stack, cleaning up all related Docker artifacts.
./stop.sh --stage qa --clean --destroy-volumes

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
exitState=0

# Ensure no services are running (don't destroy dev volumes!)
./stop.sh --clean --stage development ||:
./stop.sh --clean --destroy-volumes --stage qa ||:

# Build -- but do not start -- the full QA stack.  If you try to start the stack
# Docker Compose will attempt to start pfme-api-tests, which will appear to fail
# because it is a one-off test container.  Use --transient-build so that all
# template processing can be reverted upon completion.
if ! ./build.sh --transient-build --clean --no-push --no-portable --stage qa
then
	echo "ERROR:  Build failed, cannot run tests!" >&2
	exit 1
fi

# Note that the whole stack will be automatically started on-demand to support
# the tests, but the test container will run the test script and then exit,
# leaving the rest of the stack running.
if ! ./compose.sh --stage qa run --rm pfme-api-tests run-phpunit-tests.sh; then
	echo "ERROR:  Tests failed!" >&2
	exitState=86
fi

# Optionally keep the stack running or clean up all related Docker artifacts.
if [ "${1:-}" == "--keep-running" ]; then
	echo "Keeping stack running, not cleaning up Docker artifacts."
else
	echo "Cleaning up Docker artifacts and stopping stack."
	./stop.sh --stage qa --clean --destroy-volumes
fi

# Indicate success/fail
exit $exitState

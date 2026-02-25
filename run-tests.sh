#!/usr/bin/env bash
################################################################################
# Run tests for this project.
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

if ! ./start.sh --stage qa; then
	echo "ERROR:  Failed to start services, cannot run tests!" >&2
	exit 2
fi

./compose.sh --quiet --stage qa run --rm pfme-api-tests vendor/bin/phpunit

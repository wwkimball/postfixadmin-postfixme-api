#!/usr/bin/env bash
################################################################################
# Run tests for this project.
#
# Includes PHP unit and integration tests for the API.
#
# Copyright 2026 William W. Kimball, Jr. MBA MSIS
# All rights reserved.
################################################################################
set -eu

if ! cd /var/www/pfme-api; then
	echo "ERROR:  Failed to change to API directory, cannot run tests!" >&2
	exit 2
fi

exec ./vendor/bin/phpunit tests

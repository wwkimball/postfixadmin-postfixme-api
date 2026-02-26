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

./vendor/bin/phpunit --display-all-issues | tee phpunit-test-output.log
exitState=${PIPESTATUS[0]}

# If there are an F, E, or R in the captured output, treat it as a failure
# regardless of the exit code, because PHPUnit usually only returns a non-zero
# exit code when PHPUnit itself cannot run, not when tests fail.
if [ 0 -eq $exitState ]; then
	resultLines=$(grep -E '^[.FEWR]+( +[0-9]+ / [0-9]+|)$' phpunit-test-output.log)
	if echo "$resultLines" | grep -E '[FEWR]' &>/dev/null; then
		echo "ERROR:  Detected test failure(s)!" >&2
		exitState=86
	fi
fi

# When a test failure is being indicated, run a debug version of the tests and
# from it, capture the test failure conditions.
if [ 0 -ne $exitState ]; then
	echo "Running debug version of tests to capture failure conditions:" >&2
	./vendor/bin/phpunit --display-all-issues --debug &>phpunit-debug-test-output.log
	grep -A2 -B2 'Test Failed' phpunit-debug-test-output.log >&2
fi

exit $exitState

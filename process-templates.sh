#!/usr/bin/env bash
################################################################################
# Process templates using the standard shell library's template processor.
#
# Usage:
# process-templates \
#   <DEPLOYMENT_STAGE> \
#   <BAKED_DOCKER_COMPOSE_FILE> \
#   [PROCESSOR_ARGS...]
#
# Where:
#   <DEPLOYMENT_STAGE> Name of the deployment stage (e.g., development, staging)
#   <BAKED_DOCKER_COMPOSE_FILE> Path to the baked Docker Compose file
#   <PROCESSOR_ARGS> Additional arguments to be passed to the template processor
#
# Control the variables that will be substituted by editing the
# template-variables.txt file in the same directory as this script.  Full-line
# comments and blank lines are supported in that file.
#
# All command-line arguments passed to this script are added to the prefab set
# of arguments which are passed on to the template processor.  The prefab set of
# arguments includes:
#   --stage <DEPLOYMENT_STAGE>
#   <PROCESSOR_ARGS>
#   <all environment variable names listed in template-variables.txt>
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

# Accept mandatory command-line arguments
if [ "$#" -lt 2 ]; then
	errorOut 1 "Usage: process-templates <DEPLOYMENT_STAGE> <BAKED_DOCKER_COMPOSE_FILE> [PROCESSOR_ARGS...]"
fi

# Capture the deployment stage and baked Docker Compose file
deploymentStage=${1:?ERROR:  The deployment stage must be the first command-line argument to ${BASH_SOURCE[0]}}
bakedComposeFile=${2:?ERROR:  The baked Docker Compose file must be the second command-line argument to ${BASH_SOURCE[0]}}
shift 2

# Build the command line arguments array
declare -a commandLineArgs=(--stage "$deploymentStage")
commandLineArgs+=("$@")

# Load all template variables as a commented list from the
# template-variables.txt file.
declare -a templateVars
while IFS= read -r line; do
	# Ignore empty lines
	if [[ -z "$line" ]]; then
		continue
	fi

	# Ignore commented lines
	if [[ "$line" =~ ^[[:space:]]*# ]]; then
		continue
	fi

	templateVars+=("$line")
done <"${MY_DIRECTORY}/template-variables.txt"

# Attempt to load every declared variable from any available source; ensure each
# ultimately has a value.  Indicate all missing variables in a single error
# message rather than stopping at the first.
hasError=false
for templateVar in "${templateVars[@]}"; do
	if possibleValue=$(
		getDockerEnvironmentVariable \
			"$templateVar" \
			"$bakedComposeFile" \
			"$deploymentStage"
	); then
		# Function succeeded and returned a value
		if [ -n "$possibleValue" ]; then
			export "$templateVar=$possibleValue"
		fi
	else
		# Function failed with an error (return codes 1 or 2)
		returnCode=$?
		if [ "$returnCode" -eq 1 ]; then
			logError "Invalid arguments or configuration error for variable ${templateVar}."
		elif [ "$returnCode" -eq 2 ]; then
			logError "Docker configuration files could not be processed for variable ${templateVar}."
		fi
		hasError=true
	fi

	if [ -z "${!templateVar}" ]; then
		logError "The environment variable ${templateVar} must be set."
		hasError=true
	fi
done
if $hasError; then
	errorOut 1 "One or more required environment variables are not set."
fi

if ! "${LIB_DIRECTORY}/processors/templates/process-templates.sh" \
	"${commandLineArgs[@]}" \
	${templateVars[@]}
then
	errorOut 4 "Failed to process templates."
fi

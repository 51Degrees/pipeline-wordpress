# The run-integration-tests.ps1 script handles the real package installation,
# because it has to be done both in the Pull Requests and the Publish workflow,
# but this script only runs in Publish. The purpose of this script is just to
# install test dependencies for the "integration" tests that run with phpunit.
$ErrorActionPreference = "Stop"
$PSNativeCommandUseErrorActionPreference = $true
composer install --working-dir "$PSScriptRoot/../lib" --no-interaction

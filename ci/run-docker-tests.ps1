# End-to-end integration tests against a running WordPress Docker environment.
#
# Prerequisites:
#   - docker compose services (wordpress + db) are running
#   - SUPER_RESOURCE_KEY environment variable is set
#
# Tests:
#   1. Plugin activates successfully
#   2. Settings page loads (HTTP 200)
#   3. REST endpoint responds (HTTP 200)
#   4. Device detection JavaScript is injected into page output

$ErrorActionPreference = "Stop"

if (-not $env:SUPER_RESOURCE_KEY) {
    Write-Output "::warning::No SUPER_RESOURCE_KEY provided, skipping Docker integration tests."
    exit 0
}

$baseUrl = "http://localhost:8080"
$composeFile = "$PSScriptRoot/docker-compose.test.yml"
$failed = 0
$passed = 0

function Test-Check {
    param(
        [string]$Name,
        [scriptblock]$Check
    )

    try {
        $result = & $Check
        if ($result) {
            Write-Output "  PASS: $Name"
            $script:passed++
        }
        else {
            Write-Output "  FAIL: $Name"
            $script:failed++
        }
    }
    catch {
        Write-Output "  FAIL: $Name - $_"
        $script:failed++
    }
}

# --- Wait for WordPress to respond ---

Write-Output "Waiting for WordPress to be ready..."
$maxRetries = 30
$retryInterval = 5

for ($i = 0; $i -lt $maxRetries; $i++) {
    try {
        $response = Invoke-WebRequest -Uri $baseUrl -UseBasicParsing -TimeoutSec 5 -ErrorAction SilentlyContinue
        if ($response.StatusCode -eq 200 -or $response.StatusCode -eq 302) {
            Write-Output "WordPress is ready."
            break
        }
    }
    catch {
        # Not ready yet
    }

    if ($i -eq ($maxRetries - 1)) {
        Write-Error "WordPress did not become ready within $($maxRetries * $retryInterval) seconds."
        exit 1
    }

    Write-Output "  Retrying in $retryInterval seconds... ($($i + 1)/$maxRetries)"
    Start-Sleep -Seconds $retryInterval
}

# --- Install WP-CLI inside the container ---

Write-Output "Installing WP-CLI..."
docker compose -f $composeFile exec -T wordpress bash -c "curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp"
if ($LASTEXITCODE -ne 0) {
    Write-Error "Failed to install WP-CLI."
    exit 1
}

# --- Install WordPress ---

Write-Output "Installing WordPress..."
docker compose -f $composeFile exec -T wordpress wp core install `
    --url="$baseUrl" `
    --title="51Degrees Test Site" `
    --admin_user="admin" `
    --admin_password="admin" `
    --admin_email="test@example.com" `
    --skip-email `
    --allow-root
if ($LASTEXITCODE -ne 0) {
    Write-Error "Failed to install WordPress."
    exit 1
}

# --- Activate the plugin ---

Write-Output "Activating 51Degrees plugin..."
docker compose -f $composeFile exec -T wordpress wp plugin activate fiftyonedegrees --allow-root
if ($LASTEXITCODE -ne 0) {
    Write-Error "Failed to activate plugin."
    exit 1
}

# --- Configure the resource key and build the pipeline ---
#
# wp option update on a new option internally calls add_option(), which fires
# add_option action — NOT update_option. The plugin's pipeline-build hook is
# on update_option, so it would not trigger. Instead, we use wp eval to set
# the resource key and explicitly build the pipeline.

Write-Output "Configuring resource key and building pipeline..."
$resourceKey = $env:SUPER_RESOURCE_KEY
docker compose -f $composeFile exec -T wordpress wp eval @"
`$key = '$resourceKey';
update_option('fiftyonedegrees_resource_key', `$key);
`$pipeline = Pipeline::make_pipeline(`$key);
if (`$pipeline['error']) {
    fwrite(STDERR, 'Pipeline build error: ' . `$pipeline['error'] . PHP_EOL);
    exit(1);
}
update_option('fiftyonedegrees_resource_key_pipeline', `$pipeline);
echo 'Pipeline built. Engines: ' . implode(', ', `$pipeline['available_engines']) . PHP_EOL;
"@ --allow-root
if ($LASTEXITCODE -ne 0) {
    Write-Error "Failed to configure resource key and build pipeline."
    exit 1
}

# --- Run verification tests ---
#
# Tests use curl from the host (port 8080) rather than from inside the
# container (port 80) because WordPress redirects internal requests to the
# configured site URL (localhost:8080).

Write-Output ""
Write-Output "Running verification tests..."

# Test 1: Plugin is active
Test-Check "Plugin is listed as active" {
    $output = docker compose -f $composeFile exec -T wordpress wp plugin list --status=active --field=name --allow-root
    $output -match "fiftyonedegrees"
}

# Test 2: Admin settings page loads
Test-Check "Settings page loads (HTTP 200)" {
    $response = Invoke-WebRequest -Uri "$baseUrl/wp-login.php" `
        -Method POST `
        -Body @{ log = "admin"; pwd = "admin"; "wp-submit" = "Log In"; redirect_to = "/wp-admin/" } `
        -SessionVariable session `
        -UseBasicParsing
    $settingsPage = Invoke-WebRequest -Uri "$baseUrl/wp-admin/options-general.php?page=51Degrees" `
        -WebSession $session `
        -UseBasicParsing
    $settingsPage.StatusCode -eq 200
}

# Test 3: REST endpoint responds
Test-Check "REST endpoint responds (HTTP 200)" {
    $response = Invoke-WebRequest -Uri "$baseUrl/wp-json/fiftyonedegrees/v4/json" `
        -Method POST `
        -UseBasicParsing
    $response.StatusCode -eq 200
}

# Test 4: Device detection JavaScript is injected
Test-Check "51Degrees JavaScript snippet is injected into page" {
    $response = Invoke-WebRequest -Uri "$baseUrl/" `
        -Headers @{ "User-Agent" = "Mozilla/5.0 (Linux; Android 10; SM-G973F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.120 Mobile Safari/537.36" } `
        -UseBasicParsing
    $response.Content -match "fiftyonedegrees-js-before"
}

# --- Report results ---

Write-Output ""
Write-Output "============================================"
Write-Output "Results: $passed passed, $failed failed"
Write-Output "============================================"

if ($failed -gt 0) {
    exit 1
}

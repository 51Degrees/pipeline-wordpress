param (
    [Parameter(Mandatory)][string]$RepoName,
    [Parameter(Mandatory)][hashtable]$Keys
)
$ErrorActionPreference = "Stop"

if (!$Keys.TestResourceKey) {
    Write-Host "::warning file=$($MyInvocation.ScriptName),line=$($MyInvocation.ScriptLineNumber),title=No Resource Key::No resource key was provided, so integration tests will not run."
    return
}

$env:RESOURCEKEY = $Keys.TestResourceKey
./php/run-integration-tests.ps1 -RepoName:$RepoName

$passed = 0
$failed = 0
function Test-WordPress ([Parameter(Position=0)][string]$name, [Parameter(Position=1)][scriptblock]$check) {
    try {
        if (& $check) {
            Write-Host "PASS: $name"
            ++$script:passed
        } else {
            Write-Host "FAIL: $name"
            ++$script:failed
        }
    } catch {
        Write-Host "FAIL: $name`n$_"
        ++$script:failed
    }
}

function Wait-Port ([int]$Port, [int]$Tries = 10) {
    for ($i=0; $i -lt $Tries; ++$i) {
        Start-Sleep 2
        if (Test-Connection localhost -TcpPort $Port) { return }
    }
    Write-Error "Port $Port is not up after $Tries tries"
}

Write-Host "=== Zipping the plugin"
$plugin = "$PWD/fiftyonedegrees.zip"
Push-Location package
zip -qr $plugin .
Pop-Location

Write-Host "=== Starting the database"
sudo systemctl start mysql.service

Write-Host "=== Installing wp-cli"
Invoke-WebRequest -OutFile 'wp-cli.phar' -Uri 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar'
$wp = "$PWD/wp-cli.phar"
php $wp --info

Write-Host "=== Downloading WordPress"
php $wp core download --path=wp
Push-Location wp
try {
    Write-Host "=== Setting up the database"
    php $wp config create --dbname=wp --dbuser=root --dbpass=root
    php $wp db create

    Write-Host "Installing WordPress"
    $env:WORDPRESS_URL = "http://localhost:8080"
    php $wp config set WP_SITEURL $env:WORDPRESS_URL --type=constant
    php $wp config set WP_HOME $env:WORDPRESS_URL --type=constant
    php $wp core install --url=$env:WORDPRESS_URL --title="WP test" --admin_user=admin --admin_password=admin --admin_email=noreply@example.com --skip-email

    Write-Host "=== Installing the plugin"
    php $wp plugin install --activate $plugin
    php $wp option update fiftyonedegrees_resource_key $env:RESOURCEKEY

    [version]$phpVersion = (-split (php --version))[1]

    Test-WordPress "Plugin is listed as active" {
        $PSNativeCommandUseErrorActionPreference = $false
        php $wp plugin is-active fiftyonedegrees
        $LASTEXITCODE -eq 0
    }

    try {
        Write-Host "=== Starting the server"
        $server = php $wp server &

        Wait-Port -Port 8080

        # older PHP's built-in server doesn't handle this test well. Instead of
        # setting up a real webserver we disable the test on older PHP, for now.
        if ($phpVersion -ge [version]'8.4') {
            Test-WordPress "Settings page loads (HTTP 200)" {
                Invoke-WebRequest -Uri "$env:WORDPRESS_URL/wp-login.php" -Method Post -SessionVariable session `
                    -Body @{log = "admin"; pwd = "admin"; "wp-submit" = "Log In"; redirect_to = "/wp-admin/"}
                (Invoke-WebRequest -Uri "$env:WORDPRESS_URL/wp-admin/options-general.php?page=51Degrees" -WebSession $session).StatusCode -eq 200
            }
        }

        Test-WordPress "REST endpoint responds (HTTP 200)" {
            (Invoke-WebRequest -Uri "$env:WORDPRESS_URL/wp-json/fiftyonedegrees/v4/json" -Method POST).StatusCode -eq 200
        }

        Test-WordPress "51Degrees JavaScript snippet is injected into page" {
            (Invoke-WebRequest -Uri "$env:WORDPRESS_URL/" -Headers @{
                "User-Agent" = "Mozilla/5.0 (Linux; Android 10; SM-G973F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.120 Mobile Safari/537.36"
            }).Content -match "fiftyonedegrees-js"
        }

        Write-Host "=== Installing Selenium test prerequisites"
        $env:PYTHONPATH = "$PWD/.pythonpath"
        pip install -t $env:PYTHONPATH -r "$PSScriptRoot/integration-tests/requirements.txt"

        Write-Host "=== Running Selenium tests"
        Push-Location "$PSScriptRoot/integration-tests"
        try {
            python -m pytest || $(++$failed)
        } finally {
            Pop-Location
        }

    } finally {
        Stop-Job $server
        Receive-Job $server -ErrorAction SilentlyContinue
        Remove-Job $server
    }
} finally {
    Pop-Location
}

Write-Host "Passed: $passed, Failed: $failed"
if ($failed -gt 0) {
    exit 1
}

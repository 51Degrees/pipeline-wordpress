param([string]$Version)
$ErrorActionPreference = "Stop"
$PSNativeCommandUseErrorActionPreference = $true

$package = New-Item -ItemType directory -Force -Path package/fiftyonedegrees
$repo = (Get-Item $PSScriptRoot/..).FullName

composer install --working-dir "$repo/lib" --no-interaction
rsync -r "--exclude-from=$repo/.distignore" $repo/ $package/

Write-Host "Removing dev dependencies from the package..."
composer update -d "$package/lib" --no-dev

if ($Version) {
    # Plugin header carries the release version (from the Git tag).
    $pluginFile = "$package/fiftyonedegrees.php"
    $content = Get-Content -Raw $pluginFile
    $content = ([regex]'(?m)^(\s*\*\s*Version:\s*).*').Replace($content, "`${1}$Version", 1)
    $content | Set-Content $pluginFile

    # "Tested up to" lives in readme.txt, not the plugin header. The tests run
    # against the latest WordPress, so name its newest major version, the same
    # value ci/update-packages.ps1 writes each night.
    $wpVersion = & "$PSScriptRoot/get-wordpress-major.ps1"
    $readmeFile = "$package/readme.txt"
    $content = Get-Content -Raw $readmeFile
    $content = ([regex]'(?m)^Tested up to:\s+.*').Replace($content, "Tested up to: $wpVersion", 1)
    $content | Set-Content $readmeFile
}

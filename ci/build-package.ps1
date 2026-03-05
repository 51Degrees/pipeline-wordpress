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
    $wpVersion = (Invoke-WebRequest 'https://api.wordpress.org/core/version-check/1.7/' | ConvertFrom-Json).offers `
        | Sort-Object -Property {[version]$_.version} -Bottom 1
    $file = "$package/fiftyonedegrees.php"
    $content = Get-Content -Raw $file
    $content = ([regex]'(?m)^(\s*\*\s*Version:\s*).*').Replace($content, "`${1}$Version", 1)
    $content = ([regex]'(?m)^Tested up to:\s+.*').Replace($content, "Tested up to: $wpVersion", 1)
    $content | Set-Content $file
}

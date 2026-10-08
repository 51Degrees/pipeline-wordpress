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
    # Newest released WordPress version, as a plain string (Sort-Object returns
    # the offer object, so expand .version — interpolating the object would
    # otherwise stamp its ToString() rather than e.g. "7.0").
    $wpVersion = (Invoke-WebRequest 'https://api.wordpress.org/core/version-check/1.7/' | ConvertFrom-Json).offers `
        | Sort-Object -Property {[version]$_.version} -Bottom 1 `
        | Select-Object -ExpandProperty version

    # Plugin header carries the release version (from the Git tag).
    $pluginFile = "$package/fiftyonedegrees.php"
    $content = Get-Content -Raw $pluginFile
    $content = ([regex]'(?m)^(\s*\*\s*Version:\s*).*').Replace($content, "`${1}$Version", 1)
    $content | Set-Content $pluginFile

    # "Tested up to" lives in readme.txt, not the plugin header. CI tests run
    # against WordPress latest, so track the newest release on every publish.
    $readmeFile = "$package/readme.txt"
    $content = Get-Content -Raw $readmeFile
    $content = ([regex]'(?m)^Tested up to:\s+.*').Replace($content, "Tested up to: $wpVersion", 1)
    $content | Set-Content $readmeFile
}

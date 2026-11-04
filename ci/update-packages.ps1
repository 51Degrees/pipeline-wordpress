param ([Parameter(Mandatory)][string]$RepoName)
$ErrorActionPreference = "Stop"

# Raises "Tested up to" in readme.txt to the newest major version of
# WordPress. The nightly pull request build then tests the plugin against the
# latest WordPress before the change is merged.
#
# Composer needs nothing here. lib/composer.lock is not committed, so every
# build already installs the newest versions that lib/composer.json allows.
$latest = & "$PSScriptRoot/get-wordpress-major.ps1"
$readme = (Resolve-Path "$RepoName/readme.txt").ProviderPath
$text = [IO.File]::ReadAllText($readme)
$line = [regex]'(?m)^Tested up to:[ \t]*([^\r\n]*)'
$match = $line.Match($text)
if (-not $match.Success) {
    throw "$readme has no Tested up to line"
}

$tested = $match.Groups[1].Value.Trim()
if ([version](($tested.Split('.') | Select-Object -First 2) -join '.') -ge [version]$latest) {
    Write-Host "readme.txt is tested up to $tested, which is current for WordPress $latest"
    return
}

Write-Host "Raising Tested up to in readme.txt from $tested to $latest"
[IO.File]::WriteAllText($readme, $line.Replace($text, "Tested up to: $latest", 1))

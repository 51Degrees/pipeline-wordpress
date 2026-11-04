# Writes the newest major version of WordPress, such as 7.1. "Tested up to"
# needs only the major version, because WordPress.org adds the minor one.
$ErrorActionPreference = "Stop"

$latest = (Invoke-RestMethod 'https://api.wordpress.org/core/version-check/1.7/').offers `
    | Sort-Object -Property {[version]$_.version} -Bottom 1 `
    | Select-Object -ExpandProperty version
($latest.Split('.') | Select-Object -First 2) -join '.'

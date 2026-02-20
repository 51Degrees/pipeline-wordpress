param([string]$Version)
$ErrorActionPreference = "Stop"
$PSNativeCommandUseErrorActionPreference = $true

$package = New-Item -ItemType directory -Force -Path package/fiftyonedegrees
$repo = (Get-Item $PSScriptRoot/..).FullName

composer install --working-dir "$repo/lib" --no-interaction
rsync -r "--exclude-from=$repo/.distignore" $repo/ $package/

if ($Version) {
    $file = "$package/fiftyonedegrees.php"
    [regex]$regex = '(?m)^(\s*\*\s*Version:\s*).*'
    $regex.Replace((Get-Content -Raw $file), "`${1}$Version", 1) | Set-Content $file
}

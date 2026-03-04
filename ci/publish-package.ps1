param (
    [Parameter(Mandatory)][string]$Version,
    [Parameter(Mandatory)][string]$WordpressSvnUrl,
    [Parameter(Mandatory)][string]$WordpressSvnUser,
    [Parameter(Mandatory)][string]$WordpressSvnPassword,
    [boolean]$DryRun,
    [string]$Branch = "main"
)
$ErrorActionPreference = "Stop"
$PSNativeCommandUseErrorActionPreference = $true

$package = "$PWD/package/fiftyonedegrees"

if (-not (Get-Command 'svn' -ErrorAction SilentlyContinue) -and $env:CI) {
    sudo apt-get update -y
    sudo apt-get install --no-install-recommends subversion
}

if ($env:CI) {
    $svnConfDir = New-Item -ItemType directory -Force -Path ~/.subversion
    Write-Output "[global]" "http-timeout = 7200" > $svnConfDir/servers
}

Write-Host "Checking out $WordpressSvnUrl..."
svn checkout -q --depth immediates $WordpressSvnUrl svn

Push-Location svn
try {
    svn update -q --set-depth infinity assets trunk
    svn update -q --set-depth immediates tags

    if (Test-Path "tags/$Version") {
        Write-Host "$Version tag already exists, exiting"
        exit 0
    }

    Write-Host "Syncing the code..."
    rsync -cr --delete "$package/" trunk/
    rsync -cr --delete "$package/assets/images/" assets/

    if ($Branch -ceq 'main') {
        Write-Host "Updating the stable tag to: $Version"
        [regex]$regex = '(?m)^Stable tag:\s+.*'
        $regex.Replace((Get-Content -Raw trunk/readme.txt), "Stable tag: $Version", 1) | Set-Content trunk/readme.txt
    }

    svn add -q --force .
    $removed = (svn status | Where-Object { $_ -match '^!' }) -replace '^! *'
    if ($removed) {
        svn delete -q --force $removed
    }

    svn cp trunk "tags/$Version"

    svn propset -q svn:mime-type "image/png" assets/*.png
    svn propset -q svn:mime-type "image/jpeg" assets/*.jpg

    svn update # make sure we're up to date
    svn status

    if ($DryRun) {
        Write-Host "Dry run, not committing"
    } else {
        svn commit --non-interactive --no-auth-cache --username $WordpressSvnUser --password $WordpressSvnPassword  -m "Update to $Version"
    }
} finally {
    Pop-Location
}

Write-Host 'Done!'

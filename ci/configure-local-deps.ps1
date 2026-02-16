# Configure Composer path repositories for local cross-repo dependencies.
#
# This script registers sibling repos as Composer path repositories with
# version overrides so that branches (e.g. fix/issue-33) satisfy the "4.*"
# constraint in composer.json. symlink is set to false so that dependencies
# are mirrored (copied) into lib/vendor/ instead of symlinked — this is
# required for the Docker bind mount to work correctly.

Push-Location "$PSScriptRoot/../lib"

try {
    $repos = @(
        @{
            Name    = "local-core"
            Url     = "../../pipeline-php-core"
            Package = "51degrees/fiftyone.pipeline.core"
        },
        @{
            Name    = "local-engines"
            Url     = "../../pipeline-php-engines"
            Package = "51degrees/fiftyone.pipeline.engines"
        },
        @{
            Name    = "local-cloudrequestengine"
            Url     = "../../pipeline-php-cloudrequestengine"
            Package = "51degrees/fiftyone.pipeline.cloudrequestengine"
        }
    )

    foreach ($repo in $repos) {
        $json = @{
            type    = "path"
            url     = $repo.Url
            options = @{
                versions = @{
                    $repo.Package = "4.99.99"
                }
                symlink = $false
            }
        } | ConvertTo-Json -Depth 4 -Compress

        Write-Output "Configuring path repository: $($repo.Name) -> $($repo.Url)"
        composer config "repositories.$($repo.Name)" --json $json
        if ($LASTEXITCODE -ne 0) {
            throw "Failed to configure repository $($repo.Name)"
        }
    }

    Write-Output "All path repositories configured successfully."
    composer config --list | Select-String "repositories"
}
finally {
    Pop-Location
}

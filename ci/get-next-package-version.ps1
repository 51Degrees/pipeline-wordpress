param (
    [Parameter(Mandatory)][string]$RepoName,
    [Parameter(Mandatory)][string]$VariableName
)

./php/get-next-package-version.ps1 -RepoName:$RepoName -VariableName:$VariableName

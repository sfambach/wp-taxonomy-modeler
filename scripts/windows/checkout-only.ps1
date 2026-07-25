#Requires -Version 5.1
<#
.SYNOPSIS
  Clone wp-taxonomy-tree only if missing. No git pull (use recover-repo.bat to update).
#>
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoUrl    = 'https://github.com/sfambach/wp-taxonomy-tree.git'
$Branch     = 'main'
$SourceRoot = 'C:\devel\wordpress\source'
$RepoDir    = Join-Path $SourceRoot 'wp-taxonomy-tree'

if (-not (Test-Path -LiteralPath $SourceRoot)) {
    New-Item -ItemType Directory -Path $SourceRoot -Force | Out-Null
}

if (Test-Path -LiteralPath (Join-Path $RepoDir '.git')) {
    Write-Host "Repo already present: $RepoDir"
    Write-Host 'To update from GitHub, run recover-repo.bat (asks for confirmation).'
    exit 0
}

if (Test-Path -LiteralPath $RepoDir) {
    throw "$RepoDir exists without .git - use recover-repo.bat"
}

Write-Host "Cloning into $RepoDir"
git clone --branch $Branch $RepoUrl $RepoDir
Write-Host "Checkout ready: $RepoDir"

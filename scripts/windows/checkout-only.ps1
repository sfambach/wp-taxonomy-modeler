#Requires -Version 5.1
<#
.SYNOPSIS
  Clone or update wp-taxonomy-tree under C:\devel\wordpress\source only.
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

if (-not (Test-Path -LiteralPath (Join-Path $RepoDir '.git'))) {
    Write-Host "Cloning into $RepoDir"
    git clone --branch $Branch $RepoUrl $RepoDir
}
else {
    Push-Location $RepoDir
    try {
        git fetch origin
        git checkout $Branch
        git pull --ff-only origin $Branch
    }
    finally {
        Pop-Location
    }
    Write-Host "Updated $RepoDir"
}

Write-Host "Checkout ready: $RepoDir"

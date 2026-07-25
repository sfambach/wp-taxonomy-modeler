#Requires -Version 5.1
<#
.SYNOPSIS
  Local Laragon dev setup — mirrors the Cloud VM layout (no destructive git).

.DESCRIPTION
  What this script DOES (same idea as the Cloud VM in AGENTS.md):
    1. Start Laragon (Apache + MySQL)
    2. Ensure plugin source exists under C:\devel\wordpress\source\wp-taxonomy-tree
       — clones ONLY if the folder/.git is completely missing (never git pull)
    3. Junction: wp-content\plugins\wp-taxonomy-tree -> source checkout
    4. Junction: C:\laragon\www\devel -> C:\devel\wordpress  (http://devel.test)
    5. Install WordPress via install-wordpress.ps1 (MySQL + wp-cli)

  What this script does NOT do:
    - No git pull, checkout, or reset (that destroyed scripts\windows on Windows)
    - To update sources from GitHub, run recover-repo.bat manually when you want

  Cloud agents cannot run this on your PC — they only have the Linux VM (/workspace).
#>
[CmdletBinding()]
param(
    [switch]$UpdateRepo
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoUrl           = 'https://github.com/sfambach/wp-taxonomy-tree.git'
$Branch            = 'main'
$LaragonDir        = 'C:\laragon'
$WordPressRoot     = 'C:\devel\wordpress'
$SourceRoot        = Join-Path $WordPressRoot 'source'
$RepoDir           = Join-Path $SourceRoot 'wp-taxonomy-tree'
$PluginsDir        = Join-Path $WordPressRoot 'wp-content\plugins'
$PluginLink        = Join-Path $PluginsDir 'wp-taxonomy-tree'
$LaragonWwwLink    = Join-Path $LaragonDir 'www\devel'
$SiteUrl           = 'http://devel.test'

function Write-Step([string]$Message) {
    Write-Host "`n==> $Message" -ForegroundColor Cyan
}

function Ensure-Directory([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -ItemType Directory -Path $Path -Force | Out-Null
        Write-Host "Created $Path"
    }
}

function Ensure-Junction([string]$Link, [string]$Target) {
    if (Test-Path -LiteralPath $Link) {
        $item = Get-Item -LiteralPath $Link -Force
        if ($item.LinkType -in @('Junction', 'SymbolicLink')) {
            $existing = $item.Target
            if ($existing -eq $Target) {
                Write-Host "Junction already correct: $Link -> $Target"
                return
            }
            Write-Host "Removing old link: $Link (was -> $existing)"
            cmd /c rmdir `"$Link`" | Out-Null
        }
        else {
            throw "Path exists and is not a junction: $Link"
        }
    }

    Ensure-Directory (Split-Path -Parent $Link)
    cmd /c mklink /J `"$Link`" `"$Target`" | Out-Null
    Write-Host "Linked $Link -> $Target"
}

function Start-LaragonIfNeeded {
    if (-not (Test-Path -LiteralPath (Join-Path $LaragonDir 'laragon.exe'))) {
        throw "Laragon not found at $LaragonDir. Install Laragon first, then rerun."
    }

    $running = Get-Process -Name 'laragon' -ErrorAction SilentlyContinue
    if ($null -eq $running) {
        Write-Step 'Starting Laragon'
        Start-Process -FilePath (Join-Path $LaragonDir 'laragon.exe')
        Start-Sleep -Seconds 10
    }
    else {
        Write-Host 'Laragon is already running.'
    }
}

function Ensure-RepoPresent {
    Ensure-Directory $SourceRoot

    if (Test-Path -LiteralPath (Join-Path $RepoDir '.git')) {
        Write-Host "Using existing repo: $RepoDir"
        Write-Host '(No automatic git pull — use recover-repo.bat only when YOU want to update.)'
        return
    }

    if (Test-Path -LiteralPath $RepoDir) {
        throw @"
$RepoDir exists but is not a git checkout (.git missing).
Do not delete blindly. Either:
  1. Run recover-repo.bat (asks for confirmation before replacing), or
  2. Remove/rename the broken folder yourself, then rerun setup-dev.bat
"@
    }

    Write-Step "First-time clone -> $RepoDir"
    git clone --branch $Branch $RepoUrl $RepoDir
}

function Update-RepoExplicit {
    if (-not $UpdateRepo) {
        return
    }

    Write-Step 'Explicit repo update (-UpdateRepo)'
    if (-not (Test-Path -LiteralPath (Join-Path $RepoDir '.git'))) {
        throw "Cannot update — repo missing. Run setup without -UpdateRepo first."
    }

    $env:GIT_TERMINAL_PROMPT = '0'
    Push-Location $SourceRoot
    try {
        git -C $RepoDir fetch origin
        git -C $RepoDir checkout $Branch
        git -C $RepoDir pull --ff-only origin $Branch
    }
    finally {
        Pop-Location
    }
}

function Get-InstallWordPressScript {
    $fromRepo = Join-Path $RepoDir 'scripts\windows\install-wordpress.ps1'
    if (Test-Path -LiteralPath $fromRepo) {
        return $fromRepo
    }
    $nextToSelf = Join-Path $PSScriptRoot 'install-wordpress.ps1'
    if (Test-Path -LiteralPath $nextToSelf) {
        return $nextToSelf
    }
    return $null
}

function Ensure-WordPressInstalled {
    Write-Step 'Installing WordPress (same role as ~/wordpress on the Cloud VM)'
    $installScript = Get-InstallWordPressScript
    if ($null -eq $installScript) {
        throw @"
install-wordpress.ps1 not found.

Clone the repo first (one time):
  mkdir C:\devel\wordpress\source
  git clone $RepoUrl $RepoDir

Or run recover-repo.bat
"@
    }
    & $installScript
}

function Install-NodeDependencies {
    $packageJson = Join-Path $RepoDir 'package.json'
    if (-not (Test-Path -LiteralPath $packageJson)) {
        Write-Host 'No package.json yet — npm skipped (planning phase).'
        return
    }

    Write-Step 'Running npm install'
    Push-Location $RepoDir
    try {
        npm install
        npm run build
    }
    finally {
        Pop-Location
    }
}

Write-Step 'wp-taxonomy-tree local setup (Laragon)'
Write-Host @'
Cloud VM (already done by the agent):  ~/wordpress + SQLite + symlink /workspace
Your Windows PC (this script):         C:\devel\wordpress + Laragon MySQL + junctions
'@

if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    throw 'git not on PATH. Start Laragon once so Git is available.'
}

Start-LaragonIfNeeded
Ensure-RepoPresent
Update-RepoExplicit
Ensure-WordPressInstalled
Ensure-Directory $PluginsDir
Ensure-Junction -Link $PluginLink -Target $RepoDir
Ensure-Junction -Link $LaragonWwwLink -Target $WordPressRoot
Install-NodeDependencies

Write-Step 'Done'
Write-Host @"

  Source repo : $RepoDir
  WP docroot  : $WordPressRoot
  Plugin link : $PluginLink
  Site        : $SiteUrl/wp-admin  (admin / admin123)

Rerun anytime — idempotent, no git pull unless you pass -UpdateRepo.
"@

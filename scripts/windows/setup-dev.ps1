#Requires -Version 5.1
<#
.SYNOPSIS
  Prepare local Laragon + WordPress dev environment for wp-taxonomy-tree.

.DESCRIPTION
  - Clones or updates the repo under C:\devel\wordpress\source\wp-taxonomy-tree
  - Links the checkout into C:\devel\wordpress\wp-content\plugins\wp-taxonomy-tree
  - Creates a Laragon www junction so the site is available as http://devel.test
  - Starts Laragon if it is installed but not running
  - Runs npm install when package.json exists

  Run once before reboot or whenever you need to refresh the checkout.
#>
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

function Get-LaragonPhp {
    $candidates = @(
        (Join-Path $LaragonDir 'bin\php\php-8.3.16-Win32-vs16-x64\php.exe'),
        (Join-Path $LaragonDir 'bin\php\php-8.3.14-Win32-vs16-x64\php.exe'),
        (Join-Path $LaragonDir 'bin\php\php-8.2.27-Win32-vs16-x64\php.exe')
    )

    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate) {
            return $candidate
        }
    }

    $found = Get-ChildItem -Path (Join-Path $LaragonDir 'bin\php') -Filter php.exe -Recurse -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending |
        Select-Object -First 1

    if ($null -ne $found) {
        return $found.FullName
    }

    throw "Could not find php.exe under $LaragonDir\bin\php"
}

function Start-LaragonIfNeeded {
    if (-not (Test-Path -LiteralPath (Join-Path $LaragonDir 'laragon.exe'))) {
        throw "Laragon not found at $LaragonDir. Install Laragon first, then rerun this script."
    }

    $running = Get-Process -Name 'laragon' -ErrorAction SilentlyContinue
    if ($null -eq $running) {
        Write-Step 'Starting Laragon'
        Start-Process -FilePath (Join-Path $LaragonDir 'laragon.exe')
        Start-Sleep -Seconds 8
    }
    else {
        Write-Host 'Laragon is already running.'
    }
}

function Ensure-GitCheckout {
    Ensure-Directory $SourceRoot

    if (-not (Test-Path -LiteralPath (Join-Path $RepoDir '.git'))) {
        Write-Step "Cloning $RepoUrl into $RepoDir"
        git clone --branch $Branch $RepoUrl $RepoDir
        return
    }

    Write-Step "Updating existing checkout at $RepoDir"
    Push-Location $RepoDir
    try {
        git fetch origin
        git checkout $Branch
        git pull --ff-only origin $Branch
    }
    finally {
        Pop-Location
    }
}

function Ensure-WordPressInstalled {
    Write-Step 'Installing WordPress (core, database, wp core install)'
    $installScript = Join-Path $PSScriptRoot 'install-wordpress.ps1'
    if (-not (Test-Path -LiteralPath $installScript)) {
        throw "Missing $installScript"
    }
    & $installScript
}

function Install-NodeDependencies {
    $packageJson = Join-Path $RepoDir 'package.json'
    if (-not (Test-Path -LiteralPath $packageJson)) {
        Write-Host 'No package.json yet — npm install skipped (expected during planning phase).'
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

Write-Step 'Checking prerequisites'
if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    throw 'git is not on PATH. Start Laragon (Git is bundled) or install Git for Windows.'
}

Start-LaragonIfNeeded
Ensure-GitCheckout
Ensure-WordPressInstalled
Ensure-Directory $PluginsDir
Ensure-Junction -Link $PluginLink -Target $RepoDir
Ensure-Junction -Link $LaragonWwwLink -Target $WordPressRoot
Install-NodeDependencies

Write-Step 'Done'
Write-Host @"

Local paths:
  Source repo : $RepoDir
  WP docroot  : $WordPressRoot
  Plugin link : $PluginLink -> $RepoDir
  Site URL    : $SiteUrl  (Laragon junction: $LaragonWwwLink)

Next steps:
  - Open Laragon and confirm Apache + MySQL are green.
  - Browse to $SiteUrl/wp-admin (admin / admin123)
  - After plugin bootstrap exists: Plugins -> activate wp-taxonomy-tree

Safe to reboot — rerun this script anytime to pull latest and refresh links.
"@

#Requires -Version 5.1
<#
.SYNOPSIS
  Install or verify WordPress under C:\devel\wordpress using Laragon (MySQL).

.DESCRIPTION
  Downloads WordPress core, creates the MySQL database, runs wp core install,
  and sets site URL to http://devel.test. Idempotent - safe to rerun.
#>
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$LaragonDir     = 'C:\laragon'
$WordPressRoot  = 'C:\devel\wordpress'
$SiteUrl        = 'http://devel.test'
$DbName         = 'wordpress'
$DbUser         = 'root'
$DbPass         = ''
$DbHost         = '127.0.0.1'
$AdminUser      = 'admin'
$AdminPass      = 'admin123'
$AdminEmail     = 'admin@example.test'
$SiteTitle      = 'WP Dev'

function Write-Step([string]$Message) {
    Write-Host "`n==> $Message" -ForegroundColor Cyan
}

function Ensure-Directory([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -ItemType Directory -Path $Path -Force | Out-Null
        Write-Host "Created $Path"
    }
}

function Get-LaragonPhp {
    $found = Get-ChildItem -Path (Join-Path $LaragonDir 'bin\php') -Filter php.exe -Recurse -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending |
        Select-Object -First 1

    if ($null -eq $found) {
        throw "Could not find php.exe under $LaragonDir\bin\php. Is Laragon installed?"
    }

    return $found.FullName
}

function Get-LaragonMysql {
    $found = Get-ChildItem -Path (Join-Path $LaragonDir 'bin\mysql') -Filter mysql.exe -Recurse -ErrorAction SilentlyContinue |
        Where-Object { $_.DirectoryName -match '\\bin$' } |
        Sort-Object FullName -Descending |
        Select-Object -First 1

    if ($null -eq $found) {
        throw "Could not find mysql.exe under $LaragonDir\bin\mysql"
    }

    return $found.FullName
}

function Get-WpCli {
    $wpCli = Join-Path $LaragonDir 'bin\wp-cli.phar'
    if (-not (Test-Path -LiteralPath $wpCli)) {
        Write-Host 'Downloading wp-cli.phar'
        Ensure-Directory (Split-Path -Parent $wpCli)
        Invoke-WebRequest -Uri 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar' -OutFile $wpCli
    }
    return $wpCli
}

function Invoke-WpCore {
    param(
        [string[]]$WpArgs,
        [switch]$AllowFailure
    )

    $php = Get-LaragonPhp
    $wpCli = Get-WpCli
    Push-Location $WordPressRoot
    try {
        $output = & $php $wpCli @WpArgs 2>&1
        $code = $LASTEXITCODE
        if (-not $AllowFailure -and $code -ne 0) {
            throw "wp $($WpArgs -join ' ') failed (exit $code): $output"
        }
        return [pscustomobject]@{
            ExitCode = $code
            Output   = ($output | Out-String).Trim()
        }
    }
    finally {
        Pop-Location
    }
}

function Start-LaragonIfNeeded {
    if (-not (Test-Path -LiteralPath (Join-Path $LaragonDir 'laragon.exe'))) {
        throw "Laragon not found at $LaragonDir"
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

function Wait-ForMySql {
    Write-Step 'Waiting for MySQL'
    $mysql = Get-LaragonMysql
    $deadline = (Get-Date).AddMinutes(3)

    while ((Get-Date) -lt $deadline) {
        & $mysql -h $DbHost -u $DbUser -e 'SELECT 1' 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) {
            Write-Host 'MySQL is ready.'
            return
        }
        Start-Sleep -Seconds 2
    }

    throw 'MySQL did not become ready within 3 minutes. Check Laragon (Apache + MySQL green).'
}

function Ensure-Database {
    Write-Step "Ensuring database '$DbName' exists"
    $mysql = Get-LaragonMysql
    $sql = "CREATE DATABASE IF NOT EXISTS ``$DbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    & $mysql -h $DbHost -u $DbUser -e $sql
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to create database '$DbName'"
    }
}

function Ensure-WordPressCore {
    Ensure-Directory $WordPressRoot
    if (Test-Path -LiteralPath (Join-Path $WordPressRoot 'index.php')) {
        Write-Host 'WordPress core files already present.'
        return
    }

    Write-Step 'Downloading WordPress core (de_DE)'
    Invoke-WpCore -WpArgs @('core', 'download', '--locale=de_DE') | Out-Null
}

function Ensure-WordPressConfig {
    if (Test-Path -LiteralPath (Join-Path $WordPressRoot 'wp-config.php')) {
        Write-Host 'wp-config.php already exists.'
        return
    }

    Write-Step 'Creating wp-config.php'
    Invoke-WpCore -WpArgs @(
        'config', 'create',
        "--dbname=$DbName",
        "--dbuser=$DbUser",
        "--dbpass=$DbPass",
        "--dbhost=$DbHost",
        '--skip-check',
        '--force'
    ) | Out-Null
}

function Test-WordPressInstalled {
    $result = Invoke-WpCore -WpArgs @('core', 'is-installed') -AllowFailure
    return $result.ExitCode -eq 0
}

function Install-WordPressCore {
    if (Test-WordPressInstalled) {
        Write-Host 'WordPress is already installed.'
        return
    }

    Write-Step 'Running wp core install'
    Invoke-WpCore -WpArgs @(
        'core', 'install',
        "--url=$SiteUrl",
        "--title=$SiteTitle",
        "--admin_user=$AdminUser",
        "--admin_password=$AdminPass",
        "--admin_email=$AdminEmail",
        '--skip-email'
    ) | Out-Null
}

function Sync-SiteUrl {
    $current = (Invoke-WpCore -WpArgs @('option', 'get', 'siteurl')).Output
    if ($current -ne $SiteUrl) {
        Write-Step "Updating site URL to $SiteUrl"
        Invoke-WpCore -WpArgs @('option', 'update', 'siteurl', $SiteUrl) | Out-Null
        Invoke-WpCore -WpArgs @('option', 'update', 'home', $SiteUrl) | Out-Null
    }

    Invoke-WpCore -WpArgs @('rewrite', 'structure', '/%postname%/') | Out-Null
    Invoke-WpCore -WpArgs @('rewrite', 'flush', '--hard') | Out-Null
}

Write-Step 'WordPress install for Laragon'
Start-LaragonIfNeeded
Wait-ForMySql
Ensure-WordPressCore
Ensure-Database
Ensure-WordPressConfig
Install-WordPressCore
Sync-SiteUrl

Write-Step 'WordPress ready'
Write-Host @"

  Site     : $SiteUrl
  Admin    : $SiteUrl/wp-admin
  User     : $AdminUser
  Password : $AdminPass
  Docroot  : $WordPressRoot
  Database : $DbName @ $DbHost
"@

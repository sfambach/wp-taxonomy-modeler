#Requires -Version 5.1
<#
.SYNOPSIS
  Install the shared test category tree into C:\devel\wordpress via WP-CLI.
#>
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$LaragonDir    = 'C:\laragon'
$WordPressRoot = 'C:\devel\wordpress'

function Get-LaragonPhp {
    $found = Get-ChildItem -Path (Join-Path $LaragonDir 'bin\php') -Filter php.exe -Recurse -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending |
        Select-Object -First 1
    if ($null -eq $found) { throw "php.exe not found under $LaragonDir\bin\php" }
    return $found.FullName
}

function Get-WpCli {
    $wpCli = Join-Path $LaragonDir 'bin\wp-cli.phar'
    if (-not (Test-Path -LiteralPath $wpCli)) {
        throw "Missing $wpCli - run install-wordpress.ps1 first"
    }
    return $wpCli
}

$php = Get-LaragonPhp
$wp  = Get-WpCli

Write-Host 'Installing test tree (category) ...'

# Prefer plugin AJAX path via eval if plugin is active; fallback to raw wp term create.
$eval = @'
if (!class_exists("WTT\\Demo_Data")) { echo "PLUGIN_INACTIVE\n"; return; }
$r = WTT\Demo_Data::install("category");
if (is_wp_error($r)) { echo $r->get_error_message() . "\n"; return; }
echo "created={$r["created"]} existing={$r["existing"]}\n";
'@

Push-Location $WordPressRoot
try {
    $out = & $php $wp --user=admin eval $eval 2>&1 | Out-String
    Write-Host $out.Trim()
    if ($out -match 'PLUGIN_INACTIVE') {
        Write-Host 'Plugin inactive - activating and retrying ...'
        & $php $wp plugin activate wp-taxonomy-tree | Out-Null
        $out = & $php $wp --user=admin eval $eval 2>&1 | Out-String
        Write-Host $out.Trim()
    }
}
finally {
    Pop-Location
}

Write-Host 'Done. Open Taxonomy Tree in wp-admin and refresh.'

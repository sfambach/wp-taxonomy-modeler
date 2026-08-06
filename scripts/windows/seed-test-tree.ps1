#Requires -Version 5.1
<#
.SYNOPSIS
  Sync the demo blueprint into C:\devel\wordpress (non-destructive).

  Uses Demo_Data::install — creates missing nodes and refreshes blueprint meta
  (types, fixed values, required, disabled prefixes, …). Does NOT wipe the tree.
  For a full wipe + reinstall use Reset test tree in wp-admin (test mode).
#>
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$LaragonDir    = 'C:\laragon'
$WordPressRoot = 'C:\devel\wordpress'
$RepoRoot      = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$SyncScript    = Join-Path $RepoRoot 'scripts\sync-demo-tree.php'

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

if (-not (Test-Path -LiteralPath $SyncScript)) {
	throw "Missing sync script: $SyncScript"
}

$php = Get-LaragonPhp
$wp  = Get-WpCli

Write-Host 'Syncing demo blueprint into wtt_tree (non-destructive) ...'

Push-Location $WordPressRoot
try {
	$out = & $php $wp --user=admin eval-file $SyncScript 2>&1 | Out-String
	Write-Host $out.Trim()
	if ($out -match 'PLUGIN_INACTIVE') {
		Write-Host 'Plugin inactive - activating and retrying ...'
		& $php $wp plugin activate wp-taxonomy-tree | Out-Null
		$out = & $php $wp --user=admin eval-file $SyncScript 2>&1 | Out-String
		Write-Host $out.Trim()
	}
}
finally {
	Pop-Location
}

Write-Host 'Done. Hard-refresh Taxonomy Tree in wp-admin.'

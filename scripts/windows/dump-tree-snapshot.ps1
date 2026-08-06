#Requires -Version 5.1
<#
.SYNOPSIS
  Dump a live taxonomy tree to scripts/fixtures/tree-snapshot-{tax}-{date}.json

.PARAMETER Taxonomy
  Taxonomy slug (default: category — for pre-migration backup).
#>
param(
	[string] $Taxonomy = 'category'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$LaragonDir    = 'C:\laragon'
$WordPressRoot = 'C:\devel\wordpress'
$RepoRoot      = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$DumpScript    = Join-Path $RepoRoot 'scripts\dump-tree-snapshot.php'

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

if (-not (Test-Path -LiteralPath $DumpScript)) {
	throw "Missing dump script: $DumpScript"
}

$php = Get-LaragonPhp
$wp  = Get-WpCli

Write-Host "Dumping taxonomy '$Taxonomy' ..."

Push-Location $WordPressRoot
try {
	# Pass taxonomy as extra arg after eval-file path (WP-CLI puts leftovers in $args for eval-file in some versions;
	# we also parse $argv inside the PHP script).
	$out = & $php $wp --user=admin eval-file $DumpScript $Taxonomy 2>&1 | Out-String
	Write-Host $out.Trim()
	if ($LASTEXITCODE -ne 0 -and $out -notmatch 'wrote ') {
		throw "Dump failed (exit $LASTEXITCODE)"
	}
}
finally {
	Pop-Location
}

Write-Host 'Done.'

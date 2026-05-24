# Verify WordPress.org Lite package structure for Museder RestoreOne.
param(
    [Parameter(Mandatory = $true)]
    [string]$ZipPath
)

$ErrorActionPreference = 'Stop'
$Slug = 'museder-restoreone'

if (-not (Test-Path -LiteralPath $ZipPath)) {
    throw "zip not found: $ZipPath"
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
try {
    $entries = @($zip.Entries | ForEach-Object { $_.FullName })
} finally {
    $zip.Dispose()
}

if ($entries.Count -eq 0) {
    throw "zip appears empty: $ZipPath"
}

$topLevels = @(
    $entries | ForEach-Object {
        $name = [string]$_
        if ($name -match '^([^\\/]+)[\\/]') {
            $matches[1]
        } else {
            ''
        }
    } |
    Where-Object { $_ -ne '' } |
    Sort-Object -Unique
)

if (@($topLevels).Count -ne 1) {
    throw "zip must contain exactly one top-level directory. Found: $($topLevels -join ', ')"
}

if ($topLevels[0] -ne $Slug) {
    throw "top-level directory must be '$Slug'. Found: $($topLevels[0])"
}

$requiredMain = "$Slug/museder-restoreone.php"
if (-not ($entries -contains $requiredMain)) {
    throw "missing required entry: $requiredMain"
}

$doubleWrapPattern = '^museder-restoreone-\d+\.\d+\.\d+[/\\]'
if ($entries | Where-Object { $_ -match $doubleWrapPattern }) {
    throw 'detected versioned wrapper directory inside zip (double-wrap)'
}

$forbidden = @(
    'docs/',
    'tools/',
    'logs/',
    '.git/',
    '.github/',
    '.cursor/',
    'dist/',
    'museder-restoreone-pro/'
)

foreach ($f in $forbidden) {
    $pattern = "^$([regex]::Escape($Slug + '/'))$([regex]::Escape($f))"
    if ($entries | Where-Object { $_ -match $pattern }) {
        throw "forbidden path found in zip: $Slug/$f"
    }
}

Write-Output "OK: package structure verified for $ZipPath"

# Build WordPress.org Lite ZIP (mirrors create-package.sh + release.yml boundary check).
# Rules: docs/PACKAGING.md — never use Compress-Archive on Windows.
$ErrorActionPreference = 'Stop'
$repo = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
if (-not (Test-Path (Join-Path $repo 'museder-restoreone.php'))) {
    $repo = Split-Path $PSScriptRoot -Parent
}
$mainFile = Join-Path $repo 'museder-restoreone.php'
if (-not (Test-Path $mainFile)) { throw "museder-restoreone.php not found in $repo" }

$version = (Select-String -Path $mainFile -Pattern "define\(\s*'MUSEDER_RESTOREONE_VERSION'\s*,\s*'([^']+)'").Matches.Groups[1].Value
$headerVer = (Select-String -Path $mainFile -Pattern '^\s*Version:\s*(.+)$').Matches.Groups[1].Value.Trim()
$readmeVer = (Select-String -Path (Join-Path $repo 'readme.txt') -Pattern '^Stable tag:\s*(.+)$').Matches.Groups[1].Value.Trim()
if ($version -ne $headerVer -or $version -ne $readmeVer) {
    throw "Version mismatch: define=$version header=$headerVer readme=$readmeVer"
}

$slug = 'museder-restoreone'
$dist = Join-Path $repo 'dist'
New-Item -ItemType Directory -Force -Path $dist | Out-Null
$temp = Join-Path $env:TEMP ("mro-pkg-" + [guid]::NewGuid().ToString())
$pluginDir = Join-Path $temp $slug
New-Item -ItemType Directory -Force -Path $pluginDir | Out-Null

foreach ($dir in @('assets', 'includes', 'templates')) {
    Copy-Item -Recurse -Force (Join-Path $repo $dir) (Join-Path $pluginDir $dir)
}
$lang = Join-Path $repo 'languages'
if (Test-Path $lang) {
    Copy-Item -Recurse -Force $lang (Join-Path $pluginDir 'languages')
}
foreach ($file in @(
    'museder-restoreone.php',
    'readme.txt',
    'museder-restoreone-restore-bootstrap.php',
    'uninstall.php',
    'download-handler.php'
)) {
    $src = Join-Path $repo $file
    if (Test-Path $src) {
        Copy-Item -Force $src (Join-Path $pluginDir $file)
    }
}

$zipPath = Join-Path $dist "$slug-$version.zip"
if (Test-Path $zipPath) { Remove-Item -Force $zipPath }
# Compress-Archive and ZipFile::CreateFromDirectory on Windows often use "\" in entry names;
# Linux / WordPress unzip then treats "\" as part of the filename. Use tar -a (zip) instead.
$tar = Get-Command tar -ErrorAction SilentlyContinue
if (-not $tar) {
    throw 'tar.exe is required to build a WordPress-compatible zip on Windows.'
}
& tar -a -c -f $zipPath -C (Split-Path $pluginDir -Parent) $slug
if ($LASTEXITCODE -ne 0) {
    throw "tar failed with exit code $LASTEXITCODE"
}

$prefix = "$slug/"
$forbidden = @('.cursor/', '.github/', 'AGENTS.md', 'docs/', 'tools/', 'logs/', 'dist/', 'museder-restoreone-pro/')
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
$bad = [System.Collections.Generic.List[string]]::new()
foreach ($entry in $archive.Entries) {
    $rel = $entry.FullName
    if ($rel.StartsWith($prefix)) { $rel = $rel.Substring($prefix.Length) }
    foreach ($item in $forbidden) {
        $bare = $item.TrimEnd('/')
        if ($rel -eq $bare -or $rel.StartsWith($item)) {
            $bad.Add($entry.FullName) | Out-Null
        }
    }
}
$entryCount = $archive.Entries.Count
$backslashEntries = @($archive.Entries | Where-Object { $_.FullName -like '*\*' })
$mainEntry = @($archive.Entries | Where-Object { $_.FullName -eq 'museder-restoreone/museder-restoreone.php' })
$archive.Dispose()
Remove-Item -Recurse -Force $temp

if ($bad.Count -gt 0) {
    Write-Error ("Forbidden ZIP entries:`n" + ($bad -join "`n"))
}
if ($backslashEntries.Count -gt 0) {
    Write-Error ("ZIP uses backslash in entry names (WordPress/Linux incompatible). See docs/PACKAGING.md. First: $($backslashEntries[0].FullName)")
}
if ($mainEntry.Count -ne 1) {
    Write-Error 'Missing museder-restoreone/museder-restoreone.php in zip. See docs/PACKAGING.md.'
}

$info = Get-Item $zipPath
Write-Host "Created $($info.FullName)"
Write-Host "Size bytes: $($info.Length)"
Write-Host "ZIP entries: $entryCount"
Write-Host "Version: $version (header/readme aligned)"
Write-Host "BOUNDARY_CHECK=PASS"
Write-Host "ZIP_STRUCTURE_OK"

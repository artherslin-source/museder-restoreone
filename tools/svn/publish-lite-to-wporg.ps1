# Sync Museder RestoreOne Lite into a WordPress.org SVN working copy (Windows).
# Preview:  powershell -ExecutionPolicy Bypass -File tools\svn\publish-lite-to-wporg.ps1
# Publish:  powershell -ExecutionPolicy Bypass -File tools\svn\publish-lite-to-wporg.ps1 -Commit
param(
	[string]$RepoRoot = "",
	[string]$SvnWorkingCopy = "$env:USERPROFILE\Documents\SVN\museder-restoreone",
	[string]$FromZip = "",
	[switch]$Commit
)

$ErrorActionPreference = "Stop"

function Find-SvnExe {
	$candidates = @(
		"svn",
		"${env:ProgramFiles}\TortoiseSVN\bin\svn.exe",
		"${env:ProgramFiles(x86)}\TortoiseSVN\bin\svn.exe"
	)
	foreach ($candidate in $candidates) {
		if ($candidate -eq "svn") {
			$cmd = Get-Command svn -ErrorAction SilentlyContinue
			if ($cmd) { return $cmd.Source }
			continue
		}
		if (Test-Path $candidate) { return $candidate }
	}
	throw "svn.exe not found. Install TortoiseSVN command-line tools or add svn to PATH."
}

function Get-PluginVersion {
	param([string]$MainFile)
	$content = Get-Content -LiteralPath $MainFile -Raw
	if ($content -match 'define\s*\(\s*''MUSEDER_RESTOREONE_VERSION''\s*,\s*''([^'']+)''\s*\)') {
		return $Matches[1]
	}
	if ($content -match '(?m)^\s*Version:\s*(.+)\s*$') {
		return $Matches[1].Trim()
	}
	throw "Could not read version from $MainFile"
}

function Get-StableTag {
	param([string]$ReadmeFile)
	$content = Get-Content -LiteralPath $ReadmeFile -Raw
	if ($content -match '(?m)^Stable tag:\s*(.+)\s*$') {
		return $Matches[1].Trim()
	}
	throw "Could not read Stable tag from $ReadmeFile"
}

function Copy-LiteTree {
	param(
		[string]$SourceRoot,
		[string]$Destination
	)
	if (Test-Path $Destination) {
		Remove-Item -LiteralPath $Destination -Recurse -Force
	}
	New-Item -ItemType Directory -Path $Destination | Out-Null

	foreach ($dir in @("assets", "includes", "templates")) {
		Copy-Item -LiteralPath (Join-Path $SourceRoot $dir) -Destination (Join-Path $Destination $dir) -Recurse
	}
	if (Test-Path (Join-Path $SourceRoot "languages")) {
		Copy-Item -LiteralPath (Join-Path $SourceRoot "languages") -Destination (Join-Path $Destination "languages") -Recurse
	}
	foreach ($file in @("museder-restoreone.php", "readme.txt", "uninstall.php", "download-handler.php")) {
		$path = Join-Path $SourceRoot $file
		if (Test-Path $path) {
			Copy-Item -LiteralPath $path -Destination (Join-Path $Destination $file)
		}
	}
}

function Expand-LiteZip {
	param(
		[string]$ZipPath,
		[string]$Destination
	)
	if (Test-Path $Destination) {
		Remove-Item -LiteralPath $Destination -Recurse -Force
	}
	$temp = Join-Path ([System.IO.Path]::GetTempPath()) ("museder-lite-" + [guid]::NewGuid().ToString("n"))
	New-Item -ItemType Directory -Path $temp | Out-Null
	try {
		Expand-Archive -LiteralPath $ZipPath -DestinationPath $temp -Force
		$inner = Join-Path $temp "museder-restoreone"
		if (-not (Test-Path $inner)) {
			throw "Zip must contain museder-restoreone/ top folder: $ZipPath"
		}
		Copy-Item -LiteralPath $inner -Destination $Destination -Recurse
	}
	finally {
		if (Test-Path $temp) {
			Remove-Item -LiteralPath $temp -Recurse -Force
		}
	}
}

function Sync-ToTrunk {
	param(
		[string]$LiteRoot,
		[string]$TrunkPath
	)
	if (-not (Test-Path $TrunkPath)) {
		New-Item -ItemType Directory -Path $TrunkPath | Out-Null
	}
	& robocopy.exe $LiteRoot $TrunkPath /MIR /NFL /NDL /NJH /NJS /NC /NS | Out-Null
	$rc = $LASTEXITCODE
	if ($rc -ge 8) {
		throw "robocopy failed with exit code $rc"
	}
}

function Invoke-SvnCommand {
	param(
		[string]$SvnExe,
		[string]$WorkingDirectory,
		[string[]]$SvnArgs
	)
	$p = Start-Process -FilePath $SvnExe -ArgumentList $SvnArgs -WorkingDirectory $WorkingDirectory -NoNewWindow -Wait -PassThru
	if ($p.ExitCode -ne 0) {
		throw "svn failed: svn $($SvnArgs -join ' ') (exit $($p.ExitCode))"
	}
}

if (-not $RepoRoot) {
	$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
}

$mainFile = Join-Path $RepoRoot "museder-restoreone.php"
$readmeFile = Join-Path $RepoRoot "readme.txt"
$trunkPath = Join-Path $SvnWorkingCopy "trunk"
$tagsPath = Join-Path $SvnWorkingCopy "tags"

if (-not (Test-Path $mainFile)) {
	throw "Plugin main file not found: $mainFile"
}
if (-not (Test-Path (Join-Path $SvnWorkingCopy "trunk"))) {
	throw "Invalid SVN working copy (missing trunk): $SvnWorkingCopy"
}

$svnExe = Find-SvnExe
$version = Get-PluginVersion -MainFile $mainFile
$stable = Get-StableTag -ReadmeFile $readmeFile
if ($version -ne $stable) {
	throw "Version mismatch: plugin=$version readme Stable tag=$stable"
}

$stage = Join-Path ([System.IO.Path]::GetTempPath()) ("museder-lite-stage-" + [guid]::NewGuid().ToString("n"))
New-Item -ItemType Directory -Path $stage | Out-Null
try {
	if ($FromZip) {
		if (-not (Test-Path $FromZip)) { throw "Zip not found: $FromZip" }
		Expand-LiteZip -ZipPath $FromZip -Destination (Join-Path $stage "lite")
	}
	else {
		Copy-LiteTree -SourceRoot $RepoRoot -Destination (Join-Path $stage "lite")
	}

	Write-Host "Syncing Lite $version -> $trunkPath"
	Sync-ToTrunk -LiteRoot (Join-Path $stage "lite") -TrunkPath $trunkPath

	Invoke-SvnCommand -SvnExe $svnExe -WorkingDirectory $SvnWorkingCopy -SvnArgs @("add", "trunk", "--force")
	$statusLines = & $svnExe status trunk 2>$null
	foreach ($line in $statusLines) {
		if ($line -match "^\!\s+(.+)$") {
			$missing = $Matches[1].Trim()
			& $svnExe delete $missing | Out-Null
		}
	}
	Invoke-SvnCommand -SvnExe $svnExe -WorkingDirectory $SvnWorkingCopy -SvnArgs @("add", "trunk", "--force")

	$tagPath = Join-Path $tagsPath $version
	if (-not (Test-Path $tagPath)) {
		Write-Host "Creating tags/$version from trunk"
		Invoke-SvnCommand -SvnExe $svnExe -WorkingDirectory $SvnWorkingCopy -SvnArgs @("copy", "trunk", "tags/$version")
	}
	else {
		Write-Host "tags/$version already exists; skipping svn copy"
	}

	Write-Host ""
	Write-Host "SVN status preview:"
	& $svnExe status trunk "tags/$version" 2>$null

	if ($Commit) {
		Write-Host ""
		Write-Host "Committing Release $version ..."
		Invoke-SvnCommand -SvnExe $svnExe -WorkingDirectory $SvnWorkingCopy -SvnArgs @("commit", "-m", "Release $version")
		Write-Host "Done. Verify: https://wordpress.org/plugins/museder-restoreone/"
	}
	else {
		Write-Host ""
		Write-Host "Preview complete (not committed). To publish:"
		Write-Host "  powershell -ExecutionPolicy Bypass -File tools\svn\publish-lite-to-wporg.ps1 -Commit"
	}
}
finally {
	if (Test-Path $stage) {
		Remove-Item -LiteralPath $stage -Recurse -Force
	}
}

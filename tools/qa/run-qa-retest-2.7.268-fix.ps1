# Full QA retest after BUG-QA-001..005 fixes (2026-05-28).
# Usage: powershell -File tools/qa/run-qa-retest-2.7.268-fix.ps1 [-SkipHeavy] [-SkipCrossSite]

param(
    [switch] $SkipHeavy,
    [switch] $SkipCrossSite
)

$ErrorActionPreference = 'Stop'
$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Evidence = Join-Path $RepoRoot 'docs\qa-evidence\qa-retest-2.7.268-fix-2026-05-28'
$ComposeQa = Join-Path $RepoRoot 'docker-compose.qa.yml'
$Compose = Join-Path $RepoRoot 'docker-compose.yml'
$Zip = Join-Path $RepoRoot 'dist\museder-restoreone-2.7.268.zip'
$BackupZip = 'localhost-20260527173501-nKKj4r.zip'

New-Item -ItemType Directory -Force -Path $Evidence | Out-Null
$log = Join-Path $Evidence 'orchestrator.log'

function Log($m) {
    $line = "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $m"
    Write-Host $line
    Add-Content -Path $log -Value $line -Encoding utf8
}

function Deploy-ZipToContainer {
    param([string]$Service)
    docker compose -f $ComposeQa cp $Zip "${Service}:/tmp/museder-restoreone-2.7.268.zip"
    $cmd = 'apt-get update -qq; apt-get install -y -qq unzip 2>/dev/null || true; rm -rf /var/www/html/wp-content/plugins/museder-restoreone; unzip -q -o /tmp/museder-restoreone-2.7.268.zip -d /var/www/html/wp-content/plugins; chown -R www-data:www-data /var/www/html/wp-content/plugins/museder-restoreone'
    docker compose -f $ComposeQa exec -u root -T $Service bash -lc $cmd
}

Log '=== QA retest orchestrator start ==='

Log 'Step 1: package-lite-windows.ps1'
& powershell -NoProfile -File (Join-Path $RepoRoot 'tools\package-lite-windows.ps1') 2>&1 | Tee-Object (Join-Path $Evidence 'package.log')
if (-not (Test-Path $Zip)) { throw 'ZIP missing after package' }
$hash = (Get-FileHash -Path $Zip -Algorithm SHA256).Hash
"SHA256=$hash" | Set-Content (Join-Path $Evidence 'zip-sha256.txt') -Encoding utf8

Log 'Step 2: ensure QA + clean stacks up'
docker compose -f $ComposeQa up -d qa-b1 qa-b1-db qa-a2 qa-a2-db | Out-Null
docker compose up -d wordpress db | Out-Null
Start-Sleep -Seconds 5

Log 'Step 3: deploy ZIP to qa-b1, qa-a2, wordpress'
foreach ($svc in @('qa-b1', 'qa-a2')) { Deploy-ZipToContainer $svc }
docker compose cp $Zip wordpress:/tmp/museder-restoreone-2.7.268.zip
docker compose exec -u root -T wordpress bash -lc 'apt-get update -qq; apt-get install -y -qq unzip 2>/dev/null || true; rm -rf /var/www/html/wp-content/plugins/museder-restoreone; unzip -q -o /tmp/museder-restoreone-2.7.268.zip -d /var/www/html/wp-content/plugins; chown -R www-data:www-data /var/www/html/wp-content/plugins/museder-restoreone'

Log 'Step 4: Plugin Check (8080)'
$pc = docker compose run --rm wpcli plugin activate museder-restoreone 2>&1
$pc += docker compose run --rm wpcli plugin check museder-restoreone 2>&1
$pc | Set-Content (Join-Path $Evidence 'plugin-check.txt') -Encoding utf8
if ($pc -notmatch 'No errors found') { throw 'Plugin Check failed' }
Log 'Plugin Check PASS'

Log 'Step 5: admin smoke (8080)'
docker compose run --rm wpcli config set WP_DEBUG true --raw | Out-Null
docker compose run --rm wpcli config set WP_DEBUG_LOG true --raw | Out-Null
$smoke = docker compose exec -T wordpress php /tmp/museder-restoreone-src/tools/qa/admin-smoke-wpdebug.php 2>&1
$smoke | Set-Content (Join-Path $Evidence 'admin-smoke-clean.txt') -Encoding utf8
if ($smoke -notmatch 'pages_pass=6/6') { throw 'Clean smoke failed' }
Log 'Clean smoke PASS'

Log 'Step 6: R-S2 round12'
& powershell -NoProfile -File (Join-Path $RepoRoot 'tools\qa\run-r-s2-round12.ps1') 2>&1 | Tee-Object (Join-Path $Evidence 'r-s2-round12.log')
if ($LASTEXITCODE -ne 0) { throw 'R-S2 round12 failed' }
Log 'R-S2 PASS'

if (-not $SkipHeavy) {
    Log 'Step 7: heavy B1 restore E2E (NO manual resume)'
    $heavyEvidence = Join-Path $Evidence 'heavy-b1-e2e'
    New-Item -ItemType Directory -Force -Path $heavyEvidence | Out-Null
    # Sync plugin from repo mount is already in container via zip; run browser E2E
    & powershell -NoProfile -File (Join-Path $RepoRoot 'tools\qa\run-heavy-site-full-e2e-browser.ps1') -SkipBackup -BackupZip $BackupZip 2>&1 | Tee-Object (Join-Path $heavyEvidence 'run.log')
    if ($LASTEXITCODE -ne 0) { throw 'Heavy B1 E2E failed (see heavy-b1-e2e/run.log)' }
    docker compose -f $ComposeQa exec -T qa-b1 grep MEDIA_PATHS /var/www/html/wp-content/uploads/museder-restoreone/logs/backup-lite-*.log 2>&1 | Set-Content (Join-Path $heavyEvidence 'media-paths-log.txt') -Encoding utf8
    Log 'Heavy B1 E2E PASS'
}

if (-not $SkipCrossSite -and -not $SkipHeavy) {
    Log 'Step 8: A2 cross-site restore from B1 backup'
    $a2Evidence = Join-Path $Evidence 'heavy-a2-cross'
    New-Item -ItemType Directory -Force -Path $a2Evidence | Out-Null
    Log 'Copy backup B1 -> A2 if missing'
    docker compose -f $ComposeQa exec -u root -T qa-a2 bash -lc "mkdir -p /var/www/html/wp-content/uploads/museder-restoreone/backups"
    docker cp "museder-restoreone-qa-b1-1:/var/www/html/wp-content/uploads/museder-restoreone/backups/$BackupZip" "$env:TEMP\qa-retest-cross.zip"
    docker cp "$env:TEMP\qa-retest-cross.zip" "museder-restoreone-qa-a2-1:/var/www/html/wp-content/uploads/museder-restoreone/backups/$BackupZip"
    docker compose -f $ComposeQa exec -u root -T qa-a2 chown www-data:www-data "/var/www/html/wp-content/uploads/museder-restoreone/backups/$BackupZip"
    & powershell -NoProfile -File (Join-Path $RepoRoot 'tools\qa\run-heavy-a2-restore-from-b1.ps1') -ArchiveName $BackupZip 2>&1 | Tee-Object (Join-Path $a2Evidence 'run.log')
    if ($LASTEXITCODE -ne 0) { throw 'A2 cross-site restore failed' }
    Log 'A2 cross-site PASS'
}

Log '=== ALL QA RETEST STEPS PASS ==='

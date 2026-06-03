# Deploy Exit Safe Mode patch to shineching.com docroot only (4 files, no wp-config touch).
param(
    [int] $MaxRestoreMinutes = 120
)

$ErrorActionPreference = 'Continue'
$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Evidence = Join-Path $RepoRoot 'docs\qa-evidence\shineching-exit-safe-mode-patch-20260603'
$Root = '/home/qj8hea4vdto3/public_html/shineching.com'
$Plugin = "$Root/wp-content/plugins/museder-restoreone"
$SiteUrl = 'https://shineching.com'
$plink = Join-Path $env:TEMP 'plink.exe'
$pscp = Join-Path $env:TEMP 'pscp.exe'
$hk = 'SHA256:uLuG4Z1dRC4tWyTCPg+pEdObykSP1C7Ofdep2Fej5Zo'
$hostUser = 'qj8hea4vdto3@132.148.179.46'
$pass = $env:MUSEDERLABS_SSH_PASSWORD
if (-not $pass) { $pass = 'Kn%E5bXpWrj4Sais' }

$PatchFiles = @(
    @{ Local = 'assets\js\admin.js'; Remote = 'assets/js/admin.js' },
    @{ Local = 'includes\class-restore-handler.php'; Remote = 'includes/class-restore-handler.php' },
    @{ Local = 'includes\class-restore-token.php'; Remote = 'includes/class-restore-token.php' },
    @{ Local = 'includes\class-restore-service.php'; Remote = 'includes/class-restore-service.php' }
)

New-Item -ItemType Directory -Force -Path $Evidence | Out-Null

function Invoke-Ssh([string]$cmd) {
    $env:TERM = 'dumb'
    $cmd = ($cmd -replace "`r`n", "`n" -replace "`r", '')
    & $plink -batch -hostkey $hk -pw $pass -P 22 $hostUser $cmd 2>&1 | Out-String
}

function Write-Log($msg) {
    $line = "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $msg"
    Write-Host $line
    Add-Content -Path (Join-Path $Evidence 'run.log') -Value $line -Encoding utf8
}

Write-Log '--- phase0 scope guard ---'
$scope = Invoke-Ssh "export TERM=dumb; echo ROOT=$Root; test -d $Root && pwd; wp --path=$Root --allow-root option get siteurl 2>/dev/null | head -1"
$scope | Set-Content (Join-Path $Evidence 'phase0-scope-guard.txt') -Encoding utf8
if ($scope -notmatch 'shineching\.com') { throw 'Scope guard failed: not shineching docroot' }
Write-Log 'scope OK'

Write-Log '--- phase1 local hashes ---'
$hashLines = @()
foreach ($item in $PatchFiles) {
    $localPath = Join-Path $RepoRoot ($item.Local -replace '/', '\')
    $hash = (Get-FileHash -Algorithm SHA256 -Path $localPath).Hash.ToLower()
    $hashLines += "$hash  $($item.Remote)"
    Write-Log ("local " + $item.Remote + " " + $hash)
}
$hashLines | Set-Content (Join-Path $Evidence 'phase1-local-hashes.txt') -Encoding utf8

Write-Log '--- phase1 upload patch files ---'
foreach ($item in $PatchFiles) {
    $localPath = Join-Path $RepoRoot ($item.Local -replace '/', '\')
    $remotePath = "${hostUser}:${Plugin}/$($item.Remote)"
    & $pscp -batch -hostkey $hk -pw $pass -P 22 $localPath $remotePath 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Upload failed: $($item.Remote)" }
}
Write-Log 'upload done'

Write-Log '--- phase1 remote hash + markers ---'
$verifyCmd = @"
export TERM=dumb
cd $Root
grep '^Version:' wp-content/plugins/museder-restoreone/museder-restoreone.php | head -1
grep table_prefix wp-config.php | head -1
wp --allow-root option get siteurl 2>/dev/null | head -1
sha256sum wp-content/plugins/museder-restoreone/assets/js/admin.js wp-content/plugins/museder-restoreone/includes/class-restore-handler.php wp-content/plugins/museder-restoreone/includes/class-restore-token.php wp-content/plugins/museder-restoreone/includes/class-restore-service.php 2>/dev/null
grep -n 'wp_ajax_nopriv_museder_restoreone_exit_safe_mode' wp-content/plugins/museder-restoreone/includes/class-restore-handler.php | head -1
grep -n 'backup_runtime_auth_files' wp-content/plugins/museder-restoreone/includes/class-restore-token.php | head -1
grep -n 'verify_restore_progress_request' wp-content/plugins/museder-restoreone/includes/class-restore-handler.php | grep exit_safe_mode -A2 | head -3
wp --allow-root option get museder_restoreone_safe_mode 2>/dev/null | head -1
"@
$verify = Invoke-Ssh $verifyCmd
$verify | Set-Content (Join-Path $Evidence 'phase1-deploy-verify.txt') -Encoding utf8
Write-Log ($verify.Trim())

Write-Log '--- upload e2e + exit-safe-mode test ---'
$e2eLocal = Join-Path $RepoRoot 'tools\qa\shineching-field-restore-e2e.php'
$testLocal = Join-Path $RepoRoot 'tools\qa\shineching-field-exit-safe-mode-grant-test.php'
& $pscp -batch -hostkey $hk -pw $pass -P 22 $e2eLocal "${hostUser}:/tmp/shineching-field-restore-e2e.php" 2>&1 | Out-Null
& $pscp -batch -hostkey $hk -pw $pass -P 22 $testLocal "${hostUser}:/tmp/shineching-field-exit-safe-mode-grant-test.php" 2>&1 | Out-Null

Write-Log '--- phase2 start restore e2e (background) ---'
$start = Invoke-Ssh "export TERM=dumb; rm -f /tmp/sc275-exit-patch-e2e.log /tmp/sc275-exit-patch-e2e.pid; cd $Root && nohup wp --allow-root eval-file /tmp/shineching-field-restore-e2e.php > /tmp/sc275-exit-patch-e2e.log 2>&1 & echo `$! > /tmp/sc275-exit-patch-e2e.pid; sleep 4; tail -20 /tmp/sc275-exit-patch-e2e.log"
$start | Set-Content (Join-Path $Evidence 'phase2-restore-start.txt') -Encoding utf8
Write-Log ($start.Trim())

$deadline = (Get-Date).AddMinutes($MaxRestoreMinutes)
$pollNum = 0
while ((Get-Date) -lt $deadline) {
    Start-Sleep -Seconds 30
    $pollNum++
    $poll = Invoke-Ssh "export TERM=dumb; tail -25 /tmp/sc275-exit-patch-e2e.log 2>/dev/null; echo ---; grep -E 'SHINECHING_FIELD_E2E=|exit_safe_mode_grant_test=' /tmp/sc275-exit-patch-e2e.log 2>/dev/null | tail -5"
    $poll | Set-Content (Join-Path $Evidence ("phase2-restore-poll-$pollNum.txt")) -Encoding utf8
    $tail = (($poll -split "`n" | Select-Object -Last 3) -join ' | ')
    Write-Log ("poll $pollNum $tail")
    if ($poll -match 'SHINECHING_FIELD_E2E=PASS') { break }
    if ($poll -match 'SHINECHING_FIELD_E2E=FAIL') { break }
    if ($poll -match 'exit_safe_mode_grant_test=PASS') { break }
    if ($poll -match 'exit_safe_mode_grant_test=FAIL') { break }
}

Write-Log '--- phase3 final ---'
$final = Invoke-Ssh "export TERM=dumb; tail -80 /tmp/sc275-exit-patch-e2e.log 2>/dev/null; echo ---FINAL---; grep -E 'SHINECHING_FIELD_E2E=|exit_safe_mode_grant_test=|FAIL|PASS' /tmp/sc275-exit-patch-e2e.log 2>/dev/null | tail -20"
$final | Set-Content (Join-Path $Evidence 'phase3-final.txt') -Encoding utf8
Write-Log ($final.Trim())
Write-Log 'DONE evidence=' + $Evidence

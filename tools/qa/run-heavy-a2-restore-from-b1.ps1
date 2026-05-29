# QA-A2 (B site) restore from QA-B1 heavy backup — browser admin-ajax simulation.
# Usage: powershell -File tools/qa/run-heavy-a2-restore-from-b1.ps1

param(
    [string] $ArchiveName = 'localhost-20260527173501-nKKj4r.zip',
    [int] $MaxRestoreMinutes = 300
)

$ErrorActionPreference = 'Stop'
$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$ComposeQa = Join-Path $RepoRoot 'docker-compose.qa.yml'
$BaseUrl = 'http://localhost:8082'
$AjaxUrl = "$BaseUrl/wp-admin/admin-ajax.php"
$Evidence = Join-Path $RepoRoot 'docs\qa-evidence\qa-retest-2.7.268-fix-round5-2026-05-25\heavy-a2-from-b1'
$Cookies = Join-Path $Evidence 'cookies.txt'
$A2Vol = 'museder-restoreone_qa_a2_wp'

New-Item -ItemType Directory -Force -Path $Evidence | Out-Null

function Write-Log($msg) {
    $line = "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $msg"
    Write-Host $line
    Add-Content -Path (Join-Path $Evidence 'run.log') -Value $line -Encoding utf8
}

function Invoke-QaWpA2 {
    param([string[]]$WpArgs)
    docker run --rm --user root `
        --network museder-restoreone_default `
        -v "${A2Vol}:/var/www/html" `
        -v "${RepoRoot}/tools/docker/php.ini:/usr/local/etc/php/conf.d/zzz-museder-restoreone.ini:ro" `
        -e WORDPRESS_DB_HOST=qa-a2-db:3306 `
        -e WORDPRESS_DB_USER=wordpress `
        -e WORDPRESS_DB_PASSWORD=wordpress `
        -e WORDPRESS_DB_NAME=wordpress_a2 `
        --entrypoint wp `
        wordpress:cli-php8.2 --allow-root --skip-plugins --skip-themes @WpArgs
}

function Invoke-BrowserAjax {
    param([string]$Action, [hashtable]$Fields = @{})
    $args = @('-s', '-b', $Cookies, '-c', $Cookies, '-X', 'POST', $AjaxUrl, '--data-urlencode', "action=$Action", '--data-urlencode', "nonce=$script:Nonce")
    if ($script:RestoreToken) {
        $args += @('--data-urlencode', "restore_token=$($script:RestoreToken)")
    }
    foreach ($k in $Fields.Keys) { $args += @('--data-urlencode', "${k}=$($Fields[$k])") }
    $raw = & curl.exe @args
    if (-not $raw) { throw "Empty response for action=$Action" }
    try { return $raw | ConvertFrom-Json } catch {
        $path = Join-Path $Evidence "ajax-$Action-$(Get-Date -Format 'HHmmss').txt"
        $raw | Set-Content $path -Encoding utf8
        throw "Invalid JSON for $Action"
    }
}

function Login-WpAdmin {
    if (Test-Path $Cookies) { Remove-Item $Cookies -Force }
    & curl.exe -s -c $Cookies -b $Cookies -L -X POST "$BaseUrl/wp-login.php" `
        --data-urlencode 'log=admin' --data-urlencode 'pwd=admin' `
        --data-urlencode 'wp-submit=Log In' --data-urlencode "redirect_to=$BaseUrl/wp-admin/" `
        --data-urlencode 'testcookie=1' | Out-Null
    $probe = & curl.exe -s -b $Cookies -c $Cookies -o NUL -w '%{http_code}' "$BaseUrl/wp-admin/"
    if ($probe -ne '200') { throw "WP admin login failed HTTP $probe" }
}

function Get-BrowserNonce {
    $html = & curl.exe -s -b $Cookies -c $Cookies "$BaseUrl/wp-admin/admin.php?page=museder-restoreone-backups"
    $m = [regex]::Match($html, 'MusederRestoreOneAdmin\s*=\s*\{.*?"nonce":"([a-f0-9]+)"')
    if ($m.Success) { return $m.Groups[1].Value }
    throw 'Could not extract nonce'
}

function Wait-RestoreJob {
    param([string]$JobId, [int]$MaxMinutes)
    $deadline = (Get-Date).AddMinutes($MaxMinutes)
    $i = 0
    while ((Get-Date) -lt $deadline) {
        $i++
        Invoke-BrowserAjax -Action 'museder_restoreone_restore_tick' -Fields @{ job_id = $JobId; slice = '8' } | Out-Null
        $st = Invoke-BrowserAjax -Action 'museder_restoreone_restore_job_status' -Fields @{ job_id = $JobId }
        $job = $null
        if ($st.success -and $st.data.job) { $job = $st.data.job }
        if (-not $job) {
            Write-Log "restore poll #$i (no job yet)"
            Start-Sleep -Seconds 5
            continue
        }
        $status = [string]$job.status
        $prog = if ($null -ne $job.progress) { [string]$job.progress } else { '?' }
        $stage = if ($job.stage) { [string]$job.stage } else { '' }
        $msg = if ($job.message) { [string]$job.message } else { '' }
        if ($msg.Length -gt 80) { $msg = $msg.Substring(0, 80) + '...' }
        Write-Log "restore poll #$i status=$status stage=$stage progress=$prog msg=$msg"
        if ($status -in @('success', 'completed')) { return $job }
        if ($status -in @('failed', 'cancelled')) { throw "Restore failed status=$status" }
        if ($status -eq 'pending') {
            Invoke-BrowserAjax -Action 'museder_restoreone_trigger_restore_job' -Fields @{ job_id = $JobId } | Out-Null
        }
        Start-Sleep -Seconds 5
    }
    throw "Restore timeout after $MaxMinutes minutes"
}

Write-Log "=== A2 restore from B1 archive: $ArchiveName ==="
$before = (Invoke-QaWpA2 @('option', 'get', 'blogname') | Select-Object -Last 1).Trim()
Write-Log "blogname before restore: $before"

Login-WpAdmin
$script:Nonce = Get-BrowserNonce
Write-Log "Nonce OK"

$prep = Invoke-BrowserAjax -Action 'museder_restoreone_restore_from_backup' -Fields @{ filename = $ArchiveName }
if (-not $prep.success) { throw "restore_from_backup failed" }
($prep | ConvertTo-Json -Depth 8) | Set-Content (Join-Path $Evidence 'restore-prep.json') -Encoding utf8

$enqueue = Invoke-BrowserAjax -Action 'museder_restoreone_restore_enqueue' -Fields @{
    overwrite = 'true'; autoBackup = 'true'; wpConfigMode = 'backup'
    restoreOrder = 'db_then_files'; restoreScope = 'full'
    pauseOtherPlugins = 'true'; safeMode = 'true'; filesOnly = 'false'
}
if (-not $enqueue.success) { throw "restore_enqueue failed" }
($enqueue | ConvertTo-Json -Depth 8) | Set-Content (Join-Path $Evidence 'restore-enqueue.json') -Encoding utf8
$restoreJobId = [string]$enqueue.data.job.id
if (-not $restoreJobId -and $enqueue.data.job_id) { $restoreJobId = [string]$enqueue.data.job_id }
$script:RestoreToken = ''
if ($enqueue.data.restore_token) {
    $script:RestoreToken = [string]$enqueue.data.restore_token
} elseif ($enqueue.data.exec -and $enqueue.data.exec.restore_token) {
    $script:RestoreToken = [string]$enqueue.data.exec.restore_token
}
if ($script:RestoreToken) {
    Write-Log "Restore token captured (len=$($script:RestoreToken.Length))"
} else {
    Write-Log 'WARN: no restore_token in enqueue response'
}
Write-Log "Restore job: $restoreJobId"

Invoke-BrowserAjax -Action 'museder_restoreone_trigger_restore_job' -Fields @{ job_id = $restoreJobId } | Out-Null
$done = Wait-RestoreJob -JobId $restoreJobId -MaxMinutes $MaxRestoreMinutes
($done | ConvertTo-Json -Depth 8) | Set-Content (Join-Path $Evidence 'restore-done.json') -Encoding utf8

$after = (Invoke-QaWpA2 @('option', 'get', 'blogname') | Select-Object -Last 1).Trim()
Write-Log "blogname after restore: $after"
"before=$before`nafter=$after`nchanged=$($before -ne $after)" | Set-Content (Join-Path $Evidence 'verify-blogname.txt') -Encoding utf8

docker compose -f $ComposeQa exec -T qa-a2 bash -lc "cat /var/www/html/wp-content/uploads/museder-restoreone/jobs/${restoreJobId}.json" 2>&1 | Set-Content (Join-Path $Evidence 'job-final.json') -Encoding utf8
docker compose -f $ComposeQa exec -T qa-a2 bash -lc "grep -E 'MEDIA_PATHS_' /var/www/html/wp-content/uploads/museder-restoreone/logs/backup-lite-*.log 2>/dev/null | tail -20" 2>&1 | Set-Content (Join-Path $Evidence 'log-excerpt.txt') -Encoding utf8

$smoke = docker compose -f $ComposeQa exec -T qa-a2 bash -lc ": > /var/www/html/wp-content/debug.log" 2>&1 | Out-Null
$smoke = docker compose -f $ComposeQa exec -T qa-a2 php /var/www/html/wp-content/uploads/museder-restoreone/qa-tools/admin-smoke-wpdebug.php 2>&1
$smoke | Set-Content (Join-Path $Evidence 'admin-smoke.txt') -Encoding utf8
if ($smoke -notmatch 'pages_pass=6/6') { throw 'admin smoke failed' }

Write-Log '=== PASS A2 heavy restore ==='

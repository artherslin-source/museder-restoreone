# Heavy-site FULL backup + restore E2E via admin-ajax (browser simulation).
# Usage: powershell -File tools/qa/run-heavy-site-full-e2e-browser.ps1 [-SkipBackup] [-BackupZip name.zip]

param(
    [switch] $SkipBackup,
    [string] $BackupZip = ''
)

$ErrorActionPreference = 'Stop'
$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$ComposeQa = Join-Path $RepoRoot 'docker-compose.qa.yml'
$BaseUrl = 'http://localhost:8083'
$AjaxUrl = "$BaseUrl/wp-admin/admin-ajax.php"
$Evidence = Join-Path $RepoRoot 'docs\qa-evidence\release-2.7.268-2026-05\heavy-site-e2e-browser'
$Cookies = Join-Path $Evidence 'cookies.txt'
$B1Vol = 'museder-restoreone_qa_b1_wp'

New-Item -ItemType Directory -Force -Path $Evidence | Out-Null

function Write-Log($msg) {
    $line = "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $msg"
    Write-Host $line
    Add-Content -Path (Join-Path $Evidence 'run.log') -Value $line -Encoding utf8
}

function Invoke-QaWp {
    param([string[]]$WpArgs)
    docker run --rm --user root `
        --network museder-restoreone_default `
        -v "${B1Vol}:/var/www/html" `
        -v "${RepoRoot}/tools/docker/php.ini:/usr/local/etc/php/conf.d/zzz-museder-restoreone.ini:ro" `
        -e WORDPRESS_DB_HOST=qa-b1-db:3306 `
        -e WORDPRESS_DB_USER=wordpress `
        -e WORDPRESS_DB_PASSWORD=wordpress `
        -e WORDPRESS_DB_NAME=wordpress_b1 `
        --entrypoint wp `
        wordpress:cli-php8.2 --allow-root --skip-plugins --skip-themes @WpArgs
}

function Invoke-BrowserAjax {
    param(
        [string]$Action,
        [hashtable]$Fields = @{}
    )
    $args = @(
        '-s', '-b', $Cookies, '-c', $Cookies,
        '-X', 'POST', $AjaxUrl,
        '--data-urlencode', "action=$Action",
        '--data-urlencode', "nonce=$script:Nonce"
    )
    if ($script:RestoreToken) {
        $args += @('--data-urlencode', "restore_token=$($script:RestoreToken)")
    }
    foreach ($k in $Fields.Keys) {
        $args += @('--data-urlencode', "${k}=$($Fields[$k])")
    }
    $raw = & curl.exe @args
    if (-not $raw) { throw "Empty response for action=$Action" }
    try {
        return $raw | ConvertFrom-Json
    } catch {
        $path = Join-Path $Evidence "ajax-$Action-$(Get-Date -Format 'HHmmss').txt"
        $raw | Set-Content $path -Encoding utf8
        throw "Invalid JSON for $Action (saved $path): $($raw.Substring(0, [Math]::Min(200, $raw.Length)))"
    }
}

function Login-WpAdmin {
    if (Test-Path $Cookies) { Remove-Item $Cookies -Force }
    $login = "$BaseUrl/wp-login.php"
    $redir = [uri]::EscapeDataString("$BaseUrl/wp-admin/")
    & curl.exe -s -c $Cookies -b $Cookies -L -X POST $login `
        --data-urlencode 'log=admin' `
        --data-urlencode 'pwd=admin' `
        --data-urlencode 'wp-submit=Log In' `
        --data-urlencode "redirect_to=$BaseUrl/wp-admin/" `
        --data-urlencode 'testcookie=1' | Out-Null
    $probe = & curl.exe -s -b $Cookies -c $Cookies -o NUL -w '%{http_code}' "$BaseUrl/wp-admin/"
    if ($probe -ne '200') { throw "WP admin login failed HTTP $probe" }
    Write-Log "WP admin login OK"
}

function Get-BrowserNonce {
    $html = & curl.exe -s -b $Cookies -c $Cookies "$BaseUrl/wp-admin/admin.php?page=museder-restoreone-backups"
    $htmlPath = Join-Path $Evidence 'backups-page.html'
    $html | Set-Content $htmlPath -Encoding utf8
    $m = [regex]::Match( $html, 'MusederRestoreOneAdmin\s*=\s*\{.*?"nonce":"([a-f0-9]+)"' )
    if ($m.Success) { return $m.Groups[1].Value }
    $m2 = [regex]::Match( $html, 'id="museder_restoreone_nonce"[^>]*value="([a-f0-9]+)"' )
    if ($m2.Success) { return $m2.Groups[1].Value }
    throw 'Could not extract Museder RestoreOne nonce from backups admin page'
}

function Wait-BackupJob {
    param([string]$JobId, [int]$MaxMinutes = 180)
    $deadline = (Get-Date).AddMinutes($MaxMinutes)
    $i = 0
    while ((Get-Date) -lt $deadline) {
        $i++
        $cont = Invoke-BrowserAjax -Action 'museder_restoreone_continue_backup_job' -Fields @{ job_id = $JobId }
        $st = Invoke-BrowserAjax -Action 'museder_restoreone_get_backup_job_status' -Fields @{ job_id = $JobId }
        $job = $null
        if ($st.success -and $st.data.job) { $job = $st.data.job }
        elseif ($cont.success -and $cont.data.job) { $job = $cont.data.job }
        if (-not $job) { throw 'backup job payload missing' }
        $status = [string]$job.status
        $prog = if ($null -ne $job.percentage) { [string]$job.percentage } else { [string]$job.progress }
        Write-Log "backup poll #$i status=$status progress=$prog%"
        if ($status -in @('completed', 'failed', 'cancelled')) {
            return $job
        }
        Start-Sleep -Seconds 3
    }
    throw "Backup job timeout after $MaxMinutes minutes"
}

function Wait-RestoreJob {
    param([string]$JobId, [int]$MaxMinutes = 180)
    $deadline = (Get-Date).AddMinutes($MaxMinutes)
    $i = 0
    while ((Get-Date) -lt $deadline) {
        $i++
        $tickResp = Invoke-BrowserAjax -Action 'museder_restoreone_restore_tick' -Fields @{ job_id = $JobId; slice = '12' }
        if ($tickResp.success -eq $false) {
            Write-Log "restore_tick error: $($tickResp | ConvertTo-Json -Compress -Depth 4)"
        }
        $st = Invoke-BrowserAjax -Action 'museder_restoreone_restore_job_status' -Fields @{ job_id = $JobId }
        $job = $null
        if ($st.success) {
            if ($st.data.job) { $job = $st.data.job }
            elseif ($st.data -is [pscustomobject] -and $st.data.id) { $job = $st.data }
        }
        if (-not $job) {
            if ($st.success -and $st.data.history -and $st.data.history.Count -gt 0) {
                $h = $st.data.history[0]
                Write-Log "restore poll #$i history result=$($h.result)"
                if ($h.result -eq 'success') { return @{ status = 'success'; progress = 100; message = $h.message } }
                if ($h.result -eq 'failed') { throw "Restore failed: $($h.message)" }
            }
            Write-Log "restore poll #$i (no job object yet)"
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
    throw "Restore job timeout after $MaxMinutes minutes"
}

Write-Log '=== Heavy-site browser FULL backup/restore E2E ==='
docker compose -f $ComposeQa up -d qa-b1 qa-b1-db | Out-Null
Start-Sleep -Seconds 3

$siteDu = (docker compose -f $ComposeQa exec -T qa-b1 du -sh /var/www/html 2>&1 | Select-Object -Last 1).Trim()
Write-Log "Site size: $siteDu"

Login-WpAdmin
$script:Nonce = Get-BrowserNonce
Write-Log "Nonce: $($script:Nonce.Substring(0,8))..."

$blogBefore = (Invoke-QaWp @('option', 'get', 'blogname') | Select-Object -Last 1).Trim()
$marker = "E2E-MARKER-$(Get-Date -Format 'yyyyMMddHHmmss')"
Invoke-QaWp @('option', 'update', 'blogname', $marker) | Out-Null
Write-Log "Marker blogname set: $marker"

# --- BACKUP (browser) ---
if ($SkipBackup -and $BackupZip) {
    $archiveName = $BackupZip
    Write-Log "SkipBackup: using $archiveName"
} elseif ($SkipBackup) {
    throw 'SkipBackup requires -BackupZip with a filename.zip (PowerShell breaks wp eval glob). Example: -BackupZip localhost-20260527173501-nKKj4r.zip'
} else {
    $start = Invoke-BrowserAjax -Action 'museder_restoreone_start_backup_job' -Fields @{
        backup_mode          = 'balanced'
        backup_smart_exclude = 'auto'
    }
    if (-not $start.success) { throw "start_backup_job failed: $($start | ConvertTo-Json -Compress)" }
    $backupJobId = [string]$start.data.job.id
    Write-Log "Backup job started: $backupJobId"
    ($start | ConvertTo-Json -Depth 8) | Set-Content (Join-Path $Evidence 'backup-start.json') -Encoding utf8

    $backupDone = Wait-BackupJob -JobId $backupJobId -MaxMinutes 240
    ($backupDone | ConvertTo-Json -Depth 8) | Set-Content (Join-Path $Evidence 'backup-done.json') -Encoding utf8
    if ([string]$backupDone.status -ne 'completed') { throw "Backup ended with status=$($backupDone.status)" }

    $archiveName = ''
    if ($backupDone.download_url) {
        $dl = [System.Net.WebUtility]::HtmlDecode( [string]$backupDone.download_url )
        if ($dl -match '[?&]file=([^&]+\.zip)') {
            $archiveName = $Matches[1]
        }
    }
    if (-not $archiveName) {
        $archiveName = (Invoke-QaWp @('eval', '$f=glob(trailingslashit(museder_restoreone_get_backup_dir())."*.zip"); usort($f,function($a,$b){return filemtime($b)-filemtime($a);}); echo $f?basename($f[0]):"";') | Select-Object -Last 1).Trim()
    }
    if (-not $archiveName) { throw 'No backup archive name' }
    Write-Log "Backup archive: $archiveName"
}

# Corrupt site after backup (simulate post-backup drift)
$corrupt = "CORRUPTED-AFTER-BACKUP-$(Get-Date -Format 'HHmmss')"
Invoke-QaWp @('option', 'update', 'blogname', $corrupt) | Out-Null
Write-Log "Corrupted blogname: $corrupt"

# --- RESTORE (browser) ---
$prep = Invoke-BrowserAjax -Action 'museder_restoreone_restore_from_backup' -Fields @{ filename = $archiveName }
if (-not $prep.success) { throw "restore_from_backup failed: $($prep | ConvertTo-Json -Compress)" }
($prep | ConvertTo-Json -Depth 8) | Set-Content (Join-Path $Evidence 'restore-prep.json') -Encoding utf8
Write-Log 'restore_from_backup OK'

$enqueue = Invoke-BrowserAjax -Action 'museder_restoreone_restore_enqueue' -Fields @{
    overwrite         = 'true'
    autoBackup        = 'true'
    wpConfigMode      = 'backup'
    restoreOrder      = 'db_then_files'
    restoreScope      = 'full'
    pauseOtherPlugins = 'true'
    safeMode          = 'true'
    filesOnly         = 'false'
}
if (-not $enqueue.success) { throw "restore_enqueue failed: $($enqueue | ConvertTo-Json -Compress)" }
($enqueue | ConvertTo-Json -Depth 8) | Set-Content (Join-Path $Evidence 'restore-enqueue.json') -Encoding utf8
$restoreJobId = [string]$enqueue.data.job.id
if (-not $restoreJobId -and $enqueue.data.job_id) { $restoreJobId = [string]$enqueue.data.job_id }
if (-not $restoreJobId) { throw 'No restore job id from enqueue' }
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
Write-Log "Restore job started: $restoreJobId"

Invoke-BrowserAjax -Action 'museder_restoreone_trigger_restore_job' -Fields @{ job_id = $restoreJobId } | Out-Null
$restoreDone = Wait-RestoreJob -JobId $restoreJobId -MaxMinutes 240
($restoreDone | ConvertTo-Json -Depth 8) | Set-Content (Join-Path $Evidence 'restore-done.json') -Encoding utf8

$blogAfter = (Invoke-QaWp @('option', 'get', 'blogname') | Select-Object -Last 1).Trim()
Write-Log "blogname after restore: $blogAfter (expected marker: $marker)"

if ($SkipBackup) {
    # Pre-made archive: marker set above is not inside the zip; verify restore undid corruption.
    $verifyOk = ($blogAfter -ne $corrupt)
    Write-Log "SkipBackup verify: blogname ne corrupt => $verifyOk"
} else {
    $verifyOk = ($blogAfter -eq $marker)
}
"blog_before_backup=$blogBefore`nmarker=$marker`ncorrupt=$corrupt`nblog_after=$blogAfter`nverify=$verifyOk" | Set-Content (Join-Path $Evidence 'verify.txt') -Encoding utf8

Write-Log 'Admin smoke after restore...'
docker compose -f $ComposeQa exec -T qa-b1 bash -lc ": > /var/www/html/wp-content/debug.log" 2>&1 | Out-Null
$smoke = docker compose -f $ComposeQa exec -T qa-b1 php /var/www/html/wp-content/uploads/museder-restoreone/qa-tools/admin-smoke-wpdebug.php 2>&1
$smoke | Set-Content (Join-Path $Evidence 'admin-smoke.txt') -Encoding utf8
$smokePass = ($smoke -match 'pages_pass=6/6') -and ($smoke -match 'debug_log_fatal=no')

if (-not $verifyOk) { throw "blogname verify failed: got '$blogAfter', want '$marker'" }
if (-not $smokePass) { throw 'admin-smoke-wpdebug failed after restore' }

Write-Log '=== PASS: Heavy-site browser FULL backup/restore E2E ==='
exit 0

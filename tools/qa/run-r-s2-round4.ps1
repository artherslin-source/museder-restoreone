# R-S2 round 4 — QA-A1 bootstrap E2E poll
$ErrorActionPreference = 'Stop'
$Repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Evidence = Join-Path $Repo 'docs\qa-evidence\approach-b-retest-2026-05\R-S2-round4'
$A1 = 'museder-restoreone-qa-a1-1'
$BaseUrl = 'http://localhost:8081/museder-restoreone-restore-bootstrap.php'
$Secret = 'qa-secret-12345'
$Backup = 'localhost-20260527083521-E02Tyj.zip'

if (-not (Test-Path $Evidence)) { New-Item -ItemType Directory -Path $Evidence -Force | Out-Null }

Write-Host '[R-S2] empty_shell reset...'
$prep = @'
set -e
cd /var/www/html
rm -rf wp-admin wp-includes
rm -f index.php wp-*.php xmlrpc.php readme.html license.txt
rm -rf wp-content/uploads/museder-restoreone/jobs/*
mkdir -p wp-content/uploads/museder-restoreone/backups wp-content/plugins/museder-restoreone
test -f museder-restoreone-restore-bootstrap.php
'@
docker exec $A1 bash -lc $prep | Out-Null

Write-Host '[R-S2] POST start restore...'
$postFile = Join-Path $Evidence 'bootstrap-post.html'
$postCode = curl.exe -s -o $postFile -w '%{http_code}' --max-time 180 -X POST `
    -d "museder_bootstrap_start=1&backup=$Backup&secret=$Secret" $BaseUrl
"POST HTTP: $postCode" | Set-Content (Join-Path $Evidence 'bootstrap-post-status.txt') -Encoding utf8
Write-Host "POST HTTP: $postCode"
if ($postCode -notmatch '^2') { throw "POST failed: $postCode" }

$html = Get-Content $postFile -Raw
$jobId = ''
if ($html -match 'Job:</strong>\s*([a-zA-Z0-9_]+)') { $jobId = $Matches[1] }
if ($jobId -eq '' -and $html -match 'job_id=([a-zA-Z0-9_]+)') { $jobId = $Matches[1] }
if ($jobId -eq '') {
    $handoff = docker exec $A1 cat /var/www/html/wp-content/uploads/museder-restoreone/bootstrap-handoff.json 2>$null
    if ($handoff -match '"job_id"\s*:\s*"([^"]+)"') { $jobId = $Matches[1] }
}
if ($jobId -eq '') { throw 'Could not parse job_id from POST' }
Write-Host "job_id=$jobId"
$jobId | Set-Content (Join-Path $Evidence 'job-id.txt') -Encoding utf8

$pollUrl = "$BaseUrl`?job_id=$jobId&secret=$Secret"
$finished = $false
for ($i = 0; $i -lt 120; $i++) {
    Start-Sleep -Seconds 10
    $pollFile = Join-Path $Evidence "poll-$i.html"
    $pollCode = curl.exe -s -o $pollFile -w '%{http_code}' --max-time 120 $pollUrl
    $pollHtml = Get-Content $pollFile -Raw -ErrorAction SilentlyContinue
    $prog = ''
    if ($pollHtml -match 'Progress:</strong>\s*(\d+)') { $prog = $Matches[1] }
    $status = ''
    if ($pollHtml -match 'Status:</strong>\s*([^<]+)') { $status = $Matches[1].Trim() }
    Write-Host "poll $i HTTP=$pollCode progress=$prog status=$status"
    if ($pollHtml -match 'Status:</strong>\s*finished' -or $pollHtml -match 'Restore complete' -or ($prog -eq '100')) {
        $finished = $true
        break
    }
    if ($pollHtml -match 'failed|Fatal|error' -and $pollHtml -notmatch 'no error') {
        Write-Warning "possible failure in poll page"
    }
    docker exec $A1 bash -lc 'test -f /var/www/html/wp-load.php && echo WP_LOAD_OK' 2>$null | ForEach-Object { if ($_ -eq 'WP_LOAD_OK') { $script:hasWpLoad = $true } }
    if ($hasWpLoad -and $prog -ge 90) { }
}

$core = docker exec $A1 bash -lc 'test -f /var/www/html/wp-admin/index.php && test -f /var/www/html/wp-load.php && echo CORE_OK || echo CORE_MISSING'
$core | Set-Content (Join-Path $Evidence 'core-check.txt') -Encoding utf8
Write-Host "core: $core"

$metaPath = "/var/www/html/wp-content/uploads/museder-restoreone/jobs/${jobId}.json"
$metaJson = docker exec $A1 cat $metaPath 2>$null
$metaJson | Set-Content (Join-Path $Evidence 'job-meta.json') -Encoding utf8
$pauseOk = $metaJson -match '"pause_other_plugins"\s*:\s*true'
"pause_other_plugins=$pauseOk" | Set-Content (Join-Path $Evidence 'pause-check.txt') -Encoding utf8

$result = @{
    post_code = $postCode
    job_id    = $jobId
    finished  = $finished
    core      = $core.Trim()
    pause_ok  = $pauseOk
} | ConvertTo-Json
$result | Set-Content (Join-Path $Evidence 'r-s2-summary.json') -Encoding utf8
Write-Host $result

if ($core -notmatch 'CORE_OK') { throw 'CORE_MISSING' }
if (-not $pauseOk) { throw 'pause_other_plugins not true in job meta' }
if (-not $finished) { throw 'Restore did not report finished' }
Write-Host '[R-S2] PASS'

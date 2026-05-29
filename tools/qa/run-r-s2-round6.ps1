# R-S2 round 6 — QA-A1 bootstrap E2E
$ErrorActionPreference = 'Stop'
$Repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Evidence = Join-Path $Repo 'docs\qa-evidence\approach-b-retest-2026-05\R-S2-round6'
$A1 = 'museder-restoreone-qa-a1-1'
$BaseUrl = 'http://localhost:8081/museder-restoreone-restore-bootstrap.php'
$Secret = 'qa-secret-12345'
$Backup = 'localhost-20260527083521-E02Tyj.zip'

New-Item -ItemType Directory -Force -Path $Evidence | Out-Null

Write-Host '[R-S2] Deploy bootstrap + lock + helpers...'
docker cp (Join-Path $Repo 'includes\class-restore-bootstrap.php') "${A1}:/var/www/html/wp-content/plugins/museder-restoreone/includes/class-restore-bootstrap.php"
docker cp (Join-Path $Repo 'includes\class-restore-lock.php') "${A1}:/var/www/html/wp-content/plugins/museder-restoreone/includes/class-restore-lock.php"
docker cp (Join-Path $Repo 'includes\helpers.php') "${A1}:/var/www/html/wp-content/plugins/museder-restoreone/includes/helpers.php"
docker cp (Join-Path $Repo 'museder-restoreone-restore-bootstrap.php') "${A1}:/var/www/html/museder-restoreone-restore-bootstrap.php"

docker exec $A1 bash -lc "echo 'memory_limit=2048M' > /usr/local/etc/php/conf.d/zzz-qa-mem.ini"
docker exec $A1 bash -lc "cd /var/www/html && rm -rf wp-admin wp-includes && rm -f index.php wp-*.php xmlrpc.php readme.html license.txt && rm -rf wp-content/uploads/museder-restoreone/jobs/* wp-content/uploads/museder-restoreone/bootstrap-handoff.json wp-content/uploads/museder-restoreone/bootstrap-restore.lock && mkdir -p wp-content/uploads/museder-restoreone/backups wp-content/uploads/museder-restoreone/jobs && chmod -R 777 wp-content/uploads/museder-restoreone"

Write-Host '[R-S2] POST start...'
$postFile = Join-Path $Evidence 'bootstrap-post.html'
$postCode = curl.exe -s -o $postFile -w '%{http_code}' --max-time 300 -X POST `
    -d "museder_bootstrap_start=1&backup=$Backup&secret=$Secret" $BaseUrl
"POST HTTP: $postCode" | Set-Content (Join-Path $Evidence 'bootstrap-post-status.txt') -Encoding utf8
Write-Host "POST HTTP: $postCode"
if ($postCode -notmatch '^2') {
    docker logs $A1 2>&1 | Select-String -Pattern 'Fatal' | Select-Object -Last 3
    throw "POST failed: $postCode"
}

$handoff = docker exec $A1 test -f /var/www/html/wp-content/uploads/museder-restoreone/bootstrap-handoff.json 2>$null; echo $LASTEXITCODE
if ($handoff -ne '0') { throw 'bootstrap-handoff.json not created' }
docker exec $A1 cat /var/www/html/wp-content/uploads/museder-restoreone/bootstrap-handoff.json | Set-Content (Join-Path $Evidence 'bootstrap-handoff.json') -Encoding utf8

$html = Get-Content $postFile -Raw
$jobId = ''
if ($html -match 'Job:</strong>\s*([a-zA-Z0-9_]+)') { $jobId = $Matches[1] }
if ($jobId -eq '' -and (Get-Content (Join-Path $Evidence 'bootstrap-handoff.json') -Raw) -match '"job_id"\s*:\s*"([^"]+)"') {
    $jobId = $Matches[1]
}
if ($jobId -eq '') { throw 'Could not resolve job_id' }
$jobId | Set-Content (Join-Path $Evidence 'job-id.txt') -Encoding utf8
Write-Host "job_id=$jobId"

$pollUrl = "$BaseUrl`?job_id=$jobId&secret=$Secret"
$finished = $false
for ($i = 0; $i -lt 180; $i++) {
    Start-Sleep -Seconds 12
    $pollFile = Join-Path $Evidence "poll-$i.html"
    $pollCode = curl.exe -s -o $pollFile -w '%{http_code}' --max-time 200 $pollUrl
    $pollHtml = if (Test-Path $pollFile) { Get-Content $pollFile -Raw } else { '' }
    $prog = if ($pollHtml -match 'Progress:</strong>\s*(\d+)') { $Matches[1] } else { '?' }
    $wp = docker exec $A1 bash -lc 'test -f /var/www/html/wp-load.php && echo 1 || echo 0'
    Write-Host "poll $i HTTP=$pollCode progress=$prog wp_load=$wp"
    if ($pollHtml -match 'Invalid or missing bootstrap secret') { throw 'Invalid secret on poll' }
    if ($pollHtml -match 'Status:</strong>\s*finished' -or $prog -eq '100' -or ($wp -eq '1' -and $prog -ne '?' -and [int]$prog -ge 95)) {
        $finished = $true
        break
    }
    $meta = docker exec $A1 cat "/var/www/html/wp-content/uploads/museder-restoreone/jobs/${jobId}.json" 2>$null
    if ($meta -match '"completed"\s*:\s*true') { $finished = $true; break }
}

$core = (docker exec $A1 bash -lc 'test -f /var/www/html/wp-admin/index.php && test -f /var/www/html/wp-load.php && echo CORE_OK || echo CORE_MISSING').Trim()
$core | Set-Content (Join-Path $Evidence 'core-check.txt') -Encoding utf8

$metaJson = docker exec $A1 cat "/var/www/html/wp-content/uploads/museder-restoreone/jobs/${jobId}.json"
$metaJson | Set-Content (Join-Path $Evidence 'job-meta.json') -Encoding utf8
$pauseOk = $metaJson -match '"pause_other_plugins"\s*:\s*true'

@{
    post_code = $postCode
    job_id    = $jobId
    finished  = $finished
    core      = $core
    pause_ok  = $pauseOk
} | ConvertTo-Json | Set-Content (Join-Path $Evidence 'r-s2-summary.json') -Encoding utf8

if ($core -ne 'CORE_OK') { throw "CORE check failed: $core" }
if (-not $pauseOk) { throw 'pause_other_plugins not true in job meta' }
if (-not $finished) { throw 'Job did not complete' }
Write-Host '[R-S2] PASS'

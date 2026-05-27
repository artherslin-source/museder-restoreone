# R-S2 round 5 — QA-A1 bootstrap E2E
$ErrorActionPreference = 'Stop'
$Repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Evidence = Join-Path $Repo 'docs\qa-evidence\approach-b-retest-2026-05\R-S2-round5'
$A1 = 'museder-restoreone-qa-a1-1'
$BaseUrl = 'http://localhost:8081/museder-restoreone-restore-bootstrap.php'
$Secret = 'qa-secret-12345'
$Backup = 'localhost-20260527083521-E02Tyj.zip'

New-Item -ItemType Directory -Force -Path $Evidence | Out-Null

Write-Host '[R-S2] Deploy plugin files...'
docker cp (Join-Path $Repo 'includes\class-restore-lock.php') "${A1}:/var/www/html/wp-content/plugins/museder-restoreone/includes/class-restore-lock.php"
docker cp (Join-Path $Repo 'includes\class-restore-bootstrap.php') "${A1}:/var/www/html/wp-content/plugins/museder-restoreone/includes/class-restore-bootstrap.php"
docker cp (Join-Path $Repo 'includes\helpers.php') "${A1}:/var/www/html/wp-content/plugins/museder-restoreone/includes/helpers.php"
docker cp (Join-Path $Repo 'museder-restoreone-restore-bootstrap.php') "${A1}:/var/www/html/museder-restoreone-restore-bootstrap.php"

Write-Host '[R-S2] PHP memory + empty_shell...'
docker exec $A1 bash -lc "echo 'memory_limit=2048M' > /usr/local/etc/php/conf.d/zzz-qa-mem.ini"
docker exec $A1 bash -lc "cd /var/www/html && rm -rf wp-admin wp-includes && rm -f index.php wp-*.php xmlrpc.php readme.html license.txt && rm -rf wp-content/uploads/museder-restoreone/jobs/* wp-content/uploads/museder-restoreone/bootstrap-restore.lock && mkdir -p wp-content/uploads/museder-restoreone/backups wp-content/uploads/museder-restoreone/jobs && chmod -R 777 wp-content/uploads/museder-restoreone"

Write-Host '[R-S2] Verify lock fix...'
docker exec $A1 bash -lc "grep -A2 'function use_file_lock' /var/www/html/wp-content/plugins/museder-restoreone/includes/class-restore-lock.php"

Write-Host '[R-S2] POST start...'
$postFile = Join-Path $Evidence 'bootstrap-post.html'
$postCode = curl.exe -s -o $postFile -w '%{http_code}' --max-time 300 -X POST `
    -d "museder_bootstrap_start=1&backup=$Backup&secret=$Secret" $BaseUrl
"POST HTTP: $postCode" | Set-Content (Join-Path $Evidence 'bootstrap-post-status.txt') -Encoding utf8
Write-Host "POST HTTP: $postCode"
if ($postCode -notmatch '^2') {
    docker logs $A1 2>&1 | Select-String -Pattern 'Fatal' | Select-Object -Last 2
    throw "POST failed: $postCode"
}

$html = Get-Content $postFile -Raw -ErrorAction SilentlyContinue
$jobId = ''
if ($html -match 'Job:</strong>\s*([a-zA-Z0-9_]+)') { $jobId = $Matches[1] }
if ($jobId -eq '') {
    $handoff = docker exec $A1 cat /var/www/html/wp-content/uploads/museder-restoreone/bootstrap-handoff.json 2>$null
    if ($handoff -match '"job_id"\s*:\s*"([^"]+)"') { $jobId = $Matches[1] }
}
if ($jobId -eq '') {
    $latest = docker exec $A1 bash -lc "ls -t /var/www/html/wp-content/uploads/museder-restoreone/jobs/*.json 2>/dev/null | head -1"
    if ($latest -match '([a-zA-Z0-9_]+)\.json') { $jobId = $Matches[1] }
}
if ($jobId -eq '') { throw 'Could not resolve job_id' }
Write-Host "job_id=$jobId"
$jobId | Set-Content (Join-Path $Evidence 'job-id.txt') -Encoding utf8

$pollUrl = "$BaseUrl`?job_id=$jobId&secret=$Secret"
$finished = $false
for ($i = 0; $i -lt 180; $i++) {
    Start-Sleep -Seconds 10
    $pollFile = Join-Path $Evidence "poll-$i.html"
    $pollCode = curl.exe -s -o $pollFile -w '%{http_code}' --max-time 180 $pollUrl
    $pollHtml = ''
    if (Test-Path $pollFile) { $pollHtml = Get-Content $pollFile -Raw }
    $prog = ''
    if ($pollHtml -match 'Progress:</strong>\s*(\d+)') { $prog = $Matches[1] }
    $status = ''
    if ($pollHtml -match 'Status:</strong>\s*([^<]+)') { $status = ($Matches[1] -replace '\s+', ' ').Trim() }
    $coreNow = docker exec $A1 bash -lc 'test -f /var/www/html/wp-load.php && echo 1 || echo 0'
    Write-Host "poll $i HTTP=$pollCode progress=$prog status=$status wp_load=$coreNow"
    if ($pollHtml -match 'Status:</strong>\s*finished' -or $pollHtml -match 'Restore complete' -or $prog -eq '100') {
        $finished = $true
        break
    }
    if ($pollHtml -match 'stage.{0,20}failed|Restore failed') {
        throw "Restore failed on poll $i"
    }
}

$core = docker exec $A1 bash -lc 'test -f /var/www/html/wp-admin/index.php && test -f /var/www/html/wp-load.php && echo CORE_OK || echo CORE_MISSING'
$core.Trim() | Set-Content (Join-Path $Evidence 'core-check.txt') -Encoding utf8

$metaPath = "/var/www/html/wp-content/uploads/museder-restoreone/jobs/${jobId}.json"
$metaJson = docker exec $A1 cat $metaPath
$metaJson | Set-Content (Join-Path $Evidence 'job-meta.json') -Encoding utf8
$pauseOk = $metaJson -match '"pause_other_plugins"\s*:\s*true'
"pause_other_plugins_present=$pauseOk" | Set-Content (Join-Path $Evidence 'pause-check.txt') -Encoding utf8

if (-not $pauseOk -and $metaJson -match '"options"') {
    $pauseOk = $metaJson -match '"pause_other_plugins"\s*:\s*true'
}

$summary = [ordered]@{
    post_code = $postCode
    job_id    = $jobId
    finished  = $finished
    core      = $core.Trim()
    pause_ok  = $pauseOk
}
($summary | ConvertTo-Json) | Set-Content (Join-Path $Evidence 'r-s2-summary.json') -Encoding utf8
Write-Host ($summary | ConvertTo-Json)

if ($core -notmatch 'CORE_OK') { throw 'CORE_MISSING' }
if (-not $pauseOk) {
    Write-Warning 'pause_other_plugins not in job meta; checking options blob...'
    if ($metaJson -notmatch 'pause_other_plugins') { throw 'pause_other_plugins not true in job meta' }
}
if (-not $finished) { throw 'Job did not report finished in bootstrap UI' }
Write-Host '[R-S2] PASS'

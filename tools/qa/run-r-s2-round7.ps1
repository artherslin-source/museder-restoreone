# R-S2 round 7 — QA-A1 bootstrap E2E
$ErrorActionPreference = 'Stop'
$Repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Evidence = Join-Path $Repo 'docs\qa-evidence\approach-b-retest-2026-05\R-S2-round7'
$A1 = 'museder-restoreone-qa-a1-1'
$BaseUrl = 'http://localhost:8081/museder-restoreone-restore-bootstrap.php'
$Secret = 'qa-secret-12345'
$Backup = 'localhost-20260527083521-E02Tyj.zip'

New-Item -ItemType Directory -Force -Path $Evidence | Out-Null

$files = @(
    'includes\class-restore-bootstrap.php',
    'includes\class-restore-service.php',
    'includes\class-restore-preflight.php',
    'includes\class-restore-lock.php',
    'includes\helpers.php',
    'museder-restoreone-restore-bootstrap.php'
)
foreach ($f in $files) {
    $src = Join-Path $Repo $f
    $dst = if ($f -eq 'museder-restoreone-restore-bootstrap.php') {
        '/var/www/html/museder-restoreone-restore-bootstrap.php'
    } else {
        "/var/www/html/wp-content/plugins/museder-restoreone/$($f -replace '\\','/')"
    }
    docker cp $src "${A1}:$dst"
}

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

docker exec $A1 test -f /var/www/html/wp-content/uploads/museder-restoreone/bootstrap-handoff.json
if ($LASTEXITCODE -ne 0) { throw 'bootstrap-handoff.json missing' }
docker exec $A1 cat /var/www/html/wp-content/uploads/museder-restoreone/bootstrap-handoff.json | Set-Content (Join-Path $Evidence 'bootstrap-handoff.json') -Encoding utf8

$html = Get-Content $postFile -Raw
$jobId = ''
if ($html -match 'Job:</strong>\s*([a-zA-Z0-9_]+)') { $jobId = $Matches[1] }
if ($jobId -eq '' -and (Get-Content (Join-Path $Evidence 'bootstrap-handoff.json') -Raw) -match '"job_id"\s*:\s*"([^"]+)"') {
    $jobId = $Matches[1]
}
if ($jobId -eq '') {
    $latest = (docker exec $A1 bash -lc "ls -t /var/www/html/wp-content/uploads/museder-restoreone/jobs/*.json 2>/dev/null | head -1").Trim()
    if ($latest -match '([a-zA-Z0-9_]+)\.json') { $jobId = $Matches[1] }
}
if ($jobId -eq '') { throw 'job_id not found' }
$jobId | Set-Content (Join-Path $Evidence 'job-id.txt') -Encoding utf8
Write-Host "job_id=$jobId"

$pollUrl = "$BaseUrl`?job_id=$jobId&secret=$Secret"
$finished = $false
for ($i = 0; $i -lt 200; $i++) {
    Start-Sleep -Seconds 10
    $pollFile = Join-Path $Evidence "poll-$i.html"
    $pollCode = curl.exe -s -o $pollFile -w '%{http_code}' --max-time 200 $pollUrl
    $pollHtml = if (Test-Path $pollFile) { Get-Content $pollFile -Raw } else { '' }
    if ($pollHtml -match 'Invalid or missing bootstrap secret') { throw 'invalid secret on poll' }
    $metaJson = docker exec $A1 cat "/var/www/html/wp-content/uploads/museder-restoreone/jobs/${jobId}.json"
    $prog = if ($metaJson -match '"progress"\s*:\s*(\d+)') { [int]$Matches[1] } else { -1 }
    $comp = $metaJson -match '"completed"\s*:\s*true'
    $stage = if ($metaJson -match '"stage"\s*:\s*"([^"]+)"') { $Matches[1] } else { '?' }
    $wp = (docker exec $A1 bash -lc 'test -f /var/www/html/wp-load.php && echo 1 || echo 0').Trim()
    Write-Host "poll $i HTTP=$pollCode prog=$prog stage=$stage completed=$comp wp=$wp"
    if ($comp -or $prog -ge 100 -or ($pollHtml -match 'Status:</strong>\s*finished')) {
        $finished = $true
        break
    }
    if ($pollCode -notmatch '^2') {
        docker logs $A1 2>&1 | Select-String -Pattern 'Fatal' | Select-Object -Last 1
    }
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
    completed = ($metaJson -match '"completed"\s*:\s*true')
} | ConvertTo-Json | Set-Content (Join-Path $Evidence 'r-s2-summary.json') -Encoding utf8

if ($core -ne 'CORE_OK') { throw "core: $core" }
if (-not $pauseOk) { throw 'pause_other_plugins not true' }
if (-not $finished) { throw 'job not finished' }
Write-Host '[R-S2] PASS'

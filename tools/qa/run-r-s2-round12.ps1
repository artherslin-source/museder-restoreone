# R-S2 round 12 — QA-A1 (stage_import_database → search-replace for files_then_db)
$ErrorActionPreference = 'Stop'
$Repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Evidence = Join-Path $Repo 'docs\qa-evidence\approach-b-retest-2026-05\R-S2-round12'
$A1 = 'museder-restoreone-qa-a1-1'
$BaseUrl = 'http://localhost:8081/museder-restoreone-restore-bootstrap.php'
$Secret = 'qa-secret-12345'
$Backup = 'localhost-20260527083521-E02Tyj.zip'

function Get-JobMetaFromContainer {
    param([string]$JobId)
    $path = "/var/www/html/wp-content/uploads/museder-restoreone/jobs/${JobId}.json"
    $out = docker exec $A1 bash -lc "cat '$path'" 2>$null
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($out)) { return $null }
    return $out
}

New-Item -ItemType Directory -Force -Path $Evidence | Out-Null

$deploy = @(
    @{ src = 'includes\class-restore-bootstrap.php'; dst = '/var/www/html/wp-content/plugins/museder-restoreone/includes/class-restore-bootstrap.php' },
    @{ src = 'includes\class-restore-service.php'; dst = '/var/www/html/wp-content/plugins/museder-restoreone/includes/class-restore-service.php' },
    @{ src = 'includes\class-restore-preflight.php'; dst = '/var/www/html/wp-content/plugins/museder-restoreone/includes/class-restore-preflight.php' },
    @{ src = 'includes\class-restore-lock.php'; dst = '/var/www/html/wp-content/plugins/museder-restoreone/includes/class-restore-lock.php' },
    @{ src = 'includes\helpers.php'; dst = '/var/www/html/wp-content/plugins/museder-restoreone/includes/helpers.php' },
    @{ src = 'museder-restoreone-restore-bootstrap.php'; dst = '/var/www/html/museder-restoreone-restore-bootstrap.php' }
)
foreach ($f in $deploy) {
    docker cp (Join-Path $Repo $f.src) "${A1}:$($f.dst)"
}

$r12 = (docker exec $A1 bash -lc 'grep -c "search-replace" /var/www/html/wp-content/plugins/museder-restoreone/includes/class-restore-service.php | head -1').Trim()
Write-Host "class-restore-service.php deployed (grep search-replace lines: $r12)"

docker exec $A1 bash -lc "echo 'memory_limit=2048M' > /usr/local/etc/php/conf.d/zzz-qa-mem.ini"
docker exec $A1 bash -lc "cd /var/www/html && rm -rf wp-admin wp-includes && rm -f index.php wp-*.php xmlrpc.php readme.html license.txt && rm -rf wp-content/uploads/museder-restoreone/jobs/* wp-content/uploads/museder-restoreone/bootstrap-handoff.json wp-content/uploads/museder-restoreone/bootstrap-restore.lock && mkdir -p wp-content/uploads/museder-restoreone/backups wp-content/uploads/museder-restoreone/jobs && chmod -R 777 wp-content/uploads/museder-restoreone"

Write-Host '[R-S2 R12] POST start...'
$postFile = Join-Path $Evidence 'bootstrap-post.html'
$postCode = curl.exe -s -o $postFile -w '%{http_code}' --max-time 600 -X POST `
    -d "museder_bootstrap_start=1&backup=$Backup&secret=$Secret" $BaseUrl
"POST HTTP: $postCode" | Set-Content (Join-Path $Evidence 'bootstrap-post-status.txt') -Encoding utf8
Write-Host "POST HTTP: $postCode"
if ($postCode -notmatch '^2') {
    docker logs $A1 2>&1 | Select-String -Pattern 'Fatal' | Select-Object -Last 8 | Out-String | Write-Host
    throw "POST failed: $postCode"
}

docker exec $A1 test -f /var/www/html/wp-content/uploads/museder-restoreone/bootstrap-handoff.json
if ($LASTEXITCODE -ne 0) { throw 'bootstrap-handoff.json missing' }

$html = Get-Content $postFile -Raw
$jobId = ''
if ($html -match 'Job:</strong>\s*([a-zA-Z0-9_]+)') { $jobId = $Matches[1] }
if ($jobId -eq '') {
    $handoff = docker exec $A1 cat /var/www/html/wp-content/uploads/museder-restoreone/bootstrap-handoff.json
    if ($handoff -match '"job_id"\s*:\s*"([^"]+)"') { $jobId = $Matches[1] }
}
if ($jobId -eq '') { throw 'job_id not found' }
$jobId | Set-Content (Join-Path $Evidence 'job-id.txt') -Encoding utf8
Write-Host "job_id=$jobId"

$pollUrl = "$BaseUrl`?job_id=$jobId&secret=$Secret"
$pollHeadersFile = Join-Path $Evidence 'poll-0-headers.txt'
curl.exe -s -D $pollHeadersFile -o (Join-Path $Evidence 'poll-0-body.html') --max-time 300 $pollUrl | Out-Null
$hdrRaw = Get-Content $pollHeadersFile -Raw -ErrorAction SilentlyContinue
$hasBootstrapHdr = $hdrRaw -match 'X-Museder-Restoreone-Bootstrap:\s*1'

$finished = $false
$lastStage = ''
for ($i = 0; $i -lt 180; $i++) {
    Start-Sleep -Seconds 12
    $pollCode = curl.exe -s -L -o (Join-Path $Evidence "poll-$i.html") -w '%{http_code}' --max-time 600 $pollUrl
    $metaJson = Get-JobMetaFromContainer -JobId $jobId
    if (-not $metaJson) {
        Write-Host "poll $i HTTP=$pollCode no meta"
        continue
    }
    $prog = 0
    if ($metaJson -match '"progress"\s*:\s*(\d+)') { $prog = [int]$Matches[1] }
    $comp = $metaJson -match '(?m)^\s*"completed"\s*:\s*true\s*,?\s*$'
    $stage = 'unknown'
    if ($metaJson -match '"stage"\s*:\s*"([^"]+)"') { $stage = $Matches[1] }
    if ($stage -ne $lastStage) { Write-Host "poll $i stage -> $stage" ; $lastStage = $stage }
    Write-Host "poll $i HTTP=$pollCode prog=$prog stage=$stage completed=$comp"
    if ($comp -or $prog -ge 100) {
        $finished = $true
        break
    }
}

$metaJson = Get-JobMetaFromContainer -JobId $jobId
$metaJson | Set-Content (Join-Path $Evidence 'job-meta.json') -Encoding utf8
$core = (docker exec $A1 bash -lc 'test -f /var/www/html/wp-admin/index.php && test -f /var/www/html/wp-load.php && echo CORE_OK || echo CORE_MISSING').Trim()
$pauseOk = $metaJson -match '(?m)"pause_other_plugins"\s*:\s*true'
$completed = $metaJson -match '(?m)^\s*"completed"\s*:\s*true\s*,?\s*$'

@{
    post_code     = $postCode
    job_id        = $jobId
    finished      = $finished
    completed     = $completed
    core          = $core
    pause_ok      = $pauseOk
    bootstrap_hdr = $hasBootstrapHdr
} | ConvertTo-Json | Set-Content (Join-Path $Evidence 'r-s2-summary.json') -Encoding utf8

# Docker may write benign messages to stderr; do not fail the script after PASS checks.
$prevEap = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
try {
    docker logs $A1 2>&1 | Select-String -Pattern 'Fatal' | Select-Object -Last 5 | Out-String | Set-Content (Join-Path $Evidence 'apache-fatals-tail.txt') -Encoding utf8 -ErrorAction SilentlyContinue
} finally {
    $ErrorActionPreference = $prevEap
}

if ($postCode -notmatch '^2') { throw 'POST not 2xx' }
if (-not $hasBootstrapHdr) { throw 'poll-0 missing X-Museder-Restoreone-Bootstrap: 1' }
if (-not $pauseOk) { throw 'pause_other_plugins not true' }
if (-not $completed) { throw 'job completed not true' }
if ($core -ne 'CORE_OK') { throw "core: $core" }
Write-Host '[R-S2 R12] PASS'

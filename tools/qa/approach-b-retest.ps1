# Approach B regression (ae87d28 / 2.7.268) — QA runner for Windows + Docker.
# Usage: powershell -File tools/qa/approach-b-retest.ps1

$ErrorActionPreference = 'Stop'
$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$ComposeFile = Join-Path $RepoRoot 'docker-compose.qa.yml'
$EvidenceRoot = Join-Path $RepoRoot 'docs\qa-evidence\approach-b-retest-2026-05'

function Invoke-QaCompose {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Args)
    & docker compose -f $ComposeFile @Args
    if ($LASTEXITCODE -ne 0) { throw "docker compose failed: $Args" }
}

function Sync-PluginToContainer {
    param([string]$Container)
    $syncCmd = 'set -e; src=/tmp/museder-restoreone-src; dst=/var/www/html/wp-content/plugins/museder-restoreone; rm -rf "$dst"; mkdir -p "$dst"; tar -C "$src" --exclude=./logs --exclude=./docs --exclude=./dist --exclude=./release --exclude=./.git --exclude=./.cursor --exclude=./museder-restoreone-pro -cf - . | tar -C "$dst" -xf -'
    Invoke-QaCompose exec -T $Container bash -lc $syncCmd
}

function Invoke-QaWp {
    param(
        [string]$Volume,
        [string]$DbHost,
        [string]$DbName,
        [string[]]$WpArgs
    )
    & docker run --rm `
        --network museder-restoreone_default `
        -v "${Volume}:/var/www/html" `
        -v "${RepoRoot}:/tmp/museder-restoreone-src:ro" `
        -v "${RepoRoot}/tools/docker/php.ini:/usr/local/etc/php/conf.d/zzz-museder-restoreone.ini:ro" `
        -e WORDPRESS_DB_HOST=$DbHost `
        -e WORDPRESS_DB_USER=wordpress `
        -e WORDPRESS_DB_PASSWORD=wordpress `
        -e WORDPRESS_DB_NAME=$DbName `
        --entrypoint wp `
        wordpress:cli-php8.2 @WpArgs
    if ($LASTEXITCODE -ne 0) { throw "wp failed: $($WpArgs -join ' ')" }
}

function Wait-WpInstalled {
    param([string]$Volume, [string]$DbHost, [string]$DbName, [int]$Max = 30)
    for ($i = 0; $i -lt $Max; $i++) {
        try {
            Invoke-QaWp -Volume $Volume -DbHost $DbHost -DbName $DbName -WpArgs @('core', 'is-installed')
            return
        } catch {
            Start-Sleep -Seconds 3
        }
    }
    throw 'WordPress not installed in time'
}

function Ensure-Dir($Path) {
    if (-not (Test-Path $Path)) { New-Item -ItemType Directory -Path $Path -Force | Out-Null }
}

Ensure-Dir $EvidenceRoot

Write-Host '[retest] Sync plugin to all QA wordpress containers...'
@('qa-a1', 'qa-a2', 'qa-b1', 'qa-c1', 'qa-c2') | ForEach-Object { Sync-PluginToContainer $_ }

# --- QA-B1: populated_wp source + FULL-S backup ---
Write-Host '[retest] Provision QA-B1 (populated_wp)...'
$b1Vol = 'museder-restoreone_qa_b1_wp'
Wait-WpInstalled -Volume $b1Vol -DbHost 'qa-b1-db:3306' -DbName 'wordpress_b1'
try {
    Invoke-QaWp -Volume $b1Vol -DbHost 'qa-b1-db:3306' -DbName 'wordpress_b1' -WpArgs @(
        'core', 'is-installed'
    )
} catch {
    Invoke-QaWp -Volume $b1Vol -DbHost 'qa-b1-db:3306' -DbName 'wordpress_b1' -WpArgs @(
        'core', 'install', '--url=http://localhost:8083', '--title=QA B1',
        '--admin_user=admin', '--admin_password=admin', '--admin_email=admin@example.com', '--skip-email'
    )
}
Invoke-QaWp -Volume $b1Vol -DbHost 'qa-b1-db:3306' -DbName 'wordpress_b1' -WpArgs @('plugin', 'activate', 'museder-restoreone')
Invoke-QaWp -Volume $b1Vol -DbHost 'qa-b1-db:3306' -DbName 'wordpress_b1' -WpArgs @('plugin', 'install', 'akismet', '--activate')
Invoke-QaWp -Volume $b1Vol -DbHost 'qa-b1-db:3306' -DbName 'wordpress_b1' -WpArgs @(
    'post', 'create', '--post_type=post', '--post_status=publish', '--post_title=QA populated', '--porcelain'
)

Write-Host '[retest] Create FULL-S backup on B1 via eval-file...'
$evalBackup = Join-Path $RepoRoot 'tools\qa\eval-create-full-backup.php'
Invoke-QaWp -Volume $b1Vol -DbHost 'qa-b1-db:3306' -DbName 'wordpress_b1' -WpArgs @('eval-file', "/tmp/museder-restoreone-src/tools/qa/eval-create-full-backup.php")

$backupName = (Invoke-QaWp -Volume $b1Vol -DbHost 'qa-b1-db:3306' -DbName 'wordpress_b1' -WpArgs @(
    'eval', 'echo basename((string) get_option("museder_restoreone_qa_last_backup",""));'
)) | Select-Object -Last 1
Write-Host "[retest] Backup file: $backupName"

# --- R-S13 preflight on B1 ---
Write-Host '[retest] R-S13 preflight warning...'
$r13 = Invoke-QaWp -Volume $b1Vol -DbHost 'qa-b1-db:3306' -DbName 'wordpress_b1' -WpArgs @(
    'eval-file', '/tmp/museder-restoreone-src/tools/qa/eval-preflight-s13.php'
)
Ensure-Dir (Join-Path $EvidenceRoot 'R-S13')
$r13 | Out-File (Join-Path $EvidenceRoot 'R-S13\preflight-output.json') -Encoding utf8
Write-Host $r13

# --- QA-A1: empty_shell + bootstrap R-S2 ---
Write-Host '[retest] Prepare QA-A1 empty_shell...'
$a1Vol = 'museder-restoreone_qa_a1_wp'
$a1Prep = 'set -e; cd /var/www/html; rm -rf wp-admin wp-includes; rm -f index.php wp-*.php xmlrpc.php readme.html license.txt; mkdir -p wp-content/uploads/museder-restoreone/backups wp-content/plugins/museder-restoreone; cp /tmp/museder-restoreone-src/museder-restoreone-restore-bootstrap.php ./museder-restoreone-restore-bootstrap.php'
Invoke-QaCompose exec -T qa-a1 bash -lc $a1Prep

if ($backupName) {
    Invoke-QaCompose exec -T qa-b1 bash -lc "ls -la /var/www/html/wp-content/uploads/museder-restoreone/backups/$backupName"
    # Copy from b1 container to a1 via docker cp
    & docker cp "museder-restoreone-qa-b1-1:/var/www/html/wp-content/uploads/museder-restoreone/backups/$backupName" "$env:TEMP\qa-full-s.zip"
    & docker cp "$env:TEMP\qa-full-s.zip" "museder-restoreone-qa-a1-1:/var/www/html/wp-content/uploads/museder-restoreone/backups/$backupName"
}

Write-Host '[retest] R-S2 bootstrap GET...'
$get = Invoke-WebRequest -Uri 'http://localhost:8081/museder-restoreone-restore-bootstrap.php' -UseBasicParsing
Ensure-Dir (Join-Path $EvidenceRoot 'R-S2')
$get.StatusCode | Out-File (Join-Path $EvidenceRoot 'R-S2\bootstrap-get-status.txt')
if ($get.StatusCode -ne 200) { throw 'bootstrap GET not 200' }

Write-Host '[retest] R-S2 bootstrap POST (start restore)...'
# Parse backup list from form — use known backup name
$secret = 'qa-secret-12345'
$postBody = @{
    museder_bootstrap_start = '1'
    backup                  = $backupName
    secret                  = $secret
}
$post = Invoke-WebRequest -Uri 'http://localhost:8081/museder-restoreone-restore-bootstrap.php' -Method POST -Body $postBody -UseBasicParsing
$post.Content | Out-File (Join-Path $EvidenceRoot 'R-S2\bootstrap-post.html') -Encoding utf8

Write-Host '[retest] Poll restore via wp eval on A1 volume (after handoff)...'
Start-Sleep -Seconds 5
$pollScript = Join-Path $RepoRoot 'tools\qa\eval-poll-restore-job.php'
# A1 may not have full WP until restore progresses — poll via docker exec on a1 after files appear

$jobId = ''
if ($post.Content -match 'Job:</strong>\s*([a-zA-Z0-9_\-]+)') {
    $jobId = $Matches[1]
}
for ($i = 0; $i -lt 90; $i++) {
    Start-Sleep -Seconds 15
    $pollUrl = if ($jobId) {
        "http://localhost:8081/museder-restoreone-restore-bootstrap.php?job_id=$jobId&secret=$secret"
    } else {
        'http://localhost:8081/museder-restoreone-restore-bootstrap.php'
    }
    try {
        $pollPage = Invoke-WebRequest -Uri $pollUrl -UseBasicParsing
        $pollPage.Content | Out-File (Join-Path $EvidenceRoot "R-S2\bootstrap-poll-$i.html") -Encoding utf8
        if ($pollPage.Content -match 'Status:</strong>\s*finished') {
            Write-Host '[retest] bootstrap reports finished'
            break
        }
        if ($pollPage.Content -match 'Progress:</strong>\s*(\d+)') {
            Write-Host "[retest] progress $($Matches[1])%"
        }
    } catch {
        Write-Host "[retest] bootstrap poll error: $_"
    }
    try {
        $poll = Invoke-QaWp -Volume $a1Vol -DbHost 'qa-a1-db:3306' -DbName 'wordpress_a1' -WpArgs @(
            'eval-file', '/tmp/museder-restoreone-src/tools/qa/eval-poll-restore-job.php'
        )
        Write-Host $poll
        if ($poll -match '"completed"\s*:\s*true') { break }
    } catch {
        Write-Host "[retest] wp poll ($i): not ready"
    }
}

Invoke-QaCompose exec -T qa-a1 bash -lc 'test -f /var/www/html/wp-admin/index.php && test -f /var/www/html/wp-load.php && echo CORE_OK || echo CORE_MISSING' | Out-File (Join-Path $EvidenceRoot 'R-S2\core-check.txt')

Write-Host '[retest] Done. Evidence under docs/qa-evidence/approach-b-retest-2026-05/'

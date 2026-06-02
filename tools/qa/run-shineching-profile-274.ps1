# shineching.com profile QA for 2.7.275 (Docker only — never touches production host).
#
# Usage:
#   powershell -File tools/qa/run-shineching-profile-274.ps1
#   powershell -File tools/qa/run-shineching-profile-274.ps1 -SkipDockerReset
#
# Evidence: docs/qa-evidence/shineching-profile-2.7.275/

param(
    [switch] $SkipDockerReset,
    [switch] $SimulateCpanel
)

$ErrorActionPreference = 'Stop'
function Invoke-DockerQuiet {
    param([string[]]$Args)
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    & docker @Args 2>&1 | Out-Null
    $code = $LASTEXITCODE
    $ErrorActionPreference = $prev
    if ($code -ne 0) { throw "docker failed: docker $($Args -join ' ') exit=$code" }
}
$RepoRoot   = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Evidence   = Join-Path $RepoRoot 'docs\qa-evidence\shineching-profile-2.7.275'
$ComposeQa  = Join-Path $RepoRoot 'docker-compose.qa.yml'
$SeedZipSrc = Join-Path $RepoRoot 'dist\shineching.com-20260531013823-V6yYBa (1).zip'
$SeedName   = 'shineching.com-20260531013823-V6yYBa-1.zip'
$C1Vol      = 'museder-restoreone_qa_c1_wp'
$C1DbVol    = 'museder-restoreone_qa_c1_db'
$Network    = 'museder-restoreone_default'
$BaseUrl    = 'http://localhost:8084'
$results    = [ordered]@{}
$failures   = @()

function Write-Log($msg) {
    $line = "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $msg"
    Write-Host $line
    Add-Content -Path (Join-Path $Evidence 'run.log') -Value $line -Encoding utf8
}

function Record($id, $pass, $detail = '') {
    $results[$id] = @{ pass = $pass; detail = $detail }
    if ($pass) { Write-Log "PASS $id $detail" }
    else { Write-Log "FAIL $id $detail"; $script:failures += "$id $detail" }
}

function Invoke-QaC1Wp {
    param([string[]]$WpArgs)
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $out = docker run --rm --user root `
        --network $Network `
        -v "${C1Vol}:/var/www/html" `
        -v "${RepoRoot}:/tmp/museder-restoreone-src:ro" `
        -v "${RepoRoot}/tools/docker/php.ini:/usr/local/etc/php/conf.d/zzz-museder-restoreone.ini:ro" `
        -e WORDPRESS_DB_HOST=qa-c1-db:3306 `
        -e WORDPRESS_DB_USER=wordpress `
        -e WORDPRESS_DB_PASSWORD=wordpress `
        -e WORDPRESS_DB_NAME=wordpress_c1 `
        --entrypoint wp `
        wordpress:cli-php8.2 --allow-root @WpArgs 2>&1
    $code = $LASTEXITCODE
    $ErrorActionPreference = $prev
    $global:LASTEXITCODE = $code
    return $out
}

New-Item -ItemType Directory -Force -Path $Evidence | Out-Null
Write-Log '========== shineching profile QA 2.7.275 (Docker qa-c1) =========='

if (-not (Test-Path -LiteralPath $SeedZipSrc)) {
    Record 'seed_zip' $false "missing $SeedZipSrc"
    exit 1
}
Record 'seed_zip' $true ("bytes=" + (Get-Item -LiteralPath $SeedZipSrc).Length)

Write-Log '--- L0 static ---'
$lint = & php -l (Join-Path $RepoRoot 'includes\class-restore-service.php') 2>&1 | Out-String
Record 'L0_php_lint_restore_service' ($lint -match 'No syntax errors') $lint.Trim()

$atomic = & php (Join-Path $RepoRoot 'tools\qa\verify-restore-slice-atomic-write.php') 2>&1 | Out-String
$atomic | Set-Content (Join-Path $Evidence 'l0-atomic-write.txt') -Encoding utf8
Record 'L0_atomic_write_unit' ($atomic -match 'RESTORE_SLICE_ATOMIC_QA=PASS') $atomic.Trim()

$wpConfigPolicy = & php (Join-Path $RepoRoot 'tools\qa\verify-wp-config-restore-policy.php') 2>&1 | Out-String
$wpConfigPolicy | Set-Content (Join-Path $Evidence 'l0-wp-config-policy.txt') -Encoding utf8
Record 'L0_wp_config_policy' ($wpConfigPolicy -match 'WP_CONFIG_RESTORE_POLICY_QA=PASS') $wpConfigPolicy.Trim()

$step3Status = & php (Join-Path $RepoRoot 'tools\qa\verify-step3-job-status.php') 2>&1 | Out-String
$step3Status | Set-Content (Join-Path $Evidence 'l0-step3-job-status.txt') -Encoding utf8
Record 'L0_step3_job_status' ($step3Status -match 'STEP3_JOB_STATUS_QA=PASS') $step3Status.Trim()

$mediaPaths = & php (Join-Path $RepoRoot 'tools\qa\verify-media-paths-reconcile.php') 2>&1 | Out-String
$mediaPaths | Set-Content (Join-Path $Evidence 'l0-media-paths-reconcile.txt') -Encoding utf8
Record 'L0_media_paths_reconcile' ($mediaPaths -match 'MEDIA_PATHS_RECONCILE_QA=PASS') $mediaPaths.Trim()

$runtimeOpts = & php (Join-Path $RepoRoot 'tools\qa\verify-restore-runtime-options.php') 2>&1 | Out-String
$runtimeOpts | Set-Content (Join-Path $Evidence 'l0-restore-runtime-options.txt') -Encoding utf8
Record 'L0_restore_runtime_options' ($runtimeOpts -match 'restore runtime option filter: PASS') $runtimeOpts.Trim()

$adminJsCheck = & node --check (Join-Path $RepoRoot 'assets\js\admin.js') 2>&1 | Out-String
Record 'L0_admin_js_syntax' ($LASTEXITCODE -eq 0) $adminJsCheck.Trim()

$uiConvergence = & php (Join-Path $RepoRoot 'tools\qa\verify-restore-ui-convergence-guards.php') 2>&1 | Out-String
$uiConvergence | Set-Content (Join-Path $Evidence 'l0-restore-ui-convergence-guards.txt') -Encoding utf8
Record 'L0_restore_ui_convergence_guards' ($uiConvergence -match 'PASS\s+restore_ui_convergence_guards') $uiConvergence.Trim()

$tokenStability = & php (Join-Path $RepoRoot 'tools\qa\verify-restore-token-stability-guards.php') 2>&1 | Out-String
$tokenStability | Set-Content (Join-Path $Evidence 'l0-restore-token-stability-guards.txt') -Encoding utf8
Record 'L0_restore_token_stability_guards' ($tokenStability -match 'PASS\s+restore_token_stability_guards') $tokenStability.Trim()

$rerunContinuity = & php (Join-Path $RepoRoot 'tools\qa\verify-restore-rerun-continuity-guards.php') 2>&1 | Out-String
$rerunContinuity | Set-Content (Join-Path $Evidence 'l0-restore-rerun-continuity-guards.txt') -Encoding utf8
Record 'L0_restore_rerun_continuity_guards' ($rerunContinuity -match 'PASS\s+restore_rerun_continuity_guards') $rerunContinuity.Trim()

$preflightLint = & php -l (Join-Path $RepoRoot 'includes\class-restore-preflight.php') 2>&1 | Out-String
Record 'L0_php_lint_preflight' ($preflightLint -match 'No syntax errors') $preflightLint.Trim()

$verLine = (Select-String -Path (Join-Path $RepoRoot 'museder-restoreone.php') -Pattern '^Version:' | Select-Object -First 1).Line
Record 'L0_version_275' ($verLine -match '2\.7\.275') $verLine.Trim()

Write-Log '--- Docker prep (qa-c1 clean WP) ---'
try { $null = & docker version 2>&1 } catch { Record 'docker' $false 'Docker not running'; exit 1 }
Record 'docker' $true ''

if (-not $SkipDockerReset) {
    Write-Log 'Recreating qa-c1 volumes...'
    Invoke-DockerQuiet @('compose','-f',$ComposeQa,'stop','qa-c1','qa-c1-db')
    Invoke-DockerQuiet @('compose','-f',$ComposeQa,'rm','-f','qa-c1','qa-c1-db')
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    & docker volume rm $C1Vol $C1DbVol 2>&1 | Out-Null
    $ErrorActionPreference = $prev
    Invoke-DockerQuiet @('compose','-f',$ComposeQa,'up','-d','qa-c1-db','qa-c1')
    Write-Log 'Waiting for MariaDB (fresh volumes)...'
    $ready = $false
    for ($i = 1; $i -le 40; $i++) {
        Start-Sleep -Seconds 3
        $prev = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'
        & docker compose -f $ComposeQa exec -T qa-c1-db mariadb-admin ping -h localhost -uwordpress -pwordpress --silent 2>&1 | Out-Null
        $dbOk = ($LASTEXITCODE -eq 0)
        $ErrorActionPreference = $prev
        if ($dbOk) { $ready = $true; Write-Log "DB ready after ${i} attempts"; break }
    }
    if (-not $ready) { Record 'db_ready' $false 'qa-c1-db not ready'; exit 1 }
    Record 'db_ready' $true ''
    Start-Sleep -Seconds 15
    $wpCfgCheck = docker compose -f $ComposeQa exec -T qa-c1 bash -lc "grep DB_NAME /var/www/html/wp-config.php 2>/dev/null" 2>&1 | Out-String
    if ($wpCfgCheck -match 'i10269493_ipic1') {
        Record 'volume_clean' $false 'stale shineching wp-config on qa-c1 volume'
        exit 1
    }
    Record 'volume_clean' $true ($wpCfgCheck.Trim())
}

$syncCmd = 'src=/tmp/museder-restoreone-src; dst=/var/www/html/wp-content/plugins/museder-restoreone; rm -rf "$dst"; mkdir -p "$dst"; tar -C "$src" --exclude=./logs --exclude=./docs --exclude=./dist --exclude=./release --exclude=./.git --exclude=./.cursor --exclude=./museder-restoreone-pro -cf - . | tar -C "$dst" -xf -'
$prevEa = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
& docker compose -f $ComposeQa exec -T qa-c1 bash -lc $syncCmd 2>&1 | Out-Null
$syncCode = $LASTEXITCODE
$ErrorActionPreference = $prevEa
if ($syncCode -ne 0) { Record 'plugin_sync' $false "docker exec qa-c1 failed exit=$syncCode"; exit 1 }
Record 'plugin_sync' $true ''

Invoke-QaC1Wp @('core','is-installed') 2>$null | Out-Null
if ($LASTEXITCODE -ne 0) {
    Invoke-QaC1Wp @(
        'core','install',
        "--url=$BaseUrl",
        '--title=SC275 QA Clean',
        '--admin_user=admin',
        '--admin_password=admin',
        '--admin_email=admin@example.com',
        '--skip-email'
    ) | Out-Null
    Record 'wp_install' $true 'fresh core install'
} else {
    Record 'wp_install' $true 'core already installed'
}

Invoke-QaC1Wp @('plugin','activate','museder-restoreone') 2>&1 | Out-String | ForEach-Object { Write-Log $_ }
if ($LASTEXITCODE -ne 0) { Record 'plugin_activate' $false 'museder-restoreone exit=' + $LASTEXITCODE; exit 1 }
Record 'plugin_activate' $true 'museder-restoreone'

if ($SimulateCpanel) {
    Write-Log 'Materialize wp-config DB literals (simulate cPanel / GoDaddy host)...'
    $matOut = Invoke-QaC1Wp @(
        'eval-file',
        '/tmp/museder-restoreone-src/tools/qa/materialize-wp-config-db-literals.php'
    ) 2>&1 | Out-String
    $matOut | Set-Content (Join-Path $Evidence 'materialize-wp-config.txt') -Encoding utf8
    Record 'wp_config_mode' ($matOut -match 'PASS\tmaterialize_wp_config_literals') ($matOut.Trim())
    if ($LASTEXITCODE -ne 0) { exit 1 }
} else {
    Write-Log 'Using native Docker getenv_docker wp-config (P2 merge regression path).'
    Record 'wp_config_mode' $true 'docker_getenv_native'
}

Write-Log 'Copying seed archive into backups dir (564MB)...'
$seedSrcInContainer = '/tmp/museder-restoreone-src/dist/shineching.com-20260531013823-V6yYBa (1).zip'
$copyCmd = "bk=/var/www/html/wp-content/uploads/museder-restoreone/backups; mkdir -p `"`$bk`"; cp '$seedSrcInContainer' `"`$bk/$SeedName`"; ls -lh `"`$bk/$SeedName`""
$prevEa = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
$copyOut = & docker compose -f $ComposeQa exec -T qa-c1 bash -lc $copyCmd 2>&1 | Out-String
$copyCode = $LASTEXITCODE
$ErrorActionPreference = $prevEa
$copyOut | Set-Content (Join-Path $Evidence 'seed-copy.txt') -Encoding utf8
Record 'seed_copy' ($copyCode -eq 0) $SeedName

Write-Log '--- L1 E2E: seed restore + backup + round2 restore ---'
$e2eOut = Invoke-QaC1Wp @(
    'eval-file',
    '/tmp/museder-restoreone-src/tools/qa/shineching-profile-e2e.php',
    $SeedName
) 2>&1 | Out-String
$e2eOut | Set-Content (Join-Path $Evidence 'shineching-e2e-output.txt') -Encoding utf8
Write-Log ($e2eOut -split "`n" | Select-Object -Last 8 | Out-String)
$e2eExitOk     = ($LASTEXITCODE -eq 0)
$e2eHasPass    = ($e2eOut -match 'SHINECHING_PROFILE_E2E=PASS')
$e2eHasFailTag = ($e2eOut -match '(?m)^FAIL\t')
$e2eStatusOk   = $e2eExitOk -and $e2eHasPass -and (-not $e2eHasFailTag)
$e2eDetail = @(
    "exit=$LASTEXITCODE"
    "passTag=$e2eHasPass"
    "failTag=$e2eHasFailTag"
    (($e2eOut -split "`n" | Select-Object -Last 3 | Out-String).Trim())
) -join ' | '
Record 'L1_shineching_e2e' $e2eStatusOk $e2eDetail

Write-Log '--- HTTP smoke after E2E (in-container; avoids https siteurl redirect) ---'
$httpCmd = 'code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 30 http://127.0.0.1/); echo HOME=$code; admin=$(curl -s -o /dev/null -w "%{http_code}" --max-time 30 http://127.0.0.1/wp-admin/); echo ADMIN=$admin'
$prevEa = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
$httpSmoke = docker compose -f $ComposeQa exec -T qa-c1 bash -lc $httpCmd 2>&1 | Out-String
$ErrorActionPreference = $prevEa
Write-Log $httpSmoke.Trim()
$homeOk = ($httpSmoke -match 'HOME=(200|301|302)')
$adminOk = ($httpSmoke -match 'ADMIN=(200|301|302)')
Record 'L1_home_http' $homeOk ($httpSmoke -replace "`r`n", ' ').Trim()
Record 'L1_admin_http' $adminOk ($httpSmoke -replace "`r`n", ' ').Trim()

$summaryPath = Join-Path $Evidence 'SUMMARY.json'
$results | ConvertTo-Json -Depth 4 | Set-Content $summaryPath -Encoding utf8

Write-Log "Evidence: $Evidence"
if ($failures.Count -gt 0) {
    Write-Log "OVERALL=FAIL count=$($failures.Count)"
    $failures | ForEach-Object { Write-Log "  $_" }
    exit 1
}

Write-Log 'OVERALL=PASS'
exit 0

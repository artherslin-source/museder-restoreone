# Fresh-site large restore E2E (Windows-friendly). Requires Docker Desktop running.
# Usage: .\tools\docker\run-e2e-only.ps1
# Full reset: .\tools\docker\run-fresh-restore-e2e.ps1

$ErrorActionPreference = "Continue"
Set-Location (Join-Path $PSScriptRoot "..\..")

function Assert-DockerExit {
    param([int]$Code, [string]$Step)
    if ($Code -ne 0) {
        Write-Host "[FAIL] $Step (exit $Code)"
        exit $Code
    }
}

$ZipName = if ($env:MUSEDER_RESTORE_E2E_ZIP) { $env:MUSEDER_RESTORE_E2E_ZIP } else { "sunpoweroflight.com-20260311014315-ptq9eY.zip" }
$HostZip = Join-Path "logs\150525-debug" $ZipName
if (-not (Test-Path $HostZip)) {
    Write-Host "[FAIL] Missing backup: $HostZip"
    exit 2
}

docker compose exec wordpress test -f /var/www/html/wp-config.php 2>&1 | Out-Null
Assert-DockerExit $LASTEXITCODE "wordpress-ready"

docker compose run --rm wpcli core is-installed 2>&1 | Out-Null
if ($LASTEXITCODE -ne 0) {
    Write-Host "[wp] Installing WordPress core..."
    docker compose run --rm wpcli core install `
        --url="http://localhost:8080" `
        --title="RestoreOne Fresh Repro" `
        --admin_user="admin" `
        --admin_password="admin" `
        --admin_email="admin@example.com" `
        --skip-email 2>&1 | Out-Host
    Assert-DockerExit $LASTEXITCODE "core-install"
}

Write-Host "[wp] Syncing RestoreOne $(Select-String -Path museder-restoreone.php -Pattern 'Version:' | ForEach-Object { $_.Line.Trim() })..."
docker compose exec wordpress bash /tmp/museder-restoreone-src/tools/docker/container-sync-plugin.sh 2>&1 | Out-Host
Assert-DockerExit $LASTEXITCODE "plugin-sync"

docker compose run --rm wpcli plugin activate museder-restoreone 2>&1 | Out-Host
Assert-DockerExit $LASTEXITCODE "plugin-activate"

Write-Host "[wp] Copying backup ($ZipName)..."
$env:MUSEDER_RESTORE_E2E_ZIP = $ZipName
docker compose run --rm --entrypoint bash -e MUSEDER_RESTORE_E2E_ZIP=$ZipName wpcli /tmp/museder-restoreone-src/tools/docker/container-copy-backup.sh 2>&1 | Out-Host
Assert-DockerExit $LASTEXITCODE "backup-copy"

Write-Host "[e2e] Running headless restore (30-90+ min for ~737MB)..."
$env:MUSEDER_RESTORE_E2E_ZIP = $ZipName
$env:MUSEDER_RESTORE_E2E_SLICE_SECONDS = "30"
$env:MUSEDER_RESTORE_E2E_MAX_SLICES = "8000"
docker compose run --rm `
  -e MUSEDER_RESTORE_E2E_ZIP=$ZipName `
  -e MUSEDER_RESTORE_E2E_SLICE_SECONDS=30 `
  -e MUSEDER_RESTORE_E2E_MAX_SLICES=8000 `
  wpcli eval-file /tmp/museder-restoreone-src/tools/qa/restore-large-e2e.php 2>&1 | Tee-Object -FilePath "logs\150525-debug\e2e-restore-slices.log"
Assert-DockerExit $LASTEXITCODE "restore-e2e"

Write-Host "[done] E2E PASS"

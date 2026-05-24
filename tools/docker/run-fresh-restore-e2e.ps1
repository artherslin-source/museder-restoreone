# Reset Docker volumes, fresh WP, run large restore E2E (Windows).
$ErrorActionPreference = "Continue"
Set-Location (Join-Path $PSScriptRoot "..\..")

$ZipName = if ($env:MUSEDER_RESTORE_E2E_ZIP) { $env:MUSEDER_RESTORE_E2E_ZIP } else { "sunpoweroflight.com-20260311014315-ptq9eY.zip" }
if (-not (Test-Path (Join-Path "logs\150525-debug" $ZipName))) {
    Write-Error "Missing logs\150525-debug\$ZipName"
}

Write-Host "[docker] Resetting volumes..."
docker compose down -v
docker compose up -d db wordpress

Write-Host "[docker] Waiting for MariaDB..."
for ($i = 0; $i -lt 90; $i++) {
    docker compose exec db mariadb-admin ping -uroot -proot --silent 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep -Seconds 2
}

Write-Host "[docker] Waiting for wp-config.php..."
for ($i = 0; $i -lt 90; $i++) {
    docker compose exec wordpress test -f /var/www/html/wp-config.php 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep -Seconds 2
}

Write-Host "[docker] Waiting for WP-CLI..."
for ($i = 0; $i -lt 90; $i++) {
    docker compose run --rm wpcli core version 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep -Seconds 2
}

& (Join-Path $PSScriptRoot "run-e2e-only.ps1")

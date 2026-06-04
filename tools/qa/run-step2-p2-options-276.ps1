# Sync 2.7.276 to local Docker and run Step 2 P2 options matrix.
$ErrorActionPreference = 'Stop'
$Repo = 'C:\Users\fdmur\Documents\GitHub\museder-restoreone'
Set-Location $Repo

Write-Host '== docker compose up =='
$PreviousErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
docker compose up -d 2>$null
$ComposeExitCode = $LASTEXITCODE
$ErrorActionPreference = $PreviousErrorActionPreference
if ($ComposeExitCode -ne 0) { throw 'docker compose up failed' }

$WpId = (docker compose ps -q wordpress).Trim()
if (-not $WpId) { throw 'wordpress container not found' }

Write-Host "== sync plugin to $WpId =="
$syncCmd = 'set -e; src=/tmp/museder-restoreone-src; dst=/var/www/html/wp-content/plugins/museder-restoreone; rm -rf "$dst"; mkdir -p "$dst"; tar -C "$src" --exclude=./logs --exclude=./docs --exclude=./dist --exclude=./release --exclude=./.git --exclude=./.cursor --exclude=./museder-restoreone-pro -cf - . | tar -C "$dst" -xf -'
docker exec $WpId bash -lc $syncCmd

Write-Host '== activate plugin =='
$PreviousErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
docker compose run --rm wpcli plugin is-active museder-restoreone 2>$null
$IsActiveExitCode = $LASTEXITCODE
$ErrorActionPreference = $PreviousErrorActionPreference
if ($IsActiveExitCode -ne 0) {
  $PreviousErrorActionPreference = $ErrorActionPreference
  $ErrorActionPreference = 'Continue'
  docker compose run --rm wpcli plugin activate museder-restoreone 2>$null
  $ActivateExitCode = $LASTEXITCODE
  $ErrorActionPreference = $PreviousErrorActionPreference
  if ($ActivateExitCode -ne 0) { throw 'plugin activate failed' }
}

Write-Host '== P2 options matrix =='
docker exec $WpId php /tmp/museder-restoreone-src/tools/qa/verify-step2-p2-options-matrix-2.7.276.php
exit $LASTEXITCODE

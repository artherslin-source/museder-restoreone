# Sync local plugin source into the Docker WordPress container.
# Run from repo root: .\tools\docker\sync-plugin.ps1

$ErrorActionPreference = 'Stop'
$repoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')

Push-Location $repoRoot
try {
    docker compose exec -T wordpress bash -lc @'
set -e
src=/tmp/museder-restoreone-src
dst=/var/www/html/wp-content/plugins/museder-restoreone
rm -rf "$dst"
mkdir -p "$dst"
tar -C "$src" \
  --exclude=./logs \
  --exclude=./docs \
  --exclude=./dist \
  --exclude=./release \
  --exclude=./.git \
  --exclude=./.cursor \
  --exclude=./museder-restoreone-pro \
  --exclude=./archive \
  -cf - . | tar -C "$dst" -xf -
echo "Synced plugin to $dst"
'@
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose exec failed with exit code $LASTEXITCODE"
    }
    Write-Host 'Plugin sync complete. Hard-refresh the Schedules page (Ctrl+F5).'
}
finally {
    Pop-Location
}

# Provision Approach B QA Docker environments (Windows).
# Requires: Docker Desktop running.
# Usage: .\tools\qa\approach-b-provision.ps1 -Target all|a1|a2|b1|c1|c2

param(
    [ValidateSet('all', 'a1', 'a2', 'b1', 'c1', 'c2')]
    [string] $Target = 'all'
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')

function Sync-Plugin {
    param([string] $Container)
    docker compose -f (Join-Path $RepoRoot 'docker-compose.qa.yml') exec -T $Container bash -lc @'
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
  -cf - . | tar -C "$dst" -xf -
'@
}

function Install-WpCore {
    param([string] $WpcliService, [string] $Url)
    docker compose -f (Join-Path $RepoRoot 'docker-compose.qa.yml') run --rm $WpcliService core is-installed 2>$null
    if ($LASTEXITCODE -ne 0) {
        docker compose -f (Join-Path $RepoRoot 'docker-compose.qa.yml') run --rm $WpcliService core install `
            --url=$Url --title="QA RestoreOne" --admin_user=admin --admin_password=admin `
            --admin_email=admin@example.com --skip-email
    }
}

Write-Host '[qa] Starting QA compose stack...'
docker compose -f (Join-Path $RepoRoot 'docker-compose.qa.yml') up -d

$map = @{
    a1 = @{ wp = 'qa-a1'; wpcli = 'qa-wpcli-a1'; url = 'http://localhost:8081'; profile = 'empty_shell' }
    a2 = @{ wp = 'qa-a2'; wpcli = 'qa-wpcli-a1'; url = 'http://localhost:8082'; profile = 'fresh_wp' }
    b1 = @{ wp = 'qa-b1'; wpcli = 'qa-wpcli-a1'; url = 'http://localhost:8083'; profile = 'populated_wp' }
    c1 = @{ wp = 'qa-c1'; wpcli = 'qa-wpcli-a1'; url = 'http://localhost:8084'; profile = 'populated_wp' }
    c2 = @{ wp = 'qa-c2'; wpcli = 'qa-wpcli-a1'; url = 'http://localhost:8085'; profile = 'mixed' }
}

$targets = if ($Target -eq 'all') { @('a1','a2','b1','c1','c2') } else { @($Target) }

foreach ($t in $targets) {
    $cfg = $map[$t]
    Write-Host "[qa] Provisioning $($cfg.profile) on $($cfg.url) ..."
    if ($t -eq 'a1') {
        Write-Host '[qa] QA-A1: leave docroot without core until S1/S2 (manual wipe optional).'
    } else {
        Install-WpCore -WpcliService $cfg.wpcli -Url $cfg.url
    }
    Sync-Plugin -Container $cfg.wp
    Write-Host "[qa] Done $t"
}

Write-Host '[qa] Activate plugin on each installed site with:'
Write-Host '  docker compose -f docker-compose.qa.yml run --rm qa-wpcli-a1 plugin activate museder-restoreone'
Write-Host '[qa] Note: per-site wp-cli services can be added; use host port mapping for now.'

#!/usr/bin/env bash
# Large-site / default-stack checks on repo root docker-compose (port 8080).
# Env:
#   MR_FT_SKIP_SMALL=1  — do not run run-clean-small-site-ft.sh first (default 1).
#   MR_FT_RUN_LARGE=1   — run 3GB sparse skip test (needs free disk; slow).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
mkdir -p "${ROOT}/reports"
REPORT="${ROOT}/reports/ft-large-$(date +%Y%m%d-%H%M%S).log"
exec > >(tee -a "$REPORT") 2>&1

unset COMPOSE_FILE || true
# Pin the repo-root stack (8080) — inherited COMPOSE_FILE from other FT shells must not override this.
export COMPOSE_FILE="${ROOT}/docker-compose.yml"
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-}"

if [[ "${MR_FT_SKIP_SMALL:-1}" != "1" ]]; then
  echo "[ft-large] Running small-site stack first …"
  "${ROOT}/tools/functional-test/run-clean-small-site-ft.sh" || true
fi

echo "[ft-large] Ensure default stack is up (docker compose) …"
docker compose up -d db wordpress

# shellcheck source=/dev/null
source "${ROOT}/tools/functional-test/lib.sh"
ft_wait_db || true
ft_wait_wp_config || true

if docker compose run --rm wpcli core is-installed >/dev/null 2>&1; then
  ft_sync_plugin_into_container || true
  docker compose run --rm wpcli plugin activate museder-restoreone 2>/dev/null || true
fi

echo "[ft-large] Plugin Check …"
"${ROOT}/tools/run-wp-plugin-check-docker.sh" || true

if docker compose run --rm wpcli core is-installed >/dev/null 2>&1; then
  echo "[ft-large] Regression …"
  docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tests/php-regression/final_review_248_regression.php

  echo "[ft-large] Chunk REST smoke …"
  docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-rest-chunk-smoke.php

  echo "[ft-large] REST permissions + safe_path_join + uninstall manifest …"
  docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-ft-rest-permissions-smoke.php
  docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-ft-safe-path-join.php
  docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-ft-uninstall-manifest.php

  echo "[ft-large] Endpoint matrices + mail pipeline …"
  docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-ft-endpoint-matrix.php
  docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-ft-rest-endpoint-matrix.php
  docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-ft-admin-post-matrix.php
  docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-ft-mail-pipeline-smoke.php

  if [[ "${MR_FT_INCLUDE_PCLZIP:-}" == "1" ]]; then
    echo "[ft-large] PclZip forced minimal backup (MR_FT_INCLUDE_PCLZIP=1) …"
    docker compose run --rm wpcli --require=/tmp/museder-restoreone-src/tools/functional-test/force-no-ziparchive.php eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-pclzip-minimal-backup.php
  else
    echo "[ft-large] Skip PclZip forced full backup on default stack (set MR_FT_INCLUDE_PCLZIP=1 on smaller installs)."
  fi

  if [[ -x "${ROOT}/tools/docker/make-large-uploads.sh" ]]; then
    echo "[ft-large] make-large-uploads …"
    "${ROOT}/tools/docker/make-large-uploads.sh" || true
  fi

  if [[ "${MR_FT_RUN_LARGE:-}" == "1" ]]; then
    echo "[ft-large] >2GB single-file skip test …"
    docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-backup-skip-large-smoke.php
  else
    echo "[ft-large] Skip >2GB single-file test (set MR_FT_RUN_LARGE=1 to enable)."
  fi

  echo "[ft-large] Cron sample …"
  docker compose run --rm wpcli cron event list | head -n 40 || true

  echo "[ft-large] wp-content du …"
  docker compose exec -T wordpress bash -lc 'du -sh /var/www/html/wp-content 2>/dev/null || true'
else
  echo "[ft-large] WordPress not installed on default stack — skip runtime tests. Run tools/docker/setup.sh first."
fi

echo "[ft-large] DONE log=$REPORT"

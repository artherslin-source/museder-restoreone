#!/usr/bin/env bash
# Small-site matrix: fresh WP 7.0.x stack (port 8090), pinned to 7.0.3 via wp core update when needed.
# Prerequisite: Docker. Destroys volumes for COMPOSE_PROJECT_NAME on each run.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
# shellcheck source=/dev/null
source "${ROOT}/tools/functional-test/lib.sh"

export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-restoreone-ft-small}"
export COMPOSE_FILE="${COMPOSE_FILE:-tools/docker/docker-compose.ft-small-8090.yml}"
export MR_FT_HOST_PORT="${MR_FT_HOST_PORT:-8090}"
export MR_FT_WP_URL="${MR_FT_WP_URL:-http://localhost:${MR_FT_HOST_PORT}}"
REPORT="${ROOT}/reports/ft-small-${COMPOSE_PROJECT_NAME}-$(date +%Y%m%d-%H%M%S).log"
mkdir -p "${ROOT}/reports"
exec > >(tee -a "$REPORT") 2>&1

echo "[ft-small] compose down -v …"
docker compose down -v >/dev/null 2>&1 || true
docker compose up -d db wordpress

echo "[ft-small] wait db/wp …"
ft_wait_db
ft_wait_wp_config

echo "[ft-small] wait db check …"
for i in $(seq 1 60); do
  if docker compose run --rm wpcli db check >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

if ! docker compose run --rm wpcli core is-installed >/dev/null 2>&1; then
  echo "[ft-small] core install …"
  docker compose run --rm wpcli core install \
    --url="${MR_FT_WP_URL}" \
    --title="RestoreOne FT Small" \
    --admin_user="admin" \
    --admin_password="admin" \
    --admin_email="admin@example.com" \
    --skip-email
fi

ft_ensure_wp_core_version "${MR_FT_WP_CORE_VERSION:-7.0.3}"

docker compose exec -T wordpress bash -lc 'mkdir -p /var/www/html/wp-content/mu-plugins' || true
ft_sync_plugin_into_container

docker compose run --rm wpcli plugin activate museder-restoreone

echo "[ft-small] install theme + WooCommerce + common plugins …"
if docker compose exec -T wordpress bash -lc 'test -f /tmp/test-one/museder-blank-theme.zip'; then
  docker compose run --rm wpcli theme install /tmp/test-one/museder-blank-theme.zip --activate
else
  docker compose run --rm wpcli theme install twentytwentyfour --activate
fi

docker compose run --rm wpcli plugin install woocommerce wordpress-seo contact-form-7 classic-editor wordfence-login-security --activate

docker compose run --rm wpcli plugin install plugin-check --activate

echo "[ft-small] Plugin Check …"
docker compose run --rm wpcli plugin check museder-restoreone

echo "[ft-small] PHP regression …"
docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tests/php-regression/final_review_248_regression.php

echo "[ft-small] Chunk REST smoke …"
docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-rest-chunk-smoke.php

echo "[ft-small] REST permissions + safe_path_join + uninstall manifest …"
docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-ft-rest-permissions-smoke.php
docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-ft-safe-path-join.php
docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-ft-uninstall-manifest.php

echo "[ft-small] Endpoint matrices (AJAX / REST / admin_post) + mail pipeline …"
docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-ft-endpoint-matrix.php
docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-ft-rest-endpoint-matrix.php
docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-ft-admin-post-matrix.php
docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-ft-mail-pipeline-smoke.php

echo "[ft-small] nginx -> Apache REST smoke (profile ft-nginx-front) …"
chmod +x "${ROOT}/tools/functional-test/run-nginx-chunk-proxy-smoke.sh"
"${ROOT}/tools/functional-test/run-nginx-chunk-proxy-smoke.sh"

echo "[ft-small] PclZip forced minimal backup …"
docker compose run --rm wpcli --require=/tmp/museder-restoreone-src/tools/functional-test/force-no-ziparchive.php eval-file /tmp/museder-restoreone-src/tools/functional-test/wp-pclzip-minimal-backup.php

echo "[ft-small] Cron hooks (schedule handler) …"
docker compose run --rm wpcli cron event list | head -n 30 || true

echo "[ft-small] wp-content size …"
docker compose exec -T wordpress bash -lc 'du -sh /var/www/html/wp-content || true'

export MR_FT_SKIP_LARGE=1
echo "[ft-small] Skip >2GB single-file test on small stack (MR_FT_SKIP_LARGE=1)."

if [[ "${MR_FT_RUN_ZIP_INSTALL:-1}" == "1" ]]; then
  echo "[ft-small] Dist ZIP clean install + Plugin Check …"
  chmod +x "${ROOT}/tools/functional-test/run-zip-clean-install-plugin-check.sh"
  "${ROOT}/tools/functional-test/run-zip-clean-install-plugin-check.sh"
fi

if [[ "${MR_FT_RUN_MULTISITE:-1}" == "1" ]]; then
  echo "[ft-small] Multisite uninstall stack (separate compose project) …"
  chmod +x "${ROOT}/tools/functional-test/run-multisite-uninstall-ft.sh"
  COMPOSE_PROJECT_NAME="${MR_FT_MS_PROJECT:-restoreone-ft-ms}" \
    MR_FT_MS_COMPOSE_FILE="${MR_FT_MS_COMPOSE_FILE:-${ROOT}/tools/docker/docker-compose.ft-multisite-8095.yml}" \
    MR_FT_MS_HOST_PORT="${MR_FT_MS_HOST_PORT:-8095}" \
    MR_FT_MS_WP_URL="${MR_FT_MS_WP_URL:-http://localhost:${MR_FT_MS_HOST_PORT:-8095}}" \
    "${ROOT}/tools/functional-test/run-multisite-uninstall-ft.sh"
fi

if [[ "${MR_FT_RUN_PHPCS:-1}" == "1" ]]; then
  echo "[ft-small] PHPCS summary (host-side tooling) …"
  chmod +x "${ROOT}/tools/functional-test/run-phpcs-summary.sh"
  "${ROOT}/tools/functional-test/run-phpcs-summary.sh" || echo "[ft-small] PHPCS exited non-zero — see reports/phpcs-*.txt"
fi

echo "[ft-small] DONE log=$REPORT"
echo "UI/UX: open ${MR_FT_WP_URL}/wp-login.php — use mu-plugin “Local E2E login” if present; verify RestoreOne pages in Light/Dark theme (manual)."

#!/usr/bin/env bash
# Fresh WordPress + only RestoreOne — E2E large multi-plugin restore (sunpoweroflight repro).
set -euo pipefail

cd "$(dirname "$0")/../.."

ZIP_NAME="${MUSEDER_RESTORE_E2E_ZIP:-sunpoweroflight.com-20260311014315-ptq9eY.zip}"
HOST_ZIP="logs/150525-debug/${ZIP_NAME}"

if [[ ! -f "${HOST_ZIP}" ]]; then
  echo "[error] Missing backup zip: ${HOST_ZIP}"
  echo "        Place the repro archive under logs/150525-debug/ before running."
  exit 1
fi

echo "[docker] Resetting volumes for a fresh WordPress install..."
docker compose down -v
docker compose up -d db wordpress

echo "[docker] Waiting for database..."
for i in {1..60}; do
  if docker compose exec -T db mariadb-admin ping -uroot -proot --silent >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

echo "[docker] Waiting for WordPress..."
for i in {1..90}; do
  if docker compose exec wordpress test -f /var/www/html/wp-config.php >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

echo "[docker] Waiting for WP-CLI..."
for i in {1..90}; do
  if docker compose run --rm wpcli core version >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

echo "[wp] Fresh core install..."
docker compose run --rm wpcli core install \
  --url="http://localhost:8080" \
  --title="RestoreOne Fresh Repro" \
  --admin_user="admin" \
  --admin_password="admin" \
  --admin_email="admin@example.com" \
  --skip-email

echo "[wp] Syncing RestoreOne plugin (dev tree, no extra test plugins)..."
docker compose exec -T wordpress bash -lc '
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
'

docker compose run --rm wpcli plugin activate museder-restoreone

echo "[wp] Copying repro backup into uploads/museder-restoreone/backups/ ..."
docker compose exec -T wordpress bash -lc "
  set -e
  udir=\$(wp eval 'echo wp_upload_dir()[\"basedir\"];' --allow-root 2>/dev/null || true)
  if [[ -z \"\$udir\" ]]; then
    udir=/var/www/html/wp-content/uploads
  fi
  bdir=\"\${udir}/museder-restoreone/backups\"
  mkdir -p \"\$bdir\"
  src_zip=\"/tmp/museder-restore-e2e/${ZIP_NAME}\"
  if [[ ! -f \"\$src_zip\" ]]; then
    src_zip=\"/tmp/museder-restoreone-src/${HOST_ZIP}\"
  fi
  cp \"\$src_zip\" \"\${bdir}/${ZIP_NAME}\"
  ls -lh \"\${bdir}/${ZIP_NAME}\"
"

echo "[e2e] Running headless restore (this may take 30–90+ minutes for ~737MB)..."
docker compose run --rm \
  -e MUSEDER_RESTORE_E2E_ZIP="${ZIP_NAME}" \
  -e MUSEDER_RESTORE_E2E_SLICE_SECONDS=30 \
  -e MUSEDER_RESTORE_E2E_MAX_SLICES=8000 \
  wpcli eval-file /tmp/museder-restoreone-src/tools/qa/restore-large-e2e.php

echo "[done] E2E restore test finished successfully."

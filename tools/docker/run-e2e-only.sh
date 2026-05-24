#!/usr/bin/env bash
# Continue E2E when Docker stack is already up (after setup-fresh-restore-test.sh prep).
set -euo pipefail
cd "$(dirname "$0")/../.."

ZIP_NAME="${MUSEDER_RESTORE_E2E_ZIP:-sunpoweroflight.com-20260311014315-ptq9eY.zip}"

if ! docker compose exec wordpress test -f /var/www/html/wp-config.php >/dev/null 2>&1; then
  echo "[error] WordPress not ready. Run setup-fresh-restore-test.sh first."
  exit 1
fi

if ! docker compose run --rm wpcli core is-installed >/dev/null 2>&1; then
  docker compose run --rm wpcli core install \
    --url="http://localhost:8080" \
    --title="RestoreOne Fresh Repro" \
    --admin_user="admin" \
    --admin_password="admin" \
    --admin_email="admin@example.com" \
    --skip-email
fi

echo "[wp] Syncing plugin..."
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

echo "[wp] Copying backup zip..."
docker compose exec -T wordpress bash -lc "
  set -e
  udir=\$(wp eval 'echo wp_upload_dir()[\"basedir\"];' --allow-root)
  bdir=\"\${udir}/museder-restoreone/backups\"
  mkdir -p \"\$bdir\"
  src_zip=\"/tmp/museder-restore-e2e/${ZIP_NAME}\"
  cp \"\$src_zip\" \"\${bdir}/${ZIP_NAME}\"
  ls -lh \"\${bdir}/${ZIP_NAME}\"
"

echo "[e2e] Running restore..."
docker compose run --rm \
  -e MUSEDER_RESTORE_E2E_ZIP="${ZIP_NAME}" \
  -e MUSEDER_RESTORE_E2E_SLICE_SECONDS=30 \
  -e MUSEDER_RESTORE_E2E_MAX_SLICES=8000 \
  wpcli eval-file /tmp/museder-restoreone-src/tools/qa/restore-large-e2e.php

echo "[done] PASS"

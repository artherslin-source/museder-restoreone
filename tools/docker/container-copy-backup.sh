#!/usr/bin/env bash
set -euo pipefail
ZIP_NAME="${MUSEDER_RESTORE_E2E_ZIP:-sunpoweroflight.com-20260311014315-ptq9eY.zip}"
udir="$(wp eval 'echo wp_upload_dir()["basedir"];' --path=/var/www/html --allow-root)"
bdir="${udir}/museder-restoreone/backups"
mkdir -p "$bdir"
src_zip="/tmp/museder-restore-e2e/${ZIP_NAME}"
if [[ ! -f "$src_zip" ]]; then
  src_zip="/tmp/museder-restoreone-src/logs/150525-debug/${ZIP_NAME}"
fi
cp "$src_zip" "${bdir}/${ZIP_NAME}"
ls -lh "${bdir}/${ZIP_NAME}"

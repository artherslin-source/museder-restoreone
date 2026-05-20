#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${ROOT_DIR}"

SKIP_SETUP=0
if [[ "${1:-}" == "--skip-setup" ]]; then
  SKIP_SETUP=1
fi

echo "[wp70-smoke] root: ${ROOT_DIR}"

if [[ "${SKIP_SETUP}" -eq 0 ]]; then
  echo "[wp70-smoke] running docker setup..."
  bash "${ROOT_DIR}/tools/docker/setup.sh"
else
  echo "[wp70-smoke] skip setup enabled"
fi

echo "[wp70-smoke] ensure WP core is 7.0..."
bash "${ROOT_DIR}/tools/docker/wp.sh" core update --version=7.0 --force || true
bash "${ROOT_DIR}/tools/docker/wp.sh" core update-db || true

echo "[wp70-smoke] enable WP_DEBUG..."
bash "${ROOT_DIR}/tools/docker/wp.sh" config set WP_DEBUG true --raw --type=constant || true
bash "${ROOT_DIR}/tools/docker/wp.sh" config set WP_DEBUG_LOG true --raw --type=constant || true
bash "${ROOT_DIR}/tools/docker/wp.sh" config set WP_DEBUG_DISPLAY false --raw --type=constant || true

echo "[wp70-smoke] activate plugin..."
bash "${ROOT_DIR}/tools/docker/wp.sh" plugin activate museder-restoreone

echo "[wp70-smoke] run eval smoke checks..."
bash "${ROOT_DIR}/tools/docker/wp.sh" eval-file /tmp/museder-restoreone-src/tools/qa/wp70-smoke-eval.php

echo "[wp70-smoke] build lite package..."
bash "${ROOT_DIR}/create-package.sh"

ZIP_FILE="$(ls -t "${ROOT_DIR}"/dist/museder-restoreone-*.zip 2>/dev/null | head -n 1 || true)"
if [[ -z "${ZIP_FILE}" ]]; then
  echo "[wp70-smoke] FAIL: no generated lite zip found in dist/"
  exit 1
fi

echo "[wp70-smoke] check package boundary: ${ZIP_FILE}"
ZIP_LIST="$(unzip -l "${ZIP_FILE}")"

if grep -E "museder-restoreone/(museder-restoreone-pro/|docs/|tools/|logs/)" <<<"${ZIP_LIST}" >/dev/null; then
  echo "[wp70-smoke] FAIL: lite package contains forbidden paths"
  grep -E "museder-restoreone/(museder-restoreone-pro/|docs/|tools/|logs/)" <<<"${ZIP_LIST}" || true
  exit 1
fi

echo "[wp70-smoke] PASS"

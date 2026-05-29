#!/usr/bin/env bash
# Build the WordPress.org Lite release ZIP for Museder RestoreOne.
#
# Canonical packaging rules: docs/PACKAGING.md
# Windows: do NOT use Compress-Archive; use tools/package-lite-windows.ps1 or bash here.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SLUG="museder-restoreone"
MAIN_FILE="${SCRIPT_DIR}/museder-restoreone.php"

if [[ ! -f "${MAIN_FILE}" ]]; then
	echo "error: ${MAIN_FILE} not found" >&2
	exit 1
fi

VERSION="$(php -r '$c=file_get_contents($argv[1]); if(preg_match("/define\(\s*'\''MUSEDER_RESTOREONE_VERSION'\''\s*,\s*'\''([^'\'']+)'\''\s*\)/",$c,$m)){echo $m[1];} elseif(preg_match("/^\s*Version:\s*(.+)$/m",$c,$m)){echo trim($m[1]);} else {fwrite(STDERR,"version not found\n"); exit(1);}' "${MAIN_FILE}")"

PACKAGE_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
TEMP_DIR="$(mktemp -d)"
PLUGIN_DIR="${TEMP_DIR}/${PLUGIN_SLUG}"

mkdir -p "${PLUGIN_DIR}" "${SCRIPT_DIR}/dist"

echo "Packaging ${PACKAGE_NAME} ..."

cp -r "${SCRIPT_DIR}/assets" "${SCRIPT_DIR}/includes" "${SCRIPT_DIR}/templates" "${PLUGIN_DIR}/"
if [[ -d "${SCRIPT_DIR}/languages" ]]; then
	cp -r "${SCRIPT_DIR}/languages" "${PLUGIN_DIR}/"
fi

cp "${SCRIPT_DIR}/museder-restoreone.php" "${SCRIPT_DIR}/readme.txt" "${PLUGIN_DIR}/"
if [[ -f "${SCRIPT_DIR}/museder-restoreone-restore-bootstrap.php" ]]; then
	cp "${SCRIPT_DIR}/museder-restoreone-restore-bootstrap.php" "${PLUGIN_DIR}/"
fi
if [[ -f "${SCRIPT_DIR}/uninstall.php" ]]; then
	cp "${SCRIPT_DIR}/uninstall.php" "${PLUGIN_DIR}/"
fi
if [[ -f "${SCRIPT_DIR}/download-handler.php" ]]; then
	cp "${SCRIPT_DIR}/download-handler.php" "${PLUGIN_DIR}/"
fi

OUTPUT_FILE="${SCRIPT_DIR}/dist/${PACKAGE_NAME}"
(
	cd "${TEMP_DIR}"
	zip -r "${OUTPUT_FILE}" "${PLUGIN_SLUG}" -q
)

rm -rf "${TEMP_DIR}"

if [[ -f "${OUTPUT_FILE}" ]]; then
	echo "Created ${OUTPUT_FILE}"
	ls -lh "${OUTPUT_FILE}"
else
	echo "error: zip was not created" >&2
	exit 1
fi

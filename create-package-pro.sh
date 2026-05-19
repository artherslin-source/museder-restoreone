#!/usr/bin/env bash
# Build the optional Add-on plugin ZIP (not distributed on WordPress.org).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SLUG="museder-restoreone-pro"
MAIN_FILE="${SCRIPT_DIR}/${PLUGIN_SLUG}/museder-restoreone-pro.php"

if [[ ! -f "${MAIN_FILE}" ]]; then
	echo "error: ${MAIN_FILE} not found" >&2
	exit 1
fi

VERSION="$(php -r '$c=file_get_contents($argv[1]); if(preg_match("/define\(\s*'\''MUSEDER_RESTOREONE_PRO_VERSION'\''\s*,\s*'\''([^'\'']+)'\''\s*\)/",$c,$m)){echo $m[1];} elseif(preg_match("/^\s*Version:\s*(.+)$/m",$c,$m)){echo trim($m[1]);} else {fwrite(STDERR,"version not found\n"); exit(1);}' "${MAIN_FILE}")"

PACKAGE_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
TEMP_DIR="$(mktemp -d)"
PLUGIN_DIR="${TEMP_DIR}/${PLUGIN_SLUG}"

mkdir -p "${PLUGIN_DIR}" "${SCRIPT_DIR}/dist"

echo "Packaging ${PACKAGE_NAME} ..."

rsync -a \
	"${SCRIPT_DIR}/${PLUGIN_SLUG}/" \
	"${PLUGIN_DIR}/"

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

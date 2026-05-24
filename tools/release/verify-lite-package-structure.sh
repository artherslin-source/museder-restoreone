#!/usr/bin/env bash
# Verify WordPress.org Lite package structure for Museder RestoreOne.
set -euo pipefail

ZIP_PATH="${1:-}"
SLUG="museder-restoreone"

if [[ -z "${ZIP_PATH}" ]]; then
  echo "usage: $0 <path-to-zip>" >&2
  exit 2
fi

if [[ ! -f "${ZIP_PATH}" ]]; then
  echo "error: zip not found: ${ZIP_PATH}" >&2
  exit 2
fi

if ! command -v unzip >/dev/null 2>&1; then
  echo "error: unzip command is required for verification" >&2
  exit 2
fi

ENTRIES="$(unzip -Z1 "${ZIP_PATH}")"
if [[ -z "${ENTRIES}" ]]; then
  echo "error: zip appears empty: ${ZIP_PATH}" >&2
  exit 1
fi

top_levels="$(printf '%s\n' "${ENTRIES}" | awk -F/ 'NF>1 {print $1}' | sort -u)"
top_count="$(printf '%s\n' "${top_levels}" | sed '/^$/d' | wc -l | tr -d ' ')"

if [[ "${top_count}" != "1" ]]; then
  echo "error: zip must contain exactly one top-level directory (found ${top_count})" >&2
  printf '%s\n' "${top_levels}" >&2
  exit 1
fi

if ! printf '%s\n' "${top_levels}" | grep -qx "${SLUG}"; then
  echo "error: top-level directory must be '${SLUG}'" >&2
  printf '%s\n' "${top_levels}" >&2
  exit 1
fi

if ! printf '%s\n' "${ENTRIES}" | grep -qx "${SLUG}/museder-restoreone.php"; then
  echo "error: missing required entry '${SLUG}/museder-restoreone.php'" >&2
  exit 1
fi

if printf '%s\n' "${ENTRIES}" | grep -qE "^${SLUG}-[0-9]+\.[0-9]+\.[0-9]+/"; then
  echo "error: detected versioned wrapper directory inside zip (double-wrap)" >&2
  exit 1
fi

for forbidden in \
  "docs/" \
  "tools/" \
  "logs/" \
  ".git/" \
  ".github/" \
  ".cursor/" \
  "dist/" \
  "museder-restoreone-pro/"; do
  if printf '%s\n' "${ENTRIES}" | grep -q "^${SLUG}/${forbidden}"; then
    echo "error: forbidden path found in zip: ${SLUG}/${forbidden}" >&2
    exit 1
  fi
done

echo "OK: package structure verified for ${ZIP_PATH}"

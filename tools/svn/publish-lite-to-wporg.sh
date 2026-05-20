#!/usr/bin/env bash
# Sync Museder RestoreOne Lite sources into a WordPress.org SVN working copy.
#
# Prerequisites:
#   - One-time: svn co https://plugins.svn.wordpress.org/museder-restoreone/ /path/to/svn-wc
#   - Bash, svn, rsync (Git Bash on Windows is fine)
#   - Run from plugin repo root, or set MUSEDER_RESTOREONE_REPO
#
# Usage:
#   bash tools/svn/publish-lite-to-wporg.sh /path/to/svn-wc
#   bash tools/svn/publish-lite-to-wporg.sh /path/to/svn-wc --commit
#   bash tools/svn/publish-lite-to-wporg.sh /path/to/svn-wc --from-zip dist/museder-restoreone-2.7.263.zip --commit
#
# This copies ready-to-use Lite files into trunk/, copies trunk/ to tags/<version>/,
# and optionally commits. It does not upload zip files to SVN.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="${MUSEDER_RESTOREONE_REPO:-$(cd "${SCRIPT_DIR}/../.." && pwd)}"
MAIN_FILE="${REPO_ROOT}/museder-restoreone.php"
PLUGIN_SLUG="museder-restoreone"

usage() {
	cat <<'EOF'
Usage: publish-lite-to-wporg.sh <svn-working-copy> [--commit] [--from-zip PATH]

  <svn-working-copy>  Path to SVN checkout (must contain trunk/)
  --commit            Run svn commit after sync (prompts for WordPress.org credentials)
  --from-zip PATH     Use extracted Lite zip instead of live repo tree
EOF
}

die() {
	echo "error: $*" >&2
	exit 1
}

read_version() {
	if [[ ! -f "${MAIN_FILE}" ]]; then
		die "main plugin file not found: ${MAIN_FILE}"
	fi
	php -r '$c=file_get_contents($argv[1]); if(preg_match("/define\(\s*'\''MUSEDER_RESTOREONE_VERSION'\''\s*,\s*'\''([^'\'']+)'\''\s*\)/",$c,$m)){echo $m[1];} elseif(preg_match("/^\s*Version:\s*(.+)$/m",$c,$m)){echo trim($m[1]);} else {fwrite(STDERR,"version not found\n"); exit(1);}' "${MAIN_FILE}"
}

stage_lite_tree() {
	local dest="$1"
	rm -rf "${dest}"
	mkdir -p "${dest}"
	cp -r "${REPO_ROOT}/assets" "${REPO_ROOT}/includes" "${REPO_ROOT}/templates" "${dest}/"
	if [[ -d "${REPO_ROOT}/languages" ]]; then
		cp -r "${REPO_ROOT}/languages" "${dest}/"
	fi
	cp "${REPO_ROOT}/museder-restoreone.php" "${REPO_ROOT}/readme.txt" "${dest}/"
	[[ -f "${REPO_ROOT}/uninstall.php" ]] && cp "${REPO_ROOT}/uninstall.php" "${dest}/"
	[[ -f "${REPO_ROOT}/download-handler.php" ]] && cp "${REPO_ROOT}/download-handler.php" "${dest}/"
}

stage_from_zip() {
	local zip_path="$1"
	local dest="$2"
	local temp
	temp="$(mktemp -d)"
	unzip -q "${zip_path}" -d "${temp}"
	local inner="${temp}/${PLUGIN_SLUG}"
	[[ -d "${inner}" ]] || die "zip must contain ${PLUGIN_SLUG}/ top-level folder: ${zip_path}"
	rm -rf "${dest}"
	cp -a "${inner}" "${dest}"
	rm -rf "${temp}"
}

sync_to_trunk() {
	local source="$1"
	local trunk="$2"
	mkdir -p "${trunk}"
	# Mirror Lite tree into trunk/; remove files no longer in the release.
	rsync -a --delete "${source}/" "${trunk}/"
}

svn_sync_trunk() {
	local wc="$1"
	local trunk="${wc}/trunk"
	(
		cd "${wc}"
		svn add trunk --force 2>/dev/null || true
		svn status trunk | awk '/^!/ {print $2}' | xargs -r svn delete 2>/dev/null || true
		svn add trunk --force
	)
}

tag_from_trunk() {
	local wc="$1"
	local version="$2"
	local tag_path="${wc}/tags/${version}"
	if [[ -d "${tag_path}" ]]; then
		echo "SVN tag already exists: tags/${version} (skipping svn copy)"
		return 0
	fi
	(
		cd "${wc}"
		svn copy "trunk" "tags/${version}"
	)
}

if [[ $# -lt 1 ]]; then
	usage
	exit 1
fi

SVN_WC="$1"
shift
DO_COMMIT=0
FROM_ZIP=""

while [[ $# -gt 0 ]]; do
	case "$1" in
	--commit) DO_COMMIT=1 ;;
	--from-zip)
		shift
		[[ $# -ge 1 ]] || die "--from-zip requires a path"
		FROM_ZIP="$1"
		;;
	-h | --help)
		usage
		exit 0
		;;
	*) die "unknown argument: $1" ;;
	esac
	shift
done

[[ -d "${SVN_WC}/trunk" ]] || die "SVN working copy missing trunk/: ${SVN_WC}"
command -v svn >/dev/null 2>&1 || die "svn not found in PATH"
command -v rsync >/dev/null 2>&1 || die "rsync not found in PATH"

VERSION="$(read_version)"
STABLE="$(php -r '$c=file_get_contents($argv[1]); if(preg_match("/^Stable tag:\s*(.+)$/m",$c,$m)){echo trim($m[1]);} else {exit(1);}' "${REPO_ROOT}/readme.txt")"
[[ "${VERSION}" == "${STABLE}" ]] || die "Version mismatch: museder-restoreone.php=${VERSION}, readme Stable tag=${STABLE}"

STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT

if [[ -n "${FROM_ZIP}" ]]; then
	[[ -f "${FROM_ZIP}" ]] || die "zip not found: ${FROM_ZIP}"
	stage_from_zip "${FROM_ZIP}" "${STAGE}/lite"
else
	stage_lite_tree "${STAGE}/lite"
fi

echo "Syncing Lite ${VERSION} -> ${SVN_WC}/trunk"
sync_to_trunk "${STAGE}/lite" "${SVN_WC}/trunk"
svn_sync_trunk "${SVN_WC}"
tag_from_trunk "${SVN_WC}" "${VERSION}"

echo ""
echo "SVN status (trunk and tags/${VERSION}):"
svn status "${SVN_WC}/trunk" "${SVN_WC}/tags/${VERSION}" 2>/dev/null || svn status "${SVN_WC}/trunk"

if [[ "${DO_COMMIT}" -eq 1 ]]; then
	echo ""
	echo "Committing Release ${VERSION} ..."
	(
		cd "${SVN_WC}"
		svn commit -m "Release ${VERSION}"
	)
	echo "Done. Verify https://wordpress.org/plugins/${PLUGIN_SLUG}/"
else
	echo ""
	echo "Dry run complete (no commit). Review with:"
	echo "  svn diff ${SVN_WC}/trunk"
	echo "Then commit:"
	echo "  cd ${SVN_WC} && svn commit -m \"Release ${VERSION}\""
fi

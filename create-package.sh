#!/bin/bash
# 创建 Museder RestoreOne 插件打包文件

set -euo pipefail

PLUGIN_NAME="museder-restoreone"
VERSION="$(php -r '$c=file_get_contents("museder-restoreone.php"); if(preg_match("/^\\s*Version:\\s*(.+)$/m",$c,$m)) { echo trim($m[1]); }')"
PACKAGE_NAME="${PLUGIN_NAME}-${VERSION}.zip"
TEMP_DIR=$(mktemp -d)
PLUGIN_DIR="${TEMP_DIR}/${PLUGIN_NAME}"

echo "正在创建插件打包文件: ${PACKAGE_NAME}"

# 创建临时目录
mkdir -p "${PLUGIN_DIR}"

# 复制必要的文件
echo "复制文件..."
cp -r assets "${PLUGIN_DIR}/"
cp -r includes "${PLUGIN_DIR}/"
cp -r languages "${PLUGIN_DIR}/"
cp -r templates "${PLUGIN_DIR}/"
cp museder-restoreone.php "${PLUGIN_DIR}/"
cp readme.txt "${PLUGIN_DIR}/"
cp download-handler.php "${PLUGIN_DIR}/"

# 排除的文件和目录
exclude_items=(
    ".git"
    ".gitignore"
    "logs"
    "node_modules"
    ".DS_Store"
    "*.zip"
    "*.log"
    "*.swp"
    "*.swo"
    "*~"
    ".idea"
    ".vscode"
)

# 创建 ZIP 文件（在项目根目录）
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
mkdir -p "${SCRIPT_DIR}/dist"
OUTPUT_FILE="${SCRIPT_DIR}/dist/${PACKAGE_NAME}"

# Pre-package scan: fail fast if forbidden files would be shipped.
echo "打包前自动扫描（确保封装内容干净）..."
python3 - "${PLUGIN_DIR}" <<'PY'
import os
import re
import sys
from pathlib import Path

plugin_dir = Path(sys.argv[1])
if not plugin_dir.exists():
    print(f"[scan] ERROR: plugin dir not found: {plugin_dir}", file=sys.stderr)
    sys.exit(2)

forbidden_dir_names = {
    "docs", "logs", "tools", "dist", "tmp", "node_modules",
    ".git", ".github", ".cursor", ".vscode", ".idea",
}

forbidden_ext = {
    ".zip", ".tar", ".gz", ".bz2", ".7z", ".rar",
    ".psd", ".ai", ".map",
}

# WP.org-friendly: allow only specific markdown (we ship readme.txt, not .md).
forbidden_md = True

bad = []

def is_hidden_part(part: str) -> bool:
    return part.startswith(".")

for path in plugin_dir.rglob("*"):
    rel = path.relative_to(plugin_dir)
    parts = rel.parts

    # Hidden file or folder anywhere.
    if any(is_hidden_part(p) for p in parts):
        bad.append(("hidden", str(rel)))
        continue

    # Forbidden top-level folder names.
    if parts and parts[0] in forbidden_dir_names:
        bad.append(("forbidden_dir", str(rel)))
        continue

    if path.is_dir():
        continue

    name_lower = path.name.lower()

    if path.name == ".DS_Store" or name_lower == ".ds_store":
        bad.append(("ds_store", str(rel)))
        continue

    if path.suffix.lower() in forbidden_ext:
        bad.append(("forbidden_ext", str(rel)))
        continue

    if forbidden_md and path.suffix.lower() == ".md":
        bad.append(("markdown", str(rel)))
        continue

if bad:
    print("[scan] ERROR: 封装内容包含不应提交到 WordPress.org 的档案：", file=sys.stderr)
    for kind, rel in bad[:200]:
        print(f"  - [{kind}] {rel}", file=sys.stderr)
    if len(bad) > 200:
        print(f"  ... 以及另外 {len(bad) - 200} 项", file=sys.stderr)
    sys.exit(1)

print("[scan] OK: 未发现不应封装的档案")
PY

# Ensure we don't keep stale entries when the zip already exists
rm -f "${OUTPUT_FILE}"

cd "${TEMP_DIR}"
echo "正在压缩..."
zip -r "${OUTPUT_FILE}" "${PLUGIN_NAME}" -q

# 清理临时目录
cd "${SCRIPT_DIR}"
rm -rf "${TEMP_DIR}"

if [ -f "${OUTPUT_FILE}" ]; then
    echo "打包完成！"
    echo "文件位置: ${OUTPUT_FILE}"
    echo "文件大小: $(du -h "${OUTPUT_FILE}" | cut -f1)"
    ls -lh "${OUTPUT_FILE}"
else
    echo "错误：打包文件创建失败！"
    exit 1
fi


#!/bin/bash
# 创建 Museder RestoreOne 插件打包文件

PLUGIN_NAME="museder-restoreone"
VERSION="$(php -r '$c=file_get_contents("museder-restoreone.php"); if(preg_match("/^Version:\\s*(.+)$/m",$c,$m)) { echo trim($m[1]); }')"
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
cp -r docs "${PLUGIN_DIR}/"
cp museder-restoreone.php "${PLUGIN_DIR}/"
cp readme.txt "${PLUGIN_DIR}/"
cp download-handler.php "${PLUGIN_DIR}/"
cp upload-handler.php "${PLUGIN_DIR}/"

# 排除不需要的目录
if [ -d "dist" ]; then
    cp -r dist "${PLUGIN_DIR}/" 2>/dev/null || true
fi

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


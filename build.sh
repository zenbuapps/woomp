#!/usr/bin/env bash
# =============================================================================
# Woomp Plugin Build Script
# 用法：bash build.sh
# 輸出：build/woomp-{VERSION}.zip
# =============================================================================

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SLUG="woomp"
MAIN_FILE="${PLUGIN_DIR}/${PLUGIN_SLUG}.php"
BUILD_DIR="${PLUGIN_DIR}/build"
STAGE_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"

# ── 1. 讀取版本號 ────────────────────────────────────────────────────────────
VERSION=$(grep -m1 '^ \* Version:' "${MAIN_FILE}" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')

if [[ -z "${VERSION}" ]]; then
    echo "❌ 無法從 ${MAIN_FILE} 讀取版本號，請確認 ' * Version: x.x.x' 格式存在。"
    exit 1
fi

ZIP_NAME="${PLUGIN_SLUG}.zip"
ZIP_PATH="${BUILD_DIR}/${ZIP_NAME}"

echo "================================================"
echo "  Plugin : ${PLUGIN_SLUG}"
echo "  Version: ${VERSION}"
echo "  Output : build/${ZIP_NAME}"
echo "================================================"

# ── 2. 準備暫存目錄 ──────────────────────────────────────────────────────────
echo ""
echo "▶ 清理並建立暫存目錄 build/${PLUGIN_SLUG}/ ..."
rm -rf "${STAGE_DIR}"
mkdir -p "${STAGE_DIR}"

# ── 3. 複製檔案（排除開發用目錄與檔案）────────────────────────────────────────
echo "▶ 複製檔案（rsync）..."
rsync -a \
    --exclude=".git/" \
    --exclude=".idea/" \
    --exclude="tests/" \
    --exclude="vendor/" \
    --exclude="build/" \
    --exclude="phpcs.xml" \
    --exclude="phpunit.xml" \
		--exclude="build.sh" \
    --exclude="tailwind.config.cjs" \
    --exclude=".gitignore" \
    "${PLUGIN_DIR}/" "${STAGE_DIR}/"

# ── 4. 重新執行 composer install（僅正式依賴）──────────────────────────────────
echo "▶ 執行 composer install --no-dev ..."
composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --working-dir="${STAGE_DIR}"

# ── 5. 移除 composer 設定檔（不放入 zip）──────────────────────────────────────
echo "▶ 移除 composer.json / composer.lock ..."
rm -f "${STAGE_DIR}/composer.json"
rm -f "${STAGE_DIR}/composer.lock"

# ── 6. 壓縮成 zip ────────────────────────────────────────────────────────────
echo "▶ 壓縮為 ${ZIP_NAME} ..."
# 移除舊的同名 zip（若存在）
rm -f "${ZIP_PATH}"
cd "${BUILD_DIR}"
zip -r "${ZIP_NAME}" "${PLUGIN_SLUG}/"
cd "${PLUGIN_DIR}"

# ── 7. 清理暫存目錄 ───────────────────────────────────────────────────────────
echo "▶ 清理暫存目錄 ..."
rm -rf "${STAGE_DIR}"

# ── 完成 ─────────────────────────────────────────────────────────────────────
echo ""
echo "✅ 打包完成！"
echo "   → ${ZIP_PATH}"
echo ""

# 顯示 zip 檔案大小
if command -v du &>/dev/null; then
    echo "   檔案大小：$(du -sh "${ZIP_PATH}" | cut -f1)"
fi

#!/usr/bin/env node
// =============================================================================
// Woomp Plugin Build Script (跨平台 Node.js 版本)
// 用法：npm run build  或  node build.mjs
// 輸出：build/woomp.zip
// =============================================================================
import fs from 'node:fs';
import path from 'node:path';
import { execSync } from 'node:child_process';
import { createWriteStream } from 'node:fs';
import archiver from 'archiver';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const PLUGIN_SLUG = 'woomp';
const PLUGIN_DIR  = __dirname;
const MAIN_FILE   = path.join(PLUGIN_DIR, `${PLUGIN_SLUG}.php`);
const BUILD_DIR   = path.join(PLUGIN_DIR, 'build');
const STAGE_DIR   = path.join(BUILD_DIR, PLUGIN_SLUG);

// ── 1. 讀取版本號 ─────────────────────────────────────────────────────────────
const mainContent = fs.readFileSync(MAIN_FILE, 'utf8');
const versionMatch = mainContent.match(/^\s*\*\s*Version:\s*(.+)$/m);

if (!versionMatch) {
  console.error(`❌ 無法從 ${MAIN_FILE} 讀取版本號，請確認 ' * Version: x.x.x' 格式存在。`);
  process.exit(1);
}

const VERSION  = versionMatch[1].trim();
const ZIP_NAME = `${PLUGIN_SLUG}.zip`;
const ZIP_PATH = path.join(BUILD_DIR, ZIP_NAME);

console.log('================================================');
console.log(`  Plugin : ${PLUGIN_SLUG}`);
console.log(`  Version: ${VERSION}`);
console.log(`  Output : build/${ZIP_NAME}`);
console.log('================================================');

// ── 2. 準備暫存目錄 ───────────────────────────────────────────────────────────
console.log(`\n▶ 清理並建立暫存目錄 build/${PLUGIN_SLUG}/ ...`);
fs.rmSync(STAGE_DIR, { recursive: true, force: true });
fs.mkdirSync(STAGE_DIR, { recursive: true });

// ── 3. 複製檔案（白名單制：只打包指定的目錄與檔案）──────────────────────────────
const INCLUDE_DIRS = ['admin', 'includes', 'languages', 'public', 'vendor', 'woocommerce'];

function copyDir(src, dest) {
  fs.mkdirSync(dest, { recursive: true });
  for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
    const srcPath  = path.join(src, entry.name);
    const destPath = path.join(dest, entry.name);
    if (entry.isDirectory()) {
      copyDir(srcPath, destPath);
    } else {
      fs.copyFileSync(srcPath, destPath);
    }
  }
}

console.log('▶ 複製檔案（白名單制）...');

// 複製白名單目錄
for (const dir of INCLUDE_DIRS) {
  const srcDir = path.join(PLUGIN_DIR, dir);
  if (fs.existsSync(srcDir)) {
    copyDir(srcDir, path.join(STAGE_DIR, dir));
  } else {
    console.warn(`   ⚠ 目錄不存在，跳過：${dir}/`);
  }
}

// 複製根目錄下所有 .php 檔
for (const entry of fs.readdirSync(PLUGIN_DIR, { withFileTypes: true })) {
  if (!entry.isDirectory() && entry.name.endsWith('.php')) {
    fs.copyFileSync(
      path.join(PLUGIN_DIR, entry.name),
      path.join(STAGE_DIR, entry.name),
    );
  }
}

// ── 4. 重新執行 composer install（僅正式依賴）────────────────────────────────
console.log('▶ 複製 composer 設定檔並執行 composer install --no-dev ...');
// composer install 需要 composer.json / composer.lock，白名單未包含，手動複製
for (const f of ['composer.json', 'composer.lock']) {
  const src = path.join(PLUGIN_DIR, f);
  if (fs.existsSync(src)) fs.copyFileSync(src, path.join(STAGE_DIR, f));
}
execSync(
  'composer install --no-dev --optimize-autoloader --no-interaction',
  { cwd: STAGE_DIR, stdio: 'inherit' }
);

// ── 5. 移除 composer 設定檔（不放入 zip）─────────────────────────────────────
console.log('▶ 移除 composer.json / composer.lock ...');
fs.rmSync(path.join(STAGE_DIR, 'composer.json'), { force: true });
fs.rmSync(path.join(STAGE_DIR, 'composer.lock'), { force: true });

// ── 6. 壓縮成 zip ─────────────────────────────────────────────────────────────
console.log(`▶ 壓縮為 ${ZIP_NAME} ...`);
fs.rmSync(ZIP_PATH, { force: true });

await new Promise((resolve, reject) => {
  const output  = createWriteStream(ZIP_PATH);
  const archive = archiver('zip', { zlib: { level: 9 } });

  output.on('close', resolve);
  archive.on('error', reject);

  archive.pipe(output);
  archive.directory(STAGE_DIR, PLUGIN_SLUG);
  archive.finalize();
});

// ── 7. 清理暫存目錄 ───────────────────────────────────────────────────────────
console.log('▶ 清理暫存目錄 ...');
fs.rmSync(STAGE_DIR, { recursive: true, force: true });

// ── 完成 ──────────────────────────────────────────────────────────────────────
const sizeBytes = fs.statSync(ZIP_PATH).size;
const sizeMb    = (sizeBytes / 1024 / 1024).toFixed(2);

console.log('\n✅ 打包完成！');
console.log(`   → ${ZIP_PATH}`);
console.log(`   檔案大小：${sizeMb} MB`);

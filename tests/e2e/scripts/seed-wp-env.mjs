#!/usr/bin/env node

/**
 * wp-env 測試環境 Seed Script
 *
 * 透過 WP-CLI 為全新的 wp-env 環境注入 E2E 測試所需的資料：
 * 1. WooCommerce 基礎設定（幣別、國家、商店地址、頁面）
 * 2. 測試商品（低價 + 高價分期用）
 * 3. Woomp 模組啟用（14 個 options）
 * 4. PayUni Sandbox 憑證（從 .env 讀取）
 * 5. PayUni 信用卡 V3 閘道啟用
 * 6. WC REST API Key（寫回 .env）
 *
 * 使用方式：
 *   npm run env:seed
 *   或 node scripts/seed-wp-env.mjs
 *
 * 前置條件：
 *   - wp-env 已啟動（npm run env:start）
 *   - .env 已填入 PAYUNI_MERCHANT_ID / PAYUNI_HASH_KEY / PAYUNI_HASH_IV
 */

import { execSync } from 'node:child_process';
import { readFileSync, writeFileSync, unlinkSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PLUGIN_ROOT = resolve(__dirname, '../../..');
const ENV_PATH = resolve(PLUGIN_ROOT, '.env');
const TEMP_PHP = resolve(PLUGIN_ROOT, '_seed-temp.php');

// ── Helpers ─────────────────────────────────────

function wp(cmd) {
  const full = `npx @wordpress/env run cli -- wp ${cmd}`;
  console.log(`  $ ${full}`);
  try {
    const output = execSync(full, {
      encoding: 'utf-8',
      stdio: ['pipe', 'pipe', 'pipe'],
      cwd: PLUGIN_ROOT,
      timeout: 60_000,
    });
    return output.trim();
  } catch (err) {
    console.error(`  ✗ 失敗：${err.stderr?.trim() || err.message}`);
    throw err;
  }
}

/**
 * 透過臨時檔案執行 PHP 程式碼（繞過 Windows/Docker 多層引號問題）
 * 臨時檔案寫入 plugin 目錄（已掛載到 Docker 容器），用 wp eval-file 執行
 */
function wpEval(phpCode) {
  writeFileSync(TEMP_PHP, `<?php ${phpCode}`, 'utf-8');
  try {
    return wp('eval-file /var/www/html/wp-content/plugins/woomp/_seed-temp.php');
  } finally {
    try { unlinkSync(TEMP_PHP); } catch {}
  }
}

function readEnv() {
  try {
    const content = readFileSync(ENV_PATH, 'utf-8');
    const env = {};
    for (const line of content.split('\n')) {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith('#')) continue;
      const [key, ...rest] = trimmed.split('=');
      env[key.trim()] = rest.join('=').trim();
    }
    return env;
  } catch {
    return {};
  }
}

function writeEnvKey(key, value) {
  let content = '';
  try {
    content = readFileSync(ENV_PATH, 'utf-8');
  } catch {
    content = '';
  }

  const regex = new RegExp(`^${key}=.*$`, 'm');
  if (regex.test(content)) {
    content = content.replace(regex, `${key}=${value}`);
  } else {
    content += `\n${key}=${value}`;
  }
  writeFileSync(ENV_PATH, content, 'utf-8');
}

// ── Main ────────────────────────────────────────

console.log('\n🚀 Woomp wp-env Seed Script\n');
const env = readEnv();

// Step 0: 安裝繁體中文語系（Woomp 為台灣市場外掛，需 zh_TW）
console.log('🌐 Step 0: 安裝繁體中文語系');
try {
  wp('language core install zh_TW --activate');
  wp('language plugin install woocommerce zh_TW');
  console.log('  ✓ zh_TW 語系已安裝並啟用\n');
} catch {
  console.log('  ⚠ 語系安裝失敗，使用英文介面\n');
}

// Step 1: WooCommerce 基礎設定
console.log('📦 Step 1: WooCommerce 基礎設定');
wp('option update woocommerce_currency TWD');
wp('option update woocommerce_default_country TW');
wp('option update woocommerce_calc_taxes no');
wp('option update woocommerce_store_postcode 100');
wp('option update woocommerce_store_city "台北市"');
wp('option update woocommerce_store_address "測試路1號"');
wp('option update woocommerce_allowed_countries specific');
// 限制僅允許台灣（用 wpEval 繞過引號問題）
try {
  wpEval('update_option("woocommerce_specific_allowed_countries", array("TW"));');
} catch {
  console.log('  ⚠ 設定允許國家失敗，使用 woocommerce_default_country=TW 作為替代');
}

// 建立 WC 預設頁面（商店/購物車/結帳/我的帳戶）
try {
  wp('wc tool run install_pages --user=1');
} catch {
  console.log('  ⚠ install_pages 可能已執行過，跳過');
}

// 設定經典結帳（非 Block Checkout）— wp-env 預設用 Block Checkout，需改回 shortcode
try {
  wpEval(`
    $checkout_id = wc_get_page_id('checkout');
    if ($checkout_id > 0) {
      wp_update_post(array('ID' => $checkout_id, 'post_content' => '[woocommerce_checkout]'));
      echo "checkout page $checkout_id -> classic shortcode";
    } else {
      echo "checkout page not found";
    }
  `);
} catch {
  console.log('  ⚠ 經典結帳頁設定失敗，跳過');
}

console.log('  ✓ WooCommerce 基礎設定完成\n');

// Step 2: 建立測試商品
console.log('🛍️  Step 2: 建立測試商品');

const productId = wp(
  'wc product create --name="T-Shirt with Logo" --regular_price=10 --status=publish --porcelain --user=1'
);
console.log(`  ✓ 低價商品 ID: ${productId}`);
writeEnvKey('PRODUCT_ID', productId);

const productInstallmentId = wp(
  'wc product create --name="E2E Frontend Test Course" --regular_price=1500 --status=publish --virtual=true --porcelain --user=1'
);
console.log(`  ✓ 高價商品 ID: ${productInstallmentId}\n`);
writeEnvKey('PRODUCT_INSTALLMENT_ID', productInstallmentId);

// Step 3: 啟用 Woomp 模組
console.log('🔌 Step 3: 啟用 Woomp 模組（14 options）');

const woompModules = [
  'wc_woomp_enabled_payuni_gateway',
  'wc_woomp_enabled_ecpay_invoice',
  'wc_woomp_enabled_ezpay_invoice',
  'wc_woomp_setting_paynow_gateway',
  'wc_woomp_setting_paynow_shipping',
  'wc_settings_tab_active_paynow_einvoice',
  'wc_woomp_setting_tw_field_valitdate',
  'wc_woomp_setting_tw_address',
  'ry_wt_enabled_ecpay_gateway',
  'ry_wt_enabled_ecpay_shipping',
  'ry_wt_enabled_newebpay_gateway',
  'ry_wt_enabled_newebpay_shipping',
  'ry_wt_enabled_smilepay_gateway',
  'ry_wt_enabled_smilepay_shipping',
];

for (const opt of woompModules) {
  wp(`option update ${opt} yes`);
}
console.log('  ✓ 14 個模組已啟用\n');

// Step 4: 注入 PayUni Sandbox 憑證
console.log('🔑 Step 4: PayUni Sandbox 憑證');

const merchantId = env.PAYUNI_MERCHANT_ID;
const hashKey = env.PAYUNI_HASH_KEY;
const hashIv = env.PAYUNI_HASH_IV;

if (!merchantId || !hashKey || !hashIv) {
  console.log('  ⚠ .env 缺少 PAYUNI_MERCHANT_ID / PAYUNI_HASH_KEY / PAYUNI_HASH_IV');
  console.log('  ⚠ 跳過 PayUni 憑證注入，付款矩陣測試將無法執行\n');
} else {
  wp('option update payuni_payment_testmode yes');
  wp(`option update payuni_payment_merchant_no_test "${merchantId}"`);
  wp(`option update payuni_payment_hash_key_test "${hashKey}"`);
  wp(`option update payuni_payment_hash_iv_test "${hashIv}"`);
  console.log(`  ✓ MerchantID: ${merchantId}\n`);
}

// Step 5: 啟用 PayUni 信用卡 V3 閘道（完整設定）
console.log('💳 Step 5: 啟用 PayUni 信用卡 V3 閘道');

// 用 wpEval 一次寫入完整設定（含分期選項、記憶卡、載具）
try {
  wpEval(`
    update_option("woocommerce_payuni-credit-v3_settings", array(
      "enabled" => "yes",
      "title" => "統一金流 PAYUNi 信用卡 v3",
      "description" => "",
      "enable_tokenization" => "yes",
      "installment_options" => array("3", "6", "9", "12", "18", "24", "30"),
      "enable_invoice_carrier" => "yes",
    ));
  `);
} catch {
  console.log('  ⚠ PayUni 閘道設定失敗，請手動在後台啟用');
}

console.log('  ✓ PayUni 信用卡 V3 閘道已啟用\n');

// Step 6: 建立 WC REST API Key
console.log('🔐 Step 6: 建立 WC REST API Key');

try {
  const keyOutput = wp(
    'wc customer_key create --description="E2E Seed" --permissions=read_write --user=1 --porcelain'
  );

  // WP-CLI wc customer_key 輸出格式可能不同，嘗試解析
  // 若無法取得 key/secret，用 eval 方式建立
  if (keyOutput) {
    console.log(`  ✓ API Key 建立成功`);
  }
} catch {
  // customer_key 子命令可能不支援，用 wpEval 建立
  console.log('  ⚠ wc customer_key 不支援，改用 PHP eval-file 建立 API Key');
  try {
    const phpResult = wpEval(`
      global $wpdb;
      $key = "ck_" . wc_rand_hash();
      $secret = "cs_" . wc_rand_hash();
      $wpdb->insert($wpdb->prefix . "woocommerce_api_keys", [
        "user_id" => 1,
        "description" => "E2E Seed",
        "permissions" => "read_write",
        "consumer_key" => wc_api_hash($key),
        "consumer_secret" => $secret,
        "truncated_key" => substr($key, -7),
      ]);
      echo json_encode(["key" => $key, "secret" => $secret]);
    `);

    const parsed = JSON.parse(phpResult);
    if (parsed.key && parsed.secret) {
      writeEnvKey('WC_API_KEY', parsed.key);
      writeEnvKey('WC_API_SECRET', parsed.secret);
      console.log(`  ✓ API Key: ${parsed.key.substring(0, 10)}...`);
      console.log(`  ✓ API Secret: ${parsed.secret.substring(0, 10)}...`);
    }
  } catch (evalErr) {
    console.log('  ✗ API Key 建立失敗，請手動執行 00-setup 測試');
    console.log(`    ${evalErr.message}`);
  }
}

// Step 7: 建立運送區域（台灣本島）
console.log('\n📍 Step 7: 建立運送區域');
try {
  const zoneId = wp(
    'wc shipping_zone create --name="台灣本島" --order=0 --porcelain --user=1'
  );
  wp(`wc shipping_zone_method create ${zoneId} --method_id=flat_rate --user=1`);
  // 加入台灣區域限制（用 wpEval 繞過引號問題）
  wpEval(`
    global $wpdb;
    $wpdb->insert($wpdb->prefix . "woocommerce_shipping_zone_locations", [
      "zone_id" => ${zoneId},
      "location_code" => "TW",
      "location_type" => "country",
    ]);
  `);
  console.log(`  ✓ 運送區域 ID: ${zoneId}\n`);
} catch {
  console.log('  ⚠ 運送區域建立失敗（可能已存在），跳過\n');
}

// Step 8: 設定站台 URL（若 .env 有 TEST_SITE_URL 則同步到 wp-config）
console.log('🌐 Step 8: 站台 URL 設定');
const testSiteUrl = env.TEST_SITE_URL;
if (testSiteUrl && testSiteUrl !== 'http://localhost:8888') {
  try {
    wp(`config set WP_SITEURL "${testSiteUrl}" --type=constant`);
    wp(`config set WP_HOME "${testSiteUrl}" --type=constant`);
    wp('config set WP_DEBUG_DISPLAY false --raw');
    console.log(`  ✓ WP_SITEURL / WP_HOME → ${testSiteUrl}\n`);
  } catch {
    console.log('  ⚠ 站台 URL 設定失敗，請手動執行 wp config set\n');
  }
} else {
  console.log('  ⚠ TEST_SITE_URL 未設定或為 localhost:8888，跳過\n');
}

// ── Done ────────────────────────────────────────

console.log('══════════════════════════════════════════════');
console.log('✅ Seed 完成！');
console.log('');
console.log('下一步：');
console.log('  1. 啟動 Cloudflare Tunnel:');
console.log('     cloudflared tunnel run woomp-test');
console.log('');
console.log('  2. 確認 .env 設定:');
console.log(`     TEST_SITE_URL=<your-tunnel-url>`);
console.log(`     PRODUCT_ID=${productId}`);
console.log(`     PRODUCT_INSTALLMENT_ID=${productInstallmentId}`);
console.log('');
console.log('  3. 執行測試:');
console.log('     npm run test:payuni-matrix:headed');
console.log('══════════════════════════════════════════════\n');

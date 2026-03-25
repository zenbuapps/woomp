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
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ENV_PATH = resolve(__dirname, '../../../.env');

// ── Helpers ─────────────────────────────────────

function wp(cmd) {
  const full = `npx @wordpress/env run cli -- wp ${cmd}`;
  console.log(`  $ ${full}`);
  try {
    const output = execSync(full, {
      encoding: 'utf-8',
      stdio: ['pipe', 'pipe', 'pipe'],
      cwd: resolve(__dirname, '../../..'),
      timeout: 60_000,
    });
    return output.trim();
  } catch (err) {
    console.error(`  ✗ 失敗：${err.stderr?.trim() || err.message}`);
    throw err;
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

// Step 1: WooCommerce 基礎設定
console.log('📦 Step 1: WooCommerce 基礎設定');
wp('option update woocommerce_currency TWD');
wp('option update woocommerce_default_country TW');
wp('option update woocommerce_calc_taxes no');
wp('option update woocommerce_store_postcode 100');
wp('option update woocommerce_store_city "台北市"');
wp('option update woocommerce_store_address "測試路1號"');
wp('option update woocommerce_allowed_countries specific');
wp('option update woocommerce_specific_allowed_countries --format=json \'["TW"]\'');

// 建立 WC 預設頁面（商店/購物車/結帳/我的帳戶）
try {
  wp('wc tool run install_pages --user=1');
} catch {
  console.log('  ⚠ install_pages 可能已執行過，跳過');
}

// 設定經典結帳（非 Block Checkout）
wp('option update woocommerce_checkout_page_id $(wp post list --post_type=page --name=checkout --field=ID)');

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

// Step 5: 啟用 PayUni 信用卡 V3 閘道
console.log('💳 Step 5: 啟用 PayUni 信用卡 V3 閘道');

// PayUni 閘道啟用：設定 woocommerce_payuni-credit-v3_settings option
// 這是一個序列化的陣列，需用 wp option patch
try {
  wp('option patch insert woocommerce_payuni-credit-v3_settings enabled yes');
} catch {
  // option 可能尚不存在，用 update 建立
  wp('option update woocommerce_payuni-credit-v3_settings --format=json \'{"enabled":"yes","title":"PayUni 信用卡"}\'');
}

// 同時啟用載具功能
try {
  wp('option patch insert woocommerce_payuni-credit-v3_settings enable_invoice_carrier yes');
} catch {
  // 若 patch 失敗，已在上方 update 中處理
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
  // customer_key 子命令可能不支援，用 PHP eval 方式建立
  console.log('  ⚠ wc customer_key 不支援，改用 PHP eval 建立 API Key');
  try {
    const phpResult = wp(`eval '
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
    '`);

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
  // 加入台灣區域限制
  wp(`eval '
    global $wpdb;
    $wpdb->insert($wpdb->prefix . "woocommerce_shipping_zone_locations", [
      "zone_id" => ${zoneId},
      "location_code" => "TW",
      "location_type" => "country",
    ]);
  '`);
  console.log(`  ✓ 運送區域 ID: ${zoneId}\n`);
} catch {
  console.log('  ⚠ 運送區域建立失敗（可能已存在），跳過\n');
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

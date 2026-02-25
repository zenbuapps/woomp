/**
 * PayUni v3 UNi Embed E2E 自動化測試腳本
 *
 * 使用方式：
 *   npx tsx payuni-checkout.ts                               # 直接啟動瀏覽器
 *   npx tsx payuni-checkout.ts --cdp-endpoint=http://localhost:9222  # 連線現有 Chrome
 *   CDP_ENDPOINT=http://localhost:9222 npx tsx payuni-checkout.ts    # 環境變數方式
 */

import { chromium, Browser, BrowserContext, Page } from 'playwright';
import * as fs from 'fs';
import * as path from 'path';

// ─── 設定 ────────────────────────────────────────────────────────────────────

const BASE_URL = 'https://payuni-test.powerhouse.tw';
const ADD_TO_CART_URL = `${BASE_URL}/?add-to-cart=73`; // Album, NT$15, simple product
const CHECKOUT_URL = `${BASE_URL}/checkout/`;
const SCREENSHOTS_DIR = path.join(__dirname, 'screenshots');

const CARD_NO = '4147631000000001';
const CARD_EXP = '1228';
const CARD_CVC = '123';

// ─── 工具函式 ─────────────────────────────────────────────────────────────────

function log(msg: string) {
  const time = new Date().toLocaleTimeString('zh-TW');
  console.log(`[PayUni E2E ${time}] ${msg}`);
}

function ensureScreenshotsDir() {
  if (!fs.existsSync(SCREENSHOTS_DIR)) {
    fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });
  }
}

async function screenshot(page: Page, name: string) {
  ensureScreenshotsDir();
  const file = path.join(SCREENSHOTS_DIR, `${name}-${Date.now()}.png`);
  await page.screenshot({ path: file, fullPage: false });
  log(`📸 截圖已儲存：${file}`);
}

async function retry<T>(
  fn: () => Promise<T>,
  times = 3,
  delayMs = 2000,
  label = 'operation'
): Promise<T> {
  for (let i = 1; i <= times; i++) {
    try {
      return await fn();
    } catch (err) {
      if (i === times) throw err;
      log(`⚠️  ${label} 失敗（第 ${i} 次），${delayMs / 1000} 秒後重試...`);
      await new Promise(r => setTimeout(r, delayMs));
    }
  }
  throw new Error(`${label} 重試 ${times} 次後仍失敗`);
}

// ─── 主流程 ──────────────────────────────────────────────────────────────────

async function main() {
  // 解析 CDP endpoint（CLI 參數 或 環境變數）
  const cdpArg = process.argv.find(a => a.startsWith('--cdp-endpoint='));
  const cdpEndpoint = cdpArg
    ? cdpArg.split('=').slice(1).join('=')
    : process.env.CDP_ENDPOINT;

  let browser: Browser;
  let usingCdp = false;

  if (cdpEndpoint) {
    log(`🔗 CDP 模式：連線至 ${cdpEndpoint}`);
    try {
      browser = await chromium.connectOverCDP(cdpEndpoint);
      usingCdp = true;
    } catch (err) {
      log(`⚠️  CDP 連線失敗（${String(err)}），fallback 到直接啟動瀏覽器`);
      browser = await chromium.launch({ headless: false, slowMo: 300 });
    }
  } else {
    log('🚀 直接啟動瀏覽器（headless: false）');
    browser = await chromium.launch({ headless: false, slowMo: 300 });
  }

  const context: BrowserContext = await browser.newContext({
    viewport: { width: 1280, height: 900 },
  });
  const page: Page = await context.newPage();

  try {
    // Step 1: 加入商品至購物車
    log('🛒 加入商品至購物車（Album NT$15）...');
    await page.goto(ADD_TO_CART_URL, { waitUntil: 'networkidle' });
    log(`✓ 已加入商品`);

    // Step 2: 前往結帳頁
    log('📄 前往結帳頁...');
    await page.goto(CHECKOUT_URL, { waitUntil: 'domcontentloaded' });

    // Step 3: 填寫帳單資訊（WooCommerce 必填欄位）
    log('📝 填寫帳單資訊...');
    await page.waitForSelector('#billing_first_name', { timeout: 20_000 });
    // 先清空再填入，避免既有值干擾
    await page.locator('#billing_first_name').fill('Test');
    await page.locator('#billing_last_name').fill('User');
    await page.locator('#billing_phone').fill('0912345678');
    await page.locator('#billing_email').fill('test@example.com');
    log('✓ 帳單資訊已填寫');

    // Step 4: 等待 WooCommerce 付款區塊載入，選取 PayUni v3
    log('⏳ 等待付款方式清單載入...');
    // WooCommerce 付款方式由 AJAX 渲染，需等待 #payment 區塊出現
    await page.waitForSelector('#payment .payment_methods', { timeout: 30_000 });
    // 等待網路空閒，確保所有 AJAX 完成
    await page.waitForLoadState('networkidle');

    log('💳 選取「統一金流 PAYUNi 信用卡 v3」...');
    const payuniRadio = page.locator('input[name="payment_method"][value="payuni-credit-v3"]');
    const isChecked = await payuniRadio.isChecked().catch(() => false);
    if (!isChecked) {
      await payuniRadio.click();
      await page.waitForTimeout(2000); // 等待 updated_checkout 重繪 DOM
    }
    log('✓ 已選取 PayUni v3');

    // Step 4: 等待三個卡片 iframe 全部載入
    log('⏳ 等待 SDK iframe 載入...');
    await page.waitForSelector('iframe[src*="query=CardNo"]', { timeout: 15_000 });
    await page.waitForSelector('iframe[src*="query=CardExp"]', { timeout: 15_000 });
    await page.waitForSelector('iframe[src*="query=CardCvc"]', { timeout: 15_000 });
    log('✓ 所有 iframe 已載入');

    // Step 6: 填寫卡號（cross-origin iframe，使用 frameLocator）
    // 注意：PayUni SDK 監聽鍵盤事件驗證，必須用 pressSequentially() 而非 fill()
    log('💳 填寫卡號資訊...');

    await retry(async () => {
      const cardInput = page.frameLocator('iframe[src*="query=CardNo"]').locator('input');
      await cardInput.click();
      await cardInput.pressSequentially(CARD_NO, { delay: 50 });
    }, 3, 2000, '填寫卡號');

    await retry(async () => {
      const expInput = page.frameLocator('iframe[src*="query=CardExp"]').locator('input');
      await expInput.click();
      await expInput.pressSequentially(CARD_EXP, { delay: 50 });
    }, 3, 2000, '填寫到期日');

    await retry(async () => {
      const cvcInput = page.frameLocator('iframe[src*="query=CardCvc"]').locator('input');
      await cvcInput.click();
      await cvcInput.pressSequentially(CARD_CVC, { delay: 50 });
    }, 3, 2000, '填寫 CVC');

    log('✓ 卡號資訊已填寫完成');

    // Step 7: 等待 SDK debounce 驗證
    log('⏳ 等待 SDK 驗證...');
    await page.waitForTimeout(3000);
    await screenshot(page, 'before-submit');

    // Step 7: 點擊下單購買
    log('🔄 提交訂單...');
    const placeOrderBtn = page.locator('#place_order');
    await placeOrderBtn.click();

    // Step 8: 等待跳轉至 order-received 頁面
    log('⏳ 等待付款結果（最長 60 秒）...');
    await page.waitForURL('**/order-received/**', { timeout: 60_000 });

    // Step 9: 驗證成功
    const pageTitle = await page.title();
    const pageUrl = page.url();
    log(`✅ 付款成功！`);
    log(`   訂單 URL：${pageUrl}`);
    log(`   頁面標題：${pageTitle}`);
    await screenshot(page, 'success');

    // 檢查是否有錯誤通知
    const errorNotices = await page.locator('.woocommerce-error').count();
    if (errorNotices > 0) {
      const errorText = await page.locator('.woocommerce-error').textContent();
      log(`⚠️  頁面有錯誤通知：${errorText}`);
    }

    process.exit(0);
  } catch (err) {
    log(`❌ 付款失敗：${String(err)}`);
    await screenshot(page, 'error').catch(() => {});
    process.exit(1);
  } finally {
    await context.close();
    // CDP 模式：不關閉瀏覽器（保持現有 session）
    if (!usingCdp) {
      await browser.close();
    } else {
      log('ℹ️  CDP 模式：保持瀏覽器開啟，僅關閉測試頁面');
    }
  }
}

main();

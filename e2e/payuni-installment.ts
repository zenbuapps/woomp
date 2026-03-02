/**
 * PayUni v3 信用卡分期付款 E2E 自動化測試腳本
 *
 * 測試情境：SDK token 模式（已記憶卡號）+ 選擇 3 期分期 + 輸入 CVC → 確認訂單完成
 *
 * 使用方式：
 *   npx tsx payuni-installment.ts                               # 直接啟動瀏覽器
 *   npx tsx payuni-installment.ts --cdp-endpoint=http://localhost:9222  # 連線現有 Chrome
 *   CDP_ENDPOINT=http://localhost:9222 npx tsx payuni-installment.ts    # 環境變數方式
 */

import { chromium, Browser, BrowserContext, Page, CDPSession } from 'playwright';
import * as fs from 'fs';
import * as path from 'path';

// ─── 設定 ────────────────────────────────────────────────────────────────────

const BASE_URL = 'https://payuni-test.powerhouse.tw';
const ADD_TO_CART_URL = `${BASE_URL}/?add-to-cart=73`; // Album, NT$15, simple product
const CHECKOUT_URL = `${BASE_URL}/checkout/`;
const SCREENSHOTS_DIR = path.join(__dirname, 'screenshots');

// 測試卡號（PayUni Sandbox SDK 會自動識別為 token 模式）
const CARD_NO = '4147631000000001';
const CARD_EXP = '1228';
const CARD_CVC = '123';

// 分期期數
const INSTALLMENT = '3';

// ─── 工具函式 ─────────────────────────────────────────────────────────────────

function log(msg: string) {
  const time = new Date().toLocaleTimeString('zh-TW');
  console.log(`[PayUni 分期 E2E ${time}] ${msg}`);
}

function ensureScreenshotsDir() {
  if (!fs.existsSync(SCREENSHOTS_DIR)) {
    fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });
  }
}

async function screenshot(page: Page, name: string) {
  ensureScreenshotsDir();
  const file = path.join(SCREENSHOTS_DIR, `installment-${name}-${Date.now()}.png`);
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

  const context: BrowserContext = usingCdp
    ? browser.contexts()[0]
    : await browser.newContext({ viewport: { width: 1280, height: 900 } });

  const page: Page = await context.newPage();

  // 停用快取，確保使用最新版 JS
  let cdpSession: CDPSession | null = null;
  try {
    cdpSession = await page.context().newCDPSession(page);
    await cdpSession.send('Network.setCacheDisabled', { cacheDisabled: true });
    log('🚫 已停用瀏覽器快取');
  } catch (e) {
    log('⚠️  無法停用快取（CDP session 失敗），繼續執行');
  }

  try {
    // Step 1: 加入商品至購物車
    log('🛒 加入商品至購物車（Album NT$15）...');
    await page.goto(ADD_TO_CART_URL, { waitUntil: 'networkidle' });
    log('✓ 已加入商品');

    // Step 2: 前往結帳頁
    log('📄 前往結帳頁...');
    await page.goto(CHECKOUT_URL, { waitUntil: 'domcontentloaded' });

    // Step 3: 填寫帳單資訊
    log('📝 填寫帳單資訊...');
    await page.waitForSelector('#billing_first_name', { timeout: 20_000 });
    await page.locator('#billing_first_name').fill('Test');
    await page.locator('#billing_last_name').fill('User');
    await page.locator('#billing_phone').fill('0912345678');
    await page.locator('#billing_email').fill('test@example.com');
    log('✓ 帳單資訊已填寫');

    // Step 4: 等待付款方式載入，選取 PayUni v3
    log('⏳ 等待付款方式清單載入...');
    await page.waitForSelector('#payment .payment_methods', { timeout: 30_000 });
    await page.waitForLoadState('networkidle');

    log('💳 選取「統一金流 PAYUNi 信用卡 v3」...');
    const payuniRadio = page.locator('input[name="payment_method"][value="payuni-credit-v3"]');
    const isChecked = await payuniRadio.isChecked().catch(() => false);
    if (!isChecked) {
      await payuniRadio.click();
      await page.waitForTimeout(2000);
    }
    log('✓ 已選取 PayUni v3');

    // Step 5: 等待 SDK iframe 載入
    log('⏳ 等待 SDK iframe 載入...');
    await page.waitForSelector('iframe[src*="query=CardNo"]', { timeout: 15_000 });
    await page.waitForSelector('iframe[src*="query=CardExp"]', { timeout: 15_000 });
    await page.waitForSelector('iframe[src*="query=CardCvc"]', { timeout: 15_000 });
    log('✓ 所有 iframe 已載入');

    // Step 6: 填寫卡號 → SDK 自動識別為 token 模式
    log(`💳 填寫卡號（${CARD_NO}）→ SDK 將識別為已記憶卡號...`);
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

    // 等待 SDK token 識別（useTokenType 事件）
    await page.waitForTimeout(2000);
    log('✓ 卡號已填寫，等待 SDK token 識別...');

    // Step 7: 選擇分期期數（3 期）
    log(`📋 選擇分期期數：${INSTALLMENT} 期...`);
    const installmentSelect = page.locator('select#payuni_installment, select[name="payuni_installment"]');
    await installmentSelect.waitFor({ state: 'visible', timeout: 10_000 });
    await installmentSelect.selectOption(INSTALLMENT);
    const selectedValue = await installmentSelect.inputValue();
    log(`✓ 已選擇分期期數：${selectedValue} 期`);
    if (selectedValue !== INSTALLMENT) {
      throw new Error(`分期選擇失敗：期望 ${INSTALLMENT}，實際 ${selectedValue}`);
    }

    // Step 8: 輸入 CVC（token 模式下只需要 CVC）
    log('🔒 填寫 CVC...');
    await retry(async () => {
      const cvcFrame = page.frameLocator('iframe[src*="query=CardCvc"]');
      const cvcInput = cvcFrame.locator('input');
      await cvcInput.click();
      // SDK CVC iframe 需要慢速逐字輸入
      for (const char of CARD_CVC) {
        await page.keyboard.type(char, { delay: 100 });
      }
    }, 3, 2000, '填寫 CVC');
    log('✓ CVC 已填寫');

    // Step 9: 等待 SDK 驗證完成
    log('⏳ 等待 SDK 驗證...');
    await page.waitForTimeout(3000);
    await screenshot(page, 'before-submit');

    // Step 10: 提交訂單
    log('🔄 提交訂單（分期 3 期）...');
    await page.locator('#place_order').click();

    // Step 11: 等待訂單確認頁
    log('⏳ 等待付款結果（最長 60 秒）...');
    await page.waitForURL('**/order-received/**', { timeout: 60_000 });

    // Step 12: 驗證成功
    const pageUrl = page.url();
    const pageTitle = await page.title();
    log('✅ 分期付款訂單建立成功！');
    log(`   訂單 URL：${pageUrl}`);
    log(`   頁面標題：${pageTitle}`);
    await screenshot(page, 'success');

    // 確認訂單確認頁有成功訊息
    const orderConfirm = await page.locator('.woocommerce-order').count();
    if (orderConfirm === 0) {
      throw new Error('訂單確認區塊未出現');
    }

    const errorNotices = await page.locator('.woocommerce-error').count();
    if (errorNotices > 0) {
      const errorText = await page.locator('.woocommerce-error').textContent();
      log(`⚠️  頁面有錯誤通知：${errorText}`);
    }

    log('🎉 分期付款測試通過！');
    process.exit(0);
  } catch (err) {
    log(`❌ 測試失敗：${String(err)}`);
    await screenshot(page, 'error').catch(() => {});
    process.exit(1);
  } finally {
    if (cdpSession) {
      await cdpSession.send('Network.setCacheDisabled', { cacheDisabled: false }).catch(() => {});
    }
    await page.close();
    if (!usingCdp) {
      await context.close();
      await browser.close();
    } else {
      log('ℹ️  CDP 模式：保持瀏覽器開啟，僅關閉測試頁面');
    }
  }
}

main();

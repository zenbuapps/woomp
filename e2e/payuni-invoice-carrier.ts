import { test, expect, Page } from '@playwright/test';

const BASE_URL = 'https://payuni-test.powerhouse.tw';
const WP_USER = 'test';
const WP_PASS = 'test';

async function loginWordPress(page: Page) {
  await page.goto(`${BASE_URL}/wp-login.php`);
  await page.fill('#user_login', WP_USER);
  await page.fill('#user_pass', WP_PASS);
  await page.click('#wp-submit');
  await page.waitForURL(`${BASE_URL}/wp-admin/**`);
}

async function addProductToCart(page: Page) {
  await page.goto(`${BASE_URL}/shop/`);
  await page.waitForLoadState('networkidle');
  const addToCart = page.locator('.add_to_cart_button').first();
  await addToCart.click();
  await page.waitForTimeout(1500);
}

async function goToCheckout(page: Page) {
  await page.goto(`${BASE_URL}/checkout/`);
  await page.waitForLoadState('networkidle');
  const payuniRadio = page.locator('#payment_method_payuni-credit-v3');
  if (await payuniRadio.isVisible()) {
    await payuniRadio.check();
    await page.waitForTimeout(1000);
  }
}

async function fillCvcForSavedCard(page: Page) {
  await page.waitForTimeout(2000);
  const cardCvcFrame = page.frames().find(f => f.url().includes('query=CardCvc'));
  if (!cardCvcFrame) throw new Error('CardCvc iframe not found');
  const input = cardCvcFrame.locator('input');
  await input.click();
  await input.type('000', { delay: 100 });
  await page.waitForTimeout(300);
}

async function selectCarrier(page: Page, carrierType: string, carrierInfo?: string, buyerName?: string) {
  const carrierSelect = page.locator('select[name="payuni_carrier_type"]');
  await expect(carrierSelect).toBeVisible({ timeout: 5000 });
  await carrierSelect.selectOption(carrierType);
  await page.waitForTimeout(300);

  if (carrierInfo) {
    const infoInput = page.locator(`input[name="payuni_carrier_info_${carrierType}"]`);
    await infoInput.fill(carrierInfo);
  }

  if (buyerName) {
    const nameInput = page.locator('input[name="payuni_inv_buyer_name"]');
    if (await nameInput.isVisible()) {
      await nameInput.fill(buyerName);
    }
  }
}

test.describe('PayUni 發票載具整合 (Invoice Carrier)', () => {
  test.beforeEach(async ({ page }) => {
    await loginWordPress(page);
    await addProductToCart(page);
    await goToCheckout(page);
    // Wait for PayUni payment form
    await page.waitForSelector('#payment_method_payuni-credit-v3', { timeout: 10000 });
    await page.locator('#payment_method_payuni-credit-v3').check();
    await page.waitForTimeout(1000);
  });

  test('結帳頁應顯示發票載具選擇下拉選單', async ({ page }) => {
    const carrierSelect = page.locator('select[name="payuni_carrier_type"]');
    await expect(carrierSelect).toBeVisible();

    // 驗證所有選項存在
    await expect(carrierSelect.locator('option[value=""]')).toHaveCount(1);
    await expect(carrierSelect.locator('option[value="3J0002"]')).toHaveCount(1);
    await expect(carrierSelect.locator('option[value="CQ0001"]')).toHaveCount(1);
    await expect(carrierSelect.locator('option[value="amego"]')).toHaveCount(1);
    await expect(carrierSelect.locator('option[value="Donate"]')).toHaveCount(1);
    await expect(carrierSelect.locator('option[value="Company"]')).toHaveCount(1);
  });

  test('選擇手機條碼載具後應顯示輸入框', async ({ page }) => {
    const carrierSelect = page.locator('select[name="payuni_carrier_type"]');
    await carrierSelect.selectOption('3J0002');
    await page.waitForTimeout(300);

    const infoInput = page.locator('input[name="payuni_carrier_info_3J0002"]');
    await expect(infoInput).toBeVisible();
    expect(await infoInput.getAttribute('placeholder')).toBeTruthy();
  });

  test('選擇會員載具（amego）後不應顯示識別碼輸入框', async ({ page }) => {
    const carrierSelect = page.locator('select[name="payuni_carrier_type"]');
    await carrierSelect.selectOption('amego');
    await page.waitForTimeout(300);

    const infoInput = page.locator('input[name="payuni_carrier_info"]');
    const isVisible = await infoInput.isVisible().catch(() => false);
    expect(isVisible).toBe(false);
  });

  test('手機條碼載具：付款成功', async ({ page }) => {
    // 選擇手機條碼載具
    await selectCarrier(page, '3J0002', '/ABC1234', '測試用戶');

    // 填入 CVC（使用已存卡）
    await fillCvcForSavedCard(page);

    // 下單
    await page.click('#place_order');
    await page.waitForURL(`${BASE_URL}/checkout/order-received/**`, { timeout: 30000 });

    // 確認付款成功（可能顯示「已收到訂單」或「已完成的訂單」）
    const heading = page.locator('h1, .woocommerce-order-received h1');
    await expect(heading).toHaveText(/(已收到訂單|已完成的訂單)/, { timeout: 10000 });
  });

  test('不使用載具（紙本發票）：付款成功，不影響一般付款流程', async ({ page }) => {
    // 保持預設「不使用載具」
    const carrierSelect = page.locator('select[name="payuni_carrier_type"]');
    await expect(carrierSelect).toHaveValue('');

    // 填入 CVC（使用已存卡）
    await fillCvcForSavedCard(page);

    // 下單
    await page.click('#place_order');
    await page.waitForURL(`${BASE_URL}/checkout/order-received/**`, { timeout: 30000 });

    // 確認付款成功（可能顯示「已收到訂單」或「已完成的訂單」）
    const heading = page.locator('h1, .woocommerce-order-received h1');
    await expect(heading).toHaveText(/(已收到訂單|已完成的訂單)/, { timeout: 10000 });
  });
});

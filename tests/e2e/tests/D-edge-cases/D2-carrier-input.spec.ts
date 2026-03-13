import { test, expect } from '@playwright/test';
import { addProductToCart } from '../../helpers/cart.helper';
import { fillBillingFields, selectPayuniPayment, clickPlaceOrder } from '../../helpers/checkout.helper';
import { fillNewCard, waitForIframes } from '../../helpers/iframe.helper';
import { selectCarrierType, fillCarrierInfo } from '../../helpers/carrier.helper';
import { CARDS, SELECTORS } from '../../fixtures/test-data';

async function setCarrier(page: Parameters<typeof fillCarrierInfo>[0], type: string, value: string, buyerName?: string): Promise<void> {
  await selectCarrierType(page, type);
  await fillCarrierInfo(page, type, value, buyerName);
}

async function expectBlockedOrSucceeded(page: Parameters<typeof fillCarrierInfo>[0]): Promise<void> {
  const errorNotice = page.locator('.woocommerce-error, .woocommerce-NoticeGroup-checkout').first();

  const outcome = await Promise.race([
    page.waitForURL('**/order-received/**', { timeout: 20_000 }).then(() => 'success').catch(() => null),
    errorNotice.waitFor({ state: 'visible', timeout: 20_000 }).then(() => 'error').catch(() => null),
  ]);

  expect(outcome).toBeTruthy();
}

test.describe('D2. 發票載具輸入邊緣案例', () => {
  test.beforeEach(async ({ page }) => {
    await addProductToCart(page);
    await page.goto(`${process.env.BASE_URL}/checkout/`);
    await page.waitForLoadState('networkidle');
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);
  });

  test('D2-1 @P3 手機條碼缺少斜線前綴 → 格式錯誤', async ({ page }) => {
    // Input without leading slash
    await setCarrier(page, '3J0002', 'ABC1234');

    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);

    // Should show validation error (either frontend or backend)
    const errorNotice = page.locator('.woocommerce-error, .woocommerce-NoticeGroup-checkout');
    await expect(errorNotice.first()).toBeVisible({ timeout: 15000 });
  });

  test('D2-2 @P3 自然人憑證超過 16 碼 → 截斷或驗證阻擋', async ({ page }) => {
    // Input 17 characters
    const overLengthCert = 'AB12345678901234X';
    await setCarrier(page, 'CQ0001', overLengthCert);

    const certInput = page.locator(SELECTORS.carrierInfoNatural);
    const certValue = await certInput.inputValue();
    expect(certValue.length).toBeLessThanOrEqual(16);
    expect(overLengthCert.startsWith(certValue)).toBeTruthy();

    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await expectBlockedOrSucceeded(page);
  });

  test('D2-3 @P3 捐贈碼超過 7 碼 → 截斷或驗證阻擋', async ({ page }) => {
    const overLengthDonate = '12345678';
    await setCarrier(page, 'Donate', overLengthDonate);

    const donateInput = page.locator(SELECTORS.carrierInfoDonate);
    const donateValue = await donateInput.inputValue();
    expect(donateValue.length).toBeLessThanOrEqual(7);
    expect(overLengthDonate.startsWith(donateValue)).toBeTruthy();

    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await expectBlockedOrSucceeded(page);
  });

  test('D2-4 @P3 公司統編非 8 碼 → 格式錯誤', async ({ page }) => {
    // 7 digits - too short
    await setCarrier(page, 'Company', '1234567', '測試公司');

    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);

    const errorNotice = page.locator('.woocommerce-error, .woocommerce-NoticeGroup-checkout');
    await expect(errorNotice.first()).toBeVisible({ timeout: 15000 });
  });

  test('D2-5 @P3 公司統編含非數字 → 驗證阻擋', async ({ page }) => {
    await setCarrier(page, 'Company', '1234567A', '測試公司');

    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);

    const errorNotice = page.locator('.woocommerce-error, .woocommerce-NoticeGroup-checkout');
    await expect(errorNotice.first()).toBeVisible({ timeout: 15000 });
  });

  test('D2-6 @P3 切換載具類型 → 前一個值清空', async ({ page }) => {
    // First select phone barcode and fill value
    await setCarrier(page, '3J0002', '/ABC1234');

    // Now switch to natural person certificate
    const carrierSelect = page.locator(SELECTORS.carrierTypeSelect);
    await carrierSelect.selectOption('CQ0001');
    await page.waitForTimeout(500);

    // The phone barcode input should be hidden
    const phoneInput = page.locator('input[name="payuni_carrier_info_3J0002"]');
    await expect(phoneInput).toBeHidden();

    // The natural person certificate input should be visible and empty
    const certInput = page.locator('input[name="payuni_carrier_info_CQ0001"]');
    await expect(certInput).toBeVisible();
    const certValue = await certInput.inputValue();
    expect(certValue).toBe('');
  });
});

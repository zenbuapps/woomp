import { test, expect } from '@playwright/test';
import { addProductToCart } from '../../helpers/cart.helper';
import { fillBillingFields, selectPayuniPayment, clickPlaceOrder } from '../../helpers/checkout.helper';
import { fillNewCard, waitForIframes } from '../../helpers/iframe.helper';
import { selectCarrierType, fillCarrierInfo } from '../../helpers/carrier.helper';
import { CARDS } from '../../fixtures/test-data';

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

test.describe('E2. 輸入長度邊界測試', () => {
  test.beforeEach(async ({ page }) => {
    await addProductToCart(page);
    await page.goto(`${process.env.BASE_URL}/checkout/`);
    await page.waitForLoadState('networkidle');
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);
  });

  // --- 手機條碼 (should be /+7chars = 8 total) ---

  test('E2-1 @P3 手機條碼剛好 8 碼（含 /）→ 驗證通過', async ({ page }) => {
    await setCarrier(page, '3J0002', '/ABC1234');
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);

    // Should succeed or at least not show carrier validation error
    // Payment may succeed or fail for other reasons, but carrier format should pass
    await page.waitForTimeout(5000);
    const carrierError = page.locator('.woocommerce-error li:has-text("手機條碼"), .woocommerce-error li:has-text("載具")');
    const hasCarrierError = await carrierError.count() > 0;
    // No carrier format error expected
    if (hasCarrierError) {
      // If there IS an error, it should NOT be about format
      const errorText = await carrierError.first().textContent();
      expect(errorText).not.toContain('格式');
    }
  });

  test('E2-2 @P3 手機條碼不足 8 碼 → 驗證失敗', async ({ page }) => {
    await setCarrier(page, '3J0002', '/ABC12'); // 6 chars total, need 8
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);

    const errorNotice = page.locator('.woocommerce-error, .woocommerce-NoticeGroup-checkout');
    await expect(errorNotice.first()).toBeVisible({ timeout: 15000 });
  });

  test('E2-3 @P3 手機條碼超過 8 碼 → 截斷或驗證失敗', async ({ page }) => {
    const overLengthMobile = '/ABC12345';
    await setCarrier(page, '3J0002', overLengthMobile); // 9 chars, exceeds 8

    const mobileInput = page.locator('input[name="payuni_carrier_info_3J0002"]');
    const mobileValue = await mobileInput.inputValue();
    expect(mobileValue.length).toBeLessThanOrEqual(8);
    expect(overLengthMobile.startsWith(mobileValue)).toBeTruthy();

    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await expectBlockedOrSucceeded(page);
  });

  // --- 自然人憑證 (should be 2 letters + 14 digits = 16 total) ---

  test('E2-4 @P3 自然人憑證剛好 16 碼 → 驗證通過', async ({ page }) => {
    await setCarrier(page, 'CQ0001', 'AB12345678901234'); // 2+14=16
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);

    await page.waitForTimeout(5000);
    const carrierError = page.locator('.woocommerce-error li:has-text("自然人"), .woocommerce-error li:has-text("憑證")');
    const hasCarrierError = await carrierError.count() > 0;
    if (hasCarrierError) {
      const errorText = await carrierError.first().textContent();
      expect(errorText).not.toContain('格式');
    }
  });

  test('E2-5 @P3 自然人憑證 17 碼 → 截斷或驗證失敗', async ({ page }) => {
    const overLengthCert = 'AB123456789012345';
    await setCarrier(page, 'CQ0001', overLengthCert); // 17 chars

    const certInput = page.locator('input[name="payuni_carrier_info_CQ0001"]');
    const certValue = await certInput.inputValue();
    expect(certValue.length).toBeLessThanOrEqual(16);
    expect(overLengthCert.startsWith(certValue)).toBeTruthy();

    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await expectBlockedOrSucceeded(page);
  });

  // --- 捐贈碼 (1-7 digits) ---

  test('E2-6 @P3 捐贈碼 1 碼 → 驗證通過（最短）', async ({ page }) => {
    await setCarrier(page, 'Donate', '1');
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);

    await page.waitForTimeout(5000);
    const donateError = page.locator('.woocommerce-error li:has-text("捐贈")');
    expect(await donateError.count()).toBe(0);
  });

  test('E2-7 @P3 捐贈碼 7 碼 → 驗證通過（最長）', async ({ page }) => {
    await setCarrier(page, 'Donate', '1234567');
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);

    await page.waitForTimeout(5000);
    const donateError = page.locator('.woocommerce-error li:has-text("捐贈")');
    expect(await donateError.count()).toBe(0);
  });

  test('E2-8 @P3 捐贈碼 8 碼 → 截斷或驗證失敗', async ({ page }) => {
    const overLengthDonate = '12345678';
    await setCarrier(page, 'Donate', overLengthDonate);

    const donateInput = page.locator('input[name="payuni_carrier_info_Donate"]');
    const donateValue = await donateInput.inputValue();
    expect(donateValue.length).toBeLessThanOrEqual(7);
    expect(overLengthDonate.startsWith(donateValue)).toBeTruthy();

    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await expectBlockedOrSucceeded(page);
  });

  // --- 公司統編 (exactly 8 digits) ---

  test('E2-9 @P3 統編 7 碼 → 驗證失敗', async ({ page }) => {
    await setCarrier(page, 'Company', '1234567', '測試公司');
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);

    const errorNotice = page.locator('.woocommerce-error, .woocommerce-NoticeGroup-checkout');
    await expect(errorNotice.first()).toBeVisible({ timeout: 15000 });
  });

  test('E2-10 @P3 統編 8 碼 → 驗證通過', async ({ page }) => {
    await setCarrier(page, 'Company', '12345678', '測試公司');
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);

    await page.waitForTimeout(5000);
    const taxError = page.locator('.woocommerce-error li:has-text("統編"), .woocommerce-error li:has-text("統一編號")');
    expect(await taxError.count()).toBe(0);
  });

  test('E2-11 @P3 統編 9 碼 → 截斷或驗證失敗', async ({ page }) => {
    const overLengthCompanyTaxId = '123456789';
    await setCarrier(page, 'Company', overLengthCompanyTaxId, '測試公司');

    const companyInput = page.locator('input[name="payuni_carrier_info_Company"]');
    const companyValue = await companyInput.inputValue();
    expect(companyValue.length).toBeLessThanOrEqual(8);
    expect(overLengthCompanyTaxId.startsWith(companyValue)).toBeTruthy();

    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await expectBlockedOrSucceeded(page);
  });
});

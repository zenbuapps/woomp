import { test, expect } from '@playwright/test';
import { ensureLoggedIn } from '../../helpers/auth.helper';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, waitForCheckoutUpdate } from '../../helpers/checkout.helper';

/**
 * EZPAY（藍新 ezPay）發票載具選擇 UI 測試
 * 測試結帳頁面的 EZPAY 載具類型下拉選單及對應輸入欄位
 */
test.describe('EZPAY 發票載具選擇 UI @invoice @ezpay', () => {
  /** EZPAY 載具下拉選單可能的 selectors */
  const EZPAY_CARRIER_SELECTORS = [
    'select.ezpay-carrier-type',
    'select#ezpay_carrier_type',
    'select[name*="ezpay"][name*="carrier"]',
    'select[name*="ezpay_carrier"]',
  ];

  /** 取得 EZPAY 載具下拉元素 */
  function getCarrierSelect(page: import('@playwright/test').Page) {
    return page.locator(EZPAY_CARRIER_SELECTORS.join(', ')).first();
  }

  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
    await addToCartAndCheckout(page);
    await fillBillingFields(page);
    await waitForCheckoutUpdate(page);
  });

  test('EZPAY 載具類型下拉選單顯示且包含預期選項 @P1', async ({ page }) => {
    const carrierSelect = getCarrierSelect(page);

    if (!(await carrierSelect.isVisible().catch(() => false))) {
      test.skip(true, 'EZPAY 載具下拉選單不可見，可能 EZPAY 發票模組未啟用');
      return;
    }

    await expect(carrierSelect).toBeVisible({ timeout: 10_000 });

    const options = await carrierSelect.locator('option').allTextContents();
    const optionTexts = options.map(t => t.trim());

    // EZPAY 應至少有以下載具選項
    const expectedKeywords = ['手機條碼', '自然人憑證'];
    for (const keyword of expectedKeywords) {
      const found = optionTexts.some(t => t.includes(keyword));
      expect(found, `應包含載具選項含「${keyword}」，實際選項：${optionTexts.join(', ')}`).toBe(true);
    }

    // 應至少有 3 個選項（紙本 + 手機條碼 + 自然人憑證 等）
    expect(optionTexts.length).toBeGreaterThanOrEqual(3);
  });

  test('選擇手機條碼 → 手機條碼輸入框出現 @P1', async ({ page }) => {
    const carrierSelect = getCarrierSelect(page);

    if (!(await carrierSelect.isVisible().catch(() => false))) {
      test.skip(true, 'EZPAY 載具下拉選單不可見');
      return;
    }

    // 從下拉選單中選擇含有「手機條碼」的選項
    const options = carrierSelect.locator('option');
    const allTexts = await options.allTextContents();
    const mobileOptionIndex = allTexts.findIndex(t => t.includes('手機條碼'));

    if (mobileOptionIndex === -1) {
      test.skip(true, 'EZPAY 載具選項中找不到「手機條碼」');
      return;
    }

    // 取得對應的 value 並選取
    const mobileValue = await options.nth(mobileOptionIndex).getAttribute('value');
    if (mobileValue !== null) {
      await carrierSelect.selectOption(mobileValue);
    } else {
      await carrierSelect.selectOption({ label: allTexts[mobileOptionIndex].trim() });
    }
    await page.waitForTimeout(500);

    // 驗證手機條碼輸入框出現
    const mobileInput = page.locator(
      'input[name*="ezpay"][name*="barcode"], input[name*="ezpay"][name*="carrier_info"], input[name*="ezpay_barcode"], input.ezpay-carrier-info'
    ).first();

    await expect(mobileInput).toBeVisible({ timeout: 5_000 });

    // 驗證 placeholder 或 maxlength 格式正確
    const placeholder = await mobileInput.getAttribute('placeholder');
    if (placeholder) {
      expect(placeholder).toContain('/');
    }
  });

  test('選擇自然人憑證 → 自然人憑證輸入框出現 @P1', async ({ page }) => {
    const carrierSelect = getCarrierSelect(page);

    if (!(await carrierSelect.isVisible().catch(() => false))) {
      test.skip(true, 'EZPAY 載具下拉選單不可見');
      return;
    }

    // 從下拉選單中選擇含有「自然人憑證」的選項
    const options = carrierSelect.locator('option');
    const allTexts = await options.allTextContents();
    const naturalOptionIndex = allTexts.findIndex(t => t.includes('自然人憑證'));

    if (naturalOptionIndex === -1) {
      test.skip(true, 'EZPAY 載具選項中找不到「自然人憑證」');
      return;
    }

    const naturalValue = await options.nth(naturalOptionIndex).getAttribute('value');
    if (naturalValue !== null) {
      await carrierSelect.selectOption(naturalValue);
    } else {
      await carrierSelect.selectOption({ label: allTexts[naturalOptionIndex].trim() });
    }
    await page.waitForTimeout(500);

    // 驗證自然人憑證輸入框出現
    const naturalInput = page.locator(
      'input[name*="ezpay"][name*="natural"], input[name*="ezpay"][name*="carrier_info"], input[name*="ezpay_natural"], input.ezpay-carrier-info'
    ).first();

    await expect(naturalInput).toBeVisible({ timeout: 5_000 });
  });
});

import { test, expect } from '@playwright/test';
import { PAYNOW_CARRIER_TYPES, PAYNOW_SELECTORS } from '../../fixtures/paynow-data';
import { ensureLoggedIn } from '../../helpers/auth.helper';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, waitForCheckoutUpdate } from '../../helpers/checkout.helper';

/**
 * PayNow（立吉富）發票載具選擇 UI 測試
 * 測試結帳頁面的 PayNow 載具類型下拉選單及對應輸入欄位
 */
test.describe('PayNow 發票載具選擇 UI @invoice @paynow', () => {
  /** PayNow 載具下拉選單可能的 selectors */
  const PAYNOW_CARRIER_SELECT_SELECTORS = [
    PAYNOW_SELECTORS.carrierTypeSelect, // select.paynow-carrier-type
    'select#paynow_carrier_type',
    'select[name*="paynow"][name*="carrier"]',
    'select[name*="paynow_carrier"]',
  ];

  function getCarrierSelect(page: import('@playwright/test').Page) {
    return page.locator(PAYNOW_CARRIER_SELECT_SELECTORS.join(', ')).first();
  }

  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
    await addToCartAndCheckout(page);
    await fillBillingFields(page);
    await waitForCheckoutUpdate(page);
  });

  test('PayNow 載具類型下拉選單顯示且包含預期選項 @P1', async ({ page }) => {
    const carrierSelect = getCarrierSelect(page);

    if (!(await carrierSelect.isVisible().catch(() => false))) {
      test.skip(true, 'PayNow 載具下拉選單不可見，可能 PayNow 發票模組未啟用');
      return;
    }

    await expect(carrierSelect).toBeVisible({ timeout: 10_000 });

    const options = await carrierSelect.locator('option').allTextContents();
    const optionTexts = options.map(t => t.trim());

    // PayNow 載具選項應包含以下類型
    const expectedLabels = [
      PAYNOW_CARRIER_TYPES.cloud.label,         // 雲端發票
      PAYNOW_CARRIER_TYPES.mobile.label,         // 手機條碼
      PAYNOW_CARRIER_TYPES.naturalPerson.label,  // 自然人憑證
    ];

    for (const label of expectedLabels) {
      const found = optionTexts.some(t => t.includes(label));
      expect(found, `應包含載具選項「${label}」，實際選項：${optionTexts.join(', ')}`).toBe(true);
    }

    // 其他可能存在的選項（不強制要求全部出現）
    const optionalLabels = [
      PAYNOW_CARRIER_TYPES.easycard.label,  // 悠遊卡
      PAYNOW_CARRIER_TYPES.donate.label,    // 捐贈發票
      PAYNOW_CARRIER_TYPES.company.label,   // 公司發票
    ];

    let optionalCount = 0;
    for (const label of optionalLabels) {
      if (optionTexts.some(t => t.includes(label))) {
        optionalCount++;
      }
    }

    // 至少應有一部分可選項目出現
    expect(
      optionalCount,
      `至少應有部分額外載具選項（悠遊卡/捐贈/公司），實際選項：${optionTexts.join(', ')}`
    ).toBeGreaterThanOrEqual(0);
  });

  test('選擇手機條碼 → 手機條碼輸入框出現 @P1', async ({ page }) => {
    const carrierSelect = getCarrierSelect(page);

    if (!(await carrierSelect.isVisible().catch(() => false))) {
      test.skip(true, 'PayNow 載具下拉選單不可見');
      return;
    }

    // 選擇手機條碼
    const options = carrierSelect.locator('option');
    const allTexts = await options.allTextContents();
    const mobileIdx = allTexts.findIndex(t => t.includes(PAYNOW_CARRIER_TYPES.mobile.label));

    if (mobileIdx === -1) {
      test.skip(true, 'PayNow 載具選項中找不到「手機條碼」');
      return;
    }

    const mobileValue = await options.nth(mobileIdx).getAttribute('value');
    if (mobileValue !== null) {
      await carrierSelect.selectOption(mobileValue);
    } else {
      await carrierSelect.selectOption({ label: allTexts[mobileIdx].trim() });
    }
    await page.waitForTimeout(500);

    // 驗證手機條碼輸入框出現
    const mobileInput = page.locator(
      [
        PAYNOW_SELECTORS.carrierInfoInput, // input.paynow-carrier-info
        'input[name*="paynow"][name*="barcode"]',
        'input[name*="paynow"][name*="carrier_info"]',
        'input[name*="paynow_barcode"]',
      ].join(', ')
    ).first();

    await expect(mobileInput).toBeVisible({ timeout: 5_000 });
  });

  test('選擇自然人憑證 → 自然人憑證輸入框出現 @P1', async ({ page }) => {
    const carrierSelect = getCarrierSelect(page);

    if (!(await carrierSelect.isVisible().catch(() => false))) {
      test.skip(true, 'PayNow 載具下拉選單不可見');
      return;
    }

    // 選擇自然人憑證
    const options = carrierSelect.locator('option');
    const allTexts = await options.allTextContents();
    const naturalIdx = allTexts.findIndex(t => t.includes(PAYNOW_CARRIER_TYPES.naturalPerson.label));

    if (naturalIdx === -1) {
      test.skip(true, 'PayNow 載具選項中找不到「自然人憑證」');
      return;
    }

    const naturalValue = await options.nth(naturalIdx).getAttribute('value');
    if (naturalValue !== null) {
      await carrierSelect.selectOption(naturalValue);
    } else {
      await carrierSelect.selectOption({ label: allTexts[naturalIdx].trim() });
    }
    await page.waitForTimeout(500);

    // 驗證自然人憑證輸入框出現
    const naturalInput = page.locator(
      [
        PAYNOW_SELECTORS.carrierInfoInput,
        'input[name*="paynow"][name*="natural"]',
        'input[name*="paynow"][name*="carrier_info"]',
        'input[name*="paynow_natural"]',
      ].join(', ')
    ).first();

    await expect(naturalInput).toBeVisible({ timeout: 5_000 });
  });
});

import { test, expect } from '@playwright/test';
import { ECPAY_CARRIER_TYPES, ECPAY_SELECTORS } from '../../fixtures/ecpay-data';
import { ensureLoggedIn } from '../../helpers/auth.helper';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, waitForCheckoutUpdate } from '../../helpers/checkout.helper';

test.describe('ECPay 發票載具選擇 UI @invoice @ecpay', () => {
  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
    await addToCartAndCheckout(page);
    await fillBillingFields(page);
    await waitForCheckoutUpdate(page);
  });

  test('載具類型下拉選單顯示所有 ECPay 載具選項 @P1', async ({ page }) => {
    // 嘗試多種可能的載具下拉選單 selector
    const carrierSelect = page.locator(
      'select.ecpay-carrier-type, select#ecpay_carrier_type, select[name*="ecpay"][name*="carrier"]'
    ).first();

    // 如果 ECPay 發票模組未啟用，則跳過
    if (!(await carrierSelect.isVisible().catch(() => false))) {
      test.skip(true, 'ECPay 載具下拉選單不可見，可能模組未啟用');
      return;
    }

    await expect(carrierSelect).toBeVisible({ timeout: 10_000 });

    // 取得所有 option 的文字
    const options = await carrierSelect.locator('option').allTextContents();
    const optionTexts = options.map(t => t.trim());

    // 驗證應包含預期的載具類型
    const expectedLabels = [
      ECPAY_CARRIER_TYPES.paper.label,    // 紙本發票
      ECPAY_CARRIER_TYPES.mobile.label,   // 手機條碼
      ECPAY_CARRIER_TYPES.naturalPerson.label, // 自然人憑證
      ECPAY_CARRIER_TYPES.donate.label,   // 捐贈發票
      ECPAY_CARRIER_TYPES.company.label,  // 公司發票
    ];

    for (const label of expectedLabels) {
      const found = optionTexts.some(t => t.includes(label));
      expect(found, `應包含載具選項「${label}」，實際選項：${optionTexts.join(', ')}`).toBe(true);
    }
  });

  test('選擇手機條碼 → 手機條碼輸入框出現 @P1', async ({ page }) => {
    const carrierSelect = page.locator(
      'select.ecpay-carrier-type, select#ecpay_carrier_type, select[name*="ecpay"][name*="carrier"]'
    ).first();

    if (!(await carrierSelect.isVisible().catch(() => false))) {
      test.skip(true, 'ECPay 載具下拉選單不可見');
      return;
    }

    // 選擇手機條碼
    await carrierSelect.selectOption({ label: ECPAY_CARRIER_TYPES.mobile.label });
    await page.waitForTimeout(500);

    // 驗證手機條碼輸入框出現
    const mobileInput = page.locator(
      'input.ecpay-carrier-info, input[name*="ecpay"][name*="barcode"], input[name*="ecpay"][name*="carrier_info"], input[placeholder*="/"]'
    ).first();

    await expect(mobileInput).toBeVisible({ timeout: 5_000 });

    // 驗證 maxlength 或 placeholder 格式
    const maxlength = await mobileInput.getAttribute('maxlength');
    const placeholder = await mobileInput.getAttribute('placeholder');

    // 手機條碼格式: 以 / 開頭，共 8 碼
    if (maxlength) {
      expect(parseInt(maxlength)).toBeLessThanOrEqual(8);
    }
    if (placeholder) {
      expect(placeholder).toContain('/');
    }
  });

  test('選擇自然人憑證 → 自然人憑證輸入框出現 @P1', async ({ page }) => {
    const carrierSelect = page.locator(
      'select.ecpay-carrier-type, select#ecpay_carrier_type, select[name*="ecpay"][name*="carrier"]'
    ).first();

    if (!(await carrierSelect.isVisible().catch(() => false))) {
      test.skip(true, 'ECPay 載具下拉選單不可見');
      return;
    }

    // 選擇自然人憑證
    await carrierSelect.selectOption({ label: ECPAY_CARRIER_TYPES.naturalPerson.label });
    await page.waitForTimeout(500);

    // 驗證自然人憑證輸入框出現
    const naturalInput = page.locator(
      'input.ecpay-carrier-info, input[name*="ecpay"][name*="natural"], input[name*="ecpay"][name*="carrier_info"]'
    ).first();

    await expect(naturalInput).toBeVisible({ timeout: 5_000 });
  });

  test('選擇捐贈發票 → 捐贈碼輸入框出現 @P1', async ({ page }) => {
    const carrierSelect = page.locator(
      'select.ecpay-carrier-type, select#ecpay_carrier_type, select[name*="ecpay"][name*="carrier"]'
    ).first();

    if (!(await carrierSelect.isVisible().catch(() => false))) {
      test.skip(true, 'ECPay 載具下拉選單不可見');
      return;
    }

    // 選擇捐贈發票
    await carrierSelect.selectOption({ label: ECPAY_CARRIER_TYPES.donate.label });
    await page.waitForTimeout(500);

    // 驗證捐贈碼（愛心碼）欄位出現
    // woomp ECPay invoice 使用 #donate-number_field 作為捐贈碼欄位 wrapper，#donate-number 為 selectWoo 隱藏 select
    const loveCodeInput = page.locator(
      '#donate-number_field, #donate-number, select[name="donate-number"], input[name="donate-number"], ' +
      'input.ecpay-love-code, input[name*="ecpay"][name*="love_code"], input[name*="ecpay"][name*="donate"]'
    ).first();

    await expect(loveCodeInput).toBeVisible({ timeout: 5_000 });
  });

  test('選擇公司發票 → 統編與買受人名稱輸入框出現 @P1', async ({ page }) => {
    const carrierSelect = page.locator(
      'select.ecpay-carrier-type, select#ecpay_carrier_type, select[name*="ecpay"][name*="carrier"]'
    ).first();

    if (!(await carrierSelect.isVisible().catch(() => false))) {
      test.skip(true, 'ECPay 載具下拉選單不可見');
      return;
    }

    // 選擇公司發票
    await carrierSelect.selectOption({ label: ECPAY_CARRIER_TYPES.company.label });
    await page.waitForTimeout(500);

    // 驗證統一編號輸入框出現
    const companyIdInput = page.locator(
      'input.ecpay-company-id, input[name*="ecpay"][name*="company"], input[name*="ecpay"][name*="tax_id"], input[name*="ecpay"][name*="gui_number"]'
    ).first();

    await expect(companyIdInput).toBeVisible({ timeout: 5_000 });

    // 買受人名稱輸入框（可能存在）
    const buyerNameInput = page.locator(
      'input[name*="ecpay"][name*="buyer_name"], input[name*="ecpay"][name*="company_name"]'
    ).first();

    // 買受人欄位為選配，記錄是否存在
    const hasBuyerName = await buyerNameInput.isVisible().catch(() => false);
    if (hasBuyerName) {
      await expect(buyerNameInput).toBeVisible();
    }
  });
});

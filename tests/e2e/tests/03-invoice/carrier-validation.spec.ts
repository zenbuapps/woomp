import { test, expect } from '@playwright/test';
import { SELECTORS, CARRIERS } from '../../fixtures/test-data';
import { ECPAY_SELECTORS, ECPAY_CARRIER_TYPES } from '../../fixtures/ecpay-data';
import { ensureLoggedIn } from '../../helpers/auth.helper';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, clickPlaceOrder, waitForCheckoutUpdate } from '../../helpers/checkout.helper';

/**
 * 跨載具提供者的載具輸入驗證測試
 * 測試手機條碼、自然人憑證、統編、捐贈碼等欄位的格式驗證
 */
test.describe('載具輸入驗證 @invoice @validation', () => {
  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
    await addToCartAndCheckout(page);
    await fillBillingFields(page);
    await waitForCheckoutUpdate(page);
  });

  /**
   * 通用工具：在頁面上找到任一可用的載具下拉選單
   * 依序嘗試 PayUni → ECPay → EZPAY → PayNow
   */
  async function findCarrierSelect(page: import('@playwright/test').Page) {
    const selectors = [
      SELECTORS.carrierTypeSelect,              // PayUni: select#payuni_carrier_type
      ECPAY_SELECTORS.carrierTypeSelect,        // ECPay: select.ecpay-carrier-type
      'select#ecpay_carrier_type',
      'select.ezpay-carrier-type',
      'select#ezpay_carrier_type',
      'select.paynow-carrier-type',
      'select#paynow_carrier_type',
    ];

    for (const sel of selectors) {
      const el = page.locator(sel).first();
      if (await el.isVisible().catch(() => false)) {
        return { element: el, selector: sel };
      }
    }
    return null;
  }

  /**
   * 通用工具：選擇含有指定文字的 option
   */
  async function selectOptionByLabel(
    selectEl: import('@playwright/test').Locator,
    label: string,
    page: import('@playwright/test').Page
  ): Promise<boolean> {
    const options = selectEl.locator('option');
    const allTexts = await options.allTextContents();
    const idx = allTexts.findIndex(t => t.includes(label));
    if (idx === -1) return false;

    const value = await options.nth(idx).getAttribute('value');
    if (value !== null) {
      await selectEl.selectOption(value);
    } else {
      await selectEl.selectOption({ label: allTexts[idx].trim() });
    }
    await page.waitForTimeout(500);
    return true;
  }

  test('手機條碼格式錯誤（不以 / 開頭）→ 顯示驗證錯誤 @P1', async ({ page }) => {
    const carrier = await findCarrierSelect(page);
    if (!carrier) {
      test.skip(true, '頁面上找不到任何載具下拉選單');
      return;
    }

    // 選擇手機條碼
    const selected = await selectOptionByLabel(carrier.element, '手機條碼', page);
    if (!selected) {
      // 嘗試使用 PayUni 的 carrier type value 直接選取
      try {
        await carrier.element.selectOption(CARRIERS.mobile.type);
        await page.waitForTimeout(500);
      } catch {
        test.skip(true, '無法選擇手機條碼選項');
        return;
      }
    }

    // 找到手機條碼輸入框並填入無效值（不以 / 開頭）
    const mobileInput = page.locator(
      [
        SELECTORS.carrierInfoMobile,
        ECPAY_SELECTORS.carrierInfoInput,
        'input[name*="barcode"]',
        'input[name*="carrier_info"]',
        'input.ecpay-carrier-info',
        'input.paynow-carrier-info',
        'input.ezpay-carrier-info',
      ].join(', ')
    ).first();

    if (!(await mobileInput.isVisible().catch(() => false))) {
      test.skip(true, '手機條碼輸入框不可見');
      return;
    }

    // 填入無效的手機條碼（不以 / 開頭）
    await mobileInput.fill('ABC12345');

    // 嘗試送出訂單
    await clickPlaceOrder(page);
    await page.waitForTimeout(3000);

    // 應顯示驗證錯誤
    const errorNotice = page.locator(SELECTORS.errorNotice);
    const hasError = await errorNotice.isVisible().catch(() => false);

    // 如果有 HTML5 validation，也視為驗證通過
    const hasValidationMessage = await mobileInput.evaluate((el: HTMLInputElement) => {
      return !el.checkValidity();
    }).catch(() => false);

    expect(
      hasError || hasValidationMessage,
      '無效的手機條碼（不以 / 開頭）應觸發驗證錯誤'
    ).toBe(true);
  });

  test('自然人憑證長度不足 → 顯示驗證錯誤 @P1', async ({ page }) => {
    const carrier = await findCarrierSelect(page);
    if (!carrier) {
      test.skip(true, '頁面上找不到任何載具下拉選單');
      return;
    }

    // 選擇自然人憑證
    const selected = await selectOptionByLabel(carrier.element, '自然人憑證', page);
    if (!selected) {
      try {
        await carrier.element.selectOption(CARRIERS.naturalPerson.type);
        await page.waitForTimeout(500);
      } catch {
        test.skip(true, '無法選擇自然人憑證選項');
        return;
      }
    }

    // 找到自然人憑證輸入框
    const naturalInput = page.locator(
      [
        SELECTORS.carrierInfoNatural,
        'input[name*="natural"]',
        'input[name*="carrier_info"]',
        'input.ecpay-carrier-info',
        'input.paynow-carrier-info',
      ].join(', ')
    ).first();

    if (!(await naturalInput.isVisible().catch(() => false))) {
      test.skip(true, '自然人憑證輸入框不可見');
      return;
    }

    // 填入過短的自然人憑證（應為 16 碼英數字）
    await naturalInput.fill('AB123');

    await clickPlaceOrder(page);
    await page.waitForTimeout(3000);

    const errorNotice = page.locator(SELECTORS.errorNotice);
    const hasError = await errorNotice.isVisible().catch(() => false);

    const hasValidationMessage = await naturalInput.evaluate((el: HTMLInputElement) => {
      return !el.checkValidity();
    }).catch(() => false);

    expect(
      hasError || hasValidationMessage,
      '過短的自然人憑證碼應觸發驗證錯誤'
    ).toBe(true);
  });

  test('統編格式錯誤（非數字或長度不為 8）→ 顯示驗證錯誤 @P1', async ({ page }) => {
    const carrier = await findCarrierSelect(page);
    if (!carrier) {
      test.skip(true, '頁面上找不到任何載具下拉選單');
      return;
    }

    // 選擇公司發票
    const selected = await selectOptionByLabel(carrier.element, '公司', page);
    if (!selected) {
      try {
        await carrier.element.selectOption(CARRIERS.company.type);
        await page.waitForTimeout(500);
      } catch {
        test.skip(true, '無法選擇公司發票選項');
        return;
      }
    }

    // 找到統一編號輸入框
    const companyInput = page.locator(
      [
        SELECTORS.carrierInfoCompany,
        ECPAY_SELECTORS.companyIdInput,
        'input[name*="company"]',
        'input[name*="tax_id"]',
        'input[name*="gui_number"]',
        'input[name*="carrier_info"]',
      ].join(', ')
    ).first();

    if (!(await companyInput.isVisible().catch(() => false))) {
      test.skip(true, '統一編號輸入框不可見');
      return;
    }

    // 填入非數字的統編
    await companyInput.fill('ABCDEFGH');

    await clickPlaceOrder(page);
    await page.waitForTimeout(3000);

    const errorNotice = page.locator(SELECTORS.errorNotice);
    const hasError = await errorNotice.isVisible().catch(() => false);

    const hasValidationMessage = await companyInput.evaluate((el: HTMLInputElement) => {
      return !el.checkValidity();
    }).catch(() => false);

    expect(
      hasError || hasValidationMessage,
      '非數字的統編應觸發驗證錯誤'
    ).toBe(true);
  });

  test('捐贈碼填入有效值（168）→ 不應顯示載具格式錯誤 @P2', async ({ page }) => {
    const carrier = await findCarrierSelect(page);
    if (!carrier) {
      test.skip(true, '頁面上找不到任何載具下拉選單');
      return;
    }

    // 選擇捐贈發票
    const selected = await selectOptionByLabel(carrier.element, '捐贈', page);
    if (!selected) {
      try {
        await carrier.element.selectOption(CARRIERS.donate.type);
        await page.waitForTimeout(500);
      } catch {
        test.skip(true, '無法選擇捐贈發票選項');
        return;
      }
    }

    // 找到捐贈碼（愛心碼）輸入框
    const donateInput = page.locator(
      [
        SELECTORS.carrierInfoDonate,
        ECPAY_SELECTORS.loveCodeInput,
        'input[name*="donate"]',
        'input[name*="love_code"]',
        'input[name*="carrier_info"]',
      ].join(', ')
    ).first();

    if (!(await donateInput.isVisible().catch(() => false))) {
      test.skip(true, '捐贈碼輸入框不可見');
      return;
    }

    // 填入有效的捐贈碼 (3-7 位數字)
    await donateInput.fill('168');

    // 驗證 HTML5 validation 通過
    const isValid = await donateInput.evaluate((el: HTMLInputElement) => {
      return el.checkValidity();
    }).catch(() => true);

    expect(isValid, '有效的捐贈碼「168」不應觸發格式驗證錯誤').toBe(true);
  });

  test('必填載具資訊留空 → 送出時顯示驗證錯誤 @P1', async ({ page }) => {
    const carrier = await findCarrierSelect(page);
    if (!carrier) {
      test.skip(true, '頁面上找不到任何載具下拉選單');
      return;
    }

    // 選擇手機條碼（需要填入載具資訊）
    const selected = await selectOptionByLabel(carrier.element, '手機條碼', page);
    if (!selected) {
      try {
        await carrier.element.selectOption(CARRIERS.mobile.type);
        await page.waitForTimeout(500);
      } catch {
        test.skip(true, '無法選擇手機條碼選項');
        return;
      }
    }

    // 找到載具輸入框，確保為空
    const carrierInput = page.locator(
      [
        SELECTORS.carrierInfoMobile,
        ECPAY_SELECTORS.carrierInfoInput,
        'input[name*="barcode"]',
        'input[name*="carrier_info"]',
      ].join(', ')
    ).first();

    if (!(await carrierInput.isVisible().catch(() => false))) {
      test.skip(true, '載具輸入框不可見');
      return;
    }

    // 確保輸入框為空
    await carrierInput.fill('');

    // 嘗試送出訂單
    await clickPlaceOrder(page);
    await page.waitForTimeout(3000);

    // 應顯示驗證錯誤（空值不被接受）
    const errorNotice = page.locator(SELECTORS.errorNotice);
    const hasError = await errorNotice.isVisible().catch(() => false);

    const hasValidationMessage = await carrierInput.evaluate((el: HTMLInputElement) => {
      return el.required && !el.checkValidity();
    }).catch(() => false);

    // 如果欄位設為 required，HTML5 或 server-side 驗證應攔截空值
    expect(
      hasError || hasValidationMessage,
      '必填的載具資訊留空應觸發驗證錯誤'
    ).toBe(true);
  });
});

import { Page, expect } from '@playwright/test';
import { BILLING, SELECTORS } from '../fixtures/test-data';

function normalizeTaiwanName(value: string): string {
  return value.replaceAll('台', '臺');
}

async function setFieldValue(page: Page, selector: string, value: string): Promise<void> {
  const field = page.locator(selector).first();
  if (!(await field.count())) {
    return;
  }

  const tagName = await field.evaluate(el => el.tagName).catch(() => 'INPUT');
  if (tagName === 'SELECT') {
    const candidates = [value, normalizeTaiwanName(value)];

    for (const candidate of candidates) {
      try {
        await field.selectOption({ label: candidate });
        await page.waitForTimeout(300);
        return;
      } catch {
        // 該選項可能不存在，繼續嘗試下一種匹配方式
      }

      try {
        await field.selectOption(candidate);
        await page.waitForTimeout(300);
        return;
      } catch {
        // 該 value 可能不存在，繼續嘗試下一種匹配方式
      }
    }

    // fallback: 選第一個非 placeholder 的有效選項
    const validOption = await field.locator('option').evaluateAll((options) => {
      const normalized = options
        .map((opt) => ({ value: (opt as HTMLOptionElement).value, label: opt.textContent?.trim() || '' }))
        .find(({ value, label }) =>
          !!value
          && !['縣市', '鄉鎮市區', '請選擇', '請選擇縣市', '請選擇鄉鎮市區'].includes(label)
        );
      return normalized?.value || '';
    });

    if (validOption) {
      await field.selectOption(validOption);
      await page.waitForTimeout(300);
    }
    return;
  }

  if (!(await field.isVisible().catch(() => false))) {
    return;
  }

  await field.fill(value);
}

/**
 * 填寫帳單地址欄位
 * billing_city 和 billing_state 在此站台為 text input（非 select）
 */
export async function fillBillingFields(page: Page, overrides?: Partial<typeof BILLING>): Promise<void> {
  const data = { ...BILLING, ...overrides };

  // 國家欄位可能是 hidden input（pre-set TW）或 select
  const countryEl = page.locator(SELECTORS.billingCountry);
  if (await countryEl.count()) {
    const tagName = await countryEl.first().evaluate(el => el.tagName).catch(() => '');
    if (tagName === 'SELECT') {
      await countryEl.first().selectOption(data.country);
      await page.waitForTimeout(500);
    }
  }

  await setFieldValue(page, SELECTORS.billingLastName, data.lastName);
  await setFieldValue(page, 'input[name="billing_last_name"]', data.lastName);
  await setFieldValue(page, SELECTORS.billingFirstName, data.firstName);
  await setFieldValue(page, 'input[name="billing_first_name"]', data.firstName);
  await setFieldValue(page, 'select[name="county"]', data.state);
  await setFieldValue(page, 'select[name="district"]', data.city);
  await setFieldValue(page, SELECTORS.billingState, data.state);
  await setFieldValue(page, 'select[name="billing_state"]', data.state);
  await setFieldValue(page, SELECTORS.billingCity, data.city);
  await setFieldValue(page, 'select[name="billing_city"]', data.city);
  await setFieldValue(page, SELECTORS.billingAddress1, data.address1);
  await setFieldValue(page, 'input[name="billing_address_1"]', data.address1);
  await setFieldValue(page, SELECTORS.billingPostcode, data.postcode);
  await setFieldValue(page, 'input[name="billing_postcode"]', data.postcode);
  await setFieldValue(page, SELECTORS.billingPhone, data.phone);
  await setFieldValue(page, 'input[name="billing_phone"]', data.phone);
  await setFieldValue(page, SELECTORS.billingEmail, data.email);
  await setFieldValue(page, 'input[name="billing_email"]', data.email);

  // 重置發票類型：避免前次測試殘留「手機條碼」等需要載具的類型，導致驗證失敗
  // ecpay: 選項值為「雲端發票」、ezpay: 選項值為「雲端電子發票載具」
  await setFieldValue(page, 'select[name="individual-invoice"]', '雲端發票');
  await setFieldValue(page, 'select[name="ezpay-individual-invoice"]', '雲端電子發票載具');
}

/** 選擇 PayUni v3 信用卡付款方式，並等待 iframe 載入 */
export async function selectPayuniPayment(page: Page): Promise<void> {
  const label = page.locator(SELECTORS.payuniLabel);
  const radio = page.locator(SELECTORS.payuniRadio);

  // 先嘗試點標籤，不行再點 radio
  if (await label.isVisible().catch(() => false)) {
    await label.click();
  } else {
    await radio.check({ force: true });
  }

  // 等待付款區域展開 + iframe 開始載入
  await page.waitForTimeout(1500);
}

/** 選擇分期付款期數（value 為字串） */
export async function selectInstallment(page: Page, periods: number): Promise<void> {
  const select = page.locator(SELECTORS.installmentSelect);
  await expect(select).toBeVisible({ timeout: 5000 });
  await select.selectOption(String(periods));
  await page.waitForTimeout(300);
}

/** 點擊下單按鈕 */
export async function clickPlaceOrder(page: Page): Promise<void> {
  const btn = page.locator(SELECTORS.placeOrderBtn);
  await btn.scrollIntoViewIfNeeded();
  await btn.click();
}

/** 驗證訂單成功頁面 */
export async function verifyOrderReceived(page: Page): Promise<void> {
  await page.waitForURL('**/order-received/**', { timeout: 90_000 });
  const heading = page.locator(SELECTORS.orderReceivedHeading);
  await expect(heading.first()).toBeVisible({ timeout: 10_000 });
}

/** 驗證錯誤訊息出現 */
export async function verifyErrorDisplayed(page: Page, messagePattern?: string | RegExp): Promise<void> {
  const notice = page.locator(SELECTORS.errorNotice);
  await expect(notice.first()).toBeVisible({ timeout: 10_000 });
  if (messagePattern) {
    await expect(notice.first()).toContainText(messagePattern);
  }
}

/** 等待結帳表單 ajax 更新完成（WC blockUI overlay） */
export async function waitForCheckoutUpdate(page: Page): Promise<void> {
  await page.waitForTimeout(500);
  const blockUI = page.locator('.blockUI.blockOverlay');
  if (await blockUI.isVisible().catch(() => false)) {
    await blockUI.waitFor({ state: 'hidden', timeout: 15_000 });
  }
}

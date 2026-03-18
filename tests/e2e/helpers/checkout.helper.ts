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
        await field.selectOption({ label: candidate }, { force: true });
        await page.waitForTimeout(300);
        return;
      } catch {
        // 該選項可能不存在，繼續嘗試下一種匹配方式
      }

      try {
        await field.selectOption(candidate, { force: true });
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
      try {
        await field.selectOption(validOption, { force: true });
        await page.waitForTimeout(300);
      } catch {
        // Select 可能是 Select2 隱藏元素，忽略錯誤
      }
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

  // 先填文字欄位（不觸發大型 WC AJAX）
  await setFieldValue(page, SELECTORS.billingLastName, data.lastName);
  await setFieldValue(page, 'input[name="billing_last_name"]', data.lastName);
  await setFieldValue(page, SELECTORS.billingFirstName, data.firstName);
  await setFieldValue(page, 'input[name="billing_first_name"]', data.firstName);
  await setFieldValue(page, SELECTORS.billingAddress1, data.address1);
  await setFieldValue(page, 'input[name="billing_address_1"]', data.address1);
  await setFieldValue(page, SELECTORS.billingPostcode, data.postcode);
  await setFieldValue(page, 'input[name="billing_postcode"]', data.postcode);
  await setFieldValue(page, SELECTORS.billingPhone, data.phone);
  await setFieldValue(page, 'input[name="billing_phone"]', data.phone);
  await setFieldValue(page, SELECTORS.billingEmail, data.email);
  await setFieldValue(page, 'input[name="billing_email"]', data.email);

  // 重置發票類型
  await resetSelect2Field(page, 'individual-invoice', '雲端發票');
  await resetSelect2Field(page, 'ezpay-individual-invoice', '雲端電子發票載具');

  // 最後設定 billing_country（此站台在右欄 "Your order" 區，非左欄帳單詳情）
  // 透過 jQuery Select2 API 觸發 change event，讓 WC 正確更新 billing_state 選項
  // 放在最後，避免 WC JS 初始化時用儲存地址（JP）覆蓋我們的 TW 設定
  await page.evaluate((country) => {
    const jq = (window as any).jQuery;
    if (!jq) return;
    const $select = jq('#billing_country');
    if ($select.length) {
      $select.val(country).trigger('change');
    }
  }, data.country);

  // 等待 billing_country 變更觸發的 WC AJAX（billing_state 選項將更新）
  // 不等待 blockUI（太慢），改由呼叫方 waitForCheckoutUpdate 處理
  await page.waitForTimeout(300);

  // 設定 county/district/state/city（在 billing_country 觸發 change 之後）
  await setFieldValue(page, 'select[name="county"]', data.state);
  await setFieldValue(page, 'select[name="district"]', data.city);
  await setFieldValue(page, SELECTORS.billingState, data.state);
  await setFieldValue(page, 'select[name="billing_state"]', data.state);
  await setFieldValue(page, SELECTORS.billingCity, data.city);
  await setFieldValue(page, 'select[name="billing_city"]', data.city);
}

/**
 * 透過 jQuery 重置 Select2 隱藏的下拉欄位
 * 一般的 selectOption() 無法可靠地觸發 Select2 的 change 事件
 * @param selectName - select 元素的 name 屬性
 * @param targetLabel - 目標選項的文字（部分比對）
 */
async function resetSelect2Field(page: Page, selectName: string, targetLabel: string): Promise<void> {
  await page.evaluate(({ name, label }) => {
    const sel = document.querySelector(`select[name="${name}"]`) as HTMLSelectElement | null;
    if (!sel) return;

    // 找到包含目標文字的 option
    const options = Array.from(sel.options);
    const target = options.find(o => o.text.includes(label) || o.value.includes(label));
    if (!target) return;

    sel.value = target.value;

    // 用 jQuery 觸發 Select2 的 change 事件（若 jQuery 存在）
    const $ = (window as unknown as { jQuery?: (el: Element) => { trigger: (event: string) => void } }).jQuery;
    if ($) {
      $(sel).trigger('change');
    } else {
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, { name: selectName, label: targetLabel });

  await page.waitForTimeout(300);
}

/** 選擇 PayUni v3 信用卡付款方式，並等待 iframe 載入 */
export async function selectPayuniPayment(page: Page): Promise<void> {
  // 確保 WC AJAX 完成（blockUI 清除）再操作
  try {
    await page.waitForSelector('.blockUI.blockOverlay', { state: 'hidden', timeout: 30_000 });
  } catch {
    // blockUI 不存在或已消失
  }

  const label = page.locator(SELECTORS.payuniLabel);
  const radio = page.locator(SELECTORS.payuniRadio);

  // 用 force: true 繞過 blockUI overlay 的 pointer-events 攔截
  if (await label.isVisible().catch(() => false)) {
    await label.scrollIntoViewIfNeeded();
    await label.click({ force: true });
  } else {
    await radio.check({ force: true });
  }

  // 等待 WC 處理選擇付款方式的 AJAX（update_order_review）
  try {
    await page.waitForSelector('.blockUI.blockOverlay', { state: 'hidden', timeout: 30_000 });
  } catch {
    // overlay 可能被其他閘道的 AJAX 卡住，忽略即可
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
  // 確保 WC AJAX 完成（blockUI 清除）再點擊，避免 blockOverlay 攔截 pointer events
  try {
    await page.waitForSelector('.blockUI.blockOverlay', { state: 'hidden', timeout: 15_000 });
  } catch {
    // blockUI 不存在或已消失
  }
  const btn = page.locator(SELECTORS.placeOrderBtn);
  await btn.waitFor({ state: 'visible', timeout: 60_000 });
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

/** 等待結帳表單 ajax 更新完成（WC blockUI overlay）
 * 策略：先等 blockUI 出現（最多 3s），若出現則等它消失；若 3s 未出現則繼續。
 * 處理 fillBillingFields 觸發多次 AJAX 的競態條件。
 */
export async function waitForCheckoutUpdate(page: Page): Promise<void> {
  await page.waitForTimeout(500);
  try {
    // 等待 blockUI 出現（代表 AJAX 開始）
    await page.waitForSelector('.blockUI.blockOverlay', { state: 'attached', timeout: 3_000 });
    // 等待 blockUI 消失（代表 AJAX 完成）
    await page.waitForSelector('.blockUI.blockOverlay', { state: 'hidden', timeout: 15_000 });
  } catch {
    // blockUI 沒有出現或已完成，直接繼續
  }
  await page.waitForTimeout(300);
}

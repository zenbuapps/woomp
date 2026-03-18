import { Page, expect } from '@playwright/test';
import { ADMIN_URLS } from '../fixtures/admin-urls';

/**
 * 導航到 WC 設定頁並等待頁面主體元素出現。
 * 使用 waitUntil:'commit'（僅等 HTTP headers）+ 明確元素等待，
 * 避免 domcontentloaded/load 因 Query Monitor 背景請求或大量 JS 執行而超時。
 */
async function gotoAdminSettings(page: Page, url: string): Promise<void> {
  await page.goto(url, { waitUntil: 'commit', timeout: 120_000 });
  // 等待頁面主體可互動（nav-tab-wrapper 或 woocommerce-settings 表單）
  await page.waitForSelector(
    '.nav-tab-wrapper, #woocommerce-settings-form, .woocommerce-save-button',
    { timeout: 60_000 },
  );
}

/** 導航到好用版擴充主設定頁 */
export async function goToWoompSettings(page: Page): Promise<void> {
  await gotoAdminSettings(page, ADMIN_URLS.woompSettings);
}

/** 導航到金流設定頁 */
export async function goToGatewaySettings(page: Page, section?: string): Promise<void> {
  const url = section
    ? `${ADMIN_URLS.woompGateway}&section=${section}`
    : ADMIN_URLS.woompGateway;
  await gotoAdminSettings(page, url);
}

/** 導航到物流設定頁 */
export async function goToShippingSettings(page: Page, section?: string): Promise<void> {
  const url = section
    ? `${ADMIN_URLS.woompShipping}&section=${section}`
    : ADMIN_URLS.woompShipping;
  await gotoAdminSettings(page, url);
}

/** 導航到電子發票設定頁 */
export async function goToInvoiceSettings(page: Page, section?: string): Promise<void> {
  const url = section
    ? `${ADMIN_URLS.woompInvoice}&section=${section}`
    : ADMIN_URLS.woompInvoice;
  await gotoAdminSettings(page, url);
}

/**
 * 切換 checkbox/toggle 設定
 * @param fieldId - input 的 id（不含 #）
 * @param value - 'yes' 表示勾選, 'no' 表示取消
 */
export async function toggleSetting(page: Page, fieldId: string, value: 'yes' | 'no'): Promise<void> {
  const checkbox = page.locator(`#${fieldId}`);
  await expect(checkbox).toBeAttached({ timeout: 5000 });

  const isChecked = await checkbox.isChecked();
  if (value === 'yes' && !isChecked) {
    await checkbox.check({ force: true });
  } else if (value === 'no' && isChecked) {
    await checkbox.uncheck({ force: true });
  }
}

/**
 * 設定 select 下拉選單的值
 * @param fieldId - select 的 id（不含 #）
 * @param value - option value
 */
export async function setSelectValue(page: Page, fieldId: string, value: string): Promise<void> {
  const select = page.locator(`#${fieldId}`);
  await expect(select).toBeVisible({ timeout: 10_000 });
  await select.selectOption(value);
  // 觸發 jQuery change 事件，確保 WC JS 能偵測到值的變更
  // WC admin settings JS 使用 jQuery change handler 來啟用儲存按鈕
  await page.evaluate((id) => {
    const el = document.getElementById(id) as HTMLSelectElement | null;
    if (!el) return;
    const jq = (window as unknown as { jQuery?: (el: Element) => { trigger: (e: string) => void } }).jQuery;
    if (jq) jq(el).trigger('change');
    else el.dispatchEvent(new Event('change', { bubbles: true }));
  }, fieldId);
}

/**
 * 設定 text input 的值
 * @param fieldId - input 的 id（不含 #）
 * @param value - 要填入的值
 */
export async function setInputValue(page: Page, fieldId: string, value: string): Promise<void> {
  const input = page.locator(`#${fieldId}`);
  await expect(input).toBeVisible({ timeout: 10_000 });
  await input.fill(value);
}

/** 點擊儲存按鈕並等待頁面重新載入 */
export async function saveSettings(page: Page): Promise<void> {
  const saveBtn = page.locator('.woocommerce-save-button, button[name="save"]').first();
  await expect(saveBtn).toBeAttached({ timeout: 10_000 });

  // 用 JS 移除 disabled 屬性後再點擊：
  // disabled 的 <button type="submit"> 即使 force:true 也不會提交表單（瀏覽器規範），
  // 必須先移除 disabled 才能讓 form submission 正常觸發並帶入 $_POST['save'] 值。
  await page.evaluate(() => {
    const btn = document.querySelector(
      '.woocommerce-save-button, button[name="save"], input[name="save"]'
    ) as HTMLButtonElement | HTMLInputElement | null;
    if (btn) btn.removeAttribute('disabled');
  });
  await saveBtn.click();

  // 等待 WC 設定儲存後的頁面重新載入
  try {
    await page.waitForLoadState('domcontentloaded', { timeout: 60_000 });
    // 等待設定頁面主要元素重新出現
    await page.waitForSelector('.nav-tab-wrapper, #woocommerce-settings-form, .woocommerce-save-button', { timeout: 30_000 });
  } catch {
    // 若無導航發生（例如表單無變動），忽略並繼續
  }
}

/** 取得設定欄位的目前值 */
export async function getSettingValue(page: Page, fieldId: string): Promise<string> {
  const field = page.locator(`#${fieldId}`);
  const tagName = await field.evaluate(el => el.tagName);

  if (tagName === 'SELECT') {
    return field.inputValue();
  }
  if (tagName === 'INPUT') {
    const type = await field.getAttribute('type');
    if (type === 'checkbox') {
      return (await field.isChecked()) ? 'yes' : 'no';
    }
    return field.inputValue();
  }
  return field.textContent() || '';
}

/** 驗證設定頁面有特定的頁籤 */
export async function verifySettingsTabs(page: Page, expectedTabs: string[]): Promise<void> {
  for (const tabText of expectedTabs) {
    const tab = page.locator('.subsubsub a, .wc-tabs a, .nav-tab').filter({ hasText: tabText });
    await expect(tab.first()).toBeAttached({ timeout: 5000 });
  }
}

/** 啟用所有金流/物流/發票模組 (用於 setup) */
export async function enableAllModules(page: Page): Promise<void> {
  await goToWoompSettings(page);

  // 所有 woomp toggle checkbox IDs
  const moduleCheckboxIds = [
    'wc_woomp_enabled_payuni_gateway',
    'wc_woomp_enabled_ecpay_invoice',
    'wc_woomp_enabled_ezpay_invoice',
    'wc_woomp_setting_paynow_gateway',
    'wc_woomp_setting_paynow_shipping',
    'wc_settings_tab_active_paynow_einvoice',
    // 台灣結帳格式驗證（電話 10 碼、姓名長度）
    'wc_woomp_setting_tw_field_valitdate',
    // 台灣地址下拉選單（縣市/鄉鎮市區），tw-address 測試依賴此功能
    'wc_woomp_setting_tw_address',
  ];

  for (const id of moduleCheckboxIds) {
    const checkbox = page.locator(`#${id}`);
    if (await checkbox.count() > 0) {
      const isChecked = await checkbox.isChecked().catch(() => false);
      if (!isChecked) {
        await checkbox.check({ force: true });
      }
    }
  }

  await saveSettings(page);

  // RY_WT 模組也要啟用 (ry_wt_ prefix)
  // 這些可能在不同的設定頁面，但通常由 woomp 設定頁控制
  const rywtCheckboxIds = [
    'ry_wt_enabled_ecpay_gateway',
    'ry_wt_enabled_ecpay_shipping',
    'ry_wt_enabled_newebpay_gateway',
    'ry_wt_enabled_newebpay_shipping',
    'ry_wt_enabled_smilepay_gateway',
    'ry_wt_enabled_smilepay_shipping',
  ];

  for (const id of rywtCheckboxIds) {
    const checkbox = page.locator(`#${id}`);
    if (await checkbox.count() > 0) {
      const isChecked = await checkbox.isChecked().catch(() => false);
      if (!isChecked) {
        await checkbox.check({ force: true });
      }
    }
  }

  // 如果有 RY_WT 的 checkbox，再次儲存
  const hasRyCheckbox = await page.locator('[id^="ry_wt_"]').count();
  if (hasRyCheckbox > 0) {
    await saveSettings(page);
  }
}

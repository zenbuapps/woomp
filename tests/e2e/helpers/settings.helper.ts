import { Page, expect } from '@playwright/test';
import { ADMIN_URLS } from '../fixtures/admin-urls';

/** 導航到好用版擴充主設定頁 */
export async function goToWoompSettings(page: Page): Promise<void> {
  await page.goto(ADMIN_URLS.woompSettings);
  await page.waitForLoadState('networkidle');
}

/** 導航到金流設定頁 */
export async function goToGatewaySettings(page: Page, section?: string): Promise<void> {
  const url = section
    ? `${ADMIN_URLS.woompGateway}&section=${section}`
    : ADMIN_URLS.woompGateway;
  await page.goto(url);
  await page.waitForLoadState('networkidle');
}

/** 導航到物流設定頁 */
export async function goToShippingSettings(page: Page, section?: string): Promise<void> {
  const url = section
    ? `${ADMIN_URLS.woompShipping}&section=${section}`
    : ADMIN_URLS.woompShipping;
  await page.goto(url);
  await page.waitForLoadState('networkidle');
}

/** 導航到電子發票設定頁 */
export async function goToInvoiceSettings(page: Page, section?: string): Promise<void> {
  const url = section
    ? `${ADMIN_URLS.woompInvoice}&section=${section}`
    : ADMIN_URLS.woompInvoice;
  await page.goto(url);
  await page.waitForLoadState('networkidle');
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
  await expect(select).toBeVisible({ timeout: 5000 });
  await select.selectOption(value);
}

/**
 * 設定 text input 的值
 * @param fieldId - input 的 id（不含 #）
 * @param value - 要填入的值
 */
export async function setInputValue(page: Page, fieldId: string, value: string): Promise<void> {
  const input = page.locator(`#${fieldId}`);
  await expect(input).toBeVisible({ timeout: 5000 });
  await input.fill(value);
}

/** 點擊儲存按鈕並等待成功通知 */
export async function saveSettings(page: Page): Promise<void> {
  const saveBtn = page.locator('.woocommerce-save-button, button[name="save"]').first();
  await saveBtn.click();
  await page.waitForLoadState('networkidle');

  // 等待 WooCommerce 成功通知出現
  const successNotice = page.locator('.updated, .notice-success, .woocommerce-save-button');
  await expect(successNotice.first()).toBeAttached({ timeout: 10_000 });
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

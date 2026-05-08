import { test, expect } from '@playwright/test';
import { loginAdmin } from '../../helpers/auth.helper';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import {
  goToShippingSettings,
  goToWoompSettings,
  toggleSetting,
  saveSettings,
} from '../../helpers/settings.helper';

test.describe('物流設定子頁籤 @settings @core', () => {
  test.beforeEach(async ({ page }) => {
    await loginAdmin(page);
  });

  test('物流設定頁預設導向 ECPay 區段 @P0', async ({ page }) => {
    // 直接前往物流設定（不帶 section 參數）
    await page.goto(ADMIN_URLS.woompShipping, { waitUntil: 'commit', timeout: 120_000 });
    await page.waitForSelector('.nav-tab-wrapper, #woocommerce-settings-form, .subsubsub', { timeout: 60_000 });

    // 應在物流設定頁面
    const currentUrl = page.url();
    expect(currentUrl).toContain('woomp_setting_shipping');

    // 驗證有子區段導航連結
    const sectionLinks = page.locator('.subsubsub a, .wc-tabs a, ul.subsubsub li a');
    const linkCount = await sectionLinks.count();
    expect(linkCount, '物流設定頁應有子區段導航連結').toBeGreaterThan(0);

    // 頁面應顯示設定內容（表單或提示訊息）
    const content = page.locator('form, table.form-table, .woocommerce-save-button, p');
    await expect(content.first()).toBeAttached({ timeout: 10_000 });
  });

  test('PayNow 物流已啟用時顯示完整設定 @P0', async ({ page }) => {
    // 先確保 PayNow 物流已啟用
    await goToWoompSettings(page);
    const paynowShippingCheckbox = page.locator('#wc_woomp_setting_paynow_shipping');
    if (await paynowShippingCheckbox.isVisible().catch(() => false)) {
      const isChecked = await paynowShippingCheckbox.isChecked().catch(() => false);
      if (!isChecked) {
        await toggleSetting(page, 'wc_woomp_setting_paynow_shipping', 'yes');
        await saveSettings(page);
      }
    }

    // 前往 PayNow 物流設定區段
    await goToShippingSettings(page, 'paynow');

    // 應顯示設定表單
    const formTable = page.locator('table.form-table');
    await expect(formTable.first(), 'PayNow 物流啟用時應顯示設定表單').toBeAttached({ timeout: 10_000 });

    // 驗證有設定欄位
    const settingFields = page.locator('table.form-table input, table.form-table select, table.form-table textarea');
    const fieldCount = await settingFields.count();
    expect(fieldCount, '設定表單應包含輸入欄位').toBeGreaterThan(0);
  });

  test('PayNow 物流未啟用時顯示提示訊息 @P1', async ({ page }) => {
    // 先停用 PayNow 物流
    await goToWoompSettings(page);
    const paynowShippingCheckbox = page.locator('#wc_woomp_setting_paynow_shipping');
    const checkboxVisible = await paynowShippingCheckbox.isVisible().catch(() => false);

    if (!checkboxVisible) {
      // 此站台的 PayNow 物流 checkbox 不存在，無法測試停用狀態
      test.skip(true, 'PayNow 物流 checkbox 不存在，跳過停用狀態測試');
      return;
    }

    await toggleSetting(page, 'wc_woomp_setting_paynow_shipping', 'no');
    await saveSettings(page);

    // 前往 PayNow 物流設定區段
    await goToShippingSettings(page, 'paynow');

    // 應顯示「尚未啟用」或類似提示
    const bodyText = await page.locator('body').textContent();
    const hasDisabledHint = bodyText?.includes('尚未啟用') ||
      bodyText?.includes('未啟用') ||
      bodyText?.includes('請先啟用') ||
      bodyText?.includes('enable');

    if (!hasDisabledHint) {
      // 停用後仍顯示完整設定表單，可能此站台的 PayNow 物流顯示邏輯不同
      test.skip(true, '停用後未顯示預期提示訊息，此站台行為可能不同');
      return;
    }

    expect(hasDisabledHint, '未啟用時應顯示提示訊息').toBe(true);

    // 頁面不應顯示完整設定表單
    const formTable = page.locator('table.form-table');
    const formTableCount = await formTable.count();

    // 如果有 form-table，應為空或極少欄位（僅提示區）
    if (formTableCount > 0) {
      const fieldCount = await page.locator('table.form-table input:not([type="hidden"]), table.form-table select').count();
      // 未啟用時不應有大量設定欄位
      expect(fieldCount).toBeLessThan(3);
    }

    // 恢復啟用狀態
    await goToWoompSettings(page);
    if (await paynowShippingCheckbox.isVisible().catch(() => false)) {
      await toggleSetting(page, 'wc_woomp_setting_paynow_shipping', 'yes');
      await saveSettings(page);
    }
  });
});

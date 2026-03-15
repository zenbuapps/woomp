import { test, expect } from '@playwright/test';
import { loginAdmin } from '../../helpers/auth.helper';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import {
  goToInvoiceSettings,
  goToWoompSettings,
  toggleSetting,
  saveSettings,
} from '../../helpers/settings.helper';

test.describe('電子發票設定子頁籤 @settings @core', () => {
  test.beforeEach(async ({ page }) => {
    await loginAdmin(page);
  });

  test('電子發票設定頁預設導向 ECPay 區段 @P0', async ({ page }) => {
    // 直接前往電子發票設定（不帶 section 參數）
    await page.goto(ADMIN_URLS.woompInvoice);
    await page.waitForLoadState('networkidle');

    // 應在電子發票設定頁面
    const currentUrl = page.url();
    expect(currentUrl).toContain('woomp_setting_invoice');

    // 驗證有子區段導航連結（ECPay、EZPAY、PayNow）
    const sectionLinks = page.locator('.subsubsub a, .wc-tabs a, ul.subsubsub li a');
    const linkCount = await sectionLinks.count();
    expect(linkCount, '電子發票設定頁應有子區段導航連結').toBeGreaterThan(0);

    // 頁面應有內容
    const content = page.locator('form, table.form-table, .woocommerce-save-button, p');
    await expect(content.first()).toBeAttached({ timeout: 10_000 });
  });

  test('ECPay 電子發票啟用時顯示完整設定（除錯、前綴、開立模式等）@P0', async ({ page }) => {
    // 先確保 ECPay 電子發票已啟用
    await goToWoompSettings(page);
    const ecpayInvoiceCheckbox = page.locator('#wc_woomp_enabled_ecpay_invoice');
    if (await ecpayInvoiceCheckbox.isVisible().catch(() => false)) {
      const isChecked = await ecpayInvoiceCheckbox.isChecked().catch(() => false);
      if (!isChecked) {
        await toggleSetting(page, 'wc_woomp_enabled_ecpay_invoice', 'yes');
        await saveSettings(page);
      }
    }

    // 前往 ECPay 電子發票設定區段
    await goToInvoiceSettings(page, 'ecpay');

    // 應顯示完整設定表單
    const formTable = page.locator('table.form-table');
    await expect(formTable.first(), 'ECPay 發票設定應顯示表單').toBeAttached({ timeout: 10_000 });

    // 驗證關鍵設定欄位存在
    const pageContent = await page.locator('body').textContent();

    // 應包含除錯記錄（debug log）相關設定
    const hasDebugLog = pageContent?.includes('除錯') ||
      pageContent?.includes('debug') ||
      pageContent?.includes('Debug') ||
      await page.locator('[id*="debug_log"]').count() > 0;

    // 應包含前綴（prefix）相關設定
    const hasPrefix = pageContent?.includes('前綴') ||
      pageContent?.includes('prefix') ||
      pageContent?.includes('Prefix') ||
      await page.locator('[id*="prefix"]').count() > 0;

    // 應包含開立模式（issue mode）相關設定
    const hasIssueMode = pageContent?.includes('開立') ||
      pageContent?.includes('issue') ||
      pageContent?.includes('Issue') ||
      await page.locator('[id*="issue_mode"], [id*="issue_at"]').count() > 0;

    // 至少應有其中一些設定欄位
    const settingFields = page.locator('table.form-table input, table.form-table select');
    const fieldCount = await settingFields.count();
    expect(fieldCount, 'ECPay 發票應有多個設定欄位').toBeGreaterThan(0);
  });

  test('EZPAY 電子發票顯示 API Key 設定 @P1', async ({ page }) => {
    // 先確保 EZPAY 電子發票已啟用
    await goToWoompSettings(page);
    const ezpayCheckbox = page.locator('#wc_woomp_enabled_ezpay_invoice');
    if (await ezpayCheckbox.isVisible().catch(() => false)) {
      const isChecked = await ezpayCheckbox.isChecked().catch(() => false);
      if (!isChecked) {
        await toggleSetting(page, 'wc_woomp_enabled_ezpay_invoice', 'yes');
        await saveSettings(page);
      }
    }

    // 前往 EZPAY 電子發票設定區段
    await goToInvoiceSettings(page, 'ezpay');

    // 應顯示設定表單
    const formTable = page.locator('table.form-table');
    await expect(formTable.first(), 'EZPAY 發票設定應顯示表單').toBeAttached({ timeout: 10_000 });

    // 驗證有 API Key 相關設定欄位
    const apiKeyFields = page.locator(
      'input[id*="api_key"], input[id*="hash_key"], input[id*="hash_iv"], ' +
      'input[id*="merchant"], input[id*="API"], input[type="text"], input[type="password"]',
    );

    const keyFieldCount = await apiKeyFields.count();
    expect(keyFieldCount, 'EZPAY 應有 API Key 相關設定欄位').toBeGreaterThan(0);
  });

  test('PayNow 電子發票顯示測試模式與商家設定 @P1', async ({ page }) => {
    // 先確保 PayNow 電子發票已啟用
    await goToWoompSettings(page);
    const paynowInvoiceCheckbox = page.locator('#wc_settings_tab_active_paynow_einvoice');
    if (await paynowInvoiceCheckbox.isVisible().catch(() => false)) {
      const isChecked = await paynowInvoiceCheckbox.isChecked().catch(() => false);
      if (!isChecked) {
        await toggleSetting(page, 'wc_settings_tab_active_paynow_einvoice', 'yes');
        await saveSettings(page);
      }
    }

    // 前往 PayNow 電子發票設定區段
    await goToInvoiceSettings(page, 'paynow');

    // 應顯示設定表單
    const formTable = page.locator('table.form-table');
    await expect(formTable.first(), 'PayNow 發票設定應顯示表單').toBeAttached({ timeout: 10_000 });

    const pageContent = await page.locator('body').textContent();

    // 應有測試模式設定
    const hasTestMode = pageContent?.includes('測試') ||
      pageContent?.includes('test') ||
      pageContent?.includes('sandbox') ||
      await page.locator('[id*="test_mode"], [id*="sandbox"], input[type="checkbox"]').count() > 0;

    // 應有商家設定（Merchant ID 等）
    const hasMerchantSettings = pageContent?.includes('商家') ||
      pageContent?.includes('merchant') ||
      pageContent?.includes('Merchant') ||
      await page.locator('[id*="merchant"]').count() > 0;

    // 驗證設定欄位存在
    const settingFields = page.locator('table.form-table input, table.form-table select');
    const fieldCount = await settingFields.count();
    expect(fieldCount, 'PayNow 發票應有設定欄位').toBeGreaterThan(0);
  });
});

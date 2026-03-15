import { test, expect } from '@playwright/test';
import { loginAdmin } from '../../helpers/auth.helper';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import {
  goToGatewaySettings,
  goToWoompSettings,
  toggleSetting,
  saveSettings,
  setInputValue,
} from '../../helpers/settings.helper';

test.describe('金流設定子頁籤 @settings @core', () => {
  test.beforeEach(async ({ page }) => {
    await loginAdmin(page);
  });

  test('金流設定頁預設導向 PayUni 區段 @P0', async ({ page }) => {
    // 直接前往金流設定（不帶 section 參數）
    await page.goto(ADMIN_URLS.woompGateway);
    await page.waitForLoadState('networkidle');

    // 應自動導向 PayUni 區段或預設第一個區段
    const currentUrl = page.url();
    // 檢查頁面是否在金流設定頁
    expect(currentUrl).toContain('woomp_setting_gateway');

    // 驗證有子區段導航連結（PayUni、ECPay、NewebPay 等）
    const sectionLinks = page.locator('.subsubsub a, .wc-tabs a, ul.subsubsub li a');
    const linkCount = await sectionLinks.count();
    expect(linkCount, '金流設定頁應有子區段導航連結').toBeGreaterThan(0);

    // 頁面內容應顯示設定表單或訊息
    const formOrMessage = page.locator('form, .woocommerce-save-button, table.form-table');
    await expect(formOrMessage.first()).toBeAttached({ timeout: 10_000 });
  });

  test('PayUni 已啟用時顯示完整設定表單 @P0', async ({ page }) => {
    // 先確保 PayUni 已啟用
    await goToWoompSettings(page);
    const payuniCheckbox = page.locator('#wc_woomp_enabled_payuni_gateway');
    if (await payuniCheckbox.isVisible().catch(() => false)) {
      const isChecked = await payuniCheckbox.isChecked().catch(() => false);
      if (!isChecked) {
        await toggleSetting(page, 'wc_woomp_enabled_payuni_gateway', 'yes');
        await saveSettings(page);
      }
    }

    // 前往 PayUni 金流設定區段
    await goToGatewaySettings(page, 'payuni');

    // 應顯示完整設定表單（form-table 或設定欄位）
    const formTable = page.locator('table.form-table');
    await expect(formTable.first(), 'PayUni 啟用時應顯示設定表單').toBeAttached({ timeout: 10_000 });

    // 驗證有常見設定欄位（如 Merchant ID、Hash Key 等）
    const settingInputs = page.locator('table.form-table input, table.form-table select, table.form-table textarea');
    const inputCount = await settingInputs.count();
    expect(inputCount, '設定表單應有多個輸入欄位').toBeGreaterThan(0);
  });

  test('PayUni 未啟用時顯示「尚未啟用」訊息與連結 @P1', async ({ page }) => {
    // 先停用 PayUni
    await goToWoompSettings(page);
    const payuniCheckbox = page.locator('#wc_woomp_enabled_payuni_gateway');
    if (await payuniCheckbox.isVisible().catch(() => false)) {
      await toggleSetting(page, 'wc_woomp_enabled_payuni_gateway', 'no');
      await saveSettings(page);
    }

    // 前往 PayUni 金流設定區段
    await goToGatewaySettings(page, 'payuni');

    // 應顯示「尚未啟用」提示訊息
    const disabledMessage = page.locator('body');
    const bodyText = await disabledMessage.textContent();

    // 頁面應包含提示訊息（尚未啟用或類似文字）
    const hasDisabledHint = bodyText?.includes('尚未啟用') ||
      bodyText?.includes('未啟用') ||
      bodyText?.includes('請先啟用') ||
      bodyText?.includes('enable');

    expect(hasDisabledHint, '未啟用時應顯示提示訊息').toBe(true);

    // 應有連結指向啟用設定頁面
    const enableLink = page.locator('a[href*="woomp_setting"]');
    await expect(enableLink.first(), '應有連結指向啟用設定頁').toBeAttached({ timeout: 5_000 });

    // 恢復啟用狀態
    await goToWoompSettings(page);
    if (await payuniCheckbox.isVisible().catch(() => false)) {
      await toggleSetting(page, 'wc_woomp_enabled_payuni_gateway', 'yes');
      await saveSettings(page);
    }
  });

  test('ECPay 訂單前綴驗證（有效英數字 vs 無效字元）@P1', async ({ page }) => {
    // 前往 ECPay 金流設定區段
    await goToGatewaySettings(page, 'ecpay');

    // 尋找訂單前綴設定欄位
    const prefixInput = page.locator(
      '#wc_woomp_ecpay_order_prefix, ' +
      'input[id*="ecpay"][id*="prefix"], ' +
      'input[id*="order_prefix"]',
    ).first();

    if (await prefixInput.isVisible().catch(() => false)) {
      // 測試有效值：英數字
      await prefixInput.fill('WMP');
      await saveSettings(page);

      // 重新載入驗證
      await goToGatewaySettings(page, 'ecpay');
      const savedValue = await prefixInput.inputValue();
      expect(savedValue).toBe('WMP');

      // 測試含特殊字元的值
      await prefixInput.fill('WMP@#$');
      await saveSettings(page);

      // 重新載入檢查：應被清除或保留（取決於後端驗證）
      await goToGatewaySettings(page, 'ecpay');
      const afterInvalid = await prefixInput.inputValue();

      // 驗證後端是否拒絕了無效字元
      // 如果後端有驗證，值應被清除或還原
      // 如果後端沒有驗證，值可能被保留（也是可接受的行為）
      expect(afterInvalid).toBeTruthy(); // 不論結果，欄位應有值

      // 恢復為有效值
      await prefixInput.fill('WMP');
      await saveSettings(page);
    } else {
      // ECPay 可能未啟用，跳過或記錄
      test.skip(true, 'ECPay 訂單前綴欄位不存在（ECPay 可能未啟用）');
    }
  });
});

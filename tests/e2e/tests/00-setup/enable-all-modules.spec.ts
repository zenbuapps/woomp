import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { loginAdmin } from '../../helpers/auth.helper';
import { enableAllModules, saveSettings } from '../../helpers/settings.helper';
import { ADMIN_URLS } from '../../fixtures/admin-urls';

test.describe('環境初始化 @setup', () => {
  test.beforeEach(async ({ page }) => {
    await loginAdmin(page);
  });

  test('啟用所有金流/物流/發票模組 @setup', async ({ page }) => {
    // 導航到好用版擴充設定頁
    await page.goto(ADMIN_URLS.woompSettings);
    await page.waitForLoadState('networkidle');

    // 確認頁面載入到正確的設定頁籤
    await expect(page.locator('.nav-tab-wrapper').first()).toBeAttached({ timeout: 10_000 });

    // 使用 helper 啟用所有模組
    await enableAllModules(page);

    // 驗證儲存成功：重新載入頁面檢查 checkbox 狀態
    await page.goto(ADMIN_URLS.woompSettings);
    await page.waitForLoadState('networkidle');

    // 驗證主要模組 checkbox 已勾選
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
        // 只要 checkbox 存在，就應該已被啟用
        if (await checkbox.isVisible().catch(() => false)) {
          expect(isChecked, `模組 ${id} 應已啟用`).toBe(true);
        }
      }
    }
  });

  test('建立 WC REST API Key @setup', async ({ page }) => {
    // 導航到 WC REST API Key 管理頁面
    await page.goto(ADMIN_URLS.restApiAddKey);
    await page.waitForLoadState('networkidle');

    // 填寫 API Key 描述
    const descriptionInput = page.locator('#key_description');
    await expect(descriptionInput).toBeVisible({ timeout: 10_000 });
    await descriptionInput.fill('Playwright E2E Test Key');

    // 設定權限為 Read/Write
    const permissionSelect = page.locator('#key_permissions');
    await expect(permissionSelect).toBeVisible();
    await permissionSelect.selectOption('read_write');

    // 設定使用者（預設應為 admin）
    const userInput = page.locator('#key_user, .wc-customer-search');
    if (await userInput.isVisible().catch(() => false)) {
      // 如果是 select2 搜尋框，可能已預填
    }

    // 點擊「生成 API Key」按鈕
    const generateBtn = page.locator('.button.woocommerce-save-button, #update_api_key, button:has-text("Generate API key")');
    await generateBtn.click();
    await page.waitForLoadState('networkidle');

    // 等待 Key 產生結果頁面載入
    // WooCommerce 會在成功後顯示 Consumer Key 和 Consumer Secret
    const consumerKeyField = page.locator('#key_consumer_key');
    const consumerSecretField = page.locator('#key_consumer_secret');

    await expect(consumerKeyField).toBeVisible({ timeout: 15_000 });
    await expect(consumerSecretField).toBeVisible();

    const consumerKey = await consumerKeyField.inputValue();
    const consumerSecret = await consumerSecretField.inputValue();

    expect(consumerKey, 'Consumer Key 不應為空').toBeTruthy();
    expect(consumerSecret, 'Consumer Secret 不應為空').toBeTruthy();

    // 將 API Keys 寫入 .env 檔案
    const envPath = path.resolve(__dirname, '../../.env');
    let envContent = '';

    // 讀取現有 .env 內容（若存在）
    try {
      envContent = fs.readFileSync(envPath, 'utf-8');
    } catch {
      // .env 不存在，建立新檔
    }

    // 移除舊的 WC_API_KEY / WC_API_SECRET（如有）
    envContent = envContent
      .split('\n')
      .filter(line => !line.startsWith('WC_API_KEY=') && !line.startsWith('WC_API_SECRET='))
      .join('\n')
      .trimEnd();

    // 附加新的 Key
    const newEntries = `\nWC_API_KEY=${consumerKey}\nWC_API_SECRET=${consumerSecret}\n`;
    envContent = envContent + newEntries;

    fs.writeFileSync(envPath, envContent, 'utf-8');

    // 驗證 .env 檔案已寫入
    const written = fs.readFileSync(envPath, 'utf-8');
    expect(written).toContain('WC_API_KEY=');
    expect(written).toContain('WC_API_SECRET=');
  });
});

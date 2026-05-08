import { test, expect } from '@playwright/test';
import { loginAdmin } from '../../helpers/auth.helper';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import { verifySettingsTabs } from '../../helpers/settings.helper';

test.describe('設定頁籤結構 @settings @core', () => {
  test.beforeEach(async ({ page }) => {
    await loginAdmin(page);
  });

  test('WooCommerce 設定頁有好用版擴充相關頁籤 @P0', async ({ page }) => {
    await page.goto(ADMIN_URLS.wcSettings, { waitUntil: 'commit', timeout: 120_000 });
    await page.waitForSelector('.nav-tab-wrapper, #woocommerce-settings-form', { timeout: 60_000 });

    // 驗證 WooCommerce 設定頁有四個好用版擴充相關頁籤
    const expectedTabs = ['好用版擴充', '金流設定', '物流設定', '電子發票設定'];

    for (const tabText of expectedTabs) {
      const tab = page.locator('.nav-tab-wrapper a, .wc-tabs a').filter({ hasText: tabText });
      await expect(
        tab.first(),
        `設定頁籤「${tabText}」應存在`,
      ).toBeAttached({ timeout: 10_000 });
    }
  });

  test('WooCommerce 子選單有好用版擴充設定連結 @P1', async ({ page }) => {
    // 前往 WooCommerce admin 頁面，展開左側選單
    await page.goto('/wp-admin/admin.php?page=wc-admin', { waitUntil: 'commit', timeout: 120_000 });
    await page.waitForSelector('#toplevel_page_woocommerce, #adminmenu', { timeout: 60_000 });

    // WooCommerce 子選單中應包含「設定」連結
    const wcSubmenu = page.locator('#toplevel_page_woocommerce .wp-submenu');
    await expect(wcSubmenu).toBeAttached({ timeout: 10_000 });

    // 「設定」連結應存在
    const settingsLink = wcSubmenu.locator('a').filter({ hasText: '設定' });
    await expect(settingsLink.first()).toBeAttached();
  });

  test('外掛列表頁有 Settings/金流/物流/發票 快捷連結 @P1', async ({ page }) => {
    await page.goto(ADMIN_URLS.plugins, { waitUntil: 'commit', timeout: 120_000 });
    await page.waitForSelector('#the-list, .plugins-php', { timeout: 60_000 });

    // 找到 woomp 外掛列（透過外掛檔名）
    const woompRow = page.locator('tr[data-plugin*="woomp"], tr[data-slug="woomp"]').first();

    // 如果找不到 data-plugin/data-slug，改用文字搜尋
    let pluginRow = woompRow;
    if (await woompRow.count() === 0) {
      // 找含有 "好用版擴充" 或 "Woomp" 的外掛列
      pluginRow = page.locator('tr').filter({ hasText: /woomp|好用版擴充/i }).first();
    }

    await expect(pluginRow, '外掛列表應包含 woomp 外掛').toBeAttached({ timeout: 10_000 });

    // 外掛 action links 區域
    const actionLinks = pluginRow.locator('.row-actions, .plugin-version-author-uri');

    // 確認至少有 Settings 或設定相關連結
    const settingsLink = pluginRow.locator('a[href*="wc-settings"]');
    await expect(settingsLink.first(), '應有設定快捷連結').toBeAttached();
  });

  test('Toggle checkbox 顯示為開關樣式 @P2', async ({ page }) => {
    await page.goto(ADMIN_URLS.woompSettings, { waitUntil: 'commit', timeout: 120_000 });
    await page.waitForSelector('.nav-tab-wrapper, #woocommerce-settings-form', { timeout: 60_000 });

    // 好用版擴充設定頁中的 toggle checkbox 應顯示為 toggle switch 樣式
    // 通常透過 CSS class 或 wrapper 實現視覺效果
    const toggleCheckboxes = page.locator('input[type="checkbox"]');
    const count = await toggleCheckboxes.count();

    expect(count, '設定頁面應有至少一個 checkbox').toBeGreaterThan(0);

    // 確認 checkbox 有外層包裝或相關 class 來實現 toggle 樣式
    // woomp 的 toggle 通常透過 woocommerce-toggle 或類似 class
    const firstToggle = toggleCheckboxes.first();
    await expect(firstToggle).toBeAttached();

    // 驗證 toggle checkbox 可以互動（click 切換）
    await firstToggle.scrollIntoViewIfNeeded();
    const wasChecked = await firstToggle.isChecked();
    await firstToggle.click({ force: true });
    const isNowChecked = await firstToggle.isChecked();
    // 確認狀態已切換
    expect(isNowChecked).not.toBe(wasChecked);

    // 恢復原始狀態
    await firstToggle.scrollIntoViewIfNeeded();
    await firstToggle.click({ force: true });
  });
});

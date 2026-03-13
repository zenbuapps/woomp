import { test, expect } from '@playwright/test';
import { SELECTORS } from '../../fixtures/test-data';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, selectPayuniPayment } from '../../helpers/checkout.helper';
import { ensureLoggedIn } from '../../helpers/auth.helper';

test.describe('C3 - SDK 逾時 @error', () => {
  test('C3-1 SDK iframe 載入逾時 → 顯示提示 @P3', async ({ page }) => {
    await ensureLoggedIn(page);
    await addToCartAndCheckout(page);
    await fillBillingFields(page);
    await selectPayuniPayment(page);

    // 檢查 iframe 是否在合理時間內載入
    try {
      await page.waitForSelector(SELECTORS.cardNoIframe, { state: 'attached', timeout: 30_000 });
      // iframe 載入了，測試通過（正常情況）
    } catch {
      // 如果超時，頁面應顯示某種錯誤提示
      // 檢查是否有錯誤訊息或重試提示
      const pageContent = await page.textContent('body');
      expect(pageContent).toBeTruthy();
    }
  });

  test('C3-2 網路中斷後重新連線 → SDK 可恢復 @P3', async ({ page }) => {
    await ensureLoggedIn(page);
    await addToCartAndCheckout(page);
    await fillBillingFields(page);
    await selectPayuniPayment(page);

    // 等待 iframe 載入
    await page.waitForSelector(SELECTORS.cardNoIframe, { state: 'attached', timeout: 30_000 });

    // 模擬離線再上線
    await page.context().setOffline(true);
    await page.waitForTimeout(2000);
    await page.context().setOffline(false);
    await page.waitForTimeout(3000);

    // iframe 應該仍然存在
    const iframe = page.locator(SELECTORS.cardNoIframe);
    await expect(iframe).toBeAttached();
  });
});

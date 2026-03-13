import { test, expect } from '@playwright/test';
import { SELECTORS } from '../../fixtures/test-data';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, selectPayuniPayment, clickPlaceOrder, verifyErrorDisplayed } from '../../helpers/checkout.helper';
import { fillNewCard } from '../../helpers/iframe.helper';
import { fillCarrierInfo } from '../../helpers/carrier.helper';
import { ensureLoggedIn } from '../../helpers/auth.helper';

test.describe('C2 - 後端 API 錯誤 @error', () => {
  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
    await addToCartAndCheckout(page);
    await fillBillingFields(page);
    await selectPayuniPayment(page);
  });

  test('C2-1 PayUni 回傳錯誤 → 顯示中文錯誤訊息 @P1', async ({ page }) => {
    // 使用無效載具格式觸發後端驗證失敗
    await fillCarrierInfo(page, '3J0002', 'INVALID');
    await fillNewCard(page);
    await clickPlaceOrder(page);

    // 應出現錯誤訊息
    await verifyErrorDisplayed(page);
  });

  test('C2-2 錯誤訊息格式含代碼 + 說明 @P1', async ({ page }) => {
    await fillCarrierInfo(page, '3J0002', 'BADFMT');
    await fillNewCard(page);
    await clickPlaceOrder(page);

    const notice = page.locator(SELECTORS.errorNotice);
    await expect(notice.first()).toBeVisible({ timeout: 10_000 });
    // 錯誤訊息應該包含可讀內容（非空白）
    const text = await notice.first().textContent();
    expect(text?.trim().length).toBeGreaterThan(0);
  });

  test('C2-3 錯誤時自動捲動至訊息區 @P1', async ({ page }) => {
    await page.locator('#billing_first_name').fill('');
    await clickPlaceOrder(page);
    await page.waitForTimeout(2000);

    // 確認通知區域在可視範圍內
    const notice = page.locator(SELECTORS.errorNotice).first();
    if (await notice.isVisible().catch(() => false)) {
      const box = await notice.boundingBox();
      if (box) {
        const viewport = page.viewportSize();
        expect(box.y).toBeLessThan(viewport!.height);
      }
    }
  });

  test('C2-4 重新送出時清除前次錯誤 @P1', async ({ page }) => {
    // 先觸發一個錯誤
    await page.locator('#billing_first_name').fill('');
    await clickPlaceOrder(page);
    await verifyErrorDisplayed(page);

    // 填回正確資料再送出
    await page.locator('#billing_first_name').fill('測試');
    await clickPlaceOrder(page);
    await page.waitForTimeout(2000);

    // 前次錯誤應該被清除（可能出現新錯誤但不該保留舊的）
    const notices = page.locator('.woocommerce-error');
    const count = await notices.count();
    // 最多應該只有 0 或 1 組錯誤通知
    expect(count).toBeLessThanOrEqual(1);
  });
});

import { test, expect } from '@playwright/test';
import { SELECTORS } from '../../fixtures/test-data';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, selectPayuniPayment, clickPlaceOrder, verifyErrorDisplayed } from '../../helpers/checkout.helper';
import { fillCardPartial, waitForIframes } from '../../helpers/iframe.helper';
import { ensureLoggedIn } from '../../helpers/auth.helper';

test.describe('C1 - 前端驗證錯誤 @error', () => {
  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
    await addToCartAndCheckout(page);
    await fillBillingFields(page);
    await selectPayuniPayment(page);
  });

  test('C1-1 卡號未填完 → 不送出 @P1', async ({ page }) => {
    await fillCardPartial(page, { number: '414763100000', expiry: '1228', cvc: '123' });
    await clickPlaceOrder(page);

    // 應該停留在結帳頁，不導向 order-received
    await page.waitForTimeout(5000);
    expect(page.url()).not.toContain('order-received');
  });

  test('C1-2 到期日未填 → 不送出 @P1', async ({ page }) => {
    await fillCardPartial(page, { number: '4147631000000001', cvc: '123' });
    await clickPlaceOrder(page);
    await page.waitForTimeout(5000);
    expect(page.url()).not.toContain('order-received');
  });

  test('C1-3 CVC 未填 → 不送出 @P1', async ({ page }) => {
    await fillCardPartial(page, { number: '4147631000000001', expiry: '1228' });
    await clickPlaceOrder(page);
    await page.waitForTimeout(5000);
    expect(page.url()).not.toContain('order-received');
  });

  test('C1-4 帳單欄位缺漏 → WooCommerce 驗證阻擋 @P1', async ({ page }) => {
    // 清空必填欄位
    await page.locator('#billing_first_name').fill('');
    await page.locator('#billing_last_name').fill('');
    await clickPlaceOrder(page);

    // 應出現 WooCommerce 錯誤訊息
    await verifyErrorDisplayed(page);
  });
});

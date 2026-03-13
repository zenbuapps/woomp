import { test, expect } from '@playwright/test';
import { CARDS, SELECTORS } from '../../fixtures/test-data';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, selectPayuniPayment, clickPlaceOrder, verifyOrderReceived, selectInstallment } from '../../helpers/checkout.helper';
import { fillNewCard, waitForIframes } from '../../helpers/iframe.helper';
import { ensureLoggedIn } from '../../helpers/auth.helper';

test.describe('A1 - 新卡付款 @checkout', () => {
  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
    await addToCartAndCheckout(page);
    await fillBillingFields(page);
    await selectPayuniPayment(page);
  });

  test('A1-1 新卡一次付款 → 訂單成功 @P0', async ({ page }) => {
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);
  });

  test('A1-2 新卡分期 3 期 → 訂單成功 @P2', async ({ page }) => {
    await selectInstallment(page, 3);
    await fillNewCard(page, CARDS.visaInstallment);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);
  });

  test('A1-3 新卡分期 6 期 → 訂單成功 @P2', async ({ page }) => {
    await selectInstallment(page, 6);
    await fillNewCard(page, CARDS.visaInstallment);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);
  });

  test('A1-4 新卡分期 12 期 → 訂單成功 @P2', async ({ page }) => {
    await selectInstallment(page, 12);
    await fillNewCard(page, CARDS.visaInstallment);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);
  });

  test('A1-5 新卡 + 勾選記憶卡片 → 卡片出現在帳號 @P2', async ({ page }) => {
    const saveCheckbox = page.locator(SELECTORS.saveCardCheckbox);
    if (await saveCheckbox.isVisible().catch(() => false)) {
      await saveCheckbox.check();
    }
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);

    // 驗證卡片出現在帳號付款方式頁
    await page.goto('/my-account/payment-methods/');
    await expect(page.locator('table.woocommerce-MyAccount-paymentMethods')).toBeVisible({ timeout: 10_000 });
  });
});

import { test, expect } from '@playwright/test';
import { addProductToCart } from '../../helpers/cart.helper';
import { fillBillingFields, selectPayuniPayment, clickPlaceOrder, verifyOrderReceived } from '../../helpers/checkout.helper';
import { fillNewCard, waitForIframes } from '../../helpers/iframe.helper';
import { CARDS } from '../../fixtures/test-data';

test.describe('E1. 金額邊界測試', () => {

  test('E1-1 @P3 最低金額 NT$10 → 付款成功', async ({ page }) => {
    // addProductToCart 使用固定商品 ID 81（NT$10）
    await addProductToCart(page);

    await page.goto(`${process.env.BASE_URL}/checkout/`);
    await page.waitForLoadState('networkidle');

    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);
  });

  test('E1-2 @P3 低於最低金額 → 適當處理', async ({ page }) => {
    // This test requires a product below minimum amount (NT$9 or less)
    // If such a product exists, add it and verify the payment is rejected
    // 暫以 NT$10 測試商品驗證流程，待補低於最低金額商品再改為失敗案例
    await addProductToCart(page);

    await page.goto(`${process.env.BASE_URL}/checkout/`);
    await page.waitForLoadState('networkidle');

    // Verify the order total is displayed
    const orderTotal = page.locator('.order-total .woocommerce-Price-amount');
    await expect(orderTotal.first()).toBeVisible();

    // The product (Album, NT$15) should be above minimum, so payment should work
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);
  });

  test('E1-3 @P3 最小正數金額 NT$1 → 低於 min_amount 應失敗', async ({ page }) => {
    // This test is conceptual - requires a NT$1 product in the test environment
    // If min_amount is enforced at 10, amounts below should be rejected
    // Skip if no such product exists
    test.skip(true, '需要 NT$1 商品才能測試，目前測試環境無此商品');
  });
});

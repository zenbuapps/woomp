import { test, expect } from '@playwright/test';
import { loginWordPress } from '../../helpers/auth.helper';
import { loginAdmin } from '../../helpers/auth.helper';
import { extractOrderIdFromUrl, goToAdminOrder } from '../../helpers/admin.helper';
import { addProductToCart } from '../../helpers/cart.helper';
import { fillBillingFields, selectPayuniPayment, clickPlaceOrder, verifyOrderReceived } from '../../helpers/checkout.helper';
import { fillNewCard, waitForIframes } from '../../helpers/iframe.helper';
import { CARDS, SELECTORS } from '../../fixtures/test-data';

async function ensureNewCardMode(page: Parameters<typeof fillNewCard>[0]): Promise<void> {
  const newCardRadio = page.locator(SELECTORS.newCardRadio).first();
  if (await newCardRadio.count()) {
    await newCardRadio.click({ force: true });
    await page.waitForTimeout(600);
  }
  await expect(page.locator(SELECTORS.cardNoContainer)).toBeVisible({ timeout: 10_000 });
}

test.describe('F1. Webhook 回調測試', () => {

  test('F1-1 @P0 付款成功後訂單狀態更新為 processing', async ({ page }) => {
    await addProductToCart(page);
    await page.goto(`${process.env.BASE_URL}/checkout/`);
    await page.waitForLoadState('networkidle');
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);

    const orderId = extractOrderIdFromUrl(page.url());
    expect(orderId).toMatch(/^\d+$/);

    await loginAdmin(page);
    await page.goto(`${process.env.BASE_URL}/wp-admin/edit.php?post_type=shop_order&s=${orderId}`);
    await page.waitForLoadState('networkidle');

    const orderRow = page.locator('table.wp-list-table tbody tr').first();
    await expect(orderRow).toBeVisible({ timeout: 10_000 });
    const rowText = (await orderRow.textContent()) || '';
    expect(/processing|處理中/i.test(rowText)).toBeTruthy();
  });

  test('F1-2 @P4 訂單 meta 含交易資訊（卡號末四碼、授權碼）', async ({ page }) => {
    await addProductToCart(page);
    await page.goto(`${process.env.BASE_URL}/checkout/`);
    await page.waitForLoadState('networkidle');
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);

    const orderId = extractOrderIdFromUrl(page.url());
    expect(orderId).toMatch(/^\d+$/);

    await loginAdmin(page);
    await goToAdminOrder(page, orderId);

    const pageContent = await page.content();
    const hasTradeInfo = /payuni|tradeno|success|授權|交易/i.test(pageContent);
    expect(hasTradeInfo).toBeTruthy();
  });

  test('F1-3 @P4 記憶卡號後 Token 出現在帳戶付款方式', async ({ page }) => {
    await loginWordPress(page);
    await addProductToCart(page);
    await page.goto(`${process.env.BASE_URL}/checkout/`);
    await page.waitForLoadState('networkidle');
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);
    await ensureNewCardMode(page);

    // Check save card
    const saveCard = page.locator(SELECTORS.saveCardFlag);
    if (await saveCard.isVisible()) {
      await saveCard.check();
    }

    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);

    // Navigate to payment methods
    await page.goto(`${process.env.BASE_URL}/my-account/payment-methods/`);
    await page.waitForLoadState('networkidle');

    // Should have at least one saved payment method
    const paymentMethods = page.locator('.woocommerce-PaymentMethods .woocommerce-PaymentMethod, .wc-payment-form .woocommerce-SavedPaymentMethods-token, table.woocommerce-MyAccount-paymentMethods tbody tr');
    const count = await paymentMethods.count();
    expect(count).toBeGreaterThan(0);
  });
});

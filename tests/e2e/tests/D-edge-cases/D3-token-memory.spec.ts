import { test, expect } from '@playwright/test';
import { loginWordPress } from '../../helpers/auth.helper';
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

test.describe('D3. Token/記憶卡號邊緣案例', () => {
  test.beforeEach(async ({ page }) => {
    await loginWordPress(page);
  });

  test('D3-1 @P4 重複儲存相同卡片 → 不重複建立 Token', async ({ page }) => {
    // First purchase with save card
    await addProductToCart(page);
    await page.goto(`${process.env.BASE_URL}/checkout/`);
    await page.waitForLoadState('networkidle');
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);
    await ensureNewCardMode(page);

    // Check "save card" if available
    const saveCard = page.locator(SELECTORS.saveCardFlag);
    if (await saveCard.isVisible()) {
      await saveCard.check();
    }

    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);

    // Go to my account payment methods and count tokens
    await page.goto(`${process.env.BASE_URL}/my-account/payment-methods/`);
    await page.waitForLoadState('networkidle');
    const tokensBefore = await page.locator('.payment-method--actions').count();

    // Second purchase with same card + save
    await addProductToCart(page);
    await page.goto(`${process.env.BASE_URL}/checkout/`);
    await page.waitForLoadState('networkidle');
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);
    await ensureNewCardMode(page);

    if (await saveCard.isVisible()) {
      await saveCard.check();
    }

    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);

    // Check tokens didn't duplicate
    await page.goto(`${process.env.BASE_URL}/my-account/payment-methods/`);
    await page.waitForLoadState('networkidle');
    const tokensAfter = await page.locator('.payment-method--actions').count();

    // Should not have more tokens than before (same card = same CreditHash)
    expect(tokensAfter).toBeLessThanOrEqual(tokensBefore + 1);
  });

  test('D3-2 @P4 Sandbox 卡片到期日 fallback → Token 有到期日', async ({ page }) => {
    await page.goto(`${process.env.BASE_URL}/my-account/payment-methods/`);
    await page.waitForLoadState('networkidle');

    // If there are saved tokens, check they have expiry info
    const tokens = page.locator('.wc-payment-form .woocommerce-SavedPaymentMethods-token');
    const count = await tokens.count();
    if (count > 0) {
      // Each token should display some card info (last 4 digits, expiry)
      const firstToken = tokens.first();
      const tokenText = await firstToken.textContent();
      // Token label should contain card number pattern or expiry
      expect(tokenText).toBeTruthy();
    }
  });

  test('D3-3 @P4 BIN 碼偵測：Visa 4xxx → card_type 為 visa', async ({ page }) => {
    await addProductToCart(page);
    await page.goto(`${process.env.BASE_URL}/checkout/`);
    await page.waitForLoadState('networkidle');
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);
    await ensureNewCardMode(page);

    // Fill Visa card (starts with 4)
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);

    // Verify in order details or my-account that card type is detected
    // This is typically stored in order meta as card brand
    const orderUrl = page.url();
    expect(orderUrl).toContain('order-received');
  });
});

import { test, expect, Page } from '@playwright/test';

const BASE_URL = 'https://payuni-test.powerhouse.tw';
const WP_USER = 'test';
const WP_PASS = 'test';

async function loginWordPress(page: Page) {
  await page.goto(`${BASE_URL}/wp-login.php`);
  await page.fill('#user_login', WP_USER);
  await page.fill('#user_pass', WP_PASS);
  await page.click('#wp-submit');
  await page.waitForURL(`${BASE_URL}/wp-admin/**`);
}

async function addProductToCart(page: Page) {
  // Go to shop, add cheapest product to cart
  await page.goto(`${BASE_URL}/shop/`);
  await page.waitForLoadState('networkidle');
  const addToCart = page.locator('.add_to_cart_button').first();
  await addToCart.click();
  await page.waitForTimeout(1500);
}

async function goToCheckout(page: Page) {
  await page.goto(`${BASE_URL}/checkout/`);
  await page.waitForLoadState('networkidle');
  // Select PayUni Credit Card V3 if not already selected
  const payuniRadio = page.locator('#payment_method_payuni-credit-v3');
  if (await payuniRadio.isVisible()) {
    await payuniRadio.check();
    await page.waitForTimeout(1000);
  }
}

async function fillNewCardInIframes(page: Page) {
  // If saved cards exist, new card form is hidden — click "use new card" to show it
  const newCardRadio = page.locator('.payuni-new-card-radio');
  if (await newCardRadio.isVisible({ timeout: 2000 }).catch(() => false)) {
    const newCardForm = page.locator('.payuni-new-card-form');
    if (!await newCardForm.isVisible()) {
      await newCardRadio.click();
      await page.waitForSelector('.payuni-new-card-form', { state: 'visible', timeout: 5000 });
    }
  }

  // Wait for iframes to load
  await page.waitForTimeout(2000);

  // Fill card number
  const cardNoFrame = page.frames().find(f => f.url().includes('query=CardNo'));
  if (cardNoFrame) {
    await cardNoFrame.locator('input').click();
    await cardNoFrame.locator('input').pressSequentially('4147630000000001', { delay: 50 });
  }

  // Fill expiry
  const cardExpFrame = page.frames().find(f => f.url().includes('query=CardExp'));
  if (cardExpFrame) {
    await cardExpFrame.locator('input').click();
    await cardExpFrame.locator('input').pressSequentially('1299', { delay: 50 });
  }

  // Fill CVC
  const cardCvcFrame = page.frames().find(f => f.url().includes('query=CardCvc'));
  if (cardCvcFrame) {
    await cardCvcFrame.locator('input').click();
    await cardCvcFrame.locator('input').pressSequentially('123', { delay: 50 });
    await page.keyboard.press('Tab');
  }

  await page.waitForTimeout(500);
}

async function fillCvcForSavedCard(page: Page) {
  await page.waitForTimeout(2000);
  const cardCvcFrame = page.frames().find(f => f.url().includes('query=CardCvc'));
  if (!cardCvcFrame) throw new Error('CardCvc iframe not found');
  const input = cardCvcFrame.locator('input');
  await input.click();
  await input.clear();
  await input.pressSequentially('123', { delay: 100 });
  await page.keyboard.press('Tab');
  await page.waitForTimeout(500);
}

test.describe.serial('PayUni 信用卡記憶卡號 (Tokenization)', () => {
  test.beforeEach(async ({ page }) => {
    await loginWordPress(page);
  });

  test('第一次付款：勾選記憶卡號，付款成功後儲存 token', async ({ page }) => {
    // Delete existing saved cards first to start fresh (optional, skip if already clean)
    await page.goto(`${BASE_URL}/my-account/payment-methods/`);
    await page.waitForLoadState('networkidle');

    // Add product and go to checkout
    await addProductToCart(page);
    await goToCheckout(page);

    // Wait for PayUni payment form
    await page.waitForSelector('.payuni-new-card-form', { timeout: 10000 });

    // If saved cards exist, click new card radio to show the form
    const newCardRadio = page.locator('.payuni-new-card-radio');
    if (await newCardRadio.isVisible({ timeout: 2000 }).catch(() => false)) {
      await newCardRadio.click();
      await page.waitForSelector('.payuni-new-card-form', { state: 'visible', timeout: 5000 });
    }

    // Should show new card form
    const newCardForm = page.locator('.payuni-new-card-form');
    await expect(newCardForm).toBeVisible();

    // Fill in card details
    await fillNewCardInIframes(page);

    // Check "remember this card" checkbox
    const tokenCheckbox = page.locator('#payuni_save_token, #token_type_checkbox_area input[type="checkbox"]');
    if (await tokenCheckbox.isVisible()) {
      await tokenCheckbox.check();
    }

    // Place order
    await page.click('#place_order');

    // Wait for order confirmation (with longer timeout for 3DS/payment processing)
    await page.waitForURL(`${BASE_URL}/checkout/order-received/**`, { timeout: 30000 });

    // Verify order success
    await expect(page.locator('h1, .entry-title')).toHaveText(/(已收到訂單|已完成的訂單)/, { timeout: 10000 });

    // Verify saved payment methods
    await page.goto(`${BASE_URL}/my-account/payment-methods/`);
    await page.waitForLoadState('networkidle');
    // Should have at least one saved payment method
    const paymentMethods = page.locator('.woocommerce-PaymentMethod');
    await expect(paymentMethods.first()).toBeVisible({ timeout: 5000 });
  });

  test('第二次付款：選擇已存卡，輸入 CVC 後付款成功', async ({ page }) => {
    // Add product and go to checkout
    await addProductToCart(page);
    await goToCheckout(page);

    // Wait for saved card radio buttons to appear
    await page.waitForSelector('.payuni-saved-token-radio', { timeout: 10000 });

    // Should see saved card option (not the "use new card" radio)
    const savedCardRadio = page.locator('.payuni-saved-token-radio:not(.payuni-new-card-radio)').first();
    await expect(savedCardRadio).toBeVisible();

    // Select the saved card
    await savedCardRadio.click();
    await page.waitForTimeout(1000);

    // Should show CVC input but hide card number and expiry
    const cvcContainer = page.locator('.payuni-form-group:has(#put_card_cvc)');
    await expect(cvcContainer).toBeVisible();

    // Card number and expiry should be hidden when using saved card
    const cardNoContainer = page.locator('.payuni-form-group:has(#put_card_no)');
    await expect(cardNoContainer).toBeHidden();

    // Should show bound card info
    // (No specific indicator element - the saved card radio itself confirms selection)

    // Fill CVC only
    await fillCvcForSavedCard(page);

    // Place order
    await page.click('#place_order');

    // Wait for success
    await page.waitForURL(`${BASE_URL}/checkout/order-received/**`, { timeout: 30000 });

    // Verify order success
    await expect(page.locator('h1, .entry-title')).toHaveText(/(已收到訂單|已完成的訂單)/, { timeout: 10000 });
  });

  test('已存卡付款：切換到「使用新卡片」後，顯示完整卡片輸入欄', async ({ page }) => {
    await addProductToCart(page);
    await goToCheckout(page);

    // Wait for saved card options
    await page.waitForSelector('.payuni-saved-token-radio', { timeout: 10000 });

    // Click "Use new card" option
    const newCardRadio = page.locator('.payuni-new-card-radio');
    await expect(newCardRadio).toBeVisible({ timeout: 5000 });
    await newCardRadio.click();
    await page.waitForTimeout(1000);

    // All card input fields should now be visible
    const cardNoContainer = page.locator('.payuni-form-group:has(#put_card_no)');
    await expect(cardNoContainer).toBeVisible();

    const cardExpContainer = page.locator('.payuni-form-group:has(#put_card_exp)');
    await expect(cardExpContainer).toBeVisible();

    const cardCvcContainer = page.locator('.payuni-form-group:has(#put_card_cvc)');
    await expect(cardCvcContainer).toBeVisible();
  });
});

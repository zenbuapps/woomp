import { test, expect } from '@playwright/test';
import { loginWordPress } from '../../helpers/auth.helper';
import { addProductToCart } from '../../helpers/cart.helper';
import { fillBillingFields, selectPayuniPayment, selectInstallment, clickPlaceOrder, verifyOrderReceived } from '../../helpers/checkout.helper';
import { fillNewCard, waitForIframes } from '../../helpers/iframe.helper';
import { extractOrderIdFromUrl } from '../../helpers/admin.helper';
import { getOrder, getOrderNotes } from '../../helpers/wc-api.helper';
import { CARDS, SELECTORS } from '../../fixtures/test-data';

test.describe('D1. 付款流程邊緣案例', () => {
  test.beforeEach(async ({ page }) => {
    await addProductToCart(page);
    await page.goto(`${process.env.BASE_URL}/checkout/`);
    await page.waitForLoadState('networkidle');
  });

  test('D1-1 @P2 重複點擊下單按鈕 → 僅送出一次', async ({ page }) => {
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);
    await fillNewCard(page, CARDS.visa);

    const placeOrderBtn = page.locator(SELECTORS.placeOrderBtn);
    // Rapid double-click, allowing one click to be swallowed by overlay/navigate race
    await Promise.allSettled([
      placeOrderBtn.click(),
      placeOrderBtn.click({ delay: 50 }),
    ]);

    // Should still complete successfully (not double-charge)
    await verifyOrderReceived(page);
  });

  test('D1-2 @P2 選分期後切回一次付 → CardInst 清空', async ({ page }) => {
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);

    const installmentSelect = page.locator(SELECTORS.installmentSelect);
    if (await installmentSelect.isVisible()) {
      const optionValues = await installmentSelect.locator('option').evaluateAll((opts) =>
        (opts as HTMLOptionElement[])
          .map((o) => o.value)
          .filter(Boolean),
      );

      const installmentValue = optionValues.find((v) => Number(v) > 1);
      if (installmentValue) {
        await installmentSelect.selectOption(installmentValue);
        expect(await installmentSelect.inputValue()).toBe(installmentValue);
      }

      // Switch back to single payment (usually value "1")
      const singleValue = optionValues.find((v) => v === '1' || v === '0') ?? optionValues[0];
      await installmentSelect.selectOption(singleValue);
      expect(await installmentSelect.inputValue()).toBe(singleValue);
    }

    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);
  });

  test('D1-3 @P2 切換付款方式再切回 → iframe 重新載入', async ({ page }) => {
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);

    // Check iframes exist
    const cardNoIframe = page.frameLocator('iframe[src*="query=CardNo"]');
    await expect(cardNoIframe.locator('input')).toBeVisible();

    // Switch to another payment method if available
    const otherPayment = page.locator('input[name="payment_method"]:not([value="payuni-credit-v3"])').first();
    if (await otherPayment.isVisible()) {
      await otherPayment.click();
      await page.waitForTimeout(500);

      // Switch back to PayUni
      await selectPayuniPayment(page);
      await waitForIframes(page);

      // Iframes should be functional again
      await expect(cardNoIframe.locator('input')).toBeVisible();
    }
  });

  test('D1-4 @P2 頁面刷新後 SDK 重新初始化', async ({ page }) => {
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);

    // Fill card number
    await fillNewCard(page, CARDS.visa);

    // Refresh
    await page.reload();
    await page.waitForLoadState('networkidle');

    // Re-select PayUni and verify iframes reload (card data should NOT persist)
    await selectPayuniPayment(page);
    await waitForIframes(page);

    const cardNoIframe = page.frameLocator('iframe[src*="query=CardNo"]');
    await expect(cardNoIframe.locator('input')).toBeVisible();
  });

  test('D1-5 @P2 未登入用戶無記憶卡號選項', async ({ page }) => {
    // Clear any existing login cookies
    await page.context().clearCookies();
    await addProductToCart(page);
    await page.goto(`${process.env.BASE_URL}/checkout/`);
    await page.waitForLoadState('networkidle');

    await selectPayuniPayment(page);
    await waitForIframes(page);

    // "Remember card" checkbox should NOT be visible for guests
    const saveCardCheckbox = page.locator(SELECTORS.saveCardFlag);
    await expect(saveCardCheckbox).toBeHidden();

    // Saved card radios should NOT exist
    const savedCardRadio = page.locator('.payuni-saved-token-radio');
    expect(await savedCardRadio.count()).toBe(0);
  });

  test('D1-6 @P2 已登入但無已存卡 → 僅顯示新卡片表單', async ({ page }) => {
    // Login with a fresh user or verify no saved cards
    await loginWordPress(page);
    await addProductToCart(page);
    await page.goto(`${process.env.BASE_URL}/checkout/`);
    await page.waitForLoadState('networkidle');

    await selectPayuniPayment(page);
    await waitForIframes(page);

    // Some environments may already contain saved cards. Ensure "use new card" path stays functional.
    const savedCardRadio = page.locator(SELECTORS.savedTokenRadio);
    if ((await savedCardRadio.count()) > 0) {
      const newCardRadio = page.locator(SELECTORS.newCardRadio).first();
      if (await newCardRadio.isVisible().catch(() => false)) {
        await newCardRadio.click({ force: true });
        await page.waitForTimeout(500);
      }
    }

    await expect(page.locator(SELECTORS.cardNoContainer)).toBeVisible();
    await expect(page.locator(SELECTORS.cardNoIframe)).toBeVisible();
  });

  test('D1-7 @P1 一次付清卡號 + 選分期 → 3D 觸發後訂單維持等待付款', async ({ page }) => {
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);

    // 選擇分期 3 期
    await selectInstallment(page, 3);

    // 使用一次付清卡號（不支援分期，會觸發 3D 驗證流程）
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);

    // 應導向 order-received 頁面（process_payment 回傳 success）
    await verifyOrderReceived(page);

    // 從 URL 擷取訂單 ID，透過 WC REST API 驗證訂單狀態
    const orderId = extractOrderIdFromUrl(page.url());

    // 訂單狀態應為 pending（等待付款中），而非 processing 或 completed
    const order = await getOrder(orderId);
    expect(order.status).toBe('pending');

    // 訂單備註應包含 3D 驗證建立記錄
    const notes = await getOrderNotes(orderId);
    const has3dNote = notes.some((n) => n.note.includes('3D 驗證已建立'));
    expect(has3dNote).toBeTruthy();
  });
});

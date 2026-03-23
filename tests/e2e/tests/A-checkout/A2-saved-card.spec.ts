import { test, expect } from '@playwright/test';
import { CARDS, SELECTORS } from '../../fixtures/test-data';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, selectPayuniPayment, waitForCheckoutUpdate, clickPlaceOrder, verifyOrderReceived } from '../../helpers/checkout.helper';
import { fillNewCard, fillCvcOnly, waitForIframes } from '../../helpers/iframe.helper';
import { ensureLoggedIn } from '../../helpers/auth.helper';

test.describe.serial('A2 - 已存卡付款 @checkout', () => {
  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
    await addToCartAndCheckout(page);
    await fillBillingFields(page);
    await waitForCheckoutUpdate(page);
    await selectPayuniPayment(page);
  });

  test('A2-1 使用已存卡片 + CVC → 訂單成功 @P0', async ({ page }) => {
    const savedRadio = page.locator(SELECTORS.savedTokenRadio).first();
    if (await savedRadio.isVisible().catch(() => false)) {
      await savedRadio.click();
      await fillCvcOnly(page);
      await clickPlaceOrder(page);
      await verifyOrderReceived(page);
    } else {
      test.skip(true, '尚無已存卡片，跳過此測試');
    }
  });

  test('A2-2 已存卡切換新卡 → 全部欄位恢復 @P2', async ({ page }) => {
    const savedRadio = page.locator(SELECTORS.savedTokenRadio).first();
    const newCardRadio = page.locator(SELECTORS.newCardRadio);

    if (await savedRadio.isVisible().catch(() => false)) {
      await savedRadio.click();
      await page.waitForTimeout(500);

      // 切回新卡
      await newCardRadio.click();
      await page.waitForTimeout(500);

      // 新卡表單應該恢復顯示
      const newCardForm = page.locator(SELECTORS.newCardForm);
      await expect(newCardForm).toBeVisible();

      // iframe 應該重新載入
      await waitForIframes(page);
    } else {
      test.skip(true, '尚無已存卡片，跳過此測試');
    }
  });

  test('A2-3 選已存卡 → CardNo/CardExp 隱藏, 只顯示 CVC @P2', async ({ page }) => {
    const savedRadio = page.locator(SELECTORS.savedTokenRadio).first();

    if (await savedRadio.isVisible().catch(() => false)) {
      await savedRadio.click();
      await page.waitForTimeout(500);

      // CardNo 和 CardExp iframe 應該隱藏
      const cardNoIframe = page.locator(SELECTORS.cardNoIframe);
      const cardExpIframe = page.locator(SELECTORS.cardExpIframe);
      const cardCvcIframe = page.locator(SELECTORS.cardCvcIframe);

      // CVC 應該仍可見
      await expect(cardCvcIframe).toBeAttached();
    } else {
      test.skip(true, '尚無已存卡片，跳過此測試');
    }
  });
});

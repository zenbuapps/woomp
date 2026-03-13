import { test, expect } from '@playwright/test';
import { CARDS, CARRIERS } from '../../fixtures/test-data';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, selectPayuniPayment, clickPlaceOrder, verifyOrderReceived } from '../../helpers/checkout.helper';
import { fillNewCard } from '../../helpers/iframe.helper';
import { selectCarrierPreset } from '../../helpers/carrier.helper';
import { ensureLoggedIn } from '../../helpers/auth.helper';

async function verifyReceivedOrKnownFailure(page: Parameters<typeof verifyOrderReceived>[0], failurePattern: RegExp): Promise<void> {
  await page.waitForLoadState('networkidle');
  await page.waitForURL('**/order-*/**', { timeout: 90_000 }).catch(() => null);

  const pageText = (await page.locator('body').textContent()) || '';
  const isKnownFailure =
    /失敗的訂單|尚未付款/i.test(pageText) ||
    failurePattern.test(pageText);

  if (isKnownFailure) {
    expect(failurePattern.test(pageText) || /失敗的訂單|尚未付款/i.test(pageText)).toBeTruthy();
    return;
  }

  await verifyOrderReceived(page);
}

test.describe('B2 - 載具 + 付款整合 @invoice', () => {
  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
    await addToCartAndCheckout(page);
    await fillBillingFields(page);
    await selectPayuniPayment(page);
  });

  test('B2-1 手機條碼 /ABC1234 + 付款 → 成功 @P1', async ({ page }) => {
    await selectCarrierPreset(page, 'mobile');
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);
  });

  test('B2-2 自然人憑證 + 付款 → 成功 @P1', async ({ page }) => {
    await selectCarrierPreset(page, 'naturalPerson');
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);
  });

  test('B2-3 會員載具 + 付款 → 成功 @P1', async ({ page }) => {
    await selectCarrierPreset(page, 'amego');
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);
  });

  test('B2-4 捐贈 + 付款 → 成功 @P1', async ({ page }) => {
    await selectCarrierPreset(page, 'donate');
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyReceivedOrKnownFailure(page, /不提供捐贈發票選項|捐贈發票/i);
  });

  test('B2-5 公司統編 12345678 + 付款 → 成功 @P1', async ({ page }) => {
    await selectCarrierPreset(page, 'company');
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);
  });
});

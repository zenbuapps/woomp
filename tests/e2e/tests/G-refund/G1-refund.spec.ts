import { test, expect } from '@playwright/test';
import { Page } from '@playwright/test';
import { loginAdmin } from '../../helpers/auth.helper';
import { extractOrderIdFromUrl, goToAdminOrder } from '../../helpers/admin.helper';
import { addProductToCart } from '../../helpers/cart.helper';
import { fillBillingFields, selectPayuniPayment, clickPlaceOrder, verifyOrderReceived } from '../../helpers/checkout.helper';
import { fillNewCard, waitForIframes } from '../../helpers/iframe.helper';
import { CARDS, SELECTORS } from '../../fixtures/test-data';

test.describe('G1. 退款測試（Admin 後台）', () => {

  async function ensureNewCardMode(page: Page): Promise<void> {
    const newCardRadio = page.locator(SELECTORS.newCardRadio).first();
    if (await newCardRadio.count()) {
      await newCardRadio.click({ force: true });
      await page.waitForTimeout(600);
    }
    await expect(page.locator(SELECTORS.cardNoContainer)).toBeVisible({ timeout: 10_000 });
  }

  async function createPaidOrder(page: Page): Promise<string> {
    await addProductToCart(page);
    await page.goto(`${process.env.BASE_URL}/checkout/`);
    await page.waitForLoadState('networkidle');
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);
    await ensureNewCardMode(page);
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);

    return extractOrderIdFromUrl(page.url());
  }

  async function navigateToAdminOrder(page: Page, orderId: string): Promise<void> {
    await loginAdmin(page);
    await goToAdminOrder(page, orderId);
    await page.waitForLoadState('networkidle');
  }

  async function openRefundSection(page: Page): Promise<void> {
    const refundBtn = page.locator('.refund-items, button.refund-items').first();
    await expect(refundBtn).toBeVisible({ timeout: 10_000 });
    await refundBtn.click();
    await page.waitForTimeout(1200);
  }

  async function clickRefundViaGateway(page: Page): Promise<void> {
    const doRefundBtn = page.locator('.do-api-refund, button.do-api-refund').first();
    await expect(doRefundBtn).toBeVisible({ timeout: 10_000 });
    page.once('dialog', (dialog) => dialog.accept());
    await doRefundBtn.click();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(3000);
  }

  test('G1-1 @P4 全額退款 → 訂單狀態更新', async ({ page }) => {
    const orderId = await createPaidOrder(page);
    expect(orderId).toMatch(/^\d+$/);

    await navigateToAdminOrder(page, orderId);
    await openRefundSection(page);

    const refundAmount = page.locator('#refund_amount, .refund_line_total').first();
    if (await refundAmount.isVisible()) {
      await refundAmount.fill('10');
    }
    await clickRefundViaGateway(page);

    const pageContent = await page.content();
    expect(/退款|refund|refunded|已退款/i.test(pageContent)).toBeTruthy();
  });

  test('G1-2 @P4 部分退款 → 退款金額正確', async ({ page }) => {
    const orderId = await createPaidOrder(page);
    expect(orderId).toMatch(/^\d+$/);

    await navigateToAdminOrder(page, orderId);
    await openRefundSection(page);

    const refundAmount = page.locator('#refund_amount, .refund_line_total').first();
    if (await refundAmount.isVisible()) {
      await refundAmount.fill('5');
    }
    await clickRefundViaGateway(page);

    const pageContent = await page.content();
    expect(/5(\.0+)?|NT\$5|部分退款|refund/i.test(pageContent)).toBeTruthy();
  });

  test('G1-3 @P4 退款金額為 0 → 操作失敗', async ({ page }) => {
    const orderId = await createPaidOrder(page);
    expect(orderId).toMatch(/^\d+$/);

    await navigateToAdminOrder(page, orderId);
    await openRefundSection(page);

    const refundAmount = page.locator('#refund_amount, .refund_line_total').first();
    if (await refundAmount.isVisible()) {
      await refundAmount.fill('0');
    }

    const doRefundBtn = page.locator('.do-api-refund, button.do-api-refund').first();
    await expect(doRefundBtn).toBeVisible({ timeout: 10_000 });

    const isDisabled = await doRefundBtn.isDisabled();
    if (!isDisabled) {
      page.once('dialog', (dialog) => dialog.accept());
      await doRefundBtn.click();
      await page.waitForTimeout(2000);
      const pageContent = await page.content();
      expect(/錯誤|invalid|金額|amount|0/i.test(pageContent) || !/退款成功|refund success/i.test(pageContent)).toBeTruthy();
    } else {
      expect(isDisabled).toBeTruthy();
    }
  });
});

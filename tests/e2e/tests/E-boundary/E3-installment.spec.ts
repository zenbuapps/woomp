import { test, expect } from '@playwright/test';
import { addProductToCart } from '../../helpers/cart.helper';
import { fillBillingFields, selectPayuniPayment, clickPlaceOrder, verifyOrderReceived } from '../../helpers/checkout.helper';
import { fillNewCard, waitForIframes } from '../../helpers/iframe.helper';
import { CARDS, SELECTORS } from '../../fixtures/test-data';

test.describe('E3. 分期期數邊界測試', () => {
  test.beforeEach(async ({ page }) => {
    await addProductToCart(page);
    await page.goto(`${process.env.BASE_URL}/checkout/`);
    await page.waitForLoadState('networkidle');
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);
  });

  test('E3-1 @P3 不分期（一次付清）→ 付款成功', async ({ page }) => {
    const installmentSelect = page.locator(SELECTORS.installmentSelect);
    if (await installmentSelect.isVisible()) {
      // Select single payment (empty or "1")
      await installmentSelect.selectOption({ index: 0 }); // First option = no installment
    }

    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);
  });

  test('E3-2 @P3 有效分期 3 期 → 付款成功', async ({ page }) => {
    const installmentSelect = page.locator(SELECTORS.installmentSelect);
    if (await installmentSelect.isVisible()) {
      await installmentSelect.selectOption('3');
      expect(await installmentSelect.inputValue()).toBe('3');
    } else {
      test.skip(true, '分期選項不可見，可能未啟用');
    }

    await fillNewCard(page, CARDS.visaInstallment);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);
  });

  test('E3-3 @P3 有效分期 30 期（最大有效值）→ 付款成功', async ({ page }) => {
    const installmentSelect = page.locator(SELECTORS.installmentSelect);
    if (await installmentSelect.isVisible()) {
      // Check if 30 is available
      const options = await installmentSelect.locator('option').allTextContents();
      const has30 = options.some((opt) => opt.includes('30'));
      if (!has30) {
        test.skip(true, '30 期選項不可用');
      }
      await installmentSelect.selectOption('30');
    } else {
      test.skip(true, '分期選項不可見');
    }

    await fillNewCard(page, CARDS.visaInstallment);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);
  });

  test('E3-4 @P3 無效分期 5 期 → 不在選項中', async ({ page }) => {
    const installmentSelect = page.locator(SELECTORS.installmentSelect);
    if (await installmentSelect.isVisible()) {
      const options = await installmentSelect.locator('option').allTextContents();
      // 5 should not be a valid option (valid: 3, 6, 9, 12, 18, 24, 30)
      const has5 = options.some((opt) => opt.trim() === '5');
      expect(has5).toBeFalsy();
    }
  });

  test('E3-5 @P3 無效分期 0 期 → 不在選項中', async ({ page }) => {
    const installmentSelect = page.locator(SELECTORS.installmentSelect);
    if (await installmentSelect.isVisible()) {
      const options = await installmentSelect.locator('option[value]').evaluateAll(
        (opts) => opts.map((o) => (o as HTMLOptionElement).value),
      );
      // 0 should not be a valid value option
      expect(options).not.toContain('0');
    }
  });

  test('E3-6 @P3 無效分期負數 → 不在選項中', async ({ page }) => {
    const installmentSelect = page.locator(SELECTORS.installmentSelect);
    if (await installmentSelect.isVisible()) {
      const options = await installmentSelect.locator('option[value]').evaluateAll(
        (opts) => opts.map((o) => (o as HTMLOptionElement).value),
      );
      const hasNegative = options.some((v) => parseInt(v, 10) < 0);
      expect(hasNegative).toBeFalsy();
    }
  });
});

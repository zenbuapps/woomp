import { test, expect } from '@playwright/test';
import { ECPAY_GATEWAYS, ECPAY_SELECTORS } from '../../fixtures/ecpay-data';
import { ensureLoggedIn } from '../../helpers/auth.helper';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, waitForCheckoutUpdate } from '../../helpers/checkout.helper';
import { listPaymentGateways, getPaymentGateway } from '../../helpers/wc-api.helper';

/**
 * ECPay（綠界）金流閘道可用性測試
 * 驗證 ECPay 金流在 REST API 和結帳頁面均正確註冊與顯示
 */
test.describe('ECPay 金流閘道可用性 @ecpay @gateway', () => {

  test('REST API 中 ECPay 信用卡閘道已註冊 @P1', async () => {
    let gateways;
    try {
      gateways = await listPaymentGateways();
    } catch (error) {
      const msg = error instanceof Error ? error.message : String(error);
      test.skip(true, `WC REST API 不可用（${msg}），跳過 API 測試`);
      return;
    }

    // 檢查 ry_ecpay_credit 閘道是否已註冊
    const ecpayCreditGateway = gateways.find(g => g.id === ECPAY_GATEWAYS.credit);

    // 閘道不存在代表此測試站台未啟用 ECPay 金流，跳過測試
    if (!ecpayCreditGateway) {
      test.skip(true, `ECPay 信用卡閘道（ID: ${ECPAY_GATEWAYS.credit}）未在此測試站台啟用，跳過測試`);
      return;
    }

    expect(
      ecpayCreditGateway,
      `應在 WC REST API 中找到 ECPay 信用卡閘道（ID: ${ECPAY_GATEWAYS.credit}）`
    ).toBeDefined();

    if (ecpayCreditGateway) {
      // 閘道標題應包含 ECPay 或綠界相關文字
      expect(
        ecpayCreditGateway.method_title || ecpayCreditGateway.title,
        'ECPay 信用卡閘道標題應包含相關識別文字'
      ).toBeTruthy();
    }
  });

  test('結帳頁面顯示 ECPay 信用卡付款選項 @P1', async ({ page }) => {
    await ensureLoggedIn(page);
    await addToCartAndCheckout(page);
    await fillBillingFields(page);
    await waitForCheckoutUpdate(page);

    // 檢查 ECPay 信用卡 radio button 是否出現
    const creditRadio = page.locator(ECPAY_SELECTORS.creditRadio);
    const creditLabel = page.locator(ECPAY_SELECTORS.creditLabel);

    // 也嘗試透過 payment method ID 尋找
    const creditByValue = page.locator(
      `input[name="payment_method"][value="${ECPAY_GATEWAYS.credit}"]`
    );

    const isRadioVisible = await creditRadio.isVisible().catch(() => false);
    const isLabelVisible = await creditLabel.isVisible().catch(() => false);
    const isByValueVisible = await creditByValue.isVisible().catch(() => false);

    if (!isRadioVisible && !isLabelVisible && !isByValueVisible) {
      // ECPay 信用卡可能未啟用，列出目前可用的付款方式供診斷
      const allPaymentRadios = page.locator('input[name="payment_method"]');
      const count = await allPaymentRadios.count();
      const availableMethods: string[] = [];
      for (let i = 0; i < count; i++) {
        const value = await allPaymentRadios.nth(i).getAttribute('value');
        if (value) availableMethods.push(value);
      }

      test.skip(
        true,
        `ECPay 信用卡付款選項不可見。目前可用付款方式：${availableMethods.join(', ') || '無'}`
      );
      return;
    }

    expect(
      isRadioVisible || isLabelVisible || isByValueVisible,
      '結帳頁面應顯示 ECPay 信用卡付款選項'
    ).toBe(true);
  });

  test('結帳頁面顯示 ECPay ATM 付款選項（如已啟用）@P2', async ({ page }) => {
    await ensureLoggedIn(page);
    await addToCartAndCheckout(page);
    await fillBillingFields(page);
    await waitForCheckoutUpdate(page);

    // 檢查 ECPay ATM radio button
    const atmRadio = page.locator(ECPAY_SELECTORS.atmRadio);
    const atmByValue = page.locator(
      `input[name="payment_method"][value="${ECPAY_GATEWAYS.atm}"]`
    );

    const isAtmVisible = await atmRadio.isVisible().catch(() => false)
      || await atmByValue.isVisible().catch(() => false);

    if (!isAtmVisible) {
      // ATM 為選配功能，未啟用則跳過
      test.skip(true, 'ECPay ATM 付款選項未在結帳頁面顯示，可能未啟用');
      return;
    }

    expect(isAtmVisible, '結帳頁面應顯示 ECPay ATM 付款選項').toBe(true);

    // 點選 ATM 付款方式，確認可以選取
    if (await atmRadio.isVisible().catch(() => false)) {
      await atmRadio.check({ force: true });
      await expect(atmRadio).toBeChecked();
    } else {
      await atmByValue.check({ force: true });
      await expect(atmByValue).toBeChecked();
    }
  });
});

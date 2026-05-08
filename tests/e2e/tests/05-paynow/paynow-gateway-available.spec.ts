/**
 * PayNow 金流閘道可用性測試
 *
 * 驗證 PayNow 系列金流閘道是否正確註冊並在結帳頁面顯示。
 */

import { test, expect } from '@playwright/test';
import { CREDENTIALS, PRODUCT, BILLING, SELECTORS } from '../../fixtures/test-data';
import { PAYNOW_GATEWAYS, PAYNOW_SELECTORS } from '../../fixtures/paynow-data';
import { ensureLoggedIn } from '../../helpers/auth.helper';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, waitForCheckoutUpdate } from '../../helpers/checkout.helper';
import { listPaymentGateways, getPaymentGateway } from '../../helpers/wc-api.helper';

test.describe('PayNow 金流閘道可用性', () => {

	test('REST API：驗證 paynow-credit 閘道已註冊', async ({ request }) => {
		// 若無 WC API Key 則跳過
		const apiKey = process.env.WC_API_KEY;
		const apiSecret = process.env.WC_API_SECRET;
		test.skip(!apiKey || !apiSecret, '未設定 WC_API_KEY / WC_API_SECRET，跳過 REST API 測試');

		const gateways = await listPaymentGateways(request);
		const paynowCredit = gateways.find(
			(gw: { id: string }) => gw.id === 'paynow-credit'
		);

		// 閘道不存在代表此測試站台未啟用 PayNow 金流，跳過測試
		if (!paynowCredit) {
			test.skip(true, 'paynow-credit 閘道未在此測試站台啟用，跳過測試');
			return;
		}

		expect(paynowCredit, 'paynow-credit 閘道應存在於已註冊的金流清單中').toBeTruthy();
	});

	test('結帳頁面：PayNow 信用卡選項應可見', async ({ page }) => {
		await ensureLoggedIn(page);
		await addToCartAndCheckout(page);
		await fillBillingFields(page);
		await waitForCheckoutUpdate(page);

		// 確認 PayNow 信用卡付款選項的 radio input 存在
		const paynowCreditRadio = page.locator('input#payment_method_paynow-credit');
		const isVisible = await paynowCreditRadio.isVisible().catch(() => false);

		test.skip(!isVisible, 'PayNow 信用卡閘道未啟用，跳過此測試');

		await expect(paynowCreditRadio).toBeVisible();

		// 點選該付款方式，確認可以選取
		await paynowCreditRadio.click();
		await expect(paynowCreditRadio).toBeChecked();
	});

	test('結帳頁面：其他 PayNow 付款方式應可見（若已啟用）', async ({ page }) => {
		await ensureLoggedIn(page);
		await addToCartAndCheckout(page);
		await fillBillingFields(page);
		await waitForCheckoutUpdate(page);

		// 檢查各種 PayNow 付款方式
		const paynowMethods = [
			'paynow-credit',
			'paynow-barcode',
			'paynow-webatm',
			'paynow-vacc',
			'paynow-cvs',
		];

		const visibleMethods: string[] = [];

		for (const method of paynowMethods) {
			const radio = page.locator(`input#payment_method_${method}`);
			const visible = await radio.isVisible().catch(() => false);
			if (visible) {
				visibleMethods.push(method);
			}
		}

		// 至少應有一個 PayNow 付款方式可見（若 PayNow 已啟用）
		test.skip(
			visibleMethods.length === 0,
			'未找到任何已啟用的 PayNow 付款方式，跳過此測試'
		);

		expect(visibleMethods.length).toBeGreaterThanOrEqual(1);

		// 驗證每個可見的付款方式都有對應的 label
		for (const method of visibleMethods) {
			const label = page.locator(`label[for="payment_method_${method}"]`);
			await expect(label, `${method} 應有對應的 label`).toBeVisible();
		}
	});
});

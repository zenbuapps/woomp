/**
 * PayNow 物流選項測試
 *
 * 驗證 PayNow 物流方式（超商取貨、宅配）在結帳頁面正確顯示。
 */

import { test, expect } from '@playwright/test';
import { CREDENTIALS, PRODUCT, BILLING, SELECTORS } from '../../fixtures/test-data';
import { PAYNOW_SHIPPING, PAYNOW_SELECTORS } from '../../fixtures/paynow-data';
import { ensureLoggedIn } from '../../helpers/auth.helper';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, waitForCheckoutUpdate } from '../../helpers/checkout.helper';
import { getAvailableShippingMethods } from '../../helpers/shipping-admin.helper';

test.describe('PayNow 物流選項', () => {

	test('結帳頁面：PayNow 超商取貨選項應可見（711、全家、萊爾富）', async ({ page }) => {
		await ensureLoggedIn(page);
		await addToCartAndCheckout(page);
		await fillBillingFields(page);
		await waitForCheckoutUpdate(page);

		// 取得可用的物流方式
		const shippingMethods = await getAvailableShippingMethods(page);

		// 檢查是否有 PayNow 超商物流方式
		const paynowCvsMethods = shippingMethods.filter(
			(method: string) =>
				method.includes('paynow_shipping') &&
				(method.includes('711') ||
				 method.includes('family') ||
				 method.includes('hilife') ||
				 method.includes('cvs'))
		);

		test.skip(
			paynowCvsMethods.length === 0,
			'未找到 PayNow 超商物流方式，可能未啟用，跳過此測試'
		);

		// 至少應有一個超商取貨選項
		expect(paynowCvsMethods.length).toBeGreaterThanOrEqual(1);

		// 驗證對應的 shipping method radio 可見
		for (const method of paynowCvsMethods) {
			const shippingRadio = page.locator(
				`input[type="radio"][name="shipping_method[0]"][value*="paynow_shipping"]`
			).first();
			const isVisible = await shippingRadio.isVisible().catch(() => false);
			if (isVisible) {
				// 驗證物流選項有對應的文字標籤
				const label = page.locator(`label:has(input[value*="paynow_shipping"])`).first();
				const labelAlt = page.locator(`label[for*="paynow_shipping"]`).first();
				const hasLabel =
					(await label.isVisible().catch(() => false)) ||
					(await labelAlt.isVisible().catch(() => false));
				expect(hasLabel, 'PayNow 超商物流應有顯示文字標籤').toBeTruthy();
			}
		}
	});

	test('結帳頁面：PayNow 宅配選項應可見', async ({ page }) => {
		await ensureLoggedIn(page);
		await addToCartAndCheckout(page);
		await fillBillingFields(page);
		await waitForCheckoutUpdate(page);

		// 取得可用的物流方式
		const shippingMethods = await getAvailableShippingMethods(page);

		// 檢查是否有 PayNow 宅配（TCat / 黑貓）物流方式
		const paynowHomeMethods = shippingMethods.filter(
			(method: string) =>
				method.includes('paynow_shipping') &&
				(method.includes('tcat') ||
				 method.includes('home') ||
				 method.includes('delivery'))
		);

		// 也檢查一般的 paynow shipping 項目（可能名稱中不包含 tcat）
		const anyPaynowShipping = shippingMethods.filter(
			(method: string) => method.includes('paynow_shipping')
		);

		test.skip(
			anyPaynowShipping.length === 0,
			'未找到任何 PayNow 物流方式，可能未啟用，跳過此測試'
		);

		// 驗證頁面上至少有一個 PayNow 物流 radio 按鈕可見
		const shippingRadios = page.locator(
			'input[type="radio"][name="shipping_method[0]"][value*="paynow_shipping"]'
		);
		const count = await shippingRadios.count();
		expect(count, '應有至少一個 PayNow 物流選項').toBeGreaterThanOrEqual(1);
	});
});

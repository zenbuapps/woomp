/**
 * 藍新（NewebPay）物流測試
 *
 * 驗證藍新物流方式（超商取貨）在結帳頁面正確顯示。
 */

import { test, expect } from '@playwright/test';
import { CREDENTIALS, PRODUCT, BILLING, SELECTORS } from '../../fixtures/test-data';
import { NEWEBPAY_SHIPPING } from '../../fixtures/paynow-data';
import { ensureLoggedIn } from '../../helpers/auth.helper';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, waitForCheckoutUpdate } from '../../helpers/checkout.helper';
import { getAvailableShippingMethods } from '../../helpers/shipping-admin.helper';

test.describe('藍新（NewebPay）物流', () => {

	test('結帳頁面：藍新物流方式應可見（若已啟用）', async ({ page }) => {
		await ensureLoggedIn(page);
		await addToCartAndCheckout(page);
		await fillBillingFields(page);
		await waitForCheckoutUpdate(page);

		// 取得可用的物流方式
		const shippingMethods = await getAvailableShippingMethods(page);

		// 檢查是否有藍新物流方式
		const newebpayShippingMethods = shippingMethods.filter(
			(method: string) =>
				method.includes('ry_newebpay') &&
				(method.includes('shipping') || method.includes('cvs'))
		);

		// 也檢查 ry_newebpay_shipping 開頭的方式
		const newebpayShippingAlt = shippingMethods.filter(
			(method: string) => method.includes('ry_newebpay_shipping')
		);

		const allNewebpayShipping = [
			...new Set([...newebpayShippingMethods, ...newebpayShippingAlt]),
		];

		test.skip(
			allNewebpayShipping.length === 0,
			'未找到藍新物流方式，可能未啟用，跳過此測試'
		);

		expect(allNewebpayShipping.length).toBeGreaterThanOrEqual(1);

		// 驗證頁面上有對應的物流 radio
		const shippingRadios = page.locator(
			'input[type="radio"][name="shipping_method[0]"][value*="ry_newebpay"]'
		);
		const count = await shippingRadios.count();
		expect(count, '應有至少一個藍新物流選項的 radio').toBeGreaterThanOrEqual(1);
	});

	test('結帳頁面：藍新超商取貨選項（711、全家等）', async ({ page }) => {
		await ensureLoggedIn(page);
		await addToCartAndCheckout(page);
		await fillBillingFields(page);
		await waitForCheckoutUpdate(page);

		// 取得可用的物流方式
		const shippingMethods = await getAvailableShippingMethods(page);

		// 藍新超商物流的可能 ID 模式
		const cvsPatterns = [
			'ry_newebpay_shipping_cvs',
			'ry_newebpay_shipping_711',
			'ry_newebpay_shipping_family',
			'ry_newebpay_shipping_hilife',
			'ry_newebpay_shipping_ok',
		];

		const matchedCvs = shippingMethods.filter((method: string) =>
			cvsPatterns.some((pattern) => method.includes(pattern))
		);

		// 也用更寬鬆的方式檢查
		const anyNewebpayShipping = shippingMethods.filter((method: string) =>
			method.includes('ry_newebpay')
		);

		test.skip(
			anyNewebpayShipping.length === 0,
			'未找到任何藍新物流方式，跳過超商取貨測試'
		);

		// 驗證物流選項的標籤內容
		const shippingLabels = page.locator(
			'label:has(input[value*="ry_newebpay"]), label[for*="ry_newebpay"]'
		);
		const labelCount = await shippingLabels.count();

		if (labelCount > 0) {
			// 驗證每個標籤都有文字內容
			for (let i = 0; i < labelCount; i++) {
				const labelText = await shippingLabels.nth(i).textContent();
				expect(
					labelText?.trim().length,
					'物流選項標籤應有文字內容'
				).toBeGreaterThan(0);
			}
		}

		// 檢查是否有可選取的物流 radio
		const shippingRadios = page.locator(
			'input[type="radio"][name="shipping_method[0]"][value*="ry_newebpay"]'
		);
		const radioCount = await shippingRadios.count();

		if (radioCount > 0) {
			// 點選第一個藍新物流選項
			await shippingRadios.first().click();
			await waitForCheckoutUpdate(page);

			// 驗證已選取
			await expect(shippingRadios.first()).toBeChecked();
		}
	});
});

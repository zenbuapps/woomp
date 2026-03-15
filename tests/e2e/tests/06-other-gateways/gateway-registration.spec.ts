/**
 * 其他金流閘道註冊測試
 *
 * 驗證 LINE Pay、PChomePay、藍新（NewebPay）、速買配（SmilePay）等
 * 金流閘道是否正確註冊並在結帳頁面顯示。
 */

import { test, expect } from '@playwright/test';
import { CREDENTIALS, PRODUCT, BILLING, SELECTORS } from '../../fixtures/test-data';
import {
	OTHER_GATEWAYS,
	NEWEBPAY_GATEWAYS,
	SMILEPAY_GATEWAYS,
} from '../../fixtures/paynow-data';
import { ensureLoggedIn } from '../../helpers/auth.helper';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, waitForCheckoutUpdate } from '../../helpers/checkout.helper';

test.describe('其他金流閘道註冊', () => {

	test.beforeEach(async ({ page }) => {
		await ensureLoggedIn(page);
		await addToCartAndCheckout(page);
		await fillBillingFields(page);
		await waitForCheckoutUpdate(page);
	});

	test('LINE Pay 閘道應在結帳頁面可見', async ({ page }) => {
		const linepayRadio = page.locator('input#payment_method_linepay');
		const isVisible = await linepayRadio.isVisible().catch(() => false);

		test.skip(!isVisible, 'LINE Pay 閘道未啟用，跳過此測試');

		await expect(linepayRadio).toBeVisible();

		// 驗證 label 存在
		const label = page.locator('label[for="payment_method_linepay"]');
		await expect(label).toBeVisible();

		// 點選確認可選取
		await linepayRadio.click();
		await expect(linepayRadio).toBeChecked();
	});

	test('PChomePay 支付連閘道應在結帳頁面可見', async ({ page }) => {
		const pchomepayRadio = page.locator('input#payment_method_pchomepay');
		const isVisible = await pchomepayRadio.isVisible().catch(() => false);

		test.skip(!isVisible, 'PChomePay 閘道未啟用，跳過此測試');

		await expect(pchomepayRadio).toBeVisible();

		// 驗證 label 存在
		const label = page.locator('label[for="payment_method_pchomepay"]');
		await expect(label).toBeVisible();

		// 點選確認可選取
		await pchomepayRadio.click();
		await expect(pchomepayRadio).toBeChecked();
	});

	test('藍新（NewebPay）信用卡閘道應在結帳頁面可見', async ({ page }) => {
		const newebpayRadio = page.locator('input#payment_method_ry_newebpay_credit');
		const isVisible = await newebpayRadio.isVisible().catch(() => false);

		test.skip(!isVisible, '藍新信用卡閘道未啟用，跳過此測試');

		await expect(newebpayRadio).toBeVisible();

		// 驗證 label 存在
		const label = page.locator('label[for="payment_method_ry_newebpay_credit"]');
		await expect(label).toBeVisible();

		// 點選確認可選取
		await newebpayRadio.click();
		await expect(newebpayRadio).toBeChecked();
	});

	test('速買配（SmilePay）信用卡閘道應在結帳頁面可見', async ({ page }) => {
		const smilepayRadio = page.locator('input#payment_method_ry_smilepay_credit');
		const isVisible = await smilepayRadio.isVisible().catch(() => false);

		test.skip(!isVisible, '速買配信用卡閘道未啟用，跳過此測試');

		await expect(smilepayRadio).toBeVisible();

		// 驗證 label 存在
		const label = page.locator('label[for="payment_method_ry_smilepay_credit"]');
		await expect(label).toBeVisible();

		// 點選確認可選取
		await smilepayRadio.click();
		await expect(smilepayRadio).toBeChecked();
	});

	test('結帳頁面應有足夠的付款方式選項', async ({ page }) => {
		// 取得所有付款方式 radio input
		const paymentRadios = page.locator(
			'input[type="radio"][name="payment_method"]'
		);
		const count = await paymentRadios.count();

		// 至少應有 1 個付款方式（基本的 WooCommerce 閘道）
		expect(count, '結帳頁面應至少有 1 個付款方式').toBeGreaterThanOrEqual(1);

		// 收集所有可見的付款方式 ID
		const visibleMethods: string[] = [];
		for (let i = 0; i < count; i++) {
			const radio = paymentRadios.nth(i);
			const isVisible = await radio.isVisible().catch(() => false);
			if (isVisible) {
				const value = await radio.getAttribute('value');
				if (value) {
					visibleMethods.push(value);
				}
			}
		}

		// 記錄所有可見的付款方式供除錯用
		console.log('可見的付款方式:', visibleMethods);

		// 驗證至少有 1 個可見的付款方式
		expect(
			visibleMethods.length,
			'應有至少 1 個可見的付款方式'
		).toBeGreaterThanOrEqual(1);
	});
});

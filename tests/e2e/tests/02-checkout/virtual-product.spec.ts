import { test, expect } from '@playwright/test';
import { CREDENTIALS, PRODUCT, BILLING, SELECTORS, URLS } from '../../fixtures/test-data';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import { ensureLoggedIn, loginAdmin } from '../../helpers/auth.helper';
import { addToCartAndCheckout, addProductToCart, goToCheckout, clearCart } from '../../helpers/cart.helper';
import { fillBillingFields, clickPlaceOrder, waitForCheckoutUpdate, verifyOrderReceived } from '../../helpers/checkout.helper';
import { goToWoompSettings, toggleSetting, saveSettings, setSelectValue, setInputValue } from '../../helpers/settings.helper';

test.describe('Virtual Product Checkout @checkout @virtual-product', () => {

	test('should remove address fields when all products are virtual and setting enabled @P0', async ({ page }) => {
		// 先確認虛擬商品設定已啟用
		await loginAdmin(page);
		await goToWoompSettings(page);

		// 啟用虛擬商品移除地址欄位設定
		const virtualProductSetting = page.locator(
			'#wc_woomp_setting_virtual_product_address, [name="wc_woomp_setting_virtual_product_address"]'
		);

		if (await virtualProductSetting.count() === 0) {
			test.skip(true, '找不到虛擬商品地址設定，跳過此測試');
			return;
		}

		await toggleSetting(page, 'wc_woomp_setting_virtual_product_address', 'yes');
		await saveSettings(page);

		// 嘗試將虛擬商品加入購物車
		// 先尋找虛擬商品（通常 URL 含有特定 product slug 或 ID）
		await page.goto(URLS.shop || `${page.url().split('/wp-admin')[0]}/shop/`, { waitUntil: 'commit' });
		await page.waitForLoadState('load', { timeout: 60_000 });

		// 尋找有虛擬商品標記的商品，或嘗試使用已知的虛擬商品
		// 虛擬商品通常不會顯示在一般商品列表中有特殊標記
		// 嘗試透過 WP REST API 查找虛擬商品
		const virtualProductResponse = await page.evaluate(async () => {
			try {
				const response = await fetch('/wp-json/wc/v3/products?virtual=true&per_page=1', {
					headers: { 'X-WP-Nonce': (window as any).wpApiSettings?.nonce || '' },
				});
				if (response.ok) {
					const products = await response.json();
					return products.length > 0 ? products[0] : null;
				}
			} catch {
				return null;
			}
			return null;
		});

		if (!virtualProductResponse) {
			test.skip(true, '找不到可用的虛擬商品，跳過此測試');
			return;
		}

		// 加入虛擬商品前先清空購物車（避免前一個測試遺留的實體商品干擾）
		await clearCart(page);

		// 使用 WC 加入購物車 URL 直接加入虛擬商品
		const baseUrl = page.url().split('/cart')[0].split('/wp-admin')[0];
		await page.goto(`${baseUrl}?add-to-cart=${virtualProductResponse.id}`, { waitUntil: 'commit' });
		await page.waitForLoadState('load', { timeout: 60_000 });

		// 前往結帳頁
		await goToCheckout(page);

		// 驗證地址欄位被移除
		const addressField = page.locator('#billing_address_1_field, [id="billing_address_1_field"]');
		const stateField = page.locator('#billing_state_field, [id="billing_state_field"]');
		const cityField = page.locator('#billing_city_field, [id="billing_city_field"]');
		const postcodeField = page.locator('#billing_postcode_field, [id="billing_postcode_field"]');

		// 地址相關欄位不應出現在頁面上
		const addressVisible = await addressField.isVisible().catch(() => false);
		const stateVisible = await stateField.isVisible().catch(() => false);
		const cityVisible = await cityField.isVisible().catch(() => false);
		const postcodeVisible = await postcodeField.isVisible().catch(() => false);

		expect(addressVisible).toBe(false);
		expect(stateVisible).toBe(false);
		expect(cityVisible).toBe(false);
		expect(postcodeVisible).toBe(false);

		// 基本帳單欄位（姓名、email、電話）仍應存在
		const nameField = page.locator('#billing_last_name, [name="billing_last_name"]');
		const emailField = page.locator('#billing_email, [name="billing_email"]');

		await expect(nameField).toBeVisible();
		await expect(emailField).toBeVisible();
	});

	test('should keep address fields when cart has mixed products @P1', async ({ page }) => {
		await ensureLoggedIn(page);

		// 先加入實體商品
		await addProductToCart(page);

		// 前往結帳頁
		await goToCheckout(page);

		// 當購物車包含實體商品時，地址欄位應該存在
		const addressField = page.locator('#billing_address_1_field, [id="billing_address_1_field"]');
		const stateField = page.locator('#billing_state_field, [id="billing_state_field"]');

		// 至少地址欄位或縣市欄位應可見
		const addressVisible = await addressField.isVisible().catch(() => false);
		const stateVisible = await stateField.isVisible().catch(() => false);

		expect(addressVisible || stateVisible).toBe(true);

		// 驗證完整的帳單欄位結構存在
		const billingFields = page.locator('.woocommerce-billing-fields, #customer_details');
		await expect(billingFields.first()).toBeVisible();
	});
});

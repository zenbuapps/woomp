import { test, expect } from '@playwright/test';
import { CREDENTIALS, PRODUCT, BILLING, SELECTORS, URLS } from '../../fixtures/test-data';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import { ensureLoggedIn, loginAdmin } from '../../helpers/auth.helper';
import { addToCartAndCheckout, addProductToCart, goToCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, clickPlaceOrder, waitForCheckoutUpdate, verifyOrderReceived } from '../../helpers/checkout.helper';
import { goToWoompSettings, toggleSetting, saveSettings, setSelectValue, setInputValue } from '../../helpers/settings.helper';
import { selectShippingMethod, getAvailableShippingMethods } from '../../helpers/shipping-admin.helper';

test.describe('CVS Pickup Field Handling @checkout @cvs', () => {
	test.beforeEach(async ({ page }) => {
		await ensureLoggedIn(page);
		await addToCartAndCheckout(page);
	});

	test('should hide billing address fields when CVS shipping is selected @P0', async ({ page }) => {
		// 取得可用的物流方式
		const shippingMethods = await getAvailableShippingMethods(page);

		// 尋找超商取貨的物流方式
		const cvsMethod = shippingMethods.find(
			(method) =>
				method.includes('cvs') ||
				method.includes('C2C') ||
				method.includes('超商') ||
				method.includes('convenience') ||
				method.includes('store')
		);

		if (!cvsMethod) {
			test.skip(true, '無可用的超商取貨物流方式，跳過此測試');
			return;
		}

		// 選擇超商取貨物流方式
		await selectShippingMethod(page, cvsMethod);
		await waitForCheckoutUpdate(page);

		// 驗證帳單地址欄位被隱藏
		const addressField = page.locator('#billing_address_1_field, [id="billing_address_1_field"]');
		const cityField = page.locator('#billing_city_field, [id="billing_city_field"]');
		const postcodeField = page.locator('#billing_postcode_field, [id="billing_postcode_field"]');

		// 地址相關欄位應被隱藏或不可見
		const addressVisible = await addressField.isVisible().catch(() => false);
		const cityVisible = await cityField.isVisible().catch(() => false);
		const postcodeVisible = await postcodeField.isVisible().catch(() => false);

		expect(addressVisible).toBe(false);
		expect(cityVisible).toBe(false);
		expect(postcodeVisible).toBe(false);
	});

	test('should restore address fields when switching from CVS to home delivery @P0', async ({ page }) => {
		const shippingMethods = await getAvailableShippingMethods(page);

		// 找到超商取貨和宅配物流方式
		const cvsMethod = shippingMethods.find(
			(method) =>
				method.includes('cvs') ||
				method.includes('C2C') ||
				method.includes('超商') ||
				method.includes('convenience') ||
				method.includes('store')
		);

		const homeDeliveryMethod = shippingMethods.find(
			(method) =>
				method.includes('flat_rate') ||
				method.includes('free_shipping') ||
				method.includes('宅配') ||
				method.includes('home') ||
				(!method.includes('cvs') &&
					!method.includes('C2C') &&
					!method.includes('超商') &&
					!method.includes('convenience') &&
					!method.includes('store') &&
					!method.includes('local_pickup'))
		);

		if (!cvsMethod || !homeDeliveryMethod) {
			test.skip(true, '需要同時有超商取貨和宅配物流方式，跳過此測試');
			return;
		}

		// 先選擇超商取貨
		await selectShippingMethod(page, cvsMethod);
		await waitForCheckoutUpdate(page);

		// 確認地址欄位已隱藏
		const addressFieldAfterCvs = page.locator('#billing_address_1_field, [id="billing_address_1_field"]');
		await expect(addressFieldAfterCvs).not.toBeVisible();

		// 切換回宅配
		await selectShippingMethod(page, homeDeliveryMethod);
		await waitForCheckoutUpdate(page);

		// 驗證地址欄位恢復顯示
		const addressField = page.locator('#billing_address_1_field, [id="billing_address_1_field"]');
		const cityField = page.locator('#billing_city_field, [id="billing_city_field"]');
		const postcodeField = page.locator('#billing_postcode_field, [id="billing_postcode_field"]');

		await expect(addressField).toBeVisible();
		await expect(cityField).toBeVisible();
		await expect(postcodeField).toBeVisible();
	});

	test('should not require address fields when CVS shipping is selected @P1', async ({ page }) => {
		const shippingMethods = await getAvailableShippingMethods(page);

		const cvsMethod = shippingMethods.find(
			(method) =>
				method.includes('cvs') ||
				method.includes('C2C') ||
				method.includes('超商') ||
				method.includes('convenience') ||
				method.includes('store')
		);

		if (!cvsMethod) {
			test.skip(true, '無可用的超商取貨物流方式，跳過此測試');
			return;
		}

		// 選擇超商取貨
		await selectShippingMethod(page, cvsMethod);
		await waitForCheckoutUpdate(page);

		// 填寫基本帳單資訊（不含地址）
		await fillBillingFields(page, {
			billing_last_name: BILLING.LAST_NAME,
			billing_phone: BILLING.PHONE,
			billing_email: BILLING.EMAIL,
		});

		// 嘗試送出訂單
		await clickPlaceOrder(page);

		// 不應出現地址相關的必填驗證錯誤
		const errorNotices = page.locator('.woocommerce-error, .wc-block-components-notice-banner.is-error');
		const hasErrors = await errorNotices.isVisible().catch(() => false);

		if (hasErrors) {
			const errorText = await errorNotices.textContent();
			// 如果有錯誤，不應是地址相關的
			expect(errorText).not.toContain('地址');
			expect(errorText).not.toContain('address');
			expect(errorText).not.toContain('billing_address_1');
			expect(errorText).not.toContain('城市');
			expect(errorText).not.toContain('郵遞區號');
		}
	});
});

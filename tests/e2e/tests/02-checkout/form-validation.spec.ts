import { test, expect } from '@playwright/test';
import { CREDENTIALS, PRODUCT, BILLING, SELECTORS, URLS } from '../../fixtures/test-data';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import { ensureLoggedIn, loginAdmin } from '../../helpers/auth.helper';
import { addToCartAndCheckout, addProductToCart, goToCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, clickPlaceOrder, waitForCheckoutUpdate, verifyOrderReceived } from '../../helpers/checkout.helper';
import { goToWoompSettings, toggleSetting, saveSettings, setSelectValue, setInputValue } from '../../helpers/settings.helper';

test.describe('Taiwan Form Validation @checkout @tw-validation', () => {
	test.beforeEach(async ({ page }) => {
		await ensureLoggedIn(page);
		await addToCartAndCheckout(page);
	});

	test('should show error when last name is too short (1 character) @P0', async ({ page }) => {
		// 填寫帳單欄位，姓名只有一個字
		await fillBillingFields(page, {
			billing_last_name: '王',
			billing_phone: '0912345678',
			billing_email: BILLING.EMAIL,
		});

		// 點擊下單按鈕
		await clickPlaceOrder(page);

		// 等待驗證結果
		await page.waitForTimeout(2000);

		// 驗證顯示姓名長度錯誤訊息
		const errorNotice = page.locator(
			'.woocommerce-error, .wc-block-components-notice-banner.is-error, .woocommerce-NoticeGroup, ul.woocommerce-error'
		);
		await expect(errorNotice.first()).toBeVisible({ timeout: 10000 });

		const errorText = await errorNotice.first().textContent();
		expect(errorText).toContain('姓名');
		expect(errorText).toContain('至少兩個字以上');
	});

	test('should show error when phone number has wrong length (9 digits) @P0', async ({ page }) => {
		// 填寫帳單欄位，電話號碼只有 9 碼
		await fillBillingFields(page, {
			billing_last_name: '王小明',
			billing_phone: '091234567', // 9 碼，應為 10 碼
			billing_email: BILLING.EMAIL,
		});

		// 點擊下單按鈕
		await clickPlaceOrder(page);

		// 等待驗證結果
		await page.waitForTimeout(2000);

		// 驗證顯示電話長度錯誤訊息
		const errorNotice = page.locator(
			'.woocommerce-error, .wc-block-components-notice-banner.is-error, .woocommerce-NoticeGroup, ul.woocommerce-error'
		);
		await expect(errorNotice.first()).toBeVisible({ timeout: 10000 });

		const errorText = await errorNotice.first().textContent();
		expect(errorText).toContain('聯絡電話');
		expect(errorText).toContain('長度有誤');
		expect(errorText).toContain('10 碼');
	});

	test('should accept valid phone number with 10 digits @P0', async ({ page }) => {
		// 填寫帳單欄位，電話號碼正確 10 碼
		await fillBillingFields(page, {
			billing_last_name: '王小明',
			billing_phone: '0912345678', // 正確 10 碼
			billing_email: BILLING.EMAIL,
		});

		// 點擊下單按鈕
		await clickPlaceOrder(page);

		// 等待驗證結果
		await page.waitForTimeout(2000);

		// 檢查錯誤訊息
		const errorNotice = page.locator(
			'.woocommerce-error, .wc-block-components-notice-banner.is-error, .woocommerce-NoticeGroup, ul.woocommerce-error'
		);
		const hasErrors = await errorNotice.isVisible().catch(() => false);

		if (hasErrors) {
			const errorText = await errorNotice.first().textContent();
			// 不應出現電話相關的驗證錯誤
			expect(errorText).not.toContain('聯絡電話');
			expect(errorText).not.toContain('長度有誤');
		}
		// 若無錯誤訊息，代表電話驗證通過
	});

	test('should pass validation with all valid fields @P1', async ({ page }) => {
		// 填寫所有有效的帳單欄位
		await fillBillingFields(page, {
			billing_last_name: '王小明',
			billing_phone: '0912345678',
			billing_email: BILLING.EMAIL,
		});

		// 選擇縣市和區域（如果台灣地址下拉存在）
		const stateSelect = page.locator('#billing_state, select[name="billing_state"]');
		const isStateSelect = await stateSelect.count();

		if (isStateSelect > 0) {
			const tagName = await stateSelect.evaluate(el => el.tagName.toLowerCase());
			if (tagName === 'select') {
				await stateSelect.selectOption({ index: 1 });
				await waitForCheckoutUpdate(page);

				const citySelect = page.locator('#billing_city, select[name="billing_city"]');
				const isCitySelect = await citySelect.count();

				if (isCitySelect > 0) {
					const cityTagName = await citySelect.evaluate(el => el.tagName.toLowerCase());
					if (cityTagName === 'select') {
						await citySelect.selectOption({ index: 1 });
						await waitForCheckoutUpdate(page);
					}
				}
			}
		}

		// 填寫地址（若欄位存在且可見）
		const addressField = page.locator('#billing_address_1');
		if (await addressField.isVisible().catch(() => false)) {
			await addressField.fill('測試路 123 號');
		}

		// 點擊下單按鈕
		await clickPlaceOrder(page);

		// 等待結果
		await page.waitForTimeout(3000);

		// 不應出現台灣格式驗證的錯誤
		const errorNotice = page.locator(
			'.woocommerce-error, .wc-block-components-notice-banner.is-error, .woocommerce-NoticeGroup, ul.woocommerce-error'
		);
		const hasErrors = await errorNotice.isVisible().catch(() => false);

		if (hasErrors) {
			const errorText = await errorNotice.first().textContent();
			// 不應包含台灣格式驗證相關的錯誤
			expect(errorText).not.toContain('姓名欄位 至少兩個字以上');
			expect(errorText).not.toContain('聯絡電話 長度有誤');
		} else {
			// 若無錯誤，可能已成功送出或跳轉到訂單確認頁
			// 檢查是否到達訂單確認頁或金流頁面
			const currentUrl = page.url();
			const isOnCheckout = currentUrl.includes('checkout');
			const isOnOrderReceived = currentUrl.includes('order-received');
			const isOnPayment = currentUrl.includes('pay') || currentUrl.includes('payment');

			// 應離開結帳頁（成功送出）或仍在結帳頁但無台灣格式驗證錯誤
			expect(isOnOrderReceived || isOnPayment || isOnCheckout).toBe(true);
		}
	});
});

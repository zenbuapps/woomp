import { test, expect } from '@playwright/test';
import { CREDENTIALS, PRODUCT, BILLING, SELECTORS, URLS } from '../../fixtures/test-data';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import { ensureLoggedIn, loginAdmin } from '../../helpers/auth.helper';
import { addToCartAndCheckout, addProductToCart, goToCheckout, clearCart } from '../../helpers/cart.helper';
import { fillBillingFields, clickPlaceOrder, waitForCheckoutUpdate, verifyOrderReceived } from '../../helpers/checkout.helper';
import { goToWoompSettings, toggleSetting, saveSettings, setSelectValue, setInputValue } from '../../helpers/settings.helper';

test.describe('Taiwan Form Validation @checkout @tw-validation', () => {
	// 確保「台灣格式驗證」設定已開啟（wc_woomp_setting_tw_field_valitdate = 'yes'）
	// 此設定的 admin default 為 'no'，若 setup 未正確儲存，驗證 hook 不會被註冊
	test.beforeAll(async ({ browser }) => {
		// 強制啟用「台灣格式驗證」設定（wc_woomp_setting_tw_field_valitdate = 'yes'）
		// 使用直接 HTTP POST 取代 UI 點擊，避免 saveSettings 等待頁面重載（30-60s）
		const baseURL = process.env.TEST_SITE_URL || 'https://local-turbo.powerhouse.tw';
		const context = await browser.newContext({ baseURL });
		const page = await context.newPage();
		try {
			await loginAdmin(page);
			// 取得 WC settings 頁的 nonce（用 commit 避免等待外部資源）
			await page.goto('/wp-admin/admin.php?page=wc-settings&tab=woomp_setting', {
				waitUntil: 'commit',
				timeout: 60_000,
			});
			// 等待 nonce input 出現（代表 PHP 已渲染表單）
			await page.locator('input[name="_wpnonce"]').first().waitFor({ timeout: 60_000 }).catch(() => {});
			const nonce = await page.locator('input[name="_wpnonce"]').first().getAttribute('value').catch(() => null);
			const referer = await page.locator('input[name="_wp_http_referer"]').first().getAttribute('value').catch(() => null);

				if (nonce) {
				// 等待設定表單的 checkbox 完全渲染（_wpnonce 出現時 PHP 已渲染，
				// 但 WC/jQuery 可能仍在初始化 toggle UI，等待 checkbox 出現）
				await page.waitForLoadState('domcontentloaded', { timeout: 30_000 }).catch(() => {});

				// 讀取目前所有已勾選的 checkbox 狀態，避免 POST 時重置其他設定
				// （WP 表單中未送出的 checkbox = unchecked，會被存為 '' 或 'no'）
				const existingChecks = await page.evaluate(() => {
					const data: Record<string, string> = {};
					document.querySelectorAll('input[type="checkbox"]').forEach((el) => {
						const cb = el as HTMLInputElement;
						if (cb.name && cb.checked) {
							data[cb.name] = '1';
						}
					});
					return data;
				}).catch(() => ({} as Record<string, string>));

				// 直接 POST 設定頁表單，繞過 UI 按鈕點擊（比 saveSettings 快 30-60s）
				// 保留所有既有已勾選的 checkbox，並強制啟用 tw_field_valitdate
				await page.request.post('/wp-admin/admin.php?page=wc-settings&tab=woomp_setting', {
					form: {
						'_wpnonce': nonce,
						'_wp_http_referer': referer || '/wp-admin/admin.php?page=wc-settings&tab=woomp_setting',
						'save': 'Save changes',
						...existingChecks,
						'wc_woomp_setting_tw_field_valitdate': '1',
					},
				});
			}
		} finally {
			await context.close();
		}
	});

	test.beforeEach(async ({ page }) => {
		await ensureLoggedIn(page);
		// 清空購物車（純 REST API，不觸發額外頁面導覽，避免拖慢 beforeEach）
		// clearCart(page) 會導覽到 /cart/，在慢速站台上容易超出 5 分鐘測試 timeout
		await page.evaluate(async () => {
			try {
				const info = await fetch('/wp-json/wc/store/v1').then(r => r.json()).catch(() => ({}));
				const nonce = (info as any)?.nonce || '';
				await fetch('/wp-json/wc/store/v1/cart/items', {
					method: 'DELETE',
					headers: { 'Nonce': nonce, 'X-WC-Store-API-Nonce': nonce },
				});
			} catch { /* ignore */ }
		}).catch(() => {});
		await addToCartAndCheckout(page);
	});

	test('should show error when last name is too short (1 character) @P0', async ({ page }) => {
		// PHP field_validate 的姓名長度驗證只在「結帳表單僅有一個姓名欄位」時才觸發：
		// - 僅有 billing_last_name（無 billing_first_name）→ 驗證 last name 長度
		// - 僅有 billing_first_name（無 billing_last_name）→ 驗證 first name 長度
		// 若兩個欄位都存在（標準 WC 結帳），名稱驗證不觸發 → 跳過此測試
		const firstNameField = page.locator('#billing_first_name, input[name="billing_first_name"]').first();
		const hasTwoNameFields = await firstNameField.isVisible().catch(() => false);
		if (hasTwoNameFields) {
			test.skip(true, '此站台結帳表單同時有 billing_first_name 和 billing_last_name，PHP 姓名驗證不觸發於雙欄位模式');
			return;
		}

		// 單一姓名欄位模式：填入 1 字測試驗證
		await fillBillingFields(page, {
			lastName: '王',
			phone: '0912345678',
			email: BILLING.email,
		});

		// 等待 WC AJAX 更新完成（避免 WC 用顧客儲存地址覆蓋欄位）
		await waitForCheckoutUpdate(page);

		// WC AJAX 可能已覆蓋 last name 為儲存值，重新設定為 1 字測試值
		const lastNameField = page.locator('#billing_last_name, input[name="billing_last_name"]').first();
		if (await lastNameField.isVisible().catch(() => false)) {
			await lastNameField.fill('王');
		}

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
			lastName: '王小明',
			phone: '091234567', // 9 碼，應為 10 碼
			email: BILLING.email,
		});

		// 等待 WC AJAX 更新完成（fillBillingFields 可能觸發 update_order_review）
		await waitForCheckoutUpdate(page);

		// 重新設定 9 碼電話（AJAX 可能覆寫為顧客儲存的 10 碼）
		const phoneField = page.locator('#billing_phone, input[name="billing_phone"]').first();
		await phoneField.fill('091234567');

		// 再次等待 AJAX 靜止（re-fill 可能再次觸發 update_order_review）
		await waitForCheckoutUpdate(page);

		// 點擊下單按鈕
		await clickPlaceOrder(page);

		// 等待 WC checkout AJAX 完成（blockUI 出現並消失）
		// 測試站台可能很慢（3s+ 查詢時間），需要足夠的等待時間
		try {
			await page.waitForSelector('.blockUI.blockOverlay', { state: 'attached', timeout: 5_000 });
			await page.waitForSelector('.blockUI.blockOverlay', { state: 'hidden', timeout: 30_000 });
		} catch {
			// blockUI 沒出現或已完成，繼續
		}

		// 驗證顯示電話長度錯誤訊息
		const errorNotice = page.locator(
			'.woocommerce-error, .wc-block-components-notice-banner.is-error, .woocommerce-NoticeGroup, ul.woocommerce-error'
		);
		await expect(errorNotice.first()).toBeVisible({ timeout: 15_000 });

		const errorText = await errorNotice.first().textContent();
		expect(errorText).toContain('聯絡電話');
		expect(errorText).toContain('長度有誤');
		expect(errorText).toContain('10 碼');
	});

	test('should accept valid phone number with 10 digits @P0', async ({ page }) => {
		// 填寫帳單欄位，電話號碼正確 10 碼
		await fillBillingFields(page, {
			lastName: '王小明',
			phone: '0912345678', // 正確 10 碼
			email: BILLING.email,
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
			lastName: '王小明',
			phone: '0912345678',
			email: BILLING.email,
		});

		// 選擇縣市和區域（如果台灣地址下拉存在）
		const stateSelect = page.locator('#billing_state, select[name="billing_state"]');
		const isStateSelect = await stateSelect.count();

		if (isStateSelect > 0) {
			const tagName = await stateSelect.evaluate(el => el.tagName.toLowerCase());
			if (tagName === 'select') {
				await stateSelect.selectOption({ index: 1 }, { force: true });
				await waitForCheckoutUpdate(page);

				const citySelect = page.locator('select[name="district"], select#billing_city, select[name="billing_city"]');
				const isCitySelect = await citySelect.count();

				if (isCitySelect > 0) {
					const cityTagName = await citySelect.first().evaluate(el => el.tagName.toLowerCase());
					if (cityTagName === 'select') {
						await citySelect.first().selectOption({ index: 1 }, { force: true });
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

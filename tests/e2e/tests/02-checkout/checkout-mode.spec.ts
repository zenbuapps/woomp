import { test, expect } from '@playwright/test';
import { CREDENTIALS, PRODUCT, BILLING, SELECTORS, URLS } from '../../fixtures/test-data';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import { ensureLoggedIn, loginAdmin } from '../../helpers/auth.helper';
import { addToCartAndCheckout, addProductToCart, goToCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, clickPlaceOrder, waitForCheckoutUpdate, verifyOrderReceived } from '../../helpers/checkout.helper';
import { goToWoompSettings, toggleSetting, saveSettings, setSelectValue, setInputValue } from '../../helpers/settings.helper';

test.describe('Checkout Mode Settings @checkout @mode', () => {
	let originalMode: string;

	test.beforeEach(async ({ page }) => {
		await loginAdmin(page);
	});

	test.afterAll(async ({ browser }) => {
		// 還原原始設定
		const page = await browser.newPage();
		await loginAdmin(page);
		await goToWoompSettings(page);
		await setSelectValue(page, 'wc_woomp_setting_mode', 'default');
		await saveSettings(page);
		await page.close();
	});

	test('onepage mode should auto-redirect cart to checkout @P0', async ({ page }) => {
		// 設定為一頁式結帳
		await goToWoompSettings(page);
		await setSelectValue(page, 'wc_woomp_setting_mode', 'onepage');
		await saveSettings(page);

		// 確認設定已儲存
		await expect(page.locator('#wc_woomp_setting_mode')).toHaveValue('onepage');

		// 以前台身份加入商品到購物車
		await addProductToCart(page);

		// 前往購物車頁面，應該自動跳轉到結帳頁
		await page.goto(URLS.cart, { waitUntil: 'commit', timeout: 60_000 });
		// 等待跳轉至結帳頁（onepage 模式會自動 redirect）
		await page.waitForURL(/checkout/, { timeout: 30_000 });

		// 驗證已跳轉至結帳頁
		await expect(page).toHaveURL(/checkout/);

		// 驗證結帳頁上有購物車項目可見
		const orderReview = page.locator('#order_review, .woocommerce-checkout-review-order');
		await expect(orderReview).toBeVisible();

		// 驗證商品項目在結帳頁上可見
		const cartItems = page.locator('.woocommerce-checkout-review-order-table, .cart_item, .shop_table');
		await expect(cartItems.first()).toBeVisible();
	});

	test('twopage mode should show return-to-cart message on checkout @P0', async ({ page }) => {
		// 設定為兩頁式結帳
		await goToWoompSettings(page);

		// 先確認 checkout mode select 存在
		const modeSelect = page.locator('#wc_woomp_setting_mode');
		const modeSelectVisible = await modeSelect.isVisible().catch(() => false);
		if (!modeSelectVisible) {
			test.skip(true, '此站台無結帳模式選單，跳過 twopage 測試');
			return;
		}

		await setSelectValue(page, 'wc_woomp_setting_mode', 'twopage');

		// 驗證兩頁式訊息欄位出現（可選，依站台設定而定）
		const messageField = page.locator('[name="wc_woomp_setting_twopage_message"], #wc_woomp_setting_twopage_message');
		await page.waitForTimeout(500); // 等待 JS 動態顯示
		const messageFieldVisible = await messageField.isVisible().catch(() => false);

		if (!messageFieldVisible) {
			test.skip(true, 'twopage 訊息欄位不存在，此站台可能不支援此功能');
			return;
		}

		// 設定返回購物車訊息
		const testMessage = '返回購物車修改商品';
		await setInputValue(page, 'wc_woomp_setting_twopage_message', testMessage);
		await saveSettings(page);

		// 前台驗證：加入商品並前往結帳
		await addToCartAndCheckout(page);

		// 驗證結帳頁上顯示自訂訊息
		const pageContent = page.locator('body');
		await expect(pageContent).toContainText(testMessage);
	});

	test('default mode should use standard WC checkout layout @P1', async ({ page }) => {
		// 設定為預設模式
		await goToWoompSettings(page);
		await setSelectValue(page, 'wc_woomp_setting_mode', 'default');
		await saveSettings(page);

		// 確認設定已儲存
		await expect(page.locator('#wc_woomp_setting_mode')).toHaveValue('default');

		// 前台驗證：加入商品並前往結帳
		await addToCartAndCheckout(page);

		// 驗證標準 WooCommerce 結帳表單結構
		const checkoutForm = page.locator('form.woocommerce-checkout, form[name="checkout"]');
		await expect(checkoutForm).toBeVisible();

		// 驗證帳單欄位區塊存在
		const billingFields = page.locator('.woocommerce-billing-fields, #customer_details');
		await expect(billingFields.first()).toBeVisible();

		// 驗證訂單摘要區塊存在
		const orderReview = page.locator('#order_review, .woocommerce-checkout-review-order');
		await expect(orderReview).toBeVisible();
	});

	test('switching modes should persist after page reload @P1', async ({ page }) => {
		// 設定為 onepage
		await goToWoompSettings(page);

		// 先確認 checkout mode select 存在
		const modeSelectCheck = page.locator('#wc_woomp_setting_mode');
		if (await modeSelectCheck.count() === 0) {
			test.skip(true, '此站台無結帳模式選單，跳過此測試');
			return;
		}

		await setSelectValue(page, 'wc_woomp_setting_mode', 'onepage');
		await saveSettings(page);

		// 重新導航到設定頁（避免 WC 通知或 URL 參數干擾 reload 後的頁面狀態）
		await goToWoompSettings(page);

		// 驗證設定仍然是 onepage
		const modeSelect = page.locator('#wc_woomp_setting_mode');
		await expect(modeSelect).toHaveValue('onepage');

		// 切換為 default 並儲存
		await setSelectValue(page, 'wc_woomp_setting_mode', 'default');
		await saveSettings(page);

		// 重新導航並驗證
		await goToWoompSettings(page);
		await expect(page.locator('#wc_woomp_setting_mode')).toHaveValue('default');
	});
});

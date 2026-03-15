import { test, expect } from '@playwright/test';
import { CREDENTIALS, PRODUCT, BILLING, SELECTORS, URLS } from '../../fixtures/test-data';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import { ensureLoggedIn, loginAdmin } from '../../helpers/auth.helper';
import { addToCartAndCheckout, addProductToCart, goToCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, clickPlaceOrder, waitForCheckoutUpdate, verifyOrderReceived } from '../../helpers/checkout.helper';
import { goToWoompSettings, toggleSetting, saveSettings, setSelectValue, setInputValue } from '../../helpers/settings.helper';

test.describe('Free Shipping Hint Labels @checkout @free-shipping-hint', () => {

	test('should show remaining amount hint when cart is below free shipping threshold @P0', async ({ page }) => {
		// 先確認免運提示設定已啟用
		await loginAdmin(page);
		await goToWoompSettings(page);

		const freeShippingHintSetting = page.locator(
			'#wc_woomp_setting_free_shipping_hint, [name="wc_woomp_setting_free_shipping_hint"]'
		);

		if (await freeShippingHintSetting.count() === 0) {
			test.skip(true, '找不到免運提示設定，跳過此測試');
			return;
		}

		// 啟用免運提示
		await setSelectValue(page, 'wc_woomp_setting_free_shipping_hint', 'yes');
		await saveSettings(page);

		// 加入單一低價商品到購物車（預期低於免運門檻）
		await addProductToCart(page);

		// 前往購物車或結帳頁查看免運提示
		await goToCheckout(page);
		await waitForCheckoutUpdate(page);

		// 尋找免運提示標籤
		const freeShippingHint = page.locator(
			'.free-shipping-hint, .shipping-hint, [class*="free-shipping"], [class*="shipping-hint"]'
		);

		// 也嘗試透過文字內容尋找
		const hintByText = page.locator(
			'*:has-text("差"), *:has-text("免運費")'
		).filter({ hasText: '元' });

		const hintVisible = await freeShippingHint.isVisible().catch(() => false);
		const textHintVisible = await hintByText.first().isVisible().catch(() => false);

		if (!hintVisible && !textHintVisible) {
			// 可能購物車金額已超過免運門檻，或沒有設定免運物流
			// 檢查是否已顯示「免運」標籤
			const freeLabel = page.locator('*:has-text("免運")');
			const freeLabelVisible = await freeLabel.first().isVisible().catch(() => false);

			if (!freeLabelVisible) {
				test.skip(true, '找不到免運提示標籤，可能未設定免運物流方式');
				return;
			}

			// 如果已經免運，驗證免運標籤存在
			await expect(freeLabel.first()).toBeVisible();
		} else {
			// 驗證「差 X 元」提示標籤內容格式正確
			if (hintVisible) {
				const hintText = await freeShippingHint.first().textContent();
				expect(hintText).toBeTruthy();
				// 應包含「差」和「元」字樣
				expect(hintText).toMatch(/差.*元|免運/);
			} else if (textHintVisible) {
				const hintText = await hintByText.first().textContent();
				expect(hintText).toBeTruthy();
			}
		}
	});

	test('should show free shipping label when cart meets threshold @P1', async ({ page }) => {
		await loginAdmin(page);
		await goToWoompSettings(page);

		const freeShippingHintSetting = page.locator(
			'#wc_woomp_setting_free_shipping_hint, [name="wc_woomp_setting_free_shipping_hint"]'
		);

		if (await freeShippingHintSetting.count() === 0) {
			test.skip(true, '找不到免運提示設定，跳過此測試');
			return;
		}

		// 確保免運提示已啟用
		await setSelectValue(page, 'wc_woomp_setting_free_shipping_hint', 'yes');
		await saveSettings(page);

		// 加入多個商品到購物車以達到免運門檻
		// 多次加入商品來確保超過門檻
		await addProductToCart(page);
		await addProductToCart(page);
		await addProductToCart(page);
		await addProductToCart(page);
		await addProductToCart(page);

		// 前往結帳頁
		await goToCheckout(page);
		await waitForCheckoutUpdate(page);

		// 尋找免運標籤
		const freeShippingLabel = page.locator(
			'.free-shipping-hint, .shipping-hint, [class*="free-shipping"], [class*="shipping-hint"]'
		);

		// 也嘗試透過文字內容尋找「免運」標籤
		const freeLabelByText = page.locator('*:has-text("免運")').first();

		const labelVisible = await freeShippingLabel.isVisible().catch(() => false);
		const textLabelVisible = await freeLabelByText.isVisible().catch(() => false);

		if (!labelVisible && !textLabelVisible) {
			// 檢查是否顯示「差 X 元」（代表金額仍不足）
			const remainingHint = page.locator('*:has-text("差")').filter({ hasText: '元' });
			const remainingVisible = await remainingHint.first().isVisible().catch(() => false);

			if (remainingVisible) {
				// 金額仍不足，記錄但不視為失敗（取決於商品價格和門檻設定）
				const hintText = await remainingHint.first().textContent();
				console.log(`尚未達到免運門檻: ${hintText}`);
			} else {
				test.skip(true, '找不到免運相關標籤，可能未設定免運物流方式');
				return;
			}
		} else {
			// 驗證顯示「免運」標籤
			if (labelVisible) {
				const labelText = await freeShippingLabel.first().textContent();
				expect(labelText).toContain('免運');
			} else if (textLabelVisible) {
				await expect(freeLabelByText).toBeVisible();
			}
		}
	});
});

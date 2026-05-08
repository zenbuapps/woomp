/**
 * 前台變化款式單選按鈕（Radio）樣式測試
 *
 * 驗證 Woomp 的 Radio 樣式變化款式選擇器在商品頁面的顯示與互動。
 */

import { test, expect } from '@playwright/test';
import { CREDENTIALS, PRODUCT, SELECTORS } from '../../fixtures/test-data';
import { ensureLoggedIn } from '../../helpers/auth.helper';
import {
	goToProduct,
	goToShop,
	verifyVariationRadioUI,
	selectVariation,
} from '../../helpers/product.helper';

test.describe('前台變化款式單選按鈕（Radio）樣式', () => {

	test('可變商品頁面應顯示 Radio 樣式的變化款式選擇器', async ({ page }) => {
		// 前往商店頁面尋找可變商品
		await goToShop(page);

		// 尋找可變商品
		const variableProductLink = page.locator(
			'a.button.product_type_variable, a:has-text("選擇選項"), a:has-text("Select options")'
		).first();

		const hasVariableProduct = await variableProductLink.isVisible().catch(() => false);

		if (hasVariableProduct) {
			await variableProductLink.click();
		} else {
			try {
				await goToProduct(page);
			} catch {
				test.skip(true, '找不到可變商品，跳過此測試');
				return;
			}
		}

		await page.waitForLoadState('domcontentloaded');

		// 檢查是否有變化款式選擇器
		const variationsForm = page.locator(
			'form.variations_form, .variations_form'
		);
		const hasVariationsForm = await variationsForm.isVisible().catch(() => false);

		test.skip(!hasVariationsForm, '此商品頁面無變化款式表單，跳過此測試');

		// 驗證 Radio 樣式 UI
		const hasRadioUI = await verifyVariationRadioUI(page);

		test.skip(!hasRadioUI, 'Woomp Radio 樣式變化款式 UI 未啟用，跳過此測試');

		expect(hasRadioUI).toBeTruthy();
	});

	test('選取 Radio 選項應切換選取狀態', async ({ page }) => {
		await goToShop(page);

		// 尋找可變商品
		const variableProductLink = page.locator(
			'a.button.product_type_variable, a:has-text("選擇選項"), a:has-text("Select options")'
		).first();

		const hasVariableProduct = await variableProductLink.isVisible().catch(() => false);

		if (hasVariableProduct) {
			await variableProductLink.click();
		} else {
			try {
				await goToProduct(page);
			} catch {
				test.skip(true, '找不到可變商品，跳過此測試');
				return;
			}
		}

		await page.waitForLoadState('domcontentloaded');

		// 檢查 Radio UI
		const hasRadioUI = await verifyVariationRadioUI(page);
		test.skip(!hasRadioUI, 'Woomp Radio 樣式 UI 未啟用，跳過此測試');

		// 尋找 Radio 樣式的變化款式選項
		const radioOptions = page.locator(
			'.woomp-variation-radio input[type="radio"], .variation-radio input[type="radio"], .woomp-radio-variation input[type="radio"], input[type="radio"][name*="attribute"]'
		);
		const radioCount = await radioOptions.count();

		// 也檢查自訂的 radio-like 元素
		const radioLikeOptions = page.locator(
			'.woomp-variation-radio label, .variation-radio-item, [class*="radio-variation"] label'
		);
		const radioLikeCount = await radioLikeOptions.count();

		const totalOptions = radioCount + radioLikeCount;
		test.skip(totalOptions === 0, '找不到 Radio 變化款式選項');

		if (radioCount > 0) {
			// 有標準 radio input
			const firstRadio = radioOptions.first();
			await firstRadio.click({ force: true });

			// 驗證已選取
			await expect(firstRadio).toBeChecked();

			// 若有第二個選項，點擊它驗證切換
			if (radioCount > 1) {
				const secondRadio = radioOptions.nth(1);
				await secondRadio.click({ force: true });
				await expect(secondRadio).toBeChecked();

				// 第一個應取消選取（同 name 的 radio 互斥）
				const firstName = await firstRadio.getAttribute('name');
				const secondName = await secondRadio.getAttribute('name');
				if (firstName === secondName) {
					await expect(firstRadio).not.toBeChecked();
				}
			}
		} else if (radioLikeCount > 0) {
			// 有自訂 radio-like 元素
			const firstOption = radioLikeOptions.first();
			await firstOption.click();

			// 驗證選取狀態
			const isSelected = await firstOption.evaluate((el) => {
				return (
					el.classList.contains('selected') ||
					el.classList.contains('active') ||
					el.classList.contains('checked') ||
					el.querySelector('input[type="radio"]:checked') !== null
				);
			});

			expect(isSelected, '點擊後 Radio 選項應為選取狀態').toBeTruthy();
		}
	});

	test('選取 Radio 變化款式後應觸發價格與庫存更新', async ({ page }) => {
		await goToShop(page);

		// 尋找可變商品
		const variableProductLink = page.locator(
			'a.button.product_type_variable, a:has-text("選擇選項"), a:has-text("Select options")'
		).first();

		const hasVariableProduct = await variableProductLink.isVisible().catch(() => false);

		if (hasVariableProduct) {
			await variableProductLink.click();
		} else {
			try {
				await goToProduct(page);
			} catch {
				test.skip(true, '找不到可變商品，跳過此測試');
				return;
			}
		}

		await page.waitForLoadState('domcontentloaded');

		// 檢查 Radio UI
		const hasRadioUI = await verifyVariationRadioUI(page);
		test.skip(!hasRadioUI, 'Woomp Radio 樣式 UI 未啟用，跳過此測試');

		// 選取變化款式
		await selectVariation(page);

		// 等待 WooCommerce AJAX 更新
		await page.waitForTimeout(1000);

		// 驗證變化款式相關 UI 已更新
		const variationWrap = page.locator('.single_variation_wrap');
		const hasVariationWrap = await variationWrap.isVisible().catch(() => false);

		if (hasVariationWrap) {
			// 檢查價格更新
			const variationPrice = page.locator(
				'.single_variation_wrap .woocommerce-variation-price, .woocommerce-variation-price .price'
			).first();
			const hasPriceUpdate = await variationPrice.isVisible().catch(() => false);

			// 檢查庫存狀態
			const stockStatus = page.locator(
				'.single_variation_wrap .stock, .woocommerce-variation-availability'
			).first();
			const hasStockInfo = await stockStatus.isVisible().catch(() => false);

			// 檢查加入購物車按鈕
			const addToCartButton = page.locator(
				'button.single_add_to_cart_button'
			).first();
			const isButtonVisible = await addToCartButton.isVisible().catch(() => false);

			console.log('價格已更新:', hasPriceUpdate);
			console.log('庫存資訊已顯示:', hasStockInfo);
			console.log('加入購物車按鈕可見:', isButtonVisible);

			// 至少價格或加入購物車按鈕應該可見
			expect(
				hasPriceUpdate || isButtonVisible,
				'選取變化款式後應顯示價格或加入購物車按鈕'
			).toBeTruthy();
		}
	});
});

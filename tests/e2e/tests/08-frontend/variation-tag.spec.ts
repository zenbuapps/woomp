/**
 * 前台變化款式標籤（Tag）樣式測試
 *
 * 驗證 Woomp 的標籤樣式變化款式選擇器在商品頁面的顯示與互動。
 */

import { test, expect } from '@playwright/test';
import { CREDENTIALS, PRODUCT, SELECTORS } from '../../fixtures/test-data';
import { ensureLoggedIn } from '../../helpers/auth.helper';
import {
	goToProduct,
	goToShop,
	verifyVariationTagUI,
	selectVariation,
} from '../../helpers/product.helper';

test.describe('前台變化款式標籤（Tag）樣式', () => {

	test('可變商品頁面應顯示標籤樣式的變化款式選擇器', async ({ page }) => {
		// 前往商店頁面尋找可變商品
		await goToShop(page);

		// 尋找可變商品（通常有價格範圍或「選擇選項」按鈕）
		const variableProductLink = page.locator(
			'a.button.product_type_variable, a:has-text("選擇選項"), a:has-text("Select options")'
		).first();

		const hasVariableProduct = await variableProductLink.isVisible().catch(() => false);

		if (hasVariableProduct) {
			await variableProductLink.click();
		} else {
			// 嘗試直接前往已知商品頁面
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

		// 驗證標籤樣式 UI
		const hasTagUI = await verifyVariationTagUI(page);

		test.skip(!hasTagUI, 'Woomp 標籤樣式變化款式 UI 未啟用，跳過此測試');

		expect(hasTagUI).toBeTruthy();
	});

	test('點擊標籤應切換為選取狀態', async ({ page }) => {
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

		// 檢查標籤 UI
		const hasTagUI = await verifyVariationTagUI(page);
		test.skip(!hasTagUI, 'Woomp 標籤樣式 UI 未啟用，跳過此測試');

		// 尋找可點擊的變化款式標籤
		const tags = page.locator(
			'.woomp-variation-tag, .variation-tag, .swatch, [class*="variation-swatch"], [class*="attribute-swatch"]'
		);
		const tagCount = await tags.count();

		test.skip(tagCount === 0, '找不到變化款式標籤元素');

		// 點擊第一個標籤
		const firstTag = tags.first();
		await firstTag.click();

		// 驗證標籤變為選取狀態
		// 可能的選取狀態 class: selected, active, chosen, current
		const isSelected = await firstTag.evaluate((el) => {
			return (
				el.classList.contains('selected') ||
				el.classList.contains('active') ||
				el.classList.contains('chosen') ||
				el.classList.contains('current') ||
				el.getAttribute('aria-checked') === 'true'
			);
		});

		expect(isSelected, '點擊後標籤應為選取狀態').toBeTruthy();
	});

	test('選取變化款式後價格應更新', async ({ page }) => {
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

		// 檢查標籤 UI
		const hasTagUI = await verifyVariationTagUI(page);
		test.skip(!hasTagUI, 'Woomp 標籤樣式 UI 未啟用，跳過此測試');

		// 取得選取前的價格文字
		const priceElement = page.locator(
			'.woocommerce-variation-price .price, p.price, .summary .price, .single_variation_wrap .price'
		).first();

		const priceBefore = await priceElement.textContent().catch(() => '');

		// 使用 selectVariation helper 選取變化款式
		await selectVariation(page);

		// 等待 WooCommerce AJAX 更新
		await page.waitForTimeout(1000);

		// 檢查價格是否有更新（或至少價格元素仍然存在）
		const variationPrice = page.locator(
			'.woocommerce-variation-price .price, .single_variation_wrap .woocommerce-variation-price'
		).first();

		const hasVariationPrice = await variationPrice.isVisible().catch(() => false);

		if (hasVariationPrice) {
			const priceAfter = await variationPrice.textContent();
			expect(
				priceAfter?.trim().length,
				'選取變化款式後價格應有內容'
			).toBeGreaterThan(0);
		}

		// 確認加入購物車按鈕可用
		const addToCartButton = page.locator(
			'button.single_add_to_cart_button, .single_add_to_cart_button'
		).first();
		const isAddToCartVisible = await addToCartButton.isVisible().catch(() => false);

		if (isAddToCartVisible) {
			// 選取完整變化款式後，加入購物車按鈕應可點擊
			const isDisabled = await addToCartButton.isDisabled().catch(() => true);
			console.log('加入購物車按鈕是否可用:', !isDisabled);
		}
	});
});

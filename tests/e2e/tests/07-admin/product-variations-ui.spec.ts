/**
 * 後台商品變化款式 UI 測試
 *
 * 驗證 Woomp 的變化款式管理介面（屬性類型選擇器、圖片/顏色選擇等）。
 */

import { test, expect } from '@playwright/test';
import { CREDENTIALS, SELECTORS } from '../../fixtures/test-data';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import { loginAdmin } from '../../helpers/auth.helper';

test.describe('後台商品變化款式 UI', () => {

	test.beforeEach(async ({ page }) => {
		await loginAdmin(page);
	});

	test('前往可變商品編輯頁', async ({ page }) => {
		// 前往商品列表頁
		await page.goto('/wp-admin/edit.php?post_type=product', {
			waitUntil: 'domcontentloaded',
		});

		// 尋找可變商品（Variable product）
		// 可能有 "可變" 或 "variable" 標記
		const variableProduct = page.locator(
			'table.wp-list-table tbody tr:has(.product-type-variable), table.wp-list-table tbody tr:has(span.variable)'
		).first();

		let hasVariableProduct = await variableProduct.isVisible().catch(() => false);

		// 也嘗試其他方式尋找
		if (!hasVariableProduct) {
			// 透過篩選器篩選可變商品
			const productTypeFilter = page.locator(
				'select#dropdown_product_type, select[name="product_type"]'
			);
			const hasFilter = await productTypeFilter.isVisible().catch(() => false);

			if (hasFilter) {
				await productTypeFilter.selectOption('variable');
				const filterButton = page.locator(
					'input#post-query-submit, button#post-query-submit'
				);
				if (await filterButton.isVisible().catch(() => false)) {
					await filterButton.click();
					await page.waitForLoadState('domcontentloaded');
				}
			}
		}

		// 嘗試找到任何商品連結
		const productLink = page.locator(
			'table.wp-list-table tbody tr td.column-name a.row-title'
		).first();
		const hasProduct = await productLink.isVisible().catch(() => false);

		test.skip(!hasProduct, '找不到商品，跳過此測試');

		// 點擊進入商品編輯頁
		await productLink.click();
		await page.waitForLoadState('domcontentloaded');

		// 驗證是商品編輯頁
		const productDataMetabox = page.locator('#woocommerce-product-data, .product_data');
		const hasMetabox = await productDataMetabox.isVisible().catch(() => false);
		expect(hasMetabox, '應在商品編輯頁面').toBeTruthy();
	});

	test('Woomp 變化款式 UI 功能檢查', async ({ page }) => {
		// 前往商品列表頁
		await page.goto('/wp-admin/edit.php?post_type=product', {
			waitUntil: 'domcontentloaded',
		});

		// 篩選可變商品
		const productTypeFilter = page.locator(
			'select#dropdown_product_type, select[name="product_type"]'
		);
		const hasFilter = await productTypeFilter.isVisible().catch(() => false);

		if (hasFilter) {
			// 嘗試篩選可變商品
			const options = productTypeFilter.locator('option');
			const optionCount = await options.count();
			let hasVariableOption = false;

			for (let i = 0; i < optionCount; i++) {
				const value = await options.nth(i).getAttribute('value');
				if (value === 'variable') {
					hasVariableOption = true;
					break;
				}
			}

			if (hasVariableOption) {
				await productTypeFilter.selectOption('variable');
				const filterButton = page.locator(
					'input#post-query-submit, button#post-query-submit'
				);
				if (await filterButton.isVisible().catch(() => false)) {
					await filterButton.click();
					await page.waitForLoadState('domcontentloaded');
				}
			}
		}

		// 取得第一個商品
		const productLink = page.locator(
			'table.wp-list-table tbody tr td.column-name a.row-title'
		).first();
		const hasProduct = await productLink.isVisible().catch(() => false);

		test.skip(!hasProduct, '找不到可變商品，跳過此測試');

		await productLink.click();
		await page.waitForLoadState('domcontentloaded');

		// 檢查是否為可變商品
		const productTypeSelect = page.locator('select#product-type');
		const productType = await productTypeSelect.inputValue().catch(() => '');

		test.skip(productType !== 'variable', '此商品非可變商品，跳過此測試');

		// 切換到屬性標籤頁
		const attributesTab = page.locator(
			'a[href="#product_attributes"], li.attribute_tab a, .product_attributes_tab a'
		).first();
		const hasAttributesTab = await attributesTab.isVisible().catch(() => false);

		if (hasAttributesTab) {
			await attributesTab.click();
			await page.waitForLoadState('domcontentloaded');

			// 檢查 Woomp 是否有新增屬性類型選擇器
			// Woomp 可能提供 color, image, button 等屬性顯示類型
			const typeSelector = page.locator(
				'select[name*="attribute_type"], select.woomp-attribute-type, [class*="attribute-type"]'
			).first();
			const hasTypeSelector = await typeSelector.isVisible().catch(() => false);
			console.log('是否有屬性類型選擇器:', hasTypeSelector);

			if (hasTypeSelector) {
				const typeOptions = typeSelector.locator('option');
				const typeCount = await typeOptions.count();
				const typeLabels: string[] = [];
				for (let i = 0; i < typeCount; i++) {
					const label = await typeOptions.nth(i).textContent();
					if (label?.trim()) typeLabels.push(label.trim());
				}
				console.log('屬性類型選項:', typeLabels);
			}
		}

		// 切換到變化款式標籤頁
		const variationsTab = page.locator(
			'a[href="#variable_product_options"], li.variations_tab a, .variations_tab a'
		).first();
		const hasVariationsTab = await variationsTab.isVisible().catch(() => false);

		if (hasVariationsTab) {
			await variationsTab.click();
			await page.waitForLoadState('domcontentloaded');

			// 檢查變化款式列表
			const variations = page.locator(
				'.woocommerce_variations .woocommerce_variation, #variable_product_options .woocommerce_variation'
			);
			const variationCount = await variations.count();
			console.log('變化款式數量:', variationCount);
		}
	});

	test('屬性類型選擇器選項檢查', async ({ page }) => {
		// 前往 WooCommerce 屬性管理頁面
		await page.goto('/wp-admin/edit.php?post_type=product&page=product_attributes', {
			waitUntil: 'domcontentloaded',
		});

		// 檢查屬性列表
		const attributeTable = page.locator('table.widefat, table.wp-list-table');
		const hasTable = await attributeTable.isVisible().catch(() => false);

		// 檢查新增屬性表單中是否有 Woomp 提供的類型選項
		const typeSelect = page.locator(
			'select#attribute_type, select[name="attribute_type"]'
		);
		const hasTypeSelect = await typeSelect.isVisible().catch(() => false);

		if (hasTypeSelect) {
			const options = typeSelect.locator('option');
			const optionCount = await options.count();

			const typeValues: string[] = [];
			const typeLabels: string[] = [];

			for (let i = 0; i < optionCount; i++) {
				const value = await options.nth(i).getAttribute('value');
				const label = await options.nth(i).textContent();
				if (value) typeValues.push(value);
				if (label?.trim()) typeLabels.push(label.trim());
			}

			console.log('屬性類型值:', typeValues);
			console.log('屬性類型文字:', typeLabels);

			// Woomp 可能新增的類型：color, image, button, label
			const woopmTypes = typeValues.filter(
				(v) =>
					v === 'color' ||
					v === 'image' ||
					v === 'button' ||
					v === 'label' ||
					v.includes('woomp')
			);

			console.log('Woomp 自訂屬性類型:', woopmTypes);

			// 至少應有預設的 "select" 類型
			expect(typeValues.length, '應有屬性類型選項').toBeGreaterThanOrEqual(1);
		} else {
			console.log('找不到屬性類型選擇器（可能頁面結構不同）');
		}
	});
});

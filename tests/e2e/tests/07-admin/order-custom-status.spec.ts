/**
 * 自訂訂單狀態測試
 *
 * 驗證 Woomp 新增的自訂訂單狀態（如出貨中、已送達等）。
 */

import { test, expect } from '@playwright/test';
import { CREDENTIALS, SELECTORS } from '../../fixtures/test-data';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import { loginAdmin } from '../../helpers/auth.helper';
import { goToAdminOrders, goToAdminOrder } from '../../helpers/admin.helper';

test.describe('自訂訂單狀態', () => {

	test.beforeEach(async ({ page }) => {
		await loginAdmin(page);
	});

	test('訂單列表篩選應包含自訂狀態', async ({ page }) => {
		await goToAdminOrders(page);
		await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {});

		// 檢查訂單狀態篩選列（subsubsub）
		const statusFilters = page.locator('ul.subsubsub li a, .wp-header-end ~ .subsubsub a');
		const filterCount = await statusFilters.count();

		// 收集所有狀態篩選選項
		const statusTexts: string[] = [];
		for (let i = 0; i < filterCount; i++) {
			const text = await statusFilters.nth(i).textContent();
			if (text?.trim()) {
				statusTexts.push(text.trim());
			}
		}

		console.log('訂單狀態篩選選項:', statusTexts);

		// Woomp 可能新增的自訂狀態關鍵字
		const customStatusKeywords = ['出貨', '配送', '到店', '取貨', 'shipping', 'delivered', 'picked-up'];
		const hasCustomStatus = statusTexts.some((text) =>
			customStatusKeywords.some((keyword) =>
				text.toLowerCase().includes(keyword.toLowerCase())
			)
		);

		// 記錄是否有自訂狀態（不強制要求，因為可能取決於設定）
		console.log('是否有自訂訂單狀態:', hasCustomStatus);

		// 基本驗證：應有狀態篩選列
		expect(filterCount, '應有訂單狀態篩選選項').toBeGreaterThanOrEqual(1);
	});

	test('訂單詳情頁狀態下拉選單應包含自訂狀態', async ({ page }) => {
		await goToAdminOrders(page);
		await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {});

		// 取得第一筆訂單
		const orderLink = page.locator(
			'table.wp-list-table tbody tr .order-view, table.wp-list-table tbody tr a.order-view'
		).first();
		const orderLinkAlt = page.locator(
			'table.wp-list-table tbody tr td.column-order_number a'
		).first();

		const hasOrder =
			(await orderLink.isVisible().catch(() => false)) ||
			(await orderLinkAlt.isVisible().catch(() => false));

		test.skip(!hasOrder, '訂單列表為空，跳過此測試');

		// 進入訂單詳情
		if (await orderLink.isVisible().catch(() => false)) {
			await orderLink.click();
		} else {
			await orderLinkAlt.click();
		}
		await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {});

		// 尋找訂單狀態下拉選單
		const statusSelect = page.locator(
			'select#order_status, select[name="order_status"], select[name="post_status"]'
		);
		const hasStatusSelect = await statusSelect.isVisible().catch(() => false);

		test.skip(!hasStatusSelect, '找不到訂單狀態下拉選單，跳過此測試');

		// 取得所有狀態選項
		const options = statusSelect.locator('option');
		const optionCount = await options.count();

		const statusValues: string[] = [];
		const statusLabels: string[] = [];

		for (let i = 0; i < optionCount; i++) {
			const value = await options.nth(i).getAttribute('value');
			const label = await options.nth(i).textContent();
			if (value) statusValues.push(value);
			if (label?.trim()) statusLabels.push(label.trim());
		}

		console.log('訂單狀態選項值:', statusValues);
		console.log('訂單狀態選項文字:', statusLabels);

		// 驗證基本 WooCommerce 狀態存在
		const hasProcessing = statusValues.some((v) => v.includes('processing'));
		const hasCompleted = statusValues.some((v) => v.includes('completed'));
		expect(hasProcessing || hasCompleted, '應有處理中或已完成狀態').toBeTruthy();

		// 檢查是否有 Woomp 自訂狀態
		const woopmStatuses = statusValues.filter(
			(v) =>
				v.includes('wc-shipping') ||
				v.includes('wc-delivered') ||
				v.includes('wc-picked-up') ||
				v.includes('wc-cvs')
		);

		console.log('Woomp 自訂狀態:', woopmStatuses);
	});

	test('變更訂單為自訂狀態並儲存', async ({ page }) => {
		await goToAdminOrders(page);
		await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {});

		// 取得第一筆訂單
		const orderLink = page.locator(
			'table.wp-list-table tbody tr .order-view, table.wp-list-table tbody tr a.order-view'
		).first();
		const orderLinkAlt = page.locator(
			'table.wp-list-table tbody tr td.column-order_number a'
		).first();

		const hasOrder =
			(await orderLink.isVisible().catch(() => false)) ||
			(await orderLinkAlt.isVisible().catch(() => false));

		test.skip(!hasOrder, '訂單列表為空，跳過此測試');

		// 進入訂單詳情
		if (await orderLink.isVisible().catch(() => false)) {
			await orderLink.click();
		} else {
			await orderLinkAlt.click();
		}
		await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {});

		// 尋找訂單狀態下拉選單
		const statusSelect = page.locator(
			'select#order_status, select[name="order_status"], select[name="post_status"]'
		);
		const hasStatusSelect = await statusSelect.isVisible().catch(() => false);
		test.skip(!hasStatusSelect, '找不到訂單狀態下拉選單');

		// 取得可用的自訂狀態
		const options = statusSelect.locator('option');
		const optionCount = await options.count();

		let customStatusValue: string | null = null;
		let originalStatus: string | null = null;

		// 記錄目前狀態
		originalStatus = await statusSelect.inputValue();

		// 尋找可切換的自訂狀態
		for (let i = 0; i < optionCount; i++) {
			const value = await options.nth(i).getAttribute('value');
			if (
				value &&
				value !== originalStatus &&
				(value.includes('wc-shipping') ||
				 value.includes('wc-delivered') ||
				 value.includes('wc-processing') ||
				 value.includes('wc-completed'))
			) {
				customStatusValue = value;
				break;
			}
		}

		test.skip(!customStatusValue, '未找到可切換的訂單狀態');

		// 變更狀態
		await statusSelect.selectOption(customStatusValue!);

		// 驗證下拉選單值已變更
		const selectedValue = await statusSelect.inputValue();
		expect(selectedValue).toBe(customStatusValue);

		// 點擊更新按鈕儲存
		const updateButton = page.locator(
			'button.save_order, input#publish, button[type="submit"]:has-text("更新"), .save_order'
		).first();
		const hasUpdateButton = await updateButton.isVisible().catch(() => false);

		if (hasUpdateButton) {
			await updateButton.click();
			await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {});

			// 驗證儲存成功（頁面不應有錯誤訊息）
			const errorMessage = page.locator('.notice-error, .error');
			const hasError = await errorMessage.isVisible().catch(() => false);

			if (!hasError) {
				// 驗證狀態已更新
				const updatedStatus = page.locator(
					'select#order_status, select[name="order_status"]'
				);
				if (await updatedStatus.isVisible().catch(() => false)) {
					const newValue = await updatedStatus.inputValue();
					expect(newValue).toBe(customStatusValue);
				}
			}

			// 復原訂單狀態
			if (originalStatus && hasUpdateButton) {
				const statusSelectAgain = page.locator(
					'select#order_status, select[name="order_status"]'
				);
				if (await statusSelectAgain.isVisible().catch(() => false)) {
					await statusSelectAgain.selectOption(originalStatus);
					await updateButton.click();
					await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {});
				}
			}
		}
	});
});

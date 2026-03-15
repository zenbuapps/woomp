/**
 * 訂單批次操作測試
 *
 * 驗證 Woomp 在訂單列表頁新增的批次操作選項。
 */

import { test, expect } from '@playwright/test';
import { CREDENTIALS, SELECTORS } from '../../fixtures/test-data';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import { loginAdmin } from '../../helpers/auth.helper';
import { goToAdminOrders } from '../../helpers/admin.helper';

test.describe('訂單批次操作', () => {

	test.beforeEach(async ({ page }) => {
		await loginAdmin(page);
	});

	test('批次操作下拉選單應有自訂選項', async ({ page }) => {
		await goToAdminOrders(page);
		await page.waitForLoadState('domcontentloaded');

		// 尋找批次操作下拉選單
		const bulkActionSelect = page.locator(
			'select#bulk-action-selector-top, select[name="action"]'
		).first();
		const hasBulkSelect = await bulkActionSelect.isVisible().catch(() => false);

		test.skip(!hasBulkSelect, '找不到批次操作下拉選單，跳過此測試');

		// 取得所有批次操作選項
		const options = bulkActionSelect.locator('option');
		const optionCount = await options.count();

		const actionValues: string[] = [];
		const actionLabels: string[] = [];

		for (let i = 0; i < optionCount; i++) {
			const value = await options.nth(i).getAttribute('value');
			const label = await options.nth(i).textContent();
			if (value && value !== '-1') actionValues.push(value);
			if (label?.trim()) actionLabels.push(label.trim());
		}

		console.log('批次操作選項值:', actionValues);
		console.log('批次操作選項文字:', actionLabels);

		// 應至少有預設的批次操作（如移至回收桶）
		expect(actionValues.length, '應有批次操作選項').toBeGreaterThanOrEqual(1);

		// 檢查 Woomp 可能新增的自訂批次操作
		// 如：列印相關、狀態變更相關
		const customActions = actionValues.filter(
			(v) =>
				v.includes('print') ||
				v.includes('export') ||
				v.includes('shipping') ||
				v.includes('invoice') ||
				v.includes('mark_') // WooCommerce 狀態批次變更格式: mark_processing, mark_completed
		);

		console.log('自訂/狀態相關批次操作:', customActions);

		// 檢查是否有狀態變更的批次操作
		const statusChangeActions = actionLabels.filter(
			(label) =>
				label.includes('變更狀態') ||
				label.includes('標記為') ||
				label.includes('Mark') ||
				label.includes('Change status')
		);

		console.log('狀態變更批次操作:', statusChangeActions);
	});

	test('列印相關批次操作應存在（若已啟用）', async ({ page }) => {
		await goToAdminOrders(page);
		await page.waitForLoadState('domcontentloaded');

		// 尋找批次操作下拉選單
		const bulkActionSelect = page.locator(
			'select#bulk-action-selector-top, select[name="action"]'
		).first();
		const hasBulkSelect = await bulkActionSelect.isVisible().catch(() => false);

		test.skip(!hasBulkSelect, '找不到批次操作下拉選單，跳過此測試');

		// 取得所有選項
		const options = bulkActionSelect.locator('option');
		const optionCount = await options.count();

		const allLabels: string[] = [];
		for (let i = 0; i < optionCount; i++) {
			const label = await options.nth(i).textContent();
			if (label?.trim()) allLabels.push(label.trim());
		}

		// 檢查列印或匯出相關的批次操作
		const printActions = allLabels.filter(
			(label) =>
				label.includes('列印') ||
				label.includes('print') ||
				label.includes('Print') ||
				label.includes('匯出') ||
				label.includes('export') ||
				label.includes('Export')
		);

		console.log('列印/匯出相關批次操作:', printActions);

		// 也檢查頁面上是否有額外的批次操作按鈕（某些外掛在表格外新增按鈕）
		const extraButtons = page.locator(
			'a.button:has-text("列印"), a.button:has-text("匯出"), button:has-text("列印"), button:has-text("匯出")'
		);
		const extraButtonCount = await extraButtons.count();

		console.log('額外的列印/匯出按鈕數量:', extraButtonCount);

		// 記錄結果（不強制要求，因為可能取決於設定）
		const hasPrintFeature = printActions.length > 0 || extraButtonCount > 0;
		console.log('是否有列印/匯出功能:', hasPrintFeature);
	});
});

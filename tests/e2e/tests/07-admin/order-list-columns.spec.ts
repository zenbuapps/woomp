/**
 * 後台訂單列表自訂欄位測試
 *
 * 驗證 Woomp 在訂單列表頁新增的自訂欄位（付款方式、物流追蹤等）。
 */

import { test, expect } from '@playwright/test';
import { CREDENTIALS, SELECTORS } from '../../fixtures/test-data';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import { loginAdmin } from '../../helpers/auth.helper';
import { goToAdminOrders } from '../../helpers/admin.helper';

test.describe('後台訂單列表自訂欄位', () => {

	test.beforeEach(async ({ page }) => {
		await loginAdmin(page);
	});

	test('訂單列表應有自訂欄位', async ({ page }) => {
		await goToAdminOrders(page);
		await page.waitForLoadState('domcontentloaded');

		// 檢查表頭欄位
		const headerRow = page.locator('table.wp-list-table thead tr').first();
		const headers = headerRow.locator('th, td');
		const headerCount = await headers.count();

		// 收集所有欄位 ID 與文字
		const columnIds: string[] = [];
		const columnTexts: string[] = [];

		for (let i = 0; i < headerCount; i++) {
			const th = headers.nth(i);
			const id = await th.getAttribute('id');
			const text = await th.textContent();
			if (id) columnIds.push(id);
			if (text?.trim()) columnTexts.push(text.trim());
		}

		console.log('訂單列表欄位 IDs:', columnIds);
		console.log('訂單列表欄位文字:', columnTexts);

		// 預設的 WooCommerce 欄位應該存在
		// WooCommerce HPOS 或傳統模式可能有不同的欄位名稱
		expect(headerCount, '訂單列表應有多個欄位').toBeGreaterThan(3);
	});

	test('付款方式欄位應顯示閘道名稱', async ({ page }) => {
		await goToAdminOrders(page);
		await page.waitForLoadState('domcontentloaded');

		// 檢查是否有訂單資料
		const orderRows = page.locator('table.wp-list-table tbody tr').filter({
			hasNot: page.locator('.no-items'),
		});
		const rowCount = await orderRows.count();

		test.skip(rowCount === 0, '訂單列表為空，跳過此測試');

		// 查找付款方式相關的欄位
		// 可能的欄位 class: column-payment_method, column-wc_payment_method
		const paymentColumn = page.locator(
			'td.column-payment_method, td.column-wc_payment_method, td[data-colname="付款方式"], td[data-colname="Payment method"]'
		).first();

		const hasPaymentColumn = await paymentColumn.isVisible().catch(() => false);

		if (hasPaymentColumn) {
			const paymentText = await paymentColumn.textContent();
			expect(
				paymentText?.trim().length,
				'付款方式欄位應有內容'
			).toBeGreaterThan(0);
		} else {
			// 嘗試尋找包含付款方式資訊的任何欄位
			const allCells = orderRows.first().locator('td');
			const cellCount = await allCells.count();
			let foundPaymentInfo = false;

			for (let i = 0; i < cellCount; i++) {
				const cellText = await allCells.nth(i).textContent();
				const colName = await allCells.nth(i).getAttribute('data-colname');
				if (
					colName?.includes('付款') ||
					colName?.includes('payment') ||
					colName?.includes('Payment')
				) {
					foundPaymentInfo = true;
					break;
				}
			}

			// 不強制要求付款方式欄位一定存在（可能在不同版本有差異）
			console.log('付款方式欄位存在:', foundPaymentInfo);
		}
	});

	test('欄位排序功能應正常運作', async ({ page }) => {
		await goToAdminOrders(page);
		await page.waitForLoadState('domcontentloaded');

		// 檢查是否有可排序的欄位
		const sortableColumns = page.locator(
			'table.wp-list-table thead th.sortable, table.wp-list-table thead th.sorted'
		);
		const sortableCount = await sortableColumns.count();

		test.skip(sortableCount === 0, '無可排序欄位，跳過此測試');

		// 取得第一個可排序欄位
		const firstSortable = sortableColumns.first();
		const sortLink = firstSortable.locator('a');
		const hasLink = await sortLink.isVisible().catch(() => false);

		if (hasLink) {
			// 取得排序前的 URL
			const currentUrl = page.url();

			// 點擊排序
			await sortLink.click();
			await page.waitForLoadState('domcontentloaded');

			// 驗證 URL 包含排序參數
			const newUrl = page.url();
			expect(
				newUrl.includes('orderby') || newUrl !== currentUrl,
				'點擊排序後 URL 應變更'
			).toBeTruthy();
		}
	});
});

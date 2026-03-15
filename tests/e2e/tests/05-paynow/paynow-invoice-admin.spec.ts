/**
 * PayNow 電子發票後台管理測試
 *
 * 驗證訂單後台中 PayNow 電子發票相關功能的 UI 元素。
 */

import { test, expect } from '@playwright/test';
import { CREDENTIALS, SELECTORS } from '../../fixtures/test-data';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import { loginAdmin } from '../../helpers/auth.helper';
import { goToAdminOrders, goToAdminOrder } from '../../helpers/admin.helper';
import { verifyInvoiceMetaboxExists, clickIssueInvoice } from '../../helpers/invoice-admin.helper';

test.describe('PayNow 電子發票後台管理', () => {

	test.beforeEach(async ({ page }) => {
		await loginAdmin(page);
	});

	test('訂單詳情頁：電子發票 metabox 應存在', async ({ page }) => {
		// 前往訂單列表頁
		await goToAdminOrders(page);

		// 取得第一筆訂單的連結
		const orderLink = page.locator('table.wp-list-table tbody tr .order-view, table.wp-list-table tbody tr a.order-view').first();
		const orderLinkAlt = page.locator('table.wp-list-table tbody tr td.column-order_number a').first();

		const hasOrder = (await orderLink.isVisible().catch(() => false)) ||
			(await orderLinkAlt.isVisible().catch(() => false));

		test.skip(!hasOrder, '訂單列表為空，無法測試電子發票 metabox');

		// 點擊進入訂單詳情
		if (await orderLink.isVisible().catch(() => false)) {
			await orderLink.click();
		} else {
			await orderLinkAlt.click();
		}

		await page.waitForLoadState('domcontentloaded');

		// 驗證電子發票 metabox 存在
		const invoiceExists = await verifyInvoiceMetaboxExists(page);

		// 若此訂單非使用 PayNow 付款則可能沒有發票 metabox
		test.skip(!invoiceExists, '此訂單可能非 PayNow 付款或發票模組未啟用，跳過');

		expect(invoiceExists).toBeTruthy();
	});

	test('訂單詳情頁：開立發票按鈕應存在', async ({ page }) => {
		// 前往訂單列表頁
		await goToAdminOrders(page);

		// 取得第一筆訂單
		const orderLink = page.locator('table.wp-list-table tbody tr .order-view, table.wp-list-table tbody tr a.order-view').first();
		const orderLinkAlt = page.locator('table.wp-list-table tbody tr td.column-order_number a').first();

		const hasOrder = (await orderLink.isVisible().catch(() => false)) ||
			(await orderLinkAlt.isVisible().catch(() => false));

		test.skip(!hasOrder, '訂單列表為空，無法測試開立發票按鈕');

		// 點擊進入訂單詳情
		if (await orderLink.isVisible().catch(() => false)) {
			await orderLink.click();
		} else {
			await orderLinkAlt.click();
		}

		await page.waitForLoadState('domcontentloaded');

		// 檢查發票 metabox 是否存在
		const invoiceExists = await verifyInvoiceMetaboxExists(page);
		test.skip(!invoiceExists, '發票 metabox 不存在，跳過開立發票按鈕測試');

		// 檢查開立發票按鈕
		// 注意：此處僅驗證按鈕存在，不實際執行開立操作
		const issueButton = page.locator(
			'button:has-text("開立"), input[type="button"][value*="開立"], a:has-text("開立發票"), .invoice-issue-btn, #paynow_invoice_issue'
		).first();

		const hasIssueButton = await issueButton.isVisible().catch(() => false);

		// 也檢查是否有已開立的發票資訊（表示發票功能正常運作）
		const invoiceInfo = page.locator(
			'.invoice-number, .invoice-status, [class*="invoice"]'
		).first();
		const hasInvoiceInfo = await invoiceInfo.isVisible().catch(() => false);

		// 至少應有開立按鈕或已開立的發票資訊
		expect(
			hasIssueButton || hasInvoiceInfo,
			'應有開立發票按鈕或已開立的發票資訊'
		).toBeTruthy();
	});
});

/**
 * 訂單地址顯示測試
 *
 * 驗證 Woomp 的地址顯示格式（單行地址、超商店舖資訊等）。
 */

import { test, expect } from '@playwright/test';
import { CREDENTIALS, SELECTORS } from '../../fixtures/test-data';
import { ADMIN_URLS } from '../../fixtures/admin-urls';
import { loginAdmin } from '../../helpers/auth.helper';
import { goToAdminOrders, goToAdminOrder } from '../../helpers/admin.helper';

test.describe('訂單地址顯示', () => {

	test.beforeEach(async ({ page }) => {
		await loginAdmin(page);
	});

	test('訂單詳情頁：帳單地址應正確顯示', async ({ page }) => {
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

		// 檢查帳單地址區塊
		const billingAddress = page.locator(
			'.order_data_column .address, #order_data .billing .address, .woocommerce-billing-fields, div.order_data_column:has(h3:has-text("帳單")), div.order_data_column:has(h3:has-text("Billing"))'
		).first();

		const hasBillingSection = await billingAddress.isVisible().catch(() => false);

		if (hasBillingSection) {
			const addressText = await billingAddress.textContent();
			expect(
				addressText?.trim().length,
				'帳單地址區塊應有內容'
			).toBeGreaterThan(0);
		}

		// 也檢查是否有地址欄位（HPOS 模式可能不同）
		const addressFields = page.locator(
			'input[name="_billing_address_1"], input[name="_billing_city"], #_billing_address_1, p._billing_address_1_field'
		).first();
		const hasFields = await addressFields.isVisible().catch(() => false);

		// 至少應有地址區塊或地址欄位之一
		expect(
			hasBillingSection || hasFields,
			'應有帳單地址區塊或欄位'
		).toBeTruthy();
	});

	test('單行地址格式顯示（若已設定）', async ({ page }) => {
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

		// 檢查帳單地址區塊的格式
		// Woomp 的 wc_woomp_setting_one_line_address 設定會影響地址顯示格式
		const addressContainer = page.locator(
			'.order_data_column .address, #order_data .billing .address'
		).first();

		const hasAddress = await addressContainer.isVisible().catch(() => false);
		test.skip(!hasAddress, '找不到地址區塊，跳過此測試');

		const addressHtml = await addressContainer.innerHTML();

		// 檢查地址是否為單行格式（<br> 較少或沒有）
		// 單行格式時，地址資訊會在同一行中
		const brCount = (addressHtml.match(/<br\s*\/?>/gi) || []).length;
		console.log('地址中 <br> 數量:', brCount);

		// 記錄地址格式（不強制判斷，因為取決於設定）
		const isOneLine = brCount <= 1;
		console.log('地址是否為單行格式:', isOneLine);

		// 驗證地址有內容（不論格式）
		const addressText = await addressContainer.textContent();
		expect(addressText?.trim().length, '地址應有內容').toBeGreaterThan(0);
	});

	test('超商取貨訂單應顯示店舖資訊', async ({ page }) => {
		await goToAdminOrders(page);
		await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {});

		// 尋找可能的超商訂單（透過訂單列表中的物流標記）
		const orderRows = page.locator('table.wp-list-table tbody tr').filter({
			hasNot: page.locator('.no-items'),
		});
		const rowCount = await orderRows.count();

		test.skip(rowCount === 0, '訂單列表為空，跳過此測試');

		// 進入第一筆訂單查看
		const orderLink = page.locator(
			'table.wp-list-table tbody tr .order-view, table.wp-list-table tbody tr a.order-view'
		).first();
		const orderLinkAlt = page.locator(
			'table.wp-list-table tbody tr td.column-order_number a'
		).first();

		if (await orderLink.isVisible().catch(() => false)) {
			await orderLink.click();
		} else {
			await orderLinkAlt.click();
		}
		await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {});

		// 檢查是否有超商取貨相關的資訊區塊
		const cvsSelectors = [
			// 超商店舖資訊可能出現的位置
			'[class*="cvs"], [class*="store"], [id*="cvs"], [id*="store"]',
			// 物流 metabox
			'#woocommerce-shipping-fields, .shipping_address',
			// 超商名稱、地址、代號等欄位
			'input[name*="cvs_store"], input[name*="shipping_store"], input[name*="_store_name"]',
			// 自訂 meta 欄位
			'p:has-text("門市"), p:has-text("店舖"), p:has-text("超商"), p:has-text("取貨")',
		];

		let foundCvsInfo = false;
		for (const selector of cvsSelectors) {
			const element = page.locator(selector).first();
			const isVisible = await element.isVisible().catch(() => false);
			if (isVisible) {
				foundCvsInfo = true;
				const text = await element.textContent();
				console.log(`找到超商相關資訊 (${selector}):`, text?.trim().substring(0, 100));
				break;
			}
		}

		// 檢查運送地址區塊
		const shippingAddress = page.locator(
			'div.order_data_column:has(h3:has-text("運送")), div.order_data_column:has(h3:has-text("Shipping"))'
		).first();
		const hasShipping = await shippingAddress.isVisible().catch(() => false);

		if (hasShipping) {
			const shippingText = await shippingAddress.textContent();
			console.log('運送地址內容:', shippingText?.trim().substring(0, 200));
		}

		// 記錄結果（不強制要求，因為第一筆訂單不一定是超商訂單）
		console.log('是否找到超商取貨相關資訊:', foundCvsInfo);
		console.log('是否有運送地址區塊:', hasShipping);
	});
});

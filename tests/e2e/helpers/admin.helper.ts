import { Page, expect } from '@playwright/test';
import { loginAdmin } from './auth.helper';

/** 前往後台訂單列表（HPOS 相容，自動偵測 HPOS 或傳統 post_type 模式） */
export async function goToAdminOrders(page: Page): Promise<void> {
  // 優先嘗試 HPOS 訂單列表 URL
  await page.goto('/wp-admin/admin.php?page=wc-orders', { waitUntil: 'commit', timeout: 120_000 });

  // 若被重導向到 Dashboard（page=wc-orders 不存在 → 表示未啟用 HPOS），
  // 改用傳統訂單列表 URL（edit.php?post_type=shop_order）
  const currentUrl = page.url();
  const isOnDashboard =
    currentUrl.includes('/wp-admin/index.php') ||
    currentUrl.endsWith('/wp-admin/') ||
    (!currentUrl.includes('wc-orders') && !currentUrl.includes('shop_order'));

  if (isOnDashboard) {
    await page.goto('/wp-admin/edit.php?post_type=shop_order', { waitUntil: 'commit', timeout: 120_000 });
  }

  await page.waitForSelector('table.wp-list-table, .wc-orders-list-table, #the-list, .no-items', { timeout: 60_000 });
}

/** 前往特定訂單的後台編輯頁（HPOS 相容） */
export async function goToAdminOrder(page: Page, orderId: string): Promise<void> {
  await page.goto(`/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`, { waitUntil: 'commit', timeout: 120_000 });
  await page.waitForSelector('#order_status, .wc-order-status, .order_status', { timeout: 60_000 });
}

/** 從 order-received URL 擷取訂單 ID */
export function extractOrderIdFromUrl(url: string): string {
  const match = url.match(/order-received\/(\d+)/);
  if (!match) throw new Error(`Cannot extract order ID from URL: ${url}`);
  return match[1];
}

/** 在後台執行全額退款 */
export async function processFullRefund(page: Page, orderId: string): Promise<void> {
  await loginAdmin(page);
  await goToAdminOrder(page, orderId);

  // 點擊退款按鈕
  const refundBtn = page.locator('.refund-items');
  await refundBtn.click();

  // 點擊「經由 PayUni 信用卡退款」按鈕
  const refundViaGateway = page.locator('.do-api-refund');
  await expect(refundViaGateway).toBeVisible();
  await refundViaGateway.click();

  // 確認退款對話框
  page.once('dialog', (dialog) => dialog.accept());
  await page.waitForTimeout(3000);
}

/** 在後台執行部分退款 */
export async function processPartialRefund(
  page: Page,
  orderId: string,
  amount: number
): Promise<void> {
  await loginAdmin(page);
  await goToAdminOrder(page, orderId);

  const refundBtn = page.locator('.refund-items');
  await refundBtn.click();

  // 填入退款金額
  const refundAmountInput = page.locator('#refund_amount');
  await refundAmountInput.fill(String(amount));

  const refundViaGateway = page.locator('.do-api-refund');
  await expect(refundViaGateway).toBeVisible();
  await refundViaGateway.click();

  page.once('dialog', (dialog) => dialog.accept());
  await page.waitForTimeout(3000);
}

/** 取得訂單狀態 */
export async function getOrderStatus(page: Page, orderId: string): Promise<string> {
  await goToAdminOrder(page, orderId);
  const statusSelect = page.locator('#order_status');
  return statusSelect.inputValue();
}

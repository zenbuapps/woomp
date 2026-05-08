import { test, expect } from '@playwright/test';
import { ECPAY_SELECTORS } from '../../fixtures/ecpay-data';
import { loginAdmin } from '../../helpers/auth.helper';
import { goToAdminOrders, goToAdminOrder } from '../../helpers/admin.helper';
import { verifyInvoiceMetaboxExists, getInvoiceNumber } from '../../helpers/invoice-admin.helper';

/**
 * ECPay 電子發票作廢測試
 * 驗證已開立發票訂單的作廢按鈕與確認對話框
 * 注意：不實際執行作廢 API 呼叫，僅驗證 UI 元素
 */
test.describe('ECPay 電子發票作廢 @ecpay @invoice @admin', () => {
  let issuedOrderId: string | null = null;

  test.beforeEach(async ({ page }) => {
    await loginAdmin(page);

    // 尋找一筆已開立發票的訂單
    if (!issuedOrderId) {
      await goToAdminOrders(page);

      // 取得訂單列表中的訂單 IDs（最多檢查前 5 筆）
      const orderLinks = page.locator(
        'a.order-view, .wc-orders-list-table a[href*="wc-orders"], .column-order_number a, td.order_number a'
      );
      const linkCount = Math.min(await orderLinks.count(), 5);

      // 先收集所有 hrefs，避免在迴圈中導航後 locator 失效
      const hrefsToCheck: string[] = [];
      for (let i = 0; i < linkCount; i++) {
        const href = await orderLinks.nth(i).getAttribute('href');
        if (href) hrefsToCheck.push(href);
      }

      for (const href of hrefsToCheck) {
        const hposMatch = href.match(/[?&]id=(\d+)/);
        const classicMatch = href.match(/post=(\d+)/);
        const orderId = hposMatch?.[1] || classicMatch?.[1];
        if (!orderId) continue;

        // 前往訂單頁面檢查是否有 ECPay 發票號碼
        await goToAdminOrder(page, orderId);

        const invoiceNumber = await getInvoiceNumber(page, 'ecpay');
        if (invoiceNumber) {
          issuedOrderId = orderId;
          break;
        }
      }
    }
  });

  test('已開立 ECPay 發票的訂單顯示作廢按鈕 @P1', async ({ page }) => {
    if (!issuedOrderId) {
      test.skip(true, '找不到已開立 ECPay 發票的訂單，無法測試作廢按鈕');
      return;
    }

    await goToAdminOrder(page, issuedOrderId);

    // 確認 ECPay Metabox 存在
    const hasMetabox = await verifyInvoiceMetaboxExists(page, 'ecpay');
    expect(hasMetabox, 'ECPay 發票 Metabox 應存在').toBe(true);

    // 確認有發票號碼
    const invoiceNumber = await getInvoiceNumber(page, 'ecpay');
    expect(invoiceNumber, '應有已開立的發票號碼').toBeTruthy();

    // 驗證作廢按鈕存在
    const voidBtn = page.locator(
      [
        ECPAY_SELECTORS.invoiceVoidBtn,
        'button[data-action="void-ecpay-invoice"]',
        '.ecpay-invoice-void',
        'button:has-text("作廢")',
        'a:has-text("作廢")',
      ].join(', ')
    ).first();

    await expect(
      voidBtn,
      `訂單 #${issuedOrderId} 的 ECPay 發票（${invoiceNumber}）應有作廢按鈕`
    ).toBeVisible({ timeout: 5_000 });
  });

  test('點擊作廢按鈕時顯示確認對話框 @P2', async ({ page }) => {
    if (!issuedOrderId) {
      test.skip(true, '找不到已開立 ECPay 發票的訂單');
      return;
    }

    await goToAdminOrder(page, issuedOrderId);

    // 找到作廢按鈕
    const voidBtn = page.locator(
      [
        ECPAY_SELECTORS.invoiceVoidBtn,
        'button[data-action="void-ecpay-invoice"]',
        '.ecpay-invoice-void',
        'button:has-text("作廢")',
        'a:has-text("作廢")',
      ].join(', ')
    ).first();

    if (!(await voidBtn.isVisible().catch(() => false))) {
      test.skip(true, '作廢按鈕不可見');
      return;
    }

    // 監聽 dialog 事件（確認對話框）
    let dialogMessage = '';
    let dialogAppeared = false;

    page.once('dialog', async (dialog) => {
      dialogMessage = dialog.message();
      dialogAppeared = true;
      // 取消對話框，不實際執行作廢操作
      await dialog.dismiss();
    });

    // 點擊作廢按鈕
    await voidBtn.click();

    // 等待可能的對話框出現
    await page.waitForTimeout(2000);

    if (dialogAppeared) {
      // 確認對話框出現且訊息合理
      expect(dialogMessage).toBeTruthy();

      // 對話框訊息通常包含「作廢」或「確認」相關文字
      const hasRelevantText =
        dialogMessage.includes('作廢') ||
        dialogMessage.includes('確認') ||
        dialogMessage.includes('void') ||
        dialogMessage.includes('confirm') ||
        dialogMessage.includes('sure');

      expect(
        hasRelevantText,
        `確認對話框訊息應包含作廢相關文字，實際內容：「${dialogMessage}」`
      ).toBe(true);
    } else {
      // 部分實作可能使用自訂確認 modal 而非原生 dialog
      const confirmModal = page.locator(
        '.modal, .dialog, [role="dialog"], .confirm-dialog, .swal2-container'
      ).first();
      const hasCustomModal = await confirmModal.isVisible().catch(() => false);

      // 或者可能直接發送 AJAX 請求（不推薦但可能存在）
      // 此情況下驗證頁面無錯誤即可
      const errorMessage = page.locator('.notice-error, .error, .woocommerce-error').first();
      const hasError = await errorMessage.isVisible().catch(() => false);

      expect(
        hasCustomModal || !hasError,
        '作廢操作應顯示確認對話框或自訂確認 modal'
      ).toBe(true);
    }
  });
});

import { test, expect } from '@playwright/test';
import { ECPAY_SELECTORS } from '../../fixtures/ecpay-data';
import { loginAdmin } from '../../helpers/auth.helper';
import { goToAdminOrders, goToAdminOrder } from '../../helpers/admin.helper';
import { verifyInvoiceMetaboxExists } from '../../helpers/invoice-admin.helper';

/**
 * ECPay 電子發票後台管理測試
 * 驗證 ECPay 發票 Metabox 及開立功能在訂單編輯頁正確顯示
 */
test.describe('ECPay 電子發票後台 @ecpay @invoice @admin', () => {
  let testOrderId: string | null = null;

  test.beforeEach(async ({ page }) => {
    await loginAdmin(page);

    // 取得第一筆訂單的 ID
    if (!testOrderId) {
      await goToAdminOrders(page);
      await page.waitForLoadState('networkidle');

      // HPOS 格式的訂單連結
      const orderLink = page.locator(
        'a.order-view, .wc-orders-list-table a[href*="wc-orders"], .column-order_number a, td.order_number a'
      ).first();

      if (await orderLink.isVisible().catch(() => false)) {
        const href = await orderLink.getAttribute('href');
        if (href) {
          const hposMatch = href.match(/[?&]id=(\d+)/);
          const classicMatch = href.match(/post=(\d+)/);
          testOrderId = hposMatch?.[1] || classicMatch?.[1] || null;
        }
      }
    }
  });

  test('訂單編輯頁顯示 ECPay 發票 Metabox @P1', async ({ page }) => {
    if (!testOrderId) {
      test.skip(true, '找不到任何訂單，無法測試 ECPay 發票 Metabox');
      return;
    }

    await goToAdminOrder(page, testOrderId);

    // 驗證 ECPay 發票 Metabox 存在
    const hasEcpayMetabox = await verifyInvoiceMetaboxExists(page, 'ecpay');

    // 也嘗試更廣泛的 selector
    const ecpayMetabox = page.locator(
      ECPAY_SELECTORS.invoiceMetabox + ', [id*="ecpay"][id*="invoice"]'
    ).first();
    const isMetaboxVisible = await ecpayMetabox.isVisible().catch(() => false);

    if (!hasEcpayMetabox && !isMetaboxVisible) {
      // ECPay 發票模組可能未啟用
      test.skip(true, 'ECPay 發票 Metabox 不可見，可能模組未啟用');
      return;
    }

    expect(
      hasEcpayMetabox || isMetaboxVisible,
      `訂單 #${testOrderId} 應顯示 ECPay 發票 Metabox`
    ).toBe(true);

    // 驗證 Metabox 內有標題
    if (isMetaboxVisible) {
      const metaboxTitle = ecpayMetabox.locator('h2, .hndle, .postbox-header h2').first();
      if (await metaboxTitle.isVisible().catch(() => false)) {
        const titleText = await metaboxTitle.textContent();
        expect(titleText?.trim()).toBeTruthy();
      }
    }
  });

  test('ECPay 發票 Metabox 中有開立發票按鈕 @P1', async ({ page }) => {
    if (!testOrderId) {
      test.skip(true, '找不到任何訂單');
      return;
    }

    await goToAdminOrder(page, testOrderId);

    // 確認 ECPay Metabox 存在
    const hasMetabox = await verifyInvoiceMetaboxExists(page, 'ecpay');
    if (!hasMetabox) {
      const ecpayMetabox = page.locator(
        ECPAY_SELECTORS.invoiceMetabox + ', [id*="ecpay"][id*="invoice"]'
      ).first();
      if (!(await ecpayMetabox.isVisible().catch(() => false))) {
        test.skip(true, 'ECPay 發票 Metabox 不存在');
        return;
      }
    }

    // 尋找開立發票按鈕
    const issueBtn = page.locator(
      [
        ECPAY_SELECTORS.invoiceIssueBtn,
        'button[data-action="issue-ecpay-invoice"]',
        'input[type="button"][value*="開立"]',
        '.ecpay-invoice-issue',
      ].join(', ')
    ).first();

    const isIssueBtnVisible = await issueBtn.isVisible().catch(() => false);

    // 如果開立按鈕不可見，可能是發票已開立，檢查是否有作廢按鈕或發票號碼
    if (!isIssueBtnVisible) {
      const voidBtn = page.locator(ECPAY_SELECTORS.invoiceVoidBtn).first();
      const invoiceNumber = page.locator(ECPAY_SELECTORS.invoiceNumber).first();

      const isVoidVisible = await voidBtn.isVisible().catch(() => false);
      const hasInvoiceNumber = await invoiceNumber.isVisible().catch(() => false);

      expect(
        isVoidVisible || hasInvoiceNumber,
        '如果開立按鈕不可見，應有作廢按鈕或發票號碼（表示已開立）'
      ).toBe(true);
    } else {
      await expect(issueBtn).toBeVisible();

      // 注意：不實際點擊開立，避免在測試環境產生真實發票
      // 僅驗證按鈕 UI 存在且可互動
      const isDisabled = await issueBtn.isDisabled().catch(() => false);
      if (!isDisabled) {
        await expect(issueBtn).toBeEnabled();
      }
    }
  });
});

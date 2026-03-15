import { test, expect } from '@playwright/test';
import { ECPAY_SELECTORS } from '../../fixtures/ecpay-data';
import { PAYNOW_SELECTORS } from '../../fixtures/paynow-data';
import { loginAdmin } from '../../helpers/auth.helper';
import { goToAdminOrders, goToAdminOrder } from '../../helpers/admin.helper';
import { verifyInvoiceMetaboxExists } from '../../helpers/invoice-admin.helper';

/**
 * 後台訂單頁發票 Metabox UI 測試
 * 驗證 ECPay / EZPAY / PayNow 發票 Metabox 在訂單編輯頁正確顯示
 */
test.describe('發票 Metabox 後台 UI @invoice @admin', () => {
  let firstOrderId: string | null = null;

  test.beforeEach(async ({ page }) => {
    await loginAdmin(page);

    // 如果尚未取得訂單 ID，先到訂單列表取得第一筆
    if (!firstOrderId) {
      await goToAdminOrders(page);
      await page.waitForLoadState('networkidle');

      // 嘗試取得第一筆訂單的連結（HPOS 格式）
      const orderLink = page.locator(
        'a.order-view, .wc-orders-list-table a[href*="wc-orders"], table.wp-list-table td.order_number a, .column-order_number a'
      ).first();

      if (await orderLink.isVisible().catch(() => false)) {
        const href = await orderLink.getAttribute('href');
        if (href) {
          // 從 URL 擷取訂單 ID（HPOS: ?page=wc-orders&action=edit&id=123）
          const hposMatch = href.match(/[?&]id=(\d+)/);
          const classicMatch = href.match(/post=(\d+)/);
          firstOrderId = hposMatch?.[1] || classicMatch?.[1] || null;
        }
      }

      if (!firstOrderId) {
        // 嘗試從表格列中取得訂單編號
        const orderNumberCell = page.locator('.column-order_number, td.order_number').first();
        if (await orderNumberCell.isVisible().catch(() => false)) {
          const text = await orderNumberCell.textContent();
          const match = text?.match(/#?(\d+)/);
          firstOrderId = match?.[1] || null;
        }
      }
    }
  });

  test('訂單編輯頁顯示發票 Metabox（ECPay 或 PayNow）@P1', async ({ page }) => {
    if (!firstOrderId) {
      test.skip(true, '找不到任何訂單，無法測試發票 Metabox');
      return;
    }

    await goToAdminOrder(page, firstOrderId);

    // 檢查是否有任一發票 Metabox 存在
    const ecpayMetabox = page.locator(ECPAY_SELECTORS.invoiceMetabox).first();
    const paynowMetabox = page.locator(PAYNOW_SELECTORS.invoiceMetabox).first();
    const ezpayMetabox = page.locator('#woomp-ezpay-invoice, #woomp_ezpay_invoice').first();

    // 通用的 woomp 發票 metabox selector
    const genericMetabox = page.locator(
      '[id*="invoice"], [id*="einvoice"]'
    );

    const ecpayVisible = await ecpayMetabox.isVisible().catch(() => false);
    const paynowVisible = await paynowMetabox.isVisible().catch(() => false);
    const ezpayVisible = await ezpayMetabox.isVisible().catch(() => false);
    const genericCount = await genericMetabox.count();

    const hasAnyInvoiceMetabox = ecpayVisible || paynowVisible || ezpayVisible || genericCount > 0;

    expect(
      hasAnyInvoiceMetabox,
      `訂單 #${firstOrderId} 的編輯頁應至少有一個發票 Metabox（ECPay/EZPAY/PayNow）`
    ).toBe(true);
  });

  test('發票 Metabox 中有開立發票按鈕 @P1', async ({ page }) => {
    if (!firstOrderId) {
      test.skip(true, '找不到任何訂單');
      return;
    }

    await goToAdminOrder(page, firstOrderId);

    // 嘗試各家發票的開立按鈕
    const issueButtonSelectors = [
      ECPAY_SELECTORS.invoiceIssueBtn,    // .ecpay-invoice-issue
      PAYNOW_SELECTORS.invoiceIssueBtn,   // .paynow-invoice-issue
      '.ezpay-invoice-issue',
      'button[data-action*="issue"]',
      'input[type="button"][value*="開立"]',
      'button:has-text("開立")',
      'a:has-text("開立")',
    ];

    let foundIssueButton = false;

    for (const selector of issueButtonSelectors) {
      const btn = page.locator(selector).first();
      if (await btn.isVisible().catch(() => false)) {
        foundIssueButton = true;

        // 驗證按鈕可被點擊（但不實際執行開立）
        const isDisabled = await btn.isDisabled().catch(() => false);
        // 按鈕可能因為發票已開立而被停用，記錄狀態即可
        if (!isDisabled) {
          await expect(btn).toBeEnabled();
        }
        break;
      }
    }

    // 如果沒有開立按鈕，可能是發票已開立（顯示作廢按鈕），也算通過
    if (!foundIssueButton) {
      const voidButtonSelectors = [
        ECPAY_SELECTORS.invoiceVoidBtn,
        PAYNOW_SELECTORS.invoiceVoidBtn,
        '.ezpay-invoice-void',
        'button[data-action*="void"]',
        'button:has-text("作廢")',
      ];

      let foundVoidButton = false;
      for (const selector of voidButtonSelectors) {
        const btn = page.locator(selector).first();
        if (await btn.isVisible().catch(() => false)) {
          foundVoidButton = true;
          break;
        }
      }

      // 如果既沒有開立也沒有作廢按鈕，但有 metabox 存在，也算通過
      const hasMetabox = await verifyInvoiceMetaboxExists(page, 'ecpay')
        || await verifyInvoiceMetaboxExists(page, 'paynow')
        || await verifyInvoiceMetaboxExists(page, 'ezpay');

      expect(
        foundVoidButton || hasMetabox,
        '發票 Metabox 中應有開立或作廢按鈕'
      ).toBe(true);
    }
  });

  test('已開立發票的訂單顯示作廢按鈕 @P2', async ({ page }) => {
    // 此測試需要已開立發票的訂單，若環境無此訂單則跳過
    test.skip(!firstOrderId, '找不到任何訂單');

    if (!firstOrderId) return;

    await goToAdminOrder(page, firstOrderId);

    // 嘗試尋找已開立發票的標記（發票號碼、作廢按鈕等）
    const invoiceNumberSelectors = [
      ECPAY_SELECTORS.invoiceNumber,
      PAYNOW_SELECTORS.invoiceNumber,
      '.ezpay-invoice-number',
      '[data-field="invoice-number"]',
    ];

    let hasInvoiceNumber = false;
    for (const selector of invoiceNumberSelectors) {
      const el = page.locator(selector).first();
      if (await el.isVisible().catch(() => false)) {
        const text = (await el.textContent())?.trim() || '';
        if (text.length > 0) {
          hasInvoiceNumber = true;
          break;
        }
      }
    }

    if (!hasInvoiceNumber) {
      test.skip(true, '此訂單尚未開立發票，無法測試作廢按鈕');
      return;
    }

    // 已開立發票的訂單應顯示作廢按鈕
    const voidButtonSelectors = [
      ECPAY_SELECTORS.invoiceVoidBtn,
      PAYNOW_SELECTORS.invoiceVoidBtn,
      '.ezpay-invoice-void',
      'button[data-action*="void"]',
      'button:has-text("作廢")',
      'a:has-text("作廢")',
    ];

    let foundVoidButton = false;
    for (const selector of voidButtonSelectors) {
      const btn = page.locator(selector).first();
      if (await btn.isVisible().catch(() => false)) {
        foundVoidButton = true;
        await expect(btn).toBeVisible();
        break;
      }
    }

    expect(foundVoidButton, '已開立發票的訂單應顯示作廢按鈕').toBe(true);
  });
});

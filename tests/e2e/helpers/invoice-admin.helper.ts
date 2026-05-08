import { Page, expect } from '@playwright/test';
import { goToAdminOrder } from './admin.helper';

/**
 * 後台發票 Metabox 操作 Helper
 * 支援 ECPay / EZPAY / PayNow 三種發票系統
 */

export type InvoiceProvider = 'ecpay' | 'ezpay' | 'paynow';

/** 發票 Metabox 的 CSS selector 對照 */
const METABOX_SELECTORS: Record<InvoiceProvider, {
  metabox: string;
  issueBtn: string;
  voidBtn: string;
  numberField: string;
  statusField: string;
}> = {
  ecpay: {
    metabox: '#woomp-ecpay-invoice, #woomp_ecpay_invoice',
    issueBtn: '.ecpay-invoice-issue, button[data-action="issue-ecpay-invoice"]',
    voidBtn: '.ecpay-invoice-void, button[data-action="void-ecpay-invoice"]',
    numberField: '.ecpay-invoice-number, [data-field="invoice-number"]',
    statusField: '.ecpay-invoice-status, [data-field="invoice-status"]',
  },
  ezpay: {
    metabox: '#woomp-ezpay-invoice, #woomp_ezpay_invoice',
    issueBtn: '.ezpay-invoice-issue, button[data-action="issue-ezpay-invoice"]',
    voidBtn: '.ezpay-invoice-void, button[data-action="void-ezpay-invoice"]',
    numberField: '.ezpay-invoice-number',
    statusField: '.ezpay-invoice-status',
  },
  paynow: {
    metabox: '#paynow-einvoice, #paynow_einvoice',
    issueBtn: '.paynow-invoice-issue, button[data-action="issue-paynow-invoice"]',
    voidBtn: '.paynow-invoice-void, button[data-action="void-paynow-invoice"]',
    numberField: '.paynow-invoice-number',
    statusField: '.paynow-invoice-status',
  },
};

/** 驗證發票 Metabox 是否存在 */
export async function verifyInvoiceMetaboxExists(
  page: Page,
  provider: InvoiceProvider
): Promise<boolean> {
  const selectors = METABOX_SELECTORS[provider];
  const metabox = page.locator(selectors.metabox);
  return metabox.isVisible().catch(() => false);
}

/** 點擊開立發票按鈕 */
export async function clickIssueInvoice(
  page: Page,
  provider: InvoiceProvider
): Promise<void> {
  const selectors = METABOX_SELECTORS[provider];
  const btn = page.locator(selectors.issueBtn).first();
  await expect(btn).toBeVisible({ timeout: 5000 });
  await btn.click();

  // 等待 AJAX 完成
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2000);
}

/** 點擊作廢發票按鈕 */
export async function clickVoidInvoice(
  page: Page,
  provider: InvoiceProvider
): Promise<void> {
  const selectors = METABOX_SELECTORS[provider];
  const btn = page.locator(selectors.voidBtn).first();
  await expect(btn).toBeVisible({ timeout: 5000 });

  // 可能會彈出確認對話框
  page.once('dialog', (dialog) => dialog.accept());
  await btn.click();

  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2000);
}

/** 取得發票號碼 */
export async function getInvoiceNumber(
  page: Page,
  provider: InvoiceProvider
): Promise<string> {
  const selectors = METABOX_SELECTORS[provider];
  const field = page.locator(selectors.numberField).first();
  if (await field.isVisible().catch(() => false)) {
    return (await field.textContent())?.trim() || '';
  }
  return '';
}

/** 取得發票狀態文字 */
export async function getInvoiceStatus(
  page: Page,
  provider: InvoiceProvider
): Promise<string> {
  const selectors = METABOX_SELECTORS[provider];
  const field = page.locator(selectors.statusField).first();
  if (await field.isVisible().catch(() => false)) {
    return (await field.textContent())?.trim() || '';
  }
  return '';
}

/** 在訂單頁面開立發票並驗證成功 */
export async function issueInvoiceForOrder(
  page: Page,
  orderId: string,
  provider: InvoiceProvider
): Promise<string> {
  await goToAdminOrder(page, orderId);
  await clickIssueInvoice(page, provider);

  // 重新載入頁面確認
  await page.reload();
  await page.waitForLoadState('networkidle');

  const invoiceNumber = await getInvoiceNumber(page, provider);
  return invoiceNumber;
}

/** 在訂單頁面作廢發票 */
export async function voidInvoiceForOrder(
  page: Page,
  orderId: string,
  provider: InvoiceProvider
): Promise<void> {
  await goToAdminOrder(page, orderId);
  await clickVoidInvoice(page, provider);
}

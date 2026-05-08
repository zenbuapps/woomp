import { Page, expect } from '@playwright/test';

/**
 * 物流後台操作 Helper
 * 處理超商選店、物流單號查詢、物流狀態等操作
 */

/** 超商取貨物流方式 prefix 列表 */
export const CVS_SHIPPING_PREFIXES = [
  'ry_ecpay_shipping_cvs',
  'ry_newebpay_shipping_cvs',
  'ry_smilepay_shipping_cvs',
  'paynow_shipping_c2c',
];

/** 宅配物流方式 prefix 列表 */
export const HOME_DELIVERY_PREFIXES = [
  'ry_ecpay_shipping_home',
  'ry_newebpay_shipping_home',
  'paynow_shipping_hd',
];

/** 判斷指定物流方式是否為超商取貨 */
export function isCvsShipping(shippingMethodId: string): boolean {
  return CVS_SHIPPING_PREFIXES.some(prefix => shippingMethodId.startsWith(prefix));
}

/** 在結帳頁面選擇物流方式 */
export async function selectShippingMethod(page: Page, methodId: string): Promise<void> {
  const radio = page.locator(`input[name="shipping_method[0]"][value="${methodId}"]`);

  if (await radio.count() === 0) {
    // 嘗試 partial match（物流方式 ID 可能包含 instance ID）
    const partialRadio = page.locator(`input[name="shipping_method[0]"][value*="${methodId}"]`);
    if (await partialRadio.count() > 0) {
      await partialRadio.first().check({ force: true });
    } else {
      throw new Error(`Shipping method ${methodId} not found on checkout page`);
    }
  } else {
    await radio.first().check({ force: true });
  }

  // 等待結帳 AJAX 更新
  await page.waitForTimeout(1000);
  const blockUI = page.locator('.blockUI.blockOverlay');
  if (await blockUI.isVisible().catch(() => false)) {
    await blockUI.waitFor({ state: 'hidden', timeout: 15_000 });
  }
}

/** 取得目前顯示的物流方式列表 */
export async function getAvailableShippingMethods(page: Page): Promise<string[]> {
  const radios = page.locator('input[name="shipping_method[0]"]');
  const count = await radios.count();
  const methods: string[] = [];

  for (let i = 0; i < count; i++) {
    const value = await radios.nth(i).getAttribute('value');
    if (value) methods.push(value);
  }
  return methods;
}

/** 驗證超商選店按鈕顯示 */
export async function verifyCvsStorePickerVisible(page: Page): Promise<boolean> {
  // 常見超商選店按鈕的 selectors
  const selectors = [
    '.select-store-btn',
    'button[data-action="select-store"]',
    '.cvs-store-select',
    '#shipping_store_btn',
  ];

  for (const selector of selectors) {
    if (await page.locator(selector).isVisible().catch(() => false)) {
      return true;
    }
  }
  return false;
}

/** 取得後台訂單的物流單號 */
export async function getShippingTrackingNumber(page: Page): Promise<string> {
  // 嘗試多種可能的 selector
  const selectors = [
    '.shipping-tracking-number',
    '[data-field="tracking-number"]',
    'input[name="_shipping_tracking_number"]',
    '.logistics-id',
  ];

  for (const selector of selectors) {
    const el = page.locator(selector).first();
    if (await el.isVisible().catch(() => false)) {
      const tagName = await el.evaluate(e => e.tagName);
      return tagName === 'INPUT'
        ? await el.inputValue()
        : (await el.textContent())?.trim() || '';
    }
  }
  return '';
}

/** 在後台訂單列表驗證物流欄位存在 */
export async function verifyShippingColumnExists(page: Page): Promise<boolean> {
  const shippingCol = page.locator('th.shipping_no, th.logistics-id, th.column-shipping');
  return shippingCol.isVisible().catch(() => false);
}

import { Page, expect } from '@playwright/test';
import { ADMIN_URLS } from '../fixtures/admin-urls';

/**
 * 商品頁面操作 Helper
 * 處理前台商品展示和後台商品編輯的操作
 */

/** 前往商品頁面 */
export async function goToProduct(page: Page, productId?: number | string): Promise<void> {
  const url = productId != null ? `/?p=${productId}` : '/shop/';
  await page.goto(url, { waitUntil: 'commit' });
  await page.waitForLoadState('load', { timeout: 60_000 });
}

/** 前往商品編輯頁面 (後台) */
export async function goToProductEdit(page: Page, productId: number | string): Promise<void> {
  // 使用 commit 避免 admin 頁面因 Query Monitor 背景請求導致 networkidle 無法達成
  await page.goto(ADMIN_URLS.productEdit(productId), { waitUntil: 'commit', timeout: 120_000 });
  await page.waitForSelector('#woocommerce-product-data, .postbox, #post', { timeout: 60_000 });
}

/** 前往商品列表 (前台) */
export async function goToShop(page: Page): Promise<void> {
  await page.goto('/shop/', { waitUntil: 'commit' });
  await page.waitForLoadState('load', { timeout: 60_000 });
}

/** 驗證可變商品的屬性類型選擇器 (後台) */
export async function verifyVariationUI(page: Page): Promise<boolean> {
  // Woomp 的好用版可變商品編輯介面
  const variationUI = page.locator('.woomp-variation-ui, .woomp-product-variation');
  return variationUI.isVisible().catch(() => false);
}

/** 驗證前台商品頁面的標籤式選項 */
export async function verifyVariationTagUI(page: Page): Promise<boolean> {
  const tagUI = page.locator(
    '.woomp-variation-tag, ' +
    '.woomp-swatch, ' +
    '.product-variation-tag, ' +
    '.variations .value .woomp-frontend-ui'
  );
  return tagUI.first().isVisible().catch(() => false);
}

/** 驗證前台商品頁面的單選方塊 (Radio) 選項 */
export async function verifyVariationRadioUI(page: Page): Promise<boolean> {
  const radioUI = page.locator(
    '.woomp-variation-radio, ' +
    'input[type="radio"].woomp-variation-option, ' +
    '.variations .value .woomp-radio-ui'
  );
  return radioUI.first().isVisible().catch(() => false);
}

/** 在前台選擇特定的商品變體 */
export async function selectVariation(page: Page, attributeName?: string, value?: string): Promise<void> {
  // 嘗試 select 下拉 (原版 WC)
  const select = page.locator(`select#${attributeName}, select[name="attribute_${attributeName}"]`);
  if (await select.isVisible().catch(() => false)) {
    await select.selectOption(value);
    await page.waitForTimeout(500);
    return;
  }

  // 嘗試 tag UI (Woomp)
  const tag = page.locator(`.woomp-frontend-ui [data-value="${value}"], .woomp-swatch[data-value="${value}"]`);
  if (await tag.isVisible().catch(() => false)) {
    await tag.click();
    await page.waitForTimeout(500);
    return;
  }

  // 嘗試 radio UI (Woomp)
  const radio = page.locator(`input[type="radio"][value="${value}"]`);
  if (await radio.count() > 0) {
    await radio.first().check({ force: true });
    await page.waitForTimeout(500);
    return;
  }
}

/** 驗證商品頁面的加入購物車按鈕 */
export async function verifyAddToCartButton(page: Page): Promise<boolean> {
  const btn = page.locator('.single_add_to_cart_button, button[name="add-to-cart"]');
  return btn.isVisible().catch(() => false);
}

/** 取得商品價格文字 */
export async function getProductPrice(page: Page): Promise<string> {
  const priceEl = page.locator('.price .woocommerce-Price-amount, .summary .price').first();
  return (await priceEl.textContent())?.trim() || '';
}

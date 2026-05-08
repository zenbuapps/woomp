import { Page } from '@playwright/test';
import { PRODUCT, URLS } from '../fixtures/test-data';

/**
 * 清空購物車
 * 優先嘗試 WC Store API，失敗則手動逐一刪除購物車商品
 */
export async function clearCart(page: Page): Promise<void> {
  await page.goto('/cart/', { waitUntil: 'commit' });
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 }).catch(() => {});

  // 嘗試使用 WC Store API 清空購物車
  const cleared = await page.evaluate(async () => {
    try {
      // WC Store API v1 支援 DELETE /cart/items 清空所有商品
      const storeBase = '/wp-json/wc/store/v1';
      const infoRes = await fetch(storeBase);
      const info = await infoRes.json().catch(() => ({}));
      const nonce = info?.nonce || '';

      const res = await fetch(`${storeBase}/cart/items`, {
        method: 'DELETE',
        headers: {
          'Nonce': nonce,
          'X-WC-Store-API-Nonce': nonce,
        },
      });
      return res.ok;
    } catch {
      return false;
    }
  });

  if (cleared) return;

  // Fallback：手動點擊 Remove 按鈕
  for (let i = 0; i < 15; i++) {
    const removeBtn = page.locator(
      '.product-remove a, td.product-remove a, .wc-block-cart-item__remove-link'
    );
    const count = await removeBtn.count();
    if (count === 0) break;
    await removeBtn.first().click({ force: true });
    await page.waitForTimeout(1200);
  }
}

/**
 * 加入商品至購物車
 * WooCommerce 的 ?add-to-cart={id} 會自動加入並導向購物車頁或結帳頁
 * 使用 domcontentloaded 避免等待外部 JS（如 PayUni SDK）拖慢導覽速度
 */
export async function addProductToCart(page: Page, addToCartUrl?: string): Promise<void> {
  const url = addToCartUrl || PRODUCT.addToCartUrl;
  await page.goto(url, { waitUntil: 'commit', timeout: 120_000 });
  // 等待 DOM ready（不等 load 事件，避免外部資源阻塞）
  await page.waitForLoadState('domcontentloaded', { timeout: 60_000 }).catch(() => {});
}

/** 前往結帳頁面 */
export async function goToCheckout(page: Page): Promise<void> {
  await page.goto(URLS.checkout, { waitUntil: 'commit', timeout: 120_000 });
  // 等待結帳表單出現（代表 WC checkout JS 已初始化足夠）
  await page.waitForSelector(
    'form.woocommerce-checkout, form[name="checkout"], .woocommerce-checkout',
    { timeout: 60_000 },
  ).catch(() => {});
}

/**
 * 加入商品並前往結帳（組合步驟）
 *
 * 自動清空購物車再加入商品，避免前一個 test 的 cart 狀態（多件商品、
 * 待處理訂單等）導致 WC 將 checkout 重導向至 my-account 或首頁。
 * 加入自動重試機制：
 * 有時 WC session 在 retry（新 browser context）中未正確攜帶，
 * 導致 add-to-cart 後購物車為空，checkout 頁重導向到 My Account。
 * 偵測到不在結帳頁時，重新加入購物車並再次前往結帳。
 */
export async function addToCartAndCheckout(page: Page, addToCartUrl?: string): Promise<void> {
  // 先清空購物車（純 REST API，不觸發頁面導覽），確保乾淨的 cart 狀態
  // 避免前一個 test 殘留商品導致 WC 重導向至非結帳頁
  await page.evaluate(async () => {
    try {
      const info = await fetch('/wp-json/wc/store/v1').then(r => r.json()).catch(() => ({}));
      const nonce = (info as any)?.nonce || '';
      await fetch('/wp-json/wc/store/v1/cart/items', {
        method: 'DELETE',
        headers: { 'Nonce': nonce, 'X-WC-Store-API-Nonce': nonce },
      });
    } catch { /* ignore */ }
  }).catch(() => {});

  await addProductToCart(page, addToCartUrl);
  await goToCheckout(page);

  // 確認是否在結帳頁（URL 包含 /checkout/）
  const isOnCheckout = page.url().includes('/checkout');
  if (!isOnCheckout) {
    // 購物車可能為空或 WC 將我們重導向至其他頁面，重試一次
    await addProductToCart(page, addToCartUrl);
    await goToCheckout(page);
  }
}

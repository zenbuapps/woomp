import { Page } from '@playwright/test';
import { PRODUCT, URLS } from '../fixtures/test-data';

/**
 * 加入商品至購物車
 * WooCommerce 的 ?add-to-cart={id} 會自動加入並導向購物車頁
 */
export async function addProductToCart(page: Page, addToCartUrl?: string): Promise<void> {
  const url = addToCartUrl || PRODUCT.addToCartUrl;
  await page.goto(url);
  await page.waitForLoadState('networkidle');
}

/** 前往結帳頁面 */
export async function goToCheckout(page: Page): Promise<void> {
  await page.goto(URLS.checkout);
  await page.waitForLoadState('networkidle');
}

/** 加入商品並前往結帳（組合步驟）*/
export async function addToCartAndCheckout(page: Page, addToCartUrl?: string): Promise<void> {
  await addProductToCart(page, addToCartUrl);
  await goToCheckout(page);
}

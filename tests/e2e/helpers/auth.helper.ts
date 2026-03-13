import { Page, expect } from '@playwright/test';
import { CREDENTIALS, URLS } from '../fixtures/test-data';

/** WordPress 前台登入 */
export async function loginWordPress(page: Page): Promise<void> {
  await page.goto(URLS.wpLogin);
  await page.locator('#user_login').fill(CREDENTIALS.username);
  await page.locator('#user_pass').fill(CREDENTIALS.password);
  await page.locator('#wp-submit').click();
  await page.waitForURL('**/wp-admin/**');
}

/** WordPress 後台管理員登入 */
export async function loginAdmin(page: Page): Promise<void> {
  await loginWordPress(page);
}

/** 確認已登入，否則執行登入 */
export async function ensureLoggedIn(page: Page): Promise<void> {
  await page.goto(URLS.myAccount);
  await page.waitForLoadState('networkidle');

  const logoutLink = page.locator('a[href*="customer-logout"]').first();
  if (await logoutLink.isVisible().catch(() => false)) {
    return;
  }

  const username = page.locator('#user_login, #username, input[name="username"]').first();
  const password = page.locator('#user_pass, #password, input[name="password"]').first();
  const submit = page.locator('#wp-submit, button[name="login"], .woocommerce-form-login__submit').first();

  if (await username.isVisible().catch(() => false)) {
    await username.fill(CREDENTIALS.username);
    await password.fill(CREDENTIALS.password);
    await submit.click();
    await page.waitForLoadState('networkidle');
  }
}

/** 登出 */
export async function logout(page: Page): Promise<void> {
  await page.goto(URLS.myAccount);
  const logoutLink = page.locator('a[href*="customer-logout"]');
  if (await logoutLink.isVisible().catch(() => false)) {
    await logoutLink.click();
    await page.waitForLoadState('networkidle');
  }
}

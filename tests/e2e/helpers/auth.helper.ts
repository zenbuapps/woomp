import { Page, expect } from '@playwright/test';
import { CREDENTIALS, URLS } from '../fixtures/test-data';

/** WordPress 前台登入（不強制要求導向 wp-admin） */
export async function loginWordPress(page: Page): Promise<void> {
  await page.goto(URLS.wpLogin);
  await page.locator('#user_login').fill(CREDENTIALS.username);
  await page.locator('#user_pass').fill(CREDENTIALS.password);
  await page.locator('#wp-submit').click();
  await page.waitForLoadState('load');
}

/** WordPress 後台管理員登入（強制確保在 wp-admin）*/
export async function loginAdmin(page: Page): Promise<void> {
  // 直接導向後台；若未登入，WP 會重導向至登入頁
  // commit 只等 HTTP headers，避免 JS 執行或背景請求阻塞 domcontentloaded
  await page.goto('/wp-admin/', { waitUntil: 'commit', timeout: 60_000 });

  // 若被重導向到登入頁，執行登入
  if (page.url().includes('wp-login.php')) {
    await page.locator('#user_login').fill(CREDENTIALS.username);
    await page.locator('#user_pass').fill(CREDENTIALS.password);

    // 用 evaluate 提交表單，避免 Playwright 的 click() 等待導航完成
    await page.evaluate(() => {
      const form = document.querySelector('#loginform') as HTMLFormElement;
      if (form) form.submit();
    });

    // 等待 URL 離開登入頁（waitUntil: 'commit' 只等 URL 改變，不等 load 事件）
    try {
      await page.waitForURL(url => !url.toString().includes('wp-login.php'), {
        timeout: 60000,
        waitUntil: 'commit',
      });
    } catch {
      // 若仍在登入頁代表登入失敗
    }

    // WooCommerce 可能將登入後重導向至前台 my-account，強制回到後台
    if (!page.url().includes('/wp-admin')) {
      await page.goto('/wp-admin/', { waitUntil: 'commit', timeout: 60_000 });
    }
  }
}

/** 確認已登入，否則執行登入 */
export async function ensureLoggedIn(page: Page): Promise<void> {
  // 使用 commit 避免等待外部資源拖慢導覽（登入狀態在初始 HTML 即可判斷）
  await page.goto(URLS.myAccount, { waitUntil: 'commit', timeout: 120_000 });
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 }).catch(() => {});

  const logoutLink = page.locator('a[href*="customer-logout"]').first();
  if (await logoutLink.isVisible({ timeout: 5_000 }).catch(() => false)) {
    return;
  }

  const username = page.locator('#user_login, #username, input[name="username"]').first();
  const password = page.locator('#user_pass, #password, input[name="password"]').first();
  const submit = page.locator('#wp-submit, button[name="login"], .woocommerce-form-login__submit').first();

  if (await username.isVisible({ timeout: 5_000 }).catch(() => false)) {
    await username.fill(CREDENTIALS.username);
    await password.fill(CREDENTIALS.password);

    // 用 evaluate 提交表單，避免 Playwright 的 click() 等待導航完成而 timeout
    await page.evaluate(() => {
      const form = document.querySelector('#loginform, .woocommerce-form-login') as HTMLFormElement;
      if (form) form.submit();
    });

    // 等待導覽離開登入頁
    await page.waitForURL(url =>
      !url.toString().includes('wp-login.php') && !url.toString().includes('my-account'), {
      timeout: 60_000,
      waitUntil: 'commit',
    }).catch(() => {});
  }
}

/** 登出 */
export async function logout(page: Page): Promise<void> {
  await page.goto(URLS.myAccount);
  const logoutLink = page.locator('a[href*="customer-logout"]');
  if (await logoutLink.isVisible().catch(() => false)) {
    await logoutLink.click();
    await page.waitForLoadState('load');
  }
}

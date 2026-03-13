import { Page, expect, FrameLocator } from '@playwright/test';
import { SELECTORS, CARDS } from '../fixtures/test-data';

type CardData = { number: string; expiry: string; cvc: string };

/**
 * 等待所有 SDK iframe 載入完成
 * iframe ID: #ifr_cardno, #ifr_cardexp, #ifr_cardcvc
 * iframe src 指向 sandbox-vendor.payuni.com.tw
 */
export async function waitForIframes(page: Page, timeout = 30_000): Promise<void> {
  await page.waitForSelector(SELECTORS.cardNoIframe, { state: 'attached', timeout });
  await page.waitForSelector(SELECTORS.cardExpIframe, { state: 'attached', timeout });
  await page.waitForSelector(SELECTORS.cardCvcIframe, { state: 'attached', timeout });
  // 額外等待 iframe 內容載入
  await page.waitForTimeout(1000);
}

/** 取得卡號 iframe */
export function getCardNoFrame(page: Page): FrameLocator {
  return page.frameLocator(SELECTORS.cardNoIframe);
}

/** 取得到期日 iframe */
export function getCardExpFrame(page: Page): FrameLocator {
  return page.frameLocator(SELECTORS.cardExpIframe);
}

/** 取得 CVC iframe */
export function getCardCvcFrame(page: Page): FrameLocator {
  return page.frameLocator(SELECTORS.cardCvcIframe);
}

/** 填入新卡片完整資料 */
export async function fillNewCard(page: Page, card?: CardData): Promise<void> {
  const c = card || CARDS.visa;
  await waitForIframes(page);

  // 若已有綁卡，先切回「使用新卡片」模式再填寫卡號與效期
  const newCardRadio = page.locator(SELECTORS.newCardRadio).first();
  if (await newCardRadio.isVisible().catch(() => false)) {
    await newCardRadio.click({ force: true });
    await page.waitForTimeout(500);
  }
  await expect(page.locator(SELECTORS.cardNoContainer)).toBeVisible({ timeout: 10_000 });
  await expect(page.locator(SELECTORS.cardExpContainer)).toBeVisible({ timeout: 10_000 });

  const cardNoInput = getCardNoFrame(page).locator('input');
  await cardNoInput.click();
  await cardNoInput.pressSequentially(c.number, { delay: 30 });

  const cardExpInput = getCardExpFrame(page).locator('input');
  await cardExpInput.click();
  await cardExpInput.pressSequentially(c.expiry, { delay: 30 });

  const cardCvcInput = getCardCvcFrame(page).locator('input');
  await cardCvcInput.click();
  await cardCvcInput.pressSequentially(c.cvc, { delay: 30 });
}

/** 僅填入 CVC（已存卡片使用）*/
export async function fillCvcOnly(page: Page, cvc = '123'): Promise<void> {
  const cvcFrame = page.frameLocator(SELECTORS.cardCvcIframe);
  const cvcInput = cvcFrame.locator('input');
  await cvcInput.click();
  await cvcInput.pressSequentially(cvc, { delay: 30 });
}

/** 檢查 iframe 是否已載入 */
export async function areIframesLoaded(page: Page): Promise<boolean> {
  try {
    const cardNo = await page.$(SELECTORS.cardNoIframe);
    const cardExp = await page.$(SELECTORS.cardExpIframe);
    const cardCvc = await page.$(SELECTORS.cardCvcIframe);
    return !!(cardNo && cardExp && cardCvc);
  } catch {
    return false;
  }
}

/** 部分填寫（測試驗證用）*/
export async function fillCardPartial(
  page: Page,
  fields: { number?: string; expiry?: string; cvc?: string }
): Promise<void> {
  await waitForIframes(page);

  // 若有已綁卡，切到新卡模式，避免僅填 CVC 造成測試語意偏移
  const newCardRadio = page.locator(SELECTORS.newCardRadio).first();
  if (await newCardRadio.isVisible().catch(() => false)) {
    await newCardRadio.click({ force: true });
    await page.waitForTimeout(500);
  }

  if (fields.number !== undefined) {
    await expect(page.locator(SELECTORS.cardNoContainer)).toBeVisible({ timeout: 10_000 });
    const input = getCardNoFrame(page).locator('input');
    await input.click();
    await input.press('ControlOrMeta+A').catch(() => {});
    await input.press('Backspace').catch(() => {});
    await input.pressSequentially(fields.number, { delay: 30 });
  }
  if (fields.expiry !== undefined) {
    await expect(page.locator(SELECTORS.cardExpContainer)).toBeVisible({ timeout: 10_000 });
    const input = getCardExpFrame(page).locator('input');
    await input.click();
    await input.press('ControlOrMeta+A').catch(() => {});
    await input.press('Backspace').catch(() => {});
    await input.pressSequentially(fields.expiry, { delay: 30 });
  } else {
    await expect(page.locator(SELECTORS.cardExpContainer)).toBeVisible({ timeout: 10_000 });
    const input = getCardExpFrame(page).locator('input');
    await input.click();
    await input.press('ControlOrMeta+A').catch(() => {});
    await input.press('Backspace').catch(() => {});
  }
  if (fields.cvc !== undefined) {
    const input = getCardCvcFrame(page).locator('input');
    await input.click();
    await input.press('ControlOrMeta+A').catch(() => {});
    await input.press('Backspace').catch(() => {});
    await input.pressSequentially(fields.cvc, { delay: 30 });
  } else {
    const input = getCardCvcFrame(page).locator('input');
    await input.click();
    await input.press('ControlOrMeta+A').catch(() => {});
    await input.press('Backspace').catch(() => {});
  }
}

import { Page, expect } from '@playwright/test';
import { SELECTORS, CARRIERS } from '../fixtures/test-data';

type CarrierKey = keyof typeof CARRIERS;

/** 選擇載具類型並等待 UI 更新 */
export async function selectCarrierType(page: Page, carrierType: string): Promise<void> {
  const select = page.locator(SELECTORS.carrierTypeSelect);
  await expect(select).toBeVisible({ timeout: 5000 });
  await select.selectOption(carrierType);
  await page.waitForTimeout(500); // 等待對應輸入框顯示
}

/**
 * 填寫載具資訊（根據類型自動填入對應欄位）
 * 欄位 ID 模式: #payuni_carrier_info_{TYPE}
 * 行容器: #payuni_carrier_info_row_{TYPE} (display:none 預設隱藏)
 */
export async function fillCarrierInfo(
  page: Page,
  carrierType: string,
  value: string,
  buyerName?: string
): Promise<void> {
  await selectCarrierType(page, carrierType);

  switch (carrierType) {
    case '3J0002': { // 手機條碼 — maxlength=8, placeholder=/XXXXXXX
      const input = page.locator(SELECTORS.carrierInfoMobile);
      await expect(input).toBeVisible({ timeout: 3000 });
      await input.fill(value);
      break;
    }
    case 'CQ0001': { // 自然人憑證
      const input = page.locator(SELECTORS.carrierInfoNatural);
      await expect(input).toBeVisible({ timeout: 3000 });
      await input.fill(value);
      break;
    }
    case 'Donate': { // 捐贈
      const input = page.locator(SELECTORS.carrierInfoDonate);
      await expect(input).toBeVisible({ timeout: 3000 });
      await input.fill(value);
      break;
    }
    case 'Company': { // 公司統編
      const input = page.locator(SELECTORS.carrierInfoCompany);
      await expect(input).toBeVisible({ timeout: 3000 });
      await input.fill(value);
      if (buyerName) {
        const nameInput = page.locator(SELECTORS.buyerName);
        if (await nameInput.isVisible().catch(() => false)) {
          await nameInput.fill(buyerName);
        }
      }
      break;
    }
    case 'amego': // 會員載具 — 無額外輸入
    case '': // 紙本發票 — 無額外輸入
      break;
  }
}

/** 使用預設載具資料 */
export async function selectCarrierPreset(page: Page, preset: CarrierKey): Promise<void> {
  const carrier = CARRIERS[preset];
  await fillCarrierInfo(
    page,
    carrier.type,
    carrier.value,
    'buyerName' in carrier ? (carrier as typeof CARRIERS.company).buyerName : undefined
  );
}

/** 取得載具下拉選單所有選項文字 */
export async function getCarrierOptions(page: Page): Promise<string[]> {
  const select = page.locator(SELECTORS.carrierTypeSelect);
  await expect(select).toBeVisible();
  return select.locator('option').allTextContents();
}

/** 取得載具下拉選單所有選項 value */
export async function getCarrierOptionValues(page: Page): Promise<string[]> {
  const select = page.locator(SELECTORS.carrierTypeSelect);
  await expect(select).toBeVisible();
  return select.locator('option').evaluateAll(opts =>
    (opts as HTMLOptionElement[]).map(o => o.value)
  );
}

/** 確認載具輸入欄位可見性 */
export async function isCarrierInputVisible(page: Page, carrierType: string): Promise<boolean> {
  const rowSelector = SELECTORS.carrierInfoRow(carrierType);
  try {
    const row = page.locator(rowSelector);
    return await row.isVisible();
  } catch {
    return false;
  }
}

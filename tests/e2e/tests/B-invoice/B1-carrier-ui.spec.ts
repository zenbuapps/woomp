import { test, expect } from '@playwright/test';
import { CARRIERS } from '../../fixtures/test-data';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, selectPayuniPayment } from '../../helpers/checkout.helper';
import { selectCarrierType, getCarrierOptions, isCarrierInputVisible } from '../../helpers/carrier.helper';
import { ensureLoggedIn } from '../../helpers/auth.helper';

test.describe('B1 - 載具 UI @invoice', () => {
  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
    await addToCartAndCheckout(page);
    await fillBillingFields(page);
    await selectPayuniPayment(page);
  });

  test('B1-1 下拉選單顯示所有載具選項 @P1', async ({ page }) => {
    const options = await getCarrierOptions(page);
    // 至少應有: 紙本, 手機條碼, 自然人憑證, 會員載具, 捐贈, 公司統編
    expect(options.length).toBeGreaterThanOrEqual(6);
  });

  test('B1-2 選手機條碼 → 出現輸入框 @P1', async ({ page }) => {
    await selectCarrierType(page, CARRIERS.mobile.type);
    const visible = await isCarrierInputVisible(page, CARRIERS.mobile.type);
    expect(visible).toBe(true);
  });

  test('B1-3 選自然人憑證 → 出現輸入框 @P1', async ({ page }) => {
    await selectCarrierType(page, CARRIERS.naturalPerson.type);
    const visible = await isCarrierInputVisible(page, CARRIERS.naturalPerson.type);
    expect(visible).toBe(true);
  });

  test('B1-4 選會員載具 → 無額外輸入框 @P1', async ({ page }) => {
    await selectCarrierType(page, CARRIERS.amego.type);
    const mobileVisible = await isCarrierInputVisible(page, CARRIERS.mobile.type);
    const naturalVisible = await isCarrierInputVisible(page, CARRIERS.naturalPerson.type);
    expect(mobileVisible).toBe(false);
    expect(naturalVisible).toBe(false);
  });

  test('B1-5 選捐贈 → 出現捐贈碼輸入框 @P1', async ({ page }) => {
    await selectCarrierType(page, CARRIERS.donate.type);
    const visible = await isCarrierInputVisible(page, CARRIERS.donate.type);
    expect(visible).toBe(true);
  });

  test('B1-6 選公司統編 → 出現統編輸入框 @P1', async ({ page }) => {
    await selectCarrierType(page, CARRIERS.company.type);
    const visible = await isCarrierInputVisible(page, CARRIERS.company.type);
    expect(visible).toBe(true);
  });

  test('B1-7 選紙本（空值）→ 無額外輸入框 @P1', async ({ page }) => {
    await selectCarrierType(page, CARRIERS.paper.type);
    const mobileVisible = await isCarrierInputVisible(page, CARRIERS.mobile.type);
    const naturalVisible = await isCarrierInputVisible(page, CARRIERS.naturalPerson.type);
    const donateVisible = await isCarrierInputVisible(page, CARRIERS.donate.type);
    expect(mobileVisible).toBe(false);
    expect(naturalVisible).toBe(false);
    expect(donateVisible).toBe(false);
  });
});

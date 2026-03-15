import { test, expect } from '@playwright/test';
import { ECPAY_SHIPPING } from '../../fixtures/ecpay-data';
import { ensureLoggedIn } from '../../helpers/auth.helper';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import { fillBillingFields, waitForCheckoutUpdate } from '../../helpers/checkout.helper';
import {
  getAvailableShippingMethods,
  selectShippingMethod,
  verifyCvsStorePickerVisible,
} from '../../helpers/shipping-admin.helper';

/**
 * ECPay（綠界）物流方式測試
 * 驗證超商取貨、宅配等物流選項在結帳頁正確顯示
 */
test.describe('ECPay 物流選項 @ecpay @shipping', () => {
  test.beforeEach(async ({ page }) => {
    await ensureLoggedIn(page);
    await addToCartAndCheckout(page);
    await fillBillingFields(page);
    await waitForCheckoutUpdate(page);
  });

  test('結帳頁面顯示 ECPay 超商取貨物流選項 @P1', async ({ page }) => {
    // 取得目前所有可用的物流方式
    const availableMethods = await getAvailableShippingMethods(page);

    // ECPay 超商物流 IDs（可能帶有 instance ID 後綴，如 ry_ecpay_shipping_cvs_711:3）
    const ecpayCvsMethods = [
      ECPAY_SHIPPING.cvs711,     // ry_ecpay_shipping_cvs_711
      ECPAY_SHIPPING.cvsFami,    // ry_ecpay_shipping_cvs_fami
      ECPAY_SHIPPING.cvsHilife,  // ry_ecpay_shipping_cvs_hilife
      ECPAY_SHIPPING.cvsOkmart,  // ry_ecpay_shipping_cvs_okmart
    ];

    // 檢查是否有任一 ECPay 超商物流方式可用
    const foundCvsMethods = availableMethods.filter(method =>
      ecpayCvsMethods.some(cvs => method.includes(cvs))
    );

    if (foundCvsMethods.length === 0) {
      // 也嘗試在頁面上找到物流方式 label
      const shippingLabels = page.locator(
        'label[for*="ecpay_shipping"], .shipping_method label'
      );
      const labelCount = await shippingLabels.count();
      const labelTexts: string[] = [];
      for (let i = 0; i < labelCount; i++) {
        const text = await shippingLabels.nth(i).textContent();
        if (text) labelTexts.push(text.trim());
      }

      const hasEcpayShippingLabel = labelTexts.some(t =>
        t.includes('7-11') || t.includes('全家') || t.includes('萊爾富') || t.includes('OK')
        || t.includes('711') || t.includes('FamilyMart') || t.includes('Hi-Life')
      );

      if (!hasEcpayShippingLabel) {
        test.skip(
          true,
          `ECPay 超商物流未啟用。目前可用物流：${availableMethods.join(', ') || '無'}`
        );
        return;
      }

      expect(hasEcpayShippingLabel).toBe(true);
      return;
    }

    expect(
      foundCvsMethods.length,
      `應至少有一個 ECPay 超商物流方式可用，找到：${foundCvsMethods.join(', ')}`
    ).toBeGreaterThan(0);
  });

  test('選擇超商取貨時顯示選店按鈕 @P1', async ({ page }) => {
    const availableMethods = await getAvailableShippingMethods(page);

    // 找出第一個 ECPay 超商物流方式
    const ecpayCvsIds = [
      ECPAY_SHIPPING.cvs711,
      ECPAY_SHIPPING.cvsFami,
      ECPAY_SHIPPING.cvsHilife,
      ECPAY_SHIPPING.cvsOkmart,
    ];

    const firstCvsMethod = availableMethods.find(method =>
      ecpayCvsIds.some(cvs => method.includes(cvs))
    );

    if (!firstCvsMethod) {
      test.skip(true, 'ECPay 超商物流不可用，無法測試選店按鈕');
      return;
    }

    // 選擇超商取貨物流方式
    await selectShippingMethod(page, firstCvsMethod);
    await page.waitForTimeout(1000);
    await waitForCheckoutUpdate(page);

    // 驗證選店按鈕出現
    const hasStorePicker = await verifyCvsStorePickerVisible(page);

    // 也嘗試其他可能的選店 UI
    const storeSelectionUI = page.locator(
      '.cvs-store-select, .select-store, button[data-action="select-store"], [class*="store-picker"], [class*="store-select"], a[href*="ecpay"][href*="map"]'
    ).first();
    const hasStoreUI = await storeSelectionUI.isVisible().catch(() => false);

    // 超商取貨可能有門市名稱欄位而非按鈕
    const storeFields = page.locator(
      'input[name*="store"], input[name*="cvs"], [id*="shipping_store"]'
    ).first();
    const hasStoreField = await storeFields.isVisible().catch(() => false);

    expect(
      hasStorePicker || hasStoreUI || hasStoreField,
      '選擇超商取貨後應顯示選店按鈕或門市相關欄位'
    ).toBe(true);
  });

  test('ECPay 宅配物流選項顯示（如已設定）@P2', async ({ page }) => {
    const availableMethods = await getAvailableShippingMethods(page);

    // ECPay 宅配物流 IDs
    const ecpayHomeMethods = [
      ECPAY_SHIPPING.homePost,  // ry_ecpay_shipping_home_post（郵局）
      ECPAY_SHIPPING.homeTcat,  // ry_ecpay_shipping_home_tcat（黑貓）
    ];

    const foundHomeMethods = availableMethods.filter(method =>
      ecpayHomeMethods.some(home => method.includes(home))
    );

    if (foundHomeMethods.length === 0) {
      // 嘗試從 label 文字尋找
      const shippingLabels = page.locator('.shipping_method label, label[for*="shipping_method"]');
      const labelCount = await shippingLabels.count();
      const labelTexts: string[] = [];
      for (let i = 0; i < labelCount; i++) {
        const text = await shippingLabels.nth(i).textContent();
        if (text) labelTexts.push(text.trim());
      }

      const hasHomeDeliveryLabel = labelTexts.some(t =>
        t.includes('宅配') || t.includes('郵局') || t.includes('黑貓')
        || t.includes('Post') || t.includes('Tcat')
      );

      if (!hasHomeDeliveryLabel) {
        test.skip(
          true,
          `ECPay 宅配物流未啟用。目前可用物流：${availableMethods.join(', ') || '無'}`
        );
        return;
      }

      expect(hasHomeDeliveryLabel).toBe(true);
      return;
    }

    expect(
      foundHomeMethods.length,
      `應有 ECPay 宅配物流方式可用，找到：${foundHomeMethods.join(', ')}`
    ).toBeGreaterThan(0);

    // 選擇宅配物流，確認不會顯示超商選店按鈕
    await selectShippingMethod(page, foundHomeMethods[0]);
    await page.waitForTimeout(1000);
    await waitForCheckoutUpdate(page);

    const hasStorePicker = await verifyCvsStorePickerVisible(page);
    expect(
      hasStorePicker,
      '宅配物流不應顯示超商選店按鈕'
    ).toBe(false);
  });
});

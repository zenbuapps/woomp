import { test, expect } from '@playwright/test';
import { addProductToCart } from '../../helpers/cart.helper';
import {
  fillBillingFields,
  selectPayuniPayment,
  selectInstallment,
  clickPlaceOrder,
  verifyOrderReceived,
} from '../../helpers/checkout.helper';
import { waitForIframes, fillNewCard } from '../../helpers/iframe.helper';
import { extractOrderIdFromUrl } from '../../helpers/admin.helper';
import { pollOrderStatus, waitAndCheckOrderStatus, getOrderNotes } from '../../helpers/wc-api.helper';
import { CARDS, PRODUCT_INSTALLMENT } from '../../fixtures/test-data';

/**
 * F2. Webhook 輪詢測試（紅燈 E2E）
 *
 * 驗證 PayUni v3 信用卡 3D 流程修正後：
 * - 成功場景：webhook 回傳後訂單狀態變為 processing/completed
 * - 失敗場景：webhook 未回傳，訂單狀態維持 pending
 *
 * 使用 WC REST API 輪詢（不走 Admin UI），60 秒超時。
 */

const WEBHOOK_TIMEOUT = 60_000;
const POLL_INTERVAL = 5_000;
const SUCCESS_STATUSES = ['processing', 'completed'];

test.describe('F2. Webhook 輪詢測試 @webhook', () => {
  test.beforeEach(async ({ page }) => {
    await addProductToCart(page);
    await page.goto(`${process.env.BASE_URL}/checkout/`);
    await page.waitForLoadState('networkidle');
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);
  });

  test('F2-1 @P0 不分期成功 → webhook 回傳 → 訂單 processing/completed @no-installment @success', async ({
    page,
  }) => {
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);

    const orderId = extractOrderIdFromUrl(page.url());

    // 輪詢等待 webhook 回傳，訂單狀態應變為 processing 或 completed
    const finalStatus = await pollOrderStatus(orderId, {
      expectedStatuses: SUCCESS_STATUSES,
      timeout: WEBHOOK_TIMEOUT,
      interval: POLL_INTERVAL,
    });

    expect(SUCCESS_STATUSES).toContain(finalStatus);

    // 驗證 webhook order_note 存在
    const notes = await getOrderNotes(orderId);
    const hasWebhookNote = notes.some((n) => n.note.includes('Webhook'));
    expect(hasWebhookNote).toBeTruthy();
  });

  test('F2-2 @P0 不分期失敗（無效卡號授權被拒）→ 訂單維持 pending @no-installment @failure', async ({ page }) => {
    await fillNewCard(page, CARDS.invalidVisa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);

    const orderId = extractOrderIdFromUrl(page.url());

    // 等滿 60 秒後確認訂單狀態仍為 pending
    await waitAndCheckOrderStatus(orderId, {
      expectedStatus: 'pending',
      waitTime: WEBHOOK_TIMEOUT,
    });

    // 驗證 order_note 包含付款失敗記錄
    const notes = await getOrderNotes(orderId);
    const hasFailureNote = notes.some((n) => n.note.includes('信用卡付款失敗'));
    expect(hasFailureNote).toBeTruthy();
  });

  test('F2-3 @P1 分期成功（Visa 分期卡 + 3 期 + 高金額商品）→ webhook 回傳 → 訂單 processing/completed @installment @success', async ({
    page,
  }) => {
    // 分期需要高金額商品（NT$1,500），beforeEach 加的低價商品不夠
    // 額外加入高金額商品（購物車會同時有兩個商品，總額足夠分期）
    await addProductToCart(page, PRODUCT_INSTALLMENT.addToCartUrl);
    await page.goto(`${process.env.BASE_URL}/checkout/`);
    await page.waitForLoadState('networkidle');
    await fillBillingFields(page);
    await selectPayuniPayment(page);
    await waitForIframes(page);

    await selectInstallment(page, 3);
    await fillNewCard(page, CARDS.visaInstallment);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);

    const orderId = extractOrderIdFromUrl(page.url());

    // 輪詢等待 webhook 回傳
    const finalStatus = await pollOrderStatus(orderId, {
      expectedStatuses: SUCCESS_STATUSES,
      timeout: WEBHOOK_TIMEOUT,
      interval: POLL_INTERVAL,
    });

    expect(SUCCESS_STATUSES).toContain(finalStatus);

    // 驗證 webhook order_note 存在
    const notes = await getOrderNotes(orderId);
    const hasWebhookNote = notes.some((n) => n.note.includes('Webhook'));
    expect(hasWebhookNote).toBeTruthy();
  });

  test('F2-4 @P1 分期失敗（一次付清卡 + 3 期）→ 訂單維持 pending @installment @failure', async ({ page }) => {
    await selectInstallment(page, 3);
    await fillNewCard(page, CARDS.visa);
    await clickPlaceOrder(page);
    await verifyOrderReceived(page);

    const orderId = extractOrderIdFromUrl(page.url());

    // 等滿 60 秒後確認訂單狀態仍為 pending
    await waitAndCheckOrderStatus(orderId, {
      expectedStatus: 'pending',
      waitTime: WEBHOOK_TIMEOUT,
    });

    // 驗證 order_note 包含付款失敗記錄
    const notes = await getOrderNotes(orderId);
    const hasFailureNote = notes.some((n) => n.note.includes('信用卡付款失敗'));
    expect(hasFailureNote).toBeTruthy();
  });
});

import { test, expect } from '@playwright/test';
import { addToCartAndCheckout } from '../../helpers/cart.helper';
import {
  fillBillingFields,
  selectPayuniPayment,
  selectInstallment,
  clickPlaceOrder,
  verifyOrderReceived,
  waitForCheckoutUpdate,
} from '../../helpers/checkout.helper';
import { waitForIframes, fillNewCard } from '../../helpers/iframe.helper';
import { selectCarrierPreset } from '../../helpers/carrier.helper';
import { extractOrderIdFromUrl } from '../../helpers/admin.helper';
import {
  pollOrderStatus,
  waitAndCheckOrderStatus,
  getOrderNotes,
} from '../../helpers/wc-api.helper';
import { CARDS, PRODUCT_INSTALLMENT } from '../../fixtures/test-data';

/**
 * PayUni V3 付款矩陣（8 案例）
 *
 * 維度：分期/不分期 × 載具/無載具 × 成功/失敗
 *
 * 設計決策：
 * - 全部使用 PRODUCT_INSTALLMENT（虛擬商品 $1500），避免實體商品的運費計算 AJAX 干擾 PayUni SDK iframe
 * - 成功案例：一次付清用 visa，分期用 visaInstallment
 * - 失敗案例：統一使用「非分期卡 + 分期請求」觸發處理層失敗（非 SDK 前端拒絕），確保訂單會建立
 *   - M5/M6 用 JCB 非分期卡，M7/M8 用 Visa 非分期卡（覆蓋兩種卡品牌）
 *
 * 驗證策略：
 * - 成功：WC REST API 輪詢 60s，期望 processing/completed + order note 含 "Webhook"
 * - 失敗：等滿 60s 後確認仍為 pending + order note 含 "信用卡付款失敗"
 */

const WEBHOOK_TIMEOUT = 60_000;
const POLL_INTERVAL = 5_000;
const SUCCESS_STATUSES = ['processing', 'completed'];

// ── 共用結帳流程 ────────────────────────────────

interface CheckoutOptions {
  productUrl: string;
  card: { number: string; expiry: string; cvc: string };
  installment?: number;
  withCarrier?: boolean;
}

async function checkout(
  page: Parameters<typeof fillBillingFields>[0],
  opts: CheckoutOptions,
): Promise<string> {
  await addToCartAndCheckout(page, opts.productUrl);
  await fillBillingFields(page);
  await waitForCheckoutUpdate(page);
  await selectPayuniPayment(page);

  if (opts.installment) {
    await selectInstallment(page, opts.installment);
  }

  if (opts.withCarrier) {
    await selectCarrierPreset(page, 'mobile');
  }

  await fillNewCard(page, opts.card);
  await clickPlaceOrder(page);
  await verifyOrderReceived(page);

  return extractOrderIdFromUrl(page.url());
}

// ── 共用驗證 ────────────────────────────────────

async function verifySuccess(orderId: string): Promise<void> {
  const finalStatus = await pollOrderStatus(orderId, {
    expectedStatuses: SUCCESS_STATUSES,
    timeout: WEBHOOK_TIMEOUT,
    interval: POLL_INTERVAL,
  });
  expect(SUCCESS_STATUSES).toContain(finalStatus);

  const notes = await getOrderNotes(orderId);
  const hasWebhookNote = notes.some((n) => n.note.includes('Webhook'));
  expect(hasWebhookNote).toBeTruthy();
}

async function verifyFailure(orderId: string): Promise<void> {
  await waitAndCheckOrderStatus(orderId, {
    expectedStatus: 'pending',
    waitTime: WEBHOOK_TIMEOUT,
  });

  const notes = await getOrderNotes(orderId);
  const hasFailureNote = notes.some((n) => n.note.includes('信用卡付款失敗'));
  expect(hasFailureNote).toBeTruthy();
}

// ── 測試矩陣 ────────────────────────────────────

test.describe('PayUni V3 付款矩陣 @payuni @P0', () => {

  // ── 成功案例 ──────────────────────────────────

  test('M1 @P0 一次付清+無載具 → 訂單成功 @success @no-installment', async ({ page }) => {
    const orderId = await checkout(page, {
      productUrl: PRODUCT_INSTALLMENT.addToCartUrl,
      card: CARDS.visa,
    });
    await verifySuccess(orderId);
  });

  test('M2 @P0 一次付清+有載具 → 訂單成功 @success @no-installment @carrier', async ({ page }) => {
    const orderId = await checkout(page, {
      productUrl: PRODUCT_INSTALLMENT.addToCartUrl,
      card: CARDS.visa,
      withCarrier: true,
    });
    await verifySuccess(orderId);
  });

  test('M3 @P0 分期+無載具 → 訂單成功 @success @installment', async ({ page }) => {
    const orderId = await checkout(page, {
      productUrl: PRODUCT_INSTALLMENT.addToCartUrl,
      card: CARDS.visaInstallment,
      installment: 3,
    });
    await verifySuccess(orderId);
  });

  test('M4 @P0 分期+有載具 → 訂單成功 @success @installment @carrier', async ({ page }) => {
    const orderId = await checkout(page, {
      productUrl: PRODUCT_INSTALLMENT.addToCartUrl,
      card: CARDS.visaInstallment,
      installment: 3,
      withCarrier: true,
    });
    await verifySuccess(orderId);
  });

  // ── 失敗案例 ──────────────────────────────────

  test('M5 @P0 JCB非分期卡+分期請求+無載具 → 付款失敗，訂單維持 pending @failure @jcb', async ({ page }) => {
    const orderId = await checkout(page, {
      productUrl: PRODUCT_INSTALLMENT.addToCartUrl,
      card: CARDS.jcb,
      installment: 3,
    });
    await verifyFailure(orderId);
  });

  test('M6 @P0 JCB非分期卡+分期請求+有載具 → 付款失敗，訂單維持 pending @failure @jcb @carrier', async ({ page }) => {
    const orderId = await checkout(page, {
      productUrl: PRODUCT_INSTALLMENT.addToCartUrl,
      card: CARDS.jcb,
      installment: 3,
      withCarrier: true,
    });
    await verifyFailure(orderId);
  });

  test('M7 @P0 分期+無載具 → 付款失敗（非分期卡），訂單維持 pending @failure @installment', async ({ page }) => {
    const orderId = await checkout(page, {
      productUrl: PRODUCT_INSTALLMENT.addToCartUrl,
      card: CARDS.visa,
      installment: 3,
    });
    await verifyFailure(orderId);
  });

  test('M8 @P0 分期+有載具 → 付款失敗（非分期卡），訂單維持 pending @failure @installment @carrier', async ({ page }) => {
    const orderId = await checkout(page, {
      productUrl: PRODUCT_INSTALLMENT.addToCartUrl,
      card: CARDS.visa,
      installment: 3,
      withCarrier: true,
    });
    await verifyFailure(orderId);
  });
});

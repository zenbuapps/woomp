import { APIRequestContext, request } from '@playwright/test';

/**
 * WooCommerce REST API v3 Client
 * 透過 Playwright APIRequestContext 呼叫 WC REST API
 *
 * API Keys 從環境變數或 test state 讀取：
 * - WC_API_KEY (Consumer Key)
 * - WC_API_SECRET (Consumer Secret)
 */

let cachedContext: APIRequestContext | null = null;

/** 取得或建立 API request context */
async function getApiContext(): Promise<APIRequestContext> {
  if (cachedContext) return cachedContext;

  const baseURL = process.env.TEST_SITE_URL || process.env.BASE_URL || 'https://local-turbo.powerhouse.tw';
  const key = process.env.WC_API_KEY || '';
  const secret = process.env.WC_API_SECRET || '';

  if (!key || !secret) {
    throw new Error(
      'WC REST API credentials not configured. ' +
      'Run 00-setup/enable-all-modules.spec.ts first, or set WC_API_KEY / WC_API_SECRET in .env'
    );
  }

  cachedContext = await request.newContext({
    baseURL,
    extraHTTPHeaders: {
      Authorization: `Basic ${Buffer.from(`${key}:${secret}`).toString('base64')}`,
    },
  });

  return cachedContext;
}

/** 列出所有已註冊的 WC 付款閘道 */
export async function listPaymentGateways(): Promise<WcGateway[]> {
  const ctx = await getApiContext();
  const resp = await ctx.get('/wp-json/wc/v3/payment_gateways');
  if (!resp.ok()) {
    throw new Error(`Failed to list payment gateways: ${resp.status()} ${resp.statusText()}`);
  }
  return resp.json();
}

/** 取得單一付款閘道資訊 */
export async function getPaymentGateway(gatewayId: string): Promise<WcGateway> {
  const ctx = await getApiContext();
  const resp = await ctx.get(`/wp-json/wc/v3/payment_gateways/${gatewayId}`);
  if (!resp.ok()) {
    throw new Error(`Failed to get gateway ${gatewayId}: ${resp.status()}`);
  }
  return resp.json();
}

/** 取得訂單完整資料 */
export async function getOrder(orderId: string | number): Promise<WcOrder> {
  const ctx = await getApiContext();
  const resp = await ctx.get(`/wp-json/wc/v3/orders/${orderId}`);
  if (!resp.ok()) {
    throw new Error(`Failed to get order ${orderId}: ${resp.status()}`);
  }
  return resp.json();
}

/** 取得訂單備註 */
export async function getOrderNotes(orderId: string | number): Promise<WcOrderNote[]> {
  const ctx = await getApiContext();
  const resp = await ctx.get(`/wp-json/wc/v3/orders/${orderId}/notes`);
  if (!resp.ok()) {
    throw new Error(`Failed to get order notes for ${orderId}: ${resp.status()}`);
  }
  return resp.json();
}

/** 取得訂單 meta 中的特定值 */
export async function getOrderMeta(orderId: string | number, metaKey: string): Promise<string | undefined> {
  const order = await getOrder(orderId);
  const meta = order.meta_data?.find((m: { key: string }) => m.key === metaKey);
  return meta?.value as string | undefined;
}

/** 列出所有運送方式 */
export async function listShippingMethods(): Promise<WcShippingMethod[]> {
  const ctx = await getApiContext();
  const resp = await ctx.get('/wp-json/wc/v3/shipping_methods');
  if (!resp.ok()) {
    throw new Error(`Failed to list shipping methods: ${resp.status()}`);
  }
  return resp.json();
}

/** 取得系統狀態 */
export async function getSystemStatus(): Promise<Record<string, unknown>> {
  const ctx = await getApiContext();
  const resp = await ctx.get('/wp-json/wc/v3/system_status');
  if (!resp.ok()) {
    throw new Error(`Failed to get system status: ${resp.status()}`);
  }
  return resp.json();
}

/** 取得 WC Settings 中的特定選項 */
export async function getWcSetting(group: string, id: string): Promise<WcSetting> {
  const ctx = await getApiContext();
  const resp = await ctx.get(`/wp-json/wc/v3/settings/${group}/${id}`);
  if (!resp.ok()) {
    throw new Error(`Failed to get setting ${group}/${id}: ${resp.status()}`);
  }
  return resp.json();
}

/** 清除快取的 API context */
export async function disposeApiContext(): Promise<void> {
  if (cachedContext) {
    await cachedContext.dispose();
    cachedContext = null;
  }
}

// ── Type definitions ──

export interface WcGateway {
  id: string;
  title: string;
  description: string;
  order: number;
  enabled: boolean;
  method_title: string;
  method_description: string;
  method_supports: string[];
  settings: Record<string, WcSettingField>;
}

export interface WcSettingField {
  id: string;
  label: string;
  description: string;
  type: string;
  value: string;
  default: string;
  tip: string;
  placeholder: string;
  options?: Record<string, string>;
}

export interface WcOrder {
  id: number;
  status: string;
  payment_method: string;
  payment_method_title: string;
  total: string;
  meta_data: Array<{ id: number; key: string; value: unknown }>;
  billing: Record<string, string>;
  shipping: Record<string, string>;
  line_items: Array<Record<string, unknown>>;
}

export interface WcOrderNote {
  id: number;
  author: string;
  date_created: string;
  note: string;
  customer_note: boolean;
}

export interface WcShippingMethod {
  id: string;
  title: string;
  description: string;
}

export interface WcSetting {
  id: string;
  label: string;
  description: string;
  type: string;
  value: string;
  default: string;
  options?: Record<string, string>;
}

// ── Polling utilities ──

export interface PollOrderStatusOptions {
  /** 預期的目標狀態列表，匹配任一即回傳 */
  expectedStatuses: string[];
  /** 超時時間（毫秒），預設 60_000 */
  timeout?: number;
  /** 輪詢間隔（毫秒），預設 5_000 */
  interval?: number;
}

/**
 * 輪詢訂單狀態直到匹配目標狀態之一，或超時拋錯。
 * 成功場景用：等待 webhook 回傳後訂單狀態變為 processing/completed。
 * 單次 API 查詢失敗會靜默跳過（網路抖動容錯），繼續下一輪。
 */
export async function pollOrderStatus(
  orderId: string | number,
  options: PollOrderStatusOptions,
): Promise<string> {
  const timeout = options.timeout ?? 60_000;
  const interval = options.interval ?? 5_000;
  const start = Date.now();
  let lastStatus = 'unknown';
  let polls = 0;

  while (Date.now() - start < timeout) {
    try {
      const order = await getOrder(orderId);
      lastStatus = order.status;
      polls++;

      if (options.expectedStatuses.includes(lastStatus)) {
        return lastStatus;
      }
    } catch {
      // 單次查詢失敗（網路抖動），靜默跳過
    }

    await new Promise((r) => setTimeout(r, interval));
  }

  throw new Error(
    `pollOrderStatus 超時：訂單 #${orderId} 在 ${Math.round(timeout / 1000)} 秒內` +
    `未變為 [${options.expectedStatuses.join(', ')}]。` +
    `最後狀態：${lastStatus}，已輪詢 ${polls} 次。`,
  );
}

export interface WaitCheckOptions {
  /** 預期的訂單狀態 */
  expectedStatus: string;
  /** 等待時間（毫秒），預設 60_000 */
  waitTime?: number;
}

/**
 * 等待指定時間後檢查訂單狀態是否仍為預期值。
 * 失敗場景用：等滿超時後確認訂單仍為 pending（webhook 未到達）。
 */
export async function waitAndCheckOrderStatus(
  orderId: string | number,
  options: WaitCheckOptions,
): Promise<WcOrder> {
  const waitTime = options.waitTime ?? 60_000;

  await new Promise((r) => setTimeout(r, waitTime));

  const order = await getOrder(orderId);

  if (order.status !== options.expectedStatus) {
    throw new Error(
      `waitAndCheckOrderStatus 失敗：訂單 #${orderId} 等待 ${Math.round(waitTime / 1000)} 秒後，` +
      `狀態為 ${order.status}，預期為 ${options.expectedStatus}。`,
    );
  }

  return order;
}

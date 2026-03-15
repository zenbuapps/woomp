import { test, expect } from '@playwright/test';
import { listPaymentGateways, getPaymentGateway } from '../../helpers/wc-api.helper';
import {
  ECPAY_GATEWAYS,
  ECPAY_INSTALLMENT_GATEWAYS,
} from '../../fixtures/ecpay-data';
import {
  PAYNOW_GATEWAYS,
  OTHER_GATEWAYS,
  NEWEBPAY_GATEWAYS,
  SMILEPAY_GATEWAYS,
} from '../../fixtures/paynow-data';

/**
 * 金流閘道註冊驗證
 * 透過 WC REST API 確認各金流閘道已正確註冊
 * 需要先完成 00-setup 建立 API Key
 */

// 檢查 API Key 是否可用
const hasApiCredentials = !!(process.env.WC_API_KEY && process.env.WC_API_SECRET);

test.describe('金流閘道註冊 @gateway @core', () => {
  // 若缺少 API Key 則跳過整個測試群組
  test.skip(!hasApiCredentials, '需要 WC_API_KEY / WC_API_SECRET 環境變數。請先執行 00-setup。');

  let allGatewayIds: string[] = [];

  test.beforeAll(async () => {
    if (!hasApiCredentials) return;
    try {
      const gateways = await listPaymentGateways();
      allGatewayIds = gateways.map(g => g.id);
    } catch {
      // API 呼叫失敗，測試中再處理
    }
  });

  test('所有 ECPay 金流閘道已註冊 @P0', async () => {
    expect(allGatewayIds.length, '應能取得閘道列表').toBeGreaterThan(0);

    // 驗證所有 ECPay 基礎閘道
    const ecpayIds = Object.values(ECPAY_GATEWAYS);
    for (const gatewayId of ecpayIds) {
      expect(
        allGatewayIds,
        `ECPay 閘道 ${gatewayId} 應已註冊`,
      ).toContain(gatewayId);
    }

    // 驗證 ECPay 獨立分期閘道
    const installmentIds = Object.values(ECPAY_INSTALLMENT_GATEWAYS);
    for (const gatewayId of installmentIds) {
      expect(
        allGatewayIds,
        `ECPay 分期閘道 ${gatewayId} 應已註冊`,
      ).toContain(gatewayId);
    }
  });

  test('PayUni 金流閘道已註冊 @P0', async () => {
    expect(allGatewayIds.length, '應能取得閘道列表').toBeGreaterThan(0);

    // PayUni 閘道 ID pattern: payuni-*-v3 或 payuni-*
    const payuniGateways = allGatewayIds.filter(id => id.startsWith('payuni'));
    expect(
      payuniGateways.length,
      '至少應有一個 PayUni 閘道註冊',
    ).toBeGreaterThan(0);

    // 驗證信用卡閘道（最核心的閘道）
    const hasCreditGateway = allGatewayIds.some(
      id => id.includes('payuni') && id.includes('credit'),
    );
    expect(hasCreditGateway, 'PayUni 信用卡閘道應已註冊').toBe(true);
  });

  test('PayNow 金流閘道已註冊 @P0', async () => {
    expect(allGatewayIds.length, '應能取得閘道列表').toBeGreaterThan(0);

    const paynowIds = Object.values(PAYNOW_GATEWAYS);
    for (const gatewayId of paynowIds) {
      expect(
        allGatewayIds,
        `PayNow 閘道 ${gatewayId} 應已註冊`,
      ).toContain(gatewayId);
    }
  });

  test('NewebPay 金流閘道已註冊 @P0', async () => {
    expect(allGatewayIds.length, '應能取得閘道列表').toBeGreaterThan(0);

    const newebpayIds = Object.values(NEWEBPAY_GATEWAYS);
    for (const gatewayId of newebpayIds) {
      expect(
        allGatewayIds,
        `NewebPay 閘道 ${gatewayId} 應已註冊`,
      ).toContain(gatewayId);
    }
  });

  test('SmilePay 金流閘道已註冊 @P1', async () => {
    expect(allGatewayIds.length, '應能取得閘道列表').toBeGreaterThan(0);

    const smilepayIds = Object.values(SMILEPAY_GATEWAYS);
    for (const gatewayId of smilepayIds) {
      expect(
        allGatewayIds,
        `SmilePay 閘道 ${gatewayId} 應已註冊`,
      ).toContain(gatewayId);
    }
  });

  test('LINE Pay 和 PChomePay 閘道已註冊 @P1', async () => {
    expect(allGatewayIds.length, '應能取得閘道列表').toBeGreaterThan(0);

    // LINE Pay
    expect(
      allGatewayIds,
      `LINE Pay 閘道 ${OTHER_GATEWAYS.linepay} 應已註冊`,
    ).toContain(OTHER_GATEWAYS.linepay);

    // PChomePay
    expect(
      allGatewayIds,
      `PChomePay 閘道 ${OTHER_GATEWAYS.pchomepay} 應已註冊`,
    ).toContain(OTHER_GATEWAYS.pchomepay);
  });
});

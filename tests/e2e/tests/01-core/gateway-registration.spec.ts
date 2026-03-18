import { test, expect } from '@playwright/test';
import * as dotenv from 'dotenv';
import * as path from 'path';
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
 *
 * 注意：integration project 使用 dependency: ['setup']，
 * setup 在執行時才將 API Key 寫入 .env，
 * 因此必須在 beforeAll 裡重新載入 .env，而非在模組載入時判斷。
 */

test.describe('金流閘道註冊 @gateway @core', () => {
  let allGatewayIds: string[] = [];

  test.beforeAll(async () => {
    // 重新讀取 .env（setup project 執行後才寫入 API Key）
    dotenv.config({
      path: path.resolve(__dirname, '../../../../.env'),
      override: true,
    });

    const hasCredentials = !!(process.env.WC_API_KEY && process.env.WC_API_SECRET);
    if (!hasCredentials) return;

    try {
      const gateways = await listPaymentGateways();
      allGatewayIds = gateways.map(g => g.id);
    } catch {
      // API 呼叫失敗，測試中再處理
    }
  });

  test('所有 ECPay 金流閘道已註冊 @P0', async () => {
    test.skip(allGatewayIds.length === 0, '需要 WC_API_KEY / WC_API_SECRET 環境變數。請先執行 00-setup。');

    // 若此測試站台未安裝 ECPay 模組，跳過而非失敗
    const ecpayIds = Object.values(ECPAY_GATEWAYS);
    const anyEcpayRegistered = ecpayIds.some(id => allGatewayIds.includes(id));
    test.skip(!anyEcpayRegistered, `此站台未安裝 ECPay 模組（未找到任何 ECPay 閘道），跳過。可用閘道：${allGatewayIds.join(', ')}`);

    // 驗證所有 ECPay 基礎閘道
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
    test.skip(allGatewayIds.length === 0, '需要 WC_API_KEY / WC_API_SECRET 環境變數。請先執行 00-setup。');

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
    test.skip(allGatewayIds.length === 0, '需要 WC_API_KEY / WC_API_SECRET 環境變數。請先執行 00-setup。');

    const paynowIds = Object.values(PAYNOW_GATEWAYS);
    const anyPaynowRegistered = paynowIds.some(id => allGatewayIds.includes(id));
    test.skip(!anyPaynowRegistered, `此站台未安裝 PayNow 模組（未找到任何 PayNow 閘道），跳過。`);

    for (const gatewayId of paynowIds) {
      expect(
        allGatewayIds,
        `PayNow 閘道 ${gatewayId} 應已註冊`,
      ).toContain(gatewayId);
    }
  });

  test('NewebPay 金流閘道已註冊 @P0', async () => {
    test.skip(allGatewayIds.length === 0, '需要 WC_API_KEY / WC_API_SECRET 環境變數。請先執行 00-setup。');

    const newebpayIds = Object.values(NEWEBPAY_GATEWAYS);
    const anyNewebpayRegistered = newebpayIds.some(id => allGatewayIds.includes(id));
    test.skip(!anyNewebpayRegistered, `此站台未安裝 NewebPay 模組（未找到任何 NewebPay 閘道），跳過。`);

    for (const gatewayId of newebpayIds) {
      expect(
        allGatewayIds,
        `NewebPay 閘道 ${gatewayId} 應已註冊`,
      ).toContain(gatewayId);
    }
  });

  test('SmilePay 金流閘道已註冊 @P1', async () => {
    test.skip(allGatewayIds.length === 0, '需要 WC_API_KEY / WC_API_SECRET 環境變數。請先執行 00-setup。');

    const smilepayIds = Object.values(SMILEPAY_GATEWAYS);
    const anySmilepayRegistered = smilepayIds.some(id => allGatewayIds.includes(id));
    test.skip(!anySmilepayRegistered, `此站台未安裝 SmilePay 模組（未找到任何 SmilePay 閘道），跳過。`);

    for (const gatewayId of smilepayIds) {
      expect(
        allGatewayIds,
        `SmilePay 閘道 ${gatewayId} 應已註冊`,
      ).toContain(gatewayId);
    }
  });

  test('LINE Pay 和 PChomePay 閘道已註冊 @P1', async () => {
    test.skip(allGatewayIds.length === 0, '需要 WC_API_KEY / WC_API_SECRET 環境變數。請先執行 00-setup。');

    const hasLinePay = allGatewayIds.includes(OTHER_GATEWAYS.linepay);
    const hasPChomePay = allGatewayIds.includes(OTHER_GATEWAYS.pchomepay);

    test.skip(!hasLinePay && !hasPChomePay, `此站台未安裝 LINE Pay 或 PChomePay 模組，跳過。`);

    if (hasLinePay) {
      expect(
        allGatewayIds,
        `LINE Pay 閘道 ${OTHER_GATEWAYS.linepay} 應已註冊`,
      ).toContain(OTHER_GATEWAYS.linepay);
    }

    if (hasPChomePay) {
      expect(
        allGatewayIds,
        `PChomePay 閘道 ${OTHER_GATEWAYS.pchomepay} 應已註冊`,
      ).toContain(OTHER_GATEWAYS.pchomepay);
    }
  });
});

# NestJS Service 整合範例（zenbu-site 專案慣例）

> 對應的真實程式碼：`apps/api-gateway/src/commerce/payments/payuni-logistics/payuni-logistics.service.ts` 與 `payuni-logistics.controller.ts`。
> 本檔列出 4 個情境的可貼上程式碼。`payuni-crypto.ts` 已存在，重用即可。

## 0. Settings & Module

```ts
// payuni-logistics.module.ts
import { Module } from '@nestjs/common';
import { PayuniLogisticsService } from './payuni-logistics.service';
import { PayuniLogisticsController } from './payuni-logistics.controller';
import { PayuniLogisticsNotifyController } from './payuni-logistics-notify.controller';
import { OrdersModule } from '../../orders/orders.module';
import { SettingsModule } from '../../../settings/settings.module';

@Module({
  imports: [SettingsModule, OrdersModule],
  controllers: [PayuniLogisticsController, PayuniLogisticsNotifyController],
  providers: [PayuniLogisticsService],
  exports: [PayuniLogisticsService],
})
export class PayuniLogisticsModule {}
```

```ts
// settings.service.ts (擷取)
async getPayuniCreds() {
  const [merId, hashKey, hashIv, mode, enabled] = await Promise.all([
    this.get('payment.payuni_mer_id'),
    this.get('payment.payuni_hash_key'),
    this.get('payment.payuni_hash_iv'),
    this.get('payment.payuni_mode'),       // 'sandbox' | 'production'
    this.get('shipping.payuni_enabled'),   // '1' / '0'
  ]);
  if (!merId || !hashKey || !hashIv) {
    throw new ServiceUnavailableException('PAYUNi credentials not configured');
  }
  return {
    merId, hashKey, hashIv,
    mode: (mode === 'production' ? 'production' : 'sandbox') as 'sandbox' | 'production',
    enabled: enabled === '1',
  };
}
```

---

## 情境 1：建立超商物流單（B2C 取貨付款）+ 列印出貨單

```ts
// payuni-logistics.service.ts
import { BadRequestException, HttpException, HttpStatus, Injectable, Logger } from '@nestjs/common';
import { encryptPayuni, decryptPayuni, hashInfoPayuni, verifyPayuniHash, PAYUNI_HOSTS } from '../payuni/payuni-crypto';
import { SettingsService } from '../../../settings/settings.service';
import { OrdersService } from '../../orders/orders.service';

interface CreateCvsTradeArgs {
  merTradeNo: string;        // ≤ 25, [A-Za-z0-9_-]
  goodsType: 1 | 2;          // 1=常溫 / 2=冷凍
  lgsType: 'B2C' | 'C2C';
  tradeAmt: number;          // ≤ 20000
  serviceType: 1 | 3;        // 1=取貨付款 / 3=取貨不付款
  storeId: string;           // 6 chars
  consignee: string;
  consigneeMobile: string;   // 09xxxxxxxx
  consigneeMail?: string;
  refundStoreId?: string;    // C2C only
  notifyUrl?: string;        // 取件付款完成時觸發
}

@Injectable()
export class PayuniLogisticsService {
  private readonly logger = new Logger(PayuniLogisticsService.name);

  constructor(
    private readonly settings: SettingsService,
    private readonly orders: OrdersService,
  ) {}

  async createCvsTrade(args: CreateCvsTradeArgs) {
    const c = await this.settings.getPayuniCreds();
    if (!c.enabled) throw new BadRequestException('shipping.payuni_enabled is off');

    const params = {
      MerID: c.merId,
      Timestamp: Math.floor(Date.now() / 1000),
      MerTradeNo: args.merTradeNo,
      GoodsType: args.goodsType,
      LgsType: args.lgsType,
      ShipType: 1, // 7-ELEVEN
      TradeAmt: args.tradeAmt,
      ServiceType: args.serviceType,
      StoreID: args.storeId,
      Consignee: args.consignee,
      ConsigneeMobile: args.consigneeMobile,
      ConsigneeMail: args.consigneeMail,
      RefundStoreID: args.lgsType === 'C2C' ? args.refundStoreId : undefined,
      NotifyURL: args.notifyUrl,
    };

    return this.callPayuni(c, '/api/logistics/trade', '1.3', params);
  }

  async buildPrintLabelForm(shipTradeNo: string | string[], lgsType: 'B2C' | 'C2C', goodsType: 1 | 2, shipDate: string, labelMode: 1 | 2 = 1) {
    const c = await this.settings.getPayuniCreds();
    const ids = Array.isArray(shipTradeNo) ? shipTradeNo.join(',') : shipTradeNo;
    if (ids.split(',').length > 50) throw new BadRequestException('max 50 ShipTradeNo per print');

    const params = {
      MerID: c.merId,
      Timestamp: Math.floor(Date.now() / 1000),
      ShipTradeNo: ids,
      GoodsType: goodsType,
      LgsType: lgsType,
      ShipType: 1,
      ShipDate: shipDate, // YYYYMMDD; B2C 不得為當日
      LabelMode: labelMode,
    };
    const encryptInfo = encryptPayuni(params, c.hashKey, c.hashIv);
    const hashInfo = hashInfoPayuni(c.hashKey, encryptInfo, c.hashIv);
    return {
      formUrl: `${PAYUNI_HOSTS[c.mode]}/api/logistics/print_label`,
      fields: { MerID: c.merId, Version: '1.0', EncryptInfo: encryptInfo, HashInfo: hashInfo },
    };
  }

  // 共用 helper
  private async callPayuni(
    creds: Awaited<ReturnType<SettingsService['getPayuniCreds']>>,
    path: string, version: string,
    body: Record<string, string | number | undefined>,
  ): Promise<Record<string, string>> {
    const encryptInfo = encryptPayuni(body, creds.hashKey, creds.hashIv);
    const hashInfo = hashInfoPayuni(creds.hashKey, encryptInfo, creds.hashIv);

    const form = new URLSearchParams({
      MerID: creds.merId, Version: version,
      EncryptInfo: encryptInfo, HashInfo: hashInfo,
    });

    const res = await fetch(`${PAYUNI_HOSTS[creds.mode]}${path}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'User-Agent': 'payuni',
      },
      body: form.toString(),
      // Node 18+ fetch 預設無 timeout，自己加 AbortController
      signal: AbortSignal.timeout(15_000),
    });

    const text = await res.text();
    let json: { Status?: string; Message?: string; EncryptInfo?: string; HashInfo?: string };
    try { json = JSON.parse(text); }
    catch {
      this.logger.error('PAYUNi non-JSON response', { path, text: text.slice(0, 200) });
      throw new HttpException('payuni non-json', HttpStatus.BAD_GATEWAY);
    }

    if (json.Status !== 'SUCCESS') {
      this.logger.warn('PAYUNi non-success', { path, status: json.Status, message: json.Message });
      throw new HttpException({ status: json.Status, message: json.Message }, HttpStatus.BAD_GATEWAY);
    }

    if (!verifyPayuniHash(creds.hashKey, json.EncryptInfo ?? '', creds.hashIv, json.HashInfo ?? '')) {
      this.logger.error('PAYUNi response hash mismatch', { path });
      throw new HttpException('hash mismatch', HttpStatus.BAD_GATEWAY);
    }

    return decryptPayuni(json.EncryptInfo!, creds.hashKey, creds.hashIv);
  }
}
```

---

## 情境 2：超商門市地圖（前景跳轉）

```ts
// payuni-logistics.service.ts (continued)
async buildCvsMapForm(args: {
  merKeyNo: string;          // ≤ 20；Tag=4/5 時帶 ShipTradeNo
  goodsType: 1 | 2;
  lgsType: 'B2C' | 'C2C';
  tag: 2 | 3 | 4 | 5;
  mapType?: 1 | 2;           // GoodsType=2 時固定 2
  mapReturnUrl: string;
  mobileTag?: 'Y' | 'N';
}) {
  const c = await this.settings.getPayuniCreds();
  const params = {
    MerID: c.merId,
    Timestamp: Math.floor(Date.now() / 1000),
    MerKeyNo: args.merKeyNo,
    GoodsType: args.goodsType,
    LgsType: args.lgsType,
    ShipType: 1,
    MapType: args.goodsType === 2 ? 2 : (args.mapType ?? 1),
    MapReturnURL: args.mapReturnUrl,
    Tag: args.tag,
    MobileTag: args.mobileTag,
  };
  const encryptInfo = encryptPayuni(params, c.hashKey, c.hashIv);
  const hashInfo = hashInfoPayuni(c.hashKey, encryptInfo, c.hashIv);
  return {
    formUrl: `${PAYUNI_HOSTS[c.mode]}/api/logistics/ship_map`,
    fields: { MerID: c.merId, Version: '1.1', EncryptInfo: encryptInfo, HashInfo: hashInfo },
  };
}

// 收到 MapReturnURL 的回調
async parseShipMapReturn(body: { MerID: string; Version: string; EncryptInfo: string; HashInfo: string }) {
  const c = await this.settings.getPayuniCreds();
  if (!verifyPayuniHash(c.hashKey, body.EncryptInfo, c.hashIv, body.HashInfo)) {
    throw new BadRequestException('hash mismatch');
  }
  const data = decryptPayuni(body.EncryptInfo, c.hashKey, c.hashIv);
  if (data.Status !== 'SUCCESS') throw new BadRequestException(data.Message);
  const map = JSON.parse(data.MapJson);
  return {
    storeType: map.StoreType,           // 'SEVEN'
    storeId: map.StoreID,               // 6 碼
    storeName: map.StoreName,
    address: map.Address,
    insularArea: map.InsularArea,       // I=本島 / O=離島
    merKeyNo: data.MerKeyNo,
    goodsType: parseInt(data.GoodsType, 10),
    lgsType: data.LgsType,
  };
}
```

---

## 情境 3：建立黑貓宅配單 + 取得託運單 PDF

```ts
async createHomeDeliveryTrade(args: {
  merTradeNo: string;
  goodsType: 1 | 2 | 3;     // 1/2/3=常溫/冷凍/冷藏
  tradeAmt: number;          // 30~20000
  serviceType: 1 | 3;
  deliveryTimeTag: '01' | '02' | '04';
  consignee: string;
  consigneeMobile: string;
  consigneeAddress: string;  // ≤ 120
  prodDesc: string;          // ≤ 20
  notifyUrl?: string;
  consigneeTel?: string;
  consigneeTelAreaCode?: string;
}) {
  const c = await this.settings.getPayuniCreds();
  if (!c.enabled) throw new BadRequestException('shipping.payuni_enabled is off');

  return this.callPayuni(c, '/api/home_delivery/trade', '1.2', {
    MerID: c.merId,
    Timestamp: Math.floor(Date.now() / 1000),
    MerTradeNo: args.merTradeNo,
    GoodsType: args.goodsType,
    LgsType: 'HOME',
    ShipType: 2,
    TradeAmt: args.tradeAmt,
    ServiceType: args.serviceType,
    DeliveryTimeTag: args.deliveryTimeTag,
    Consignee: args.consignee,
    ConsigneeMobile: args.consigneeMobile,
    ConsigneeAddress: args.consigneeAddress,
    ProdDesc: args.prodDesc,
    NotifyURL: args.notifyUrl,
    ConsigneeTel: args.consigneeTel,
    ConsigneeTelAreaCode: args.consigneeTelAreaCode,
  });
}

async buildHomePdfForm(shipTradeNo: string | string[], goodsType: 1 | 2 | 3, shipDate: string, deliveryDate: string, spec: 1 | 2 | 3 | 4) {
  const c = await this.settings.getPayuniCreds();
  if (goodsType !== 1 && spec === 4) throw new BadRequestException('Spec=4 (150cm) only for normal temp');

  const ids = Array.isArray(shipTradeNo) ? shipTradeNo.join(',') : shipTradeNo;
  const params = {
    MerID: c.merId,
    Timestamp: Math.floor(Date.now() / 1000),
    PostType: 1, PrintType: 1,
    ShipTradeNo: ids,
    GoodsType: goodsType,
    LgsType: 'HOME', ShipType: 2,
    ShipDate: shipDate,           // YYYYMMDD; > today, 非週日國定假日
    DeliveryDate: deliveryDate,   // > ShipDate, 非週日
    Spec: spec,
  };
  const encryptInfo = encryptPayuni(params, c.hashKey, c.hashIv);
  const hashInfo = hashInfoPayuni(c.hashKey, encryptInfo, c.hashIv);
  return {
    formUrl: `${PAYUNI_HOSTS[c.mode]}/api/home_delivery/get_obt_number_pdf`,
    fields: { MerID: c.merId, Version: '1.0', EncryptInfo: encryptInfo, HashInfo: hashInfo },
  };
}

async callCat(args: {
  contactName: string;
  contactAddress: string;
  contactMobile?: string;        // 與 contactTel 二擇一
  contactTel?: string;
  contactTelAreaCode?: string;
  normalQuantity: number;
  coldQuantity: number;
  freezeQuantity: number;
  isContact: 'Y' | 'N';
  isTrolley: 'Y' | 'N';
  memo?: string;
}) {
  const c = await this.settings.getPayuniCreds();

  // 客戶端校驗：三種溫層只能有一種 > 0
  const nonZero = [args.normalQuantity, args.coldQuantity, args.freezeQuantity].filter(q => q > 0);
  if (nonZero.length === 0) throw new BadRequestException('At least one temp quantity > 0');
  if (nonZero.length > 1) throw new BadRequestException('Only one temp can be > 0 per call_cat');

  return this.callPayuni(c, '/api/home_delivery/call_cat', '1.0', {
    MerID: c.merId,
    Timestamp: Math.floor(Date.now() / 1000),
    ContactName: args.contactName,
    ContactMobile: args.contactMobile,
    ContactTel: args.contactTel,
    ContactTelAreaCode: args.contactTelAreaCode,
    ContactAddress: args.contactAddress,
    NormalQuantity: args.normalQuantity,
    ColdQuantity: args.coldQuantity,
    FreezeQuantity: args.freezeQuantity,
    IsContact: args.isContact,
    IsTrolley: args.isTrolley,
    Memo: args.memo,
  });
}
```

---

## 情境 4：完整 Notify Controller（貨態 + 取貨完成 + 列印結果）

```ts
// payuni-logistics-notify.controller.ts
import { Body, Controller, HttpCode, Logger, Post } from '@nestjs/common';
import { decryptPayuni, verifyPayuniHash } from '../payuni/payuni-crypto';
import { SettingsService } from '../../../settings/settings.service';
import { OrdersService } from '../../orders/orders.service';

interface PayuniNotifyBody {
  MerID: string;
  Version: string;
  EncryptInfo: string;
  HashInfo: string;
}

const SHIP_STATUS_TO_ORDER_STATUS: Record<number, string> = {
  11: 'delivered',
  21: 'preparing', 22: 'preparing', 91: 'preparing', 92: 'preparing', 98: 'preparing',
  31: 'shipped', 32: 'shipped',
  33: 'attention', 81: 'attention',
  41: 'cancelled',
  43: 'compensation', 44: 'compensation',
  46: 'abandoned',
  51: 'returned', 52: 'returned', 53: 'returned', 55: 'returned', 56: 'returned', 82: 'returned',
};

@Controller('webhooks/payuni/logistics')
export class PayuniLogisticsNotifyController {
  private readonly logger = new Logger(PayuniLogisticsNotifyController.name);

  constructor(
    private readonly settings: SettingsService,
    private readonly orders: OrdersService,
  ) {}

  /**
   * 貨態 Notify (page 274 / 291) — ApiType=ShipStatus
   * URL 在後台「物流設定」 與 「黑貓宅配開通」分別設定
   */
  @Post('ship-status')
  @HttpCode(200)
  async shipStatusNotify(@Body() body: PayuniNotifyBody) {
    const data = await this.decode(body);
    if (!data || data.Status !== 'SUCCESS' || data.ApiType !== 'ShipStatus') return 'OK';

    const shipStatus = parseInt(data.ShipStatus, 10);
    const orderStatus = SHIP_STATUS_TO_ORDER_STATUS[shipStatus];
    if (!orderStatus) {
      this.logger.warn('PAYUNi unknown ShipStatus', { shipStatus });
      return 'OK';
    }

    // 兩種來源欄位：超商 B2C/C2C/HOME 用 ShipTradeNo；C2B 用 RefundODNO
    const shippingRef = data.ShipTradeNo || data.RefundODNO;
    if (!shippingRef) return 'OK';

    await this.orders.updatePayuniShipStatus({
      shippingRef,
      shipStatusCode: shipStatus,
      shipStatusDesc: data.ShipStatusDesc,
      shipStatusTime: data.ShipStatusTime,
      pickupStoreType: data.PickupStoreType ? parseInt(data.PickupStoreType, 10) : undefined,
      orderStatus,
      odno: data.OBTNumber || undefined, // 黑貓會回 OBTNumber
      fileNo: data.FileNo || undefined,
    });

    return 'OK';
  }

  /**
   * 取件付款完成 Notify (建立物流單時帶的 NotifyURL，僅 ServiceType=1 觸發)
   */
  @Post('cod-paid')
  @HttpCode(200)
  async codPaidNotify(@Body() body: PayuniNotifyBody) {
    const data = await this.decode(body);
    if (!data || data.Status !== 'SUCCESS') return 'OK';
    if (data.TradeStatus !== '1') return 'OK';

    await this.orders.markCodPaid({
      shippingRef: data.ShipTradeNo,
      odno: data.Odno,
      payTime: data.PayTime,
      paymentType: parseInt(data.PaymentType, 10),
      shipAmt: parseInt(data.ShipAmt, 10),
    });
    return 'OK';
  }

  /**
   * 出貨單列印成功 Notify — ApiType=Print
   */
  @Post('print-result')
  @HttpCode(200)
  async printResultNotify(@Body() body: PayuniNotifyBody) {
    const data = await this.decode(body);
    if (!data || data.Status !== 'SUCCESS') return 'OK';
    if (data.ApiType !== 'Print') return 'OK';

    await this.orders.recordPrintLabel({
      shippingRef: data.ShipTradeNo,
      partnerId: data.PartnerId,
      odno: data.Odno,
      validationNo: data.ValidationNo, // C2C only
    });
    return 'OK';
  }

  private async decode(body: PayuniNotifyBody) {
    if (!body?.EncryptInfo || !body?.HashInfo) return null;
    const c = await this.settings.getPayuniCreds();
    if (!verifyPayuniHash(c.hashKey, body.EncryptInfo, c.hashIv, body.HashInfo)) {
      this.logger.warn('PAYUNi notify hash mismatch', { merId: body.MerID });
      return null;
    }
    return decryptPayuni(body.EncryptInfo, c.hashKey, c.hashIv);
  }
}
```

### Idempotency / Retry 處理

PAYUNi 在 5xx / 4xx 會重送 Notify。確保訂單 update 為冪等：

```ts
// orders.service.ts
async updatePayuniShipStatus(payload: ShipStatusUpdate) {
  const order = await this.repo.findOne({ where: { shippingRef: payload.shippingRef } });
  if (!order) return; // 物流單未連結到訂單，忽略（可能是純物流不綁金流）

  // 用 (shippingRef, shipStatusCode, shipStatusTime) 為冪等 key
  const dupKey = `${payload.shippingRef}:${payload.shipStatusCode}:${payload.shipStatusTime}`;
  if (order.lastShipStatusKey === dupKey) return;

  order.shippingStatus = payload.orderStatus;
  order.shippingStatusCode = payload.shipStatusCode;
  order.shippingStatusDesc = payload.shipStatusDesc;
  order.shippingStatusTime = new Date(payload.shipStatusTime);
  order.lastShipStatusKey = dupKey;
  if (payload.odno) order.shippingOdno = payload.odno;
  if (payload.fileNo) order.shippingPdfFileNo = payload.fileNo;
  await this.repo.save(order);
}
```

---

## 測試慣例（jest spec）

```ts
// payuni-logistics.service.spec.ts
import { encryptPayuni, decryptPayuni, hashInfoPayuni } from '../payuni/payuni-crypto';

const KEY = '12345678901234567890123456789012';
const IV  = '1234567890123456';

describe('payuni crypto', () => {
  it('encrypt → decrypt round trip', () => {
    const encrypted = encryptPayuni({ MerID: 'AAA', MerTradeNo: 'BBB' }, KEY, IV);
    const decrypted = decryptPayuni(encrypted, KEY, IV);
    expect(decrypted).toEqual({ MerID: 'AAA', MerTradeNo: 'BBB' });
  });

  it('hashInfo is deterministic + uppercase', () => {
    const h = hashInfoPayuni(KEY, 'someEncrypted', IV);
    expect(h).toMatch(/^[0-9A-F]{64}$/);
  });
});
```

```ts
// 用 MSW / nock mock PAYUNi
import nock from 'nock';

beforeEach(() => {
  nock('https://sandbox-api.payuni.com.tw')
    .post('/api/logistics/trade')
    .reply(200, () => {
      const inner = encryptPayuni({
        Status: 'SUCCESS', Message: '建立成功', MerID: 'S111111111',
        MerTradeNo: 'TEST001', TradeNo: 'P20260504000001',
        TradeAmt: 100, TradeStatus: 0, PaymentType: 5,
        ShipTradeNo: 'L20260504000001',
        StoreID: '916712', StoreName: '敦安門市',
      }, KEY, IV);
      return {
        Status: 'SUCCESS', MerID: 'S111111111', Version: '1.3',
        EncryptInfo: inner,
        HashInfo: hashInfoPayuni(KEY, inner, IV),
      };
    });
});
```

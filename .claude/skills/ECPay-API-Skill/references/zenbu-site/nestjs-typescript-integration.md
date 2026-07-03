# ECPay × NestJS / TypeScript 整合（zenbu-site 專案專屬）

> 本檔案為 zenbu-site 專案專屬補充，整合自原 `ecpay-aio-v5` SKILL（已合併刪除）。
> **適用範圍**：`apps/api-gateway/src/commerce/payments/ecpay/`
> **對應官方 SDK**：`scripts/SDK_PHP/src/`
> **最後更新**：2026-05

---

## 為什麼有這個檔案

1. 本專案後端為 **NestJS 11 + TypeORM 0.3**，官方 PHP SDK 不可直接使用
2. 已驗證可運作的 TypeScript 完整範例（CheckMacValue util、Service、Callback Controller）
3. 補充官方 SKILL 未強調的本機開發實務陷阱（Cloudflare Tunnel、時間同步、防火牆、Postman 限制）
4. 對應本專案的 `BasePaymentGateway` 抽象（多支付閘道共存：ECPay / NewebPay / PAYUNi / Shopline）

## 何時載入本檔案（決策表）

| 情境 | 載入時機 |
|------|----------|
| 在 `apps/api-gateway/src/commerce/payments/ecpay/` 開發 | 必載入 |
| 撰寫 ECPay Service / Controller 的 NestJS 程式碼 | 必載入 |
| 處理 ECPay Callback Controller / 驗證 CheckMacValue | 必載入 |
| 撰寫單元測試 / 整合測試 | 必載入（看「§測試對照」段） |
| 純查詢官方 API 規格（不涉及程式碼） | 不需載入，直接讀官方 `references/Payment/*.md` |

## 索引中樞（NEW）

> 完整的「Guide 段落 ↔ NestJS 章節」對應表、「PHP 範例 ↔ TypeScript 反查表」、LLM 觸發完整性說明
> 已抽出獨立檔案：**[`cross-reference-index.md`](./cross-reference-index.md)**
>
> 本檔聚焦「實作細節與程式碼」，索引中樞檔聚焦「導航查詢」。

## 與官方 PHP SDK 範例的對應索引（完整版見 cross-reference-index.md）

> 下方為主要對應；完整 50+ 個 PHP 範例的反查表見 [`cross-reference-index.md §2`](./cross-reference-index.md#2-php-sdk-範例--typescript-反查表v2)

| 本專案章節 | 對應官方範例 / 文件 |
|-----------|-------------------|
| §1 CheckMacValue util | `scripts/SDK_PHP/src/Services/CheckMacValueService.php` + `guides/13-checkmacvalue.md` |
| §2 建立 AIO 訂單 | `scripts/SDK_PHP/example/Payment/Aio/CreateCreditOrder.php` + `guides/01-payment-aio.md` |
| §3 處理付款結果通知 | `scripts/SDK_PHP/example/Payment/Aio/GetCheckoutResponse.php` + `guides/01` §ReturnURL |
| §4 處理取號結果通知 | `guides/01-payment-aio.md` §PaymentInfoURL |
| §5 查詢訂單狀態 | `scripts/SDK_PHP/example/Payment/Aio/QueryTrade.php` |
| §6 信用卡請退款 / DoAction | `scripts/SDK_PHP/example/Payment/Aio/Capture.php` |
| §7 定期定額訂單建立 | `scripts/SDK_PHP/example/Payment/Aio/CreatePeriodicOrder.php` |
| §8 ECPG 站內付 2.0 GetTokenbyTrade | `scripts/SDK_PHP/example/Payment/Ecpg/CreateAllOrder/GetToken.php` |
| §9 ECPG CreatePayment + ThreeDURL 處理 | `guides/02-payment-ecpg.md` |
| §10 ECPG ReturnURL（JSON POST + AES）| `scripts/SDK_PHP/example/Payment/Ecpg/GetResponse.php` |
| §11 幕後授權建單 | `scripts/SDK_PHP/example/Payment/Ecpg/CreatePaymentWithCardID.php` |
| §12 環境變數與設定 | `guides/16-go-live-checklist.md` §安全性 |
| **§13 各付款方式 NestJS 補充** | `scripts/SDK_PHP/example/Payment/Aio/Create{Atm,Cvs,Barcode,WebAtm,Bnpl,Twqr,WeiXin,Installment}Order.php` |
| **§14 定期定額管理 / 查詢** | `Aio/CreditCardPeriodAction.php` + `Aio/QueryPeridicTrade.php` |
| **§15 下載對帳檔** | `Aio/DownloadReconcileCsv.php` + `Aio/DownloadCreditReconcileCsv.php` + `Ecpg/QueryTradeMedia.php` |
| **§16 ECPG 綁卡完整流程** | `Ecpg/CreateBindCard.php` + `CreateBindCardOrder/WebJS.html` + `GetCreateBindCardResponse.php` + `GetTokenbyBindingCard.php` |
| **§17 ECPG 會員綁卡管理** | `Ecpg/GetMemberBindCard.php` + `Ecpg/DeleteMemberBindCard.php` |
| **§18 ECPG DoAction（請款 / 退款）** | `Ecpg/Capture.php` + `Ecpg/DeleteCredit.php` |
| **§19 ECPG 定期定額 / 查詢** | `Ecpg/CreditPeriodAction.php` + `Ecpg/Query{Trade,CreditTrade,PaymentInfo,PeridicTrade}.php` |
| **§20 ECPG 安全處理** | `guides/02` §安全注意事項 |
| **§21 ECPG ATM/CVS 完整 NestJS 範例** | `Ecpg/CreateAtmOrder/` + `Ecpg/CreateCvsOrder/` + `Ecpg/CreateBarcodeOrder/` |
| **§22 Next.js 16 App Router 整合** | `guides/02b` §SPA / Next.js 整合架構 |
| **§23 非信用卡幕後取號（GenPaymentCode）** | `guides/03` §非信用卡幕後取號 + `references/Payment/非信用卡幕後取號API技術文件.md` |

---

## 本專案實務陷阱清單（關鍵守則）

> 以下守則整合自原 `ecpay-aio-v5` SKILL.md 的踩坑紀錄，**生產環境必看**。
> 與官方 SKILL.md §AI 注意事項 互補：本清單偏「本機開發 / 部署」實務，官方清單偏「協議規範」。

1. **Form 提交必須是真實 HTML Form POST**，不可用 AJAX/fetch — ECPay 需要瀏覽器導轉（AIO）
2. **ReturnURL 必須是 Server 端 URL**，不可是前端頁面 URL
3. **ReturnURL 僅支援 HTTP(80) 和 HTTPS(443)**，本機開發用 Cloudflare Tunnel（見 `scripts/start-tunnel.sh`，URL: `https://zenbu-site.powerhouse.tw`）
4. **ItemName 超過 400 字元會被截斷**，截斷處 UTF-8 多位元組字元易產生亂碼，導致 CheckMacValue 不一致 → 掉單。建議送出前先截斷至 200 字元內
5. **API 呼叫過快（非建立訂單）會收到 HTTP 403**，需等 30 分鐘
6. **TotalAmount 必須為正整數**，不可有小數
7. **MerchantTradeNo 不可重複使用**，長度上限 20 字元，本專案建議 prefix `ZBC` + timestamp + 短亂數
8. **PlatformID 使用時，CheckMacValue 需用 PlatformID 對應的 HashKey/HashIV**
9. **CDN 安全過濾**：ECPay 的 CDN 會封鎖含有 shell 指令關鍵字的參數值（echo、curl、wget、ping...）
10. **Postman 無法測試 AIO**：需要瀏覽器環境處理導轉和 3D 驗證
11. **主機時間同步**：商家伺服器必須做時間同步，避免 TimeStamp 驗證失敗
12. **防火牆設定**：出站需開放 `payment.ecpay.com.tw:443`，入站需開放 `postgate.ecpay.com.tw:443`
13. **參數不支援 HTML 標籤**（class-validator 應預先 strip）
14. **非英文域名需 punycode 編碼**

---

## 本專案 BasePaymentGateway 對齊

本專案的 `apps/api-gateway/src/commerce/payments/` 採用統一抽象：

```
payments/
├── interfaces/
│   ├── base-payment-gateway.ts       # 抽象基類
│   └── payment-gateway.types.ts      # 共用型別（PaymentMethod, GatewayResult, ...）
├── ecpay/
│   ├── ecpay.module.ts
│   ├── ecpay.service.ts              # extends BasePaymentGateway
│   └── ecpay.controller.ts           # callback handler
├── newebpay/  payuni/  shopline/     # 同一抽象的其他閘道
└── payment-gateway-registry.module.ts
```

**EcpayService 應 extends BasePaymentGateway**，並實作以下契約方法（根據 `payment-gateway.types.ts`）：
- `createPaymentSession()` — 建立付款（回傳 form HTML 或 redirect URL）
- `verifyCallback()` — 驗證 ReturnURL/PaymentInfoURL callback
- `queryPayment()` — 查詢訂單狀態
- `refund()` — 信用卡退款（僅信用卡）

---

## §1 CheckMacValue util（`apps/api-gateway/src/commerce/payments/ecpay/ecpay-crypto.util.ts`）

```typescript
// ecpay-crypto.util.ts
import * as crypto from 'crypto';

/**
 * ECPay AIO V5 CheckMacValue 產生函式
 * 演算法: SHA256 (EncryptType=1)
 *
 * 對應官方規格：guides/13-checkmacvalue.md §SHA256
 * 對應 PHP SDK：scripts/SDK_PHP/src/Services/CheckMacValueService.php
 */
export function generateCheckMacValue(
  params: Record<string, string | number>,
  hashKey: string,
  hashIV: string,
): string {
  // 1. 排除 CheckMacValue，依參數名稱 A-Z 排序（不分大小寫）
  const sorted = Object.keys(params)
    .filter((key) => key !== 'CheckMacValue')
    .sort((a, b) => a.toLowerCase().localeCompare(b.toLowerCase()))
    .map((key) => `${key}=${params[key]}`)
    .join('&');

  // 2. 前加 HashKey，後加 HashIV
  const raw = `HashKey=${hashKey}&${sorted}&HashIV=${hashIV}`;

  // 3. URL Encode（以 .NET HttpUtility.UrlEncode 為基準）
  let encoded = ecpayUrlEncode(raw);

  // 4. 轉小寫
  encoded = encoded.toLowerCase();

  // 5. SHA256 雜湊 -> 轉大寫
  return crypto.createHash('sha256').update(encoded).digest('hex').toUpperCase();
}

/**
 * 模擬 .NET HttpUtility.UrlEncode 行為
 * Node.js encodeURIComponent 編碼後需替換部分字元以對齊 .NET 標準
 *
 * ⚠️ 此為 CMV 專用 URL encode（AES API 請改用 aesUrlEncode，邏輯不同）
 * 詳見 guides/14-aes-encryption.md §AES vs CMV URL Encode 對比表
 */
export function ecpayUrlEncode(str: string): string {
  return encodeURIComponent(str)
    .replace(/%20/g, '+')
    .replace(/%2d/gi, '-')
    .replace(/%5f/gi, '_')
    .replace(/%2e/gi, '.')
    .replace(/%21/g, '!')
    .replace(/%2a/gi, '*')
    .replace(/%28/g, '(')
    .replace(/%29/g, ')')
    .replace(/%7e/gi, '~');
}

/**
 * 驗證 ECPay 回傳的 CheckMacValue（timing-safe）
 *
 * ⚠️ 必須使用 timingSafeEqual，不可用 `===`（防 timing attack）
 * 對應守則：SKILL.md §AI 注意事項 「CheckMacValue 驗證禁止使用 == / ===」
 */
export function verifyCheckMacValue(
  params: Record<string, string | number>,
  hashKey: string,
  hashIV: string,
): boolean {
  const receivedMac = String(params.CheckMacValue ?? '');
  const calculatedMac = generateCheckMacValue(params, hashKey, hashIV);

  if (receivedMac.length !== calculatedMac.length) return false;

  return crypto.timingSafeEqual(
    Buffer.from(receivedMac, 'utf-8'),
    Buffer.from(calculatedMac, 'utf-8'),
  );
}
```

**URL Encode 特殊字元替換表**（ECPay 以 .NET 標準為基準）：

| 字元 | 標準 URLEncode | ECPay 期望 (.NET) |
|------|---------------|-------------------|
| 空格 | `%20` | `+` |
| `-` | `%2D` | `-` |
| `_` | `%5F` | `_` |
| `.` | `%2E` | `.` |
| `!` | `%21` | `!` |
| `*` | `%2A` | `*` |
| `(` | `%28` | `(` |
| `)` | `%29` | `)` |
| `~` | `%7E` | `~` |

---

## §2 建立 AIO 訂單（`ecpay.service.ts`）

```typescript
// ecpay.service.ts
import { Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { generateCheckMacValue } from './ecpay-crypto.util';

interface CreateEcpayOrderDto {
  merchantTradeNo: string;
  totalAmount: number;
  tradeDesc: string;
  itemName: string;
  returnUrl: string;
  orderResultUrl?: string;
  choosePayment?: 'ALL' | 'Credit' | 'ATM' | 'CVS' | 'BARCODE' | 'WebATM' | 'ApplePay' | 'TWQR' | 'BNPL';
  customField1?: string;
}

@Injectable()
export class EcpayService {
  private readonly logger = new Logger(EcpayService.name);
  private readonly merchantId: string;
  private readonly hashKey: string;
  private readonly hashIV: string;
  private readonly apiUrl: string;

  constructor(private readonly configService: ConfigService) {
    this.merchantId = this.configService.getOrThrow<string>('ECPAY_MERCHANT_ID');
    this.hashKey = this.configService.getOrThrow<string>('ECPAY_HASH_KEY');
    this.hashIV = this.configService.getOrThrow<string>('ECPAY_HASH_IV');

    const isProduction = this.configService.get('NODE_ENV') === 'production';
    this.apiUrl = isProduction
      ? 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5'
      : 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5';
  }

  /**
   * 產生 AIO 訂單的 HTML Form（前端直接 render 後自動 submit）
   *
   * ⚠️ 必須是真實 HTML Form POST，不可用 AJAX/fetch
   * 對應 PHP 範例：scripts/SDK_PHP/example/Payment/Aio/CreateCreditOrder.php
   */
  createOrderForm(dto: CreateEcpayOrderDto): string {
    const now = new Date();
    const merchantTradeDate = this.formatDate(now);

    const params: Record<string, string | number> = {
      MerchantID: this.merchantId,
      MerchantTradeNo: dto.merchantTradeNo,
      MerchantTradeDate: merchantTradeDate,
      PaymentType: 'aio',
      TotalAmount: dto.totalAmount,
      TradeDesc: this.sanitizeTradeDesc(dto.tradeDesc),
      ItemName: this.truncateItemName(dto.itemName, 200),
      ReturnURL: dto.returnUrl,
      ChoosePayment: dto.choosePayment || 'ALL',
      EncryptType: 1,
      NeedExtraPaidInfo: 'Y',
    };

    if (dto.orderResultUrl) {
      params.OrderResultURL = dto.orderResultUrl;
    }
    if (dto.customField1) {
      params.CustomField1 = dto.customField1;
    }

    // 產生 CheckMacValue
    params.CheckMacValue = generateCheckMacValue(params, this.hashKey, this.hashIV);

    // 產生 HTML Form（注意：value 需做 HTML escape）
    const inputs = Object.entries(params)
      .map(([key, value]) => {
        const safeValue = String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
        return `<input type="hidden" name="${key}" value="${safeValue}">`;
      })
      .join('\n');

    return `
      <form id="ecpay-form" method="POST" action="${this.apiUrl}">
        ${inputs}
      </form>
      <script>document.getElementById('ecpay-form').submit();</script>
    `;
  }

  /**
   * 格式化日期為 ECPay 規格 yyyy/MM/dd HH:mm:ss（UTC+8）
   * ⚠️ 伺服器若在海外或 UTC，必須先轉為台灣時間
   */
  private formatDate(date: Date): string {
    const taiwan = new Date(date.getTime() + (date.getTimezoneOffset() + 480) * 60 * 1000);
    const pad = (n: number) => String(n).padStart(2, '0');
    return (
      `${taiwan.getFullYear()}/${pad(taiwan.getMonth() + 1)}/${pad(taiwan.getDate())} ` +
      `${pad(taiwan.getHours())}:${pad(taiwan.getMinutes())}:${pad(taiwan.getSeconds())}`
    );
  }

  /**
   * TradeDesc 過濾：去除控制字元 + WAF 危險關鍵字防護
   */
  private sanitizeTradeDesc(input: string): string {
    return input
      .replace(/[\x00-\x1F\x7F]/g, '')
      .replace(/<[^>]*>/g, '')
      .substring(0, 200);
  }

  /**
   * ItemName 截斷至安全長度（避免 UTF-8 多位元組字元被截斷產生亂碼）
   */
  private truncateItemName(input: string, maxLength: number): string {
    if (input.length <= maxLength) return input;
    return input.substring(0, maxLength);
  }
}
```

---

## §3 處理付款結果通知（Callback Controller）

```typescript
// ecpay.controller.ts
import { Controller, Post, Body, Res, HttpStatus, Logger } from '@nestjs/common';
import type { Response } from 'express';
import { ConfigService } from '@nestjs/config';
import { verifyCheckMacValue } from './ecpay-crypto.util';
import { OrdersService } from '../../orders/orders.service';

/**
 * ECPay Callback Controller
 *
 * ⚠️ 守則：
 * - Callback 回應的 HTTP Status 必須是 200
 * - body 必須是純文字 `1|OK`（精確 ASCII，無引號、無小寫、無換行）
 * - 必須先驗 CheckMacValue 再處理訂單
 * - 必須冪等（綠界最多重送 4 次，5~15 分鐘間隔）
 */
@Controller('webhooks/ecpay')
export class EcpayCallbackController {
  private readonly logger = new Logger(EcpayCallbackController.name);

  constructor(
    private readonly ordersService: OrdersService,
    private readonly configService: ConfigService,
  ) {}

  /**
   * ReturnURL callback handler
   * Content-Type: application/x-www-form-urlencoded
   * 對應 PHP 範例：scripts/SDK_PHP/example/Payment/Aio/GetCheckoutResponse.php
   */
  @Post('return')
  async handlePaymentResult(
    @Body() body: Record<string, string>,
    @Res() res: Response,
  ): Promise<void> {
    const hashKey = this.configService.getOrThrow<string>('ECPAY_HASH_KEY');
    const hashIV = this.configService.getOrThrow<string>('ECPAY_HASH_IV');

    // 1. 驗證 CheckMacValue（timing-safe）
    if (!verifyCheckMacValue(body, hashKey, hashIV)) {
      this.logger.warn(`ECPay callback CMV verify failed: ${body.MerchantTradeNo}`);
      // 注意：CMV 失敗仍應回 200，避免被重試攻擊；但不更新訂單狀態
      res.status(HttpStatus.OK).send('0|CheckMacValueFailed');
      return;
    }

    // 2. 檢查模擬付款
    const isSimulated = body.SimulatePaid === '1';

    // 3. 判斷付款結果（AIO 的 RtnCode 為字串，需 Number() 轉型）
    const rtnCode = Number(body.RtnCode);

    try {
      if (rtnCode === 1) {
        // 付款成功：upsert 確保冪等
        await this.ordersService.upsertPaymentSuccess(body.MerchantTradeNo, {
          tradeNo: body.TradeNo,
          paymentDate: body.PaymentDate,
          paymentType: body.PaymentType,
          tradeAmt: Number(body.TradeAmt),
          isSimulated,
        });
      } else {
        // 付款失敗：記錄
        await this.ordersService.recordPaymentFailure(body.MerchantTradeNo, {
          rtnCode,
          rtnMsg: body.RtnMsg,
        });
      }
    } catch (err) {
      this.logger.error('ECPay callback processing failed', err);
      // 即使內部處理失敗，仍回 1|OK 避免 ECPay 無限重試
      // 失敗的訂單狀態應有獨立的對帳機制處理
    }

    // 4. 回應 ECPay（必須精確為 `1|OK`，HTTP 200）
    res.status(HttpStatus.OK).send('1|OK');
  }
}
```

---

## §4 處理取號結果通知（ATM/CVS/BARCODE PaymentInfoURL）

```typescript
// ecpay.controller.ts (續)
import { Post, Body, Res } from '@nestjs/common';

/**
 * ATM/CVS/BARCODE 取號結果通知
 *
 * RtnCode 成功值：
 * - ATM: RtnCode=2
 * - CVS/BARCODE: RtnCode=10100073
 *
 * ⚠️ 不可將「取號成功碼」視為錯誤！這是消費者取得繳費資訊的成功通知，
 *    與「實際付款成功」（RtnCode=1，via ReturnURL）不同。
 */
@Post('payment-info')
async handlePaymentInfo(
  @Body() body: Record<string, string>,
  @Res() res: Response,
): Promise<void> {
  const hashKey = this.configService.getOrThrow<string>('ECPAY_HASH_KEY');
  const hashIV = this.configService.getOrThrow<string>('ECPAY_HASH_IV');

  if (!verifyCheckMacValue(body, hashKey, hashIV)) {
    res.status(HttpStatus.OK).send('0|CheckMacValueFailed');
    return;
  }

  const paymentType = body.PaymentType ?? '';

  if (paymentType.startsWith('ATM_')) {
    await this.ordersService.savePaymentInfo(body.MerchantTradeNo, {
      type: 'ATM',
      bankCode: body.BankCode,
      vAccount: body.vAccount,
      expireDate: body.ExpireDate,
    });
  } else if (paymentType.startsWith('CVS_')) {
    await this.ordersService.savePaymentInfo(body.MerchantTradeNo, {
      type: 'CVS',
      paymentNo: body.PaymentNo,
      expireDate: body.ExpireDate,
    });
  } else if (paymentType.startsWith('BARCODE_')) {
    await this.ordersService.savePaymentInfo(body.MerchantTradeNo, {
      type: 'BARCODE',
      barcode1: body.Barcode1,
      barcode2: body.Barcode2,
      barcode3: body.Barcode3,
      expireDate: body.ExpireDate,
    });
  }

  res.status(HttpStatus.OK).send('1|OK');
}
```

---

## §5 查詢訂單狀態

```typescript
// ecpay.service.ts (續)

/**
 * 查詢訂單狀態
 *
 * ⚠️ TimeStamp 驗證區間 3 分鐘內有效
 * ⚠️ TimeStamp 必須是 Unix 秒數（不是毫秒），JS 需 Math.floor(Date.now() / 1000)
 * 對應 PHP 範例：scripts/SDK_PHP/example/Payment/Aio/QueryTrade.php
 */
async queryTradeInfo(merchantTradeNo: string): Promise<Record<string, string>> {
  const isProduction = this.configService.get('NODE_ENV') === 'production';
  const url = isProduction
    ? 'https://payment.ecpay.com.tw/Cashier/QueryTradeInfo/V5'
    : 'https://payment-stage.ecpay.com.tw/Cashier/QueryTradeInfo/V5';

  const params: Record<string, string | number> = {
    MerchantID: this.merchantId,
    MerchantTradeNo: merchantTradeNo,
    TimeStamp: Math.floor(Date.now() / 1000),
  };

  params.CheckMacValue = generateCheckMacValue(params, this.hashKey, this.hashIV);

  const formData = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    formData.append(key, String(value));
  }

  // ⚠️ 不使用 axios（本專案禁用），改用內建 fetch
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: formData.toString(),
  });

  const text = await response.text();

  // 回應為 key=value&key=value 格式（pipe-separated 變體）
  const result: Record<string, string> = {};
  text.split('&').forEach((pair: string) => {
    const [key, ...valueParts] = pair.split('=');
    result[key] = decodeURIComponent(valueParts.join('=').replace(/\+/g, ' '));
  });

  return result;
}
```

---

## §6 信用卡請退款（DoAction）

```typescript
// ecpay.service.ts (續)

/**
 * 信用卡請退款作業
 * Action: C=請款, R=退款, E=取消, N=放棄
 *
 * ⚠️ 守則：
 * - 僅正式環境可用（測試環境無此 API）
 * - 僅信用卡（PaymentType=Credit_CreditCard）可呼叫
 * - ATM/CVS/BARCODE 不支援 API 退款，需透過綠界商家後台
 */
async creditCardAction(
  merchantTradeNo: string,
  tradeNo: string,
  action: 'C' | 'R' | 'E' | 'N',
  totalAmount: number,
): Promise<{ rtnCode: number; rtnMsg: string }> {
  const url = 'https://payment.ecpay.com.tw/CreditDetail/DoAction';

  const params: Record<string, string | number> = {
    MerchantID: this.merchantId,
    MerchantTradeNo: merchantTradeNo,
    TradeNo: tradeNo,
    Action: action,
    TotalAmount: totalAmount,
  };

  params.CheckMacValue = generateCheckMacValue(params, this.hashKey, this.hashIV);

  const formData = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    formData.append(key, String(value));
  }

  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: formData.toString(),
  });

  const text = await response.text();

  const result: Record<string, string> = {};
  text.split('&').forEach((pair: string) => {
    const [key, ...valueParts] = pair.split('=');
    result[key] = decodeURIComponent(valueParts.join('=').replace(/\+/g, ' '));
  });

  return {
    rtnCode: Number(result.RtnCode),
    rtnMsg: result.RtnMsg,
  };
}
```

---

## §7 定期定額訂單建立

```typescript
// ecpay.service.ts (續)

interface CreatePeriodicOrderDto {
  merchantTradeNo: string;
  totalAmount: number;
  tradeDesc: string;
  itemName: string;
  returnUrl: string;
  periodReturnUrl?: string;
  periodType: 'D' | 'M' | 'Y';
  frequency: number;
  execTimes: number;
}

/**
 * 建立定期定額訂單
 *
 * ⚠️ 守則：
 * - PeriodAmount 必須與 TotalAmount 相同
 * - ChoosePayment 必須為 Credit
 * - 不可與紅利折抵或分期同時使用
 * - 首次授權失敗不會排入排程；連續 6 次失敗自動取消後續扣款
 *
 * 對應 PHP 範例：scripts/SDK_PHP/example/Payment/Aio/CreatePeriodicOrder.php
 */
createPeriodicOrderForm(dto: CreatePeriodicOrderDto): string {
  const params: Record<string, string | number> = {
    MerchantID: this.merchantId,
    MerchantTradeNo: dto.merchantTradeNo,
    MerchantTradeDate: this.formatDate(new Date()),
    PaymentType: 'aio',
    TotalAmount: dto.totalAmount,
    TradeDesc: this.sanitizeTradeDesc(dto.tradeDesc),
    ItemName: this.truncateItemName(dto.itemName, 200),
    ReturnURL: dto.returnUrl,
    ChoosePayment: 'Credit',
    EncryptType: 1,
    PeriodAmount: dto.totalAmount, // 必須與 TotalAmount 相同
    PeriodType: dto.periodType,
    Frequency: dto.frequency,
    ExecTimes: dto.execTimes,
  };

  if (dto.periodReturnUrl) {
    params.PeriodReturnURL = dto.periodReturnUrl;
  }

  params.CheckMacValue = generateCheckMacValue(params, this.hashKey, this.hashIV);

  const inputs = Object.entries(params)
    .map(([key, value]) => {
      const safeValue = String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
      return `<input type="hidden" name="${key}" value="${safeValue}">`;
    })
    .join('\n');

  return `
    <form id="ecpay-form" method="POST" action="${this.apiUrl}">
      ${inputs}
    </form>
    <script>document.getElementById('ecpay-form').submit();</script>
  `;
}
```

---

## §8 ECPG 站內付 2.0 — GetTokenbyTrade

> ECPG 與 AIO 是不同的服務。ECPG 走 AES-JSON，需要兩個 Domain：
> - Token / 建立交易：`ecpg.ecpay.com.tw`
> - 查詢 / 退款：`ecpayment.ecpay.com.tw`

```typescript
// ecpay-aes.util.ts
import * as crypto from 'crypto';

/**
 * AES-128-CBC 加密（ECPG / 發票 / 物流 v2 / ECTicket / 收據）
 *
 * ⚠️ 與 CMV 的 ecpayUrlEncode 邏輯不同：
 * - 不做 toLowerCase
 * - 不做 .NET 字元還原
 * - 只做 standard urlencode + 補編碼 `!*~`
 *
 * 詳見 guides/14-aes-encryption.md §AES vs CMV URL Encode 對比表
 */
export function aesUrlEncode(str: string): string {
  return encodeURIComponent(str)
    .replace(/!/g, '%21')
    .replace(/\*/g, '%2A')
    .replace(/~/g, '%7E');
}

export function aesEncrypt(plaintext: string, hashKey: string, hashIV: string): string {
  const cipher = crypto.createCipheriv(
    'aes-128-cbc',
    Buffer.from(hashKey, 'utf-8'),
    Buffer.from(hashIV, 'utf-8'),
  );
  cipher.setAutoPadding(true); // PKCS7
  const encrypted = Buffer.concat([cipher.update(plaintext, 'utf-8'), cipher.final()]);
  return encrypted.toString('base64');
}

export function aesDecrypt(ciphertext: string, hashKey: string, hashIV: string): string {
  const decipher = crypto.createDecipheriv(
    'aes-128-cbc',
    Buffer.from(hashKey, 'utf-8'),
    Buffer.from(hashIV, 'utf-8'),
  );
  decipher.setAutoPadding(true);
  const decrypted = Buffer.concat([
    decipher.update(Buffer.from(ciphertext, 'base64')),
    decipher.final(),
  ]);
  return decrypted.toString('utf-8');
}

/**
 * ECPG 請求三層結構打包
 * Layer 1: { MerchantID, RqHeader, Data }
 * Layer 2: Data = aesEncrypt(JSON.stringify({ MerchantID, ... }))
 */
export function buildEcpgRequest(
  merchantId: string,
  payload: Record<string, unknown>,
  hashKey: string,
  hashIV: string,
): { MerchantID: string; RqHeader: { Timestamp: number }; Data: string } {
  const innerJson = JSON.stringify({
    MerchantID: merchantId,
    ...payload,
  });
  const urlEncoded = aesUrlEncode(innerJson);
  const encrypted = aesEncrypt(urlEncoded, hashKey, hashIV);

  return {
    MerchantID: merchantId,
    RqHeader: {
      Timestamp: Math.floor(Date.now() / 1000),
      // 注意：電子收據 RqHeader 不需 Revision（其他服務需要）
    },
    Data: encrypted,
  };
}
```

```typescript
// ecpg.service.ts
import { Injectable } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { aesEncrypt, aesDecrypt, aesUrlEncode, buildEcpgRequest } from './ecpay-aes.util';

interface CreateEcpgOrderDto {
  merchantTradeNo: string;
  totalAmount: number;
  tradeDesc: string;
  itemName: string;
  returnUrl: string;
  orderResultUrl: string;
  consumerInfo: {
    Email: string; // ⚠️ 必填，缺漏是 GetToken 失敗最常見根因
    Phone: string; // ⚠️ 必填
  };
}

@Injectable()
export class EcpgService {
  private readonly merchantId: string;
  private readonly hashKey: string;
  private readonly hashIV: string;
  private readonly tokenUrl: string;
  private readonly paymentUrl: string;
  private readonly queryUrl: string; // 查詢/退款用 ecpayment domain

  constructor(private readonly configService: ConfigService) {
    this.merchantId = this.configService.getOrThrow<string>('ECPAY_MERCHANT_ID');
    this.hashKey = this.configService.getOrThrow<string>('ECPAY_HASH_KEY');
    this.hashIV = this.configService.getOrThrow<string>('ECPAY_HASH_IV');

    const isProduction = this.configService.get('NODE_ENV') === 'production';
    // ⚠️ 雙 Domain：Token/建單走 ecpg；查詢/退款走 ecpayment（混用會 404）
    this.tokenUrl = isProduction
      ? 'https://ecpg.ecpay.com.tw/Merchant/GetTokenbyTrade'
      : 'https://ecpg-stage.ecpay.com.tw/Merchant/GetTokenbyTrade';
    this.paymentUrl = isProduction
      ? 'https://ecpg.ecpay.com.tw/Merchant/CreatePayment'
      : 'https://ecpg-stage.ecpay.com.tw/Merchant/CreatePayment';
    this.queryUrl = isProduction
      ? 'https://ecpayment.ecpay.com.tw/Merchant/QueryTradeInfo'
      : 'https://ecpayment-stage.ecpay.com.tw/Merchant/QueryTradeInfo';
  }

  /**
   * 取得交易 Token（前端拿這個 Token 才能呼叫 CreatePayment）
   *
   * ⚠️ ConsumerInfo 物件缺失或 Email/Phone 未填 → RtnCode≠1 無明確錯誤訊息
   */
  async getTokenbyTrade(dto: CreateEcpgOrderDto): Promise<string> {
    const body = buildEcpgRequest(
      this.merchantId,
      {
        MerchantTradeNo: dto.merchantTradeNo,
        MerchantTradeDate: this.formatDate(new Date()),
        TotalAmount: dto.totalAmount,
        TradeDesc: dto.tradeDesc,
        ItemName: dto.itemName,
        ReturnURL: dto.returnUrl,
        OrderResultURL: dto.orderResultUrl,
        ConsumerInfo: dto.consumerInfo,
      },
      this.hashKey,
      this.hashIV,
    );

    const response = await fetch(this.tokenUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });

    const json = await response.json() as { TransCode: number; Data: string };

    // ⚠️ 雙層錯誤檢查：先驗 TransCode（傳輸層）
    if (json.TransCode !== 1) {
      throw new Error(`ECPG GetToken TransCode failed: ${json.TransCode}`);
    }

    // 解密 Data 取得業務層資料
    const decrypted = aesDecrypt(json.Data, this.hashKey, this.hashIV);
    const innerData = JSON.parse(decodeURIComponent(decrypted)) as {
      RtnCode: number;
      RtnMsg: string;
      Token: string;
    };

    // 再驗 RtnCode（業務層，AES-JSON 服務的 RtnCode 為整數）
    if (innerData.RtnCode !== 1) {
      throw new Error(`ECPG GetToken RtnCode failed: ${innerData.RtnCode} ${innerData.RtnMsg}`);
    }

    return innerData.Token;
  }

  private formatDate(date: Date): string {
    const taiwan = new Date(date.getTime() + (date.getTimezoneOffset() + 480) * 60 * 1000);
    const pad = (n: number) => String(n).padStart(2, '0');
    return (
      `${taiwan.getFullYear()}/${pad(taiwan.getMonth() + 1)}/${pad(taiwan.getDate())} ` +
      `${pad(taiwan.getHours())}:${pad(taiwan.getMinutes())}:${pad(taiwan.getSeconds())}`
    );
  }
}
```

---

## §9 ECPG CreatePayment + ThreeDURL 處理

```typescript
// 前端（apps/web）：取得 Token 後送出 CreatePayment 請求
// JS SDK 三依賴必須按順序載入：
// <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
// <script src="https://cdn.jsdelivr.net/npm/node-forge@1.3.1/dist/forge.min.js"></script>
// <script src="https://ecpg-stage.ecpay.com.tw/Scripts/sdk-1.0.0.js"></script>  // 注意大寫 S

// <div id="ECPayPayment"></div>  ⚠️ 必須使用此 ID（SDK 硬編碼）

// ECPay.initialize('Stage', 1, callback)  // 字串而非整數！'Stage' 或 'Prod'
```

```typescript
// 後端：CreatePayment 回應處理
// ⚠️ ThreeDURL 是巢狀結構：data.ThreeDInfo.ThreeDURL（不是 data.ThreeDURL）
// ⚠️ 2025/8 起幾乎必定出現 ThreeDURL，省略此步驟會導致交易逾時失敗

interface CreatePaymentResponse {
  TransCode: number;
  Data: string; // AES 加密
}

interface CreatePaymentInnerData {
  RtnCode: number;
  RtnMsg: string;
  ThreeDInfo?: {
    ThreeDURL: string; // 非空時必須前端導向此 URL
  };
  // ATM/CVS/Barcode：含付款指示
  PaymentInfo?: {
    BankCode?: string;
    vAccount?: string;
    PaymentNo?: string;
    Barcode1?: string;
    Barcode2?: string;
    Barcode3?: string;
    ExpireDate?: string;
  };
}
```

---

## §10 ECPG ReturnURL（JSON POST + AES）

```typescript
// ecpg-callback.controller.ts

/**
 * ECPG ReturnURL（Server-to-Server，JSON POST）
 *
 * ⚠️ 與 AIO ReturnURL 不同：
 * - Content-Type: application/json（不是 form-urlencoded）
 * - body 從 raw body 讀（不是 $_POST）
 * - RtnCode 為整數（不是字串）
 * - 但回應同樣為 `1|OK`
 */
@Post('ecpg/return')
async handleEcpgReturn(
  @Body() body: { MerchantID: string; RqHeader: { Timestamp: number }; TransCode: number; Data: string },
  @Res() res: Response,
): Promise<void> {
  const hashKey = this.configService.getOrThrow<string>('ECPAY_HASH_KEY');
  const hashIV = this.configService.getOrThrow<string>('ECPAY_HASH_IV');

  // 1. 先驗 TransCode（傳輸層）
  if (body.TransCode !== 1) {
    this.logger.warn(`ECPG callback TransCode failed: ${body.TransCode}`);
    res.status(HttpStatus.OK).send('0|TransCodeFailed');
    return;
  }

  // 2. AES 解密 Data
  let innerData: { RtnCode: number; RtnMsg: string; MerchantTradeNo: string; TradeNo: string };
  try {
    const decrypted = aesDecrypt(body.Data, hashKey, hashIV);
    innerData = JSON.parse(decodeURIComponent(decrypted));
  } catch (err) {
    this.logger.error('ECPG callback AES decrypt failed', err);
    res.status(HttpStatus.OK).send('0|DecryptFailed');
    return;
  }

  // 3. 業務層處理（RtnCode 整數）
  if (innerData.RtnCode === 1) {
    await this.ordersService.upsertPaymentSuccess(innerData.MerchantTradeNo, {
      tradeNo: innerData.TradeNo,
      // ...其他欄位
    });
  }

  res.status(HttpStatus.OK).send('1|OK');
}

/**
 * ECPG OrderResultURL（前端跳轉，Form POST + ResultData）
 *
 * ⚠️ 與 ReturnURL 不同：
 * - Content-Type: application/x-www-form-urlencoded
 * - body.ResultData 是 JSON 字串需 parse
 * - 回應應為 HTML 結果頁（不需 `1|OK`）
 */
@Post('ecpg/order-result')
async handleEcpgOrderResult(
  @Body() body: { ResultData: string },
  @Res() res: Response,
): Promise<void> {
  // 解析前端跳轉夾帶的結果（用於顯示結果頁，不更新訂單狀態 — 訂單狀態以 ReturnURL 為準）
  const result = JSON.parse(body.ResultData) as { MerchantTradeNo: string };

  // 重定向到本專案的結果頁
  res.redirect(`/zh-TW/shop/checkout/result?orderNo=${encodeURIComponent(result.MerchantTradeNo)}`);
}
```

---

## §11 幕後授權建單

```typescript
// ecpay-backend-auth.service.ts

/**
 * 幕後授權（純後台扣款，無消費者前端互動）
 *
 * 適用：訂閱續扣、代理人代付、儲值卡綁定後扣款
 * 必要前提：消費者已綁卡（透過站內付 2.0 綁卡流程取得 CardID）
 *
 * 對應 PHP：scripts/SDK_PHP/example/Payment/Ecpg/CreatePaymentWithCardID.php
 * 對應 guide：guides/03-payment-backend.md
 */
async createBackendAuthOrder(dto: {
  merchantTradeNo: string;
  totalAmount: number;
  cardId: string; // 從綁卡流程取得
  memberId: string;
}): Promise<{ tradeNo: string; rtnCode: number }> {
  const url = this.configService.get('NODE_ENV') === 'production'
    ? 'https://ecpayment.ecpay.com.tw/Merchant/CreatePaymentWithCardID'
    : 'https://ecpayment-stage.ecpay.com.tw/Merchant/CreatePaymentWithCardID';

  const body = buildEcpgRequest(
    this.merchantId,
    {
      MerchantTradeNo: dto.merchantTradeNo,
      MerchantTradeDate: this.formatDate(new Date()),
      TotalAmount: dto.totalAmount,
      CardID: dto.cardId,
      MemberID: dto.memberId,
    },
    this.hashKey,
    this.hashIV,
  );

  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });

  const json = await response.json() as { TransCode: number; Data: string };
  if (json.TransCode !== 1) {
    throw new Error(`Backend auth TransCode failed: ${json.TransCode}`);
  }

  const decrypted = aesDecrypt(json.Data, this.hashKey, this.hashIV);
  const innerData = JSON.parse(decodeURIComponent(decrypted)) as {
    RtnCode: number;
    RtnMsg: string;
    TradeNo: string;
  };

  return {
    tradeNo: innerData.TradeNo,
    rtnCode: innerData.RtnCode,
  };
}
```

---

## §12 環境變數與設定

### `.env` 範例

```env
# ECPay AIO + ECPG 共用測試帳號（公開測試用，正式環境必須替換）
ECPAY_MERCHANT_ID=3002607
ECPAY_HASH_KEY=pwFHCqoQZGmho4w6
ECPAY_HASH_IV=EkRm7iFT261dpevs

# 環境（production 切換到正式 endpoint）
NODE_ENV=development

# Callback URL（用 Cloudflare Tunnel 提供本機可達的公網位址）
ECPAY_RETURN_URL=https://zenbu-site.powerhouse.tw/api/v1/webhooks/ecpay/return
ECPAY_PAYMENT_INFO_URL=https://zenbu-site.powerhouse.tw/api/v1/webhooks/ecpay/payment-info
ECPAY_ORDER_RESULT_URL=https://zenbu-site.powerhouse.tw/zh-TW/shop/checkout/result
```

### 安全守則

- ❌ HashKey/HashIV **絕對不可放在前端程式碼**
- ❌ HashKey/HashIV **絕對不可寫入 git**（即使是測試值也要走 `.env`）
- ✅ 正式環境用環境變數 / Secret Manager（GCP Secret Manager / AWS SSM / Vault）
- ✅ Production deploy 前確認 `NODE_ENV=production` 已切換 endpoint

---

## 集中對照表

### ChoosePayment 付款方式值

| 值 | 說明 |
|----|------|
| `ALL` | 全方位金流（顯示所有已啟用的付款方式） |
| `Credit` | 信用卡（含一次付清、分期、定期定額） |
| `ATM` | ATM 虛擬帳號 |
| `CVS` | 超商代碼 |
| `BARCODE` | 超商條碼 |
| `WebATM` | 網路 ATM |
| `ApplePay` | Apple Pay |
| `TWQR` | TWQR 行動支付 (歐付寶) |
| `BNPL` | 無卡分期 (裕富/中租) |
| `WeiXin` | 微信支付 |
| `DigitalPayment` | 綠界 Pay (街口支付等) |

### ChooseSubPayment 子項目值

**ATM**：`FIRST` / `CATHAY` / `PANHSIN` / `KGI`
**CVS**：`CVS` / `OK` / `FAMILY` / `HILIFE` / `IBON`
**BNPL**：`URICH`（裕富）/ `ZINGALA`（中租銀角零卡）
**WebATM**：`BOT` / `CHINATRUST` / `FIRST` / `LAND`

### PaymentType 回傳值（Callback 中常見）

| PaymentType | 說明 |
|-------------|------|
| `Credit_CreditCard` | 信用卡（VISA/MasterCard/JCB） |
| `Flexible_Installment` | 永豐 30 期 |
| `WebATM_BOT/CHINATRUST/FIRST/LAND/TACHONG` | WebATM |
| `ATM_BOT/CHINATRUST/FIRST/LAND/CATHAY/PANHSIN/KGI` | ATM |
| `CVS_CVS/OK/FAMILY/HILIFE/IBON` | 超商代碼 |
| `BARCODE_BARCODE` | 超商條碼 |
| `TWQR_OPAY` | 歐付寶 TWQR |
| `BNPL_URICH/ZINGALA` | 無卡分期 |
| `DigitalPayment_Jkopay` | 街口支付 |
| `DigitalPayment_IPASS` | 一卡通 iPASS MONEY |

### 重要交易訊息代碼

| 代碼 | 訊息 | 說明 |
|------|------|------|
| `1` | 付款成功 | RtnCode=1 |
| `2` | ATM 取號成功 | ATM PaymentInfoURL 的成功碼（不是錯誤！） |
| `10100073` | CVS/BARCODE 取號成功 | CVS/BARCODE PaymentInfoURL 的成功碼（不是錯誤！） |
| `10100058` | 3D 驗證失敗 | 建議：更換瀏覽器、關閉 VPN、更換 IP |
| `10200095` | 交易未成立 | TradeStatus 查詢結果 |
| `10200141` | 商店未開啟收款服務 | 確認 API URL 環境正確、確認服務已啟用 |
| `10100248` / `10800001` | 觸動風險控管 | 連續刷卡或可疑電話，需聯繫發卡行 |
| `5100070` | 建立訂單失敗 | 交易金額超出付款方式的限額範圍 |

---

## 測試對照（與本專案測試慣例對齊）

本專案測試 layout：
- 單元測試：`*.spec.ts` — Mock `fetch` / Repository
- 整合測試：`*.int-spec.ts` — 真實 DB
- E2E 測試：`apps/web/e2e/*.spec.ts` — Playwright 1920×1080 viewport

### 範例：CheckMacValue util 單元測試

```typescript
// ecpay-crypto.util.spec.ts
import { generateCheckMacValue, verifyCheckMacValue } from './ecpay-crypto.util';

describe('ECPay CheckMacValue', () => {
  // 測試向量來自 test-vectors/checkmacvalue.json（官方）
  const HASH_KEY = 'pwFHCqoQZGmho4w6';
  const HASH_IV = 'EkRm7iFT261dpevs';

  it('應正確產生 CheckMacValue', () => {
    const params = {
      MerchantID: '3002607',
      MerchantTradeNo: 'TEST20260101',
      TotalAmount: 100,
      // ...
    };
    const cmv = generateCheckMacValue(params, HASH_KEY, HASH_IV);
    expect(cmv).toMatch(/^[A-F0-9]{64}$/); // SHA256 = 64 hex chars uppercase
  });

  it('verifyCheckMacValue 應為 timing-safe（同長度才比較）', () => {
    const params = { Foo: 'Bar', CheckMacValue: 'INVALID' };
    expect(verifyCheckMacValue(params, HASH_KEY, HASH_IV)).toBe(false);
  });
});
```

### 範例：Callback Controller 整合測試

參考 `apps/api-gateway/src/commerce/payments/newebpay/newebpay.int-spec.ts` 的 fixture 模式：將真實 callback payload 存於 `apps/api-gateway/tests/fixtures/ecpay/`，測試時讀檔重放。

---

## 常見錯誤對照（生成程式碼前自檢）

| # | 錯誤 | 後果 | 防範 |
|---|------|------|------|
| 1 | 混用 `ecpayUrlEncode`（CMV）與 `aesUrlEncode`（AES） | CheckMacValue 永遠不符 / TransCode≠1 | 確認當前 API 協定後選用 |
| 2 | 用 `===` 比較 CheckMacValue | timing attack 風險 | 使用 `crypto.timingSafeEqual` |
| 3 | RtnCode 用字串比較（CMV 服務）/ 整數比較（AES 服務） | 判斷永遠失敗 | AIO/物流 callback → 字串；ECPG/發票 → 整數 |
| 4 | Callback 回 `"1\|OK"`（含引號）/ `1\|ok`（小寫）/ `1OK`（缺分隔） | 觸發最多 4 次重試 | 精確 ASCII `1\|OK`，HTTP 200 |
| 5 | 將 ATM `RtnCode=2` 視為錯誤 | 訂單誤取消 | 取號成功碼≠付款成功碼 |
| 6 | 站內付 2.0 所有請求打 `ecpg` domain | 404 錯誤 | 查詢/退款必須用 `ecpayment` domain |
| 7 | `JSON.stringify` 序列化中文字元 | AES 解密成功但內容含 unicode escape | 加 `replacer` 或前端 `unescape` |
| 8 | TimeStamp 用毫秒（`Date.now()`） | TimeStamp 驗證失敗 | 必須是秒（`Math.floor(Date.now() / 1000)`） |
| 9 | MerchantTradeDate 沒處理時區 | ECPay 拒單 | 必須 UTC+8，本檔 §formatDate 已處理 |
| 10 | 用 axios | 違反專案規則 | 改用內建 `fetch` |
| 11 | HashKey/HashIV 在前端或 git | 安全事件 | 環境變數 / Secret Manager |
| 12 | ItemName 含 `<`、`>`、控制字元 | WAF 攔截 10400011 | sanitizeTradeDesc / truncateItemName |
| 13 | ItemName 超過 400 字元被 ECPay 截斷 | UTF-8 多位元組字元亂碼 → CMV 不一致 → 掉單 | 送出前先 truncateItemName 至 200 字元再算 CMV |
| 14 | iframe 嵌入綠界付款頁 | 瀏覽器封鎖 / 跨域阻擋 | 改用站內付 2.0 或 `window.location.href` 跳轉 |
| 15 | iOS LINE / Facebook 內建 WebView 付款 | MerchantID is Null / 表單無法送出 | 偵測 WebView 後引導消費者用外部瀏覽器開啟 |
| 16 | BNPL 金額低於 3,000 元 | API 回 5100070 訂單建立失敗 | 前端先擋金額；URICH 1,000+, ZINGALA 50+（其實 BNPL 整體 ≥3,000）|
| 17 | 定期定額連續 6 次失敗仍嘗試扣款 | ECPay 自動取消後續扣款 | 監控 PeriodReturnURL，連續失敗時通知客服 |
| 18 | 測試 / 正式環境帳號混用 | CheckMacValue 驗證失敗 / API 401 | 環境變數明確區分，部署前 grep 檢查 hardcoded 值 |
| 19 | MerchantTradeNo 重複 | API 回 10100097 訂單編號重複 | 加 timestamp + 短亂數確保唯一 |
| 20 | AES 解密失敗（PKCS7 padding 錯）| Buffer.from 編碼錯誤 | 確保 base64 用標準 alphabet（`+/=`），不用 URL-safe（`-_`） |
| 21 | 站內付 GetToken RtnCode≠1 無明確錯誤 | ConsumerInfo 物件缺失或 Email/Phone 未填 | 驗證 dto.consumerInfo 必含 Email + Phone |
| 22 | 站內付 ThreeDURL 處理遺漏 | 2025/8 起交易逾時失敗 | 必須讀 `data.ThreeDInfo.ThreeDURL`（巢狀！）並前端跳轉 |
| 23 | 站內付 Callback 格式混淆 | 處理失敗 / 重試 4 次 | ReturnURL=JSON POST（讀 raw body），OrderResultURL=Form POST + ResultData |
| 24 | Apple Pay 按鈕沒顯示 | 域名驗證 / Merchant ID / 憑證未完成 | 上線前完成 Apple Pay 三項前置 |
| 25 | WebATM 限制（必須登入網銀）| 消費者卡關 | UI 提示「需準備網銀帳號密碼」 |
| 26 | 微信 / TWQR 限制金額（49,999 上限）| API 回限額錯誤 | 前端先擋金額 |
| 27 | URL 含特殊編碼（中文 / 空格）| CheckMacValue 驗證失敗 | encodeURIComponent + 對齊 .NET 字元（已在 §1 處理） |
| 28 | TWD 以外幣別 | API 回不支援幣別 | ECPay 僅支援新台幣，金額單位為「整數元」（無小數）|
| 29 | 3D Secure 2.0 未啟用 | 信用卡交易被拒 | 確認商家後台已申請 3DS 2.0（2025/8 強制） |
| 30 | ChoosePayment=ALL 排除特定付款方式 | 顯示了不該顯示的付款方式 | 用 IgnorePayment 參數排除（用 `#` 分隔）|

> 完整對應 guides/15-troubleshooting.md 的 §1-§31 各種症狀。協議規範層面的詳細診斷流程（HTTP/DNS/TLS）請查 guides/15。

---

## §13 各付款方式 NestJS 補充

> 對應 PHP：`scripts/SDK_PHP/example/Payment/Aio/Create{Atm,Cvs,Barcode,WebAtm,Bnpl,Twqr,WeiXin,Installment,WeiXin}Order.php`
> 對應 guide：`guides/01-payment-aio.md` §各付款方式專用參數

NestJS 寫法核心：以 §2 的 `createOrderForm()` 為基礎，**透過 ChoosePayment 參數切換 + 各付款方式專屬參數**即可。下方為各付款方式的 dto / 參數覆蓋範例：

### §13.1 信用卡分期（CreditInstallment）

```typescript
interface CreateInstallmentDto extends CreateEcpayOrderDto {
  installment: 3 | 6 | 12 | 18 | 24 | 30; // 30 為永豐專屬，需另申請
}

createInstallmentOrderForm(dto: CreateInstallmentDto): string {
  // 在 §2 的 params 上覆蓋
  const params = {
    // ... §2 共用參數
    ChoosePayment: 'Credit',
    CreditInstallment: dto.installment,
  };
  // ⚠️ 不可與紅利折抵或定期定額同時使用
  // ... CMV + Form 邏輯同 §2
}
```

### §13.2 ATM 虛擬帳號

```typescript
interface CreateAtmDto extends CreateEcpayOrderDto {
  expireDate?: number;          // 1-60 天，預設 3
  paymentInfoUrl: string;       // 取號通知 URL
  clientRedirectURL?: string;
}

createAtmOrderForm(dto: CreateAtmDto): string {
  const params = {
    // ... §2 共用參數
    ChoosePayment: 'ATM',
    ExpireDate: dto.expireDate ?? 3,
    PaymentInfoURL: dto.paymentInfoUrl, // ⚠️ 取號 callback 給 §4 處理
    ...(dto.clientRedirectURL && { ClientRedirectURL: dto.clientRedirectURL }),
  };
  // ...
}
```

### §13.3 CVS 超商代碼（單位：分鐘）

```typescript
interface CreateCvsDto extends CreateEcpayOrderDto {
  storeExpireDate?: number;     // ⚠️ CVS 是分鐘，預設 10080（7天），最大 43200（30天）
  paymentInfoUrl: string;
  desc1?: string;               // 顯示於超商機台
  desc2?: string;
  desc3?: string;
  desc4?: string;
}

createCvsOrderForm(dto: CreateCvsDto): string {
  const params = {
    // ...
    ChoosePayment: 'CVS',
    StoreExpireDate: dto.storeExpireDate ?? 10080,
    PaymentInfoURL: dto.paymentInfoUrl,
    ...(dto.desc1 && { Desc_1: dto.desc1 }),
    // ... Desc_2 ~ Desc_4 同樣處理
  };
}
```

### §13.4 BARCODE 超商條碼（單位：天）

```typescript
interface CreateBarcodeDto extends CreateEcpayOrderDto {
  storeExpireDate?: number;     // ⚠️ BARCODE 是天，預設 7，最短 1，最長 30（與 CVS 不同！）
  paymentInfoUrl: string;
}

createBarcodeOrderForm(dto: CreateBarcodeDto): string {
  const params = {
    // ...
    ChoosePayment: 'BARCODE',
    StoreExpireDate: dto.storeExpireDate ?? 7,
    PaymentInfoURL: dto.paymentInfoUrl,
  };
}
```

### §13.5 BNPL 無卡分期

```typescript
interface CreateBnplDto extends CreateEcpayOrderDto {
  subPayment?: 'URICH' | 'ZINGALA';
  paymentInfoUrl: string;       // 申請結果通知
}

createBnplOrderForm(dto: CreateBnplDto): string {
  // ⚠️ 最低消費金額 3,000 元（URICH/ZINGALA 共同限制）
  if (dto.totalAmount < 3000) {
    throw new BadRequestException('BNPL 最低消費金額為 3,000 元');
  }
  const params = {
    // ...
    ChoosePayment: 'BNPL',
    ...(dto.subPayment && { ChooseSubPayment: dto.subPayment }),
    PaymentInfoURL: dto.paymentInfoUrl,
  };
}
```

### §13.6 TWQR / 微信 / WebATM / Apple Pay

```typescript
// TWQR：金額限制 6 ~ 49,999
createTwqrOrderForm(dto: CreateEcpayOrderDto): string {
  if (dto.totalAmount < 6 || dto.totalAmount > 49999) {
    throw new BadRequestException('TWQR 金額需介於 6~49,999 元');
  }
  const params = { /* ... */ ChoosePayment: 'TWQR' };
  // ...
}

// 微信支付（無額外參數）
createWeiXinOrderForm(dto: CreateEcpayOrderDto): string {
  const params = { /* ... */ ChoosePayment: 'WeiXin' };
}

// WebATM（無額外參數）
createWebAtmOrderForm(dto: CreateEcpayOrderDto): string {
  const params = { /* ... */ ChoosePayment: 'WebATM' };
}

// Apple Pay（無額外參數，但需事先完成域名驗證 + Merchant ID + 憑證上傳）
createApplePayOrderForm(dto: CreateEcpayOrderDto): string {
  const params = { /* ... */ ChoosePayment: 'ApplePay' };
}
```

---

## §14 定期定額管理 / 查詢

> 對應 PHP：`Aio/CreditCardPeriodAction.php` + `Aio/QueryPeridicTrade.php`
> 對應 guide：`guides/01-payment-aio.md` §定期定額管理 / §查詢定期定額

### §14.1 定期定額作業（補授權 / 取消）

```typescript
// ecpay.service.ts (續)

/**
 * 定期定額訂單作業
 * Action: ReAuth=補授權失敗交易, Cancel=終止後續交易
 */
async creditCardPeriodAction(
  merchantTradeNo: string,
  action: 'ReAuth' | 'Cancel',
): Promise<{ rtnCode: number; rtnMsg: string }> {
  const isProduction = this.configService.get('NODE_ENV') === 'production';
  const url = isProduction
    ? 'https://payment.ecpay.com.tw/Cashier/CreditCardPeriodAction'
    : 'https://payment-stage.ecpay.com.tw/Cashier/CreditCardPeriodAction';

  const params: Record<string, string | number> = {
    MerchantID: this.merchantId,
    MerchantTradeNo: merchantTradeNo,
    Action: action,
    TimeStamp: Math.floor(Date.now() / 1000),
  };

  params.CheckMacValue = generateCheckMacValue(params, this.hashKey, this.hashIV);

  const formData = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => formData.append(k, String(v)));

  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: formData.toString(),
  });

  const text = await response.text();
  const result: Record<string, string> = {};
  text.split('&').forEach((pair) => {
    const [k, ...v] = pair.split('=');
    result[k] = decodeURIComponent(v.join('=').replace(/\+/g, ' '));
  });

  return { rtnCode: Number(result.RtnCode), rtnMsg: result.RtnMsg };
}
```

### §14.2 定期定額查詢（含 ExecLog 授權紀錄）

```typescript
// ecpay.service.ts (續)

interface PeriodicTradeInfo {
  merchantTradeNo: string;
  tradeNo: string;          // 首次授權的綠界交易編號
  rtnCode: number;
  periodType: 'D' | 'M' | 'Y';
  frequency: number;
  execTimes: number;
  periodAmount: number;
  totalSuccessTimes: number;
  totalSuccessAmount: number;
  execStatus: '0' | '1' | '2'; // 0=已終止, 1=執行中, 2=執行完成
  execLog: Array<{
    rtnCode: number;
    amount: number;
    gwsr: number;
    processDate: string;
    authCode: string;
    tradeNo: string;
  }>;
}

async queryCreditCardPeriodInfo(merchantTradeNo: string): Promise<PeriodicTradeInfo> {
  const isProduction = this.configService.get('NODE_ENV') === 'production';
  const url = isProduction
    ? 'https://payment.ecpay.com.tw/Cashier/QueryCreditCardPeriodInfo'
    : 'https://payment-stage.ecpay.com.tw/Cashier/QueryCreditCardPeriodInfo';

  const params: Record<string, string | number> = {
    MerchantID: this.merchantId,
    MerchantTradeNo: merchantTradeNo,
    TimeStamp: Math.floor(Date.now() / 1000),
  };
  params.CheckMacValue = generateCheckMacValue(params, this.hashKey, this.hashIV);

  // 此 API 回應為 JSON（與一般查詢的 key=value 格式不同！）
  const formData = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => formData.append(k, String(v)));

  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: formData.toString(),
  });

  const data = await response.json() as Record<string, unknown>;
  // ⚠️ 此 endpoint 回 JSON，格式映射到 PeriodicTradeInfo
  return {
    merchantTradeNo: String(data.MerchantTradeNo),
    tradeNo: String(data.TradeNo),
    rtnCode: Number(data.RtnCode),
    periodType: data.PeriodType as 'D' | 'M' | 'Y',
    frequency: Number(data.Frequency),
    execTimes: Number(data.ExecTimes),
    periodAmount: Number(data.PeriodAmount),
    totalSuccessTimes: Number(data.TotalSuccessTimes),
    totalSuccessAmount: Number(data.TotalSuccessAmount),
    execStatus: String(data.ExecStatus) as '0' | '1' | '2',
    execLog: (data.ExecLog as Array<Record<string, unknown>>) ?? [],
  };
}
```

---

## §15 下載對帳檔

> 對應 PHP：`Aio/DownloadReconcileCsv.php` + `Aio/DownloadCreditReconcileCsv.php` + `Ecpg/QueryTradeMedia.php`
> 對應 guide：`guides/01-payment-aio.md` §下載對帳檔
> ⚠️ **Domain 注意**：對帳檔 API 在 `vendor.ecpay.com.tw`（不是 payment.ecpay.com.tw）

### §15.1 AIO 對帳（CSV）

```typescript
// ecpay.service.ts (續)

/**
 * 下載 AIO 對帳檔（CSV 格式）
 *
 * ⚠️ Domain 是 vendor.ecpay.com.tw（特店後台 domain，不是 payment domain）
 * ⚠️ 需提供日期區間，最大 1 個月
 */
async downloadReconcileCsv(startDate: Date, endDate: Date): Promise<Buffer> {
  const isProduction = this.configService.get('NODE_ENV') === 'production';
  const url = isProduction
    ? 'https://vendor.ecpay.com.tw/PaymentMedia/TradeNoAio'
    : 'https://vendor-stage.ecpay.com.tw/PaymentMedia/TradeNoAio';

  const formatDate = (d: Date) => {
    const taiwan = new Date(d.getTime() + (d.getTimezoneOffset() + 480) * 60 * 1000);
    return `${taiwan.getFullYear()}-${String(taiwan.getMonth() + 1).padStart(2, '0')}-${String(taiwan.getDate()).padStart(2, '0')}`;
  };

  const params: Record<string, string | number> = {
    MerchantID: this.merchantId,
    DateType: 1,                       // 1=訂單日期, 2=付款日期
    StartDate: formatDate(startDate),
    EndDate: formatDate(endDate),
    PaymentType: 'aio',
  };
  params.CheckMacValue = generateCheckMacValue(params, this.hashKey, this.hashIV);

  const formData = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => formData.append(k, String(v)));

  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: formData.toString(),
  });

  // 回傳為 CSV 檔案 binary
  return Buffer.from(await response.arrayBuffer());
}
```

### §15.2 信用卡撥款對帳檔

```typescript
async downloadCreditReconcileCsv(year: number, month: number): Promise<Buffer> {
  const url = 'https://vendor.ecpay.com.tw/CreditDetail/FundingReconDetail'; // 僅正式環境

  const params: Record<string, string | number> = {
    MerchantID: this.merchantId,
    PayDateType: 1,
    StartDate: `${year}-${String(month).padStart(2, '0')}-01`,
    EndDate: `${year}-${String(month).padStart(2, '0')}-${new Date(year, month, 0).getDate()}`,
  };
  params.CheckMacValue = generateCheckMacValue(params, this.hashKey, this.hashIV);

  const formData = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => formData.append(k, String(v)));

  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: formData.toString(),
  });

  return Buffer.from(await response.arrayBuffer());
}
```

### §15.3 ECPG 對帳（QueryTradeMedia）

```typescript
// ecpg.service.ts (續)

/**
 * ECPG 對帳檔下載（AES-JSON 模式，Domain 為 ecpayment）
 */
async queryTradeMedia(startDate: Date, endDate: Date): Promise<{ mediaUrl: string }> {
  const isProduction = this.configService.get('NODE_ENV') === 'production';
  const url = isProduction
    ? 'https://ecpayment.ecpay.com.tw/Merchant/QueryTradeMedia'
    : 'https://ecpayment-stage.ecpay.com.tw/Merchant/QueryTradeMedia';

  const formatDate = (d: Date) => {
    const taiwan = new Date(d.getTime() + (d.getTimezoneOffset() + 480) * 60 * 1000);
    return `${taiwan.getFullYear()}-${String(taiwan.getMonth() + 1).padStart(2, '0')}-${String(taiwan.getDate()).padStart(2, '0')}`;
  };

  const body = buildEcpgRequest(
    this.merchantId,
    {
      StartDate: formatDate(startDate),
      EndDate: formatDate(endDate),
    },
    this.hashKey,
    this.hashIV,
  );

  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });

  const json = await response.json() as { TransCode: number; Data: string };
  if (json.TransCode !== 1) throw new Error(`QueryTradeMedia TransCode=${json.TransCode}`);

  const decrypted = aesDecrypt(json.Data, this.hashKey, this.hashIV);
  const innerData = JSON.parse(decodeURIComponent(decrypted)) as { MediaUrl: string };

  return { mediaUrl: innerData.MediaUrl };
}
```

---

## §16 ECPG 綁卡完整流程

> 對應 PHP：`Ecpg/CreateBindCard.php` + `Ecpg/CreateBindCardOrder/WebJS.html` + `Ecpg/GetCreateBindCardResponse.php` + `Ecpg/GetTokenbyBindingCard.php`
> 對應 guide：`guides/02-payment-ecpg.md` §綁卡付款流程
> 流程：取得綁卡 Token → 前端 3D 驗證 → 處理綁卡結果 → 用 CardID 扣款

### §16.1 步驟 1：取得綁卡 Token（後端）

```typescript
// ecpg-bind-card.service.ts
import { Injectable } from '@nestjs/common';
import { aesDecrypt, buildEcpgRequest } from './ecpay-aes.util';

@Injectable()
export class EcpgBindCardService {
  // ... constructor 同 EcpgService

  /**
   * 取得綁卡 Token（消費者首次綁卡時呼叫）
   * 端點：ecpg domain（建立綁卡走 ecpg）
   */
  async createBindCard(memberId: string, bindCardSn: string): Promise<string> {
    const url = this.isProduction
      ? 'https://ecpg.ecpay.com.tw/Merchant/CreateBindCard'
      : 'https://ecpg-stage.ecpay.com.tw/Merchant/CreateBindCard';

    const body = buildEcpgRequest(
      this.merchantId,
      {
        MerchantMemberID: `${this.merchantId}_${memberId}`,
        BindCardSn: bindCardSn,         // 綁卡序號（自訂，需唯一）
        Description: '會員綁卡',
        ReturnURL: this.bindCardReturnUrl, // 綁卡結果通知 URL
      },
      this.hashKey,
      this.hashIV,
    );

    const response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });

    const json = await response.json() as { TransCode: number; Data: string };
    if (json.TransCode !== 1) {
      throw new Error(`CreateBindCard TransCode failed: ${json.TransCode}`);
    }

    const decrypted = aesDecrypt(json.Data, this.hashKey, this.hashIV);
    const innerData = JSON.parse(decodeURIComponent(decrypted)) as {
      RtnCode: number;
      RtnMsg: string;
      BindingToken: string;
    };

    if (innerData.RtnCode !== 1) {
      throw new Error(`CreateBindCard RtnCode failed: ${innerData.RtnCode} ${innerData.RtnMsg}`);
    }

    return innerData.BindingToken;
  }
}
```

### §16.2 步驟 2：前端 3D 驗證後建立綁卡（HTML）

```html
<!-- 前端：apps/web/app/.../bind-card-page.tsx 對應的 HTML 部分 -->
<!-- 必須引入官方 SDK 三依賴（順序不可錯）-->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/node-forge@1.3.1/dist/forge.min.js"></script>
<script src="https://ecpg-stage.ecpay.com.tw/Scripts/sdk-1.0.0.js"></script>

<div id="ECPayPayment"></div>  <!-- ⚠️ ID 固定，SDK 硬編碼 -->

<script>
  // bindingToken 從後端 §16.1 取得
  ECPay.initialize('Stage', 1, function () {  // 'Stage' 或 'Prod'，字串非整數
    ECPay.createBindCardPayment(bindingToken);
  });
</script>
```

### §16.3 步驟 3：處理綁卡結果 callback（後端）

```typescript
// ecpg-bind-card.controller.ts
import { Post, Body, Res } from '@nestjs/common';
import type { Response } from 'express';

@Post('webhooks/ecpay/bind-card-result')
async handleBindCardResult(
  @Body() body: { TransCode: number; Data: string },
  @Res() res: Response,
): Promise<void> {
  if (body.TransCode !== 1) {
    res.status(200).send('0|TransCodeFailed');
    return;
  }

  const decrypted = aesDecrypt(body.Data, this.hashKey, this.hashIV);
  const innerData = JSON.parse(decodeURIComponent(decrypted)) as {
    RtnCode: number;
    MerchantMemberID: string;
    CardID: string;          // ⭐ 重要：日後扣款用此 ID
    Card4No: string;
    Card6No: string;
  };

  if (innerData.RtnCode === 1) {
    // 將 CardID 與會員綁定保存
    await this.customerService.saveBindCard({
      memberId: innerData.MerchantMemberID.split('_')[1],
      cardId: innerData.CardID,
      cardLast4: innerData.Card4No,
      cardFirst6: innerData.Card6No,
    });
  }

  res.status(200).send('1|OK');
}
```

### §16.4 步驟 4：用 CardID 扣款（同 §11 幕後授權）

> 直接使用 §11 的 `createBackendAuthOrder()` 即可，傳入步驟 3 取得的 `CardID`。

---

## §17 ECPG 會員綁卡管理

> 對應 PHP：`Ecpg/GetMemberBindCard.php` + `Ecpg/DeleteMemberBindCard.php`
> 對應 guide：`guides/02-payment-ecpg.md` §會員綁卡管理

### §17.1 查詢會員所有綁卡

```typescript
// ecpg-bind-card.service.ts (續)

async getMemberBindCards(memberId: string): Promise<Array<{
  cardId: string;
  card4no: string;
  card6no: string;
  cardType: string;
  bindingDate: string;
}>> {
  const url = this.isProduction
    ? 'https://ecpayment.ecpay.com.tw/Merchant/GetMemberBindCard'
    : 'https://ecpayment-stage.ecpay.com.tw/Merchant/GetMemberBindCard';

  const body = buildEcpgRequest(
    this.merchantId,
    { MerchantMemberID: `${this.merchantId}_${memberId}` },
    this.hashKey,
    this.hashIV,
  );

  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });

  const json = await response.json() as { TransCode: number; Data: string };
  if (json.TransCode !== 1) throw new Error('GetMemberBindCard failed');

  const decrypted = aesDecrypt(json.Data, this.hashKey, this.hashIV);
  const innerData = JSON.parse(decodeURIComponent(decrypted)) as {
    RtnCode: number;
    BindCardList: Array<Record<string, string>>;
  };

  return (innerData.BindCardList ?? []).map((c) => ({
    cardId: c.CardID,
    card4no: c.Card4No,
    card6no: c.Card6No,
    cardType: c.CardType,
    bindingDate: c.BindingDate,
  }));
}
```

### §17.2 刪除會員綁卡

```typescript
async deleteMemberBindCard(memberId: string, cardId: string): Promise<void> {
  const url = this.isProduction
    ? 'https://ecpayment.ecpay.com.tw/Merchant/DeleteMemberBindCard'
    : 'https://ecpayment-stage.ecpay.com.tw/Merchant/DeleteMemberBindCard';

  const body = buildEcpgRequest(
    this.merchantId,
    {
      MerchantMemberID: `${this.merchantId}_${memberId}`,
      CardID: cardId,
    },
    this.hashKey,
    this.hashIV,
  );

  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });

  const json = await response.json() as { TransCode: number; Data: string };
  if (json.TransCode !== 1) throw new Error('DeleteMemberBindCard failed');

  const decrypted = aesDecrypt(json.Data, this.hashKey, this.hashIV);
  const innerData = JSON.parse(decodeURIComponent(decrypted)) as { RtnCode: number };

  if (innerData.RtnCode !== 1) throw new Error(`DeleteMemberBindCard RtnCode=${innerData.RtnCode}`);

  // 同步刪除本地保存的綁卡記錄
  await this.customerService.deleteBindCard(memberId, cardId);
}
```

---

## §18 ECPG DoAction（請款 / 退款）

> 對應 PHP：`Ecpg/Capture.php` + `Ecpg/DeleteCredit.php`
> ⚠️ 端點走 `ecpayment` domain，與 §6（AIO DoAction 走 payment domain）不同

```typescript
// ecpg.service.ts (續)

async ecpgDoAction(
  merchantTradeNo: string,
  tradeNo: string,
  action: 'C' | 'R' | 'E' | 'N',
  amount: number,
): Promise<{ rtnCode: number; rtnMsg: string }> {
  // ⚠️ 注意：ECPG 的請款 / 退款是兩個不同 endpoint
  const url = action === 'R'
    ? (this.isProduction
        ? 'https://ecpayment.ecpay.com.tw/Merchant/DeleteCredit'
        : 'https://ecpayment-stage.ecpay.com.tw/Merchant/DeleteCredit')
    : (this.isProduction
        ? 'https://ecpayment.ecpay.com.tw/Merchant/Capture'
        : 'https://ecpayment-stage.ecpay.com.tw/Merchant/Capture');

  const body = buildEcpgRequest(
    this.merchantId,
    {
      MerchantTradeNo: merchantTradeNo,
      TradeNo: tradeNo,
      Action: action,
      TotalAmount: amount,
    },
    this.hashKey,
    this.hashIV,
  );

  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });

  const json = await response.json() as { TransCode: number; Data: string };
  if (json.TransCode !== 1) throw new Error(`ECPG DoAction TransCode=${json.TransCode}`);

  const decrypted = aesDecrypt(json.Data, this.hashKey, this.hashIV);
  const innerData = JSON.parse(decodeURIComponent(decrypted)) as { RtnCode: number; RtnMsg: string };

  return { rtnCode: innerData.RtnCode, rtnMsg: innerData.RtnMsg };
}
```

---

## §19 ECPG 定期定額 / 查詢

> 對應 PHP：`Ecpg/CreditPeriodAction.php` + `Ecpg/Query{Trade,CreditTrade,PaymentInfo,PeridicTrade}.php`
> 對應 guide：`guides/02-payment-ecpg.md` §定期定額管理 / §查詢

```typescript
// ecpg.service.ts (續)

/**
 * ECPG 一般查詢訂單
 */
async ecpgQueryTrade(merchantTradeNo: string): Promise<Record<string, unknown>> {
  const url = this.isProduction
    ? 'https://ecpayment.ecpay.com.tw/Merchant/QueryTrade/V2'
    : 'https://ecpayment-stage.ecpay.com.tw/Merchant/QueryTrade/V2';

  const body = buildEcpgRequest(
    this.merchantId,
    { MerchantTradeNo: merchantTradeNo },
    this.hashKey,
    this.hashIV,
  );

  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });

  const json = await response.json() as { TransCode: number; Data: string };
  if (json.TransCode !== 1) throw new Error(`ECPG QueryTrade TransCode=${json.TransCode}`);

  const decrypted = aesDecrypt(json.Data, this.hashKey, this.hashIV);
  return JSON.parse(decodeURIComponent(decrypted));
}

/**
 * ECPG 定期定額作業（補授權 / 取消）
 */
async ecpgCreditPeriodAction(
  merchantTradeNo: string,
  action: 'ReAuth' | 'Cancel',
): Promise<{ rtnCode: number }> {
  const url = this.isProduction
    ? 'https://ecpayment.ecpay.com.tw/Merchant/CreditPeriodAction'
    : 'https://ecpayment-stage.ecpay.com.tw/Merchant/CreditPeriodAction';

  const body = buildEcpgRequest(
    this.merchantId,
    {
      MerchantTradeNo: merchantTradeNo,
      Action: action,
    },
    this.hashKey,
    this.hashIV,
  );

  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });

  const json = await response.json() as { TransCode: number; Data: string };
  if (json.TransCode !== 1) throw new Error('CreditPeriodAction failed');

  const decrypted = aesDecrypt(json.Data, this.hashKey, this.hashIV);
  const innerData = JSON.parse(decodeURIComponent(decrypted)) as { RtnCode: number };

  return { rtnCode: innerData.RtnCode };
}
```

---

## §20 ECPG 安全處理

> 對應 guide：`guides/02-payment-ecpg.md` §安全注意事項

### §20.1 GetResponse 安全處理（防止偽造 callback）

```typescript
// ecpg.controller.ts 守則
// 1. 必驗 TransCode（傳輸層）
// 2. 必驗 RtnCode（業務層）
// 3. 訂單金額交叉驗證：本地訂單 amount === callback amount
// 4. 重複 callback 偵測：以 MerchantTradeNo + TradeNo 組合為 unique key

@Post('webhooks/ecpay/ecpg-return')
async handleEcpgReturn(@Body() body: any, @Res() res: Response) {
  if (body.TransCode !== 1) return res.status(200).send('0|TransCodeFailed');

  const decrypted = aesDecrypt(body.Data, this.hashKey, this.hashIV);
  const innerData = JSON.parse(decodeURIComponent(decrypted));

  // 取本地訂單做金額交叉驗證（防止重放攻擊）
  const localOrder = await this.ordersService.findByMerchantTradeNo(innerData.MerchantTradeNo);
  if (!localOrder) return res.status(200).send('0|OrderNotFound');
  if (localOrder.totalAmount !== Number(innerData.TradeAmt)) {
    this.logger.error(`金額不一致：local=${localOrder.totalAmount} vs callback=${innerData.TradeAmt}`);
    return res.status(200).send('0|AmountMismatch');
  }

  // upsert 確保冪等
  await this.ordersService.upsertPaymentSuccess(innerData.MerchantTradeNo, { /* ... */ });

  res.status(200).send('1|OK');
}
```

### §20.2 CSP（Content Security Policy）

> 站內付 2.0 前端需要載入綠界 SDK，CSP 必須允許：

```typescript
// apps/web/middleware.ts 或 next.config.mjs
const cspHeader = `
  default-src 'self';
  script-src 'self' 'unsafe-inline' 'unsafe-eval'
    https://code.jquery.com
    https://cdn.jsdelivr.net
    https://ecpg-stage.ecpay.com.tw
    https://ecpg.ecpay.com.tw;
  connect-src 'self'
    https://ecpg-stage.ecpay.com.tw
    https://ecpg.ecpay.com.tw
    https://ecpayment-stage.ecpay.com.tw
    https://ecpayment.ecpay.com.tw;
  frame-src 'self'
    https://ecpg-stage.ecpay.com.tw
    https://ecpg.ecpay.com.tw;
`;
```

### §20.3 Token 安全儲存

- ❌ 不可放 localStorage / sessionStorage（XSS 可讀取）
- ✅ 後端產生 Token 後，回傳給前端時用 httpOnly cookie，或一次性使用後立即廢棄
- ✅ Token 應有 TTL（建議 ≤ 30 分鐘）

### §20.4 防止重複付款（Idempotency）

```typescript
// 用 unique constraint 確保同一 MerchantTradeNo 不會重複入帳
// migration 範例：
// CREATE UNIQUE INDEX idx_orders_merchant_trade_no ON orders (merchant_trade_no);

// upsert 寫法
async upsertPaymentSuccess(merchantTradeNo: string, data: PaymentData) {
  return this.ordersRepository
    .createQueryBuilder()
    .insert()
    .values({ merchantTradeNo, ...data })
    .orUpdate(['paymentDate', 'paymentType', 'tradeAmt'], ['merchantTradeNo'])
    .execute();
}
```

---

## §21 ECPG ATM/CVS 完整 NestJS 範例

> 對應 PHP：`Ecpg/CreateAtmOrder/` + `Ecpg/CreateCvsOrder/` + `Ecpg/CreateBarcodeOrder/`
> 對應 guide：`guides/02b-ecpg-atm-cvs-spa.md`
> 與信用卡 ECPG 的差異：(1) **不需要 JS SDK**；(2) **CreatePayment 回應含付款指示**；(3) **ReturnURL 是非同步的**（消費者繳費後才到）

### §21.1 後端：建立 ECPG ATM 訂單 + 處理 CreatePayment 回應

```typescript
// ecpg-atm-cvs.service.ts

@Injectable()
export class EcpgAtmCvsService {
  // ... constructor 同 EcpgService

  /**
   * ATM 一站式建單（GetToken + CreatePayment 後端直接呼叫）
   *
   * ⚠️ 與信用卡 ECPG 不同：ATM/CVS 不需要前端 JS SDK + 3D 驗證，
   *    後端 GetToken → CreatePayment 即可取得付款指示。
   */
  async createAtmOrder(dto: {
    merchantTradeNo: string;
    totalAmount: number;
    consumerInfo: { Email: string; Phone: string };
    expireDate?: number;
  }): Promise<{ bankCode: string; vAccount: string; expireDate: string }> {
    // Step 1: GetTokenbyTrade（同 §8，但 OrderInfo 內加 ATM 參數）
    const tokenBody = buildEcpgRequest(
      this.merchantId,
      {
        OrderInfo: {
          MerchantTradeNo: dto.merchantTradeNo,
          TotalAmount: dto.totalAmount,
          ReturnURL: this.atmReturnUrl,
          ChoosePayment: { ATM: { ExpireDate: dto.expireDate ?? 3 } },
        },
        ConsumerInfo: dto.consumerInfo,
      },
      this.hashKey,
      this.hashIV,
    );

    const tokenRes = await fetch(this.tokenUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(tokenBody),
    });
    const tokenJson = await tokenRes.json() as { TransCode: number; Data: string };
    if (tokenJson.TransCode !== 1) throw new Error('GetToken failed');
    const tokenInner = JSON.parse(decodeURIComponent(aesDecrypt(tokenJson.Data, this.hashKey, this.hashIV)));
    const token = tokenInner.Token;

    // Step 2: CreatePayment（取得虛擬帳號）
    const paymentBody = buildEcpgRequest(
      this.merchantId,
      { MerchantTradeNo: dto.merchantTradeNo, Token: token },
      this.hashKey,
      this.hashIV,
    );
    const paymentRes = await fetch(this.paymentUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(paymentBody),
    });
    const paymentJson = await paymentRes.json() as { TransCode: number; Data: string };
    if (paymentJson.TransCode !== 1) throw new Error('CreatePayment failed');
    const paymentInner = JSON.parse(decodeURIComponent(aesDecrypt(paymentJson.Data, this.hashKey, this.hashIV)));

    // ⚠️ ATM 付款指示在 PaymentInfo 內（巢狀結構，不是頂層）
    return {
      bankCode: paymentInner.PaymentInfo.BankCode,
      vAccount: paymentInner.PaymentInfo.vAccount,
      expireDate: paymentInner.PaymentInfo.ExpireDate,
    };
  }

  // CVS / Barcode 同模式，差別：
  // CVS  → ChoosePayment: { CVS: { ExpireDate: 10080 } }（單位分鐘），回傳 PaymentNo + ExpireDate
  // BARCODE → ChoosePayment: { BARCODE: { ExpireDate: 7 } }（單位天），回傳 Barcode1/2/3 + ExpireDate
}
```

### §21.2 ATM/CVS 非同步 ReturnURL 處理

> 與信用卡 ECPG 的關鍵差異：消費者「取號」後立即收到 PaymentInfo，但「實際繳費」是後續異步事件。
> ReturnURL 在消費者**真正繳費後**才會收到（可能是幾小時或幾天後）。

直接使用 §10 的 `handleEcpgReturn()` 即可，邏輯一致。

---

## §22 Next.js 16 App Router 整合

> 對應 guide：`guides/02b-ecpg-atm-cvs-spa.md` §🖥️ SPA / Next.js 整合架構
> 本專案前端為 Next.js 16，路徑：`apps/web/app/(public)/[locale]/shop/checkout/`

### §22.1 OrderResultURL 結果頁（Next.js Server Component）

```typescript
// apps/web/app/(public)/[locale]/shop/checkout/result/page.tsx
import { redirect } from 'next/navigation';
import { headers } from 'next/headers';

interface CheckoutResultPageProps {
  searchParams: Promise<{ orderNo?: string; ResultData?: string }>;
}

export default async function CheckoutResultPage({ searchParams }: CheckoutResultPageProps) {
  const params = await searchParams;

  // 情境 A：從 OrderResultURL POST 跳轉（ResultData 在 query string）
  if (params.ResultData) {
    try {
      const result = JSON.parse(params.ResultData) as { MerchantTradeNo: string };
      redirect(`/shop/checkout/result?orderNo=${encodeURIComponent(result.MerchantTradeNo)}`);
    } catch {
      // ResultData 格式錯誤，視為失敗
      redirect('/shop/checkout/result?error=invalid_result_data');
    }
  }

  // 情境 B：直接帶 orderNo 訪問（已從訂單系統取得狀態）
  if (!params.orderNo) {
    redirect('/shop');
  }

  // 透過 API 查詢訂單狀態（後端走 ECPay queryTradeInfo）
  const order = await fetchOrderByMerchantTradeNo(params.orderNo);

  return <CheckoutResult order={order} />;
}
```

### §22.2 OrderResultURL 接收 Server Action（POST handler）

> ⚠️ Next.js App Router 預設不支援頁面接收 POST，需透過 Route Handler。

```typescript
// apps/web/app/api/v1/webhooks/ecpay/order-result/route.ts
import { NextRequest, NextResponse } from 'next/server';

export async function POST(req: NextRequest) {
  const formData = await req.formData();
  const resultData = formData.get('ResultData') as string;

  let merchantTradeNo: string;
  try {
    const parsed = JSON.parse(resultData) as { MerchantTradeNo: string };
    merchantTradeNo = parsed.MerchantTradeNo;
  } catch {
    return NextResponse.redirect(new URL('/shop/checkout/result?error=invalid', req.url));
  }

  // ⚠️ 不在這裡更新訂單狀態！訂單狀態以後端 ReturnURL（NestJS）為準。
  // 此 endpoint 只負責「把消費者導到結果頁」。
  return NextResponse.redirect(
    new URL(`/zh-TW/shop/checkout/result?orderNo=${encodeURIComponent(merchantTradeNo)}`, req.url)
  );
}
```

### §22.3 前端載入 ECPay JS SDK（信用卡 / Apple Pay）

```typescript
// apps/web/app/(public)/[locale]/shop/checkout/[token]/EcpayPaymentForm.tsx
'use client';

import Script from 'next/script';
import { useEffect, useRef, useState } from 'react';

interface EcpayPaymentFormProps {
  bindingToken: string;  // 從後端 §8 取得
  isProduction: boolean;
}

export function EcpayPaymentForm({ bindingToken, isProduction }: EcpayPaymentFormProps) {
  const [sdkReady, setSdkReady] = useState(false);
  const initialized = useRef(false);

  // ⚠️ 必須等三依賴都載入完才能 initialize
  // 順序：jQuery → node-forge → ECPay SDK
  useEffect(() => {
    if (sdkReady && !initialized.current) {
      // @ts-expect-error window.ECPay 由 SDK 注入
      window.ECPay.initialize(isProduction ? 'Prod' : 'Stage', 1, () => {
        // @ts-expect-error
        window.ECPay.createPayment(bindingToken);
      });
      initialized.current = true;
    }
  }, [sdkReady, isProduction, bindingToken]);

  return (
    <>
      <Script src="https://code.jquery.com/jquery-3.6.0.min.js" strategy="afterInteractive" />
      <Script src="https://cdn.jsdelivr.net/npm/node-forge@1.3.1/dist/forge.min.js" strategy="afterInteractive" />
      <Script
        src={isProduction
          ? 'https://ecpg.ecpay.com.tw/Scripts/sdk-1.0.0.js'
          : 'https://ecpg-stage.ecpay.com.tw/Scripts/sdk-1.0.0.js'}
        strategy="afterInteractive"
        onReady={() => setSdkReady(true)}
      />
      {/* ⚠️ id 必須為 ECPayPayment（SDK 硬編碼）*/}
      <div id="ECPayPayment" />
    </>
  );
}
```

### §22.4 後端 Controller 對應前端結帳流程

```typescript
// apps/api-gateway/src/commerce/checkout/checkout.controller.ts
import { Post, Body, Controller } from '@nestjs/common';

@Controller('v1/checkout/ecpay')
export class CheckoutEcpayController {
  constructor(private readonly ecpgService: EcpgService) {}

  /**
   * 前端建立訂單後，回傳 bindingToken 供 §22.3 使用
   */
  @Post('create-token')
  async createToken(@Body() dto: CreateCheckoutTokenDto) {
    const token = await this.ecpgService.getTokenbyTrade(dto);
    return { bindingToken: token };
  }
}
```

---

## §23 非信用卡幕後取號（GenPaymentCode）

> 對應 PHP：`Ecpg/CreateOrder.php`（含 ATM/CVS/Barcode 後端建單模式）
> 對應 guide：`guides/03-payment-backend.md` §非信用卡幕後取號
> 對應官方規格：`https://developers.ecpay.com.tw/27984.md`
> 端點：`ecpayment` domain

### §23.1 場景

純後台呼叫（無消費者前端互動），向 ECPay 取得：
- ATM 虛擬帳號
- CVS 超商代碼
- BARCODE 超商條碼

適用：訂閱續扣前送繳費單、企業帳款、會員儲值等不需綠界付款頁的場景。

### §23.2 NestJS 實作

```typescript
// ecpay-gen-payment-code.service.ts
import { Injectable } from '@nestjs/common';
import { aesDecrypt, buildEcpgRequest } from './ecpay-aes.util';

interface GenPaymentCodeDto {
  merchantTradeNo: string;
  totalAmount: number;
  itemName: string;
  paymentType: 'ATM' | 'CVS' | 'BARCODE';
  expireDate?: number;          // ATM:1-60天, CVS:分鐘(最大43200), BARCODE:1-30天
  paymentInfoUrl: string;       // 取號結果 callback
  returnUrl: string;            // 付款結果 callback
}

@Injectable()
export class EcpayGenPaymentCodeService {
  // ... constructor 同 EcpgService

  /**
   * 非信用卡幕後取號
   *
   * ⚠️ 與 §11 幕後授權的差異：
   * - §11 是信用卡（有 CardID 直接扣款）
   * - §23 是 ATM/CVS/BARCODE（取號後消費者自行繳費）
   *
   * ⚠️ 與 §21 ECPG ATM/CVS 的差異：
   * - §21 走 GetToken + CreatePayment（兩步驟，消費者前端介入）
   * - §23 走 GenPaymentCode（一步驟，純後台）
   */
  async genPaymentCode(dto: GenPaymentCodeDto): Promise<{
    paymentType: 'ATM' | 'CVS' | 'BARCODE';
    bankCode?: string;
    vAccount?: string;
    paymentNo?: string;
    barcode1?: string;
    barcode2?: string;
    barcode3?: string;
    expireDate: string;
  }> {
    const url = this.isProduction
      ? 'https://ecpayment.ecpay.com.tw/Merchant/GenPaymentCode'
      : 'https://ecpayment-stage.ecpay.com.tw/Merchant/GenPaymentCode';

    const body = buildEcpgRequest(
      this.merchantId,
      {
        MerchantTradeNo: dto.merchantTradeNo,
        TotalAmount: dto.totalAmount,
        ItemName: dto.itemName,
        ChoosePayment: dto.paymentType,
        ExpireDate: dto.expireDate ?? (dto.paymentType === 'CVS' ? 10080 : 3),
        PaymentInfoURL: dto.paymentInfoUrl,
        ReturnURL: dto.returnUrl,
      },
      this.hashKey,
      this.hashIV,
    );

    const response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });

    const json = await response.json() as { TransCode: number; Data: string };
    if (json.TransCode !== 1) throw new Error(`GenPaymentCode TransCode=${json.TransCode}`);

    const decrypted = aesDecrypt(json.Data, this.hashKey, this.hashIV);
    const innerData = JSON.parse(decodeURIComponent(decrypted)) as {
      RtnCode: number;
      RtnMsg: string;
      PaymentInfo: Record<string, string>;
    };

    if (innerData.RtnCode !== 1) {
      throw new Error(`GenPaymentCode failed: ${innerData.RtnCode} ${innerData.RtnMsg}`);
    }

    const info = innerData.PaymentInfo;
    return {
      paymentType: dto.paymentType,
      bankCode: info.BankCode,
      vAccount: info.vAccount,
      paymentNo: info.PaymentNo,
      barcode1: info.Barcode1,
      barcode2: info.Barcode2,
      barcode3: info.Barcode3,
      expireDate: info.ExpireDate,
    };
  }
}
```

### §23.3 取號結果通知 callback（與 §4 略有不同）

> ⚠️ 幕後取號的 PaymentInfoURL 是 **AES-JSON callback**（與 §4 AIO 的 form-urlencoded 不同！）

```typescript
@Post('webhooks/ecpay/gen-payment-code-info')
async handleGenPaymentCodeInfo(
  @Body() body: { TransCode: number; Data: string },
  @Res() res: Response,
): Promise<void> {
  if (body.TransCode !== 1) return res.status(200).send('0|TransCodeFailed');

  const decrypted = aesDecrypt(body.Data, this.hashKey, this.hashIV);
  const innerData = JSON.parse(decodeURIComponent(decrypted)) as {
    RtnCode: number;
    MerchantTradeNo: string;
    PaymentType: string;
    PaymentInfo: Record<string, string>;
  };

  // 儲存付款指示給後續對帳使用
  await this.ordersService.savePaymentInfo(innerData.MerchantTradeNo, {
    paymentType: innerData.PaymentType,
    ...innerData.PaymentInfo,
  });

  res.status(200).send('1|OK');
}
```

---

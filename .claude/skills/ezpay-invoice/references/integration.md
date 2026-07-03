# ezPay 電子發票 — NestJS / TypeScript 整合

對應 EZP_INVI_1.2.1 標準版。本檔提供在 zenbu-site（NestJS 11 + TypeORM 0.3）
`apps/api-gateway/src/commerce/` 下整合 ezPay 電子發票的實作指引。官方範例是
PHP / .NET C#，本檔將其轉為 Node.js / TypeScript。

> 以下程式碼為依官方規格推導的 TypeScript 實作；AES 參數、CheckCode 規則、
> API 路徑、欄位名稱均忠實對齊 PDF。

## 1. AES-256-CBC 加密 / 解密

ezPay 規定 blocksize 為 **32**，須自行補 PKCS#7，加密時關閉自動 padding。

```typescript
import * as crypto from 'crypto';

const BLOCK_SIZE = 32; // ezPay 規定 blocksize = 32（非 AES 標準 16）

/** PKCS#7 padding（blocksize 32） */
function addPkcs7Padding(buf: Buffer, blockSize = BLOCK_SIZE): Buffer {
  const pad = blockSize - (buf.length % blockSize);
  return Buffer.concat([buf, Buffer.alloc(pad, pad)]);
}

function removePkcs7Padding(buf: Buffer): Buffer {
  const pad = buf[buf.length - 1];
  return buf.subarray(0, buf.length - pad);
}

/**
 * 加密 PostData_：query string → PKCS#7(32) → AES-256-CBC → 小寫 hex
 * hashKey 必須 32 bytes、hashIv 必須 16 bytes。
 */
export function encryptPostData(
  params: Record<string, string | number>,
  hashKey: string,
  hashIv: string,
): string {
  // 1. 組 query string（值需 url encode；與 PHP http_build_query 一致）
  const queryString = new URLSearchParams(
    Object.fromEntries(
      Object.entries(params).map(([k, v]) => [k, String(v)]),
    ),
  ).toString();

  // 2. 補 PKCS#7 padding（blocksize 32）
  const padded = addPkcs7Padding(Buffer.from(queryString, 'utf8'));

  // 3. AES-256-CBC，關閉自動 padding（padding 已自行補）
  const cipher = crypto.createCipheriv('aes-256-cbc', hashKey, hashIv);
  cipher.setAutoPadding(false);
  const encrypted = Buffer.concat([cipher.update(padded), cipher.final()]);

  return encrypted.toString('hex'); // 小寫 hex
}

/** 解密（驗證 / debug 用） */
export function decryptPostData(
  hex: string,
  hashKey: string,
  hashIv: string,
): string {
  const decipher = crypto.createDecipheriv('aes-256-cbc', hashKey, hashIv);
  decipher.setAutoPadding(false);
  const decrypted = Buffer.concat([
    decipher.update(Buffer.from(hex, 'hex')),
    decipher.final(),
  ]);
  return removePkcs7Padding(decrypted).toString('utf8');
}
```

## 2. CheckCode 回應驗證（SHA256）

```typescript
import * as crypto from 'crypto';

/**
 * 計算回應 CheckCode：取 5 欄位 → A~Z 排序 → 前後加 HashIV/HashKey → SHA256 大寫
 */
export function computeCheckCode(
  result: {
    InvoiceTransNo: string;
    MerchantID: string;
    MerchantOrderNo: string;
    RandomNum: string;
    TotalAmt: string | number;
  },
  hashKey: string,
  hashIv: string,
): string {
  // A~Z 排序的 5 個欄位
  const sorted = ['InvoiceTransNo', 'MerchantID', 'MerchantOrderNo', 'RandomNum', 'TotalAmt']
    .sort()
    .map((k) => `${k}=${String((result as Record<string, unknown>)[k])}`)
    .join('&');

  const raw = `HashIV=${hashIv}&${sorted}&HashKey=${hashKey}`;
  return crypto.createHash('sha256').update(raw, 'utf8').digest('hex').toUpperCase();
}

/** 用 timing-safe 比對驗證回應 CheckCode */
export function verifyCheckCode(
  result: Parameters<typeof computeCheckCode>[0] & { CheckCode: string },
  hashKey: string,
  hashIv: string,
): boolean {
  const expected = computeCheckCode(result, hashKey, hashIv);
  const a = Buffer.from(expected);
  const b = Buffer.from(result.CheckCode ?? '');
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}
```

## 3. EzpayInvoiceService 骨架

依 zenbu-site 慣例：注入 `ConfigService`（禁直讀 `process.env`），HTTP 用
標準 fetch / undici。設定（網域 / MerchantID / HashKey / HashIV）建議走 settings
資料表（per-tenant）；下例以 ConfigService 示意。

```typescript
import { Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { encryptPostData, verifyCheckCode } from './ezpay-crypto';

interface EzpayConfig {
  baseUrl: string;   // https://cinv.ezpay.com.tw（測試）/ https://inv.ezpay.com.tw（正式）
  merchantId: string;
  hashKey: string;   // 32 bytes
  hashIv: string;    // 16 bytes
}

@Injectable()
export class EzpayInvoiceService {
  private readonly logger = new Logger(EzpayInvoiceService.name);

  constructor(private readonly config: ConfigService) {}

  private getConfig(): EzpayConfig {
    const isProd = this.config.get('EZPAY_ENV') === 'production';
    return {
      baseUrl: isProd ? 'https://inv.ezpay.com.tw' : 'https://cinv.ezpay.com.tw',
      merchantId: this.config.getOrThrow<string>('EZPAY_MERCHANT_ID'),
      hashKey: this.config.getOrThrow<string>('EZPAY_HASH_KEY'),
      hashIv: this.config.getOrThrow<string>('EZPAY_HASH_IV'),
    };
  }

  /** 通用 POST：組 PostData_ → form post → parse JSON 回應 */
  private async post(
    path: string,
    bizParams: Record<string, string | number>,
  ): Promise<{ Status: string; Message: string; Result: string }> {
    const cfg = this.getConfig();
    const postData = encryptPostData(bizParams, cfg.hashKey, cfg.hashIv);

    const body = new URLSearchParams({
      MerchantID_: cfg.merchantId,  // 注意底線
      PostData_: postData,          // 注意底線
    });

    const res = await fetch(`${cfg.baseUrl}${path}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    });

    const json = (await res.json()) as { Status: string; Message: string; Result: string };
    // 注意：不要在 logger 印 hashKey/hashIv/postData 全文
    this.logger.debug(`ezPay ${path} -> ${json.Status}`);
    return json;
  }

  /** 開立發票（即時，B2C 範例） */
  async issueInvoice(input: {
    merchantOrderNo: string;
    buyerName: string;
    buyerEmail?: string;
    category: 'B2B' | 'B2C';
    buyerUbn?: string;        // B2B 必填
    items: { name: string; count: number; unit: string; price: number; amt: number }[];
    amt: number;             // 銷售額（未稅）
    taxAmt: number;          // 稅額
    totalAmt: number;        // 含稅總額
    carrierType?: '0' | '1' | '2';
    carrierNum?: string;
    loveCode?: string;
    printFlag: 'Y' | 'N';
  }): Promise<{ invoiceNumber: string; invoiceTransNo: string; randomNum: string }> {
    const bizParams: Record<string, string | number> = {
      RespondType: 'JSON',
      Version: '1.5',                                  // 開立發票固定 1.5
      TimeStamp: Math.floor(Date.now() / 1000),        // Unix 秒
      MerchantOrderNo: input.merchantOrderNo,
      Status: '1',                                     // 1=即時開立
      Category: input.category,
      BuyerName: input.buyerName,
      ...(input.buyerUbn ? { BuyerUBN: input.buyerUbn } : {}),
      ...(input.buyerEmail ? { BuyerEmail: input.buyerEmail } : {}),
      ...(input.carrierType ? { CarrierType: input.carrierType } : {}),
      ...(input.carrierNum ? { CarrierNum: encodeURIComponent(input.carrierNum) } : {}),
      ...(input.loveCode ? { LoveCode: input.loveCode } : {}),
      PrintFlag: input.printFlag,
      TaxType: '1',
      TaxRate: '5',
      Amt: input.amt,
      TaxAmt: input.taxAmt,
      TotalAmt: input.totalAmt,
      ItemName: input.items.map((i) => i.name).join('|'),
      ItemCount: input.items.map((i) => i.count).join('|'),
      ItemUnit: input.items.map((i) => i.unit).join('|'),
      ItemPrice: input.items.map((i) => i.price).join('|'),
      ItemAmt: input.items.map((i) => i.amt).join('|'),
    };

    const res = await this.post('/Api/invoice_issue', bizParams);
    if (res.Status !== 'SUCCESS') {
      // res.Status 為錯誤代碼，見 error-codes.md
      throw new Error(`ezPay invoice_issue failed: ${res.Status} ${res.Message}`);
    }

    const result = JSON.parse(res.Result);
    const cfg = this.getConfig();
    if (!verifyCheckCode(result, cfg.hashKey, cfg.hashIv)) {
      throw new Error('ezPay response CheckCode mismatch');
    }
    return {
      invoiceNumber: result.InvoiceNumber,    // 只有 Status=1 才有
      invoiceTransNo: result.InvoiceTransNo,
      randomNum: result.RandomNum,
    };
  }

  /** 作廢發票 */
  async invalidInvoice(invoiceNumber: string, reason: string): Promise<void> {
    const res = await this.post('/Api/invoice_invalid', {
      RespondType: 'JSON',
      Version: '1.0',                          // 作廢固定 1.0
      TimeStamp: Math.floor(Date.now() / 1000),
      InvoiceNumber: invoiceNumber,
      InvalidReason: reason,                   // 限中文 6 字 / 英文 20 字
    });
    if (res.Status !== 'SUCCESS') {
      throw new Error(`ezPay invoice_invalid failed: ${res.Status} ${res.Message}`);
    }
  }

  /** 查詢發票（用發票號碼 + 隨機碼） */
  async searchInvoice(invoiceNumber: string, randomNum: string) {
    const res = await this.post('/Api/invoice_search', {
      RespondType: 'JSON',
      Version: '1.3',                          // 查詢固定 1.3
      TimeStamp: Math.floor(Date.now() / 1000),
      SearchType: '0',                         // 0=發票號碼+隨機碼
      InvoiceNumber: invoiceNumber,
      RandomNum: randomNum,
    });
    if (res.Status !== 'SUCCESS') {
      throw new Error(`ezPay invoice_search failed: ${res.Status} ${res.Message}`);
    }
    return JSON.parse(res.Result); // 含 UploadStatus / InvoiceStatus / ItemDetail
  }
}
```

## 4. Version 欄位速查（不可填錯）

每支 API 的 `PostData_` 內 `Version` 欄位固定值：

| API | path | Version |
|-----|------|---------|
| 開立發票 | `/Api/invoice_issue` | `1.5` |
| 觸發開立發票 | `/Api/invoice_touch_issue` | `1.0` |
| 作廢發票 | `/Api/invoice_invalid` | `1.0` |
| 開立折讓 | `/Api/allowance_issue` | `1.3` |
| 觸發確認/取消折讓 | `/Api/allowance_touch_issue` | `1.0` |
| 作廢折讓 | `/Api/allowanceInvalid` | `1.0` |
| 查詢發票 | `/Api/invoice_search` | `1.3` |

## 5. 錯誤處理

- 回應 `Status !== 'SUCCESS'` 時，`Status` 即錯誤代碼（見 error-codes.md）。
- `KEY10002`（解密錯誤）→ 多為 AES 實作問題，**不要重試**，檢查 padding / 金鑰。
- `NOR10001` / `KEY10014`（網路 / timeout）→ 可重試；重試時用**完全相同的
  `PostData_`**（平台對相同 `PostData_` 冪等回原發票，不會重複開立）。
- `LIB10003`（自訂編號重覆）→ `MerchantOrderNo` 已用過，需查既有發票或換編號。

## 6. 安全與隱私

- **不要在 logger 印** `HashKey` / `HashIV` / `PostData_` 全文 / 買受人 PII
  （email / 統編 / 姓名 / 地址）。logger 只記 path、`Status`、`Message`、自訂編號。
- CheckCode 比對用 `crypto.timingSafeEqual`（已在 §2 實作），先做 length guard。
- `HashKey` / `HashIV` 透過 ConfigService 或 settings 資料表讀取，**禁止 hardcode**
  （見 `.claude/rules/config-service.rule.md`）。

## 7. zenbu-site commerce 對齊建議

- ezPay 電子發票屬「訂單後處理」，建議在訂單 `paid` 後（金流 webhook 確認付款）
  才觸發開立——與 `commerce/payments/` 的訂單金錢計算順序解耦。
- `MerchantOrderNo` 建議用訂單編號（或訂單編號 + 後綴），確保同商店唯一且可追溯。
- 開立成功後將 `invoiceNumber` / `invoiceTransNo` / `randomNum` 存到訂單相關 entity，
  供後續查詢 / 作廢 / 折讓使用。
- 退貨流程對應 `allowance_issue`（開折讓）；整張取消對應 `invoice_invalid`（作廢）。
  作廢有期限（奇數月 14 日前、前兩個月），跨期只能走折讓。
- 財政部上傳是非同步——若需確認上傳結果，可排程（cron）用 `invoice_search` 查
  `UploadStatus`，與 zenbu-site 既有 cron 機制（`@nestjs/schedule`）整合。
- ezPay 電子發票與既有 NewebPay 金流同屬藍新集團——若專案已整合 NewebPay
  （見 `newebpay-mpg` skill），ezPay 發票是獨立服務、獨立 HashKey/HashIV，
  **不可共用** NewebPay 金流的金鑰。

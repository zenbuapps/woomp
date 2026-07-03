# PAYUNi UPP V2 — NestJS 11 + TypeScript 整合範例

> 本專案技術棧：NestJS 11 + Express 5 + TypeScript 5.4.5 + Node 20 + class-validator + class-transformer + Zod 3。
> 對齊 `.claude/rules/nestjs-backend.rule.md` 的模組樣板。

## TOC

- [模組結構](#模組結構)
- [環境變數](#環境變數)
- [PayuniCrypto Utility](#payunicrypto-utility)
- [PayuniService（核心業務邏輯）](#payuniservice核心業務邏輯)
- [PayuniController（建立交易 + Notify Webhook）](#payunicontroller建立交易--notify-webhook)
- [DTO（class-validator）](#dtoclass-validator)
- [Zod schema 替代驗證 DTO](#zod-schema-替代驗證-dto)
- [Module 註冊](#module-註冊)
- [Express body-parser 設定（必要）](#express-body-parser-設定必要)
- [單元測試（Jest）](#單元測試jest)
- [常見整合陷阱](#常見整合陷阱)

---

## 模組結構

依專案慣例：

```
apps/api-gateway/src/commerce/payments/payuni/
├── payuni.module.ts
├── payuni.service.ts
├── payuni.service.spec.ts
├── payuni-crypto.util.ts
├── payuni.controller.ts          # NotifyURL webhook handler
├── admin-payuni.controller.ts    # Admin 操作（查詢/退款/取消）
└── dto/
    ├── create-payuni-payment.dto.ts
    ├── refund-payuni.dto.ts
    └── payuni-notify.dto.ts
```

## 環境變數

```env
# .env
PAYUNI_MER_ID=your_merchant_id
PAYUNI_HASH_KEY=your_32_char_hash_key__________  # 必須 32 字元
PAYUNI_HASH_IV=your_16char_iv__                  # 必須 16 字元
PAYUNI_API_BASE=https://sandbox-api.payuni.com.tw   # production: https://api.payuni.com.tw
PAYUNI_NOTIFY_URL=https://yourdomain.com/v1/payments/payuni/notify
PAYUNI_RETURN_URL=https://yourdomain.com/checkout/result
```

## PayuniCrypto Utility

完整實作見 `references/encryption.md`。要點：

- 用 Node.js `crypto` 模組 (`createCipheriv` / `createDecipheriv` / `createHash`)
- AES-256-GCM，IV = 16 bytes
- HashInfo = `SHA256(hashKey + EncryptInfo + hashIv).toUpperCase()`
- `verifyHashInfo` 用 `crypto.timingSafeEqual` 防 timing attack

## PayuniService（核心業務邏輯）

```typescript
// apps/api-gateway/src/commerce/payments/payuni/payuni.service.ts
import { Injectable, BadRequestException, Logger } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import { HttpService } from "@nestjs/axios";
import { firstValueFrom } from "rxjs";
import { PayuniCrypto } from "./payuni-crypto.util";

interface CreatePaymentInput {
  merTradeNo: string;
  tradeAmt: number;
  prodDesc: string;
  email?: string;
  paymentMethods?: ("Credit" | "ATM" | "CVS" | "ApplePay" | "GooglePay" | "LinePay" | "JKoPay")[];
  installmentTerms?: number[]; // [3, 6, 12]
}

interface FormPostFields {
  action: string;
  fields: {
    MerID: string;
    Version: "2.0";
    EncryptInfo: string;
    HashInfo: string;
  };
}

@Injectable()
export class PayuniService {
  private readonly logger = new Logger(PayuniService.name);
  private readonly crypto: PayuniCrypto;
  private readonly merID: string;
  private readonly baseUrl: string;
  private readonly notifyUrl: string;
  private readonly returnUrl: string;

  constructor(
    private readonly config: ConfigService,
    private readonly http: HttpService,
  ) {
    this.merID = this.config.getOrThrow<string>("PAYUNI_MER_ID");
    this.baseUrl = this.config.getOrThrow<string>("PAYUNI_API_BASE");
    this.notifyUrl = this.config.getOrThrow<string>("PAYUNI_NOTIFY_URL");
    this.returnUrl = this.config.getOrThrow<string>("PAYUNI_RETURN_URL");
    this.crypto = new PayuniCrypto(
      this.config.getOrThrow<string>("PAYUNI_HASH_KEY"),
      this.config.getOrThrow<string>("PAYUNI_HASH_IV"),
    );
  }

  /**
   * 建立 UPP 付款導向（回傳 form action + fields，由前端 auto-submit）
   */
  createPayment(input: CreatePaymentInput): FormPostFields {
    const params: Record<string, string | number | undefined> = {
      MerID: this.merID,
      MerTradeNo: input.merTradeNo,
      TradeAmt: input.tradeAmt,
      Timestamp: Math.floor(Date.now() / 1000),
      ProdDesc: input.prodDesc,
      ReturnURL: this.returnUrl,
      NotifyURL: this.notifyUrl,
      UsrMail: input.email,
    };

    // 啟用指定支付方式
    for (const method of input.paymentMethods ?? []) {
      params[method] = 1;
    }

    // 信用卡分期
    if (input.installmentTerms?.length) {
      params.CreditInst = input.installmentTerms.join(",");
    }

    const encryptInfo = this.crypto.encrypt(params);
    return {
      action: `${this.baseUrl}/api/upp`,
      fields: {
        MerID: this.merID,
        Version: "2.0",
        EncryptInfo: encryptInfo,
        HashInfo: this.crypto.generateHashInfo(encryptInfo),
      },
    };
  }

  /**
   * 處理 NotifyURL Webhook：驗章 + 解密 + 回傳內層
   * @throws BadRequestException 若 HashInfo 不一致或外層 Status === "ERROR"
   */
  processNotify(body: Record<string, string>): Record<string, string> {
    if (body.Status === "ERROR" || !body.EncryptInfo || !body.HashInfo) {
      this.logger.warn("PAYUNi notify with ERROR status or missing fields", { body });
      throw new BadRequestException("Invalid PAYUNi notify body");
    }
    if (!this.crypto.verifyHashInfo(body.EncryptInfo, body.HashInfo)) {
      this.logger.error("PAYUNi notify HashInfo mismatch", { merTradeNo: body.MerID });
      throw new BadRequestException("HashInfo mismatch — possible tampering");
    }
    return this.crypto.decrypt(body.EncryptInfo);
  }

  /**
   * 交易查詢（Version 2.0）
   */
  async queryTrade(opts: { tradeNo?: string; merTradeNo?: string }) {
    if (!opts.tradeNo && !opts.merTradeNo) {
      throw new BadRequestException("tradeNo or merTradeNo required");
    }
    return this.callApi("/api/trade/query", { ...opts, Timestamp: Math.floor(Date.now() / 1000) }, "2.0");
  }

  /**
   * 信用卡退款（CloseType=2）
   * @param tradeAmt 部分退款時帶入；全額退款不帶
   */
  async refund(tradeNo: string, tradeAmt?: number) {
    const params: Record<string, string | number> = {
      TradeNo: tradeNo,
      CloseType: 2,
      Timestamp: Math.floor(Date.now() / 1000),
    };
    if (tradeAmt) params.TradeAmt = tradeAmt;
    return this.callApi("/api/trade/close", params, "1.0");
  }

  /**
   * 信用卡請款（CloseType=1，僅在商店設為手動請款時需要）
   */
  async capture(tradeNo: string) {
    return this.callApi(
      "/api/trade/close",
      {
        TradeNo: tradeNo,
        CloseType: 1,
        Timestamp: Math.floor(Date.now() / 1000),
      },
      "1.0",
    );
  }

  /**
   * 取消授權（尚未請款的交易）
   */
  async cancelAuth(tradeNo: string) {
    return this.callApi(
      "/api/trade/cancel",
      {
        TradeNo: tradeNo,
        Timestamp: Math.floor(Date.now() / 1000),
      },
      "1.0",
    );
  }

  /**
   * 取消超商代碼（在繳費前可取消）
   */
  async cancelCvs(payNo: string) {
    return this.callApi(
      "/api/cancel_cvs",
      {
        PayNo: payNo,
        Timestamp: Math.floor(Date.now() / 1000),
      },
      "1.0",
    );
  }

  // 共用 API 呼叫
  private async callApi(
    path: string,
    encryptParams: Record<string, string | number | undefined>,
    version: string,
  ): Promise<Record<string, string>> {
    const params = { MerID: this.merID, ...encryptParams };
    const encryptInfo = this.crypto.encrypt(params);
    const body = new URLSearchParams({
      MerID: this.merID,
      Version: version,
      EncryptInfo: encryptInfo,
      HashInfo: this.crypto.generateHashInfo(encryptInfo),
    });

    const url = `${this.baseUrl}${path}`;
    this.logger.debug(`PAYUNi POST ${url}`, { merTradeNo: encryptParams.MerTradeNo });

    const { data } = await firstValueFrom(
      this.http.post(url, body.toString(), {
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
          "User-Agent": "payuni",
        },
      }),
    );

    if (data.Status === "ERROR" || !data.EncryptInfo) {
      this.logger.error(`PAYUNi API error`, { path, status: data.Status, message: data.Message });
      throw new BadRequestException(`PAYUNi API error: ${data.Status} ${data.Message ?? ""}`);
    }

    if (!this.crypto.verifyHashInfo(data.EncryptInfo, data.HashInfo)) {
      throw new BadRequestException("PAYUNi response HashInfo mismatch");
    }

    return this.crypto.decrypt(data.EncryptInfo);
  }
}
```

## PayuniController（建立交易 + Notify Webhook）

```typescript
// apps/api-gateway/src/commerce/payments/payuni/payuni.controller.ts
import { Body, Controller, Post, HttpCode, HttpStatus, Logger } from "@nestjs/common";
import { PayuniService } from "./payuni.service";
import { CreatePayuniPaymentDto } from "./dto/create-payuni-payment.dto";

@Controller("v1/payments/payuni")
export class PayuniController {
  private readonly logger = new Logger(PayuniController.name);

  constructor(private readonly payuni: PayuniService) {}

  @Post("create")
  createPayment(@Body() dto: CreatePayuniPaymentDto) {
    return this.payuni.createPayment(dto);
  }

  /**
   * NotifyURL Webhook
   * PAYUNi POST application/x-www-form-urlencoded
   * 必須回應 "1" 或 200 OK，否則 PAYUNi 會 retry。
   */
  @Post("notify")
  @HttpCode(HttpStatus.OK)
  async handleNotify(@Body() body: Record<string, string>): Promise<string> {
    try {
      const result = this.payuni.processNotify(body);
      this.logger.log("PAYUNi notify received", {
        merTradeNo: result.MerTradeNo,
        tradeNo: result.TradeNo,
        tradeStatus: result.TradeStatus,
        paymentType: result.PaymentType,
      });

      // TODO: 依 result.TradeStatus 更新訂單狀態
      // 1=已付款, 0=取號成功, 2=失敗, 3=取消, 8=待確認

      // PAYUNi 期待回應 "1" 表示已收到
      return "1";
    } catch (err) {
      this.logger.error("PAYUNi notify processing failed", err);
      // 故意回 200 + 錯誤訊息，讓 PAYUNi 不要 retry（因為 HashInfo 錯誤代表攻擊或設定錯，retry 沒用）
      return "0";
    }
  }
}
```

## DTO（class-validator）

```typescript
// apps/api-gateway/src/commerce/payments/payuni/dto/create-payuni-payment.dto.ts
import {
  IsString, IsNumber, IsEmail, IsOptional, Matches, Length, Min, Max, IsArray, IsIn, ArrayUnique
} from "class-validator";

export class CreatePayuniPaymentDto {
  @IsString()
  @Length(1, 25)
  @Matches(/^[A-Za-z0-9_-]+$/, { message: "MerTradeNo 只能含 [A-Za-z0-9_-]" })
  merTradeNo: string;

  @IsNumber()
  @Min(1)
  @Max(199_999)
  tradeAmt: number;

  @IsString()
  @Length(1, 550)
  prodDesc: string;

  @IsOptional()
  @IsEmail()
  email?: string;

  @IsOptional()
  @IsArray()
  @ArrayUnique()
  @IsIn(["Credit", "ATM", "CVS", "ApplePay", "GooglePay", "SamsungPay", "LinePay", "JKoPay", "Aftee", "ICash"], {
    each: true,
  })
  paymentMethods?: string[];

  @IsOptional()
  @IsArray()
  @IsIn([3, 6, 9, 12, 18, 24, 30], { each: true })
  installmentTerms?: number[];
}
```

> 對應金額限制：信用卡 1~199,999；ATM 15~49,999；CVS 30~20,000。實作上請依商店啟用之支付工具動態檢查。

## Zod schema 替代驗證 DTO

如專案前端以 Zod schema 驗證表單，後端可同樣定義並用 `nestjs-zod` 或自訂 pipe：

```typescript
// apps/api-gateway/src/commerce/payments/payuni/dto/payuni.schema.ts
import { z } from "zod";

export const CreatePayuniPaymentSchema = z.object({
  merTradeNo: z.string().min(1).max(25).regex(/^[A-Za-z0-9_-]+$/),
  tradeAmt: z.number().int().min(1).max(199_999),
  prodDesc: z.string().min(1).max(550),
  email: z.string().email().optional(),
  paymentMethods: z
    .array(z.enum(["Credit", "ATM", "CVS", "ApplePay", "GooglePay", "SamsungPay", "LinePay", "JKoPay", "Aftee", "ICash"]))
    .optional(),
  installmentTerms: z.array(z.union([z.literal(3), z.literal(6), z.literal(9), z.literal(12), z.literal(18), z.literal(24), z.literal(30)])).optional(),
});

export type CreatePayuniPaymentInput = z.infer<typeof CreatePayuniPaymentSchema>;
```

## Module 註冊

```typescript
// apps/api-gateway/src/commerce/payments/payuni/payuni.module.ts
import { Module } from "@nestjs/common";
import { ConfigModule } from "@nestjs/config";
import { HttpModule } from "@nestjs/axios";
import { PayuniController } from "./payuni.controller";
import { PayuniService } from "./payuni.service";

@Module({
  imports: [ConfigModule, HttpModule.register({ timeout: 30_000 })],
  controllers: [PayuniController],
  providers: [PayuniService],
  exports: [PayuniService],
})
export class PayuniModule {}
```

> 在 `apps/api-gateway/src/app.module.ts` 的 `imports` 陣列中加入 `PayuniModule`。

## Express body-parser 設定（必要）

PAYUNi NotifyURL 是 `application/x-www-form-urlencoded`。NestJS 11 + Express 5 預設 **不會** 自動 parse `urlencoded` body。在 `main.ts` 加入：

```typescript
// apps/api-gateway/src/main.ts
import { NestFactory } from "@nestjs/core";
import { json, urlencoded } from "express";
import { AppModule } from "./app.module";

async function bootstrap() {
  const app = await NestFactory.create(AppModule, { rawBody: true });
  app.use(json({ limit: "1mb" }));
  app.use(urlencoded({ extended: true, limit: "1mb" })); // ← PAYUNi notify 必要

  // ...其他 ValidationPipe / CORS / Swagger 設定
  await app.listen(6001);
}
bootstrap();
```

## 單元測試（Jest）

```typescript
// apps/api-gateway/src/commerce/payments/payuni/payuni.service.spec.ts
import { Test } from "@nestjs/testing";
import { ConfigService } from "@nestjs/config";
import { HttpService } from "@nestjs/axios";
import { PayuniService } from "./payuni.service";
import { of } from "rxjs";

describe("PayuniService", () => {
  let service: PayuniService;
  const httpMock = {
    post: jest.fn(),
  };

  beforeEach(async () => {
    const moduleRef = await Test.createTestingModule({
      providers: [
        PayuniService,
        {
          provide: ConfigService,
          useValue: {
            getOrThrow: (k: string) =>
              ({
                PAYUNI_MER_ID: "TEST_MER",
                PAYUNI_HASH_KEY: "12345678901234567890123456789012",
                PAYUNI_HASH_IV: "1234567890123456",
                PAYUNI_API_BASE: "https://sandbox-api.payuni.com.tw",
                PAYUNI_NOTIFY_URL: "https://x.test/notify",
                PAYUNI_RETURN_URL: "https://x.test/return",
              }[k] ?? ""),
          },
        },
        { provide: HttpService, useValue: httpMock },
      ],
    }).compile();

    service = moduleRef.get(PayuniService);
  });

  it("createPayment 應產生有效的 form action + fields", () => {
    const result = service.createPayment({
      merTradeNo: "TEST123",
      tradeAmt: 100,
      prodDesc: "test",
      paymentMethods: ["Credit", "ATM"],
    });

    expect(result.action).toBe("https://sandbox-api.payuni.com.tw/api/upp");
    expect(result.fields.Version).toBe("2.0");
    expect(result.fields.MerID).toBe("TEST_MER");
    expect(result.fields.EncryptInfo).toMatch(/^[a-f0-9]+$/);
    expect(result.fields.HashInfo).toMatch(/^[A-F0-9]{64}$/);
  });

  it("processNotify 應拒絕 HashInfo 不一致的 body", () => {
    expect(() =>
      service.processNotify({
        Status: "SUCCESS",
        MerID: "TEST_MER",
        Version: "2.0",
        EncryptInfo: "abcd1234",
        HashInfo: "WRONG_HASH",
      }),
    ).toThrow();
  });

  it("queryTrade 必須帶 tradeNo 或 merTradeNo", async () => {
    await expect(service.queryTrade({})).rejects.toThrow();
  });

  it("refund 部分退款應帶 TradeAmt", async () => {
    httpMock.post.mockReturnValue(
      of({
        data: {
          Status: "SUCCESS",
          MerID: "TEST_MER",
          Version: "1.0",
          EncryptInfo: "PLACEHOLDER",
          HashInfo: "PLACEHOLDER",
        },
      }),
    );
    // 因 EncryptInfo/HashInfo 是 placeholder，這個測試只能用 spy 驗證 callApi 參數
    // 實務上會用 mock crypto 或 fixture 比對
  });
});
```

## 常見整合陷阱

1. **`apps/web` 的 `redirect` Form POST**：UPP 是 server-side Form POST。前端要產生隱藏 form 並 auto-submit：
   ```tsx
   useEffect(() => {
     const form = document.createElement("form");
     form.method = "POST";
     form.action = paymentInit.action;
     for (const [k, v] of Object.entries(paymentInit.fields)) {
       const input = document.createElement("input");
       input.type = "hidden";
       input.name = k;
       input.value = v;
       form.appendChild(input);
     }
     document.body.appendChild(form);
     form.submit();
   }, [paymentInit]);
   ```
2. **NotifyURL 必須回應 "1" 或 200 OK**：否則 PAYUNi 會 retry（避免重複處理需 idempotency key）。
3. **NotifyURL 必須是 HTTPS（443）或 HTTP（80）**：自訂 port 不行。本地測試用 cloudflare tunnel。
4. **`Decimal.js` / 金額精度**：`TradeAmt` 必須整數（無小數），新台幣以「元」為單位。
5. **重試 + idempotency**：NotifyURL 可能被 PAYUNi 重複呼叫（因網路或我們回 ≠ "1"），DB 訂單更新要用 `MerTradeNo` 或 `TradeNo` 作為唯一 key 避免重複扣款。
6. **Sandbox 與 Production 環境變數切換**：CI/CD 部署要確保 `.env.production` 的 `PAYUNI_API_BASE`、`PAYUNI_HASH_KEY`、`PAYUNI_HASH_IV` 是 production 值。
7. **與 ECPay/NewebPay 並行**：本專案已有 NewebPay；若同時整合 PAYUNi，命名要清楚分開模組（如 `commerce/payments/payuni/`、`commerce/payments/newebpay/`），各自一個 service / controller / module。

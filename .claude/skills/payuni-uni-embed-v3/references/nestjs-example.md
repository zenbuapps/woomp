# PAYUNi UNi Embed V3 — NestJS / TypeScript 整合範例

> 對應本專案 `apps/api-gateway/src/commerce/payments/payuni/`（已有 UPP 服務 + crypto helper）。
> UNi Embed 與 UPP 共用同一組 `MerID / HashKey / HashIV` 與加密 helper。

## TOC

- [Crypto Helper（共用 UPP）](#crypto-helper共用-upp)
- [DTO](#dto)
- [Service](#service)
- [Controller](#controller)
- [Module 註冊](#module-註冊)
- [Webhook 驗簽流程](#webhook-驗簽流程)
- [E2E 測試對位](#e2e-測試對位)

---

## Crypto Helper（共用 UPP）

直接 import 既有的 `payuni-crypto.ts`：

```ts
// apps/api-gateway/src/commerce/payments/payuni/payuni-crypto.ts（已存在）
import {
  encryptPayuni,
  decryptPayuni,
  hashInfoPayuni,
  verifyPayuniHash,
  PAYUNI_HOSTS,
  PayuniMode,
} from '../payuni/payuni-crypto';
```

**核心契約**（與 UPP 完全相同）：
- `encryptPayuni(params, hashKey, hashIv)` → `EncryptInfo` (hex string)
- `decryptPayuni(encryptInfo, hashKey, hashIv)` → `Record<string, string>`
- `hashInfoPayuni(hashKey, encryptInfo, hashIv)` → SHA256 大寫 hex
- `verifyPayuniHash(...)` → 用 `crypto.timingSafeEqual` 防 timing attack

---

## DTO

```ts
// apps/api-gateway/src/commerce/payments/payuni-uni-embed/dto/create-uni-token.dto.ts
import { IsString, IsOptional, IsInt, IsIn, MaxLength, Matches, IsUrl } from 'class-validator';

export class CreateUniTokenDto {
  /** 限定使用元件之網域（含 https://），與當前頁面 origin 必須一致 */
  @IsUrl({ require_protocol: true, protocols: ['https'] })
  @MaxLength(253)
  iframeDomain!: string;

  /** 信用卡 Token 類型（記憶卡號 / 約定 / 強制約定） */
  @IsOptional()
  @IsInt()
  @IsIn([1, 2, 3])
  useTokenType?: 1 | 2 | 3;

  /**
   * 付款人綁定識別（會員編號 / Email / 手機）
   *
   * 官方原文（UNi Embed V3 主文件）：
   *   長度限制: 150
   *   格式: [A-Z a-z 0-9 @.#$%_-]
   * 字符之間的空格僅為視覺分隔，實際字元集為：A-Z / a-z / 0-9 / @ . # $ % _ -
   * 不含空白字元。
   */
  @IsOptional()
  @IsString()
  @MaxLength(150)
  @Matches(/^[A-Za-z0-9@.#$%_-]+$/)
  creditToken?: string;

  /** Token 紀錄類型 1=會員 / 2=商店 */
  @IsOptional()
  @IsInt()
  @IsIn([1, 2])
  creditTokenType?: 1 | 2;
}
```

```ts
// apps/api-gateway/src/commerce/payments/payuni-uni-embed/dto/authorize-uni-trade.dto.ts
import { IsString, IsInt, Min, Max, MaxLength, Matches, IsOptional, IsUrl, IsEmail, IsIn } from 'class-validator';

export class AuthorizeUniTradeDto {
  /** SDK_TOKEN，由 token_get 階段取得；後端必須驗證為自己核發的合法 token */
  @IsString()
  sdkToken!: string;

  /** 商店訂單編號（由前端傳回 / 後端產生），10 分鐘內不可重複 */
  @IsString()
  @MaxLength(25)
  @Matches(/^[A-Za-z0-9_-]+$/)
  merTradeNo!: string;

  /** 訂單金額（後端必須以購物車本地金額為準，不可信前端） */
  @IsInt()
  @Min(1)
  @Max(199_999)
  tradeAmt!: number;

  @IsString()
  @MaxLength(550)
  prodDesc!: string;

  @IsOptional()
  @IsUrl()
  returnUrl?: string;

  @IsOptional()
  @IsUrl()
  notifyUrl?: string;

  @IsOptional()
  @IsEmail()
  usrMail?: string;

  /** 強制 3D（即使商店設定關閉） */
  @IsOptional()
  @IsInt()
  @IsIn([1])
  api3D?: 1;

  /** 由 UPP BuyerToken 流程取得 */
  @IsOptional()
  @IsString()
  buyerHash?: string;

  /** 發票載具（如需開立發票） */
  @IsOptional()
  @IsIn(['3J0002', 'CQ0001', 'amego', 'Donate', 'Company'])
  carrierType?: '3J0002' | 'CQ0001' | 'amego' | 'Donate' | 'Company';

  @IsOptional()
  @IsString()
  carrierInfo?: string;

  @IsOptional()
  @IsString()
  invBuyerName?: string;

  /** 消費者 IP（風控） */
  @IsOptional()
  @IsString()
  userIp?: string;
}
```

---

## Service

```ts
// apps/api-gateway/src/commerce/payments/payuni-uni-embed/payuni-uni-embed.service.ts
import { Injectable, BadRequestException, UnauthorizedException, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import {
  encryptPayuni,
  decryptPayuni,
  hashInfoPayuni,
  verifyPayuniHash,
  PAYUNI_HOSTS,
  PayuniMode,
} from '../payuni/payuni-crypto';
import { CreateUniTokenDto } from './dto/create-uni-token.dto';
import { AuthorizeUniTradeDto } from './dto/authorize-uni-trade.dto';

interface PayuniOuterResp {
  Status: 'SUCCESS' | 'ERROR' | string;
  MerID?: string;
  Version?: string;
  EncryptInfo?: string;
  HashInfo?: string;
}

@Injectable()
export class PayuniUniEmbedService {
  private readonly logger = new Logger(PayuniUniEmbedService.name);

  constructor(private readonly configService: ConfigService) {}

  private get baseUrl(): string {
    const mode = this.configService.get<PayuniMode>('PAYUNI_MODE', 'sandbox');
    return mode === 'production' ? PAYUNI_HOSTS.production : PAYUNI_HOSTS.sandbox;
  }

  private get credentials() {
    return {
      merId: this.configService.getOrThrow<string>('PAYUNI_MER_ID'),
      hashKey: this.configService.getOrThrow<string>('PAYUNI_HASH_KEY'),
      hashIv: this.configService.getOrThrow<string>('PAYUNI_HASH_IV'),
    };
  }

  /**
   * Step 1：取得 SDK_TOKEN（給前端 uniPayment SDK 使用）
   * V3 — token_get 階段不送訂單資料，僅 IFrameDomain 與 Token 類型
   */
  async createSdkToken(dto: CreateUniTokenDto): Promise<{
    sdkToken: string;
    expiredAt: number; // 後端記下 token 逾期時間（10 分鐘）
  }> {
    const { merId, hashKey, hashIv } = this.credentials;

    const innerParams: Record<string, string | number> = {
      MerID: merId,
      Timestamp: Math.floor(Date.now() / 1000),
      IFrameDomain: dto.iframeDomain,
    };

    if (dto.useTokenType) {
      innerParams.UseTokenType = dto.useTokenType;
      if (!dto.creditToken) {
        throw new BadRequestException('creditToken required when useTokenType is set');
      }
      innerParams.CreditToken = dto.creditToken;
      if (dto.creditTokenType) innerParams.CreditTokenType = dto.creditTokenType;
    }

    const encryptInfo = encryptPayuni(innerParams, hashKey, hashIv);
    const hashInfo = hashInfoPayuni(hashKey, encryptInfo, hashIv);

    const body = new URLSearchParams({
      MerID: merId,
      Version: '3.0', // token_get 請求 Version 官方原文：固定 3.0；回傳也固定 3.0
      EncryptInfo: encryptInfo,
      HashInfo: hashInfo,
    });

    const resp = await fetch(`${this.baseUrl}/api/iframe/token_get`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        // 官方原文「請於 header 加入 user-agent，建議內容為 payuni」——非強制，但帶上有助 PAYUNi 端 log 識別
        'User-Agent': 'payuni',
      },
      body: body.toString(),
    });
    const json = (await resp.json()) as PayuniOuterResp;

    return this.unwrap(json, '[token_get]');
  }

  /**
   * Step 2：幕後授權交易（前端拿到綁定 TOKEN 結果後呼叫）
   *
   * 流程定位（V3 vs V2）：
   * - V3 SDK 的 getTradeResult() 只「進行 SDK Token 綁定」，**不執行授權**
   *   （官方版本差異頁原文：「SDK 僅負責蒐集信用卡資訊並進行 SDK Token 綁定」）
   * - 前端綁定完成後，必須由後端再呼叫此 merchant_trade，才會真的授權
   * - 必要時應在送授權前再次比對 dto.tradeAmt 與本地訂單金額
   *
   * @param dto 訂單資料（在此階段才送，金額以後端為準）
   */
  async authorizeTrade(dto: AuthorizeUniTradeDto): Promise<UniEmbedAuthResult> {
    const { merId, hashKey, hashIv } = this.credentials;

    const innerParams: Record<string, string | number | undefined> = {
      MerID: merId,
      MerTradeNo: dto.merTradeNo,
      Token: dto.sdkToken,
      TradeAmt: dto.tradeAmt,
      Timestamp: Math.floor(Date.now() / 1000),
      ProdDesc: dto.prodDesc,
      ReturnURL: dto.returnUrl,
      NotifyURL: dto.notifyUrl,
      UsrMail: dto.usrMail,
      API3D: dto.api3D,
      BuyerHash: dto.buyerHash,
      CarrierType: dto.carrierType,
      CarrierInfo: dto.carrierInfo,
      InvBuyerName: dto.invBuyerName,
      UserIP: dto.userIp,
    };

    const encryptInfo = encryptPayuni(innerParams, hashKey, hashIv);
    const hashInfo = hashInfoPayuni(hashKey, encryptInfo, hashIv);

    const body = new URLSearchParams({
      MerID: merId,
      Version: '1.0', // merchant_trade 請求 Version 官方原文：固定 1.0；回傳官方原文：固定 1.2（不論是否帶發票）
      EncryptInfo: encryptInfo,
      HashInfo: hashInfo,
    });

    const resp = await fetch(`${this.baseUrl}/api/iframe/merchant_trade`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        // 官方原文「建議」帶 user-agent: payuni（非強制）
        'User-Agent': 'payuni',
      },
      body: body.toString(),
    });
    const json = (await resp.json()) as PayuniOuterResp;
    const inner = this.unwrap(json, '[merchant_trade]');

    return inner as UniEmbedAuthResult;
  }

  /**
   * 驗簽 + 解密，封裝外層 + 內層雙層回傳
   */
  private unwrap(resp: PayuniOuterResp, ctx: string): Record<string, string> {
    if (resp.Status === 'ERROR' || !resp.EncryptInfo) {
      this.logger.error(`${ctx} outer error: ${JSON.stringify(resp)}`);
      throw new BadRequestException(`PAYUNi ${ctx} error: ${resp.Status}`);
    }
    const { hashKey, hashIv } = this.credentials;
    if (!resp.HashInfo || !verifyPayuniHash(hashKey, resp.EncryptInfo, hashIv, resp.HashInfo)) {
      throw new UnauthorizedException(`${ctx} HashInfo mismatch`);
    }
    return decryptPayuni(resp.EncryptInfo, hashKey, hashIv);
  }

  /**
   * NotifyURL Webhook 驗簽 — 處理 PAYUNi Form POST 回打的 3D 結果
   */
  verifyAndDecodeNotify(body: { EncryptInfo?: string; HashInfo?: string }): Record<string, string> {
    if (!body.EncryptInfo || !body.HashInfo) {
      throw new BadRequestException('Missing EncryptInfo / HashInfo');
    }
    const { hashKey, hashIv } = this.credentials;
    if (!verifyPayuniHash(hashKey, body.EncryptInfo, hashIv, body.HashInfo)) {
      throw new UnauthorizedException('NotifyURL HashInfo mismatch');
    }
    return decryptPayuni(body.EncryptInfo, hashKey, hashIv);
  }
}

export interface UniEmbedAuthResult {
  Status: 'SUCCESS' | 'UNKNOWN' | 'UNAPPROVED' | string;
  Message?: string;
  MerID?: string;
  MerTradeNo?: string;
  Gateway?: '9'; // 固定 9
  TradeNo?: string;
  TradeAmt?: string;
  TradeStatus?: '1' | '2' | '3' | '8';
  PaymentType?: '1';
  CardBank?: string;
  Card6No?: string;
  Card4No?: string;
  CardInst?: string;
  FirstAmt?: string;
  EachAmt?: string;
  ResCode?: string;
  ResCodeMsg?: string;
  AuthCode?: string;
  AuthBank?: string;
  AuthBankName?: string;
  AuthType?: '1' | '2' | '7';
  AuthDay?: string;
  AuthTime?: string;
  CreditHash?: string;
  CreditLife?: string;
  CoBrandCode?: string;
  // API3D=1 時：
  URL?: string; // 強制 3D 導頁網址
}
```

---

## Controller

```ts
// apps/api-gateway/src/commerce/payments/payuni-uni-embed/payuni-uni-embed.controller.ts
import { Body, Controller, Post, Req, HttpCode, Header } from '@nestjs/common';
import { Request } from 'express';
import { PayuniUniEmbedService } from './payuni-uni-embed.service';
import { CreateUniTokenDto } from './dto/create-uni-token.dto';
import { AuthorizeUniTradeDto } from './dto/authorize-uni-trade.dto';

@Controller('checkout/payuni-uni')
export class PayuniUniEmbedController {
  constructor(private readonly service: PayuniUniEmbedService) {}

  /** 前端結帳頁面載入時呼叫，取得 SDK_TOKEN */
  @Post('token')
  async issueToken(@Body() dto: CreateUniTokenDto) {
    const result = await this.service.createSdkToken(dto);
    return { sdkToken: result.sdkToken, expiredAt: result.expiredAt };
  }

  /**
   * 前端 getTradeResult() 完成後呼叫；後端用同一個 SDK_TOKEN 進行幕後授權
   * 注意：tradeAmt 由後端購物車計算，前端可不送（DTO 改為由訂單 ID 帶入）
   */
  @Post('authorize')
  async authorize(@Body() dto: AuthorizeUniTradeDto, @Req() req: Request) {
    const userIp = (req.headers['x-forwarded-for'] as string)?.split(',')[0] || req.ip;
    return this.service.authorizeTrade({ ...dto, userIp });
  }

  /**
   * NotifyURL Webhook（PAYUNi 3D 完成後 Form POST 回打）
   * 必須 200 OK 否則 PAYUNi 會記為失敗
   */
  @Post('notify')
  @HttpCode(200)
  @Header('Content-Type', 'text/plain')
  async notify(@Body() body: Record<string, string>) {
    const decoded = this.service.verifyAndDecodeNotify(body);
    // TODO: 比對本地訂單 → 更新訂單狀態 → 寫 audit log
    return 'OK';
  }
}
```

---

## Module 註冊

```ts
// apps/api-gateway/src/commerce/payments/payuni-uni-embed/payuni-uni-embed.module.ts
import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { PayuniUniEmbedService } from './payuni-uni-embed.service';
import { PayuniUniEmbedController } from './payuni-uni-embed.controller';

@Module({
  imports: [ConfigModule],
  controllers: [PayuniUniEmbedController],
  providers: [PayuniUniEmbedService],
  exports: [PayuniUniEmbedService],
})
export class PayuniUniEmbedModule {}
```

註冊到 `apps/api-gateway/src/app.module.ts` 的 imports 陣列。

---

## Webhook 驗簽流程

NotifyURL 收到 PAYUNi 的 Form POST 後：

1. **解析 body**：`MerID`、`Version`、`EncryptInfo`、`HashInfo`
2. **驗 HashInfo**：`SHA256(HashKey + EncryptInfo + HashIV).toUpperCase()` 必須吻合
3. **解密 EncryptInfo**：取出 `MerTradeNo`、`TradeAmt`、`TradeStatus`、`AuthCode` 等
4. **比對本地訂單**：
   - `MerTradeNo` 必須對應已存在的訂單
   - `TradeAmt` 必須與本地金額一致（防止竄改）
   - `Gateway` 必須是 `9`
5. **依 TradeStatus 更新訂單**：
   - `1` → 已付款
   - `2` → 付款失敗
   - `3` → 付款取消
   - `8` → 訂單待確認（買方會員審查中，UNAPPROVED）
6. **回應 200 OK**（純文字 `OK` 即可）

---

## E2E 測試對位

```ts
// apps/api-gateway/src/commerce/payments/payuni-uni-embed/payuni-uni-embed.service.spec.ts
import { Test } from '@nestjs/testing';
import { ConfigService } from '@nestjs/config';
import { PayuniUniEmbedService } from './payuni-uni-embed.service';

describe('PayuniUniEmbedService', () => {
  let service: PayuniUniEmbedService;
  const config = {
    PAYUNI_MER_ID: 'TEST_MER',
    PAYUNI_HASH_KEY: '12345678901234567890123456789012',
    PAYUNI_HASH_IV: '1234567890123456',
    PAYUNI_MODE: 'sandbox',
  };

  beforeEach(async () => {
    const module = await Test.createTestingModule({
      providers: [
        PayuniUniEmbedService,
        { provide: ConfigService, useValue: { get: (k: string, d?: any) => config[k] ?? d, getOrThrow: (k: string) => config[k] } },
      ],
    }).compile();
    service = module.get(PayuniUniEmbedService);
  });

  it('rejects when HashInfo mismatch', () => {
    expect(() =>
      service.verifyAndDecodeNotify({ EncryptInfo: 'bad', HashInfo: 'WRONG' }),
    ).toThrow(/HashInfo mismatch/);
  });

  it('uses official sample crypto values', () => {
    // 官方範例：merKey/merIV/data → 預期 SHA256
    // 詳見 ../payuni-upp-v3/references/examples.md
    // ... 略
  });
});
```

---

## 環境變數

```env
# apps/api-gateway/.env
PAYUNI_MER_ID=your_merchant_id
PAYUNI_HASH_KEY=your_32_char_hash_key_here_____   # 必須 32 字元
PAYUNI_HASH_IV=your_16char_iv__                   # 必須 16 字元
PAYUNI_MODE=sandbox                               # sandbox | production

# UNi Embed 限定 IP 必須在 PAYUNi 後台設定
# 透過 Cloudflare Tunnel 開發時，注意 PAYUNi 看到的 IP 是 Tunnel 出口 IP
```

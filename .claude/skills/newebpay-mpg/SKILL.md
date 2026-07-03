---
name: newebpay-mpg
description: >
  NewebPay MPG (Multiple Payment Gateway) complete payment gateway reference for Taiwan,
  aligned with official NDNF-1.2.2 manual (revised 2026-04-21).
  Covers MPG Version 2.0 (legacy) and 2.3 (current spec): AES-256-CBC + SHA256 encryption,
  optional AES-256-GCM (EncryptType=1), all TradeInfo parameters, payment methods
  (credit card / WebATM / VACC / CVS / BARCODE / CVSCOM / LINE Pay / Apple Pay /
  TWQR / smart-ATM 2.0 / AFTEE BNPL / e-wallets), callback handling, query trade,
  credit card close/cancel/refund, e-wallet refund, BNPL refund (NPA-B07) and
  BNPL settle (NPA-B62), error codes, sandbox endpoints.
  Use this skill whenever code involves newebpay, TradeInfo, TradeSha, EncryptData_,
  HashData_, MerchantID + HashKey + HashIV, ccore.newebpay.com / core.newebpay.com
  (and legacy spgateway.com aliases), MPG/mpg_gateway, /API/QueryTradeInfo,
  /API/CreditCard/Close, /API/CreditCard/Cancel, /API/EWallet/refund, /API/Bnpl/refund,
  /API/Bnpl/settle, NewebpayService, newebpay callback/notify handling, AFTEE 先買後付,
  TWQR 跨機構支付, Apple Pay, Taiwan payment gateway, AES-256-CBC trade encryption,
  TradeSha SHA256, CheckCode verification, OrderDetail 訂單細項.
---

# NewebPay MPG (NDNF 1.2.2)

> **Manual**: NDNF-1.2.2 (官方「線上交易—幕前支付技術串接手冊」, revised 2026-04-21)
> **API Versions**: MPG `2.3` (current spec) / `2.0` (legacy, fully compatible)
> **Brand note**: 藍新 = NewebPay = ex-spgateway. Legacy `*.spgateway.com` domains still resolve as aliases of `*.newebpay.com`; use `*.newebpay.com` for new code.

Taiwan 3rd-party payment gateway. **MPG (Multiple Payment Gateway)** is the RWD-responsive checkout page consumers see. **NDNF** is the document code name covering the entire 幕前支付 family (MPG + post-trade APIs).

## Project Integration Specs

- **Code path**: `apps/api-gateway/src/commerce/payments/newebpay/`
- **Project Version**: currently `2.0` (works against NDNF 1.2.2 — see Version Compatibility below)
- **Default payment**: `CREDIT=1`, `RespondType=JSON`, `LoginType=0`
- **Credentials source**: `settings` table (per-tenant); `.env` provides dev sandbox fallback

## Document Sources

| Source | Coverage |
|--------|----------|
| `references/api-reference.md` | Complete TradeInfo/TradeSha parameters, payment-method params, callback fields |
| `references/backend-apis.md` | QueryTradeInfo / CreditCard Close / Cancel / EWallet refund / **BNPL refund (NPA-B07)** / **BNPL settle (NPA-B62)** / error codes |
| `references/examples.md` | TypeScript/NestJS examples — CBC encrypt/decrypt, **GCM encrypt/decrypt (caveats)**, callback handler, CheckCode verify |
| `references/v2-to-v23-migration.md` | Step-by-step upgrade from `Version=2.0` to `Version=2.3` |
| `references/bnpl-aftee.md` | AFTEE 先買後付 complete flow — OrderDetail schema, install 參數, settle/refund APIs |

## Version Compatibility

| API | Project | NDNF 1.2.2 spec | Notes |
|-----|---------|-----------------|-------|
| MPG | `2.0` | `2.3` | 2.0 still accepted. Cannot use TWQR / SourceType / OrderDetail without 2.3 (OrderDetail needs ≥ 2.2). Encryption rules unchanged across 2.0/2.2/2.3. |
| QueryTradeInfo | `1.3` | `1.3` | identical |
| CreditCard Close (capture/refund) | `1.1` | `1.1` | identical |
| CreditCard Cancel | `1.0` | `1.0` | identical |
| EWallet refund | — | (POST form) | path is `/refund` (lowercase) |
| BNPL refund (NPA-B07) | — | `1.1` | uses **outer envelope `UID_/EncryptData_/HashData_/Version_/RespondType_`**, not MerchantID/TradeInfo/TradeSha |
| BNPL settle (NPA-B62) | — | `1.1` | same envelope as BNPL refund |

Upgrading `2.0` → `2.3`: change Version string only; existing CBC encryption is unchanged. See `references/v2-to-v23-migration.md`.

## Core Payment Flow

### Realtime (credit card / WebATM / e-wallets / TWQR / BNPL)

```
1. Backend: build TradeInfo (key=value&...) → AES-256-CBC encrypt → SHA256 → TradeSha
2. Frontend: auto-submit POST form (MerchantID, TradeInfo, TradeSha, Version) to MPG endpoint
3. Buyer completes payment on NewebPay MPG page (3DS OTP if applicable)
4. NewebPay → POST NotifyURL (background, source of truth) AND redirect ReturnURL (foreground UX)
5. Backend: SHA256-verify TradeSha → decrypt TradeInfo → verify CheckCode → mark order paid
```

### Offline (ATM transfer / CVS code / barcode / CVSCOM)

```
1-3. Same as above, buyer chooses offline method
4. NewebPay obtains payment voucher (16-digit account / CVS code / barcode) → redirect CustomerURL with voucher info
5. Buyer pays at ATM/CVS days later
6. Bank/CVS → NewebPay → POST NotifyURL when payment lands
7. Backend marks order paid
```

## Endpoint URLs

> **Always use `*.newebpay.com`. Legacy `*.spgateway.com` are aliases retained for backward compatibility.**

| API | Test | Prod |
|-----|------|------|
| MPG | `https://ccore.newebpay.com/MPG/mpg_gateway` | `https://core.newebpay.com/MPG/mpg_gateway` |
| QueryTradeInfo (NPA-B02) | `https://ccore.newebpay.com/API/QueryTradeInfo` | `https://core.newebpay.com/API/QueryTradeInfo` |
| Credit Card Close (NPA-B031~34) | `https://ccore.newebpay.com/API/CreditCard/Close` | `https://core.newebpay.com/API/CreditCard/Close` |
| Credit Card Cancel (NPA-B01) | `https://ccore.newebpay.com/API/CreditCard/Cancel` | `https://core.newebpay.com/API/CreditCard/Cancel` |
| EWallet refund (NPA-B06) | `https://ccore.newebpay.com/API/EWallet/refund` | `https://core.newebpay.com/API/EWallet/refund` |
| **BNPL refund (NPA-B07)** | `https://ccore.newebpay.com/API/Bnpl/refund` | `https://core.newebpay.com/API/Bnpl/refund` |
| **BNPL settle (NPA-B62)** | `https://ccore.newebpay.com/API/Bnpl/settle` | `https://core.newebpay.com/API/Bnpl/settle` |

> Note: path case matters. `/API/EWallet/refund` lowercase `r` is the official spelling.

## AES-256-CBC Encryption (default, `EncryptType=0` or omitted)

```typescript
import * as crypto from 'crypto';

function encryptTradeInfo(data: string, hashKey: string, hashIv: string): string {
  const key = hashKey.padEnd(32, '0').slice(0, 32);
  const iv = hashIv.padEnd(16, '0').slice(0, 16);
  const cipher = crypto.createCipheriv('aes-256-cbc', Buffer.from(key), Buffer.from(iv));
  return cipher.update(data, 'utf8', 'hex') + cipher.final('hex');
}

function decryptTradeInfo(encrypted: string, hashKey: string, hashIv: string): string {
  const key = hashKey.padEnd(32, '0').slice(0, 32);
  const iv = hashIv.padEnd(16, '0').slice(0, 16);
  const decipher = crypto.createDecipheriv('aes-256-cbc', Buffer.from(key), Buffer.from(iv));
  return decipher.update(encrypted, 'hex', 'utf8') + decipher.final('utf8');
}
```

- Algorithm: `aes-256-cbc`, Key: 32 bytes, IV: 16 bytes, Padding: PKCS7 (Node auto), Output: hex

## AES-256-GCM Encryption (opt-in, `EncryptType=1`)

> **Caveat**: NDNF 1.2.2 only declares `EncryptType=1` enables GCM. The PDF provides **no spec for IV length, authTag handling, AAD, or output format**, and contains **no GCM example code** (all PHP samples are CBC). Before enabling GCM in production, **contact NewebPay technical support** for the exact wire format. The code below uses the cryptography-standard convention (12-byte IV + 16-byte authTag concatenated, hex output) — verify before use.

```typescript
// CONVENTIONAL (unverified against NewebPay) — confirm with vendor before production
function encryptTradeInfoGcm(data: string, hashKey: string, hashIv: string): string {
  const key = hashKey.padEnd(32, '0').slice(0, 32);
  const iv = hashIv.padEnd(12, '0').slice(0, 12); // GCM standard 12-byte IV
  const cipher = crypto.createCipheriv('aes-256-gcm', Buffer.from(key), Buffer.from(iv));
  const enc = Buffer.concat([cipher.update(data, 'utf8'), cipher.final()]);
  const tag = cipher.getAuthTag();
  return Buffer.concat([enc, tag]).toString('hex');
}
```

See `references/examples.md` for full GCM caveats and decrypt counterpart.

## SHA256 Hash (TradeSha / HashData)

```typescript
function generateTradeSha(encryptedHex: string, hashKey: string, hashIv: string): string {
  const raw = `HashKey=${hashKey}&${encryptedHex}&HashIV=${hashIv}`;
  return crypto.createHash('sha256').update(raw).digest('hex').toUpperCase();
}
```

**Formula**: `SHA256("HashKey={K}&{encryptedHex}&HashIV={IV}")` → UPPERCASE.

Same formula serves: MPG `TradeSha`, BNPL `HashData_`, QueryTradeInfo `CheckValue` (different field-order; see `references/backend-apis.md`).

## POST Form Parameters (MPG)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `MerchantID` | string | Y | Merchant ID |
| `TradeInfo` | string | Y | AES-encrypted trade data (hex) |
| `TradeSha` | string | Y | SHA256 hash (uppercase hex) |
| `Version` | string | Y | `"2.3"` (current) or `"2.0"` (legacy) |
| `EncryptType` | int | N | `0`/omitted = AES-CBC (default), `1` = AES-GCM |

## TradeInfo Core Parameters

> Full parameter table in `references/api-reference.md`.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `MerchantID` | string | Y | Merchant ID |
| `RespondType` | string | Y | `"JSON"` or `"String"` |
| `TimeStamp` | string | Y | Unix timestamp (seconds), within ±120s of server time |
| `Version` | string | Y | `"2.3"` or `"2.0"` |
| `MerchantOrderNo` | string | Y | Order number (alphanumeric, max 30 chars, unique per MerchantID) |
| `Amt` | int | Y | Amount (integer TWD) |
| `ItemDesc` | string | Y | Item description (max 50) |
| `Email` | string | Y | Payer email |
| `ReturnURL` | string | N | Foreground redirect after payment |
| `NotifyURL` | string | N | Background server-to-server notification (source of truth) |
| `CustomerURL` | string | N | Offline-payment voucher display redirect |
| `ClientBackURL` | string | N | Cancel/back URL |
| `OrderDetail` | string | N* | JSON array — required when `AFTEE` enabled (see bnpl-aftee.md) |

## Payment Methods

Set `1` to enable, `0` or omit to disable.

| Param | Method | Type | Notes |
|-------|--------|------|-------|
| `CREDIT` | Credit card | Realtime | One-time |
| `InstFlag` | Credit installment | Realtime | string `"3,6,12,18,24,30"` |
| `CreditRed` | Credit reward points | Realtime | |
| `CREDITAE` | American Express | Realtime | |
| `UNIONPAY` | UnionPay | Realtime | |
| `WEBATM` | WebATM | Realtime | |
| `VACC` | ATM virtual account | Offline | 16-digit account |
| `CVS` | CVS code | Offline | 7-11 / FamilyMart / Hi-Life / OK; Amt 30–20,000 |
| `BARCODE` | CVS barcode | Offline | 3-segment; Amt 30–20,000 |
| `CVSCOM` | CVS pickup-and-pay | Offline | `1`=pickup, `2`=pickup+pay |
| `LINEPAY` | LINE Pay | Realtime | |
| `ESUNWALLET` | E.SUN Wallet | Realtime | |
| `TAIWANPAY` | Taiwan Pay | Realtime | |
| `SAMSUNGPAY` | Samsung Pay | Realtime | |
| `ANDROIDPAY` | Google Pay | Realtime | |
| `APPLEPAY` | Apple Pay | Realtime | |
| `BITOPAY` | BitoPay | Realtime | |
| `TWQR` | TWQR cross-org QR | Realtime | **v2.3 new**; pair with `TWQR_LifeTime` |
| `AFTEE` | AFTEE 先買後付 (BNPL) | Realtime | NDNF 1.2.2 new; `1`=one-off, `2`=one-off+install; **requires `OrderDetail`**; Amt cap 49,999 |

**Removed in v2.3**: `ezPay` (no longer supported).

See `references/api-reference.md` for sub-params (`InstFlag`, `TokenTerm`, `CREDITAGREEMENT`, `P3D`, `TWQR_LifeTime`, `SourceType/SourceBankId/SourceAccountNo`, `AFTEE_Inst`, etc.).

## Callback Response (decrypted JSON)

```typescript
interface NewebPayCallback {
  Status: string;       // "SUCCESS" or error code
  Message: string;
  Result: {
    MerchantID: string;
    Amt: number;
    TradeNo: string;           // NewebPay transaction number
    MerchantOrderNo: string;
    PaymentType: string;       // "CREDIT"|"VACC"|"CVS"|"BARCODE"|"LINEPAY"|"APPLEPAY"|"TWQR"|"AFTEE"|...
    RespondCode: string;       // "00" = success
    AuthBank: string;
    EscrowBank: string;
    PayTime: string;           // "yyyy-MM-dd HH:mm:ss"
    IP: string;
    CheckCode: string;         // verify with HashIV/Amt/MerchantID/MerchantOrderNo/TradeNo/HashKey
    // Plus payment-type-specific fields — see api-reference.md
  };
}
```

## Pitfalls

1. `TradeSha` MUST be UPPERCASE (lowercase = `MPG03012` verification fail)
2. URL-encode Chinese values **before** AES-encrypting — values containing `&` or `=` will break the key=value format
3. `Version` is a string (`"2.3"` not `2.3`); regression to `"2.0"` is allowed
4. `NotifyURL` is the source of truth; treat `ReturnURL` as UX only (browser may close before redirect)
5. Domain: prefer `*.newebpay.com`; `*.spgateway.com` is a legacy alias
6. `Amt`: integer TWD only, no decimals
7. `MerchantOrderNo`: alphanumeric, max 30, unique per MerchantID (duplicate → `MPG03002`)
8. PHP needs manual PKCS7 padding; Node.js `crypto` handles it automatically
9. `ItemDesc` max 50 chars (single-byte counted)
10. `TradeLimit`: 60–900 seconds (or `0`=no limit)
11. `EWallet` refund path is `/API/EWallet/refund` (lowercase `r`); using `/Refund` returns 404
12. **BNPL APIs use a different envelope**: `UID_` + `EncryptData_` + `HashData_` (not `MerchantID` + `TradeInfo` + `TradeSha`); see `references/bnpl-aftee.md`
13. `EncryptType=1` (GCM) wire format is undocumented — confirm with vendor before enabling
14. `OrderDetail` requires Version ≥ `"2.2"`; ItemAmt sum must equal `Amt` exactly

## Sandbox Detection (project pattern)

```typescript
const isTest = !credentials.merchantId.startsWith('M') || credentials.merchantId === 'MS154450763';
const baseUrl = isTest ? 'https://ccore.newebpay.com' : 'https://core.newebpay.com';
```

## References

| Need | File |
|------|------|
| Full parameter tables (TradeInfo, payment methods, callback) | `references/api-reference.md` |
| Code examples (TypeScript, including GCM caveats) | `references/examples.md` |
| Backend APIs + error codes | `references/backend-apis.md` |
| **v2.0 → v2.3 migration guide** | `references/v2-to-v23-migration.md` |
| **AFTEE BNPL complete flow** | `references/bnpl-aftee.md` |

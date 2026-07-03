# NewebPay MPG API Reference (NDNF 1.2.2)

> Complete parameter reference for TradeInfo fields, payment methods, and callback response fields.
> **Spec source**: NDNF-1.2.2 (revised 2026-04-21). MPG `Version=2.3` (current) / `2.0` (legacy, fully compatible).

## Table of Contents
1. TradeInfo Request Parameters
2. Payment Method Parameters
3. Credit Card Parameters
4. Offline Payment Parameters
5. E-Wallet Parameters
6. TWQR Parameters (v2.3)
7. Smart-ATM 2.0 Parameters (v2.3)
8. AFTEE BNPL Parameters
9. Callback Response Parameters
10. Payment-Type-Specific Response Fields
11. CheckCode Verification

## TradeInfo Request Parameters

Joined as `key=value&key=value` (URL-encoded values), then AES-256-CBC encrypted to produce `TradeInfo` (hex).

### Required

| Parameter | Type | MaxLen | Description |
|-----------|------|--------|-------------|
| `MerchantID` | string | 15 | Merchant ID from NewebPay |
| `RespondType` | string | 6 | `"JSON"` or `"String"` |
| `TimeStamp` | string | 10 | Unix timestamp (seconds). Must be within ±120s of NewebPay server time |
| `Version` | string | 5 | `"2.3"` (current) or `"2.0"` (legacy) |
| `MerchantOrderNo` | string | 30 | Order number. Alphanumeric. Unique per MerchantID |
| `Amt` | int | 10 | Amount in TWD (integer, no decimals) |
| `ItemDesc` | string | 50 | Product description. Comma-separated for multiple |
| `Email` | string | 50 | Payer email |

### Optional - URLs

| Parameter | Type | MaxLen | Description |
|-----------|------|--------|-------------|
| `ReturnURL` | string | 255 | Foreground redirect after payment (UX only) |
| `NotifyURL` | string | 255 | Background server-to-server POST notification (source of truth) |
| `CustomerURL` | string | 255 | Offline payment: display payment instructions (ATM account, CVS code) |
| `ClientBackURL` | string | 255 | "Return to Store" button URL on NewebPay page |

### Optional - Display / Behavior

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `LangType` | string | `"zh-tw"` | `"zh-tw"`, `"en"`, `"jp"` |
| `LoginType` | int | `0` | `0`=no login, `1`=require NewebPay login |
| `TradeLimit` | int | `0` | Timeout 60–900 seconds. `0`=no limit |
| `ExpireDate` | string | 7 days | Offline deadline `YYYYMMDD`. Max 180 days |
| `EmailModify` | int | `1` | `0`=read-only, `1`=editable |
| `OrderComment` | string | — | Notes on payment page (max 300 chars) |
| `EncryptType` | int | `0` | `0`=AES-256-CBC + PKCS7 (default), `1`=AES-256-GCM (see SKILL.md GCM caveat) |
| `OrderDetail` | string | — | JSON array of items. **Required when `AFTEE` enabled.** Needs Version ≥ `"2.2"`. See `bnpl-aftee.md` |

## Payment Method Parameters

Set `1` to enable, `0` or omit to disable.

### Credit Card
| Parameter | Type | Description |
|-----------|------|-------------|
| `CREDIT` | int | One-time payment |
| `InstFlag` | string | Installment periods comma-separated: `"3,6,12,18,24,30"` |
| `CreditRed` | int | Reward points redemption |
| `CREDITAE` | int | American Express |
| `UNIONPAY` | int | UnionPay |

### Bank Transfer
| Parameter | Type | Description |
|-----------|------|-------------|
| `WEBATM` | int | WebATM (card reader required) |
| `VACC` | int | ATM virtual account (generates 16-digit account) |

### Convenience Store
| Parameter | Type | Description |
|-----------|------|-------------|
| `CVS` | int | Code payment (kiosk at 7-11, FamilyMart, Hi-Life, OK Mart) |
| `BARCODE` | int | Barcode payment (3-segment barcode) |
| `CVSCOM` | int | CVS pickup; `1`=pickup only, `2`=pickup+pay |

### E-Wallets / Mobile Pay
| Parameter | Type | Description |
|-----------|------|-------------|
| `LINEPAY` | int | LINE Pay |
| `ESUNWALLET` | int | E.SUN Wallet |
| `TAIWANPAY` | int | Taiwan Pay |
| `ANDROIDPAY` | int | Google Pay |
| `SAMSUNGPAY` | int | Samsung Pay |
| `APPLEPAY` | int | Apple Pay |
| `BITOPAY` | int | BitoPay |

### Cross-Org QR Code (v2.3 new)
| Parameter | Type | Description |
|-----------|------|-------------|
| `TWQR` | int | TWQR 跨機構與簡單付電子錢包 |
| `TWQR_LifeTime` | int | QR code TTL seconds. Default `300`. Max `2678400` (31 days) |

### BNPL (NDNF 1.2.2 new)
| Parameter | Type | Description |
|-----------|------|-------------|
| `AFTEE` | int | `1`=one-off, `2`=one-off+installment, `0`/omit=disabled |
| `AFTEE_Inst` | string(21) | When `AFTEE=2`: omit/`1`=all periods; specify periods comma-separated from `3,6,9,12,15,18,21,24` (e.g. `"3,6,12"`) |
| `OrderDetail` | string | JSON array (required). See `bnpl-aftee.md` for schema |

> **AFTEE constraints**: Amt cap 49,999 (above which payment option is hidden), Version ≥ `"2.2"`, `OrderDetail` ItemAmt sum must equal `Amt` exactly.

### Removed
- `ezPay` — discontinued in v2.3.

## Credit Card Parameters

### Installment
| Parameter | Type | Description |
|-----------|------|-------------|
| `InstFlag` | string | Periods: `"3,6,12,18,24,30"` |

Fee rates (typical): 3p=3%, 6p=3.5%, 12p=7%, 18p=9%, 24p=12%, 30p=15%.

### Card Token (record card for fast checkout)
| Parameter | Type | Description |
|-----------|------|-------------|
| `TokenTerm` | string | Cardholder ID (e.g. user UUID) for card binding |
| `TokenTermDemand` | int | `0`=none, `1`=CVV, `2`=CVV+expiry |
| `TokenLife` | string | Token expiration `YYMM` |

### Recurring (subsequent charge with stored token)
| Parameter | Type | Description |
|-----------|------|-------------|
| `CREDITAGREEMENT` | int | `1`=first auth (returns token), `2`=subsequent charge |
| `TokenValue` | string | Token from first auth |

### 3D Secure
| Parameter | Type | Description |
|-----------|------|-------------|
| `P3D` | int | `0`=skip, `1`=enforce |

## Offline Payment Parameters

### ATM (VACC)
- Generates 16-digit virtual account.
- `ExpireDate`: deadline `YYYYMMDD`, default 7d, max 180d.

### CVS (Convenience Store Code)
- Generates code for 7-11 / FamilyMart / Hi-Life / OK Mart kiosk.
- `ExpireDate`: deadline `YYYYMMDD`.
- Amount range: TWD 30 – 20,000.

### BARCODE
- Generates 3-segment barcode for store counter.
- `ExpireDate`: deadline `YYYYMMDD`.
- Amount range: TWD 30 – 20,000.

## E-Wallet Parameters

### LINE Pay
| Parameter | Type | Description |
|-----------|------|-------------|
| `LINEPAY` | int | `1` to enable |
| `ImageUrl` | string | Product image URL for LINE Pay checkout |

### CVSCOM
| Parameter | Type | Description |
|-----------|------|-------------|
| `CVSCOM` | int | `1`=pickup only, `2`=pickup+pay |
| `LgsType` | string | `"B2C"` or `"C2C"` |

## TWQR Parameters (v2.3)

| Parameter | Type | Description |
|-----------|------|-------------|
| `TWQR` | int | `1` enable, `0`/omit disable |
| `TWQR_LifeTime` | int | Lifetime in seconds. Default `300`. Max `2678400` (31 days). Determines QR expiry |

## Smart-ATM 2.0 Parameters (v2.3)

These control whether buyers can specify or are forced to use a particular bank/account when paying via ATM transfer.

| Parameter | Type | Description |
|-----------|------|-------------|
| `SourceType` | int | Controls bank/account behavior on payment page. `1`/`2`=KGI Bank only; `3`/`4`=BOT/Hua Nan/KGI. Odd values = locked (no edit), even values = editable. `1`/`3` require `SourceBankId` and `SourceAccountNo` |
| `SourceBankId` | string | Bank code shown to buyer. Required when `SourceType=1` or `3` |
| `SourceAccountNo` | string | Account number shown to buyer. Required when `SourceType=1` or `3` |

> Banks list (v2.3) includes KGI (凱基銀行) — added in v2.3.

## AFTEE BNPL Parameters

See dedicated `bnpl-aftee.md` for complete OrderDetail schema, settle/refund APIs and full flow.

Quick reference:

| Parameter | Type | Required when AFTEE on | Description |
|-----------|------|------------------------|-------------|
| `AFTEE` | int | — | `1`=enable, `2`=enable+installment |
| `AFTEE_Inst` | string(21) | When `AFTEE=2` | Installment periods, comma-separated from `3,6,9,12,15,18,21,24` |
| `OrderDetail` | string | Y | JSON array. Items have 4 fields: `ItemName(20)`, `ItemAmt(int)`, `ItemType(1=normal/2=ticket/3=stored-value/4=discount)`, `ItemOrderNo(20)`. Sum of `ItemAmt` = `Amt` |

## Callback Response Parameters

### Top-level (decrypted JSON)
| Field | Type | Description |
|-------|------|-------------|
| `Status` | string | `"SUCCESS"` or error code (e.g. `MPG03012`) |
| `Message` | string | Status description |

### Result Object
| Field | Type | Description |
|-------|------|-------------|
| `MerchantID` | string | Merchant ID |
| `Amt` | int | Amount |
| `TradeNo` | string | NewebPay transaction number |
| `MerchantOrderNo` | string | Merchant order number |
| `PaymentType` | string | `"CREDIT"`,`"VACC"`,`"WEBATM"`,`"CVS"`,`"BARCODE"`,`"LINEPAY"`,`"APPLEPAY"`,`"TWQR"`,`"AFTEE"`, etc. |
| `RespondCode` | string | Bank code (`"00"`=success) |
| `AuthBank` | string | Acquiring bank |
| `EscrowBank` | string | Escrow bank |
| `PayTime` | string | `"yyyy-MM-dd HH:mm:ss"` |
| `IP` | string | Payer IP |
| `CheckCode` | string | Verification hash (see below) |

## Payment-Type-Specific Response Fields

### Credit Card
| Field | Type | Description |
|-------|------|-------------|
| `Card6No` | string | First 6 digits |
| `Card4No` | string | Last 4 digits |
| `AuthCode` | string | Authorization code |
| `Inst` | int | Installment periods (0=one-time) |
| `InstFirst` | int | First installment amount |
| `InstEach` | int | Each installment amount |
| `ECI` | string | 3D Secure ECI |
| `RedAmt` | int | Reward points amount |
| `TokenValue` | string | Card token |
| `TokenLife` | string | Token expiry `"YYMM"` |

### ATM (VACC / WEBATM)
| Field | Type | Description |
|-------|------|-------------|
| `PayBankCode` | string | Payer bank code |
| `PayerAccount5Code` | string | Last 5 digits |
| `BankCode` | string | Virtual account bank (VACC) |
| `CodeNo` | string | Virtual account number 16-digit (VACC) |

### CVS
| Field | Type | Description |
|-------|------|-------------|
| `CodeNo` | string | Payment code |
| `StoreType` | int | Store channel |
| `StoreName` | string | Store name |

### Barcode
| Field | Type | Description |
|-------|------|-------------|
| `Barcode_1` | string | Segment 1 |
| `Barcode_2` | string | Segment 2 |
| `Barcode_3` | string | Segment 3 |

### TWQR
| Field | Type | Description |
|-------|------|-------------|
| `PaymentType` | string | `"TWQR"` |
| `TWQRTradeNo` | string | TWQR-side transaction number (if returned) |

### AFTEE
| Field | Type | Description |
|-------|------|-------------|
| `PaymentType` | string | `"AFTEE"` |
| `Inst` | int | Installment periods if installment chosen, 0 otherwise |

## CheckCode Verification

```typescript
const checkStr = [
  `HashIV=${hashIv}`,
  `Amt=${result.Amt}`,
  `MerchantID=${result.MerchantID}`,
  `MerchantOrderNo=${result.MerchantOrderNo}`,
  `TradeNo=${result.TradeNo}`,
  `HashKey=${hashKey}`,
].join('&');

const checkCode = crypto.createHash('sha256')
  .update(checkStr).digest('hex').toUpperCase();

const isValid = checkCode === result.CheckCode;
```

**Parameter order**: HashIV, Amt, MerchantID, MerchantOrderNo, TradeNo, HashKey.

> Note: BNPL refund/settle responses **do not** carry `CheckCode`; they use `HashData_` over the encrypted payload instead. See `backend-apis.md`.

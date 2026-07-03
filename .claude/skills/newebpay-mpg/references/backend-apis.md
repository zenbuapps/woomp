# NewebPay Backend APIs (NDNF 1.2.2) and Error Codes

> Query trade, credit card close/refund/cancel, e-wallet refund, **BNPL refund/settle**, periodic payment (out-of-NDNF reference), error codes.
> **Spec source**: NDNF-1.2.2 (revised 2026-04-21).

## Table of Contents
1. Query Trade Info API (NPA-B02)
2. Credit Card Close (Capture) API (NPA-B031~34)
3. Credit Card Cancel Authorization API (NPA-B01)
4. Credit Card Refund API (same endpoint as Close, CloseType=2)
5. E-Wallet Refund API (NPA-B06)
6. **BNPL Refund / Cancel API (NPA-B07)**
7. **BNPL Settle (Capture) API (NPA-B62)**
8. Periodic Payment API (out-of-NDNF, kept as legacy reference)
9. Error Codes
10. Test Environment

## Query Trade Info API (NPA-B02)

### Endpoint
| Env | URL |
|-----|-----|
| Test | `https://ccore.newebpay.com/API/QueryTradeInfo` |
| Prod | `https://core.newebpay.com/API/QueryTradeInfo` |

### Request (POST form-encoded)
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `MerchantID` | string | Y | Merchant ID |
| `Version` | string | Y | `"1.3"` |
| `RespondType` | string | Y | `"JSON"` |
| `CheckValue` | string | Y | SHA256 verification hash |
| `TimeStamp` | string | Y | Unix timestamp |
| `MerchantOrderNo` | string | Y | Order number to query |
| `Amt` | int | Y | Original amount |

### CheckValue Generation

```typescript
function generateCheckValue(hashKey: string, hashIv: string, merchantId: string, orderNo: string, amt: number): string {
  const raw = `IV=${hashIv}&Amt=${amt}&MerchantID=${merchantId}&MerchantOrderNo=${orderNo}&Key=${hashKey}`;
  return crypto.createHash('sha256').update(raw).digest('hex').toUpperCase();
}
```

> Note: QueryTradeInfo's `CheckValue` uses keys `IV=`/`Key=` (not `HashIV=`/`HashKey=` as MPG TradeSha does). Easy to mix up.

### TradeStatus Values
| Value | Meaning |
|-------|---------|
| `"0"` | Unpaid |
| `"1"` | Paid |
| `"2"` | Failed |
| `"3"` | Cancelled |
| `"6"` | Refunded |

### Response Result Fields
| Field | Type | Description |
|-------|------|-------------|
| `TradeStatus` | string | See above |
| `CloseStatus` | string | `"0"`=not closed, `"1"`=waiting, `"2"`=closed, `"3"`=refunded |
| `CloseAmt` | int | Captured amount |
| `BackBalance` | int | Refunded amount |
| `BackStatus` | string | `"0"`=not refunded, `"1"`=refunded |
| `FundTime` | string | Settlement date |

## Credit Card Close (Capture) API (NPA-B031~34)

### Endpoint
| Env | URL |
|-----|-----|
| Test | `https://ccore.newebpay.com/API/CreditCard/Close` |
| Prod | `https://core.newebpay.com/API/CreditCard/Close` |

### Request (POST form-encoded)
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `MerchantID_` | string | Y | Merchant ID |
| `PostData_` | string | Y | AES-encrypted request data (hex) |

> Note: this API uses `MerchantID_` and `PostData_` (trailing underscore) — different envelope from MPG.

### PostData (before encryption)
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `RespondType` | string | Y | `"JSON"` |
| `Version` | string | Y | `"1.1"` |
| `Amt` | int | Y | Amount |
| `MerchantOrderNo` | string | Y* | Merchant order number |
| `TradeNo` | string | Y* | NewebPay trade number |
| `IndexType` | int | Y | `1`=MerchantOrderNo, `2`=TradeNo |
| `CloseType` | int | Y | `1`=capture, `2`=refund |
| `Cancel` | int | N | `1`=cancel previous request |

### Example

```typescript
async function capturePayment(merchantId: string, hashKey: string, hashIv: string, tradeNo: string, amount: number) {
  const npCrypto = new NewebPayCrypto(hashKey, hashIv);
  const postStr = `RespondType=JSON&Version=1.1&Amt=${amount}&TradeNo=${tradeNo}&IndexType=2&CloseType=1`;
  const encrypted = npCrypto.encrypt(postStr);

  return fetch('https://core.newebpay.com/API/CreditCard/Close', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `MerchantID_=${merchantId}&PostData_=${encrypted}`,
  }).then(r => r.json());
}
```

## Credit Card Cancel Authorization API (NPA-B01)

### Endpoint
| Env | URL |
|-----|-----|
| Test | `https://ccore.newebpay.com/API/CreditCard/Cancel` |
| Prod | `https://core.newebpay.com/API/CreditCard/Cancel` |

### PostData
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `RespondType` | string | Y | `"JSON"` |
| `Version` | string | Y | `"1.0"` |
| `Amt` | int | Y | Authorization amount |
| `MerchantOrderNo` | string | Y* | Merchant order number |
| `TradeNo` | string | Y* | NewebPay trade number |
| `IndexType` | int | Y | `1`=MerchantOrderNo, `2`=TradeNo |

## Credit Card Refund API (same endpoint as Close, `CloseType=2`)

```typescript
// Refund
const postStr = `RespondType=JSON&Version=1.1&Amt=${amount}&TradeNo=${tradeNo}&IndexType=2&CloseType=2`;

// Cancel a pending refund
const postStr = `RespondType=JSON&Version=1.1&Amt=${amount}&TradeNo=${tradeNo}&IndexType=2&CloseType=2&Cancel=1`;
```

## E-Wallet Refund API (NPA-B06)

### Endpoint
| Env | URL |
|-----|-----|
| Test | `https://ccore.newebpay.com/API/EWallet/refund` |
| Prod | `https://core.newebpay.com/API/EWallet/refund` |

> **Path is `/refund` (lowercase `r`).** Using `/Refund` returns 404 — easy mistake when copy-pasting from older docs that used `/Refund`.

### Parameters
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `MerchantID` | string | Y | Merchant ID |
| `TradeNo` | string | Y | NewebPay trade number |
| `Amt` | int | Y | Refund amount |
| `PaymentType` | string | Y | `"LINEPAY"`, `"TAIWANPAY"`, `"ESUNWALLET"` |

## BNPL Refund / Cancel API (NPA-B07)

> Used for full-cancel, full-refund, partial-refund, or multiple partial-refund within **1 year** after AFTEE BNPL transaction is established.
> **Different envelope**: outer fields are `UID_` / `EncryptData_` / `HashData_` / `Version_` / `RespondType_` (trailing underscore) — not the MPG `MerchantID` / `TradeInfo` / `TradeSha`.

### Endpoint
| Env | URL |
|-----|-----|
| Test | `https://ccore.newebpay.com/API/Bnpl/refund` |
| Prod | `https://core.newebpay.com/API/Bnpl/refund` |

### Outer POST form fields
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `UID_` | string(15) | Y | Merchant ID (this API names it `UID_` instead of `MerchantID`) |
| `Version_` | string(5) | Y | `"1.1"` |
| `EncryptData_` | text | Y | AES-256-CBC encrypted query-string of inner fields, hex |
| `RespondType_` | string(6) | Y | `"JSON"` or `"String"`. Must match the value inside `EncryptData_` |
| `HashData_` | text | Y | SHA256 over `HashKey={K}&{EncryptData_hex}&HashIV={IV}` (uppercase) |

### Inner fields (URL-encoded query-string, then AES-CBC encrypted)
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `MerchantOrderNo` | string(30) | Y | Merchant order number |
| `Amt` | int(10) | Y | Cancel/refund amount |
| `TimeStamp` | string(50) | Y | Unix timestamp |
| `PaymentType` | string(10) | Y | Fixed `"AFTEE"` |
| `Reason` | string(100) | Y | Refund/cancel reason text |

> **Note**: BNPL refund **does not** use `IndexType` / `TradeNo` (unlike credit-card Close). It identifies the trade by `MerchantOrderNo` only.

### Response (outer)
| Field | Type | Description |
|-------|------|-------------|
| `Status` | string | `"SUCCESS"` or error code |
| `Message` | string | Status description |
| `EncryptData` | text | AES-encrypted inner result |
| `HashData` | text | SHA256 over the EncryptData (verify with same formula) |
| `UID` | string | Merchant ID |
| `Version` | string | `"1.1"` |

### Response inner fields (after decrypt)
| Field | Type | Description |
|-------|------|-------------|
| `MerchantOrderNo` | string | Merchant order number |
| `TradeNo` | string | NewebPay trade number |
| `RefundAmount` | int | Amount refunded this call |
| `RefundDate` | string | Date of refund |
| `RefundType` | string | `"cancel"` or `"refund"` |

### Verification

BNPL responses **do not carry `CheckCode`** (unlike MPG). Verify integrity by recomputing `HashData` over the received `EncryptData`:

```typescript
const expected = sha256(`HashKey=${hashKey}&${response.EncryptData}&HashIV=${hashIv}`).toUpperCase();
const ok = expected === response.HashData;
```

### Example

```typescript
async function refundBnpl(merchantId: string, hashKey: string, hashIv: string, orderNo: string, amount: number, reason: string) {
  const npCrypto = new NewebPayCrypto(hashKey, hashIv);
  const inner = [
    `MerchantOrderNo=${encodeURIComponent(orderNo)}`,
    `Amt=${amount}`,
    `TimeStamp=${Math.floor(Date.now() / 1000)}`,
    `PaymentType=AFTEE`,
    `Reason=${encodeURIComponent(reason)}`,
  ].join('&');
  const encryptData = npCrypto.encrypt(inner);
  const hashData = npCrypto.generateSha(encryptData);

  return fetch('https://core.newebpay.com/API/Bnpl/refund', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      UID_: merchantId,
      Version_: '1.1',
      RespondType_: 'JSON',
      EncryptData_: encryptData,
      HashData_: hashData,
    }).toString(),
  }).then(r => r.json());
}
```

## BNPL Settle (Capture) API (NPA-B62)

> Used to capture an AFTEE BNPL transaction within **89 days** of trade establishment. The `Amt` must equal the original trade amount.

### Endpoint
| Env | URL |
|-----|-----|
| Test | `https://ccore.newebpay.com/API/Bnpl/settle` |
| Prod | `https://core.newebpay.com/API/Bnpl/settle` |

### Outer POST form fields
Same as NPA-B07 above (`UID_` / `Version_=1.1` / `EncryptData_` / `RespondType_` / `HashData_`).

### Inner fields (encrypted)
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `MerchantOrderNo` | string(30) | Y | Merchant order number |
| `Amt` | int(10) | Y | Settle amount — **must equal original trade amount** |
| `TimeStamp` | string(50) | Y | Unix timestamp |
| `PaymentType` | string(10) | Y | Fixed `"AFTEE"` |

### Response inner fields (after decrypt)
| Field | Type | Description |
|-------|------|-------------|
| `MerchantOrderNo` | string | Merchant order number |
| `TradeNo` | string | NewebPay trade number |
| `Amount` | int | Settled amount |
| `CloseDate` | string | Settle date |

## Periodic Payment API (out-of-NDNF — legacy reference)

> NDNF 1.2.2 explicitly **does not** cover periodic payment (定期定額) — that lives in a separate manual. The information below is retained as a legacy reference for the existing project integration; verify against the dedicated periodic-payment manual before changes.

### Create Endpoint
| Env | URL |
|-----|-----|
| Test | `https://ccore.newebpay.com/MPG/period` |
| Prod | `https://core.newebpay.com/MPG/period` |

### Key Parameters
| Parameter | Type | Description |
|-----------|------|-------------|
| `PeriodAmt` | int | Amount per period |
| `PeriodType` | string | `"D"`=daily, `"W"`=weekly, `"M"`=monthly, `"Y"`=yearly |
| `PeriodPoint` | string | Day of week (1-7), day of month (01-31), or MMDD |
| `PeriodStartType` | int | `1`=auth TWD 10 test, `2`=full first charge, `3`=validate only |
| `PeriodTimes` | int | Number of billing cycles |

### Manage
| Action | Endpoint | Key Param |
|--------|----------|-----------|
| Suspend | `POST /API/PeriodAPI/AlterStatus` | `AlterType=suspend` |
| Restart | `POST /API/PeriodAPI/AlterStatus` | `AlterType=restart` |
| Terminate | `POST /API/PeriodAPI/AlterStatus` | `AlterType=terminate` |
| Modify | `POST /API/PeriodAPI/AlterAmt` | new `Amt` |

## Error Codes

### Prefixes
| Prefix | Category |
|--------|----------|
| `MPG` | MPG gateway |
| `TRA` | Transaction |
| `PER` | Periodic payment |

### MPG Errors
| Code | Description |
|------|-------------|
| `MPG01001` | Merchant ID does not exist |
| `MPG01002` | Merchant is disabled |
| `MPG01003` | IP not in whitelist |
| `MPG02004` | EncryptType mismatch — encrypt method (CBC/GCM) does not match `EncryptType` parameter |
| `MPG03002` | Duplicate MerchantOrderNo |
| `MPG03007` | Merchant ID format incorrect |
| `MPG03009` | Transaction failed (general) |
| `MPG03010` | Amount format error |
| `MPG03011` | TradeInfo decryption failed |
| `MPG03012` | TradeSha verification failed |
| `MPG03014` | TimeStamp expired (beyond 120s) |
| `MPG03015` | Version not supported |
| `MPG05001` | Credit card auth failed |
| `MPG05002` | 3D Secure failed |
| `MPG05003` | Card expired |
| `MPG05004` | Insufficient balance |
| `MPG05005` | Amount exceeds limit |

### Transaction Errors
| Code | Description |
|------|-------------|
| `TRA10001` | Trade not found |
| `TRA10002` | Already cancelled |
| `TRA10003` | Already refunded |
| `TRA10012` | Refund exceeds original |
| `TRA10027` | Status does not allow operation |

### Periodic Payment Errors
| Code | Description |
|------|-------------|
| `PER10001` | Order not found |
| `PER10058` | Card auth failed |
| `PER10078` | Config error |

### Bank Response Codes (RespondCode)
| Code | Description |
|------|-------------|
| `00` | Approved |
| `01` | Refer to issuer |
| `05` | Do not honor |
| `12` | Invalid transaction |
| `14` | Invalid card number |
| `33` | Expired card |
| `41` | Lost card |
| `43` | Stolen card |
| `51` | Insufficient funds |
| `54` | Expired card |
| `55` | Incorrect PIN |
| `61` | Exceeds limit |

## Test Environment

### Endpoints (all use `ccore.newebpay.com`)
- MPG: `https://ccore.newebpay.com/MPG/mpg_gateway`
- Query: `https://ccore.newebpay.com/API/QueryTradeInfo`
- Close: `https://ccore.newebpay.com/API/CreditCard/Close`
- Cancel: `https://ccore.newebpay.com/API/CreditCard/Cancel`
- EWallet refund: `https://ccore.newebpay.com/API/EWallet/refund`
- BNPL refund: `https://ccore.newebpay.com/API/Bnpl/refund`
- BNPL settle: `https://ccore.newebpay.com/API/Bnpl/settle`

### Sandbox Console
- Merchant dashboard: `https://cwww.newebpay.com/`
- Register a test account to obtain MerchantID, HashKey, HashIV.

### Test Credit Cards
| Card Number | Result |
|-------------|--------|
| `4000-2211-1111-1111` | Successful authorization |
| `4000-2222-2222-2222` | 3D Secure flow test |

> ⚠️ **NewebPay-only**：以上卡號僅適用於 NewebPay sandbox。
> 不要塞進 PAYUNi（會授權失敗）或 ECPay sandbox。各家測試卡號互不相通：
> - PAYUNi 用 `4147631000000001` — 見 `payuni-upp-v2` / `payuni-uni-embed-v3`
> - ECPay 見 `ECPay-API-Skill`

### Sandbox Detection (project pattern)

```typescript
const isTest = !credentials.merchantId.startsWith('M') || credentials.merchantId === 'MS154450763';
const baseUrl = isTest ? 'https://ccore.newebpay.com' : 'https://core.newebpay.com';
```

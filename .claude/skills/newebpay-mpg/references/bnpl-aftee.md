# NewebPay AFTEE BNPL (Buy Now, Pay Later) — Complete Flow

> **Spec source**: NDNF-1.2.2 (revised 2026-04-21). AFTEE 先享後付 is new in NDNF 1.2.2.
> Requires MPG `Version` ≥ `"2.2"` (because `OrderDetail` was introduced in v2.2).

## Overview

AFTEE (先享後付) is a buy-now-pay-later product. Three life-cycle APIs:

| Stage | API | When | Notes |
|-------|-----|------|-------|
| 1. Authorize | MPG (TradeInfo) | Checkout | Buyer is approved by AFTEE; trade is "authorized" |
| 2. Settle | NPA-B62 `/API/Bnpl/settle` | Within 89 days of authorization | Capture the authorized amount (must equal original `Amt`) |
| 3. Refund/Cancel | NPA-B07 `/API/Bnpl/refund` | Within 1 year of authorization | Cancel/full-refund/partial-refund/multiple partial-refund |

> **Constraints**: single-order `Amt` ≤ 49,999 TWD. Above the cap the AFTEE option is hidden on the MPG payment page even with `AFTEE=1` set.

## Stage 1 — Authorize via MPG

### TradeInfo parameters specific to AFTEE

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `AFTEE` | int | Y | `1`=one-off, `2`=one-off + installment, `0`/omit=disabled |
| `AFTEE_Inst` | string(21) | Required when `AFTEE=2` | Installment periods: omit/`1` = all periods; specify periods comma-separated from `3,6,9,12,15,18,21,24` (e.g., `"3,6,12"`) |
| `OrderDetail` | string | Y when `AFTEE` enabled | JSON-stringified array of items. See below |

### `OrderDetail` schema

JSON array. Each item has 4 mandatory fields. Sum of `ItemAmt` over all items must exactly equal MPG's `Amt`.

| Field | Type | MaxLen | Description |
|-------|------|--------|-------------|
| `ItemName` | string | 20 | Item name |
| `ItemAmt` | int | 10 | Item subtotal. **Can be negative** (use `-N` for discounts). Sum across items = `Amt` |
| `ItemType` | int | 1 | `1`=normal product, `2`=ticket, `3`=stored-value, `4`=discount |
| `ItemOrderNo` | string | 20 | Item SKU. Must be unique within the order |

### Example `OrderDetail`

```json
[
  { "ItemName": "電競筆電", "ItemAmt": 32000, "ItemType": 1, "ItemOrderNo": "SKU-LAPTOP-001" },
  { "ItemName": "藍牙耳機", "ItemAmt": 1990, "ItemType": 1, "ItemOrderNo": "SKU-BT-002" },
  { "ItemName": "新會員 9 折", "ItemAmt": -3399, "ItemType": 4, "ItemOrderNo": "DISCOUNT-1" }
]
```

If MPG's `Amt` is `30591` (= 32000 + 1990 − 3399), this `OrderDetail` is valid.

### Building TradeInfo with AFTEE (TypeScript)

```typescript
import { NewebPayCrypto } from './newebpay-crypto';

interface AfteeOrderItem {
  ItemName: string;       // <= 20 chars
  ItemAmt: number;        // sum across items must equal Amt
  ItemType: 1 | 2 | 3 | 4;
  ItemOrderNo: string;    // <= 20 chars, unique within order
}

function createAfteeTradeInfo(params: {
  merchantId: string;
  orderNo: string;
  amount: number;          // must be <= 49_999
  itemDesc: string;
  email: string;
  notifyUrl: string;
  returnUrl: string;
  items: AfteeOrderItem[];
  installment?: boolean;   // default false (one-off)
  installmentPeriods?: string; // e.g. "3,6,12"; default = all
}, hashKey: string, hashIv: string) {
  // Validate cap
  if (params.amount > 49_999) {
    throw new Error('AFTEE single order Amt cap is 49,999 TWD');
  }
  // Validate sum
  const sum = params.items.reduce((s, i) => s + i.ItemAmt, 0);
  if (sum !== params.amount) {
    throw new Error(`OrderDetail sum (${sum}) must equal Amt (${params.amount})`);
  }

  const npCrypto = new NewebPayCrypto(hashKey, hashIv);

  const tradeParams: Record<string, string | number> = {
    MerchantID: params.merchantId,
    RespondType: 'JSON',
    TimeStamp: Math.floor(Date.now() / 1000),
    Version: '2.3',                          // ≥ 2.2 required for OrderDetail
    MerchantOrderNo: params.orderNo,
    LoginType: 0,
    Amt: params.amount,
    ItemDesc: params.itemDesc,
    Email: params.email,
    NotifyURL: params.notifyUrl,
    ReturnURL: params.returnUrl,
    AFTEE: params.installment ? 2 : 1,
    OrderDetail: JSON.stringify(params.items),
  };
  if (params.installment && params.installmentPeriods) {
    tradeParams.AFTEE_Inst = params.installmentPeriods;
  }

  const tradeString = Object.entries(tradeParams)
    .map(([k, v]) => `${k}=${encodeURIComponent(String(v))}`)
    .join('&');

  const tradeInfo = npCrypto.encrypt(tradeString);
  const tradeSha = npCrypto.generateSha(tradeInfo);

  return {
    formData: {
      MerchantID: params.merchantId,
      TradeInfo: tradeInfo,
      TradeSha: tradeSha,
      Version: '2.3',
    },
  };
}
```

### Callback (after buyer approves with AFTEE)

After AFTEE authorization, MPG callback arrives at `NotifyURL` with:

```json
{
  "Status": "SUCCESS",
  "Message": "...",
  "Result": {
    "MerchantID": "...",
    "Amt": 30591,
    "TradeNo": "26050412345678",
    "MerchantOrderNo": "ORDER-001",
    "PaymentType": "AFTEE",
    "RespondCode": "00",
    "PayTime": "2026-05-04 10:30:00",
    "Inst": 3,
    "CheckCode": "..."
  }
}
```

Verify `TradeSha` and `CheckCode` exactly as for any other MPG callback. Mark the order as **authorized but not yet captured**.

## Stage 2 — Settle (Capture)

After fulfillment is confirmed (e.g., goods shipped), call NPA-B62 to capture the authorized amount.

### Endpoint
- Test: `https://ccore.newebpay.com/API/Bnpl/settle`
- Prod: `https://core.newebpay.com/API/Bnpl/settle`

### Outer POST form fields
- `UID_` = MerchantID (note trailing underscore)
- `Version_` = `"1.1"`
- `EncryptData_` = AES-256-CBC encrypted inner string (hex)
- `RespondType_` = `"JSON"`
- `HashData_` = SHA256 over the encrypted blob (uppercase, same formula as TradeSha)

### Inner fields (URL-encoded query string, then encrypted)
- `MerchantOrderNo` = order number (matches Stage 1)
- `Amt` = **must equal original MPG Amt**
- `TimeStamp` = unix seconds
- `PaymentType` = fixed `"AFTEE"`

### Example

```typescript
async function settleBnpl(params: {
  merchantId: string;
  orderNo: string;
  amount: number;
}, hashKey: string, hashIv: string, isTest = false) {
  const npCrypto = new NewebPayCrypto(hashKey, hashIv);
  const inner = [
    `MerchantOrderNo=${encodeURIComponent(params.orderNo)}`,
    `Amt=${params.amount}`,
    `TimeStamp=${Math.floor(Date.now() / 1000)}`,
    `PaymentType=AFTEE`,
  ].join('&');
  const encryptData = npCrypto.encrypt(inner);
  const hashData = npCrypto.generateSha(encryptData);

  const url = isTest
    ? 'https://ccore.newebpay.com/API/Bnpl/settle'
    : 'https://core.newebpay.com/API/Bnpl/settle';

  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      UID_: params.merchantId,
      Version_: '1.1',
      RespondType_: 'JSON',
      EncryptData_: encryptData,
      HashData_: hashData,
    }).toString(),
  }).then(r => r.json());

  // Verify HashData over EncryptData
  if (res.Status === 'SUCCESS') {
    const expected = npCrypto.generateSha(res.EncryptData);
    if (expected !== res.HashData) {
      throw new Error('Settle response HashData mismatch');
    }
    const inner = JSON.parse(npCrypto.decrypt(res.EncryptData));
    return inner; // { MerchantOrderNo, TradeNo, Amount, CloseDate }
  }
  throw new Error(`Settle failed: ${res.Status} ${res.Message}`);
}
```

## Stage 3 — Refund / Cancel

NPA-B07 covers full-cancel, full-refund, partial-refund, and multiple partial-refunds within 1 year of authorization.

### Endpoint
- Test: `https://ccore.newebpay.com/API/Bnpl/refund`
- Prod: `https://core.newebpay.com/API/Bnpl/refund`

### Outer POST form fields
Same envelope as Stage 2 (`UID_`, `Version_=1.1`, `EncryptData_`, `RespondType_`, `HashData_`).

### Inner fields (encrypted)
- `MerchantOrderNo` = order number
- `Amt` = cancel/refund amount (≤ remaining balance)
- `TimeStamp` = unix seconds
- `PaymentType` = fixed `"AFTEE"`
- `Reason` = string up to 100 chars, refund/cancel reason text

> Unlike credit-card Close, BNPL refund **does not** use `IndexType` / `TradeNo`. The order is identified by `MerchantOrderNo` only.

### Example

```typescript
async function refundBnpl(params: {
  merchantId: string;
  orderNo: string;
  amount: number;
  reason: string;
}, hashKey: string, hashIv: string, isTest = false) {
  const npCrypto = new NewebPayCrypto(hashKey, hashIv);
  const inner = [
    `MerchantOrderNo=${encodeURIComponent(params.orderNo)}`,
    `Amt=${params.amount}`,
    `TimeStamp=${Math.floor(Date.now() / 1000)}`,
    `PaymentType=AFTEE`,
    `Reason=${encodeURIComponent(params.reason)}`,
  ].join('&');
  const encryptData = npCrypto.encrypt(inner);
  const hashData = npCrypto.generateSha(encryptData);

  const url = isTest
    ? 'https://ccore.newebpay.com/API/Bnpl/refund'
    : 'https://core.newebpay.com/API/Bnpl/refund';

  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      UID_: params.merchantId,
      Version_: '1.1',
      RespondType_: 'JSON',
      EncryptData_: encryptData,
      HashData_: hashData,
    }).toString(),
  }).then(r => r.json());

  if (res.Status === 'SUCCESS') {
    const expected = npCrypto.generateSha(res.EncryptData);
    if (expected !== res.HashData) {
      throw new Error('Refund response HashData mismatch');
    }
    const inner = JSON.parse(npCrypto.decrypt(res.EncryptData));
    // { MerchantOrderNo, TradeNo, RefundAmount, RefundDate, RefundType: "cancel" | "refund" }
    return inner;
  }
  throw new Error(`Refund failed: ${res.Status} ${res.Message}`);
}
```

## Validation gotchas

1. **Sum check**: `OrderDetail` items' `ItemAmt` sum must EXACTLY equal MPG `Amt`. Off-by-one on rounded discount → trade rejected. Always round at the discount-line level, not at the order level.
2. **Amt cap**: 49,999 TWD per order. Above the cap, AFTEE option is hidden even though all parameters are correct.
3. **Version**: must be `"2.2"` or above. Sending `Version="2.0"` with `OrderDetail` set silently fails AFTEE pre-auth — Version 2.0 does not understand the field.
4. **`AFTEE_Inst` only when `AFTEE=2`**: setting `AFTEE_Inst` while `AFTEE=1` is undefined behavior; either drop `AFTEE_Inst` or set `AFTEE=2`.
5. **Settle Amt = original Amt**: NPA-B62 cannot do partial settle. Use refund (NPA-B07) afterwards if you need to reduce the captured amount.
6. **No `CheckCode` on BNPL response**: validate using `HashData` over `EncryptData` instead. Code that copy-pastes from MPG callback verification will misfire.
7. **`ItemOrderNo` uniqueness within the order**: duplicate SKU on two lines → trade rejected.
8. **Negative `ItemAmt` for discounts**: use `ItemType=4` and a negative `ItemAmt`, not a separate "discount" mechanism.

## Project hookup notes (zenbu-site)

zenbu-site does not yet integrate AFTEE. When it does, suggested touchpoints:

- Add a new method `NewebpayService.createAfteeTradeInfo()` mirroring `createPaymentForm()` but with the AFTEE/OrderDetail/Inst fields.
- Persist `OrderDetail` items alongside the `OrderEntity` so settle/refund can recompute exact subtotals.
- Settle is typically triggered when the order transitions to `shipped` — wire it into `OrdersService.markShipped()` or a separate cron.
- Refund logic lives on `ReturnsService` — reuse the existing return flow, branch by `paymentMethod === 'AFTEE'`.

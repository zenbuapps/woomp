# NewebPay MPG: v2.0 → v2.3 Migration Guide

> **Spec source**: NDNF-1.2.2 (revised 2026-04-21).
> **TL;DR**: Encryption rules unchanged. The only forced change is the `Version` string. Everything else is opt-in (TWQR, smart-ATM 2.0, GCM, AFTEE), and v2.0 code keeps working unchanged against v2.3 endpoints.

## Why upgrade?

You only need to upgrade if you want any of the new features:

| Feature | Available since | Why upgrade |
|---------|-----------------|-------------|
| TWQR cross-org QR (`TWQR`, `TWQR_LifeTime`) | v2.3 | Cover Taiwan TWQR scheme + simple-pay e-wallets |
| Smart-ATM 2.0 (`SourceType`/`SourceBankId`/`SourceAccountNo`) | v2.3 | Force buyers onto a specific bank/account for ATM transfer (KGI/BOT/Hua Nan) |
| KGI Bank (凱基) on bank list | v2.3 | Bank list expansion |
| AFTEE BNPL (`AFTEE`, `AFTEE_Inst`, `OrderDetail`) | NDNF 1.2.2 (Version ≥ 2.2) | Buy-now-pay-later 先買後付 |
| AES-256-GCM (`EncryptType=1`) | NDNF 1.2.2 | Stronger AEAD encryption (caveat: undocumented wire format) |

If you don't need any of these, **stay on `Version=2.0`**. NewebPay accepts it.

## What does NOT change

- Endpoint URLs (still `*.newebpay.com/MPG/mpg_gateway`)
- AES-256-CBC + PKCS7 encryption (still default)
- SHA256 TradeSha formula (`HashKey={K}&{hex}&HashIV={IV}`, uppercase)
- POST form fields (`MerchantID`, `TradeInfo`, `TradeSha`, `Version`)
- Required TradeInfo parameters (`MerchantID`, `RespondType`, `TimeStamp`, `Version`, `MerchantOrderNo`, `Amt`, `ItemDesc`, `Email`)
- Existing payment-method parameters (`CREDIT`, `WEBATM`, `VACC`, `CVS`, `BARCODE`, `CVSCOM`, `LINEPAY`, `ESUNWALLET`, `TAIWANPAY`, `ANDROIDPAY`, `SAMSUNGPAY`, `APPLEPAY`, `BITOPAY`, `UNIONPAY`, `CREDITAE`, `CreditRed`, `InstFlag`)
- Card token / 3DS / recurring (`TokenTerm`, `TokenLife`, `CREDITAGREEMENT`, `P3D`)
- Callback structure (`Status`, `Result.{...}`, `CheckCode`)
- Backend APIs (`QueryTradeInfo` v1.3, `Close` v1.1, `Cancel` v1.0) — independent versioning, not affected

## What DOES change

### 1. `Version` string

```diff
  const tradeParams = {
    MerchantID: ...,
    RespondType: 'JSON',
    TimeStamp: ...,
-   Version: '2.0',
+   Version: '2.3',
    ...
  };
```

That's it for the minimal upgrade. The encrypted payload is structurally identical — only the version label is different.

### 2. (Optional) Add TWQR

```diff
  const tradeParams = {
    ...
    Version: '2.3',
    CREDIT: 1,
+   TWQR: 1,
+   TWQR_LifeTime: 600, // optional, default 300, max 2_678_400 (31 days)
  };
```

### 3. (Optional) Add Smart-ATM 2.0 with forced bank/account

```diff
  const tradeParams = {
    ...
    Version: '2.3',
    VACC: 1,
+   SourceType: 1,             // 1 or 3 = forced (no edit), 2 or 4 = editable
+   SourceBankId: '809',       // KGI bank code, required when SourceType=1 or 3
+   SourceAccountNo: '1234567890',
  };
```

### 4. (Optional) Add AFTEE BNPL

> Requires Version ≥ `"2.2"`; pairs with `OrderDetail` (mandatory). See `bnpl-aftee.md` for full schema.

```diff
  const tradeParams = {
    ...
    Version: '2.3',                                  // or '2.2' minimum
+   AFTEE: 2,                                        // 1=one-off, 2=one-off+installment
+   AFTEE_Inst: '3,6,12',                            // when AFTEE=2; default = all periods
+   OrderDetail: JSON.stringify([
+     { ItemName: 'Laptop', ItemAmt: 30000, ItemType: 1, ItemOrderNo: 'SKU-001' },
+   ]),                                              // ItemAmt sum must equal Amt
  };
```

### 5. (Optional) Switch to AES-256-GCM

> **Caveat**: NDNF 1.2.2 does not document the GCM wire format. Confirm with NewebPay before enabling.

```diff
  const tradeParams = {
    ...
    Version: '2.3',
+   EncryptType: 1,           // 0/omit = CBC (default), 1 = GCM
  };

- const tradeInfo = npCrypto.encrypt(tradeString);                       // CBC
+ const tradeInfo = npCryptoGcm.encrypt(tradeString);                    // GCM (see examples.md)
  const tradeSha = npCrypto.generateSha(tradeInfo);
```

If `EncryptType` parameter and the actual encryption don't match (e.g., declare `1` but encrypt with CBC), NewebPay returns `MPG02004`.

### 6. Removed: `ezPay`

If your code references the `ezPay` parameter, remove it. It is no longer accepted in v2.3.

## Upgrade checklist

```
[ ] Change Version='2.0' to '2.3' in TradeInfo construction
[ ] Run a sandbox transaction with the new version, confirm callback Status='SUCCESS'
[ ] (If using ezPay) remove the ezPay parameter
[ ] (If adopting new features) add the relevant params, test individually
[ ] (If switching to GCM) confirm wire format with vendor, test, leave EncryptType=0 as fallback
```

## Validation: smoke-test after upgrade

Run the existing CBC encrypt → submit → callback flow with `Version='2.3'`. Expected:
- POST to `https://ccore.newebpay.com/MPG/mpg_gateway` returns the MPG payment page
- After test card auth, callback `Status='SUCCESS'`
- `TradeSha` verifies, `CheckCode` verifies
- No new error codes (especially no `MPG03015` "Version not supported")

If a regression appears, immediately roll `Version` back to `'2.0'` — same code path, no encryption changes — and report to NewebPay.

## Project-specific notes (zenbu-site)

The project currently sends `Version='2.0'`. Migration touchpoint is one constant in:

```
apps/api-gateway/src/commerce/payments/newebpay/newebpay.service.ts
```

When upgrading:
1. Change the Version string constant.
2. Update fixtures under `apps/api-gateway/tests/fixtures/newebpay/` if they contain `"Version":"2.0"`.
3. Re-run `pnpm --filter @zenbu-site/api-gateway test` and `test:int`.
4. Sandbox transaction via Cloudflare Tunnel (`bash scripts/start-tunnel.sh`) to verify callback round-trip.

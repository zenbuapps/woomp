# NewebPay MPG TypeScript/NestJS Examples (NDNF 1.2.2)

> Complete runnable TypeScript examples for NewebPay MPG integration.
> All endpoints aligned to `*.newebpay.com` (legacy `*.spgateway.com` is alias).

## Table of Contents
1. Encryption Utilities (CBC default + GCM caveat)
2. Create Payment Form
3. Handle Callback (NotifyURL)
4. Handle Return (ReturnURL)
5. Verify CheckCode
6. Frontend Auto-Submit Form
7. Full NestJS Service Reference

## Encryption Utilities

### Default: AES-256-CBC + PKCS7 (`EncryptType=0` or omitted)

```typescript
// newebpay-crypto.ts
import * as crypto from 'crypto';

export class NewebPayCrypto {
  constructor(
    private readonly hashKey: string,
    private readonly hashIv: string,
  ) {}

  /** AES-256-CBC encrypt. Input: URL-encoded query string. Output: hex. */
  encrypt(data: string): string {
    const key = this.hashKey.padEnd(32, '0').slice(0, 32);
    const iv = this.hashIv.padEnd(16, '0').slice(0, 16);
    const cipher = crypto.createCipheriv('aes-256-cbc', Buffer.from(key), Buffer.from(iv));
    return cipher.update(data, 'utf8', 'hex') + cipher.final('hex');
  }

  /** AES-256-CBC decrypt. Input: hex. Output: JSON or URL-encoded string. */
  decrypt(encrypted: string): string {
    const key = this.hashKey.padEnd(32, '0').slice(0, 32);
    const iv = this.hashIv.padEnd(16, '0').slice(0, 16);
    const decipher = crypto.createDecipheriv('aes-256-cbc', Buffer.from(key), Buffer.from(iv));
    return decipher.update(encrypted, 'hex', 'utf8') + decipher.final('utf8');
  }

  /** SHA256("HashKey={key}&{encrypted}&HashIV={iv}") -> UPPERCASE.
   *  Same formula serves MPG TradeSha and BNPL HashData_. */
  generateSha(encryptedHex: string): string {
    const raw = `HashKey=${this.hashKey}&${encryptedHex}&HashIV=${this.hashIv}`;
    return crypto.createHash('sha256').update(raw).digest('hex').toUpperCase();
  }

  /** Verify CheckCode from MPG callback (different field order than TradeSha) */
  verifyCheckCode(
    result: { Amt: number; MerchantID: string; MerchantOrderNo: string; TradeNo: string },
    receivedCheckCode: string,
  ): boolean {
    const raw = `HashIV=${this.hashIv}&Amt=${result.Amt}&MerchantID=${result.MerchantID}&MerchantOrderNo=${result.MerchantOrderNo}&TradeNo=${result.TradeNo}&HashKey=${this.hashKey}`;
    const computed = crypto.createHash('sha256').update(raw).digest('hex').toUpperCase();
    return computed === receivedCheckCode;
  }
}
```

### Opt-in: AES-256-GCM (`EncryptType=1`) — caveat warning

> **NDNF 1.2.2 caveat (read first)**: The official PDF only declares that `EncryptType=1` enables AES-256-GCM. It does **not** specify:
> - IV length (12 bytes is the cryptographic standard, but the PDF does not confirm)
> - whether `authTag` is concatenated to ciphertext or returned separately
> - whether output is hex / base64 / something else
> - whether AAD (Additional Authenticated Data) is used and what its value is
>
> All PHP samples in the manual are CBC. There is **no Node.js sample at all**.
> **Before enabling GCM in production, contact NewebPay technical support and confirm the wire format.**
> The class below uses the conventional cryptography defaults — 12-byte IV, 16-byte authTag concatenated after ciphertext, hex output, no AAD. Treat as a starting point and verify against a sandbox round-trip with vendor confirmation.

```typescript
// newebpay-crypto-gcm.ts — UNVERIFIED, confirm with NewebPay before production use
import * as crypto from 'crypto';

export class NewebPayCryptoGcm {
  constructor(
    private readonly hashKey: string,
    private readonly hashIv: string,
  ) {}

  /** AES-256-GCM encrypt. Output: hex(ciphertext + 16-byte authTag).
   *  IV uses the leading 12 bytes of HashIV (right-padded with '0'). */
  encrypt(data: string): string {
    const key = this.hashKey.padEnd(32, '0').slice(0, 32);
    const iv = this.hashIv.padEnd(12, '0').slice(0, 12);
    const cipher = crypto.createCipheriv('aes-256-gcm', Buffer.from(key), Buffer.from(iv));
    const enc = Buffer.concat([cipher.update(data, 'utf8'), cipher.final()]);
    const tag = cipher.getAuthTag(); // 16 bytes
    return Buffer.concat([enc, tag]).toString('hex');
  }

  /** AES-256-GCM decrypt. Input: hex(ciphertext + 16-byte authTag). */
  decrypt(encryptedHex: string): string {
    const key = this.hashKey.padEnd(32, '0').slice(0, 32);
    const iv = this.hashIv.padEnd(12, '0').slice(0, 12);
    const buf = Buffer.from(encryptedHex, 'hex');
    const ciphertext = buf.slice(0, buf.length - 16);
    const tag = buf.slice(buf.length - 16);
    const decipher = crypto.createDecipheriv('aes-256-gcm', Buffer.from(key), Buffer.from(iv));
    decipher.setAuthTag(tag);
    return Buffer.concat([decipher.update(ciphertext), decipher.final()]).toString('utf8');
  }
}
```

### When to use which

- **CBC (default)**: All current zenbu-site code. Stable, well-documented, PHP samples match.
- **GCM (opt-in)**: Only after the wire format is confirmed with NewebPay. Set `EncryptType=1` in TradeInfo, then encrypt with the GCM class. If callbacks fail with `MPG02004`, your encrypt method does not match the declared `EncryptType`.

## Create Payment Form

```typescript
import { NewebPayCrypto } from './newebpay-crypto';

interface CreatePaymentParams {
  merchantId: string;
  orderNo: string;
  amount: number;
  itemDesc: string;
  email: string;
  returnUrl?: string;
  notifyUrl?: string;
  clientBackUrl?: string;
}

function createPaymentForm(
  params: CreatePaymentParams,
  hashKey: string,
  hashIv: string,
): { formUrl: string; formData: Record<string, string> } {
  const npCrypto = new NewebPayCrypto(hashKey, hashIv);
  const timestamp = Math.floor(Date.now() / 1000);

  const tradeParams: Record<string, string | number> = {
    MerchantID: params.merchantId,
    RespondType: 'JSON',
    TimeStamp: timestamp,
    Version: '2.3',                   // NDNF 1.2.2 current spec; '2.0' also works
    MerchantOrderNo: params.orderNo,
    LoginType: 0,
    Amt: params.amount,
    ItemDesc: params.itemDesc,
    Email: params.email,
    CREDIT: 1,
  };
  if (params.returnUrl) tradeParams.ReturnURL = params.returnUrl;
  if (params.notifyUrl) tradeParams.NotifyURL = params.notifyUrl;
  if (params.clientBackUrl) tradeParams.ClientBackURL = params.clientBackUrl;

  const tradeString = Object.entries(tradeParams)
    .map(([k, v]) => `${k}=${encodeURIComponent(String(v))}`)
    .join('&');

  const tradeInfo = npCrypto.encrypt(tradeString);
  const tradeSha = npCrypto.generateSha(tradeInfo);

  const isTest = !params.merchantId.startsWith('M') || params.merchantId === 'MS154450763';

  return {
    formUrl: isTest
      ? 'https://ccore.newebpay.com/MPG/mpg_gateway'
      : 'https://core.newebpay.com/MPG/mpg_gateway',
    formData: {
      MerchantID: params.merchantId,
      TradeInfo: tradeInfo,
      TradeSha: tradeSha,
      Version: '2.3',
    },
  };
}
```

## Handle Callback (NotifyURL)

```typescript
interface NewebPayResult {
  Status: string;
  Message: string;
  Result: {
    MerchantID: string;
    Amt: number;
    TradeNo: string;
    MerchantOrderNo: string;
    PaymentType: string;
    RespondCode: string;
    CheckCode: string;
    Card6No?: string;
    Card4No?: string;
    AuthCode?: string;
  };
}

function handleNotify(
  body: { TradeInfo: string; TradeSha: string },
  hashKey: string,
  hashIv: string,
): { success: boolean; result?: NewebPayResult } {
  const npCrypto = new NewebPayCrypto(hashKey, hashIv);

  // 1. Verify TradeSha (tampering check)
  if (npCrypto.generateSha(body.TradeInfo) !== body.TradeSha) {
    return { success: false };
  }

  // 2. Decrypt
  const parsed: NewebPayResult = JSON.parse(npCrypto.decrypt(body.TradeInfo));

  // 3. Check status
  if (parsed.Status !== 'SUCCESS') {
    return { success: false, result: parsed };
  }

  // 4. Verify CheckCode (cross-check on Result fields)
  if (!npCrypto.verifyCheckCode(parsed.Result, parsed.Result.CheckCode)) {
    return { success: false, result: parsed };
  }

  return { success: true, result: parsed };
}
```

## Handle Return (ReturnURL)

```typescript
// NestJS controller for foreground redirect — UX only, do NOT mark order paid here
@Post('return')
async handleReturn(@Body() body: any, @Res() res: Response) {
  const creds = await this.loadCredentials();
  const npCrypto = new NewebPayCrypto(creds.hashKey, creds.hashIv);

  try {
    const parsed = JSON.parse(npCrypto.decrypt(body.TradeInfo));
    if (parsed.Status === 'SUCCESS') {
      res.redirect(`/checkout/success?order=${parsed.Result.MerchantOrderNo}`);
    } else {
      res.redirect(`/checkout/failed?reason=${encodeURIComponent(parsed.Message)}`);
    }
  } catch {
    res.redirect('/checkout/failed');
  }
}
```

> **Why ReturnURL is UX-only**: a user may close the browser before redirect lands. NotifyURL is server-to-server and is the source of truth for payment state.

## Verify CheckCode

```typescript
function verifyCheckCode(
  hashKey: string, hashIv: string,
  result: { Amt: number; MerchantID: string; MerchantOrderNo: string; TradeNo: string },
  received: string,
): boolean {
  // Order: HashIV, Amt, MerchantID, MerchantOrderNo, TradeNo, HashKey
  const str = `HashIV=${hashIv}&Amt=${result.Amt}&MerchantID=${result.MerchantID}&MerchantOrderNo=${result.MerchantOrderNo}&TradeNo=${result.TradeNo}&HashKey=${hashKey}`;
  return crypto.createHash('sha256').update(str).digest('hex').toUpperCase() === received;
}
```

## Frontend Auto-Submit Form

```typescript
// React/Next.js: redirect to NewebPay payment page via auto-submitted form
function redirectToNewebPay(formUrl: string, params: Record<string, string>) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = formUrl;
  form.style.display = 'none';
  Object.entries(params).forEach(([key, value]) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = key;
    input.value = value;
    form.appendChild(input);
  });
  document.body.appendChild(form);
  form.submit();
}

// Usage:
const { formUrl, formData } = await fetch(
  `/v1/commerce/payments/newebpay/form/${token}`,
  { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ clientBackUrl: window.location.href }) }
).then(r => r.json());
redirectToNewebPay(formUrl, formData);
```

## Full NestJS Service Reference

The project implementation is at `apps/api-gateway/src/commerce/payments/newebpay/newebpay.service.ts`.

Key integration points:
- `CheckoutService.verifySession(token)` — validates checkout session
- `CheckoutService.markCompleted(token)` — marks session as paid
- `OrdersService.createFromPayment(data)` — creates order record
- `CheckoutService.recordUsageFromSession(token, orderId)` — records discount usage
- Credentials loaded from `settings` table via `SettingsService` (per-tenant)

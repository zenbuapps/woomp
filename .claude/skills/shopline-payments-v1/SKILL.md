---
name: shopline-payments-v1
description: >
  SHOPLINE Payments v1 API complete technical reference for redirect/regular mode payment integration.
  Covers Create Session API, Session Query, webhook (Event) verification with HMAC-SHA256,
  all payment methods (CreditCard, LinePay, VirtualAccount, ApplePay, JKOPay, ChaileaseBNPL),
  error codes, status codes, and sandbox test data.
  Use this skill whenever the task involves: import or code referencing shopline, ShoplineService,
  ShoplineController, shoplinepayments.com, SHOPLINE Payments, SLP API, trade/sessions/create,
  payment session redirect, webhook sign verification with signKey, or any payment gateway integration
  for the zenbu-site project's Shopline module.
  Even if the user doesn't say "Shopline", if the task involves the commerce/payments/shopline/ directory
  or TWD payment processing with redirect mode, use this skill.
  This skill covers API v1 only (apiVersion: V1.2 for webhooks).
---

# SHOPLINE Payments v1 (Redirect Mode)

> **API Version**: v1 | **Webhook apiVersion**: V1.2 | **Docs**: https://docs.shoplinepayments.com/ | **Last Updated**: 2026-04-30

SHOPLINE Payments (SLP) is a Taiwan-focused payment gateway. Redirect mode creates a checkout Session via Server-API, obtains a `sessionUrl`, redirects the customer to Shopline's hosted payment page, and receives results via Webhook Event notifications.

## Companion Tool: `shopline-payments-mcp` MCP

When this SKILL does not cover a detail (newly released endpoint, edge case in official documentation, or up-to-date integration guide content), use the **`shopline-payments-mcp`** MCP server to fetch the official documentation site directly.

- Tool: `mcp__shopline-payments-mcp__get_slpayment_docs()` -- returns the official docs landing page (HTML)
- Use cases: confirm latest API changes, fetch official integration flow descriptions, cross-check against this SKILL when in doubt
- This SKILL takes precedence for everything documented below (parameters, code samples, error codes, sandbox data, pitfalls). The MCP is a fallback for what is not yet captured here.

## Environment

| Environment | Base URL |
|---|---|
| Sandbox | `https://api-sandbox.shoplinepayments.com` |
| Production | `https://api.shoplinepayments.com` |

Sandbox dashboard: `https://login.shoplinepayments.com/zh-Hant/signin/`

## Authentication (HTTP Headers)

Every Server API request requires these headers:

| Header | Type | Required | Description |
|---|---|---|---|
| `Content-Type` | String | Yes | Fixed: `application/json` |
| `merchantId` | String | Yes | SLP-assigned merchant ID |
| `apiKey` | String | Yes | API key (secret, server-side only) |
| `requestId` | String(32) | Yes | Unique per HTTP request (use `crypto.randomUUID()`) |
| `platformId` | String | Platform only | SLP platform ID (only for platform merchants) |
| `idempotentKey` | String(32) | No | Idempotency key |

**Three credential keys** issued by Shopline:
- `apiKey` -- Server API authentication
- `clientKey` -- SDK authentication (not used in redirect mode)
- `signKey` -- Webhook signature verification

## Amount Format

All amounts are in **cents** (smallest currency unit):

```typescript
// NT$100 = 10000 cents
const amount = { value: Math.round(priceInDollars * 100), currency: 'TWD' };
// NT$1 = 100, NT$99.50 = 9950
```

**Currency**: Only `TWD` is supported.

## Payment Methods

| Method Key | Name | Notes |
|---|---|---|
| `CreditCard` | Credit Card | Visa/MC/JCB; supports installments via `paymentMethodOptions` |
| `ApplePay` | Apple Pay | Requires domain verification; no `paymentMethodOptions` |
| `LinePay` | LINE Pay | No `paymentMethodOptions` supported |
| `VirtualAccount` | ATM Bank Transfer | Virtual bank account; `paymentExpireTime` 1440-86400 min |
| `JKOPay` | JKO Pay | Default expire 60 min |
| `ChaileaseBNPL` | Chailease zingla BNPL | Buy-now-pay-later; supports installments |

Set `allowPaymentMethodList` array to control which methods appear on the payment page. Array order = display order.

## Core API: Create Session (Redirect Mode)

```
POST {BASE_URL}/api/v1/trade/sessions/create
```

### Minimal TypeScript Example

```typescript
import * as crypto from 'crypto';

interface CreateSessionResponse {
  sessionId: string;     // SLP session order ID
  referenceId: string;   // Merchant order ID (echo back)
  status: 'CREATED';     // Always CREATED on success
  sessionUrl: string;    // Redirect customer to this URL
  createTime: string;    // Unix timestamp in ms
  amount: { value: number; currency: string };
}

const requestId = crypto.randomUUID();
const referenceId = `ORD-${Date.now()}`.slice(0, 32);

const res = await fetch(`${BASE_URL}/api/v1/trade/sessions/create`, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    merchantId: MERCHANT_ID,
    apiKey: API_KEY,
    requestId,
  },
  body: JSON.stringify({
    referenceId,
    amount: { value: 10000, currency: 'TWD' },
    returnUrl: 'https://example.com/checkout/success',
    mode: 'regular',
    language: 'zh-TW',
    expireTime: 360,
    allowPaymentMethodList: ['CreditCard', 'LinePay', 'VirtualAccount'],
    order: {
      products: [{
        id: 'prod-001', name: 'Product Name', quantity: 1,
        amount: { value: 10000, currency: 'TWD' },
      }],
      shipping: {
        shippingMethod: 'delivery', carrier: 'carrier-name',
        personalInfo: { lastName: 'Wang', firstName: 'Ming', email: 'a@b.com', phone: '+886912345678' },
        address: { countryCode: 'TW', city: 'Taipei', street: '123 Main St', postcode: '100' },
      },
    },
    billing: {
      personalInfo: { lastName: 'Wang', firstName: 'Ming', email: 'a@b.com', phone: '+886912345678' },
      address: { countryCode: 'TW', city: 'Taipei', street: '123 Main St', postcode: '100' },
    },
    customer: {
      referenceCustomerId: 'cust-001',
      personalInfo: { lastName: 'Wang', firstName: 'Ming', email: 'a@b.com', phone: '+886912345678' },
    },
    client: { ip: '1.2.3.4' },
  }),
});

const data: CreateSessionResponse = await res.json();
// Redirect customer to data.sessionUrl
```

## Webhook Verification (HMAC-SHA256)

Webhooks arrive as POST requests with these **HTTP headers**:

| Header | Type | Description |
|---|---|---|
| `apiVersion` | String | API version, e.g. `V1.2` |
| `timestamp` | String | Unix timestamp in **milliseconds** |
| `sign` | String | HMAC-SHA256 hex signature |

### Signature Verification (TypeScript)

```typescript
import * as crypto from 'crypto';

function verifyWebhookSignature(
  signKey: string,
  timestamp: string,
  rawBody: string,
  receivedSign: string,
): boolean {
  const payload = `${timestamp}.${rawBody}`;
  const expected = crypto
    .createHmac('sha256', signKey)
    .update(payload, 'utf8')
    .digest('hex');
  const a = Buffer.from(expected, 'hex');
  const b = Buffer.from(receivedSign, 'hex');
  if (a.length !== b.length) return false;
  return crypto.timingSafeEqual(a, b);
}

// Reject stale timestamps (recommended: 5 min window)
const WINDOW_MS = 5 * 60 * 1000;
const ts = Number(timestamp);
if (!Number.isFinite(ts) || Math.abs(Date.now() - ts) > WINDOW_MS) {
  throw new Error('Timestamp out of window');
}
```

### Webhook Body Structure

```typescript
interface WebhookPayload {
  id: string;          // Unique notification ID
  type: string;        // Event type, e.g. "trade.succeeded"
  created: number;     // Unix timestamp ms
  data: Record<string, unknown>;
}
```

## Session Status Flow

| Status | Description |
|---|---|
| `CREATED` | Session created, awaiting customer payment |
| `PENDING` | Payment in progress |
| `SUCCEEDED` | All payments completed successfully |
| `EXPIRED` | Session expired (default 360 min timeout) |

## Payment (Trade) Status Flow

| Status | SubStatus | Description |
|---|---|---|
| `CREATED` | | Order created, validation passed |
| `CUSTOMER_ACTION` | | Customer performing 3DS / biometric / QR scan |
| `PROCESSING` | `AUTHORIZED` | Authorization succeeded, awaiting capture |
| `PROCESSING` | `PENDING_REVIEW` | Manual review in progress |
| `PROCESSING` | `RISK_PENDING` | Risk review in progress |
| `SUCCEEDED` | | Payment/capture succeeded |
| `FAILED` | `RISK_REJECTED` | Blocked by risk engine |
| `FAILED` | `CONFIRM_FAILED` | Authorization failed |
| `FAILED` | `CAPTURE_FAILED` | Capture failed |
| `CANCELLED` | | Customer or merchant cancelled |
| `EXPIRED` | | 3DS timeout, SLP 6-hour timeout, bank timeout |

## Key Webhook Events (Redirect Mode)

| Event Type | Description | Typical Action |
|---|---|---|
| `session.succeeded` | Session fully paid | Mark order as paid |
| `session.expired` | Session expired | Cancel/expire order |
| `trade.succeeded` | Payment succeeded | Mark order as paid |
| `trade.failed` | Payment failed | Mark order as failed |
| `trade.expired` | Payment expired | Cancel order |
| `trade.refund.succeeded` | Refund completed | Mark order as refunded |
| `trade.refund.failed` | Refund failed | Log, manual intervention |

## Sandbox Test Data

### Test Credit Cards

| Brand | Card Number | Expiry | CVC |
|---|---|---|---|
| JCB | `3565586700000200` | `03/30` | `484` |
| Visa | `4147633700198405` | `03/30` | `638` |
| MasterCard | `5149147700000300` | `03/30` | `231` |

### Amount-Based Test Rules (Sandbox)

- **3D Secure trigger**: Amount (in TWD) divisible by 3 enters 3DS flow
- **Non-3D success**: TWD digit (ignoring cents 00) is odd = success
- **Non-3D failure**: TWD digit (ignoring cents 00) is even = failure
- Example: NT$401 (value=40100) -> non-3D, success. NT$400 (value=40000) -> non-3D, failure.

### Sandbox Account (primary general merchant)

| Email | Merchant ID | Password |
|---|---|---|
| `slpsandbox2@shopline.com` | `2652289079513847808` | `shoplinePayments123.` |

> Full list of 9 sandbox accounts (general / platform / sub-merchant), ApplePay test Apple ID, dashboard URL, amount-based simulation rules, and `.env` mapping: see `references/sandbox-resources.md`.
>
> The primary credentials above are also wired into the project `.env` as `SHOPLINE_SANDBOX_ADMIN_*` so AI agents can sign into the dashboard via `playwright-cli` to inspect orders.

## Pitfalls and Warnings

1. **Amount is in cents**: `value: 100` = NT$1, not NT$100. Always `Math.round(dollars * 100)`.
2. **referenceId max 32 chars**: Must be unique per session. Truncate if needed.
3. **requestId must be unique per HTTP call**: Use `crypto.randomUUID()`.
4. **lastName is required, firstName is optional**: For CJK names, put family name in `lastName`.
5. **Webhook raw body**: Must capture raw HTTP body for signature verification. In NestJS, enable `rawBody` middleware.
6. **Timestamp is milliseconds**: Webhook timestamps are ms, not seconds.
7. **returnUrl vs webhook URL**: `returnUrl` is browser redirect. Webhooks configured via Shopline dashboard.
8. **mode must be 'regular'**: For redirect flow. Never use 'direct'.
9. **Refund window**: 180 days from payment date. After that, error code `1020`.
10. **Partial refund**: Not all payment methods support it (error `4707`).

## References Guide

| Need | File |
|---|---|
| All API endpoints with full parameter tables | `references/api-reference.md` |
| 100+ error codes by category | `references/error-codes.md` |
| All webhook event types with payload structures | `references/webhook-events.md` |
| Full sandbox login accounts, ApplePay test ID, amount-based simulation rules, `.env` mapping | `references/sandbox-resources.md` |
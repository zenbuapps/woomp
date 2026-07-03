# SHOPLINE Payments v1 -- Webhook Events

> Source: https://docs.shoplinepayments.com/api/event/model/

## Table of Contents

1. [Webhook Overview](#webhook-overview)
2. [HTTP Headers](#http-headers)
3. [Body Structure](#body-structure)
4. [Signature Verification](#signature-verification)
5. [Session Events](#session-events)
6. [Payment (Trade) Events](#payment-trade-events)
7. [Refund Events](#refund-events)
8. [Customer (Member) Events](#customer-member-events)
9. [Instrument Events](#instrument-events)
10. [Dispute Events](#dispute-events)
11. [Platform Events](#platform-events)
12. [Complete Event Type List](#complete-event-type-list)

---

## Webhook Overview

SHOPLINE Payments sends HTTPS POST requests to your registered webhook URL when events occur. All subscribed events are enabled by default. Contact SLP support to modify the subscription list.

Webhook URLs are configured per-environment (sandbox/production) through Shopline support -- not via API.

## HTTP Headers

| Header | Required | Type | Description | Example |
|---|---|---|---|---|
| `apiVersion` | Yes | String | API version | `V1.2` |
| `timestamp` | Yes | String | Send time in ms | `1629169157000` |
| `sign` | Yes | String | HMAC-SHA256 hex signature | `873c40ac22fc...` |

## Body Structure

All webhook payloads share this envelope:

```typescript
interface WebhookEnvelope {
  id: string;        // Unique notification message ID
  type: string;      // Event type (e.g. "trade.succeeded")
  created: number;   // Notification creation timestamp (ms)
  data: object;      // Event-specific payload (varies by type)
}
```

## Signature Verification

### Algorithm: HMAC-SHA256

```
payload = "{timestamp}.{rawBodyString}"
expectedSign = HMAC-SHA256(payload, signKey)
```

### TypeScript Implementation

```typescript
import * as crypto from 'crypto';

function verifyShoplineWebhook(
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
```

### NestJS Controller Example

```typescript
import { Controller, Post, Headers, Req, HttpCode } from '@nestjs/common';
import { Request } from 'express';

interface RequestWithRawBody extends Request {
  rawBody?: Buffer;
}

@Controller('commerce/payments/shopline')
export class ShoplineController {
  @Post('webhook')
  @HttpCode(200)
  async webhook(
    @Headers('timestamp') timestamp: string,
    @Headers('sign') sign: string,
    @Headers('apiversion') apiVersion: string | undefined,
    @Req() req: RequestWithRawBody,
  ): Promise<string> {
    if (!timestamp || !sign) return 'OK';
    const raw = req.rawBody?.toString('utf8') ?? '';
    // Verify signature and process event
    await this.shoplineService.handleWebhook({ timestamp, sign, apiVersion }, raw);
    return 'OK';
  }
}
```

### Anti-Replay Protection

Compare `timestamp` against current time. Reject if difference exceeds your tolerance (recommended: 5 minutes).

```typescript
const WINDOW_MS = 5 * 60 * 1000;
const ts = Number(timestamp);
if (Math.abs(Date.now() - ts) > WINDOW_MS) {
  throw new Error('Timestamp out of window');
}
```

---

## Session Events

Events related to checkout sessions (redirect mode).

### `session.created`
Fired when a checkout session is created.

### `session.pending`
Fired when the session enters processing state.

### `session.succeeded`
Fired when all payments in the session are completed. **Primary event for confirming payment in redirect mode.**

### `session.expired`
Fired when the session expires (default timeout: 360 minutes).

### Session Event Data Fields

| Field | Type | Description |
|---|---|---|
| `sessionId` | String | SLP session ID |
| `referenceId` | String | Merchant order reference |
| `status` | String | `CREATED` / `PENDING` / `SUCCEEDED` / `EXPIRED` |
| `amount` | Amount | Total session amount |
| `paymentDetails` | Array | List of payment details within session |

---

## Payment (Trade) Events

Events for individual payment transactions.

### `trade.succeeded`
Payment completed successfully. Contains full payment details including card info, method, and amounts.

### `trade.failed`
Payment failed. Check `data.paymentError` or `data.payment.paymentMethod` for details.

### `trade.expired`
Payment expired (3DS timeout, bank timeout, or SLP 6-hour default timeout).

### `trade.processing`
Payment is being processed by SLP (authorization succeeded for credit card).

### `trade.cancelled`
Payment was cancelled by customer or merchant.

### `trade.customer_action`
Customer needs to perform an action (3DS verification, biometric, QR scan).

### Payment Event Data Fields

| Field | Type | Description |
|---|---|---|
| `actionType` | String | `SDK` or `API` |
| `referenceOrderId` | String | Merchant order reference |
| `tradeOrderId` | String | SLP payment order ID |
| `paymentMsg` | String/null | Payment error message |
| `status` | String | Payment status |
| `subStatus` | String | Sub-status (e.g. `AUTHORIZED`, `RISK_REJECTED`) |
| `payment.paymentMethod` | String | e.g. `CreditCard`, `LinePay` |
| `payment.paymentBehavior` | String | `Regular`, `Recurring`, etc. |
| `payment.paymentSuccessTime` | String | Success timestamp (ms) |
| `payment.autoCapture` | Boolean | Auto-capture flag |
| `payment.channelDealId` | String | Channel transaction ID |
| `payment.creditCard.last4` | String | Last 4 digits of card |
| `payment.creditCard.bin` | String | Card BIN (first 6-8 digits) |
| `payment.creditCard.brand` | String | `Visa`, `MasterCard`, `JCB` |
| `payment.creditCard.type` | String | `CREDIT`, `DEBIT` |
| `payment.creditCard.issuer` | String | Issuing bank name |
| `payment.creditCard.issuerCountry` | String | Issuer country code |
| `payment.paidAmount` | Amount | Actual paid amount |
| `payment.paymentMethodOptions.installments.count` | String/null | Installment count |
| `order.amount` | Amount | Order amount |
| `order.referenceOrderId` | String | Merchant reference |
| `order.merchantId` | String | Merchant ID |
| `order.createTime` | Number | Order creation time (ms) |
| `order.customer.customerId` | String | SLP customer ID |
| `order.customer.referenceCustomerId` | String | Merchant customer ref |

### Example: trade.succeeded Payload

```json
{
  "id": "000100698482394232932302030234328327",
  "type": "trade.succeeded",
  "created": 1718551769058,
  "data": {
    "actionType": "SDK",
    "referenceOrderId": "1600053335",
    "tradeOrderId": "1001001084733463323223973",
    "paymentMsg": null,
    "payment": {
      "paymentSuccessTime": "1718551768922",
      "autoCapture": true,
      "paymentBehavior": "Regular",
      "channelDealId": "17185517455610128070000",
      "paymentMethod": "CreditCard",
      "paymentMethodOptions": {
        "installments": { "installPay": null, "count": null, "installDownPay": null }
      },
      "creditCard": {
        "issuerCountry": "TW",
        "last4": "1234",
        "bin": "12345678",
        "type": "CREDIT",
        "category": "BUSINESS SIGNATURE",
        "brand": "Visa",
        "issuer": "HONG KONG AND SHANGHAI BANKING CORP., LTD."
      },
      "paidAmount": { "currency": "TWD", "value": 10000 }
    },
    "subStatus": "",
    "status": "SUCCEEDED",
    "order": {
      "amount": { "currency": "TWD", "value": 10000 },
      "referenceOrderId": "123456789",
      "merchantId": "12345678",
      "createTime": 1718551768994,
      "customer": { "customerId": "", "referenceCustomerId": "123456789" }
    }
  }
}
```

---

## Refund Events

### `trade.refund.succeeded`
Refund completed successfully.

### `trade.refund.failed`
Refund failed. Check error details in payload.

### Refund Event Data Fields

| Field | Type | Description |
|---|---|---|
| `refundOrderId` | String | SLP refund order ID |
| `referenceRefundId` | String | Merchant refund reference |
| `tradeOrderId` | String | Original payment order ID |
| `status` | String | `SUCCEEDED` or `FAILED` |
| `amount` | Amount | Refund amount |
| `reason` | String | Refund reason |

---

## Customer (Member) Events

### `customer.created`
New customer/member created.

### `customer.updated`
Customer info updated.

### `customer.deleted`
Customer deleted/deactivated.

---

## Instrument Events

### `customer.instrument.binded`
Customer bound a payment instrument (card).

### `customer.instrument.updated`
Payment instrument updated.

### `customer.instrument.unbinded`
Payment instrument unbound.

---

## Dispute Events

### Chargeback Events

| Event | Description |
|---|---|
| `dispute.chargeback.created` | Chargeback initiated |
| `dispute.chargeback.evidence_required` | Evidence submission required |
| `dispute.chargeback.evidence_under_review` | Evidence submitted, under review |
| `dispute.chargeback.evidence_returned` | Evidence returned (resubmit needed) |
| `dispute.chargeback.resolved` | Dispute resolved |
| `dispute.chargeback.cancelled` | Dispute cancelled |
| `dispute.chargeback.lost` | Dispute lost (chargeback upheld) |
| `dispute.chargeback.won` | Dispute won (chargeback reversed) |
| `dispute.chargeback.expired` | Dispute expired |
| `dispute.chargeback.accepted` | Dispute accepted by merchant |

### Pre-Chargeback Events

| Event | Description |
|---|---|
| `dispute.pre-chargeback.created` | Pre-chargeback notification |
| `dispute.pre-chargeback.in_accept` | Response accepted |
| `dispute.pre-chargeback.in_reject` | Response rejected |
| `dispute.pre-chargeback.in_expire` | Response expired |
| `dispute.pre-chargeback.accepted` | Pre-chargeback accepted |
| `dispute.pre-chargeback.merchant_rejected` | Merchant rejected |

### Other Dispute Events

| Event | Description |
|---|---|
| `dispute.fraud.finished` | Fraud alert |
| `dispute.retrieval.created` | Retrieval request created |
| `dispute.retrieval.finished` | Retrieval completed |
| `dispute.retrieval.cancelled` | Retrieval cancelled |

---

## Platform Events

| Event | Description |
|---|---|
| `trade.settled` | Sub-merchant settlement |
| `merchant.kyc.audit` | Sub-merchant KYC audit result |

---

## Complete Event Type List

| Category | Event Type | Description |
|---|---|---|
| Session | `session.created` | Session created |
| Session | `session.pending` | Session processing |
| Session | `session.succeeded` | Session payment completed |
| Session | `session.expired` | Session expired |
| Payment | `trade.succeeded` | Payment succeeded |
| Payment | `trade.failed` | Payment failed |
| Payment | `trade.expired` | Payment expired |
| Payment | `trade.processing` | Payment processing |
| Payment | `trade.cancelled` | Payment cancelled |
| Payment | `trade.customer_action` | Customer action needed |
| Refund | `trade.refund.succeeded` | Refund succeeded |
| Refund | `trade.refund.failed` | Refund failed |
| Member | `customer.created` | Customer created |
| Member | `customer.updated` | Customer updated |
| Member | `customer.deleted` | Customer deleted |
| Instrument | `customer.instrument.binded` | Card bound |
| Instrument | `customer.instrument.updated` | Card updated |
| Instrument | `customer.instrument.unbinded` | Card unbound |
| Dispute | `dispute.chargeback.created` | Chargeback initiated |
| Dispute | `dispute.chargeback.evidence_required` | Evidence needed |
| Dispute | `dispute.chargeback.evidence_under_review` | Evidence reviewing |
| Dispute | `dispute.chargeback.evidence_returned` | Evidence returned |
| Dispute | `dispute.chargeback.resolved` | Dispute resolved |
| Dispute | `dispute.chargeback.cancelled` | Dispute cancelled |
| Dispute | `dispute.chargeback.lost` | Chargeback upheld |
| Dispute | `dispute.chargeback.won` | Chargeback reversed |
| Dispute | `dispute.chargeback.expired` | Dispute expired |
| Dispute | `dispute.chargeback.accepted` | Dispute accepted |
| Dispute | `dispute.pre-chargeback.created` | Pre-chargeback created |
| Dispute | `dispute.pre-chargeback.in_accept` | Pre-CB response accepted |
| Dispute | `dispute.pre-chargeback.in_reject` | Pre-CB response rejected |
| Dispute | `dispute.pre-chargeback.in_expire` | Pre-CB response expired |
| Dispute | `dispute.pre-chargeback.accepted` | Pre-CB accepted |
| Dispute | `dispute.pre-chargeback.merchant_rejected` | Pre-CB merchant rejected |
| Dispute | `dispute.fraud.finished` | Fraud alert |
| Dispute | `dispute.retrieval.created` | Retrieval created |
| Dispute | `dispute.retrieval.finished` | Retrieval completed |
| Dispute | `dispute.retrieval.cancelled` | Retrieval cancelled |
| Platform | `trade.settled` | Sub-merchant settlement |
| Platform | `merchant.kyc.audit` | Sub-merchant KYC audit |
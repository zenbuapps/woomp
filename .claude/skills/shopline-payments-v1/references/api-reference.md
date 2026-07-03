# SHOPLINE Payments v1 -- API Reference

> Source: https://docs.shoplinepayments.com/api/

## Table of Contents

1. [Create Session (Checkout)](#1-create-session)
2. [Query Session](#2-query-session)
3. [Create Payment (Direct)](#3-create-payment)
4. [Capture Payment](#4-capture-payment)
5. [Cancel Payment](#5-cancel-payment)
6. [Query Payment](#6-query-payment)
7. [Create Refund](#7-create-refund)
8. [Query Refund](#8-query-refund)

---

## Common Headers (All Endpoints)

| Header | Type | Required | Description |
|---|---|---|---|
| `Content-Type` | String | Yes | `application/json` |
| `platformId` | String | Platform only | SLP platform ID |
| `merchantId` | String | Yes | SLP merchant ID (or sub-merchant ID for platform) |
| `apiKey` | String | Yes | API authentication key |
| `requestId` | String(32) | Yes | Unique per HTTP request |
| `idempotentKey` | String(32) | No | Idempotency key |

---

## 1. Create Session

Creates a checkout session for redirect (regular) mode. Returns a `sessionUrl` for customer redirect.

```
POST {DOMAIN}/api/v1/trade/sessions/create
```

### Request Body

| Field | Type | Required | Description |
|---|---|---|---|
| `referenceId` | String(32) | Yes | Merchant order number, unique |
| `amount` | Amount | Yes | Total amount object |
| `amount.value` | Number(14) | Yes | Amount in cents (TWD x 100) |
| `amount.currency` | String | Yes | Currency code, only `TWD` |
| `language` | String(6) | No | `zh-TW` or `en` |
| `expireTime` | Integer | No | Session timeout in minutes, default 360 |
| `returnUrl` | String(256) | Yes | URL customer returns to after payment |
| `mode` | String | Yes | Fixed: `regular` for redirect mode |
| `allowPaymentMethodList` | String[] | Yes | Payment methods to display, order matters |
| `paymentMethodOptions` | Object | No | Per-method configuration (see below) |
| `confirm` | Object | No | Payment confirmation options |
| `confirm.autoSettle` | Boolean | No | Auto-settle after authorization |
| `order` | SessionPurchaseOrder | Yes | Order details |
| `order.purchaseScene` | String(16) | No | Purchase scenario |
| `order.products` | Product[] | Yes | Product list (required for risk engine) |
| `order.products[].id` | String(64) | Yes | Product ID |
| `order.products[].name` | String(128) | Yes | Product name |
| `order.products[].quantity` | Integer | Yes | Quantity |
| `order.products[].amount` | Amount | Yes | Product unit price (cents) |
| `order.products[].desc` | String(512) | No | Product description |
| `order.products[].url` | String(256) | No | Product URL |
| `order.products[].sku` | String(64) | No | SKU |
| `order.shipping` | Shipping | Yes | Shipping info (required for risk engine) |
| `order.shipping.shippingMethod` | String(64) | Yes | e.g. "delivery", "pickup" |
| `order.shipping.carrier` | String(64) | Yes | e.g. carrier name |
| `order.shipping.personalInfo` | PersonalInfo | Yes | Recipient info |
| `order.shipping.address` | Address | Yes | Shipping address |
| `billing` | BillingInfo | Yes | Billing information |
| `billing.description` | String(32) | No | Billing description |
| `billing.personalInfo` | PersonalInfo | Yes | Billing contact |
| `billing.address` | Address | Yes | Billing address |
| `customer` | Customer | Yes | Customer info (required for risk engine) |
| `customer.referenceCustomerId` | String(32) | Yes | Unique customer ID |
| `customer.type` | String(1) | No | Customer type |
| `customer.personalInfo` | PersonalInfo | Yes | Customer details |
| `client` | ClientInfo | Yes | Client/browser info |
| `client.ip` | String(32) | Yes | Customer IP address |
| `client.userAgent` | String(128) | No | Browser user agent |
| `client.language` | String(32) | No | Browser language |
| `client.screenWidth` | String(16) | No | Screen width (px) |
| `client.screenHeight` | String(16) | No | Screen height (px) |

### PersonalInfo Object

| Field | Type | Required | Description |
|---|---|---|---|
| `firstName` | String(128) | No | First name (combined with lastName <= 128) |
| `lastName` | String(128) | Yes | Last name |
| `email` | String(128) | Conditional | Email (email or phone required) |
| `phone` | String(64) | Conditional | Phone with country code (e.g. +886...) |
| `gender` | String(1) | No | Gender (customer only) |
| `identityType` | String(20) | No | ID document type (customer only) |
| `identityNumber` | String(64) | No | ID document number (customer only) |

### Address Object

| Field | Type | Required | Description |
|---|---|---|---|
| `countryCode` | String(2) | Yes | ISO country code, e.g. `TW` |
| `stateCode` | String(12) | No | State/province code |
| `state` | String(128) | No | State/province name |
| `city` | String(128) | No | City |
| `district` | String(128) | No | District |
| `street` | String(128) | Yes | Street address |
| `postcode` | String(32) | No | Postal code |

### paymentMethodOptions

| Field | Type | Description |
|---|---|---|
| `CreditCard.installmentCounts` | String[] | Installment periods, e.g. `["0","3","6","12"]`. `"0"` = regular |
| `ChaileaseBNPL.installmentCounts` | String[] | Same as above |
| `ChaileaseBNPL.paymentExpireTime` | Integer | Timeout in minutes, default 4320 (3 days) |
| `JKOPay.paymentExpireTime` | Integer | Timeout in minutes, default 60 |
| `VirtualAccount.paymentExpireTime` | Integer | Timeout in minutes, min 1440, max 86400, default 4320 |

Note: ApplePay and LinePay do not support `paymentMethodOptions`.

### Response (200 Success)

| Field | Type | Description |
|---|---|---|
| `sessionId` | String(32) | SLP session order ID |
| `referenceId` | String(32) | Merchant order number (echo) |
| `status` | String(16) | Session status (always `CREATED`) |
| `sessionUrl` | String(256) | Payment page URL for customer redirect |
| `createTime` | Integer | Order creation timestamp (ms) |
| `amount` | Amount | Amount object |
| `paymentDetails` | paymentDetail[] | Payment detail list (optional) |
| `paymentDetails[].tradeOrderId` | String(64) | SLP payment order ID |
| `paymentDetails[].status` | String(128) | Payment status |
| `paymentDetails[].paymentSuccessTime` | Integer | Payment success time |
| `paymentDetails[].paymentMethod` | String(512) | Payment method used |
| `paymentDetails[].autoSettle` | Boolean | Auto-settle flag |

### Response (400/429/500 Error)

| Field | Type | Description |
|---|---|---|
| `code` | String | Error code (see error-codes.md) |
| `msg` | String | Error description |

---

## 2. Query Session

```
POST {DOMAIN}/api/v1/trade/sessions/query
```

### Request Body

| Field | Type | Required | Description |
|---|---|---|---|
| `sessionId` | String | Conditional | SLP session ID (one of sessionId/referenceId required) |
| `referenceId` | String | Conditional | Merchant order number |

### Response (200)

Same structure as Create Session response, with current status and payment details.

---

## 3. Create Payment (Direct Mode -- Not Used in Redirect)

```
POST {DOMAIN}/api/v1/trade/orders/create
```

For embedded/SDK mode only. Not applicable to redirect mode integration.

---

## 4. Capture Payment

Captures an authorized credit card payment.

```
POST {DOMAIN}/api/v1/trade/orders/capture
```

### Request Body

| Field | Type | Required | Description |
|---|---|---|---|
| `tradeOrderId` | String | Yes | SLP payment order ID |
| `amount` | Amount | No | Partial capture amount (omit for full capture) |

---

## 5. Cancel Payment

Cancels an authorized (uncaptured) credit card payment. Must be done before capture.

```
POST {DOMAIN}/api/v1/trade/orders/cancel
```

### Request Body

| Field | Type | Required | Description |
|---|---|---|---|
| `tradeOrderId` | String | Yes | SLP payment order ID |

---

## 6. Query Payment

```
POST {DOMAIN}/api/v1/trade/orders/query
```

### Request Body

| Field | Type | Required | Description |
|---|---|---|---|
| `tradeOrderId` | String | Conditional | SLP payment order ID |
| `referenceOrderId` | String | Conditional | Merchant reference order ID |

---

## 7. Create Refund

Refunds a succeeded payment. Can be full or partial (if supported by payment method).

```
POST {DOMAIN}/api/v1/trade/refund/create
```

### Request Body

| Field | Type | Required | Description |
|---|---|---|---|
| `tradeOrderId` | String | Yes | SLP payment order ID to refund |
| `referenceRefundId` | String(32) | Yes | Merchant refund reference ID (unique) |
| `amount` | Amount | Yes | Refund amount in cents |
| `reason` | String | No | Refund reason |

### Constraints

- Refund window: 180 days from payment
- Cannot exceed original payment amount
- Only one refund can be processing at a time per payment
- Not all methods support partial refund (error `4707`)

---

## 8. Query Refund

```
POST {DOMAIN}/api/v1/trade/refund/query
```

### Request Body

| Field | Type | Required | Description |
|---|---|---|---|
| `refundOrderId` | String | Conditional | SLP refund order ID |
| `referenceRefundId` | String | Conditional | Merchant refund reference ID |

---

## Refund Status Codes

| Status | Description |
|---|---|
| `CREATED` | Refund created |
| `PROCESSING` | Refund processing |
| `SUCCEEDED` | Refund succeeded |
| `FAILED` | Refund failed |
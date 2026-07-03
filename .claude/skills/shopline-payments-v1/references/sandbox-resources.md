# SHOPLINE Payments Sandbox Resources

> Source: https://docs.shoplinepayments.com/overview/sandboxResource/
> Last synced: 2026-05-04

Complete sandbox testing resources: login credentials, API endpoints, test cards, payment-method behavior, and amount-based simulation rules.

## Sandbox Endpoints

| Resource | URL |
|---|---|
| Backend Dashboard (login portal) | `https://login.shoplinepayments.com/zh-Hant/signin/` |
| Sandbox API base URL | `https://api-sandbox.shoplinepayments.com` |
| Production API base URL | `https://api.shoplinepayments.com` |

After signing in, retrieve `apiKey`, `clientKey`, `signKey` from **Settings → Developer Management** (開發者管理). Each webhook URL pairs with one `signKey`.

## Sandbox Login Accounts

All accounts share the same password: `shoplinePayments123.`

### General Merchant (most common — use for normal redirect-mode integration)

| Email | Merchant ID | Role |
|---|---|---|
| `slpsandbox2@shopline.com` | `2652289079513847808` | Merchant (primary test account) |
| `slpsandbox2+001@shopline.com` | `2875079337371111424` | Merchant |
| `slpsandbox2+003@shopline.com` | `3252259480759574528` | Merchant |
| `slpsandbox2+004@shopline.com` | `3252264968486264832` | Merchant |
| `slpsandbox2+005@shopline.com` | `3252269936723238912` | Merchant |

### Platform Merchant (for Connect / multi-merchant testing)

| Email | Merchant ID | Role |
|---|---|---|
| `slpsandbox1@shopline.com` | `2652270930693330944` | Platform (this Merchant ID also serves as `platformId`) |
| `slpsandbox3@shopline.com` | `2652298043211257856` | Sub-merchant (KYC pending) |
| `slpsandbox4@shopline.com` | `2652302529069717504` | Sub-merchant (KYC approved) |
| `slpsandbox5@shopline.com` | `2652306537700268032` | Sub-merchant (KYC approved) |

When calling Server API on behalf of a sub-merchant, send the platform's `merchantId` in the `platformId` HTTP header AND the sub-merchant's `merchantId` in the `merchantId` header.

## Test Credit Cards

| Brand | Card Number | Expiry | CVC |
|---|---|---|---|
| JCB | `3565586700000200` | `03/30` | `484` |
| Visa | `4147633700198405` | `03/30` | `638` |
| MasterCard | `5149147700000300` | `03/30` | `231` |

## Amount-Based Simulation Rules (Sandbox Only)

The sandbox uses the **TWD amount** to deterministically pick the flow and outcome. This applies to credit-card transactions.

| Rule | Behavior |
|---|---|
| TWD amount divisible by 3 | Enters **3D Secure** flow |
| TWD amount NOT divisible by 3, last two digits stripped, remaining is **odd** | Non-3D, **success** |
| TWD amount NOT divisible by 3, last two digits stripped, remaining is **even** | Non-3D, **failure** |

**Examples** (amount expressed in TWD dollars; remember `value` in API payload is cents):

| TWD | API `value` (cents) | Flow | Outcome |
|---|---|---|---|
| 300 | 30000 | 300 ÷ 3 = 100 → enters 3DS | (3DS challenge) |
| 401 | 40100 | 401 ÷ 3 ≠ int; strip 00 → 4 (even? No, 4 is even... wait) | Per docs: 401 → non-3D, **success** |
| 400 | 40000 | 400 ÷ 3 ≠ int; strip 00 → 4 (even) | non-3D, **failure** |

> The official docs phrase the odd/even check loosely — when in doubt, run a probe call. The divisible-by-3 → 3DS rule is the most reliable lever to force a 3DS test.

## ApplePay Testing

| Item | Value |
|---|---|
| Apple ID email | `slpsandbox2@shopline.com` |
| Apple ID password | `Aa123456!` |
| Min OS | macOS 10.14.1+ or iOS 12.1+ |
| Test cards | Use Apple's official test cards: https://developer.apple.com/apple-pay/sandbox-testing/ |

ApplePay sandbox flow requires the buyer device to sign into the above Apple ID and have at least one Apple-issued sandbox test card added to Wallet.

## Other Payment Methods (LINE Pay / JKOPay / VirtualAccount / ChaileaseBNPL)

In **production**, these methods redirect the customer to the third-party payment channel (LINE, JKO, bank ATM, etc.).

In **sandbox**, the redirect lands on a SHOPLINE-hosted simulation page where the tester chooses the outcome:
- Pay successfully
- Pay failed
- Cancel / abandon
- (For VirtualAccount) trigger the bank-confirmation webhook

No real third-party credentials or virtual-account numbers are required — pick the outcome on the simulator and the corresponding webhook event fires back to the merchant URL.

## Webhook Configuration

- Each webhook URL configured in the dashboard maps 1-to-1 with a `signKey`
- Webhook events list: see `references/webhook-events.md`
- Sign verification: HMAC-SHA256 over `${timestamp}.${rawBody}`, hex digest — see SKILL.md "Webhook Verification"
- For local development, expose `localhost` via Cloudflare Tunnel (project script: `bash scripts/start-tunnel.sh`) so SHOPLINE sandbox can reach the merchant webhook endpoint

## Project `.env` Mapping

This project pre-fills the primary general-merchant credentials in `.env` so that AI agents (e.g. via `playwright-cli`) can sign into the sandbox dashboard to inspect orders, transactions, and webhook delivery logs.

| `.env` key | Value source |
|---|---|
| `SHOPLINE_SANDBOX_ADMIN_URL` | Backend Dashboard URL |
| `SHOPLINE_SANDBOX_ADMIN_ACCOUNT` | `slpsandbox2@shopline.com` (primary general merchant) |
| `SHOPLINE_SANDBOX_ADMIN_PASSWORD` | `shoplinePayments123.` |
| `SHOPLINE_SANDBOX_MERCHANT_ID` | `2652289079513847808` |
| `SHOPLINE_SANDBOX_API_BASE` | `https://api-sandbox.shoplinepayments.com` |

For platform/sub-merchant testing, swap to one of the Platform accounts listed above.

## Pitfalls

1. **Password contains a trailing period** — `shoplinePayments123.` (the `.` is part of the password, not punctuation).
2. **`apiKey` / `signKey` / `clientKey` are NOT in this doc** — log into the dashboard → Developer Management to retrieve them per merchant account. They are different per merchant ID.
3. **Sandbox-mode amounts ≠ production behavior** — never carry the divisible-by-3 / odd-even logic into production code paths.
4. **Sub-merchant accounts only work via the Platform header pattern** — direct Server API calls with a sub-merchant's `merchantId` alone (without the platform's `platformId`) will be rejected.

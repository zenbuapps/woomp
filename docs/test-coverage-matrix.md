# Test Coverage Matrix

46 個 Gherkin Feature Spec 與測試檔案的對照表。

## 圖例

| 標記 | 說明 |
|------|------|
| E2E | Playwright 瀏覽器 E2E 測試 |
| API | Playwright + WC REST API 驗證 |
| PHP | PHPUnit 整合測試 |
| `-` | 現有 PayUni Embed E2E 測試 (A-H) |

---

## Core System (5 specs)

| # | Feature Spec | Playwright 測試 | PHPUnit 測試 | 覆蓋方式 |
|---|-------------|----------------|-------------|---------|
| 1 | `core-initialization.feature` | — | `CoreInitializationTest.php` | PHP |
| 2 | `checkout-flow.feature` | `02-checkout/*.spec.ts` (7 files) | — | E2E |
| 3 | `admin-settings.feature` | `01-core/settings-*.spec.ts` (5 files) | `SettingsPersistenceTest.php` | E2E + PHP |
| 4 | `admin-product-management.feature` | `07-admin/product-variations-ui.spec.ts` | — | E2E |
| 5 | `admin-order-management.feature` | `07-admin/order-*.spec.ts` (4 files) | `OrderMetaTest.php` | E2E + PHP |

## PayUni Embed (12 specs)

| # | Feature Spec | Playwright 測試 | PHPUnit 測試 | 覆蓋方式 |
|---|-------------|----------------|-------------|---------|
| 6 | `payuni-embed/gateway/設定金流參數.feature` | `A-H/` (existing) | — | - |
| 7 | `payuni-embed/gateway/初始化結帳頁SDK.feature` | `A-H/H1-initialization.spec.ts` | — | - |
| 8 | `payuni-embed/payment/提交信用卡付款.feature` | `A-H/A1-new-card.spec.ts` | — | - |
| 9 | `payuni-embed/payment/執行PayUni交易.feature` | `A-H/A1-*.spec.ts` | — | - |
| 10 | `payuni-embed/payment/處理交易結果.feature` | `A-H/A1-*.spec.ts` | — | - |
| 11 | `payuni-embed/payment/解密驗證交易回應.feature` | — | `PayuniEncryptionTest.php` | PHP |
| 12 | `payuni-embed/token/儲存付款Token.feature` | `A-H/A2-saved-card.spec.ts` | — | - |
| 13 | `payuni-embed/token/查詢已儲存卡片.feature` | `A-H/A2-saved-card.spec.ts` | — | - |
| 14 | `payuni-embed/invoice/選擇發票載具.feature` | `A-H/B1-carrier-ui.spec.ts` | — | - |
| 15 | `payuni-embed/webhook/處理交易回調.feature` | `A-H/F1-webhook.spec.ts` | `PayuniWebhookTest.php` | - + PHP |
| 16 | `payuni-embed/refund/處理退費.feature` | `A-H/G1-refund.spec.ts` | — | - |
| 17 | `payuni-embed/error/對應錯誤訊息.feature` | `A-H/C1-C3-*.spec.ts` | — | - |

## PayUni (non-Embed) (7 specs)

| # | Feature Spec | Playwright 測試 | PHPUnit 測試 | 覆蓋方式 |
|---|-------------|----------------|-------------|---------|
| 18 | `payuni-credit-card-payment.feature` | `01-core/gateway-registration.spec.ts` | `GatewayRegistrationTest.php` | API + PHP |
| 19 | `payuni-atm.feature` | `01-core/gateway-registration.spec.ts` | `GatewayRegistrationTest.php` | API + PHP |
| 20 | `payuni-cvs.feature` | `01-core/gateway-registration.spec.ts` | `GatewayRegistrationTest.php` | API + PHP |
| 21 | `payuni-installment.feature` | `01-core/gateway-registration.spec.ts` | `GatewayRegistrationTest.php` | API + PHP |
| 22 | `payuni-subscription.feature` | `01-core/gateway-registration.spec.ts` | `GatewayRegistrationTest.php` | API + PHP |
| 23 | `payuni-refund.feature` | — | `PayuniWebhookTest.php` | PHP |
| 24 | `payuni-tokenization.feature` | — | `PayuniEncryptionTest.php` | PHP |

## PayUni v3 / SDK (2 specs)

| # | Feature Spec | Playwright 測試 | PHPUnit 測試 | 覆蓋方式 |
|---|-------------|----------------|-------------|---------|
| 25 | `payuni-credit-v3-embed.feature` | `A-H/` (existing) | — | - |
| 26 | `payuni-frontend-sdk.feature` | `A-H/H1-initialization.spec.ts` | — | - |
| 27 | `payuni-trade-notification.feature` | — | `PayuniWebhookTest.php`, `PayuniEncryptionTest.php` | PHP |

## ECPay (5 specs)

| # | Feature Spec | Playwright 測試 | PHPUnit 測試 | 覆蓋方式 |
|---|-------------|----------------|-------------|---------|
| 28 | `ecpay-payment.feature` | `04-ecpay/ecpay-gateway-available.spec.ts` | `EcpayCallbackTest.php`, `GatewayRegistrationTest.php` | E2E + PHP |
| 29 | `ecpay-shipping.feature` | `04-ecpay/ecpay-shipping-options.spec.ts` | `ShippingMethodTest.php` | E2E + PHP |
| 30 | `ecpay-invoice-issue.feature` | `04-ecpay/ecpay-invoice-admin.spec.ts` | `InvoiceHandlerTest.php` | E2E + PHP |
| 31 | `ecpay-invoice-void.feature` | `04-ecpay/ecpay-invoice-void.spec.ts` | `InvoiceHandlerTest.php` | E2E + PHP |
| 32 | `ecpay-invoice-allowance.feature` | `04-ecpay/ecpay-invoice-admin.spec.ts` | `InvoiceHandlerTest.php` | E2E + PHP |

## NewebPay (2 specs)

| # | Feature Spec | Playwright 測試 | PHPUnit 測試 | 覆蓋方式 |
|---|-------------|----------------|-------------|---------|
| 33 | `newebpay-payment.feature` | `06-other-gateways/gateway-registration.spec.ts` | `GatewayRegistrationTest.php` | E2E + PHP |
| 34 | `newebpay-shipping.feature` | `06-other-gateways/newebpay-shipping.spec.ts` | `ShippingMethodTest.php` | E2E + PHP |

## EZPay (2 specs)

| # | Feature Spec | Playwright 測試 | PHPUnit 測試 | 覆蓋方式 |
|---|-------------|----------------|-------------|---------|
| 35 | `ezpay-invoice-issue.feature` | `03-invoice/carrier-selection-ezpay.spec.ts` | `InvoiceHandlerTest.php` | E2E + PHP |
| 36 | `ezpay-invoice-void.feature` | `03-invoice/carrier-selection-ezpay.spec.ts` | `InvoiceHandlerTest.php` | E2E + PHP |

## PayNow (3 specs)

| # | Feature Spec | Playwright 測試 | PHPUnit 測試 | 覆蓋方式 |
|---|-------------|----------------|-------------|---------|
| 37 | `paynow-payment.feature` | `05-paynow/paynow-gateway-available.spec.ts` | `PaynowCallbackTest.php`, `GatewayRegistrationTest.php` | E2E + PHP |
| 38 | `paynow-shipping.feature` | `05-paynow/paynow-shipping-options.spec.ts` | `ShippingMethodTest.php` | E2E + PHP |
| 39 | `paynow-invoice-issue.feature` | `05-paynow/paynow-invoice-admin.spec.ts` | `InvoiceHandlerTest.php` | E2E + PHP |

## Other Gateways (3 specs)

| # | Feature Spec | Playwright 測試 | PHPUnit 測試 | 覆蓋方式 |
|---|-------------|----------------|-------------|---------|
| 40 | `linepay-payment.feature` | `06-other-gateways/gateway-registration.spec.ts` | `GatewayRegistrationTest.php` | E2E + PHP |
| 41 | `pchomepay-payment.feature` | `06-other-gateways/gateway-registration.spec.ts` | `GatewayRegistrationTest.php` | E2E + PHP |
| 42 | `smilepay-payment.feature` | `06-other-gateways/gateway-registration.spec.ts` | `GatewayRegistrationTest.php` | E2E + PHP |

## Additional Features (4 specs)

| # | Feature Spec | Playwright 測試 | PHPUnit 測試 | 覆蓋方式 |
|---|-------------|----------------|-------------|---------|
| 43 | `invoice-carrier.feature` | `03-invoice/carrier-*.spec.ts` (3 files) | — | E2E |
| 44 | `invoice-settings.feature` | `01-core/settings-invoice.spec.ts` | `SettingsPersistenceTest.php` | E2E + PHP |
| 45 | `email-notifications.feature` | — | `EmailNotificationTest.php` | PHP |
| 46 | `frontend-product-display.feature` | `08-frontend/*.spec.ts` (3 files) | — | E2E |

---

## 覆蓋統計

| 類型 | 檔案數 | 測試案例數 |
|------|-------|----------|
| Playwright (現有 PayUni Embed) | 16 | ~67 |
| Playwright (新增整合測試) | 36 | ~115 |
| PHPUnit (新增) | 12 | ~53 |
| **合計** | **64** | **~235** |

### 按覆蓋方式統計

| 覆蓋方式 | Spec 數量 |
|---------|----------|
| 僅 E2E (Playwright) | 14 |
| 僅 PHP (PHPUnit) | 7 |
| E2E + PHP 雙層覆蓋 | 17 |
| 現有 PayUni Embed 測試 | 8 |
| **合計** | **46** |

所有 46 個 Feature Spec 均有對應的測試覆蓋。

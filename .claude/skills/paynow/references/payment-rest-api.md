# 體系 1：新版 REST API（PaymentIntent + Component SDK）完整參考

> 來源：docs.paynow.com.tw/developer/docs/getting-started、understanding-paynow/*、
> apidoc/*（Payment 分類）。所有端點 `Authorization: Bearer {PrivateKey}`（apiKey, in=header）。
> 正式 `https://api.paynow.com.tw`，測試 `https://sandboxapi.paynow.com.tw`。

## 目錄（TOC）

1. 認證與金鑰
2. 統一回應格式
3. Component SDK v2（前端 iframe）
4. PaymentIntent Create / Retrieve / Checkout
5. Refund（退款開立 / 列表 / 查詢）
6. Customer（綁卡 Token）
7. Apple Pay（session + deferred）
8. Session（3DS / OTP）
9. Partner（商戶綁定）
10. Webhook（payment_result）完整 payload

---

## 1. 認證與金鑰

| 金鑰 | 用途 |
|------|------|
| **PublicKey（公鑰）** | 前端產生付款表單 / Component SDK 初始化（`PayNow.createPayment({publicKey})`） |
| **PrivateKey（私鑰）** | 後端發起付款 / 退款 / 查詢的 Bearer Token；Webhook HMAC-SHA256 驗簽金鑰。**不可公開** |

申請：向 PayNow 申請（信件主旨「申請 PayNow 串接私鑰 (PrivateKey)」），會有專人聯繫。

所有 REST 請求：
```
Authorization: Bearer {PrivateKey}
Content-Type: application/json   （POST 時）
Accept: application/json
```

> ⚠️ 官方 curl 範例的 host 寫成 `https://docs.paynow.com.tw/api/...`（文件站），
> **真實 host 是 `api.paynow.com.tw` / `sandboxapi.paynow.com.tw`**。

---

## 2. 統一回應格式

所有 REST API 回應外層固定（成功 `status=200`、`type="success"`）：

```json
{
  "status": 200,
  "type": "success",
  "message": "",
  "result": { /* 各 API 的資料 */ },
  "requestId": "09020f76-1405-4db2-b30a-ba30de629c05",
  "paginate": null
}
```

列表類 API（refunds、partner/merchants、card-tokens、apple-pay-deferreds）`result` 為陣列，
並帶 `paginate` 物件（分頁）。

---

## 3. Component SDK v2（前端 iframe）

```html
<!-- 1. head 載入 SDK -->
<script src="https://js.paynow.com.tw/sdk/v2/index.js"></script>
<!-- 2. 掛載點 -->
<div id="paynow-container"></div>
```

```js
// 3. 建立付款實例（secret 來自後端建立的 PaymentIntent）
PayNow.createPayment({
  publicKey: '{{YOUR_PUBLIC_KEY}}',
  secret:    '{{YOUR_PAYMENT_INTENT_SECRET}}',  // result.secret，格式 pp_xxx_st_xxx
  env:       'sandbox'  // 上線改 'production'
})

// 4. 掛載 UI（options 可帶 appearance / locale，locale 預設 'en'）
PayNow.mount('#paynow-container', options)

// 5. 提交付款
checkoutButton.onclick = () => {
  PayNow.checkout().then(response => {
    if (response.error) { /* handle error */ }
    // handle success
  })
}
```

> 付款成功與否仍以後端 Webhook / `GET /payment-intents/:id` 為準（SDK checkout 成功只代表前端流程完成）。

### Component API v1（方法 / 事件）

```js
PayNow.updateLocale('zh_tw')                       // 變更語系
PayNow.checkout()                                  // 提交（回 Promise）

PayNow.on('mounted', () => {})                     // UI 掛載完成
PayNow.on('localeUpdated', (locale) => {})         // 語系變更
PayNow.on('update', (data: UpdateData) => {})      // 欄位內容變更
PayNow.on('paymentMethodSelected', (m: PaymentMethod) => {})  // 付款方式變更
```

```ts
type UpdateData = {
  type: string,
  data: {
    cardType?: string,       // field name 為 CARD_NUMBER 時回卡別
    isComplete: boolean,
    message: string,
    name: string,
    status: FieldErrorStatus,
    value?: string           // 非卡片欄位回其值
  }
}
enum FieldErrorStatus { VALID = 0, INVALID = 1, INCOMPLETE = 2 }
enum PaymentMethod { CreditCard = "CreditCard", ATM = "ATM", CreditCardInstallment = "CreditCardInstallment" }
```

> v2 Component 詳細文件在外站 `docs.paynow.com.tw/component/`（ComponentsDocs），不在本開發者文件內。

---

## 4. PaymentIntent

### 4.1 建立付款意圖 — `POST /api/v1/payment-intents`

付款意圖是一個付款物件，含金額、可用付款方式、付款人。建立後處於 `draft`，回傳編號供後續付款。

**Request Body**

| 欄位 | 型別 | 必填 | 說明 |
|------|------|------|------|
| `paymentNo` | string | nullable | 付款單號；不指定則系統自動產生 |
| `amount` | double | **required** | 付款金額（< 1000000000000） |
| `currency` | string | **required** | 固定 `TWD` |
| `description` | string | nullable | 描述（<= 255 字） |
| `resultUrl` | string | nullable | 付款完成轉跳網址 |
| `webhookUrl` | string | nullable | Webhook 網址；**填了即訂閱 `payment_result`** |
| `allowedPaymentMethods` | string[] | nullable | 允許付款方式（預設全開）。值見下 |
| `allowInstallments` | int32[] | nullable | 限制分期數（不填同後台設定）。可選 `3,6,9,12,18,24` |
| `isBillToRequiredMethods` | object | nullable | 各方式是否需帳單地址，如 `{"ATM":true,"ConvenienceStore":false}` |
| `expireDays` | int32 | nullable | 繳款天數（含當天）；`ATM`/`ConvenienceStore` 有效 |
| `customer` | string | nullable | 指定付款人（Customer uuid，綁卡用） |
| `linePayOnlineInfo` | object | — | LINE Pay 線上資訊（見下） |
| `linePayOfflineInfo` | object | — | LINE Pay 實體資訊 |
| `applePayDeferredInfo` | object | — | Apple Pay 延遲付款資訊 |

`allowedPaymentMethods` 可選值：`CreditCardInstallment`、`CreditCard`、`ATM`、
`ConvenienceStore`、`LINEPayOnline`、`LINEPayOffline`、`ApplePay`、`ApplePayDeferred`。
**`ApplePayDeferred` 不可與其他付款方式同時使用。**

`linePayOnlineInfo` 結構：
```json
{
  "channelId": "string",
  "options": { "displayLocale": "string", "extraBranchID": "string", "extraBranchName": "string" },
  "packages": [{
    "id": "string", "name": "string", "amount": 0,
    "products": [{ "id":"string","imageUrl":"string","name":"string","originalPrice":0,"price":0,"quantity":0 }],
    "userFee": 0
  }],
  "redirectUrlAppPackageName": "string"
}
```
`linePayOfflineInfo`：`{ channelId, extras:{ addFriends:[{idList:[...]}], branchName }, productName }`
`applePayDeferredInfo`：`{ billingAgreement, paymentDescription, deferredPaymentDate, freeCancellationDate, managementUrl }`

**Request 範例（getting-started）**
```bash
curl -L 'https://sandboxapi.paynow.com.tw/api/v1/payment-intents' \
-H 'Content-Type: application/json' \
-H 'Authorization: Bearer {PrivateKey}' \
-d '{
  "amount": 100, "currency": "TWD", "description": "描述",
  "resultUrl": "https://example.com", "webhookUrl": "https://example.com",
  "allowedPaymentMethods": ["CreditCard","ConvenienceStore","CreditCardInstallment"],
  "allowInstallments": [3,6,9,12],
  "isBillToRequiredMethods": { "ATM": true, "ConvenienceStore": false },
  "expireDays": 3,
  "customer": "cus-25ebdf326b924fcba2d57154ad9791e3"
}'
```

**Response（result）關鍵欄位**
```json
{
  "result": {
    "id": "pp_1a304818ced44e5cbeab6107400da3c4",
    "secret": "pp_1a304818ced44e5cbeab6107400da3c4_st_04895990e31b4cefbd59d494ae420392",
    "module": "iframe",
    "allowPaymentMethodTypes": ["CreditCard","ATM","CreditCardInstallment"],
    "amount": 199, "currency": "TWD", "description": null,
    "status": "draft",
    "createdAt": "2023-08-15T11:46:03+00:00",
    "payment": null,
    "meta": { "allowInstallments": [ { "installments": 3, "rate": 3, "extra": false, "enabled": true } ] }
  }
}
```
`module=iframe` 表示 SDK 會生成 UI；`secret` 交給前端 SDK；`meta.allowInstallments[]` 列出各分期費率。

### 4.2 查詢付款意圖 — `GET /api/v1/payment-intents/:id`

Path：`id`（PaymentIntent 編號，required）。回 PaymentIntent 詳細（含各付款方式的成功 / 3DS / pending 範例）。
用於 Webhook 之外的補查。

### 4.3 執行付款（後端收單）— `POST /api/v1/payment-intents/:id/checkout`

> **需聯繫業務人員開通**。須先建立 PaymentIntent 才能執行。供後端直接收單（非 SDK iframe 流程）。

Path：`id`（required）。**Request Body**

| 欄位 | 型別 | 必填 | 說明 |
|------|------|------|------|
| `paymentNo` | string | nullable | 自訂付款編號；不帶則系統產生 |
| `usePayNowSdk` | boolean | — | 是否走 SDK |
| `key` | string | **required** | 公鑰 |
| `secret` | string | **required** | PaymentIntent secret |
| `paymentMethodType` | string | **required** | 付款方式（見下） |
| `paymentMethodData` | object | nullable | 對應付款方式的資料（見下） |
| `sessionId` | string | nullable | 3DS session |
| `otpFlag` | boolean | — | 是否走 3DS / OTP 流程 |
| `meta` | object | — | `{ client:{height,width}, iframe:{height,width} }` |
| `owlpay_session` | string | nullable | — |

`paymentMethodType` 可選：`CreditCard`、`CreditCardInstallment`、`ATM`、`ConvenienceStore`、
`LINEPayOnline`、`LINEPayOffline`、`ApplePay`、`ApplePayDeferred`。

`paymentMethodData` 依付款方式必填項：
- `CreditCard`：`card`、`billTo` 必填
- `CreditCardInstallment`：`card`、`installments`、`billTo` 必填
- `ConvenienceStore`：`codeType` 必填
- `LINEPayOffline`：`oneTimeKey` 必填
- `ApplePay`：`applePayPayload` 必填
- `ApplePayDeferred`：`applePayDeferredPayload` 必填

Response 視付款方式回 success / pending_review（3DS / 超商待繳 / ATM 待繳 / LINE Pay 待付）/ 帶 token 等多種範例。

---

## 5. Refund（退款）

### 5.1 退款開立 — `POST /api/v1/payment-intents/:id/refunds`

Path：`id`（PaymentIntent 編號，required）。**Request Body**

| 欄位 | 型別 | 必填 | 說明 |
|------|------|------|------|
| `amount` | double | **required** | 退款金額 |
| `reason` | string | **required** | 退款原因（<= 255 字） |
| `bankCode` | string | nullable | 退款銀行代碼（**ATM 退款必填**） |
| `bankBranchCode` | string | nullable | 退款銀行分行代碼（ATM 退款必填） |
| `bankAccount` | string | nullable | 退款銀行帳號（ATM 退款必填） |

退款狀態類型（result.type 或回傳 status 字串）：
`success` 退款成功 / `failed` 退款失敗 / `rejected` 拒絕（原因在 `RejectReason`）/
`processing` 退款處理中 / `validation_error` request 驗證有誤。
HTTP 可能回 `200` / `400` / `422`。

### 5.2 退款列表 — `GET /api/v1/refunds`

Query：`Page`（int32, 例 1）、`Limit`（int32, 例 10）。回退款陣列。

### 5.3 退款查詢 — `GET /api/v1/refunds/:uuid`

Path：`uuid`（required）。回單筆退款詳細。狀態：`success`/`failed`/`rejected`/`processing`。

---

## 6. Customer（綁卡 / Token）

### 6.1 建立 Customer — `POST /api/v1/customers`

**Request Body**

| 欄位 | 型別 | 說明 |
|------|------|------|
| `first_name` | string nullable | 名 |
| `last_name` | string nullable | 姓 |
| `email` | string nullable | 電子郵件 |
| `phone_code` | string nullable | 電話國碼 |
| `phone_number` | string nullable | 電話 |
| `address` | object | `{ country, locality, address1, address2, administrative_area, postal_code }` |
| `metadata` | object nullable | 自訂 |

回 `result`（含 customer uuid，供建立 PaymentIntent 時帶 `customer` 綁定）。

### 6.2 查詢 Customer — `GET /api/v1/customers/:customer_uuid`

Path：`customer_uuid`（required）。

### 6.3 查詢 Customer 卡片 Token — `GET /api/v1/customers/:customer_uuid/card-tokens`

Path：`customer_uuid`（required）。`result` 為卡片 Token 陣列（綁卡後快速付款用）。

---

## 7. Apple Pay

### 7.1 請求 Apple Pay session — `POST /api/v2/apple-pay-session`

**Request Body**

| 欄位 | 型別 | 說明 |
|------|------|------|
| `merchant_identifier` | string nullable | 你的 merchant ID；用 Apple Pay Web Merchant Registration API 註冊者帶 `partnerInternalMerchantIdentifier` |
| `display_name` | string nullable | 商店顯示名稱（<= 64 UTF-8 字，固定值勿動態） |
| `initiative` | string nullable | 識別發起的 e-commerce 應用 |
| `initiative_context` | string nullable | 依 initiative 提供的值 |

### 7.2 確認 Apple Pay session — `POST /api/v1/apple-pay-session`

**Request Body**：`merchant_identifier`、`validation_url`、`domain_name`、`display_name`（皆 nullable）。
用於前端 `onvalidatemerchant` 取得的 `validationURL` 拋回後端驗證。

### 7.3 Apple Pay 延遲付款列表 — `GET /api/v1/payment-intents/apple-pay-deferreds`

Query：`Status`（**required**，`Pending`待扣款 / `Paid`已扣款 / `Canceled`已取消 / `Failed`扣款失敗）、
`Page`、`Limit`。回延遲付款陣列。

### 7.4 Apple Pay 延遲付款取消 — `POST /api/v1/payment-intents/apple-pay-deferreds/:uuid/cancel`

Path：`uuid`（PaymentIntent 唯一碼，required）。Body：`reason`（**required**，<= 500 字）。

---

## 8. Session（3DS / OTP）

### 8.1 取得 fingerprint session — `GET /api/v1/fingerprint-session`

無 body。回 `orgId`, `SessionId`（3DS 裝置指紋收集用）。

### 8.2 裝置資料回傳 — `POST /sessions/:sessionID/device-information`

Path：`sessionID`（required）。`multipart/form-data` Body：`browser_height`、`browser_width`。

### 8.3 OTP / 3DS 結果回傳 — `POST /sessions/:sessionID/confirm`

Path：`sessionID`（required）。`multipart/form-data` Body：`TransactionId`、`Response`、`MD`。

---

## 9. Partner（商戶綁定）

> Partner 體系：平台商代管多個 PayNow 商戶。`Authorization: Bearer {Partner PrivateKey}`。

### 9.1 取得 Partner 所屬商戶 — `GET /api/v1/partner/merchants`

Query：`merchant_no`（商戶編號 / 商家帳號）、`Page`、`Limit`。回商戶陣列。

### 9.2 綁定商戶 — `POST /api/v1/partner/merchants/binding`

**Request Body**（皆 required）：

| 欄位 | 說明 |
|------|------|
| `merchant_no` | 商戶編號（商家帳號） |
| `api_key` | API Key（交易密碼） |

---

## 10. Webhook（payment_result）完整 payload

PayNow 以 POST 推送付款結果到 PaymentIntent 建立時的 `webhookUrl`。

### Webhook topics

| Topic | Event |
|-------|-------|
| `payment_result` | 付款結果 |
| `merchant_file_review` | 商家檔案審核 |

### Headers

```
X-Payment-Center-Topic: payment_result
X-Payment-Center-Webhook-Id: 999
X-Payment-Center-Hmac-Sha256: F9E1AB6630980C7B4701798046C1E2BFF1EC7E6DDF70CA27E5AD66A0B27ED575
X-Payment-Center-API-Version: v1
X-Payment-Center-Triggered-At: 2023-12-25T18:00:00+00:00
```

### payload（payment_result）

```json
{
  "ConnectId": "26c06b86-1324-48b6-8017-29e4efa649e6",
  "RequestId": "09020f76-1405-4db2-b30a-ba30de629c05",
  "Status": "Success",
  "OrderNo": "12345678",
  "PaymentNo": "12345678",
  "PaymentIntentId": "pp_1a304818ced44e5cbeab6107400da3c4",
  "TransactionNo": "4000002312251234756",
  "PaidAt": "2023-12-25T18:10:00+00:00",
  "CreatedAt": "2023-12-25T18:00:00+00:00",
  "Amount": 100,
  "Currency": "TWD",
  "PaymentType": "CreditCard",
  "PaymentSubtype": "",
  "PayLink": { "uuid": "8ab210b5-e1f4-4242-b344-7c3a56c08ba9" },
  "Meta": {
    "LastFourDigitsOfCard": "1234",
    "ApprovalCode": "123456",
    "CardType": "Visa",
    "Installments": null,
    "CardMode": "card",
    "CardToken": ""
  }
}
```

| 欄位 | 型別 | 說明 |
|------|------|------|
| `PaymentNo` | string | 付款編號 |
| `TransactionNo` | string | 交易編號 |
| `OrderNo` | string | 訂單編號 |
| `PaymentType` | string | 交易類型（`CreditCard` 等） |
| `Status` | string | `Success` / `Failed` |
| `Amount` | string | 交易金額 |
| `Currency` | string | `TWD` |
| `PaidAt` | string | 交易時間（ISO 8601） |
| `CreatedAt` | string | 訂單建立時間（ISO 8601） |
| `Meta` | object | 卡末四碼 / 授權碼 / 卡別 / 分期 / CardToken 等補充 |

### 驗簽與回應

1. 取 `X-Payment-Center-Hmac-Sha256` 標頭。
2. 用商家 **PrivateKey** 對 raw payload（原始 body 字串）做 HMAC-SHA256，與標頭比對（建議 `hash_equals`）。
3. 驗證通過才處理；處理完**回 HTTP 200** 確認收到。

> HMAC-SHA256 驗簽 PHP 範例見 `references/php-examples.md`（`PaynowWebhookVerifier`）。

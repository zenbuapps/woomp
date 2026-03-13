# PAYUNi API 規格

## 端點總覽

| 端點 | 用途 | 方法 |
|------|------|------|
| `/iframe/token_get` | 取得 SDK Token | POST |
| `/iframe/merchant_trade` | 執行信用卡授權交易 | POST |

**Base URL：** 見 [SKILL.md](../SKILL.md) 環境 URLs。

---

## 1. 取得 SDK Token — `/iframe/token_get`

### 請求參數（外層）

| 參數 | 必要 | 類型 | 說明 |
|------|------|------|------|
| MerID | Y | string | 商店代號 |
| Version | Y | string | 固定 `3.0` |
| EncryptInfo | Y | string | AES-256-GCM 加密字串 |
| HashInfo | Y | string | SHA256 Hash 字串 |
| IsPlatForm | C | int | 代理商金鑰串接時設為 `1` |

### 請求參數（EncryptInfo 內）

| 參數 | 必要 | 類型 | 說明 | 備註 |
|------|------|------|------|------|
| MerID | Y | string | 商店代號 | |
| Timestamp | Y | int | Unix 時間戳 | `time()` |
| IFrameDomain | Y | string | 使用元件的限定域名 | 格式：`https://example.com`，僅 HTTPS |
| UseTokenType | C | int | 信用卡 Token 類型 | 1=約定, 2=記憶, 3=強制約定 |
| CreditToken | C | string | 信用卡 Token 識別碼 | 使用者 email 或會員編號 |
| CreditTokenType | C | int | Token 紀錄類型 | 1=會員（預設），2=商店 |

### 回應參數（外層）

| 參數 | 說明 |
|------|------|
| Status | `SUCCESS` 或錯誤代碼 |
| MerID | 商店代號 |
| Version | `3.0` |
| EncryptInfo | 加密字串（需解密） |
| HashInfo | SHA256 Hash |

### 回應參數（EncryptInfo 解密後）

| 參數 | 說明 |
|------|------|
| Status | `SUCCESS` = 取得 Token 成功 |
| Message | 狀態說明 |
| MerID | 商店代號 |
| Token | SDK Token（用於 `UniPayment.createSession()`） |
| TokenExpired | Token 逾期時間（10 分鐘） |

---

## 2. 執行交易 — `/iframe/merchant_trade`

### 請求參數（外層）

同 token_get 外層格式（MerID、Version、EncryptInfo、HashInfo）。

### 請求參數（EncryptInfo 內 — TradeReqHashDTO）

| 參數 | 必要 | 類型 | 說明 | 備註 |
|------|------|------|------|------|
| MerID | Y | string | 商店代號 | |
| MerTradeNo | Y | string | 商店訂單編號 | 最長 25 字元，格式 `[A-Za-z0-9_-]`，10 分鐘內不可重複 |
| Token | Y | string | SDK Token | 來自 token_get 回應 |
| TradeAmt | Y | int | 交易金額 | 必須為整數 |
| Timestamp | Y | int | Unix 時間戳 | |
| ReturnURL | C | string | 3D 驗證後返回 URL | 完整 URL（WC order-received 頁面） |
| NotifyURL | C | string | 非同步通知 URL | 完整 URL，僅限 port 80/443 |
| UsrMail | C | string | 買家 Email | 用於 Token 識別 |
| ProdDesc | Y | string | 商品描述 | 最長 550 字元，多項以分號分隔 |
| API3D | C | int | 強制 3D 驗證 | `1` = 在未啟用 3D 時強制使用 |
| CreditToken | C | string | 信用卡 Token | 綁定用戶 ID（email、手機、會員編號） |
| CardInst | C | int | 分期期數 | `1`=一次付清, `3/6/9/12/18/24/30`=分期 |
| UseTokenType | C | int | Token 類型 | `2` = 記憶卡號 |
| UserIP | C | string | 客戶 IP | IPv4 或 IPv6 |
| CarrierType | C | string | 發票載具類型 | 見 [invoice-carrier.md](invoice-carrier.md) |
| CarrierInfo | C | string | 載具資料 | 格式依 CarrierType 而定 |
| InvBuyerName | C | string | 買家名稱/公司名稱 | 設定 CarrierType 時必填 |

### 交易回應參數（EncryptInfo 解密後）

| 參數 | 說明 |
|------|------|
| Status | `SUCCESS` 或錯誤代碼 |
| Message | 狀態訊息 |
| MerID | 商店代號 |
| MerTradeNo | 商店訂單編號 |
| Gateway | 付款閘道 |
| TradeNo | PAYUNi 交易編號 |
| TradeAmt | 交易金額 |
| TradeStatus | 交易狀態 |
| PaymentType | 付款方式 |
| CardBank | 發卡銀行 |
| Card6No | 卡號前 6 碼 |
| Card4No | 卡號末 4 碼 |
| CardInst | 分期期數 |
| FirstAmt | 首期金額 |
| EachAmt | 每期金額 |
| ResCode | 授權回應代碼 |
| ResCodeMsg | 授權回應訊息 |
| AuthCode | 授權碼 |
| AuthBank | 授權銀行代碼 |
| AuthBankName | 授權銀行名稱 |
| AuthType | 授權類型 |
| AuthDay | 授權日期 |
| AuthTime | 授權時間 |
| CreditHash | 信用卡 Token Hash（Tokenization 回傳） |
| CreditLife | 卡片到期日 MMYY 格式（Tokenization 回傳） |
| CoBrandCode | 聯名卡代碼 |

---

## 3. Webhook 非同步通知 — `/wc-api/payuni_notify`

PAYUNi 在交易完成後（包含 3D 驗證完成）會 POST 至 NotifyURL。

### 接收參數

| 參數 | 說明 |
|------|------|
| EncryptInfo | AES-256-GCM 加密字串 |
| HashInfo | SHA256 Hash 字串 |

### 處理流程（TradeHandler::process_notify）

1. 計算 HashInfo 驗證：`SHA256(hash_key + EncryptInfo + hash_iv)` 是否一致
2. 解密 EncryptInfo 取得交易結果
3. 更新 WooCommerce 訂單狀態與 meta data

### 訂單 Meta 欄位

| Meta Key | 值 |
|----------|-----|
| `_payuni_v3_resp` | 完整回應陣列 |
| `_payuni_resp_status` | Status 代碼 |
| `_payuni_resp_message` | 狀態訊息 |
| `_payuni_resp_trade_no` | PAYUNi 交易編號 |
| `_payuni_card_number` | 卡號末 4 碼 |
| `payuni_save_card` | 是否儲存卡片（boolean） |

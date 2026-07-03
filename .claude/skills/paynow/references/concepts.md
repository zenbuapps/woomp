# PayNow 概念與背景知識

> 來源：docs.paynow.com.tw/developer/docs/understanding-paynow/*、getting-started、
> apipdf/cashflow/cashflow-ftp（SFTP 對帳檔）。彙整三代 API 架構、金鑰、付款方式、狀態機、對帳。

## 目錄（TOC）

1. PayNow 是什麼
2. 三套 API 架構對照
3. 金鑰體系（PublicKey / PrivateKey / JWT-Token / 交易密碼）
4. 付款方式總覽
5. PaymentIntent 類型與狀態（體系 1）
6. 交易結果 / 繳款狀態（體系 2）
7. Webhook vs 導頁回傳 vs 離線回傳
8. SFTP 對帳檔格式
9. 台灣電子發票背景
10. 環境網域速查

---

## 1. PayNow 是什麼

PayNow 立吉富（**立吉富股份有限公司**，paynow.com.tw）是台灣第三方支付與電子發票加值服務商。
提供信用卡 / ATM / 超商代碼 / 超商條碼 / 銀聯 / 分期 / LINE Pay / Apple Pay 收單，
以及電子發票開立 / 作廢 / 折讓。聯絡：02-2521-5088、service@paynow.com.tw。

文件站：`docs.paynow.com.tw/developer/`；Component SDK 文件另在 `docs.paynow.com.tw/component/`。

---

## 2. 三套 API 架構對照

| 維度 | 體系 1：新版 REST + SDK | 體系 2：舊版 CashFlow | 體系 3：電子發票 |
|------|------------------------|----------------------|-----------------|
| 模型 | PaymentIntent 付款意圖 | 導轉 form-post + 背景交易 | REST 資源 |
| 認證 | Bearer PrivateKey | 帳號 + 交易密碼 + 多層檢核碼 | Bearer JWT-Token |
| 前端 | Component SDK v2（iframe，站內付） | 導轉到 etopm.aspx | 無前端（後端開票） |
| 加密 | 無（Webhook HMAC-SHA256） | SHA-1 / AES256 / HMAC-SHA256 / TripleDES | 無 |
| 回傳 | Webhook（payment_result） | 導頁回傳 + 離線回傳 | 同步 response |
| 端點 | api.paynow.com.tw | www.paynow.com.tw | invoiceapi-prod.paynow.com.tw |
| 適用 | 新案、內嵌付款、API 退款、綁卡 | 既有導轉式金流、背景請款/退款/查詢 | 開票 |
| 文件 | apidoc + understanding-paynow | apipdf/cashflow | invoice |

> **WooCommerce / Power Checkout 新整合建議**：金流走體系 1（PaymentIntent + Component SDK，
> 行為類似既有 ECPG / PAYUNi UNi Embed 站內付），發票走體系 3。體系 2 僅在維護既有舊整合時參考。

---

## 3. 金鑰體系

| 金鑰 / 憑證 | 屬於 | 用途 |
|-------------|------|------|
| **PublicKey（公鑰）** | 體系 1 | 前端 Component SDK 初始化、產生付款表單 |
| **PrivateKey（私鑰）** | 體系 1 | 後端 Bearer Token（發起付款 / 退款 / 查詢）、**Webhook HMAC-SHA256 驗簽金鑰**。不可外洩 |
| **商家 JWT-Token** | 體系 3 | 發票 API Bearer Token |
| **WebNo / mem_cid** | 體系 2 | 商家帳號（公司統編 / 個人身分證） |
| **商家交易密碼（mem_checkpw）** | 體系 2 | PassCode / Signature 計算用 |
| **apicode** | 體系 2 | 導轉送出 PassCode 計算用的 API 串接碼 |
| **EncryptionKey / EncryptionIV** | 體系 2 | 背景交易握手（GP→GK）後取得的動態 AES256 金鑰 |

申請：體系 1 私鑰需來信「申請 PayNow 串接私鑰 (PrivateKey)」；體系 2 / 3 需申請對應帳號與密碼 / Token。

---

## 4. 付款方式總覽

### 體系 1（allowedPaymentMethods / paymentMethodType）

| 值 | 付款方式 | 備註 |
|----|----------|------|
| `CreditCard` | 信用卡 | 需 `card`、`billTo` |
| `CreditCardInstallment` | 信用卡分期 | 需 `card`、`installments`、`billTo`；期數 3/6/9/12/18/24 |
| `ATM` | ATM 虛擬帳號 | 退款需 bankCode/bankBranchCode/bankAccount |
| `ConvenienceStore` | 超商代碼（ibon / FamiPort） | 需 `codeType` |
| `LINEPayOnline` | LINE Pay 線上 | 帶 `linePayOnlineInfo` |
| `LINEPayOffline` | LINE Pay 實體 | 帶 `linePayOfflineInfo`、`oneTimeKey` |
| `ApplePay` | Apple Pay | 需 `applePayPayload` |
| `ApplePayDeferred` | Apple Pay 延遲付款 | 需 `applePayDeferredPayload`；**不可與其他併用** |

### 體系 2（PayType）

`01`信用卡、`02`WebATM、`03`虛擬帳號、`05`代碼繳費（CodeType `0`ibon/`1`FamiPort/`2`icash）、
`09`銀聯、`10`超商條碼、`11`分期付款、`13`自動扣款/預存授權。

---

## 5. PaymentIntent 類型與狀態（體系 1）

### module（類型）

| module | 說明 |
|--------|------|
| `iframe` | SDK 會生成對應的 UI（站內付） |

### status（狀態機）

| status | 說明 |
|--------|------|
| `draft` | 已建立尚未付款 |
| `processing` | 交易進行中；失敗會退回 `draft` |
| `pending_review` | 已發起付款、待驗證 3DS（信用卡） |
| `success` | 交易完成 |
| `canceled` | 取消（**僅 `draft` 可轉為取消**；`processing`/`success` 不可取消，要退款） |

```
draft ──checkout──> processing ──3DS──> pending_review ──> success
  │                     │
  │                     └──失敗──> draft
  └──cancel──> canceled
```

---

## 6. 交易結果 / 繳款狀態（體系 2）

- `TranStatus`：`S` 成功（信用卡授權成功 / 繳款成功）、`F` 失敗（授權失敗 / 未繳款）。
- 即時付款（信用卡 / WebATM / 銀聯）導頁即回 `TranStatus`。
- 離線付款（虛擬帳號 / 超商條碼 / 代碼繳費）：先回「產生繳款資訊」（含 `ATMNo`/`BarCode`/繳費代碼），
  待消費者實際繳費、PayNow 收到通知後，再 POST 一次到後台接收網址回 `TranStatus=S`。
- 交易狀態查詢（PQS_gp）以開頭數字 `1`/`2`/`3`/`4`/`02..` 表達（見 `references/cashflow-legacy-api.md` 第 8 節）。

---

## 7. Webhook vs 導頁回傳 vs 離線回傳

| 機制 | 體系 | 觸發 | 驗簽 |
|------|------|------|------|
| **Webhook** | 1 | PayNow POST 到 `webhookUrl`，付款結果變化時 | `X-Payment-Center-Hmac-Sha256`（HMAC-SHA256, key=PrivateKey） |
| **導頁回傳** | 2 | 即時付款後瀏覽器導回「交易成功 / 失敗網址」 | `PassCode`（SHA-1） |
| **離線回傳** | 2 | 消費者繳費後 PayNow POST 到後台接收網址 | `PassCode`（SHA-1） |

- 體系 1 Webhook topics：`payment_result`（付款結果）、`merchant_file_review`（商家檔案審核）。
- 體系 2「各服務各自設定交易成功 / 失敗回傳網址」（後台依不同服務分別設定）。
- 三者收到後都應做驗簽 + 冪等處理；體系 1 處理完回 HTTP 200。

---

## 8. SFTP 對帳檔格式

> 來源：apipdf/cashflow/cashflow-ftp、cashflow-paynowapi_js。PayNow 每日 01:00 推 XML 到
> **SFTP://61.216.8.41/**（UTF-8）。屬對帳 / 撥款 ops 範疇，非即時交易必要。

| 檔案 | 檔名 | 內容 |
|------|------|------|
| 會員可請款資料 | `YYYYMMDD_Sno.xml` | `WEBNO` / `BUYSAFENO` / `CAPTUREPRICE`（可請款訂單） |
| 會員銀行帳號變更 | `memYYYYMMDD_Sno.xml` | 會員銀行帳戶資料 |
| 銀行代碼 | `BANKYYYYMMDD.xml` | `BANKTYPE`(1 銀行/2 信合社/3 漁農會/4 郵局) `BANKCODE` `BANKNAME` |
| 分行代碼 | `BRANCHYYYYMMDD.xml` | `BANKCODE` `BRANCHCODE` `BRANCHNAME` |
| INS 已撥款交易 | `INSYYYYMMDD_Sno.xml` | `BUYSAFENO` `INSTRUCTDATE` 受款帳號 / 銀行代碼 |
| MCC 代碼表 | `NCCC_MCCCODEYYYYMMDD.xml` | `MCC_TYPE` `MCC_CODE`（百貨 01 / 餐飲 05 / 線上遊戲 34 / 拍賣 99 等） |

每個檔案結構：`<DOCHEAD><DOCDATE><TOTALRECORDS></DOCHEAD>` + 多筆 `<CONTENT>`。

---

## 9. 台灣電子發票背景

PayNow 電子發票（體系 3）串接台灣財政部電子發票平台。重點規則：

- **稅額計算**：非統編（B2C）發票 `tax_amount` 帶 0，由國稅局算稅；統編（B2B）發票帶實際稅額。
  整張發票應稅金額 = 應稅品項小計合計 − 整張發票稅額。
- **課稅別**：`SaleTax` 應稅（一般 5%）/ `ZeroTax` 零稅率 / `FreeTax` 免稅 / `MixTax` 混合
  （只能 應稅+免 或 應稅+零稅率）。
- **零稅率**：`tax_type=ZeroTax` 時必填 `is_pass_customs`（是否經海關）與 `zero_tax_rate_reason`（原因 enum）。
- **載具**：手機條碼 / 悠遊卡 / 自然人憑證 / PayNow 會員載具（BuyerSno）/ 紙本（None）。
- **捐贈**：帶 `npoban` 愛心碼，載具留空。
- **POS 取號**：自管號碼，不進一般上傳流程，未使用者次期單數月 5 號上傳空白發票。

> 其他台灣電子發票服務商（ezPay / Amego / ECPay）的載具代碼、課稅別代碼**與 PayNow 不同**
> （PayNow 用字串 enum 如 `PhoneBarCodeCarrier`/`SaleTax`，ezPay 用數字 `0`/`1`），不可混用。

---

## 10. 環境網域速查

| 體系 / 用途 | 正式 | 測試 / Sandbox |
|-------------|------|----------------|
| 體系 1 REST | `https://api.paynow.com.tw` | `https://sandboxapi.paynow.com.tw` |
| 體系 1 Component SDK | `https://js.paynow.com.tw/sdk/v2/index.js` | （同，env 參數切換 production/sandbox） |
| 體系 2 導轉金流 | `https://www.paynow.com.tw/service/etopm.aspx` | `https://test.paynow.com.tw/service/etopm.aspx` |
| 體系 2 背景交易 | `https://www.paynow.com.tw/service/PayNowAPI_JS.aspx` | `https://test.paynow.com.tw/service/PayNowAPI_JS.aspx` |
| 體系 2 信用卡授權 WSDL | `https://www.paynow.com.tw/WS_CardAuthorise_JS.asmx` | `https://test.paynow.com.tw/WS_CardAuthorise_JS.asmx` |
| 體系 2 ApplePay 商家驗證 | `https://mpay.paynow.com.tw/api/ApplePay/GetTransactionSession` | （同） |
| 體系 3 發票 | `https://invoiceapi-prod.paynow.com.tw/` | `https://invoiceapi-dev.paynow.com.tw/` |
| SFTP 對帳 | `SFTP://61.216.8.41/` | （同） |

> ⚠️ 各體系正式 / 測試環境**完全獨立**，帳號 / 金鑰需個別申請，資料庫不互通。
> 上線前不可在正式環境測試交易（PayNow 會警告並停用帳號）。
> 官方 REST / 發票 curl 範例的 host 寫成 `docs.paynow.com.tw`（文件站），**真實 host 見上表**。

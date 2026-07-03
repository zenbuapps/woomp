# ECPay × zenbu-site：交叉索引中樞

> 本檔為 zenbu-site 專案的 ECPay 整合**索引中樞**，目的：
> 1. 從任何 guide 段落 → 精準找到 NestJS 對應段落（V1 章節對應）
> 2. 從任何 PHP SDK 範例 → 精準找到 TypeScript 對應段落（V2 PHP 反查）
> 3. LLM / 工程師快速判斷「我該讀哪個檔案的哪個段落」（V3 觸發完整性）
>
> **適用範圍**：`apps/api-gateway/src/commerce/payments/ecpay/`
> **同目錄主檔**：`nestjs-typescript-integration.md`（完整 NestJS 範例 §1-§12）

---

## 0. 載入規則（LLM 必讀）

當你（AI 或工程師）正在做以下任何一件事，**必須同時載入** `nestjs-typescript-integration.md`：

| 觸發條件 | 必載章節 |
|---------|---------|
| 修改 `apps/api-gateway/src/commerce/payments/ecpay/*.ts` | 全檔 §1-§12 |
| 撰寫 ECPay AIO 建單 / Callback | §1, §2, §3, §4 |
| 撰寫 ECPG 站內付 2.0 / 綁卡 / 幕後 | §1, §8, §9, §10, §11 |
| 撰寫 ECPay 查詢 / 退款 / 定期定額 | §5, §6, §7 |
| 撰寫測試（*.spec.ts / *.int-spec.ts）| §測試對照（line 1099+）|
| Debug CheckMacValue / AES 加密 | §1, §8（buildEcpgRequest）+ §常見錯誤對照 |
| 設定環境變數 | §12 |

---

## 1. Guide 段落 → NestJS 對應索引（V1）

### guides/01-payment-aio.md（AIO 全方位金流）

| Guide H2/H3 | NestJS 對應 | 狀態 |
|------------|------------|------|
| §概述 / §前置需求 / §HTTP 協議速查 / §端點 URL 一覽 / §整合流程 / §AIO 共用必填參數 / §選用參數 / §MerchantTradeNo 注意事項 | 純說明，參考 §2 程式碼註解 + §本專案實務陷阱 #7（MerchantTradeNo 規範）| ⚪ 純說明 |
| §🚀 首次串接 §步驟 1：後端建立訂單 | **§2 建立 AIO 訂單** | ✅ |
| §🚀 §步驟 2：消費者在綠界付款頁完成付款 | （瀏覽器行為，無 code）| ⚪ |
| §🚀 §步驟 3：接收 ReturnURL 付款通知 | **§3 處理付款結果通知** | ✅ |
| §🚀 §步驟 4：（可選）主動查詢訂單狀態 | **§5 查詢訂單狀態** | ✅ |
| §🚀 §首次串接常見失誤 | §常見錯誤對照 + §本專案實務陷阱 | ✅ |
| §各付款方式專用參數 §信用卡 / §ATM / §CVS / §BARCODE / §分期 / §BNPL / §TWQR / §微信 | §2（透過 ChoosePayment 切換）+ §13 各付款方式 NestJS 補充（**新增於 nestjs-typescript-integration.md**） | ✅ |
| §付款結果通知（ReturnURL）§驗證流程 / §ReturnURL 重要限制 / §各付款方式額外回傳參數 | **§3 + §本專案實務陷阱 #1-#14** | ✅ |
| §ATM/CVS/BARCODE 取號通知（PaymentInfoURL）/ §查詢付款資訊 | **§4 處理取號結果通知** | ✅ |
| §定期定額（訂閱制）§建立 / §PeriodReturnURL / §管理 / §查詢 | **§7 定期定額訂單建立 + §14 定期定額管理 / 查詢**（**新增**） | ✅ |
| §信用卡請款 / 退款 / 取消 §部分退款 / §退款注意事項 | **§6 信用卡請退款（DoAction）** | ✅ |
| §查詢訂單 §一般查詢 / §PaymentType 對照 / §信用卡交易查詢 | **§5 + §集中對照表 §PaymentType** | ✅ |
| §下載對帳檔 §AIO 對帳 / §信用卡對帳 | **§15 下載對帳檔**（**新增**）| ✅ |
| §完整範例檔案對照 / §⚡ 完整可執行範例（Python Flask）| §1-§7（NestJS 即為對應）| ⚪ 已等價 |
| §參數邊界情況 / §生產等級 ReturnURL 處理 / §CSRF 防護 / §IP 白名單 / §ReturnURL 重送機制 | §3 + §本專案實務陷阱（CSRF/重送已涵蓋）| ✅ |
| §常見錯誤碼速查 | §集中對照表 §重要交易訊息代碼 | ✅ |

### guides/02-payment-ecpg.md（ECPG 站內付 2.0 hub）

| Guide H2/H3 | NestJS 對應 | 狀態 |
|------------|------------|------|
| §概述 / §何時選擇站內付 2.0？/ §內部導航 / §站內付2.0 vs AIO 差異 / §前置需求 | 純說明 | ⚪ |
| §HTTP 協議速查 / §端點 URL 一覽 / §AES 三層請求結構 | **§8 buildEcpgRequest util**（含三層結構打包） | ✅ |
| §非 PHP 語言整合指引（PHP SDK 自動處理 / 對照表 / Python 範例）| §8-§11（NestJS 等價）| ✅ |
| §一般付款流程 §步驟 1：前端取得 Token | **§8 GetTokenbyTrade** | ✅ |
| §一般付款流程 §前端 JavaScript SDK 整合 | **§9 ECPG CreatePayment + ThreeDURL**（含 SDK 三依賴 + ECPayPayment div ID）| ✅ |
| §一般付款流程 §步驟 2：後端建立交易 | **§9** | ✅ |
| §一般付款流程 §步驟 3：處理回應 | **§10 ECPG ReturnURL（JSON POST + AES）** | ✅ |
| §綁卡付款流程 §步驟 1-4 | **§16 ECPG 綁卡完整流程**（**新增**：取得綁卡 Token / 3D 驗證後建立 / 處理結果 / 用 CardID 扣款） | ✅ |
| §會員綁卡管理 §查詢 / §刪除 / §讓消費者管理 | **§17 ECPG 會員綁卡管理**（**新增**） | ✅ |
| §請款 / 退款 | §6（同邏輯，端點改 ecpayment domain，**§18 ECPG DoAction 補充**新增） | ✅ |
| §定期定額管理 / §查詢 §一般 / §信用卡交易 / §付款資訊 / §定期定額 | **§19 ECPG 定期定額 / 查詢**（**新增**） | ✅ |
| §對帳 | **§15 對帳**（**新增**） | ✅ |
| §安全注意事項 §GetResponse 安全 / §CSP / §CORS / §Token 安全存儲 / §防止重複付款 | **§20 ECPG 安全處理**（**新增**） | ✅ |
| §AI 生成代碼常見錯誤 / §完整範例檔案對照 | §常見錯誤對照（已強化）| ✅ |

### guides/02a-ecpg-quickstart.md

| Guide H2/H3 | NestJS 對應 | 狀態 |
|------------|------------|------|
| §🚀 首次串接 §本地開發環境快速設定 | **§12 環境變數**（含 Cloudflare Tunnel）| ✅ |
| §串接前確認清單 | §本專案實務陷阱清單（14 條）| ✅ |
| §GetTokenbyTrade Data 必填欄位速查 | **§8（ConsumerInfo 必填提醒）** | ✅ |
| §⚡ API 端點速查 | **§8（雙 Domain 已展示）** | ✅ |
| §5 步驟分段驗證流程 / §無公開 URL 時的測試替代方案 | §12（Tunnel）+ §10 callback 處理 | ✅ |
| §⚡ 完整可執行範例（Python/Node.js）§Python Flask / §Node.js Express | §8-§11（NestJS 即為對應）| ⚪ 已等價 |
| §⚡ ATM / CVS 完整可執行範例（Python Flask） | **§21 ECPG ATM/CVS 完整 NestJS 範例**（**新增**） | ✅ |

### guides/02b-ecpg-atm-cvs-spa.md

| Guide H2/H3 | NestJS 對應 | 狀態 |
|------------|------------|------|
| §ATM / CVS 首次串接快速路徑 §ATM vs 信用卡流程對比 / §ATM GetToken 參數差異 / §ATM CreatePayment / §顯示付款指示 / §CVS 差異 / §ATM/CVS ReturnURL 非同步 | **§21 ECPG ATM/CVS 完整 NestJS 範例**（**新增**）+ §10 ReturnURL | ✅ |
| §非信用卡 Callback 時序 §信用卡 vs ATM/CVS / §ATM/CVS 取號後的 CreatePayment 回應 / §非同步 ReturnURL 處理 / §測試注意事項 | §21 + §本專案實務陷阱 | ✅ |
| §🖥️ SPA / React / Vue / Next.js 整合架構 §整體架構 / §React Hooks / §Next.js API Routes / §Vue 3 Nuxt 3 | **§22 Next.js 16 App Router 整合**（**新增**：apps/web 端的 OrderResultURL 處理 + Server Action）| ✅ |

### guides/03-payment-backend.md

| Guide H2/H3 | NestJS 對應 | 狀態 |
|------------|------------|------|
| §概述 §AES-JSON 雙層錯誤檢查 | **§8（雙層檢查 TransCode/RtnCode 已展示）** | ✅ |
| §何時使用幕後 API / §前置需求 / §🚀 首次串接 §前置確認清單 / §選擇 API 類型 | 純說明 | ⚪ |
| §🚀 §步驟 2：發送 AES-JSON 請求 / §步驟 3：處理 Callback | §8 buildEcpgRequest + §10 callback | ✅ |
| §HTTP 協議速查 / §端點 URL 一覽 | §11（ecpayment domain）| ✅ |
| §信用卡幕後授權 §重要前提 / §整合流程 / §PCI DSS / §主要功能 / §請求格式範例 / §API 規格 | **§11 幕後授權建單** | ✅ |
| §信用卡綁卡代扣（CreatePaymentWithCardID）§整合流程 / §步驟一：綁卡 / §步驟二：代扣 / §綁卡管理 API | **§16 ECPG 綁卡完整流程**（**新增**，含 GetTokenbyBindingCard）+ §11 代扣 | ✅ |
| §非信用卡幕後取號 §適用場景 / §整合流程 / §取號結果對照 / §請求格式範例 / §API 規格 | **§23 非信用卡幕後取號（GenPaymentCode）NestJS 範例**（**新增**） | ✅ |
| §定期定額管理（CreditPeriodAction）/ §ReturnURL 回呼格式 | §19（ECPG 定期定額管理）| ✅ |

### guides/13-checkmacvalue.md

| Guide H2/H3 | NestJS 對應 | 狀態 |
|------------|------------|------|
| §概述 / §使用場景 / §計算流程 / §ECPay 專用 URL Encode | §1 已涵蓋 | ✅ |
| §PHP 開發者（使用 SDK 可跳過）/ §12 種語言實作 / §各語言 URL Encode 行為差異 | §1（NestJS / TS 含 timing-safe + dotNet 對齊）| ✅ TypeScript 部分 |
| §Python / §Node.js / §TypeScript / §Java / §C# / §Go / §C / §C++ / §Rust / §Swift / §Kotlin / §Ruby | §1（本專案僅需 TypeScript 版本）| ⚪ 其他語言不需 |
| §測試向量 §SHA256 / §MD5 / §特殊字元 `'` 測試向量 | **§測試對照（已增強，line 1106+）**：直接引用 `test-vectors/checkmacvalue.json` | ✅ |
| §常見錯誤 / §相關文件 / §官方規格參照 | §常見錯誤對照（已強化）| ✅ |

### guides/15-troubleshooting.md

| Guide H2/H3 | NestJS 對應 | 狀態 |
|------------|------------|------|
| §症狀速查表 / §快速排查決策樹 | §常見錯誤對照（已強化至 30 條）+ §本專案實務陷阱 14 條 | ✅ |
| §1-§29 各種症狀 | §常見錯誤對照 + §本專案實務陷阱 | ✅ 大部分覆蓋 |
| §30 WAF / DoAction / RtnCode 型別 | §常見錯誤 #3, #6, #10 | ✅ |
| §HTTP 層除錯 / §網路層除錯 / §DNS / §TLS / §連線可達性 / §日誌記錄 / §回報綠界技術支援 | （通用 ops debug，與 NestJS 無關，不需對應）| ⚪ |
| §跨服務 Top 5 錯誤碼 / §31 站內付 ATM/CVS Callback 非同步時序 | §10 + §21 + §本專案實務陷阱 | ✅ |

---

## 2. PHP SDK 範例 → TypeScript 反查表（V2）

### scripts/SDK_PHP/example/Payment/Aio/ — 20 個範例

| PHP 檔案 | 用途 | NestJS 對應段落 |
|---------|------|----------------|
| `CreateOrder.php` | AIO 通用建單骨架 | §2 建立 AIO 訂單 |
| `CreateCreditOrder.php` | 信用卡一次付清建單 | §2 + §13 各付款方式 §信用卡 |
| `CreateInstallmentOrder.php` | 信用卡分期建單 | §2 + §13 §分期 |
| `CreatePeriodicOrder.php` | 信用卡定期定額建單 | §7 定期定額訂單建立 |
| `CreateAtmOrder.php` | ATM 虛擬帳號建單 | §2 + §13 §ATM |
| `CreateCvsOrder.php` | CVS 超商代碼建單 | §2 + §13 §CVS |
| `CreateBarcodeOrder.php` | BARCODE 超商條碼建單 | §2 + §13 §BARCODE |
| `CreateWebAtmOrder.php` | WebATM 建單 | §2 + §13 §WebATM |
| `CreateBnplOrder.php` | BNPL 無卡分期建單 | §2 + §13 §BNPL |
| `CreateTwqrOrder.php` | TWQR 行動支付建單 | §2 + §13 §TWQR |
| `CreateWeiXinOrder.php` | 微信支付建單 | §2 + §13 §微信 |
| `Capture.php` | 信用卡請款 (Action=C) | §6 信用卡請退款 |
| `CreditCardPeriodAction.php` | 定期定額訂單作業（暫停/恢復）| §14 定期定額管理 |
| `GetCheckoutResponse.php` | ReturnURL callback 處理 | §3 處理付款結果通知 |
| `QueryTrade.php` | 查詢訂單 | §5 查詢訂單狀態 |
| `QueryCreditTrade.php` | 信用卡單筆明細查詢 | §5（CreditDetail/QueryTrade/V2） |
| `QueryPaymentInfo.php` | ATM/CVS/BARCODE 取號查詢 | §4 處理取號結果通知 |
| `QueryPeridicTrade.php` | 定期定額訂單查詢 | §14 定期定額查詢 |
| `DownloadReconcileCsv.php` | AIO 對帳檔下載 | §15 下載對帳檔 §AIO |
| `DownloadCreditReconcileCsv.php` | 信用卡撥款對帳檔下載 | §15 §信用卡 |

### scripts/SDK_PHP/example/Payment/Ecpg/ — 30+ 個範例（含子目錄）

| PHP 檔案 / 目錄 | 用途 | NestJS 對應段落 |
|---------|------|----------------|
| `CreateOrder.php` | ECPG 通用建單骨架 | §8 + §9 |
| `CreateAllOrder/GetToken.php` | 全部付款方式 GetToken | §8 GetTokenbyTrade |
| `CreateAllOrder/WebJS.html` | 前端 JS SDK 載入範例 | §9（前端 SDK 三依賴 + ECPayPayment div）|
| `CreateApplePayOrder/GetToken.php` + `WebJS.html` | Apple Pay GetToken + 前端 | §8 + §9 |
| `CreateAtmOrder/GetToken.php` + `WebJS.html` | ATM via ECPG | §21 ECPG ATM/CVS 完整範例 |
| `CreateCvsOrder/GetToken.php` + `WebJS.html` | CVS via ECPG | §21 |
| `CreateBarcodeOrder/GetToken.php` + `WebJS.html` | BARCODE via ECPG | §21 |
| `CreateCreditOrder/GetToken.php` + `WebJS.html` | 信用卡 via ECPG | §8 + §9 |
| `CreateInstallmentOrder/GetToken.php` + `WebJS.html` | 分期 via ECPG | §8 + §9 |
| `CreateUnionPayOrder/GetToken.php` + `WebJS.html` | 銀聯卡 via ECPG | §8 + §9 |
| `CreateBindCard.php` | 綁卡前置（取得綁卡 token） | §16 ECPG 綁卡 §步驟 1 |
| `CreateBindCardOrder/WebJS.html` | 前端 3D 驗證後建立綁卡 | §16 §步驟 2 |
| `GetCreateBindCardResponse.php` | 處理綁卡結果 callback | §16 §步驟 3 |
| `GetTokenbyBindingCard.php` | 用綁卡 token 建立交易 | §16 §步驟 4 |
| `CreatePaymentWithCardID.php` | 用 CardID 後端代扣 | §11 幕後授權建單 |
| `GetMemberBindCard.php` | 查詢會員綁卡 | §17 會員綁卡管理 §查詢 |
| `DeleteMemberBindCard.php` | 刪除會員綁卡 | §17 §刪除 |
| `DeleteCredit.php` | 退費（信用卡）| §18 ECPG DoAction §退費 |
| `Capture.php` | 請款（ECPG 版本）| §18 ECPG DoAction §請款 |
| `CreditPeriodAction.php` | ECPG 定期定額作業 | §19 ECPG 定期定額管理 |
| `GetResponse.php` | ReturnURL callback 處理（含 AES 解密）| §10 ECPG ReturnURL |
| `QueryTrade.php` | ECPG 查詢訂單 | §19 ECPG 查詢 §一般 |
| `QueryCreditTrade.php` | ECPG 信用卡明細查詢 | §19 §信用卡 |
| `QueryPaymentInfo.php` | ECPG ATM/CVS 付款資訊查詢 | §19 §付款資訊 |
| `QueryPeridicTrade.php` | ECPG 定期定額查詢 | §19 §定期定額 |
| `QueryTradeMedia.php` | ECPG 對帳檔下載 | §15 對帳 |

> **未在 NestJS 範例中的 PHP 範例**：本專案目前不需要的場景（如 `Logistics/`, `Invoice/`, `Ecticket/` 全部）— 這些 PHP 範例請直接參考 PHP 原始碼，需要時手動翻譯為 TypeScript。

---

## 3. LLM 觸發完整性說明（V3）

### 何時 LLM 應該主動載入本檔 + nestjs-typescript-integration.md

LLM 偵測到以下任一條件時，**強制載入** `references/zenbu-site/`：

```
條件 A：用戶提到本專案 ECPay 開發
  - "ECPay" / "綠界" + "本專案" / "zenbu-site" / "NestJS" / "TypeScript"
  - "apps/api-gateway/src/commerce/payments/ecpay/"
  - 修改 ecpay.service.ts / ecpay.controller.ts / ecpay-crypto.util.ts

條件 B：用戶提到本專案 callback 處理
  - "ReturnURL" / "PaymentInfoURL" / "OrderResultURL" + "NestJS"
  - "Callback Controller" + "ECPay"
  - 處理 webhooks/ecpay 路由

條件 C：用戶提到本專案 ECPG / 站內付
  - "站內付 2.0" / "ECPG" / "ecpg.ecpay.com.tw" / "ecpayment.ecpay.com.tw"
  - 修改 ecpg.service.ts / ecpay-aes.util.ts

條件 D：用戶提到測試
  - "spec.ts" / "int-spec.ts" + "ECPay"
  - "test-vectors/checkmacvalue.json"

條件 E：用戶提到環境變數
  - "ECPAY_MERCHANT_ID" / "ECPAY_HASH_KEY" / "ECPAY_HASH_IV"
```

### 載入優先順序

1. **本檔（cross-reference-index.md）** — 用作導航
2. **`nestjs-typescript-integration.md`** — 取得實際 TypeScript 程式碼
3. **官方 guides/** — 取得協議規範與整合知識
4. **`references/Payment/*.md`** — 用 web_fetch 取得最新 API 規格

### 衝突處理

若三邊有衝突：
- **NestJS 寫法**（本檔 + `nestjs-typescript-integration.md`）優於 **PHP SDK 寫法**（guides/）
- **官方協議規範**（guides/ + references/Payment/）優於 **本專案 NestJS 範例**（協議規範不可違背）
- 例：guides/02 規定「Callback 必須回 `1|OK`」→ 本專案 NestJS 必須遵守，不可改成 `200 OK` 或 JSON

---

## 4. 快速導航（按情境）

| 我想做... | 直接讀 |
|---------|-------|
| 開始本專案 ECPay 整合 | `nestjs-typescript-integration.md` §0-§3 |
| 寫 AIO 建單 | §2 + §13 各付款方式 |
| 寫 AIO Callback | §3 + §本專案實務陷阱 |
| 寫 AIO 取號 callback | §4 |
| 寫 ECPG 站內付 2.0 | §8 + §9 + §10 |
| 寫 ECPG 綁卡 | §16 + §11 |
| 寫幕後授權（純後台扣款）| §11 |
| 寫非信用卡幕後取號 | §23 |
| 寫定期定額 | §7 + §14（AIO）/ §19（ECPG）|
| 寫退款 | §6（AIO）/ §18（ECPG）|
| 寫對帳檔下載 | §15 |
| 寫測試 | §測試對照 |
| 設定環境變數 | §12 |
| Debug | §常見錯誤對照 + §本專案實務陷阱 + guides/15 |
| 查 PaymentType / ChoosePayment 值 | §集中對照表 |
| 查 ECPG Next.js 16 整合 | §22 |

---
name: payuni-uni-embed-v3
description: >
  PAYUNi UNi Embed (免跳轉支付元件 / 內嵌式金流) API Ver 3.0 + JS SDK Ver 2.0 完整技術參考。
  iframe 嵌入式信用卡收單，站內付不跳轉，後端 token_get 取 SDK_TOKEN，前端 uniPayment.js
  收集卡片資訊取得綁定結果，後端再呼叫 merchant_trade 完成幕後授權。
  當程式碼涉及 PAYUNi、統一金流、payuni-uni、UNi Embed、內嵌金流、站內付、tokenization、
  iframe 信用卡收單、SDK 整合、uni-payment.js、createSession、getTradeResult、
  iframe/token_get、iframe/merchant_trade、IFrameDomain、SDK_TOKEN 時使用。
  與 payuni-upp-v3（導轉式整合支付頁）平行；加密規則（AES-256-GCM + SHA256 HashInfo）
  與 UPP 完全一致，請參考 payuni-upp-v3 該段。本 SKILL 為 V3 (2025/09 釋出)，
  若程式碼出現 V2 或 V1 路徑，請勿混用——V3 token_get 階段不送訂單資料。
---

# PAYUNi UNi Embed V3

> **版本**: API Ver 3.0 / JS SDK Ver 2.0 | **支付工具**: 信用卡（含 Visa/MasterCard/JCB/銀聯）| **來源**: https://docs.payuni.com.tw/web/#/29/383 | **更新**: 2026-05-04

PAYUNi 免跳轉支付元件——商店頁面以 iframe 內嵌支付欄位（信用卡號、有效期限、CVC），消費者**不離開商店網站**完成付款。後端先呼叫 `token_get` 取 SDK_TOKEN（10 分鐘有效），前端用 SDK 蒐集卡片並取得綁定 TOKEN 結果，後端再以**原 SDK_TOKEN** 呼叫 `merchant_trade` 進行幕後授權。

**目前僅支援信用卡**（一次付清、分期、約定信用卡、強制約定、記憶卡號）。ATM/CVS/LINE Pay/街口/Apple Pay 等請改用 **UPP**（導轉式）— 見 `payuni-upp-v3`。

## 與 UPP 的關鍵差異

| 面向 | UNi Embed (本 SKILL) | UPP (`payuni-upp-v3`) |
|------|----------------------|------------------------|
| 體驗 | 站內付（iframe，不跳轉） | 跳轉至 PAYUNi 託管頁 |
| 支付工具 | **僅信用卡** | 全部（信用卡+ATM+CVS+LINE Pay+街口+Apple/Google/Samsung Pay+AFTEE+貨到付款...） |
| 商家責任 | 高（PCI/CSP 配置） | 低（PAYUNi 完全託管 UI） |
| 開通申請 | **必須**事先聯繫客服 + 限定 IP | 一般申請 |
| 流程 | 兩步驟：token_get → SDK 綁定 → merchant_trade | 一步驟：Form POST → PAYUNi 完成 → Notify |
| API 路徑 | `/api/iframe/token_get`、`/api/iframe/merchant_trade` | `/api/upp` |
| Gateway 回傳值 | `9` (IFrame) | `2` (UPP) |
| 加密規則 | **同 UPP**（AES-256-GCM + SHA256 HashInfo） | 同左 |
| Hash Key/IV | 同一組商店金鑰 | 同左 |

**加密實作完全相同——本 SKILL 不重複；請直接讀 `payuni-upp-v3/SKILL.md` 的「加解密」段或本專案 `apps/api-gateway/src/commerce/payments/payuni/payuni-crypto.ts`。**

## V3 vs V2 vs V1 版本差異

| 版本 | token_get 階段 | 交易授權 | 狀態 |
|------|----------------|----------|------|
| **V3** (2025/09 釋出) | **不送訂單資料**（僅 MerID + Timestamp + IFrameDomain） | 前端取得綁定 TOKEN 結果，**後端**再呼叫 `merchant_trade` | **本 SKILL** ✓ |
| V2 (2025/02 釋出) | **必須送完整訂單**（MerTradeNo、TradeAmt 等）at token_get | SDK 蒐集卡片後**直接執行授權**，前端拿到結果 | 舊版，2025/10 後不再更新 |
| V1 (2023/11) | 後台需綁定網域（不在 token_get 傳入） | 同 V2 | **2025/06/18 已下架** |

> ⚠️ V3 的核心改變：**token_get 階段只在驗證 IFrameDomain，不綁訂單**；訂單金額與 MerTradeNo 在後續 `merchant_trade` 才送，前端不再經手敏感金額/訂單編號。商店端可在 SDK 綁卡完成後重新檢視購物車金額（避免使用者在 SDK 等候期間竄改）。

## 串接前置作業

1. **註冊 PAYUNi 平台會員**並建立收款商店，取得 `MerID`
   - 測試: https://sandbox.payuni.com.tw
   - 正式: https://www.payuni.com.tw
2. **聯繫 PAYUNi 客服**提出免跳轉元件申請，並設定**限定後端來源 IP**（必要）
3. 自後台「商店清單 > 串接設定」取得 `HashKey`（32 字元）、`HashIV`（16 字元）
4. **代理商**：另需簽訂代理商合作契約，於「代理商專區 > 合約及基本資料」取得代理商金鑰，串接時於外層額外帶入 `IsPlatForm=1`

## 端點

| 環境 | URL |
|------|-----|
| 正式 | `https://api.payuni.com.tw` |
| 測試 | `https://sandbox-api.payuni.com.tw` |

| 功能 | 路徑 | 方式 | 請求 Version | 回傳 Version |
|------|------|------|--------------|--------------|
| 取得 SDK_TOKEN | `/api/iframe/token_get` | HTTP POST | 固定 `3.0` | 固定 `3.0` |
| 幕後授權交易 | `/api/iframe/merchant_trade` | HTTP POST | 固定 `1.0` | 固定 `1.2` |

> 上述「固定」皆為官方文件原文措辭（`token_get` 頁、`merchant_trade` 頁）。
> 注意 merchant_trade 請求送 `1.0`、回傳 `1.2`，是 PAYUNi 設計，不是 bug。

**SDK CDN（前端）**：

| 環境 | SDK URL（script-src + frame-src） |
|------|------------------------------------|
| 正式 | `https://vendor.payuni.com.tw/sdk/uni-payment.js` |
| 測試 | （SDK 透過 `env: "S"` 切換指向 sandbox-vendor） |

**CSP** 必須允許：
```
script-src https://vendor.payuni.com.tw;
frame-src  https://vendor.payuni.com.tw https://sandbox-vendor.payuni.com.tw;
```
TLS v1.2+；`User-Agent` 官方原文「**建議**內容為 `payuni`」（非強制；若不帶仍可送單，但 PAYUNi 端 log 識別會比較難）。

## 完整流程

### 3D 交易（強制 / 由銀行決定）

```
[商店頁面] ─POST─> [後端] ─POST iframe/token_get─> [PAYUNi] ─SDK_TOKEN─> [後端] ─SDK_TOKEN─> [前端]
[前端] uniPayment.createSession(SDK_TOKEN) → start() → onUpdate（卡號驗證）→ getTradeResult()
[前端] ─綁定 TOKEN 結果─> [後端] ─POST iframe/merchant_trade (帶原 SDK_TOKEN + 訂單)─> [PAYUNi]
[PAYUNi] 回傳 3D 導頁 URL → 商店導向 3D 頁面 → 銀行驗證 → NotifyURL（Form POST）回打
[商店] 在 NotifyURL 收到並驗證 HashInfo → 解密 EncryptInfo → 寫入訂單狀態
```

### 非 3D 交易

```
[同上至 merchant_trade]
[PAYUNi] 直接回 Status=SUCCESS + 加密的授權結果（同步回應，無 3D 跳轉）
[ReturnURL] 若有設定，PAYUNi 也會 Form POST 同樣結果（前景）
```

> **交易結果以 NotifyURL 為準**——ReturnURL 可能因使用者關掉瀏覽器而漏收。

## API 1：取得 SDK_TOKEN — `/api/iframe/token_get`

### 外層（HTTP Body, application/x-www-form-urlencoded）

| 參數 | 必要 | 型別 | 說明 |
|------|------|------|------|
| `MerID` | Y | string | 商店代號 |
| `Version` | Y | string | 固定 `3.0` |
| `EncryptInfo` | Y | string | AES-256-GCM 加密字串（同 UPP） |
| `HashInfo` | Y | string | SHA256(HashKey + EncryptInfo + HashIV).toUpperCase() |
| `IsPlatForm` | C | int | 代理商串接時 `1`（與 MerID/Version 同層） |

### EncryptInfo 內層

**基本參數（一律必填）**：

| 參數 | 必要 | 型別 | 說明 | 備註 |
|------|------|------|------|------|
| `MerID` | Y | string | 商店代號 | |
| `Timestamp` | Y | int | Unix 時間戳 | `time()` |
| `IFrameDomain` | Y | string | 使用元件之限定網域 | **必須含 `https://`**，僅允許中文/英數字/`-`，不可開頭結尾為 `-`，例：`https://www.payuni.com.tw` |

> ⚠️ 注意 V3 比 V2 少了 `MerTradeNo`、`TradeAmt`、`ReturnURL`、`NotifyURL`、`ProdDesc` 等所有訂單欄位——這些在 `merchant_trade` 階段才送。

**首次信用卡 Token 交易參數（記憶卡號 / 約定 / 強制約定）**：

| 參數 | 必要 | 型別 | 說明 | 備註 |
|------|------|------|------|------|
| `UseTokenType` | C | int | Token 類型 | `1`=約定信用卡（消費者可取消）<br>`2`=記憶卡號（含到期日）<br>`3`=強制約定（不可取消） |
| `CreditToken` | C | string | 付款人綁定識別 | 使用 `UseTokenType` 時必填。長度 ≤150，格式 `[A-Za-z0-9@.#$%_-]`。建議用會員編號/Email/手機 |
| `CreditTokenType` | C | int | Token 紀錄類型 | `1`=會員（預設，會員旗下所有商店共用）<br>`2`=商店（僅本商店可用） |

### 回傳

外層 JSON（官方明文「`Version` 固定 `3.0`」——回傳值與請求值相同）：
```json
{
  "Status": "SUCCESS",
  "MerID": "...",
  "Version": "3.0",
  "EncryptInfo": "...(hex)...",
  "HashInfo": "...(SHA256)..."
}
```

EncryptInfo 解密後：

| 參數 | 說明 |
|------|------|
| `Status` | `SUCCESS` 或錯誤碼（見錯誤代碼） |
| `Message` | 狀態說明 |
| `MerID` | 商店代號 |
| `Token` | **SDK_TOKEN** — 給前端 uniPayment SDK 使用 |
| `TokenExpired` | Token 逾期時間（10 分鐘） |

外層 `Status=ERROR` 時無 `EncryptInfo`。

## API 2：幕後授權交易 — `/api/iframe/merchant_trade`

> 在前端 `getTradeResult()` 回傳成功（拿到綁定 TOKEN 結果）後，後端用**原 SDK_TOKEN** 呼叫此 API 進行授權。

### 外層

| 參數 | 必要 | 型別 | 說明 |
|------|------|------|------|
| `MerID` | Y | string | 商店代號 |
| `Version` | Y | string | 固定 `1.0` |
| `EncryptInfo` | Y | string | AES-256-GCM 加密字串 |
| `HashInfo` | Y | string | SHA256 雜湊 |
| `IsPlatForm` | C | int | 代理商 `1` |

### EncryptInfo 內層

| 參數 | 必要 | 型別 | 說明 | 備註 |
|------|------|------|------|------|
| `MerID` | Y | string | 商店代號 | |
| `MerTradeNo` | Y | string | 商店訂單編號 | ≤25 字元，格式 `[A-Za-z0-9_-]`，**10 分鐘內不可重複** |
| `Token` | Y | string | SDK_TOKEN | 由 `token_get` 取得（同一個 Token） |
| `TradeAmt` | Y | int | 訂單金額 | 信用卡 1～199,999 元 |
| `Timestamp` | Y | int | Unix 時間戳 | |
| `ProdDesc` | Y | string | 商品說明 | ≤550，超出自動截斷；`;` 分隔多項 |
| `ReturnURL` | C | string | 前景通知 URL | Form POST，空值則回 PAYUNi 結果頁；交易結果**以 NotifyURL 為主** |
| `NotifyURL` | C | string | 背景通知 URL | **僅 80/443 port**；Form POST |
| `UsrMail` | C | string | 消費者信箱 | |
| `API3D` | C | int | 強制 3D | `1`=強制 3D（即使商店設定關閉） |
| `BuyerHash` | C | string | 買方會員 Hash | 由 UPP 端 `BuyerToken` 綁定取得 |
| `CarrierType` | C | string | 發票載具類別 | `3J0002`=手機條碼<br>`CQ0001`=自然人憑證<br>`amego`=會員載具<br>`Donate`=捐贈碼<br>`Company`=公司發票 |
| `CarrierInfo` | C | string | 載具內容 | `CarrierType` 為 3J0002/CQ0001/Donate/Company 時必填；`amego` 免填 |
| `InvBuyerName` | C | string | 買方名稱 / 公司抬頭 | 帶 CarrierType 時必填 |
| `UserIP` | C | string | 消費者 IP | 支援 IPv4/IPv6；協助風控阻擋異常交易 |

### 回傳（非 3D 直接授權成功）

外層 JSON 結構同 token_get（5 欄：`Status` / `MerID` / `Version` / `EncryptInfo` / `HashInfo`）。
**注意：merchant_trade 回傳的 `Version` 官方原文為「固定 `1.2`」**——與請求送出的 `1.0` 不同（也與 token_get 的 `3.0` 不同）。
這是 PAYUNi 設計，**不論是否帶發票/載具參數**，回傳 `Version` 一律 `1.2`，無條件分支。

EncryptInfo 解密後：

| 參數 | 說明 | 備註 |
|------|------|------|
| `Status` | 狀態代碼 | `SUCCESS` / `UNKNOWN`（60 秒無銀行回應）/ `UNAPPROVED`（買家會員審查中）/ 錯誤碼 |
| `Message` | 狀態說明 | `授權成功` / `UNKNOWN=系統忙碌中` |
| `MerID` | 商店代號 | |
| `MerTradeNo` | 商店訂單編號 | |
| `Gateway` | 交易標記 | 固定 `9`（IFrame，與 UPP 的 `2` 不同） |
| `TradeNo` | UNi 序號 | PAYUNi 端訂單流水號 |
| `TradeAmt` | 訂單金額 | |
| `TradeStatus` | 訂單狀態 | `1`=已付款 / `2`=付款失敗 / `3`=付款取消 / `8`=訂單待確認 |
| `PaymentType` | 支付工具 | 固定 `1`=信用卡 |
| `CardBank` | 發卡銀行代碼 | 國內 3 碼，非國內為 `-` |
| `Card6No` / `Card4No` | 卡號前 6 / 後 4 碼 | |
| `CardInst` | 分期數 | |
| `FirstAmt` / `EachAmt` | 首期 / 每期金額 | |
| `ResCode` / `ResCodeMsg` | 回應碼 / 敘述 | |
| `AuthCode` | 授權碼 | |
| `AuthBank` / `AuthBankName` | 授權銀行（代碼 / 名稱） | |
| `AuthType` | 授權類型 | `1`=一次 / `2`=分期 / `7`=銀聯 |
| `AuthDay` / `AuthTime` | 授權日期 / 時間 | `YYYYMMDD` / `HHIISS` |
| `CreditHash` | Token Hash | 有 `CreditToken` 且授權成功才壓碼 |
| `CreditLife` | Token 有效日期 | `MMYY` |
| `CoBrandCode` | 聯名卡代號 | 需事先設定 |

### 回傳（API3D=1，強制 3D）

| 參數 | 說明 |
|------|------|
| `Status` | `SUCCESS`=建立幕後 3D 成功 |
| `Message` | `建立幕後3D成功` |
| `URL` | 強制 3D 導頁網址（前端應導向此 URL，銀行驗證後 Form POST 至 NotifyURL） |

## NotifyURL 回打格式（3D 完成後）

PAYUNi 以 **Form POST** 將授權結果送至 `NotifyURL`，欄位與「非 3D 直接授權成功」回傳完全一致（外層 + EncryptInfo 內層）。商店必須：

1. 驗證外層 `HashInfo` = `SHA256(HashKey + EncryptInfo + HashIV).toUpperCase()`
2. 解密 `EncryptInfo`
3. 比對 `MerTradeNo` 與本地訂單
4. 比對 `TradeAmt` 與本地訂單金額（防止竄改）
5. 依 `Status` / `TradeStatus` 更新訂單

> NotifyURL 必須回應 `200 OK`；PAYUNi 不會用 retry，逾時建議 30 分鐘後主動以 [`/api/trade/query`](../payuni-upp-v3/references/api-reference.md) 查詢一次（與 UPP 共用，請參考 `payuni-upp-v3`）。

## SDK 整合（前端核心）

> Next.js / React 完整實作見 `references/frontend-example.md`。本段為純 SDK API 參考。

### 引入 SDK（禁止下載託管）

```html
<script src="https://vendor.payuni.com.tw/sdk/uni-payment.js"></script>
```

### HTML 容器（必要 IDs）

```html
<div id="put_card_no"></div>        <!-- 卡號 iframe 容器 -->
<div id="put_card_exp"></div>       <!-- 到期日 iframe 容器 -->
<div id="put_card_cvc"></div>       <!-- CVC iframe 容器 -->
<!-- 約定 / 記憶卡號時才需要： -->
<div id="put_token_type"></div>     <!-- checkbox 容器 -->
```

### createSession options

```javascript
const sdk = UniPayment.createSession(SDK_TOKEN, {
  env: "P",          // 必填：P=正式 / S=測試
  useInst: false,    // 啟用分期才設 true
  elements: {        // 必填：與 HTML id 對應
    CardNo: "put_card_no",
    CardExp: "put_card_exp",
    CardCvc: "put_card_cvc",
    CardTokenType: "put_token_type",  // 僅約定/記憶卡號需要
  },
  style: {           // 選填：input 預設樣式
    color: "#000000",
    errorColor: "#FF0000",
    fontSize: "14px",
    fontWeight: "400",
    lineHeight: "24px",
  },
});
```

### onUpdate 事件

```javascript
sdk.onUpdate((update) => {
  const { status, event, data } = update;
  // status = { CardNo, CardExp, CardCvc }，每個欄位值為下表
  if (event === "useTokenType") { /* 顯示 checkbox + tokenTypeText */ }
});
```

`update.status.{CardNo|CardExp|CardCvc}` 值：

| 值 | 含義 |
|----|------|
| `true` | 已填寫，驗證通過 |
| `null` | 尚未填寫 |
| `false` | 欄位錯誤（input 顯示 errorColor） |
| `"typing"` | 使用者輸入中 |

`update.event === "useTokenType"` 時 `update.data`：

| 欄位 | 說明 |
|------|------|
| `tokenType` | `"1"`=約定 / `"2"`=記憶卡號 / `"3"`=強制約定 |
| `tokenTypeText` | checkbox 旁說明文字 |
| `cardNo` | 已記憶的隱碼卡號（`tokenType="2"` 非首次才有，否則 `null`） |

### 來源驗證機制

iframe 自動比對 `window.location.origin` 與 token_get 階段傳入的 `IFrameDomain`：
- 不一致 → 拋 `Code 1007`，元件不載入
- 取不到 origin（Safari 私密瀏覽等） → **不中斷流程**，僅在卡號框下方顯示警語

### getTradeResult(config) 參數

```javascript
await sdk.getTradeResult({ cardInst: 3, useDefault: true });
```

| 屬性 | 型別 | 預設 | 說明 |
|------|------|------|------|
| `cardInst` | number | `1` | 分期期數（須先 `useInst:true` + `getCardAcceptInfo()` 取得可用期數） |
| `useDefault` | boolean | `false` | 使用記憶卡號交易（忽略 CardNo input 值） |

> **⚠️ V3 vs V2 行為差異（極易混淆，務必注意）**
>
> - 官方 API 表格描述 `getTradeResult` = 「進行交易並取得加密的交易結果」，這個措辭沿用自 V2。
> - 但**根據官方版本差異頁原文**（https://docs.payuni.com.tw/web/#/29/384）：
>   - **V3**：「SDK 僅負責蒐集信用卡資訊並進行 SDK Token 綁定。商戶前端取得綁定結果後，需自行呼叫另一支 API 進行交易授權，並在取得交易結果後**自行核對訂單金額及資訊**」
>   - **V2**：「SDK 蒐集完信用卡資訊後，系統會直接執行交易授權」
> - V3 的 `getTradeResult` 實際語義是「**取得卡號綁定 TOKEN 結果**」，**不執行授權**。
> - 官方 V3 主文件範例的程式碼註解也明確寫：「取得信用卡號綁定 TOKEN 結果」+「取得成功後再將原始的 TOKEN 進行幕後交易授權」+ catch 註解「信用卡號綁定 TOKEN 失敗」。
> - **後續授權必須由後端再呼叫 `merchant_trade`**（同一個 SDK_TOKEN）。
> - 所以：若你看到舊文件 / 第三方教學寫「`getTradeResult` 完成就有授權結果」——那是 V2 的行為，**V3 不適用**。

### 自訂 focus 樣式

input 處於 focus 時 SDK 自動加上 `form-input-focus` class，可自訂 CSS：
```css
.form-input-focus { box-shadow: 0 0 4px 0.1rem #0485ff73; }
```

## SDK API 完整索引

### `UniPayment.createSession(token, initOption)`

| 參數 | 型別 | 說明 |
|------|------|------|
| `token` | string | 後端取得的 SDK_TOKEN |
| `initOption` | object | 設定（env / elements / style / useInst） |

回傳：SDK 實例。

### SDK 實例方法

| 方法 | async | 參數 | 回傳 | 說明 |
|------|-------|------|------|------|
| `start()` | Y | — | `Promise<Object>` | 驗證 origin / token，顯示 iframe 輸入框 |
| `onUpdate(callback)` | N | `(update) => void` | `void` | 註冊狀態 / 事件回呼 |
| `getCardAcceptInfo()` | Y | — | `Promise<{CreditInst}>` | 取得支援分期期數與銀行 |
| `getTokenTypeText(callback)` | N | `(text) => void` | `string` | 取得記憶卡號 / 約定 checkbox 文案 |
| `getTradeResult(config)` | Y | `{cardInst?, useDefault?}` | `Promise<Object>` | 進行交易，回傳加密綁定結果 |

## 信用卡 Token（記憶卡號 / 約定信用卡）

### 三種模式

| `UseTokenType` | 行為 | checkbox 出現 | 消費者可取消 |
|----------------|------|---------------|--------------|
| `1` 約定信用卡 | 完成首次交易後綁定，後續可幕後扣款 | ✓ | ✓（在付款頁取消） |
| `2` 記憶卡號 | 卡號 + 到期日記憶在 PAYUNi，下次自動帶入隱碼卡號 | ✓ | ✓ |
| `3` 強制約定 | 強制綁定，不出現 checkbox | ✗（強制） | ✗ |

### CreditToken（付款人識別）

| 限制 | 值 |
|------|----|
| 長度 | ≤150 |
| 格式 | `[A-Za-z0-9@.#$%_-]` |
| 建議內容 | 會員編號 / Email / 手機號 |

### CreditTokenType（紀錄範圍）

| 值 | 說明 |
|----|------|
| `1` 會員（預設） | 該會員 PAYUNi 帳下所有商店共用此 Token |
| `2` 商店 | 僅首次交易商店可用 |

### 後續幕後扣款（續期）

首次交易完成後，回傳會帶 `CreditHash`（Token Hash）+ `CreditLife`（有效日期 MMYY）。

後續扣款使用 PAYUNi UPP 體系的「續期收款 API」（`/api/credit` mode）——**與 UPP 共用**，詳見 `payuni-upp-v3/references/api-reference.md` 的「續期收款 API」段。UNi Embed **本身不負責續期扣款**，僅負責首次綁卡。

## 訂單金額限制（信用卡）

| 工具 | 範圍 |
|------|------|
| 信用卡 | 1～199,999 元 |

> ATM/CVS/LINE Pay 等其他工具上限見 `payuni-upp-v3` 內 `references/api-reference.md`，本 SKILL 不適用。

## 信用卡支援卡別與分期

| 卡別 | 一次付清 | 分期 |
|------|---------|------|
| Visa | ✓ | ✓（3/6/9/12/18/24/30 期） |
| MasterCard | ✓ | ✓（同上） |
| JCB | ✓ | ✓（同上） |
| 銀聯 | ✓ | ✗（不支援分期，AuthType=7） |

各銀行支援期數見 https://www.payuni.com.tw/bank-info-inst

## Sandbox 測試資源

### 測試卡號

| 場景 | 卡號 | CVC / 到期日 |
|------|------|--------------|
| 一次付清成功 | `4147631000000001`、`3560511000000001` | 任意（建議 `123` / `12/30`） |
| 一次付清模擬 3D 取消（ECI 不符） | `4147631000000002`、`3560511000000002` | 任意 |
| 分期成功 | `3560562000000001` | 任意 |
| 分期成功（不支援 9 期） | `4147632000000001`、`3560512000000001` | 任意 |

> ⚠️ **PAYUNi sandbox 只接受上表卡號**——別把其他金流的測試卡塞進來。
> 常見踩坑：用了 NewebPay 的 `4000-2211-1111-1111` → PAYUNi 直接授權失敗。
> ECPay 測試卡見 `ECPay-API-Skill`、NewebPay 見 `newebpay-mpg`。

### Sandbox 端點

| 用途 | URL |
|------|-----|
| 註冊 | https://sandbox.payuni.com.tw/signup |
| 後台 | https://sandbox.payuni.com.tw |
| API | `https://sandbox-api.payuni.com.tw` |
| SDK CDN | `https://vendor.payuni.com.tw/sdk/uni-payment.js`（用 `env:"S"` 切換） |

詳細 sandbox 後台驗證流程（playwright-cli 自動登入查訂單）請見 `payuni-upp-v3/SKILL.md`「後台驗證訂單」段——同一帳號通用。

## 注意事項

1. **加密規則 100% 同 UPP**：AES-256-GCM、SHA256 HashInfo、`hex(base64(cipher) + ":::" + base64(tag))`，不重複定義；本專案實作見 `apps/api-gateway/src/commerce/payments/payuni/payuni-crypto.ts`。
2. **V3 token_get 不要送 MerTradeNo / TradeAmt / NotifyURL** — 這是 V3 與 V2 最容易踩雷的差異；送了會被忽略或回 `TOKEN02xxx` 錯誤。
3. **同一個 SDK_TOKEN 走完全程**：`token_get` → 前端 `getTradeResult` → 後端 `merchant_trade` 都用同一個 SDK_TOKEN。10 分鐘逾期 → `IFTRADE04001`。
4. **MerTradeNo 在 merchant_trade 才送，10 分鐘內不可重複**。
5. **NotifyURL 僅 80/443 port**，否則 PAYUNi 不送回呼。
6. **CSP** 必須允許 `https://vendor.payuni.com.tw`（script-src + frame-src）；測試環境若同時啟用 `sandbox-vendor.payuni.com.tw`。
7. **禁止下載 uni-payment.js 至商店主機託管** — PAYUNi 安全規範要求始終由 vendor.payuni.com.tw 載入。
8. **IFrameDomain 必含 `https://`**，僅允許中文 / `a-z` / `0-9` / `-`，不可開頭結尾為 `-`。
9. **限定 IP 必須事先設定**，否則 token_get 回 `TOKEN03005` / `TOKEN03006`。
10. **3D 交易結果以 NotifyURL 為準**，ReturnURL 可能漏收。
11. **UNKNOWN 狀態**：60 秒無銀行回應，後續以 NotifyURL 通知；建議 15 分鐘後以 `/api/trade/query`（同 UPP）查詢確認。
12. **訂單金額在 merchant_trade 時必須與商店本地金額一致**——前端 SDK 回傳後務必後端比對，避免 SDK 等候期間竄改。
13. **代理商串接** 需在外層額外帶 `IsPlatForm=1`，與 `MerID/Version/EncryptInfo/HashInfo` 同層（不在 EncryptInfo 內）。
14. **`User-Agent` header 官方原文「建議內容為 `payuni`」**——非強制；不帶仍可送單，但 PAYUNi 端 log 識別會比較難。
15. **回傳 `Version` 欄位語意**（容易誤抄）：
    - `token_get` 請求送 `3.0`、回傳也固定 `3.0`
    - `merchant_trade` 請求送 `1.0`、回傳固定 `1.2`（**不論是否帶發票**，回傳一律 `1.2`，無條件分支）
16. **`getTradeResult` 在 V3 是「綁定」不是「授權」**——詳見「SDK 整合 / getTradeResult(config) 參數」段的 V3 vs V2 差異說明。

## 與本專案的整合

| 區段 | 檔案 |
|------|------|
| NestJS Service / Controller / DTO 範本 | `references/nestjs-example.md` |
| 前端 Next.js 16 + React 19 整合範例 | `references/frontend-example.md` |
| 完整錯誤代碼表（SDK + Token API + Trade API） | `references/error-codes.md` |
| Sandbox / 後台驗證 / playwright-cli 自動化 | 同 UPP，見 `../payuni-upp-v3/SKILL.md` |

## 官方資源

| 資源 | URL |
|------|-----|
| 主文件（V3） | https://docs.payuni.com.tw/web/#/29/383 |
| V2（舊版，2025/10 後不再更新） | https://docs.payuni.com.tw/web/#/29/375 |
| 版本差異 | https://docs.payuni.com.tw/web/#/29/384 |
| Notify v1.0 | https://docs.payuni.com.tw/web/#/29/382 |
| 錯誤代碼 | https://docs.payuni.com.tw/web/#/29/385 |
| 版本紀錄 | https://docs.payuni.com.tw/web/#/29/376 |
| 共用：資料加解密 | https://docs.payuni.com.tw/web/#/7/29 |
| 共用：信用卡 Token 查詢 | https://docs.payuni.com.tw/web/#/7/40 |
| 共用：訂單金額限制 | https://docs.payuni.com.tw/web/#/7/170 |
| 範例專案（React + Vue） | https://github.com/payuni/UNiEmbed-uniPayment |

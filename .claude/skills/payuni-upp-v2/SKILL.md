---
name: payuni-upp-v2
description: >
  PAYUNi UPP V2 (UNiPaypage Version 2.0) 統一金流整合支付頁完整技術參考。
  涵蓋 AES-256-GCM 加解密、SHA256 HashInfo、UPP 請求/回傳參數、所有付款方式
  (信用卡/分期/紅利/Apple Pay/Google Pay/Samsung Pay/銀聯/ATM 虛擬帳號/超商代碼/
  icash Pay/AFTEE/LINE Pay/街口支付/貨到付款/宅配)、信用卡 Token / 買方 Token、
  優惠券、物流參數、交易查詢/請退款/取消授權、Token 查詢/取消、Sandbox 測試。
  當程式碼涉及 PAYUNi、統一金流、payuni、UPP、UNiPaypage、台灣金流、
  EncryptInfo HashInfo、信用卡分期、超商代碼、ATM 虛擬帳號、LINE Pay、
  街口支付、AFTEE、icash Pay、Apple Pay、Google Pay、Samsung Pay、
  /api/upp、/api/trade/query、/api/trade/close、/api/trade/cancel、
  /api/credit_bind、/api/cancel_cvs、/api/aftee_direct 等端點時必須使用。
  V2 專用 (Version 2.0、AES-256-GCM)，PAYUNi 官方目前 UPP 僅有 V2，沒有 V3。
  與舊版 V1.x (AES-256-CBC) 不相容。
---

# PAYUNi UPP V2 (UNiPaypage Version 2.0)

> **適用版本**：UPP `Version 2.0`（PAYUNi 官方 UPP 目前最新且唯一線上版本）
> **加密**：AES-256-GCM
> **驗章**：SHA256 (HashKey + EncryptInfo + HashIV).toUpperCase()
> **官方文件**：https://docs.payuni.com.tw/web/#/7/34
> **更新驗證**：2026-05-04（playwright-cli 抓取官方頁面 verbatim）

PAYUNi（統一金流）整合支付頁（UNiPaypage / UPP）：商家以 Form POST 將加密訂單送往 PAYUNi，PAYUNi 渲染 RWD 支付頁讓消費者選擇付款方式（信用卡 / ATM / 超商 / 行動支付 / 後付 / 物流），完成後以 ReturnURL（前景 Form POST）+ NotifyURL（背景 Form POST）回傳結果。

> **版本說明**：
> - PAYUNi 文件中 UPP 標題明確標示 `Ver 2.0`（**只有這一個版本**），對應 hash `#/7/34`。
> - 過去文件曾使用 V1.x（AES-256-CBC + zero padding），目前 V2 採 AES-256-GCM，**新串接者一律使用 V2**。
> - **本 SKILL 等同 PAYUNi UPP 唯一在用版本**——若有人提到「UPP V3」，那是命名誤解，實際就是 V2。
> - 「幕後系列」（`/api/credit`、`/api/atm`、`/api/cvs`）目前是 `Ver 1.3`，與 UPP V2 並用、共用同套加解密與外層格式。

## 端點

| 環境 | URL |
|------|-----|
| 正式 | `https://api.payuni.com.tw/api/upp` |
| 測試（Sandbox） | `https://sandbox-api.payuni.com.tw/api/upp` |

請求方式：**Form POST**（HTML `<form>` 提交），TLS v1.2+。

來源：[#/7/34 — 請求 URL / 請求方式](https://docs.payuni.com.tw/web/#/7/34)

## 加解密（V2 核心）

**金鑰**：HashKey（32 字元）+ HashIV（16 字元），於後台 → 會員 → 商店清單 → 串接設定取得。**不可含空白**。

### Encrypt（AES-256-GCM）

```typescript
import * as crypto from "crypto";
import * as querystring from "querystring";

function encrypt(
  params: Record<string, string | number>,
  hashKey: string,    // 32 字元
  hashIv: string,     // 16 字元
): string {
  const plaintext = querystring.stringify(params as Record<string, string>);
  const cipher = crypto.createCipheriv("aes-256-gcm", hashKey, Buffer.from(hashIv));
  let enc = cipher.update(plaintext, "utf8", "base64");
  enc += cipher.final("base64");
  const authTag = cipher.getAuthTag().toString("base64");
  // 格式：hex( base64(ciphertext) + ":::" + base64(authTag) )
  return Buffer.from(`${enc}:::${authTag}`).toString("hex").trim();
}
```

### Decrypt

```typescript
function decrypt(encryptStr: string, hashKey: string, hashIv: string): Record<string, string> {
  const [encData, tag] = Buffer.from(encryptStr, "hex").toString().split(":::");
  const decipher = crypto.createDecipheriv("aes-256-gcm", hashKey, Buffer.from(hashIv));
  decipher.setAuthTag(Buffer.from(tag, "base64"));
  let dec = decipher.update(encData, "base64", "utf8");
  dec += decipher.final("utf8");
  const result: Record<string, string> = {};
  new URLSearchParams(dec).forEach((v, k) => { result[k] = v; });
  return result;
}
```

### HashInfo（SHA256）

```typescript
function hashInfo(encryptStr: string, hashKey: string, hashIv: string): string {
  return crypto.createHash("sha256")
    .update(`${hashKey}${encryptStr}${hashIv}`)
    .digest("hex")
    .toUpperCase();
}
```

公式：`HashInfo = SHA256(HashKey + EncryptInfo + HashIV).toUpperCase()`

### 官方測試向量（驗證實作正確性）

來源：[#/7/312 Node.js 範例](https://docs.payuni.com.tw/web/#/7/312)、[#/7/29 PHP 範例](https://docs.payuni.com.tw/web/#/7/29)

```typescript
const merKey = "12345678901234567890123456789012"; // 32 chars
const merIv  = "1234567890123456";                 // 16 chars

// 輸入：{ MerID: "AAA", MerTradeNO: "BBB", Prod: "商品說明" }
//   → querystring.stringify → "MerID=AAA&MerTradeNO=BBB&Prod=%E5%95%86%E5%93%81%E8%AA%AA%E6%98%8E"
// AES-256-GCM 加密 + hex(base64+":::"+base64(tag)) 結果（含 GCM tag，內容會因 IV 重用相同卻 deterministic）：
//   47396636346f66735853533167396942344f587a3775696b34732b596e70452b675270564f73536b7753446c6a4d77526d4e374256514173672b6c78616d4533504d475152642b362f4530626f446e4f6356533969756c743a3a3a4b5961342f4635456965743069385a784b6277704a413d3d
// SHA256 結果（HashKey + EncryptInfo + HashIV）：
//   E97180D78C8378D64A188D292938B9D2717034F292B626019B01DF160AEFC0B7
```

> 注意：上面 SHA256 是 **官方範例的固定值**——要重現須用同樣的測試向量。實際環境中每次加密的 EncryptInfo 都會不同（因為 GCM tag），但只要實作正確，給定同樣輸入就應產生同樣的 SHA256。

## 外層請求格式

```http
POST /api/upp HTTP/1.1
Host: api.payuni.com.tw
Content-Type: application/x-www-form-urlencoded

MerID=...&Version=2.0&EncryptInfo=...&HashInfo=...
```

| 參數 | 必要 | 類型 | 說明 |
|------|------|------|------|
| `MerID` | Y | string | 商店代號（需與 EncryptInfo 內 MerID 一致） |
| `Version` | Y | string | **固定 `2.0`** |
| `EncryptInfo` | Y | string | AES-256-GCM 加密字串（hex） |
| `HashInfo` | Y | string | SHA256 大寫 |

來源：[#/7/34 — 請求參數 第一張表](https://docs.payuni.com.tw/web/#/7/34)

## EncryptInfo 內層 — 通用請求參數

> 完整內層參數見 `references/upp-request-params.md`（含 token / 買方 token / 優惠券 / 物流）。本表為共通必要 + 高頻使用欄位。

| 參數 | 必要 | 類型 | 說明 | 備註 |
|------|------|------|------|------|
| `MerID` | Y | string | 商店代號 | |
| `MerTradeNo` | Y | string | 商店訂單編號 | 長度 ≤25, `[A-Za-z0-9_-]`, 10 分鐘內不可重複 |
| `TradeAmt` | Y | int | 訂單金額 | 見「金額限制」 |
| `Timestamp` | Y | int | Unix 時間戳 | `time()` |
| `ProdDesc` | Y | string | 商品說明 | 長度 ≤550，超出截斷；多項以半形 `;` 分隔 |
| `ReturnURL` | C | string | 前景通知網址 | Form POST 回傳；空值則停留在 PAYUNi 結果頁 |
| `NotifyURL` | C | string | 背景通知網址 | **僅限 80 / 443 port** |
| `BackURL` | C | string | 返回商店按鈕網址 | |
| `DeepLinkURL` | C | string | 深層連結（icash/LINE/街口/AFTEE 才生效）| 有值時不觸發 ReturnURL；**Sandbox 環境會自動忽略**，需同時帶 ReturnURL |
| `UsrMail` | C | string | 消費者信箱 | 預付帶入付款人信箱 |
| `UsrMailFix` | C | int | 信箱固定 | `1`=不可改 |
| `Cardholder` | C | int | 啟用持卡人英文姓名輸入 | `1`=啟用（3D 交易） |
| `ExpireDate` | C | string | 繳費有效日期 | `YYYY-MM-DD`；CVS 最大 +7 天，ATM 最大 +180 天，預設 +7 天 |
| `AtmBankType` | C | string | 指定 ATM 銀行 | 逗號分隔銀行代碼，如 `822,004,013` |
| `TradeLExpireSec` | C | int | 付款頁面截止秒數 | 60–600，預設 600 |
| `API3D` | C | int | 強制 3D 交易 | `1`=強制 |
| `Union3D` | C | int | 銀聯指定 Unionpay | `1`=啟用 |
| `Lang` | C | string | 語系 | `en` / `zh-tw`（預設 zh-tw） |

### 付款方式開關（未帶任何支付參數時，依後台預設）

| 參數 | 類型 | 說明 |
|------|------|------|
| `Credit` | int | 信用卡一次付清（`1`=啟用） |
| `CreditInst` | string | 分期，如 `"3,6,9,12"`（支援 3/6/9/12/18/24/30） |
| `CreditUnionPay` | int | 銀聯 |
| `ATM` | int | 虛擬帳號 |
| `CVS` | int | 超商代碼 / 條碼 |
| `ICash` | int | icash Pay |
| `LinePay` | int | LINE Pay |
| `JKoPay` | int | 街口支付 |
| `Aftee` | int | AFTEE 先享後付 |
| `ApplePay` | int | Apple Pay（不支援銀聯） |
| `GooglePay` | int | Google Pay（不支援銀聯） |
| `SamsungPay` | int | Samsung Pay（不支援銀聯/JCB） |
| `Ship` | int | 取貨付款（搭配物流參數） |
| `TradeInvoice` | int | 電子發票 |

> 詳細參數定義見 `references/upp-request-params.md`。

來源：[#/7/34 — 請求參數](https://docs.payuni.com.tw/web/#/7/34)

## 回傳參數（外層 Form POST + EncryptInfo 解密後）

### 外層

| 參數 | 說明 |
|------|------|
| `Status` | `SUCCESS` / `UNKNOWN` / `Unapproved` / 錯誤代碼 |
| `MerID` | 商店代號 |
| `Version` | `2.0` |
| `EncryptInfo` | 加密字串 |
| `HashInfo` | 驗章 |

### EncryptInfo 內 — 通用

| 參數 | 說明 |
|------|------|
| `Status` | `SUCCESS` / `UNKNOWN` / `UNAPPROVED` / 錯誤代碼 |
| `Message` | 狀態說明 |
| `MerID` | 商店代號 |
| `MerTradeNo` | 商店訂單編號 |
| `TradeNo` | UNi 序號（PAYUNi 內部唯一交易 ID） |
| `TradeAmt` | 訂單金額 |
| `TradeStatus` | `0`=取號成功 / `1`=已付款 / `2`=付款失敗 / `3`=付款取消 / `8`=訂單待確認 |
| `PaymentType` | 支付工具（見下表） |
| `Gateway` | **固定 `2`**=整合式支付頁 (UPP) |
| `BuyerHash` | 買方會員 Token Hash（首次帶入 BuyerToken 並完成登入後才會回傳） |

### PaymentType（UPP V2 回傳）

| 值 | 工具 |
|----|------|
| 1 | 信用卡（含分期/紅利/銀聯/Apple/Google/Samsung Pay） |
| 2 | ATM 轉帳 |
| 3 | 超商代碼 |
| 5 | 貨到付款（超商取貨付款） |
| 6 | 愛金卡（icash Pay） |
| 7 | 後支付（AFTEE） |
| 9 | LINE Pay |
| 10 | 宅配到付（黑貓） |
| 11 | 街口支付（JKoPay） |

> 各支付工具專屬回傳欄位（信用卡分期、ATM 虛擬帳號、CVS 繳費代碼、物流取貨資訊、宅配地址等）見 `references/upp-response-params.md`。

來源：[#/7/34 — 返回參數](https://docs.payuni.com.tw/web/#/7/34)

## 信用卡支援卡別

| 卡別 | 一次付清 | 分期 | Apple Pay | Google Pay | Samsung Pay |
|------|:---:|:---:|:---:|:---:|:---:|
| Visa | ✓ | ✓ | ✓ | ✓ | ✓ |
| MasterCard | ✓ | ✓ | ✓ | ✓ | ✓ |
| JCB | ✓ | ✓ | ✓ | ✓ | ✗ |
| 銀聯 | ✓ | ✗ | ✗ | ✗ | ✗ |

分期期數：3 / 6 / 9 / 12 / 18 / 24 / 30 期（依各銀行支援）。

## 訂單金額限制

| 支付工具 | 限額（TWD） |
|---------|------|
| 信用卡 | 1 ~ 199,999 |
| ATM 轉帳 | 15 ~ 49,999 |
| 超商代碼 | 30 ~ 20,000 |
| AFTEE 先享後付 | 20 ~ 49,999 |
| 貨到付款（超商取貨付款）| 1 ~ 20,000 |
| 宅配貨到付款（黑貓）| 30 ~ 20,000 |
| icash Pay | 依 icash Pay 公告 |
| LINE Pay | 依 LINE Pay 公告 |
| 街口支付 | 依街口支付公告 |

來源：[#/7/170 — 交易訂單金額限制說明](https://docs.payuni.com.tw/web/#/7/170)

## 主要 API 端點清單

| 功能 | 路徑 | Version | 方式 | 詳細 |
|------|------|---------|------|------|
| 整合支付頁（UPP）| `/api/upp` | 2.0 | Form POST | 本檔 |
| 交易查詢 | `/api/trade/query` | 2.0 | HTTP POST | `references/api-reference.md` |
| 交易請退款（CREDIT）| `/api/trade/close` | 1.0 | HTTP POST | `references/api-reference.md` |
| 交易取消授權（CREDIT）| `/api/trade/cancel` | 1.0 | HTTP POST | `references/api-reference.md` |
| 信用卡 Token 查詢 | `/api/credit_bind/query` | 1.0 | HTTP POST | `references/api-reference.md` |
| 信用卡 Token 取消 | `/api/credit_bind/cancel` | 1.0 | HTTP POST | `references/api-reference.md` |
| 取消超商代碼 | `/api/cancel_cvs` | 1.0 | HTTP POST | `references/api-reference.md` |
| icash 退款 | `/api/trade/common/refund/icash` | 1.0 | HTTP POST | `references/api-reference.md` |
| AFTEE 確認 | `/api/trade/common/confirm/aftee` | 1.0 | HTTP POST | `references/api-reference.md` |
| AFTEE 退款 | `/api/trade/common/refund/aftee` | 1.0 | HTTP POST | `references/api-reference.md` |
| LINE Pay 退款 | `/api/trade/common/refund/linepay` | 1.0 | HTTP POST | `references/api-reference.md` |
| 信用卡幕後（CREDIT）| `/api/credit` | 1.3 | HTTP POST | `references/api-reference.md` |
| 虛擬帳號幕後（ATM）| `/api/atm` | 1.3 | HTTP POST | `references/api-reference.md` |
| 超商代碼幕後（CVS）| `/api/cvs` | 1.3 | HTTP POST | `references/api-reference.md` |
| LINE Pay 幕後 | `/api/linepay` | 1.2 | HTTP POST | `references/api-reference.md` |
| AFTEE 幕後 | `/api/aftee_direct` | 1.1 | HTTP POST | `references/api-reference.md` |
| 街口支付幕後 | `/api/jkopay` | 1.1 | HTTP POST | `references/api-reference.md` |

> 所有非 UPP 端點 HTTP body 都是同樣的外層格式 `MerID=...&Version=...&EncryptInfo=...&HashInfo=...`，**且 Header 應加入 `User-Agent: payuni`**。
> 回傳是 JSON：`{ "Status": "SUCCESS", "MerID": "...", "Version": "...", "EncryptInfo": "...", "HashInfo": "..." }`，當 `Status === "ERROR"` 時無 EncryptInfo。

## Sandbox 測試環境

| 項目 | 值 |
|------|----|
| 註冊 | https://sandbox.payuni.com.tw/signup |
| 後台 | https://sandbox.payuni.com.tw |
| API base | `https://sandbox-api.payuni.com.tw` |

### 測試卡號

來源：[#/7/34 — 測試區信用卡測試卡號](https://docs.payuni.com.tw/web/#/7/34)

| 用途 | 卡號 |
|------|------|
| 一次付清（成功）| `4147631000000001` / `3560511000000001` |
| 一次付清模擬 3D ECI 不符（取消授權）| `4147631000000002` / `3560511000000002` |
| 分期付款（不支援 9 期）| `4147632000000001` / `3560512000000001` |
| 銀聯卡 | `6200000000000001` |
| Apple/Google/Samsung Pay | 任意卡號皆模擬成功 |
| LINE Pay | Channel ID / Channel Secret 填任意數字 |

到期日 / CVC：任意（建議到期日 `12/30`、CVC `123`）。

> ⚠️ **PAYUNi sandbox 只接受上表卡號**——別用其他金流的測試卡。
> NewebPay 的 `4000-2211-1111-1111` 在 PAYUNi 會授權失敗。各家測試卡互不相通：ECPay 見 `ECPay-API-Skill`、NewebPay 見 `newebpay-mpg`。

### Sandbox ATM / CVS 模擬繳費

> 後台 → **交易動態 > 模擬繳費** 按鈕，立即觸發 NotifyURL 回呼，免等真實繳費。

### Sandbox DeepLinkURL 注意

> 測試環境會自動忽略 DeepLinkURL 視為空值。**請務必同時提供 ReturnURL**，否則交易結果頁會無法返回商家。

## 常見注意事項

1. **MerTradeNo 10 分鐘內不可重複**——重送會觸發錯誤代碼。
2. **NotifyURL 僅接受 80/443 port**——無法在 dev tunnel 隨意指 port。
3. **交易結果以 NotifyURL 為準**——ReturnURL 由瀏覽器轉發，使用者可能中途關閉。
4. **CVS 效期最大 7 天**：超過支付頁不顯示超商代碼支付方式（會降為其他付款工具）。
5. **ATM 效期最大 180 天**。
6. **`UNKNOWN` 狀態**：60 秒未收到銀行回應就先回 `UNKNOWN`，後續以 NotifyURL 通知最終結果，建議 15 分鐘後再發動 `/api/trade/query` 確認。
7. **收到回傳必須驗證 HashInfo**——否則資料可能被竄改。驗章流程：
   - 外層 `Status === "ERROR"` → 直接拒絕（無 EncryptInfo）
   - 比對 `SHA256(hashKey + EncryptInfo + hashIv).toUpperCase() === HashInfo`
   - 解密 EncryptInfo → 檢查內層 `Status === "SUCCESS"`
8. **Sandbox 與 Production HashKey/HashIV 不同**——切換環境一定要對應換金鑰。
9. **DeepLinkURL 僅 icash Pay / LINE Pay / 街口支付 / AFTEE 生效**，其他支付工具忽略。

來源：[#/7/34](https://docs.payuni.com.tw/web/#/7/34)

## 平台/代理商模式

若為平台商代理子商店收款，外層多帶 `IsPlatForm=1`（不進 EncryptInfo，直接放外層 form body）。

## References

| 需求 | 檔案 |
|------|------|
| 完整 EncryptInfo 內層請求欄位（含 token / 買方 token / 優惠券 / 物流）| `references/upp-request-params.md` |
| 完整回傳欄位（含各 PaymentType 專屬欄位、宅配/物流回傳）| `references/upp-response-params.md` |
| 加解密 PHP / Node.js / Java 完整官方範例 | `references/encryption.md` |
| 所有非 UPP API 端點（交易查詢/退款/取消/Token/幕後）| `references/api-reference.md` |
| 完整錯誤代碼分類與對照表 | `references/error-codes.md` |
| 銀行代碼 / 超商代碼 / 物流貨態狀態碼 | `references/codes-reference.md` |
| Sandbox 後台驗證流程（playwright-cli 自動化）| `references/sandbox-resources.md` |
| NestJS 11 + TypeScript 整合範例（Service / Controller / Zod DTO）| `references/nestjs-integration.md` |
| 修改記錄 (2022-08 至今) | `references/changelog.md` |

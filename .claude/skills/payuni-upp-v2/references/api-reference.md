# PAYUNi 完整 API 端點參考（與 UPP V2 共用加解密）

> 所有 API 共用同一套 AES-256-GCM + SHA256 機制，差別在於 endpoint 和 Version。
> 來源：官方 API 各章節，hash id 已標於各小節。

## TOC

- [API 端點總覽](#api-端點總覽)
- [共用請求格式（HTTP API）](#共用請求格式http-api)
- [交易查詢 API](#交易查詢-api)
- [交易請退款 API（CREDIT）](#交易請退款-apicredit)
- [交易取消授權 API（CREDIT）](#交易取消授權-apicredit)
- [信用卡 Token 查詢 API](#信用卡-token-查詢-api)
- [信用卡 Token 取消 API](#信用卡-token-取消-api)
- [取消超商代碼 API](#取消超商代碼-api)
- [非信用卡退款 API（icash / Aftee / LinePay）](#非信用卡退款-apiicash--aftee--linepay)
- [AFTEE 交易確認 API](#aftee-交易確認-api)
- [信用卡幕後 (CREDIT) API](#信用卡幕後-credit-api)
- [虛擬帳號幕後 (ATM) API](#虛擬帳號幕後-atm-api)
- [超商代碼幕後 (CVS) API](#超商代碼幕後-cvs-api)
- [LINE Pay 幕後 API](#line-pay-幕後-api)
- [AFTEE 幕後 API](#aftee-幕後-api)
- [街口支付 (JKoPay) 幕後 API](#街口支付-jkopay-幕後-api)
- [續期收款 API](#續期收款-api)
- [平台/代理商模式](#平台代理商模式)

---

## API 端點總覽

| 功能 | 路徑 | Version | 來源 |
|------|------|---------|------|
| 整合支付頁（UPP）| `/api/upp` | 2.0 | [#/7/34](https://docs.payuni.com.tw/web/#/7/34) |
| 交易查詢 | `/api/trade/query` | 2.0 | [#/7/164](https://docs.payuni.com.tw/web/#/7/164) |
| 交易請退款（CREDIT）| `/api/trade/close` | 1.0 | [#/7/38](https://docs.payuni.com.tw/web/#/7/38) |
| 交易取消授權（CREDIT）| `/api/trade/cancel` | 1.0 | [#/7/39](https://docs.payuni.com.tw/web/#/7/39) |
| 信用卡 Token 查詢 | `/api/credit_bind/query` | 1.0 | [#/7/40](https://docs.payuni.com.tw/web/#/7/40) |
| 信用卡 Token 取消 | `/api/credit_bind/cancel` | 1.0 | [#/7/41](https://docs.payuni.com.tw/web/#/7/41) |
| 取消超商代碼 | `/api/cancel_cvs` | 1.0 | [#/7/333](https://docs.payuni.com.tw/web/#/7/333) |
| icash 退款 | `/api/trade/common/refund/icash` | 1.0 | [#/7/72](https://docs.payuni.com.tw/web/#/7/72) |
| AFTEE 確認 | `/api/trade/common/confirm/aftee` | 1.0 | [#/7/85](https://docs.payuni.com.tw/web/#/7/85) |
| AFTEE 退款 | `/api/trade/common/refund/aftee` | 1.0 | [#/7/84](https://docs.payuni.com.tw/web/#/7/84) |
| LINE Pay 退款 | `/api/trade/common/refund/linepay` | 1.0 | [#/7/377](https://docs.payuni.com.tw/web/#/7/377) |
| 信用卡幕後 | `/api/credit` | 1.3 | [#/7/35](https://docs.payuni.com.tw/web/#/7/35) |
| 虛擬帳號幕後 | `/api/atm` | 1.3 | [#/7/36](https://docs.payuni.com.tw/web/#/7/36) |
| 超商代碼幕後 | `/api/cvs` | 1.3 | [#/7/37](https://docs.payuni.com.tw/web/#/7/37) |
| LINE Pay 幕後 | `/api/linepay` | 1.2 | [#/7/326](https://docs.payuni.com.tw/web/#/7/326) |
| AFTEE 幕後 | `/api/aftee_direct` | 1.1 | [#/7/350](https://docs.payuni.com.tw/web/#/7/350) |
| 街口支付幕後 | `/api/jkopay` | 1.1 | [#/7/386](https://docs.payuni.com.tw/web/#/7/386) |

> 正式 base URL：`https://api.payuni.com.tw`
> Sandbox base URL：`https://sandbox-api.payuni.com.tw`

---

## 共用請求格式（HTTP API）

> UPP 是 Form POST（瀏覽器導引），其餘所有 API 都是 **HTTP Post（cURL POST）**。

### Header

```
Content-Type: application/x-www-form-urlencoded
User-Agent: payuni
```

> 官方建議 User-Agent 為 `payuni`。

### Body

```
MerID={商店代號}&Version={版本}&EncryptInfo={hex}&HashInfo={SHA256 大寫}
```

### 回應（JSON）

```json
{
  "Status": "SUCCESS",
  "MerID": "...",
  "Version": "1.0",
  "EncryptInfo": "...(hex)...",
  "HashInfo": "...(SHA256 大寫)..."
}
```

> 當 `Status === "ERROR"` 時無 `EncryptInfo`，直接是錯誤回應。

---

## 交易查詢 API

**端點**：`/api/trade/query` | **Version**：`2.0` | **來源**：[#/7/164](https://docs.payuni.com.tw/web/#/7/164)

可查詢交易狀態，含信用卡、ATM、超商代碼、icash、AFTEE、LINE Pay、超商取貨、黑貓宅配、街口支付。

### EncryptInfo 請求參數

| 參數 | 必要 | 類型 | 說明 | 備註 |
|------|------|------|------|------|
| `MerID` | Y | string | 商店代號 | |
| `MerTradeNo` | C | string | 商店訂單編號 | 與 TradeNo 擇一；長度 ≤25；`[A-Za-z0-9_-]` |
| `TradeNo` | C | string | UNi 序號 | 與 MerTradeNo 擇一 |
| `Timestamp` | Y | int | 時間戳 | |

### 回應 EncryptInfo（解密後）

| 參數 | 說明 |
|------|------|
| `Status` | `SUCCESS`=查詢成功 / 錯誤代碼 |
| `Message` | 查詢成功 / 錯誤敘述 |
| `MerTradeNo` | 商店訂單編號 |
| `TradeNo` | UNi 序號 |
| `TradeAmt` | 訂單金額 |
| `TradeFee` | 交易手續費（統一金流收取）|
| `TradeStatus` | `0`=取號成功；`9`=未付款；`1`=已付款；`2`=付款失敗；`3`=付款取消；`4`=交易逾期；`8`=訂單待確認 |
| `PaymentType` | 同 UPP（多了 `8`=退貨代收 C2B 退貨便） |
| `PaymentDay` | 支付日期 `YYYY-MM-DD HH:II:SS` |
| `CreateDay` | 建立日期 `YYYY-MM-DD HH:II:SS` |
| `Gateway` | 閘道：`1`=單串；`2`=UPP；`3`=UOP |
| `DataSource` | `A`=完整資料；`B`=處理中（建議 10 分鐘後再查）|

> 信用卡專屬欄位：`Card6No`、`Card4No`、`CardExp` (`MMYY`)、`CardInst`、`AuthCode`、`AuthType`（含 `3`=紅利，2025/09/01 起停支援）、`CardBank`、`CloseStatus`、`CloseAmt`、`RefundType`、`RefundStatus`、`RefundAmt`、`RefundDay`、`RemainAmt`。
> 各 PaymentType 專屬欄位同 UPP 回傳（見 `upp-response-params.md`），多筆紀錄統一以 `Result` 陣列（從 `0` 開始）回傳。
> TradeFee 說明：(1) `DataSource=A` 且 `TradeStatus=1` 時才有正確手續費，否則為 `0`；(2) `PaymentType=6` (icash) 由 icash 收取，回傳 `-`；`PaymentType=9` (LINE Pay) 為交易處理費。

---

## 交易請退款 API（CREDIT）

**端點**：`/api/trade/close` | **Version**：`1.0` | **來源**：[#/7/38](https://docs.payuni.com.tw/web/#/7/38)

僅信用卡：包含一次付清（可全/部分請退款）、分期（僅全額）、銀聯（僅全額）、國外卡（可全/部分）、Apple Pay（可部分）。

- 已授權之交易可發動請款（平台預設自動請款）。
- 已請款之交易若取消訂單可發動退款。
- 請款天期：授權成功後 **3 天內**請款（逾期可能不被銀行受理）。
- 退款天期：請款完成後 **180 天內**退款。

### EncryptInfo 請求參數

| 參數 | 必要 | 類型 | 說明 | 備註 |
|------|------|------|------|------|
| `MerID` | Y | string | 商店代號 | |
| `Timestamp` | Y | int | 時間戳 | |
| `TradeNo` | Y | string | UNi 序號 | |
| `CloseType` | Y | int | 關帳類型 | `1`=請款；`2`=退款；`-1`=取消請款；`-2`=取消退款 |
| `TradeAmt` | C | int | 請退款金額 | 部分請退款時必填 |

### 回應 EncryptInfo

```
Status / Message / MerID / TradeNo / CloseType
```

---

## 交易取消授權 API（CREDIT）

**端點**：`/api/trade/cancel` | **Version**：`1.0` | **來源**：[#/7/39](https://docs.payuni.com.tw/web/#/7/39)

取消尚未請款的授權交易。

### EncryptInfo 請求參數

| 參數 | 必要 | 類型 | 說明 |
|------|------|------|------|
| `MerID` | Y | string | 商店代號 |
| `Timestamp` | Y | int | 時間戳 |
| `TradeNo` | Y | string | UNi 序號 |

---

## 信用卡 Token 查詢 API

**端點**：`/api/credit_bind/query` | **Version**：`1.0` | **來源**：[#/7/40](https://docs.payuni.com.tw/web/#/7/40)

### EncryptInfo 請求參數

> `CreditToken` / `CreditHash` 擇一即可。

| 參數 | 必要 | 類型 | 說明 | 備註 |
|------|------|------|------|------|
| `MerID` | Y | string | 商店代號 | |
| `CreditToken` | C | string | 信用卡 Token | 長度 ≤150；`[A-Za-z0-9@.#$%_-]` |
| `CreditTokenType` | C | int | Token 紀錄類型 | `1`=會員（預設）；`2`=商店 |
| `CreditHash` | C | string | Token Hash | 長度 64 |
| `Timestamp` | Y | int | 時間戳 | |

### 回應 EncryptInfo（多筆 Result 陣列）

每筆包含：`Status` / `Message` / `CreditHash` / `CreditToken` / `CreditTokenType` / `CreditTokenExpired` (`MMYY`) / `CreditTokenStatus`（`1`=正常；`3`=刪除；`4`=逾期）/ `Card6No` / `Card4No` / `CardExpiredDT` (`MMYY`)。

---

## 信用卡 Token 取消 API

**端點**：`/api/credit_bind/cancel` | **Version**：`1.0` | **來源**：[#/7/41](https://docs.payuni.com.tw/web/#/7/41)

| 參數 | 必要 | 類型 | 說明 |
|------|------|------|------|
| `MerID` | Y | string | 商店代號 |
| `Timestamp` | Y | int | 時間戳 |
| `UseTokenType` | Y | int | Token 類型 |
| `BindVal` | Y | string | 綁定回傳值（`CreditHash` 或 `CreditToken`）|

---

## 取消超商代碼 API

**端點**：`/api/cancel_cvs` | **Version**：`1.0` | **來源**：[#/7/333](https://docs.payuni.com.tw/web/#/7/333)

| 參數 | 必要 | 類型 | 說明 |
|------|------|------|------|
| `MerID` | Y | string | 商店代號 |
| `Timestamp` | Y | int | 時間戳 |
| `PayNo` | Y | string | 超商繳費代碼 |

---

## 非信用卡退款 API（icash / Aftee / LinePay）

| 工具 | 端點 | 來源 |
|------|------|------|
| icash | `/api/trade/common/refund/icash` | [#/7/72](https://docs.payuni.com.tw/web/#/7/72) |
| AFTEE | `/api/trade/common/refund/aftee` | [#/7/84](https://docs.payuni.com.tw/web/#/7/84) |
| LINE Pay | `/api/trade/common/refund/linepay` | [#/7/377](https://docs.payuni.com.tw/web/#/7/377) |

### 共用 EncryptInfo 參數

| 參數 | 必要 | 類型 | 說明 |
|------|------|------|------|
| `MerID` | Y | string | 商店代號 |
| `Timestamp` | Y | int | 時間戳 |
| `TradeNo` | Y | string | UNi 序號 |
| `TradeAmt` | Y | int | 退款金額 |

> 退款是否可部分取決於各支付工具——icash 通常僅全額。

---

## AFTEE 交易確認 API

**端點**：`/api/trade/common/confirm/aftee` | **Version**：`1.0` | **來源**：[#/7/85](https://docs.payuni.com.tw/web/#/7/85)

AFTEE 交易須由商店向 AFTEE 平台**確認出貨/服務提供**後，AFTEE 才會向消費者請款。

| 參數 | 必要 | 類型 | 說明 |
|------|------|------|------|
| `MerID` | Y | string | 商店代號 |
| `Timestamp` | Y | int | 時間戳 |
| `TradeNo` | Y | string | UNi 序號 |

---

## 信用卡幕後 (CREDIT) API

**端點**：`/api/credit` | **Version**：`1.3` | **來源**：[#/7/35](https://docs.payuni.com.tw/web/#/7/35)

PCIDSS 合規商店才可申請。需審核開通且綁定 IP。

支援：一次付清（國內/國外）、分期（3/6/9/12/18/24/30）、Visa/MasterCard/JCB/銀聯。

### 主要 EncryptInfo 請求參數

| 參數 | 必要 | 類型 | 說明 | 備註 |
|------|------|------|------|------|
| `MerID` | Y | string | 商店代號 | |
| `MerTradeNo` | Y | string | 訂單編號 | 限制長度 25；`[A-Za-z0-9_-]`；10 分鐘內不可重複 |
| `TradeAmt` | Y | int | 金額 | |
| `Timestamp` | Y | int | 時間戳 | |
| `ProdDesc` | Y | string | 商品說明 | |
| `CardNo` | Y | string | 卡號 | 16 位數 |
| `CardExp` | Y | string | 到期日 | 格式 `MMYY` |
| `CVC` | Y | string | 末三碼 | |
| `Cardholder` | C | string | 持卡人英文姓名 | 3D 必填 |
| `Inst` | C | int | 分期數 | `3 / 6 / 9 / 12 / 18 / 24 / 30` |
| `API3D` | C | int | 強制 3D | `1`=啟用 |
| `ReturnURL` | C | string | 3D 完成後返回網址 | |
| `NotifyURL` | C | string | 背景通知 | |
| `CreditToken` | C | string | 首次 Token 綁定識別 | |
| `UseTokenType` | C | int | Token 類型 | 同 UPP |
| `CreditHash` | C | string | 已綁定卡 Hash（續期收款用）| |
| `BuyerToken` | C | string | 買方 Token | |
| `BuyerHash` | C | string | 買方 Hash | |
| `UserIP` | C | string | 持卡人 IP | |

> 完整欄位請參考官方頁。回傳結構與 UPP 信用卡欄位類似。

---

## 虛擬帳號幕後 (ATM) API

**端點**：`/api/atm` | **Version**：`1.3` | **來源**：[#/7/36](https://docs.payuni.com.tw/web/#/7/36)

僅支援單繳帳號（一次性虛擬帳號）。需綁定取號 IP。

### 主要 EncryptInfo 請求參數

| 參數 | 必要 | 類型 | 說明 | 備註 |
|------|------|------|------|------|
| `MerID` | Y | string | 商店代號 | |
| `MerTradeNo` | Y | string | 訂單編號 | |
| `TradeAmt` | Y | int | 金額 | |
| `Timestamp` | Y | int | 時間戳 | |
| `BankType` | Y | string | 銀行代碼 | 參考[#/7/50](https://docs.payuni.com.tw/web/#/7/50) |
| `PaySet` | C | int | 繳費帳號類型 | `1`=單繳（預設）|
| `NotifyURL` | C | string | 背景通知 | 80/443 |
| `UsrMail` | Y/C | string | 消費者信箱 | 啟用物流或電子發票時必填 |
| `ProdDesc` | Y | string | 商品說明 | 長度 ≤550 |
| `ExpireDate` | C | string | 繳費截止日期 | `YYYY-MM-DD`；`PaySet=1` 時最大 +180 天，預設 +7 天 |
| `BuyerHash` | C | string | 買方 Hash | |

---

## 超商代碼幕後 (CVS) API

**端點**：`/api/cvs` | **Version**：`1.3` | **來源**：[#/7/37](https://docs.payuni.com.tw/web/#/7/37)

7-ELEVEN 超商代碼取號，需綁定取號 IP。

### 主要 EncryptInfo 請求參數（與 ATM 類似）

| 參數 | 必要 | 類型 | 說明 |
|------|------|------|------|
| `MerID` | Y | string | 商店代號 |
| `MerTradeNo` | Y | string | 訂單編號 |
| `TradeAmt` | Y | int | 金額（30~20,000）|
| `Timestamp` | Y | int | 時間戳 |
| `ProdDesc` | Y | string | 商品說明 |
| `ExpireDate` | C | string | 繳費截止日 | 最大 +7 天，預設 +7 天 |
| `NotifyURL` | C | string | 背景通知 |
| `BuyerHash` | C | string | 買方 Hash |

---

## LINE Pay 幕後 API

**端點**：`/api/linepay` | **Version**：`1.2` | **來源**：[#/7/326](https://docs.payuni.com.tw/web/#/7/326)

幕後產生 LINE Pay 付款連結，由消費者掃碼/開啟 APP 完成。需 LINE Pay Channel ID/Secret Key。

### 主要 EncryptInfo 請求參數

| 參數 | 必要 | 類型 | 說明 |
|------|------|------|------|
| `MerID` | Y | string | 商店代號 |
| `MerTradeNo` | Y | string | 訂單編號 |
| `TradeAmt` | Y | int | 金額 |
| `Timestamp` | Y | int | 時間戳 |
| `ProdDesc` | Y | string | 商品說明 |
| `ReturnURL` | C | string | 前景返回 |
| `NotifyURL` | C | string | 背景通知 |
| `DeepLinkURL` | C | string | App deep link |

---

## AFTEE 幕後 API

**端點**：`/api/aftee_direct` | **Version**：`1.1` | **來源**：[#/7/350](https://docs.payuni.com.tw/web/#/7/350)

幕後產生 AFTEE 付款連結。需綁定 IP。

---

## 街口支付 (JKoPay) 幕後 API

**端點**：`/api/jkopay` | **Version**：`1.1` | **來源**：[#/7/386](https://docs.payuni.com.tw/web/#/7/386)

幕後產生街口支付連結。需綁定 IP。

---

## 續期收款 API

PAYUNi 續期收款（定期定額）有獨立模組，端點：

| 功能 | 端點 |
|------|------|
| 續期收款建立 | `/api/period` |
| 續期收款狀態修改 | `/api/period/mdf` |
| 續期收款訂單內容修改 | `/api/period/exchange` |
| 續期收款卡號修改 | `/api/period/exchange` |
| 續期收款訂單查詢 | `/api/period/query` |

> 詳見官方 [續期收款](https://docs.payuni.com.tw/web/#/7/304) 章節。
> 簡易續期：直接用 `/api/credit` 帶 `CreditHash` 進行幕後授權即可。

---

## 平台/代理商模式

若為平台商代理子商店收款，於 **外層 form body（不進 EncryptInfo）** 額外帶：

| 參數 | 說明 |
|------|------|
| `IsPlatForm` | `1`=啟用平台模式 |

> 平台模式下，子商店共用平台商的 HashKey/HashIV 加解密，但交易歸屬於子商店。

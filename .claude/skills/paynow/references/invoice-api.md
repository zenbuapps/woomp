# 體系 3：電子發票 API 完整參考

> 來源：docs.paynow.com.tw/developer/docs/invoice/*。串接台灣財政部電子發票。
> 所有 API `Authorization: Bearer {商家 JWT-Token}`。
> 正式 `https://invoiceapi-prod.paynow.com.tw/`，測試 `https://invoiceapi-dev.paynow.com.tw/`。

## 目錄（TOC）

1. 認證與環境
2. 操作流程
3. 單張發票開立 — POST /api/invoices/issue
4. 發票作廢 — POST /api/invoices/cancel
5. 發票折讓 — POST /api/invoices/allowance
6. 折讓作廢 — POST /api/invoices/cancel-allowance
7. 取得發票資料 — GET /api/invoices
8. POS 機取得發票號碼 — POST /api/invoices/pos/invoice-numbers
9. POS 機發票開立 — POST /api/invoices/pos/issue
10. 載具 / 課稅別 / 零稅率原因全表
11. 各情境 request 範例

---

## 1. 認證與環境

```
測試：https://invoiceapi-dev.paynow.com.tw/
正式：https://invoiceapi-prod.paynow.com.tw/
所有 API：Header  Authorization: Bearer {商家 JWT-Token}
```

> 串接前需先取得**商家 JWT-Token**，打發票 API 時放入 Bearer Token 發送。
> ⚠️ 官方 curl 範例 host 寫成 `https://docs.paynow.com.tw/api/invoices/...`（文件站），
> **真實 host 是 `invoiceapi-prod.paynow.com.tw` / `invoiceapi-dev.paynow.com.tw`**。

---

## 2. 操作流程

一般開立流程（官方）：

```
1. 開立發票：POST /api/invoices/issue（依未開立的訂單編號配一個未使用發票號碼開立）
2. 作廢發票：POST /api/invoices/cancel（作廢已開立發票）
3. 開立折讓單：POST /api/invoices/allowance（對該發票號碼開對應折讓單）
4. 作廢折讓單：POST /api/invoices/cancel-allowance（作廢已開立折讓單）
```

POS 開立流程：

```
1. POS 取得發票號碼：POST /api/invoices/pos/invoice-numbers
   （取得後自行管理避免重複；該批號碼不進一般上傳流程，未使用者次期單數月 5 號上傳空白發票）
2. POS 機開立發票：POST /api/invoices/pos/issue（用取得的號碼開立，須自行建立隨機碼帶入）
```

統一回應外層：`{ status, type, message, result, request_id }`（查詢另帶 `paginate`）。

---

## 3. 單張發票開立 — `POST /api/invoices/issue`

Content-Type：`application/json`（也接受 `text/json`、`application/*+json`）。

**Request Body**

| 欄位 | 型別 | 說明 |
|------|------|------|
| `order_no` | string nullable | 訂單編號 |
| `send_paper` | boolean | 是否寄送紙本（true 寄到買方地址，依合約額外扣點） |
| `send_sms` | boolean | 是否寄送簡訊（true 寄到買方手機，依合約額外扣點） |
| `carrier_type` | string | 載具類型 enum（見第 10 節） |
| `carrier_id1` | string nullable | 載具明碼（手機條碼 / 悠遊卡 / 自然人憑證填；`BuyerSno`/`None` 留空） |
| `carrier_id2` | string nullable | 載具隱碼（值同明碼；`BuyerSno`/`None` 留空） |
| `npoban` | string nullable | 愛心碼（捐贈發票） |
| `total_amount` | int32 | 發票總金額 |
| `tax_amount` | int32 | 稅額。**非統編發票帶 0**（國稅局算稅）；統編發票帶實際稅額。整張發票應稅金額 = 應稅品項小計合計 − 整張發票稅額 |
| `tax_type` | string | 稅別 enum：`SaleTax` 應稅 / `FreeTax` 免稅 / `ZeroTax` 零稅率 / `MixTax` 混合（只能 應稅+免 或 應稅+零稅率） |
| `main_remark` | string nullable | 總備註 |
| `is_pass_customs` | boolean nullable | 是否經海關（**零稅率必填**） |
| `zero_tax_rate_reason` | string | 零稅率原因 enum（見第 10 節） |
| `buyer` | object | 買方資訊（見下） |
| `items` | object[] nullable | 商品明細（見下） |

`buyer` 物件：

| 欄位 | 說明 |
|------|------|
| `name` | 買方名稱 |
| `identifier` | 統一編號（B2B 帶統編；B2C 可空） |
| `address` | 地址 |
| `phone` | 手機（`carrier_type=BuyerSno` 時 PayNow 依此帶會員載具號碼） |
| `email` | Email |

`items[]` 每筆：

| 欄位 | 說明 |
|------|------|
| `quantity` | 數量 |
| `unit_price` | 單價 |
| `amount` | 小計 |
| `tax_type` | 該品項稅別（`SaleTax`/`ZeroTax`/`FreeTax`，混合稅率時用） |
| `tax_amount` | 該品項稅額 |
| `description` | 品名 |

**Response**：`{ status, type, message, result, request_id }`（`result` 含開立結果，如發票號碼）。

```
POST /api/invoices/issue
Authorization: Bearer {token}
Content-Type: application/json
```

---

## 4. 發票作廢 — `POST /api/invoices/cancel`

**Request Body**：`{ "invoice_number": "發票號碼" }`（string nullable）。

**Response**：`{ status, type, message, result, request_id }`。

---

## 5. 發票折讓 — `POST /api/invoices/allowance`

**Request Body**

| 欄位 | 型別 | 說明 |
|------|------|------|
| `invoice_number` | string nullable | 發票號碼 |
| `remark` | string nullable | 備註（僅標註用，不上傳財政中心） |
| `items` | object[] nullable | 折讓品項 |

`items[]` 每筆：

| 欄位 | 說明 |
|------|------|
| `quantity` | 數量 |
| `unit_price` | 單價 |
| `amount` | 小計 |
| `tax` | 稅額 |
| `tax_type` | 稅別（`SaleTax` 等） |
| `invoice_body_sequence_number` | 對應原發票明細序號 |

**Response**：`{ status, type, message, result, request_id }`（`result` 含折讓號碼 `allowance_number`）。

---

## 6. 折讓作廢 — `POST /api/invoices/cancel-allowance`

**Request Body**：`{ "allowance_number": "折讓號碼" }`（string nullable）。

**Response**：`{ status, type, message, result, request_id }`。

---

## 7. 取得發票資料 — `GET /api/invoices`

**Query Parameters**

| 參數 | 型別 | 說明 |
|------|------|------|
| `InvoiceNumber` | string | 發票號碼 |
| `OrderNo` | string | 訂單號碼 |
| `Limit` | int32 | 每頁資料筆數 |
| `Page` | int32 | 第幾頁 |

**Response**：`{ status, type, message, result, request_id, paginate }`。

```
GET /api/invoices?InvoiceNumber=&OrderNo=&Limit=10&Page=1
Authorization: Bearer {token}
```

---

## 8. POS 機取得發票號碼 — `POST /api/invoices/pos/invoice-numbers`

**Request Body**

| 欄位 | 型別 | 說明 |
|------|------|------|
| `quantity` | int32 | 數量（要取幾個號碼） |
| `uuid` | string nullable | 取號 UUID（辨識用） |

**Response**：`{ status, type, message, result, request_id }`（`result` 含配發的發票號碼）。

> 取得後請自行管理避免重複開立；該批號碼**不進一般流程的上傳**，未使用者於次期單數月 5 號上傳空白發票。

---

## 9. POS 機發票開立 — `POST /api/invoices/pos/issue`

**Request Body**：同第 3 節（issue）全部欄位，**外加**：

| 欄位 | 型別 | 說明 |
|------|------|------|
| `invoice_number` | string nullable | 發票號碼（POS 取號取得的號碼） |
| `invoice_date` | date-time nullable | 開立發票日期 |
| `random_number` | string nullable | 隨機碼（須自行建立帶入） |
| `is_printed` | boolean | POS 機消費者可選不印出（false 時可吃載具設定，但仍要吃 request 帶來的隨機碼與開立時間） |

**Response**：`{ status, type, message, result, request_id }`。

---

## 10. 載具 / 課稅別 / 零稅率原因全表

### carrier_type 載具類型

| 值 | 載具 | carrier_id1 / carrier_id2 |
|----|------|---------------------------|
| `None` | 實體列印發票（紙本） | 留空 |
| `PhoneBarCodeCarrier` | 個人手機載具（手機條碼） | 帶載具明碼 / 隱碼（值同明碼） |
| `EasyCardCarrier` | 悠遊卡載具 | 帶載具明碼 / 隱碼 |
| `CitizenDigitalCardNo` | 自然人憑證 | 帶載具明碼 / 隱碼 |
| `BuyerSno` | PayNow 會員載具 | 留空（有買方手機時 PayNow 依手機帶會員載具號碼） |

- 捐贈發票：`carrier_type` 可帶空，並帶 `npoban`（愛心碼）。

### tax_type 課稅別

| 值 | 意義 |
|----|------|
| `SaleTax` | 應稅 |
| `FreeTax` | 免稅 |
| `ZeroTax` | 零稅率 |
| `MixTax` | 混合稅率（只能 應稅+免 或 應稅+零稅率） |

### zero_tax_rate_reason 零稅率原因（`tax_type=ZeroTax` 時）

| 值 | 意義 |
|----|------|
| `None` | 無 |
| `ExportGoods` | 外銷貨物 |
| `ExportLabor` | 與外銷有關之勞務，或在國內提供而在國外使用之勞務 |
| `FreeTaxGoods` | 依法設立之免稅商店銷售與過境或出境旅客之貨物 |
| `OperatingGoodsOrLabor` | 銷售與保稅區營業人供營運之貨物或勞務 |
| `InterNationsTransPort` | 國際間之運輸（外國運輸事業需相等待遇 / 免徵類似稅捐為限） |
| `InterNationsShip` | 國際運輸用之船舶、航空器及遠洋漁船 |
| `SalesInterNationsShip` | 銷售與國際運輸用之船舶、航空器及遠洋漁船所使用之貨物或修繕勞務 |
| `Eight` | 保稅區營業人銷售與課稅區營業人未輸往課稅區而直接出口之貨物 |
| `Nine` | 保稅區營業人銷售與課稅區營業人存入自由港區事業或海關保稅倉庫 / 物流中心以供外銷之貨物 |

---

## 11. 各情境 request 範例

官方提供以下情境的 Body 範例（同一 issue 端點，依欄位組合不同）：
非統編發票（帶手機號碼默認會員載具）、統編發票、手機條碼歸戶、悠遊卡歸戶、自然人憑證歸戶、
捐贈發票、零稅率發票、免稅發票、混稅發票（應稅+零稅率）、混稅發票（應稅+免稅）、
混稅統編發票（應稅+零稅率）、混稅統編發票（應稅+免稅）、寄送紙本給消費者、寄送簡訊給消費者。

### 11.1 非統編 B2C 發票（手機條碼載具）

```json
POST /api/invoices/issue
{
  "order_no": "ORDER-20260609-001",
  "send_paper": false,
  "send_sms": false,
  "carrier_type": "PhoneBarCodeCarrier",
  "carrier_id1": "/ABC1234",
  "carrier_id2": "/ABC1234",
  "npoban": null,
  "total_amount": 1050,
  "tax_amount": 0,
  "tax_type": "SaleTax",
  "main_remark": null,
  "is_pass_customs": null,
  "zero_tax_rate_reason": "None",
  "buyer": { "name": "王小明", "identifier": "", "address": "", "phone": "0912345678", "email": "buyer@example.com" },
  "items": [
    { "quantity": 1, "unit_price": 1050, "amount": 1050, "tax_type": "SaleTax", "tax_amount": 0, "description": "商品 A" }
  ]
}
```

### 11.2 統編 B2B 發票（紙本，帶稅額）

```json
POST /api/invoices/issue
{
  "order_no": "ORDER-20260609-002",
  "send_paper": true,
  "send_sms": false,
  "carrier_type": "None",
  "carrier_id1": null,
  "carrier_id2": null,
  "npoban": null,
  "total_amount": 1050,
  "tax_amount": 50,
  "tax_type": "SaleTax",
  "is_pass_customs": null,
  "zero_tax_rate_reason": "None",
  "buyer": { "name": "某某有限公司", "identifier": "12345678", "address": "台北市…", "phone": "", "email": "ap@company.com" },
  "items": [
    { "quantity": 1, "unit_price": 1000, "amount": 1000, "tax_type": "SaleTax", "tax_amount": 50, "description": "商品 B" }
  ]
}
```

### 11.3 捐贈發票

```json
{
  "order_no": "ORDER-20260609-003",
  "carrier_type": "None",   // 捐贈時載具留 None/空
  "npoban": "919",          // 愛心碼
  "total_amount": 500, "tax_amount": 0, "tax_type": "SaleTax",
  "zero_tax_rate_reason": "None",
  "buyer": { "name": "王小明", "identifier": "", "phone": "0912345678", "email": "buyer@example.com" },
  "items": [ { "quantity": 1, "unit_price": 500, "amount": 500, "tax_type": "SaleTax", "tax_amount": 0, "description": "商品 C" } ]
}
```

### 11.4 零稅率發票

```json
{
  "order_no": "ORDER-20260609-004",
  "carrier_type": "None",
  "total_amount": 1000, "tax_amount": 0,
  "tax_type": "ZeroTax",
  "is_pass_customs": true,                  // 零稅率必填
  "zero_tax_rate_reason": "ExportGoods",    // 零稅率原因必填
  "buyer": { "name": "Foreign Co", "identifier": "", "email": "buyer@example.com" },
  "items": [ { "quantity": 1, "unit_price": 1000, "amount": 1000, "tax_type": "ZeroTax", "tax_amount": 0, "description": "Export goods" } ]
}
```

> 發票金額計算（總額 = 銷售額 + 稅額；統編發票自行算稅、非統編帶 0 由國稅局算）關係公司稅務，
> **請務必與財會人員確認**。

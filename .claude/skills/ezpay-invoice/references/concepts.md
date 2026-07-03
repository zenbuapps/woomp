# ezPay 電子發票 — 概念與機制

對應 EZP_INVI_1.2.1 標準版。本檔說明加密、驗證、載具、課稅別、發票生命週期等
背景知識與規則。

## AES-256-CBC 加密 PostData_

`PostData_` 是除 `MerchantID_` 外所有業務參數的加密結果。

### 規格

| 項目 | 值 |
|------|-----|
| 演算法 | AES-256-CBC |
| 金鑰 HashKey | 商店專屬，長度 **32 bytes**（256-bit）|
| IV HashIV | 商店專屬，長度 **16 bytes**（128-bit）|
| Padding | PKCS#7（自行補，blocksize = 32）|
| 加密模式選項 | 自行補 padding 後，openssl 用 ZERO_PADDING（不再補）|
| 輸出 | 加密結果轉**小寫 hex 字串** |

### 加密三步驟

1. **組 query string**：業務參數依官方範例用 `http_build_query` 風格組成 query
   string（key=value&key=value...），中文值需 url encode。
2. **補 PKCS#7 padding**：以 blocksize 32 對字串補 padding。
   PKCS#7 規則：缺 N bytes 就補 N 個值為 N 的 byte（N = 32 − (len mod 32)）。
3. **AES 加密 + hex**：用 HashKey + HashIV 做 AES-256-CBC 加密，結果 `bin2hex`
   轉小寫 hex → 即 `PostData_`。

### 官方 PHP 範例（加密核心）

```php
function addpadding($string, $blocksize = 32) {
    $len = strlen($string);
    $pad = $blocksize - ($len % $blocksize);
    $string .= str_repeat(chr($pad), $pad);   // PKCS#7
    return $string;
}

$post_data_str = http_build_query($post_data_array);
$key = 'abcdefghijklmnopqrstuvwxyzabcdef';  // HashKey，32 bytes
$iv  = '1234567891234567';                  // HashIV，16 bytes

// PHP 7+：先 addpadding 補 PKCS#7，加密時用 ZERO_PADDING（告訴 openssl 別再補）
$post_data = trim(bin2hex(openssl_encrypt(
    addpadding($post_data_str),
    'AES-256-CBC',
    $key,
    OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
    $iv
)));
```

### 官方 .NET C# 範例（加解密核心）

```csharp
// 加密：AddPKCS7Padding(blocksize 32) → RijndaelManaged CBC + Padding.None → hex
public string EncryptAES256(string source) {
    string sSecretKey = "12345678901234567890123456789012"; // 32 bytes
    string iv = "1234567890123456";                          // 16 bytes
    byte[] sourceBytes = AddPKCS7Padding(Encoding.UTF8.GetBytes(source), 32);
    var aes = new RijndaelManaged();
    aes.Key = Encoding.UTF8.GetBytes(sSecretKey);
    aes.IV  = Encoding.UTF8.GetBytes(iv);
    aes.Mode = CipherMode.CBC;
    aes.Padding = PaddingMode.None;   // padding 已自行補
    ICryptoTransform transform = aes.CreateEncryptor();
    return ByteArrayToHex(transform.TransformFinalBlock(
        sourceBytes, 0, sourceBytes.Length)).ToLower();
}

// 解密：hex → bytes → RijndaelManaged CBC + Padding.None → RemovePKCS7Padding
```

### 為什麼是「自行補 PKCS#7 + ZERO_PADDING」

- ezPay 規定 blocksize 為 **32**（不是 AES 標準 block 的 16）——所以必須自行補。
- 自行補了 PKCS#7 後，若再讓 openssl / RijndaelManaged 用預設 padding，會**多補一個
  block**，平台解密時 padding 對不上 → `KEY10002`（資料解密錯誤）。
- 因此正確做法：自行 `addpadding`（PKCS#7, 32）→ 加密時 padding 設為
  `ZERO_PADDING` / `PaddingMode.None`（明確告訴 crypto 函式「不要再補」）。

> Node.js 實作要點：Node 的 `crypto.createCipheriv('aes-256-cbc', ...)` 預設
> `autoPadding(true)`（PKCS padding，block 16）。需 `cipher.setAutoPadding(false)`
> 並自行補 PKCS#7（blocksize 32），與 PHP / C# 範例一致。實作見 integration.md。

## CheckCode 回應驗證（SHA256）

平台每筆成功回應都帶 `CheckCode`，讓商店驗證回應確實來自 ezPay。
**CheckCode 是回應端驗證機制，與 request 端的 AES 加密無關。**

### 計算規則

1. 取回應中**五個欄位**：`InvoiceTransNo`（ezPay 電子發票開立序號）、
   `MerchantID`（商店代號）、`MerchantOrderNo`（自訂編號）、
   `RandomNum`（發票防偽隨機碼）、`TotalAmt`（發票金額）。
2. 五個參數依**英文字母 A~Z 排序**（首字母相同比次字母），用 `&` 串聯成
   query string。
3. 字串**前後**加上 HashIV 與 HashKey：
   `"HashIV=" + <IV> + "&" + <排序串聯字串> + "&HashKey=" + <Key>`。
4. 整串做 **SHA256**，輸出**轉大寫** → 與回應的 `CheckCode` 比對，相同則回應合法。

### 官方範例

```
(1) 排列參數欄位並串聯（A~Z 排序）：
InvoiceTransNo=14061313541640927&MerchantID=3622183&MerchantOrderNo=201409170000001&RandomNum=0142&TotalAmt=500

(2) 前後加上 HashIV 與 HashKey：
HashIV=1234567891234567&InvoiceTransNo=14061313541640927&MerchantID=3622183&MerchantOrderNo=201409170000001&RandomNum=0142&TotalAmt=500&HashKey=abcdefghijklmnopqrstuvwxyzabcdef

(3) SHA256 後轉大寫：
303AB800650B724733B5D91CBCE075D9EA09E4CDE9CD33461D45F07D5EC7EECB
```

官方 PHP 範例：

```php
$check_code_arr = array(
    'MerchantID'      => '3622183',
    'MerchantOrderNo' => '201409170000001',
    'InvoiceTransNo'  => '14061313541640927',
    'TotalAmt'        => '500',
    'RandomNum'       => '0142',
);
ksort($check_code_arr);                       // A~Z 排序
$check_str  = http_build_query($check_code_arr);
$check_code = strtoupper(hash('sha256',
    'HashIV=1234567891234567&' . $check_str . '&HashKey=abcdefghijklmnopqrstuvwxyzabcdef'));
```

> **驗證時機**：開立發票 / 觸發開立 / 作廢 / 折讓 / 查詢的回應都帶 `CheckCode`。
> 比對 SHA256 時用 `crypto.timingSafeEqual`（見 integration.md）避免 timing side-channel。

## 載具規則

開立 B2C 發票時，買受人可選擇將發票存入載具。`CarrierType` + `CarrierNum` 搭配：

| CarrierType | 載具種類 | CarrierNum 內容與檢核 |
|-------------|----------|------------------------|
| `0` | 手機條碼載具 | 第 1 碼為 `/`，後接 7 碼；除第 1 碼外只能是 39 字元集 `0-9` `A-Z` `+` `-` `.`（**限大寫英字**）|
| `1` | 自然人憑證條碼載具 | 2 碼大寫英字 + 14 碼數字 |
| `2` | ezPay 電子發票載具 | 任意可識別買受人的代號（e-mail / 手機號碼 / 會員編號），由賣方自訂；同一代號視為同一買受人 |

規則：

- 載具僅 `Category=B2C` 適用。
- `CarrierType` 有值時 `CarrierNum` 必填。
- `CarrierNum` 須用 `rawurlencode()` 處理，值前後不得含空白。
- `CarrierType=2`（ezPay 電子發票載具）時 `BuyerEmail` 變必填。
- ezPay 電子發票載具**不需事先申請**——平台用「賣方統編 + 買受人代號」組成載具號碼。
- **載具與捐贈互斥**：`CarrierType` 有值 → `LoveCode` 必空；`LoveCode` 有值 →
  `CarrierType` 必空。
- ezPay 載具發票可用 `KioskPrintFlag=1` 開放買受人於合作超商 Kiosk（全家
  FamiPort）列印兌獎。

## 捐贈碼 LoveCode

- `LoveCode`：3~7 碼純數字，受贈單位 / 團體的愛心碼。
- 須為財政部電子發票整合服務平台清單內的有效捐贈碼（可至財政部平台查詢）。
- 僅 `Category=B2C` 適用；有值時 `CarrierType` 必為空。

## 課稅別 TaxType

發票層級 `TaxType`：

| 值 | 意義 |
|----|------|
| `1` | 應稅 |
| `2` | 零稅率 |
| `3` | 免稅 |
| `9` | 混合應稅與免稅或零稅率（限 `Category=B2C`）|

- `TaxType=2`（零稅率）必須帶 `CustomsClearance` 報關標記（`1`=非經海關出口、
  `2`=經海關出口）。
- `TaxType=9` 混合稅率時：
  - 須提供 `AmtSales` / `AmtZero` / `AmtFree` 三個分項銷售額，`Amt` = 三者合計。
  - 每項商品須以 `ItemTaxType`（`1`/`2`/`3`）標示課稅別。
  - 開折讓時需依應稅 / 零稅率 / 免稅**個別開立折讓單**，並用 `TaxTypeForMixed`
    指定折讓單課稅別。

查詢發票回應的 `InvoiceType` 是**字軌類型**（非課稅別）：`07`=一般稅額計算、
`08`=特種稅額計算。

## 含稅 / 未稅金額

| Category | ItemPrice / ItemAmt 性質 |
|----------|---------------------------|
| `B2B` | 未稅金額 |
| `B2C` | 含稅金額 |

平台金額檢核僅兩項：
1. 商品小計 = 商品數量 × 商品單價（`ItemAmt = ItemCount × ItemPrice`）。
2. 發票金額 = 銷售額 + 稅額（`TotalAmt = Amt + TaxAmt`）。
   折讓則為：折讓總金額 = 折讓商品小計 + 折讓商品稅額。

平台**不**檢核稅額是否嚴格等於「銷售額 × 稅率」——稅額正確性由營業人 / 財會負責。
金額計算範例見 api-reference.md「金額計算」章節。

## 發票生命週期

```
開立發票（invoice_issue）
  ├─ Status=1 即時開立 → 立即產生發票號碼
  ├─ Status=0 等待觸發 → 暫存，需 invoice_touch_issue 觸發才開出
  └─ Status=3 預約自動 → 暫存，預計日自動開出（可用 invoice_touch_issue 提前）
        ↓
  發票已開立（InvoiceStatus=1）
        ↓
  ├─ 退貨 → 開折讓（allowance_issue）
  │     ├─ Status=1 立即確認 → 平台隔日上傳財政部
  │     └─ Status=0 不立即確認 → 需 allowance_touch_issue
  │           ├─ AllowanceStatus=C 確認折讓 → 隔日上傳財政部
  │           └─ AllowanceStatus=D 取消折讓 → 折讓狀態變更為取消
  │     已確認折讓 → 可作廢折讓（allowanceInvalid）
  │
  └─ 整張取消 → 作廢發票（invoice_invalid，InvoiceStatus 變 2）
        條件：奇數月 14 日前可作廢前兩個月發票、未開過折讓、已上傳財政部成功
```

### 財政部上傳機制（非同步）

- 開立 / 作廢 / 折讓 API 回 `Status=SUCCESS` 只代表「ezPay 平台處理成功」，**不代表
  已上傳財政部**。
- 平台每日 **01:00 起**上傳前一日 00:00–23:59 的開立、作廢、折讓資料。
- 每日 **06:00 起**依財政部回覆更新上傳狀態。
- 商店要確認財政部上傳結果，需用 `invoice_search` 查 `UploadStatus`
  （`0`=未上傳、`1`=已上傳成功、`2`=上傳中、`3`=上傳失敗、`4`=上傳逾時）。

## 重送冪等性

開立發票時：

- `MerchantOrderNo`（自訂編號）同一商店內**不可重覆**。
- 若用**完全相同的 `PostData_`** 重送，平台回 `Status=SUCCESS` 並回傳原本那張發票的
  `Result`（不重複開立）——這是平台對相同 `PostData_` 的冪等保證。
- 若 `MerchantOrderNo` 重覆但其他欄位不同 → 回 `LIB10003`（自訂編號重覆）。

## 台灣電子發票背景

- ezPay 電子發票由**簡單行動支付股份有限公司**（藍新金流 NewebPay 集團品牌）提供，
  是台灣財政部認可的電子發票加值服務中心。
- 電子發票分 **B2C**（買受人為個人，可存載具 / 捐贈 / 索取紙本）與
  **B2B**（買受人為營業人，有統編，須索取紙本）。
- 發票字軌號碼由營業人在 ezPay 平台【管理設定／發票字軌號碼設定】新增；字軌用完
  會回 `INV90006`。
- 紙本電子發票證明聯（`PrintFlag=Y`）含 `BarCode`（一維條碼，兌獎用）與
  `QRcodeL` / `QRcodeR`（二維條碼，行動應用讀取與防偽用）。

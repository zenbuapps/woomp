# 體系 2：舊版 CashFlow API（導轉 + 背景交易）完整參考

> 來源：docs.paynow.com.tw/developer/docs/apipdf/cashflow/*。此為 PayNow 經典金流串接，
> 至今仍在運行，許多既有 EC 平台 / WooCommerce 外掛使用。與新版 REST（體系 1）並存，互不相通。

## 目錄（TOC）

1. 端點與環境
2. 導轉式金流（etopm.aspx）— 一般交易
3. 導轉式 — 自動扣款 / 預存授權 / 票券
4. 取得預存卡號授權（WSC_DLP）
5. 背景交易（PayNowAPI_JS.aspx）— 請款 / 退款 / 取消授權 / 查詢 / 票券核銷
6. PassCode（驗證碼）組成總表
7. PayType / CodeType 對照
8. 交易狀態查詢回應格式

> 加密 / 檢核碼演算法（AES256、GP/GK、TimeStr、TripleDES、SHA-1）見 `references/encryption.md`。

---

## 1. 端點與環境

| 用途 | 正式 | 測試 |
|------|------|------|
| 導轉式金流 | `https://www.paynow.com.tw/service/etopm.aspx` | `https://test.paynow.com.tw/service/etopm.aspx` |
| 背景交易 / 檢核碼 | `https://www.paynow.com.tw/service/PayNowAPI_JS.aspx` | `https://test.paynow.com.tw/service/PayNowAPI_JS.aspx` |
| 檢核碼握手 | `https://www.paynow.com.tw/service/paynowapi_js.aspx` | `https://test.paynow.com.tw/service/paynowapi_js.aspx` |
| 信用卡授權 WSDL | `https://www.paynow.com.tw/WS_CardAuthorise_JS.asmx` | `https://test.paynow.com.tw/WS_CardAuthorise_JS.asmx` |
| Apple Pay 商家驗證 | `https://mpay.paynow.com.tw/api/ApplePay/GetTransactionSession` | （同） |

- 傳遞方式：HTTP **form POST**；所有參數 **URL Encode**；字集 **UTF-8**。
- **正式 / 測試環境完全獨立**，帳號需個別申請，資料庫不互通。
- 測試平台除虛擬帳號外，其餘交易一律失敗（用於驗收回傳流程）；WebATM 安泰銀行不提供測試。
- 退款須慎用（連續 / 異常退款會被銀行視為信用問題，PayNow 將停用帳號）；不可在正式環境測試退款。

---

## 2. 導轉式金流（etopm.aspx）— 一般交易

Version: V1.7.1.0。商家組參數 form POST 導轉到 `etopm.aspx`，使用者付款後 PayNow POST
回商家於後台設定的「交易成功 / 失敗回傳網址」（**各服務各自設定回傳網址**）。

### 2.1 Request（信用卡 / WebATM / 虛擬帳號 / 超商代收 / 銀聯）

| 參數 | 名稱 | 型態 | 長度 | 必須 | 備註 |
|------|------|------|------|------|------|
| `WebNo` | 統編 / 身分證 | string | 10 | Y | 賣家登入帳號；身分證開頭大寫 |
| `PassCode` | 傳遞碼 | string | | Y | `sha1(WebNo + OrderNo + TotalPrice + apicode)`（直接相接，不含 `+`） |
| `ReceiverName` | 消費者姓名 | string | 20 | Y | 不可為數字 |
| `ReceiverID` | 消費者 ID | string | 50 | Y | 身分證 / Email / 手機 |
| `ReceiverTel` | 消費者電話 | string | 20 | Y | 無手機可填聯絡電話 |
| `ReceiverEmail` | 消費者 Email | string | | Y | 須符合 Email 規格 |
| `OrderNo` | 商家自訂訂單編號 | string | 50 | Y | 不可中文 |
| `ECPlatform` | EC 平台提供商 | string | 100 | Y | 商家網站名稱 |
| `TotalPrice` | 交易金額 | string | | Y | 最低 NT$30（票券需可被票券數整除） |
| `OrderInfo` | 商家自訂交易訊息 | string | 200 | Y | 5~200 字 |
| `Note1` / `Note2` | 備註 1 / 2 | string | 200 | Y | 商家自訂 |
| `PayType` | 付款方式 | string | 2 | Y | 見第 7 節 |
| `AtmRespost` | 是否導頁回傳參數 | string | 1 | N | `0`/`1`（需回傳參數帶 1；預設 0） |
| `DeadLine` | 繳款期限 | string | 1 | N | 數字，部分付款方式適用；預設 0 |
| `PayEN` | 中英文付款頁 | string | 1 | N | `0` 中文 / `1` 英文 |
| `CodeType` | 代碼繳費服務 | string | 1 | Y（PayType=05） | `0`ibon / `1`FamiPort / `2`icash；預設 0 |
| `EPT` | 系統分系代數 | string | 1 | Y | 固定帶 `1` |

> 店配服務須向 PayNow 申請設定。

### 2.2 Response — 信用卡 / WebATM / 銀聯 / 分期（即時導頁回傳）

回傳 form POST，參數 URL Encode（UTF-8），需 URL Decode。

| 參數 | 說明 |
|------|------|
| `WebNo` | 統編 / 身分證（僅信用卡回傳） |
| `BuysafeNo` | **PayNow 訂單編號**（19 碼） |
| `PassCode` | 驗證碼 = `sha1(WebNo + OrderNo + TotalPrice + 商家交易密碼 + TranStatus)` |
| `OrderNo` | 商家自訂編號 |
| `TranStatus` | `S` 成功 / `F` 失敗 |
| `ErrDesc` | 錯誤描述（`TranStatus=F` 時有值） |
| `TotalPrice` | 交易金額（30~999999999） |
| `Note1` / `Note2` | 備註 |
| `PayType` | `01`信用卡 `02`WebATM `09`銀聯 `11`分期付款 |
| `pan_no4` | 信用卡末四碼（僅信用卡） |
| `Card_Foreign` | `0` 國內卡 / `1` 國外卡 |
| `installment` | 分期期數（非分期為空或 1） |

### 2.3 Response — 虛擬帳號（離線回傳）

需消費者繳費後 PayNow 才通知（送到後台接收網址）；要在訂單成立時收回覆參數須帶 `AtmRespost`。

| 參數 | 說明 |
|------|------|
| `BuysafeNo` | PayNow 訂單編號 |
| `OrderNo` | 商家自訂編號 |
| `PassCode` | `sha1(WebNo + OrderNo + TotalPrice + 商家交易密碼)`（產生帳號時）或加 `TranStatus`（繳費成功時） |
| `TotalPrice` | 交易金額 |
| `PayType` | `03` 虛擬帳號 |
| `ATMNo` | 虛擬帳號號碼（繳款唯一編號） |
| `NewDate` | 產生日期（`yyyy/mm/dd hh:mm:ss`） |
| `DueDate` | 繳款期限（`yyyy/mm/dd`） |
| `TranStatus` | `S` 繳款成功 / `F` 未繳款 |
| `BankCode` / `BranchCode` | 銀行 / 分行代碼 |

### 2.4 Response — 四大超商條碼代收（離線回傳）

| 參數 | 說明 |
|------|------|
| `BuysafeNo` / `OrderNo` | PayNow / 商家訂單編號 |
| `PassCode` | `sha1(WebNo + OrderNo + TotalPrice + 商家交易密碼 + TranStatus)` |
| `TotalPrice` | 交易金額 |
| `PayType` | `10` 超商代收條碼繳費 |
| `BarCode1` / `BarCode2` / `BarCode3` | Code39 條碼文數字 |
| `NewDate` / `DueDate` | 產生日 / 繳款期限 |
| `TranStatus` | `S` 繳款成功 / `F` 未繳款 |

### 2.5 Response — ibon / FamiPort / icash（代碼繳費，PayType=05）

**2.5.1 交易產生時回傳**：`BuysafeNo`、`OrderNo`、`TotalPrice`、`PayType=05`、
`icashpayno`/`IBONNO`/`FamiPortNo`（繳費代碼）、`icashpayurl`（icash 付款連結，CodeType=2）、
`NewDate`、`DueDate`、`IdKey`（EC 廠商用，一般忽略）、`TranStatus`、
`PassCode` = `sha1(WebNo + OrderNo + TotalPrice + 商家交易密碼)`（成功才加 `TranStatus`）、
`PassCode2` = `strtoupper(sha1(PassCode + ReceiverEmail))`（僅成功回傳）、`Note1`/`Note2`。

**2.5.2 交易成功時回傳**：同上，`PassCode` = `sha1(WebNo + OrderNo + TotalPrice + 商家交易密碼 + TranStatus)`，
含 `ErrDesc`（`F` 時）。

---

## 3. 導轉式 — 自動扣款 / 預存授權 / 票券

### 3.1 自動扣款（Version ACV1.0.0.3，PayType=13）

Request 在 2.1 基礎上加：`Installment`（預備繳款期數 1~36, Y）、`PayDay`（授權日 01~31, Y）、
`CIFID`（UserID, 18 碼, Y）、`CIFPW`（UserPW, 18 碼, Y）、`CIFID_SN`（SN 序號, 預設 1）。
Response `PayType=13`（預存授權），回 `Installment`/`PayDay`/`CIFID`/`CIFPW`/`CIFID_SN`。

### 3.2 預存授權（Version CV1.0.1.1，PayType=01 + 預存欄位）

Request 加：`CIFID`、`CIFPW`、`CIFID_SN`、`Installment`（預存須 >1，最大 24）。
特約商家可授權 1 元（驗證用，授權後自動取消不請款）。
預存卡號 `CIFID`/`CIFPW` 必填；同序號不同卡或過期會被當次覆蓋。

### 3.3 一般票券交易（Version v1.7.0.3）

Request 加 `TotalTickets`（票券數量，僅票券交易帶；分期不適用）。
`TotalPrice` 須可被票券數整除。多張票券的 `BuysafeNo` 以逗號分隔回傳。

---

## 4. 取得預存卡號授權（OP=WSC_DLP）

走 `paynowapi_js.aspx`，HTTP POST。

**Request**：`OP=WSC_DLP`；`JStr1`/`JStr2` = 將下列 JSON 以「檢核碼握手取得的 Key/IV」AES256 加密後
對半拆解再 UrlEncode；另帶 `mem_cid`、`TimeStr`、`CheckNum`（加密卡號時的 TimeStr 與 CheckNum）。

JSON 內容：`mem_cid`（商家帳號）、`mem_checkpw`（交易密碼）、`OrderNo`、`ECPlatform`、
`TotalPrice`、`CIFID`、`CIFPW`、`UserIp`、
`PassCode` = `strtoupper(sha1(mem_cid + OrderNo + TotalPrice + mem_checkpw))`。

**Response**：純字串（JSON 以 Key/IV AES256 加密回傳，先 UrlDecode 再 AES256 解密）。JSON 欄位：
`WebNo`、`BuySafeNo`、`OrderNo`、`TotalPrice`、`RespCode`（授權回覆碼）、`TranStatus`、
`InvoiceStatus`/`InvoiceNo`/`batchNo`（若開發票）、`ResponseMSG`、`ApproveCode`（授權碼）、
`PassCode`（`sha1(WebNo & mem_checkpw & BuysafeNo & TotalPrice & RespCode)`）、
`last4CardNo`、`Result3D`、`CIFID_SN`、`ErrorMessage`。

---

## 5. 背景交易（PayNowAPI_JS.aspx）

**所有背景交易（請款 / 退款 / 取消授權 / 查詢）一律先走 GP→GK→操作 三段握手**（見 `references/encryption.md`）：

```
1. OP=GP：JStr（json，bootstrap AES256 加密 + urlencode）→ 回 PassCode + CheckNum
2. OP=GK：用 CheckNum → 回 PassCode + EncryptionKey + EncryptionIV
3. OP=<操作>：業務 JSON 用 EncryptionKey/EncryptionIV AES256 加密 → 字串對半拆成 JStr1+JStr2 → urlencode
   並帶 mem_cid + TimeStr（操作時用的）+ CheckNum
```

操作層回應為純字串：成功 `S_成功資訊`（urlencode）/ 失敗 `F_錯誤訊息`（urlencode）。

### 5.1 請款（OP=CP_gp）

JStr1/JStr2 內 JSON：`UserID`（統編 / 身分證）、`Buysafeno`（PayNow 訂單編號，最小 19 碼；多筆逗號分隔）、
`PassCode` = `strtoupper(sha1("2822" + UserID + 商家交易密碼 + "9955"))`。

### 5.2 退款（OP=R_gp）

JStr1/JStr2 內 JSON：

| 參數 | 說明 |
|------|------|
| `mem_type` | `1` 買家 / `2` 賣家 |
| `buysafeno` | PayNow 訂單編號 |
| `mem_cid` | 商家帳號 |
| `passcode` | `strtoupper(sha1("2822" + UserID + 商家交易密碼 + "9955"))` |
| `mem_bankaccno` | 退款入帳帳號 |
| `accountbankno` | 退款入帳銀行代碼 |
| `mem_bankaccount` | 退款入帳銀行名稱 |
| `refundvalue` | 退款原因 |
| `refundmode` | 退款模式 |
| `buyerid` / `buyername` / `buyeremail` | 消費者帳號 / 姓名 / Email（與原交易相同可空） |
| `refundprice` | 退款金額 |

> 退款錯誤碼 R000~R037 見 `references/error-codes.md`（含「不可當日退款 R011」「非成功交易不得退款 R018」
> 「已配送不得退款 R019」「已請款不得退款 R023」「不支援部分退款 R032」等）。

### 5.3 取消自動授權（OP=CPA_gp）

JStr1/JStr2 內 JSON：`mem_cid`、`OrderNO`、
`passcode` = `strtoupper(sha1("2822" + mem_cid + OrderNo + 商家交易密碼 + "9955"))`。

### 5.4 交易狀態查詢（OP=PQS_gp）

JStr1/JStr2 內 JSON：`mem_cid`、`OrderNO`、
`passcode` = `strtoupper(sha1("2822" + mem_cid + OrderNo + 商家交易密碼 + "9955"))`。
回應格式見第 8 節。

### 5.5 票券核銷碼查詢（OP=T_S）/ 票券核銷（OP=T_G）

> 這兩支用 **TripleDES** 加密（Key 固定 `28229955`），不是 AES256 握手。

`JStr` = JSON（TripleDES 加密）。JSON 內 `buysafeno`、`checkno`（核銷時帶）、
`passcode` = `sha1(商家帳號 + 商家自訂編號 + 票券訂單金額 + 商家交易密碼)`（以商家密碼加密）。
Response：`buysafeno`、`checkno`（7 碼核銷碼）、`passcode`、`errormessage`（`F_錯誤資訊`）。

---

## 6. PassCode（驗證碼）組成總表

> 全部用 **SHA-1**，輸入為 ASCII，輸出 HEX；多數需 `strtoupper`。各值「直接相接」（**不含 `+` 號**）。

| 情境 | 組成 |
|------|------|
| 導轉送出（傳遞碼） | `sha1(WebNo + OrderNo + TotalPrice + apicode)` |
| 導轉回傳（信用卡 / WebATM / 銀聯 / 分期 / 超商條碼） | `sha1(WebNo + OrderNo + TotalPrice + 商家交易密碼 + TranStatus)` |
| 導轉回傳（虛擬帳號 / ibon 產生時） | `sha1(WebNo + OrderNo + TotalPrice + 商家交易密碼)`（成功時加 `+ TranStatus`） |
| 導轉回傳 PassCode2（ibon/FamiPort/icash 成功） | `strtoupper(sha1(PassCode + ReceiverEmail))` |
| 預存授權（WSC_DLP） | `strtoupper(sha1(mem_cid + OrderNo + TotalPrice + mem_checkpw))` |
| 背景請款 / 退款 | `strtoupper(sha1("2822" + UserID + 商家交易密碼 + "9955"))` |
| 背景取消授權 / 查詢 | `strtoupper(sha1("2822" + mem_cid + OrderNo + 商家交易密碼 + "9955"))` |
| 票券核銷 | `sha1(商家帳號 + 商家自訂編號 + 票券訂單金額 + 商家交易密碼)` |
| 信用卡授權（WS_CardAuthorise） | `sha1(mem_cid + OrderNo + TotalPrice + mem_checkpw)`；回傳 `sha1(WebNo + mem_checkpw + BuysafeNo + TotalPrice + RespCode)` |

> 收到回傳時，務必用相同公式重算並比對 `PassCode`（建議 `hash_equals`），確認來源為 PayNow 且資料未被竄改。

---

## 7. PayType / CodeType 對照

| PayType | 付款方式 |
|---------|----------|
| `01` | 信用卡 |
| `02` | WebATM |
| `03` | 虛擬帳號 |
| `05` | 代碼繳費（ibon / FamiPort / icash，搭配 CodeType） |
| `09` | 銀聯 |
| `10` | 超商條碼 |
| `11` | 分期付款 |
| `13` | 自動扣款 / 預存授權 |

| CodeType（PayType=05） | 通路 |
|------------------------|------|
| `0` | ibon（7-11） |
| `1` | FamiPort（全家） |
| `2` | icash（icash 錢包） |

> WebATM：非約定帳戶單日轉帳上限 3 萬；交易金額 > 2 萬建議用信用卡。

---

## 8. 交易狀態查詢（PQS_gp）回應格式

回應為純字串（urlencode），以開頭數字判斷類別：

| 開頭 | 意義 | 範例 |
|------|------|------|
| `1` | 交易成功；逗號前=成功筆數，逗號後=19 碼 BuysafeNo `_`（卡末四碼 或 虛擬帳號），信用卡最後 `_` 為分期期數（非分期=1） | `1,50000011111469983213211_1`、`1,5000001111146998321_95533725300857` |
| `2` | 交易失敗（有 PayNow 訂單，授權失敗 / 未完成）；BuysafeNo `_` 卡末四碼 / 虛擬帳號 `_` 錯誤碼（可能空）；多組逗號分隔 | `2,5000001111146998321_3211_05` |
| `3` | 退貨交易；逗號後退貨狀態 `0` 買家申請 / `1` 買賣家確認 / `2` 銀行退款 / `3` 賣家申請 | `3,1`、`3,0` |
| `4` | 交易失敗（無交易訂單，使用者可能未送出授權，無法確認狀態） | `4` |
| `02`/`03`… | 重覆交易（成功 N 筆）；逗號前=成功筆數，之後每筆訂單逗號分隔 | `02,5000001111146998321_3211_1,5000001111146699323_4322_3` |

---

## 附：信用卡授權（WS_CardAuthorise_JS.asmx）

舊版 ApplePay / 後端授權用，引用函式 `CardAuthorise_P`，HTTP POST。
檢核碼握手用的 bootstrap AES256 固定金鑰見 `references/encryption.md`。

**Request**：`JStr`/`JStr2`（JSON 以握手取得的 Key/IV AES256 加密後對半拆）、`mem_cid`、`TimeStr`、`CheckNum`。

JSON Content：`mem_cid`、`mem_checkpw`、`OrderNo`、`OrderInfo`、`ECPlatform`、`ReceiverID`、
`ReceiverEmail`、`ReceiverName`、`ReceiverTel`、`TotalPrice`、
`PassCode` = `sha1(mem_cid & OrderNo & TotalPrice & mem_checkpw)`（不含 `&` 符號）。

**Response**（JSON，AES256 加密）：`WebNo`、`BuysafeNo`、`OrderNo`、`RespCode`、`TotalPrice`、
`TranStatus`（`S`/`F`）、`ResponseMSG`、`ApproveCode`、
`PassCode` = `sha1(WebNo + mem_checkpw + BuysafeNo + TotalPrice + RespCode)`、`last4CardNo`、
`CIFID_SN`、`ErrorMessage`。

> ApplePay 商家驗證（`mpay.paynow.com.tw/api/ApplePay/GetTransactionSession`）與其 Signature 演算法
> 見 `references/encryption.md`「ApplePay Signature」段。

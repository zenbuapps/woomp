# 黑貓宅配（Tcat）物流 API 完整參數表

> 來源：docs.payuni.com.tw 官方頁 #/7/268, 269, 270, 271, 272。爬取日 2026-05-04。
> LgsType 固定 `HOME`、ShipType 固定 `2`。

---

## <a id="trade-1.2"></a>5.1 建立宅配單（背景）— `/api/home_delivery/trade` Ver 1.2

> 官方頁 `#/7/268`。提供取貨付款（COD）與取貨不付款。**TradeAmt 30~20,000 元**。**可不綁定 PAYUNi 金流訂單**，直接純物流單建立。

### 請求參數（EncryptInfo）

| 參數 | 必要 | 類型 | 說明 | 備註 |
|---|---|---|---|---|
| `MerID` | Y | String |  |  |
| `Timestamp` | Y | Int |  |  |
| `MerTradeNo` | Y | String | 商店訂單編號 | 限制長度：25；格式 `[A-Za-z0-9_-]`；10 分鐘內不可重複 |
| `GoodsType` | Y | Int | 寄件型態 | 1=常溫，2=冷凍，3=冷藏 |
| `LgsType` | Y | String | **固定 HOME** |  |
| `ShipType` | Y | Int | **固定 2 = 黑貓** |  |
| `TradeAmt` | Y | Int | 訂單金額 | **下限 30，上限 20,000**；ServiceType=3 時為商品價值 |
| `ServiceType` | Y | Int | 1=取貨付款 / 3=取貨不付款 |  |
| `DeliveryTimeTag` | Y | String | 希望配達時段 | `01`=13 時前 / `02`=14-18 時 / `04`=不指定 |
| `Consignee` | Y | String | 收件人姓名 | ≤ 30；中/全形 ×2、半形 ×1 |
| `ConsigneeMobile` | Y | String | 收件人手機 | 09 開頭半形 |
| `ConsigneeTelAreaCode` | C | String | 收件人電話區碼 | 2~3 碼數字 |
| `ConsigneeTel` | C | String | 收件人電話 | 最多 8 碼數字 |
| `ConsigneeAddress` | Y | String | 收件人地址 | ≤ 120；格式：縣市+鄉鎮市區+段弄巷街+號(+樓) |
| `ProdDesc` | Y | String | 商品名稱 | ≤ 20；中/全形 ×2、半形 ×1 |
| `NotifyURL` | C | String | 宅配到付完成取件通知 URL | 僅 ServiceType=1 才會送 Notify；80/443 port only |
| `CarrierType` | Y/C | String | 發票載具類別 | 同 CVS |
| `CarrierInfo` | Y/C | String | 載具內容 | 同 CVS |
| `InvBuyerName` | Y/C | String | 買方名稱或公司抬頭 |  |
| `ProdDesc` | Y/C | String | 產品說明（發票用） | 與物流 ProdDesc 同名但語意不同；長度 ≤ 500 |
| `UsrMail` | Y/C | String | 消費者電子信箱 |  |

**優惠券（v1.2 起，2026-01-05）**：`PromoCode` / `DiscountAmt` / `OrderAmt` / `CouponNotifyURL`，同 CVS。

### 回傳（EncryptInfo 解密）

| 參數 | 類型 | 說明 |
|---|---|---|
| `Status` / `Message` / `MerID` |  |  |
| `MerTradeNo` / `TradeNo` / `TradeAmt` |  |  |
| `TradeStatus` | Int | **固定 0**（建立階段）|
| `PaymentType` | Int | 10=宅配到付 |
| `Gateway` | Int | 1=幕後 API |
| `TradeType` | Int | **固定 1=正物流** |
| `ShipTradeNo` | String | UNi 物流序號 |
| `GoodsType` / `LgsType=HOME` / `ShipType=2` |  |  |
| `ServiceType` | Int | 1 / 3 |
| `ShipAmt` | Int | 取貨付款金額 |
| `Consignee` / `ConsigneeMobile` / `ConsigneeAddress` |  |  |
| `ConsigneeTel` | String | 區碼+號碼，例 `02-12345678`；未帶為 `-` |
| `DeliveryTimeTag` |  |  |
| `ProductTypeId` | String | 商品類別代碼（自動分類）：`0001`=一般食品 / `0002`=名特產/甜產 / `0003`=酒/油/醋/醬 / `0004`=穀物蔬果 / `0005`=水產/肉品 / `0006`=3C / `0007`=家電 / `0008`=服飾配件 / `0009`=生活用品 / `0010`=美容彩妝 / `0011`=保健食品 / `0012`=醫療相關用品 / `0013`=寵物用品飼料 / `0014`=印刷品 / `0015`=其他 |
| `ProdDesc` | String | 商品名稱 |

### 「取件付款完成」Notify（ServiceType=1 才觸發；POST 到請求時填的 NotifyURL）

| 參數 | 類型 | 說明 |
|---|---|---|
| `Status` | String | SUCCESS |
| `Message` |  |  |
| `MerID` |  |  |
| `MerTradeNo` / `TradeNo` / `TradeAmt` |  |  |
| `TradeStatus` | Int | **固定 1** |
| `PaymentType` | Int | 1/2/3/5/6/7/8 對應金流方式 |
| `ShipTradeNo` |  |  |
| `Odno` | String | **12 碼**（黑貓托運單號）|
| `GoodsType` / `LgsType=HOME` / `ShipType=2` |  |  |
| `ServiceType` | Int | 1=取貨付款 |
| `ShipAmt` / `TradeAmt` |  |  |
| `PayTime` | Date | `YYYY-MM-DD HH:II:SS` |

> 一般「貨態變更通知」走 `/api/home_delivery/trade` 開通時設定的全域 Notify URL（page #/7/274）——見 `notify-and-status.md`。

---

## <a id="get_obt_number_pdf-1.0"></a>5.2 產宅配編號並下載託運單 PDF（前景）— `/api/home_delivery/get_obt_number_pdf` Ver 1.0

> 官方頁 `#/7/269`。Form POST，PAYUNi 直接產出 PDF。會自動產出 `FileNo`，**24 小時內**可用 `/download_pdf` 重抓。

### 請求參數（EncryptInfo）

| 參數 | 必要 | 類型 | 說明 | 備註 |
|---|---|---|---|---|
| `MerID` | Y |  |  |  |
| `Timestamp` | Y |  |  |  |
| `PostType` | C | Int | 傳遞方式 | 1=前景（預設）|
| `PrintType` | C | Int | 列印模式 | **固定 1**，預設 1 |
| `ShipTradeNo` | Y | String | UNi 物流序號 | 多筆以**半形逗號**分隔 |
| `GoodsType` | Y | Int | 1/2/3 |  |
| `LgsType` | Y | String | **固定 HOME** |  |
| `ShipType` | Y | Int | **固定 2** |  |
| `ShipDate` | Y | Date | 出貨日期 `YYYYMMDD` | **必須大於今日**且非週日國定假日 |
| `DeliveryDate` | Y | Date | 希望配達日期 `YYYYMMDD` | **必須大於 ShipDate**且非週日國定假日（**2025-02-10 新增**）|
| `Spec` | Y | Int | 規格代碼 | 1=60 cm / 2=90 cm / 3=120 cm / 4=150 cm（**冷凍冷藏不支援 150**）|
| `HideProdDesc` | C | String | 是否隱藏商品名稱 | 預設 N；Y=隱藏 |
| `Memo` | C | String | 給黑貓的話 | ≤ 100；中/全形 ×2、半形 ×1 |

**Form POST 回傳 PDF 檔**（前景下載）。同時若有設貨態 Notify URL，會發出含 `FileNo` 的 Notify 給商店。

---

## <a id="download_pdf-1.0"></a>5.3 重新下載託運單 PDF（前景）— `/api/home_delivery/download_pdf` Ver 1.0

> 官方頁 `#/7/270`。**FileNo 24 小時內有效**。

### 請求參數（EncryptInfo）

| 參數 | 必要 | 類型 | 說明 | 備註 |
|---|---|---|---|---|
| `MerID` | Y |  |  |  |
| `Timestamp` | Y |  |  |  |
| `FileNo` | Y | String | 檔名序號 | 來自 5.2 回傳 |
| `ShipTradeNo` | C | String | UNi 物流序號（多筆逗號分隔） | 指定下載特定幾筆；空值 = 整個檔內所有單 |

Form POST 回傳 PDF。

---

## <a id="call_cat-1.0"></a>5.4 呼叫黑貓（背景）— `/api/home_delivery/call_cat` Ver 1.0

> 官方頁 `#/7/271`。**15:00 前完成預約**，黑貓司機兩日內（通常 15:00-18:00）到府收件。已是黑貓契約客戶且已有約定收件時間者**無需**使用此服務。

### 請求參數（EncryptInfo）

| 參數 | 必要 | 類型 | 說明 | 備註 |
|---|---|---|---|---|
| `MerID` | Y |  |  |  |
| `Timestamp` | Y |  |  |  |
| `ContactName` | Y | String | 聯絡人姓名 | ≤ 30；中/全形 ×2、半形 ×1 |
| `ContactMobile` | C | String | 聯絡人手機 | **市話/手機二擇一** |
| `ContactTelAreaCode` | C | String | 聯絡人電話區碼 | 2~4 碼數字 |
| `ContactTel` | C | String | 聯絡人電話 | 最多 8 碼數字 |
| `ContactTelExt` | C | String | 聯絡人電話分機 | 最多 8 碼 |
| `ContactAddress` | Y | String | 聯絡人地址 | ≤ 120；中/全形 ×2、半形 ×1 |
| `NormalQuantity` | Y | Int | 常溫包裹件數 | 0 或正整數 |
| `ColdQuantity` | Y | Int | 冷藏包裹件數 | 0 或正整數 |
| `FreezeQuantity` | Y | Int | 冷凍包裹件數 | 0 或正整數 |
| `IsContact` | Y | String | 是否需要事先電聯 | Y=要 / N=否 |
| `IsTrolley` | Y | String | 是否需要推車 | Y=要 / N=否 |
| `Memo` | C | String | 給宅配人員的備註 | ≤ 100 |

> **三種溫層件數限制**：
> 1. 三者總和 > 0
> 2. **每次呼叫只能指定單一溫層 > 0**（同時設定常溫 1、冷藏 2 會失敗，因為運輸車與溫控不同）

### 回傳（EncryptInfo 解密）

| 參數 | 類型 | 說明 |
|---|---|---|
| `Status` / `Message` / `MerID` |  |  |
| `ErrorMsg` | String | 呼叫失敗原因；成功回 `-` |
| `CallTime` | Date | 呼叫時間 `YYYY-MM-DD HH:II:SS` |

---

## <a id="refund-1.0"></a>5.5 建立宅配退貨單（背景）— `/api/home_delivery/refund` Ver 1.0

> 官方頁 `#/7/272`。建立黑貓退貨單（逆物流）。**ServiceType 固定 3=取貨不付款**。

### 請求參數（EncryptInfo）

| 參數 | 必要 | 類型 | 說明 | 備註 |
|---|---|---|---|---|
| `MerID` | Y |  |  |  |
| `Timestamp` | Y |  |  |  |
| `ShipTradeNo` | C | String | 原宅配單 UNi 物流序號 | 對原正物流退貨時帶 |
| `MerTradeNo` | C | String | 新商店訂單編號 | 不是原單退貨時填新編號；同一般限制 |
| `GoodsType` | Y | Int | 1/2/3 |  |
| `LgsType` | Y | String | **固定 HOME** |  |
| `ShipType` | Y | Int | **固定 2** |  |
| `ServiceType` | Y | Int | **固定 3** |  |
| `DeliveryTimeTag` | Y | String | 01/02/04 |  |
| `Consignee` | Y | String | 收件人姓名（**會自動代入商店開通時設定的寄件人**） |  |
| `ConsigneeMobile` | Y | String | 收件人手機（**會自動代入**） |  |
| `ConsigneeTelAreaCode` | C | String | 收件人電話區碼（**會自動代入**） |  |
| `ConsigneeTel` | C | String | 收件人電話（**會自動代入**） |  |
| `ConsigneeAddress` | Y | String | 收件人地址（**會自動代入**） |  |
| `Consignor` | Y | String | 退貨人姓名 |  |
| `ConsignorTelAreaCode` | C | String | 退貨人電話區碼 |  |
| `ConsignorTel` | C | String | 退貨人電話 |  |
| `ConsignorMobile` | Y | String | 退貨人手機 | 09 開頭 |
| `ConsignorAddress` | Y | String | 退貨人地址 | 黑貓司機收取退件之地址；≤ 120 |
| `ProdDesc` | Y | String | 商品名稱 | ≤ 20 |
| `Spec` | Y | Int | 1/2/3/4 cm | 冷凍冷藏不支援 4 |
| `ShipDate` | Y | Date | 取件日期 `YYYYMMDD` | **D+1~D+7**；**16:25 後申請最少 D+2** |
| `Memo` | C | String | 備註給黑貓 | ≤ 100 |

> 收件人欄位「會自動代入」是指若不傳，PAYUNi 會用商店開通時填的資訊；可覆寫。

### 回傳（EncryptInfo 解密）

| 參數 | 類型 | 說明 |
|---|---|---|
| `Status` / `Message` / `MerID` |  |  |
| `TradeType` | Int | **2=逆物流** |
| `MerTradeNo` / `ShipTradeNo` |  |  |
| `OBTNumber` | String | **退貨編號** |
| `GoodsType` / `LgsType=HOME` / `ShipType=2` |  |  |
| `ServiceType` | Int | 3 |
| `Consignee` / `ConsigneeMobile` / `ConsigneeTel` / `ConsigneeAddress` |  | 同寄件人邏輯 |
| `Consignor` / `ConsignorMobile` / `ConsignorTel` / `ConsignorAddress` |  |  |
| `Spec` | Int |  |
| `ShipDate` |  |  |
| `DeliveryTimeTag` |  |  |
| `ProdDesc` |  |  |
| `Gateway` | Int | 1=幕後 API |

---

## 黑貓宅配 Spec / ProductTypeId / 編號速查

| Spec | 尺寸 | 冷凍/冷藏支援？ |
|---|---|---|
| 1 | 60 cm | 是 |
| 2 | 90 cm | 是 |
| 3 | 120 cm | 是 |
| 4 | 150 cm | **否，僅常溫** |

| 編號 | 長度 | 用途 |
|---|---|---|
| `OBTNumber` | 12 碼 | 黑貓退貨單 |
| `Odno`（建立宅配單後） | 12 碼 | 黑貓宅配（正物流）託運單號 |
| `FileNo` | 24 小時內有效 | 重新下載 PDF 用 |

> 黑貓宅配查件網站 `https://www.t-cat.com.tw/inquire/trace.aspx`，最多 10 筆同時查詢。

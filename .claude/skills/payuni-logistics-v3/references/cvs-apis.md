# CVS（7-ELEVEN 超商）物流 API 完整參數表

> 來源：docs.payuni.com.tw 官方頁 #/7/103, 122, 123, 124, 125, 129, 304。爬取日 2026-05-04。
> 所有 API 一律 `application/x-www-form-urlencoded` POST 4 個欄位 (`MerID`, `Version`, `EncryptInfo`, `HashInfo`)，內層解密為下表業務參數。

---

## <a id="trade-1.3"></a>4.1 建立超商物流單 — `/api/logistics/trade` Ver 1.3

> 官方頁 `#/7/122`。對應 LgsType=`B2C`（大宗寄倉）/`C2C`（店到店）。冷凍只支援 7-ELEVEN B2C 與 C2C；C2B 退貨便另用 `/refund`。

### 請求參數（EncryptInfo 解密後）

| 參數 | 必要 | 類型 | 說明 | 備註（官方原文） |
|---|---|---|---|---|
| `MerID` | Y | String | 商店代號 |  |
| `Timestamp` | Y | Int | 時間戳記 | 格式：time() (unix epoch sec) |
| `MerTradeNo` | Y | String | 商店訂單編號 | 限制長度：25；格式: [A-Za-z0-9_-]；10 分鐘內不可重複 |
| `GoodsType` | Y | Int | 寄件型態 | 1=常溫，2=冷凍 |
| `LgsType` | Y | String | 物流型態 | B2C=大宗寄倉；C2C=店到店 |
| `ShipType` | Y | Int | 通路類別 | 固定 1=7-ELEVEN |
| `TradeAmt` | Y | Int | 訂單金額 | 等於取貨付款金額，**上限 20,000 元**；若 ServiceType=3（不付款），則 TradeAmt 為商品價值（用於遺失賠償）|
| `ServiceType` | Y | Int | 取件方式 | 1=取貨付款；3=取貨不付款 |
| `StoreID` | Y | String | 取件門市代碼 | 限制長度：6（例：`916712`），由 ship_map 取得 |
| `Consignee` | Y | String | 取件人姓名 | 限制長度：10；最長 5 個中文字、最短至少 2 個中文字或 4 個英文字（請填真實姓名，超商核對身分用）|
| `ConsigneeMobile` | Y | String | 取件人手機號碼 | 限填 09 開頭半形數字 |
| `ConsigneeMail` | C | String | 取件人電子信箱 | Email 格式 |
| `RefundStoreID` | C | String | 指定退貨門市 | **僅 C2C 支援**；未填則使用商店開通 C2C 時設定的退貨門市 |
| `SenderName` | C | String | 指定退貨收件人 | **僅 C2C 支援**；等同寄件人；限制長度 10；與 SenderMobile 必須同時填或同時不填 |
| `SenderMobile` | C | String | 指定退貨收件人手機 | **僅 C2C 支援**；09 開頭半形數字 |
| `NotifyURL` | C | String | 取貨付款完成取件通知 URL | 若 ServiceType=1 取貨付款，消費者完成取件並付款時送 Notify；**僅限 80 / 443 port** |
| `CarrierType` | Y/C | String | 發票載具類別 | 開立發票必帶；3J0002=手機條碼；CQ0001=自然人憑證；amego=會員載具；Donate=捐贈碼；Company=公司發票 |
| `CarrierInfo` | Y/C | String | 載具內容 | CarrierType=3J0002→手機條碼（含 /）；CQ0001→自然人憑證；Donate→捐贈碼；Company→統編；amego 免填 |
| `InvBuyerName` | Y/C | String | 買方名稱或公司抬頭 | 帶 CarrierType 時必填 |
| `ProdDesc` | Y/C | String | 產品說明 | 帶 CarrierType 時必填；長度 ≤ 500；半形分號 `;` 分隔多項 |
| `UsrMail` | Y/C | String | 消費者電子信箱 | 帶 CarrierType 時必填；CarrierType=amego 時必填 |

**優惠券 (2026-01-05 v1.3 起)**：

| 參數 | 必要 | 類型 | 說明 |
|---|---|---|---|
| `PromoCode` | Y | string | 優惠碼 |
| `DiscountAmt` | Y | int | 折扣金額 |
| `OrderAmt` | Y | int | 原訂單金額 |
| `CouponNotifyURL` | C | string | 優惠劵發劵背景通知網址 |

### 回傳參數（EncryptInfo 解密後）

| 參數 | 類型 | 說明 |
|---|---|---|
| `Status` | String | `SUCCESS` 或失敗代碼（見 `error-codes.md`） |
| `Message` | String | 顯示原因 |
| `MerID` | String | 商店代號 |
| `MerTradeNo` | String | 自訂編號 |
| `TradeNo` | String | UNi 序號 |
| `TradeAmt` | Int | 訂單金額 |
| `TradeStatus` | Int | **固定 0**（建立階段）|
| `PaymentType` | Int | 5=取貨付款；若 ServiceType=3 則固定 0 |
| `PartnerId` | String | 母代碼 |
| `GoodsType` | Int | 1=常溫，2=冷凍 |
| `LgsType` | String | B2C / C2C |
| `ShipType` | Int | 1=7-ELEVEN |
| `ShipTradeNo` | String | **UNi 物流序號（主鍵）** |
| `ShipAmt` | Int | 取貨付款金額 |
| `StoreID` / `StoreName` / `StoreAddr` | String | 取件門市資訊 |
| `Consignee` / `ConsigneeMobile` / `ConsigneeMail` | String | 收件人資訊 |
| `RefundStoreID` / `RefundStoreName` / `RefundStoreAddr` | String | C2C 指定退貨門市；**僅請求時有填才回傳** |
| `SenderName` / `SenderMobile` | String | C2C 指定退貨收件人；**僅請求時有填才回傳** |

### 「取件付款完成」Notify（ServiceType=1 才觸發）

PAYUNi POST 到請求時填的 `NotifyURL`，4 form 欄位（解密後）：

| 參數 | 類型 | 說明 |
|---|---|---|
| `Status` | String | `SUCCESS` |
| `Message` | String | 顯示原因 |
| `MerID` | String |  |
| `MerTradeNo` | String |  |
| `TradeNo` | String |  |
| `TradeAmt` | Int |  |
| `TradeStatus` | Int | **固定 1**（取件付款完成）|
| `PaymentType` | Int | 1=信用卡 / 2=ATM / 3=超商代碼 / 5=取貨付款 / 6=愛金卡 / 7=後支付 / 8=退貨代收 |
| `ShipTradeNo` | String |  |
| `Odno` | String | 出貨單編號（**8 碼**）|
| `GoodsType` / `LgsType` / `ShipType` | — |  |
| `ServiceType` | Int | 1=取貨付款；3=取貨不付款 |
| `ShipAmt` | Int | 代收金額 |
| `PayTime` | Date | 取件日期 `YYYY-MM-DD HH:II:SS` |

> 使用優惠券時另回 `PromoCode/DiscountAmt/OrderAmt/CouponFee/CampaignName/CampaignNo/CouponName/CouponNo`。

---

## <a id="ship_map-1.1"></a>4.2 超商門市地圖 — `/api/logistics/ship_map` Ver 1.1（前景）

> 官方頁 `#/7/103`。**Form POST 跳轉**——後端要產出 `<form action=https://.../ship_map>` 內含 4 個 hidden 欄位，並 auto-submit。消費者選擇門市後 PAYUNi 會 POST 到 `MapReturnURL`。

### 請求參數（EncryptInfo）

| 參數 | 必要 | 類型 | 說明 | 備註 |
|---|---|---|---|---|
| `MerID` | Y | String |  |  |
| `Timestamp` | Y | Int | unix sec |  |
| `MerKeyNo` | Y | String | 自訂編號（≤ 20）；商店辨識用 | **Tag=4 或 5 時，請帶 UNi 物流序號 ShipTradeNo** |
| `GoodsType` | Y | Int | 1=常溫，2=冷凍 |  |
| `LgsType` | Y | String | B2C / C2C |  |
| `ShipType` | Y | Int | 固定 1 = 7-ELEVEN |  |
| `MapType` | Y | Int | 地圖涵蓋區域 1=本島 / 2=本島含離島；GoodsType=2 時固定 2 |  |
| `MapReturnURL` | C | String | 接收門市資訊回傳 URL；可空 | 有值時 Tag=2/3/4/5 都會 form-redirect 回此 URL |
| `Tag` | Y | Int | **處理標記（不是超商代號）** | `2`=回傳選取的門市資訊 / `3`=更新商店退貨門市（C2C 限定）/ `4`=更新物流單取件門市 / `5`=更新指定一筆 C2C 物流單退貨門市 |
| `MobileTag` | C | String | N=PC、Y=手機；空白預設 PC | 影響地圖外觀 |

> **關於門市關轉處理**：超商可能門市裝修、搬遷導致暫停服務（貨態 81）。B2C 必須在收到通知 D+2 23:59 前用 Tag=4 重選；C2C 是 D+6 23:59；C2C 退貨門市重選用 Tag=3 (商店預設) 或 Tag=5 (指定物流單)。

### 回傳（POST 到 MapReturnURL，4 form 欄位）

外層同其他 API；解密後內層：

| 參數 | 類型 | 說明 |
|---|---|---|
| `Status` | String | `SUCCESS` 或錯誤碼 |
| `Message` | String |  |
| `MerID` | String |  |
| `MerKeyNo` | String | 同請求 |
| `GoodsType` / `LgsType` / `ShipType` |  | 同請求 |
| `MapJson` | String | **JSON 字串**，需 JSON.parse 取得門市資訊 |

`MapJson` 結構：

| 鍵 | 類型 | 說明 |
|---|---|---|
| `StoreType` | String | 限制 5 字（例：`SEVEN`）|
| `StoreID` | String | 6 碼（例：`916712`）|
| `StoreName` | String | ≤ 12 字（例：`敦安門市`）|
| `Address` | String | 例：`台北市大安區安和路一段27號` |
| `InsularArea` | String | I=本島；O=離島（**2025-11-25 新增**）|

### 後端產出 form 範例（NestJS Controller）

```ts
@Get('shipmap-form/:orderToken')
buildCvsMapForm(@Param('orderToken') token: string) {
  const params = {
    MerID: creds.merId,
    Timestamp: Math.floor(Date.now() / 1000),
    MerKeyNo: token, // 自家訂單 token
    GoodsType: 1, LgsType: 'B2C', ShipType: 1, MapType: 1, Tag: 2,
    MapReturnURL: 'https://shop.example.com/api/payuni/ship-map/return',
    MobileTag: 'N',
  };
  const encryptInfo = encryptPayuni(params, hashKey, hashIv);
  const hashInfo = hashInfoPayuni(hashKey, encryptInfo, hashIv);
  return {
    formUrl: `${PAYUNI_HOSTS[mode]}/api/logistics/ship_map`,
    fields: { MerID: creds.merId, Version: '1.1', EncryptInfo: encryptInfo, HashInfo: hashInfo },
  };
}
```

---

## <a id="print_label-1.0"></a>4.3 超商出貨單列印 — `/api/logistics/print_label` Ver 1.0（前景）

> 官方頁 `#/7/123`。Form POST，PAYUNi 會直接顯示出貨單 PDF（B2C）或跳轉 7-11 列印頁面（C2C）。

### 請求參數（EncryptInfo）

| 參數 | 必要 | 類型 | 說明 | 備註 |
|---|---|---|---|---|
| `MerID` | Y |  |  |  |
| `Timestamp` | Y |  |  |  |
| `ShipTradeNo` | Y | String | UNi 物流序號 | **最多 50 筆，半形逗號分隔** |
| `GoodsType` | Y | Int | 1=常溫 / 2=冷凍 |  |
| `LgsType` | Y | String | B2C / C2C |  |
| `ShipType` | Y | Int | 固定 1 |  |
| `ShipDate` | Y | String | 出貨日期 `YYYYMMDD` | **B2C 不得為當日** |
| `LabelMode` | C | Int | 列印格式 | 1=A4 / 2=直立式（**2025-12-16 起 C2C 也支援**）；預設 1 |

### Notify 回傳（**僅當建立物流單時有填 NotifyURL**，列印成功時送）

| 參數 | 類型 | 說明 |
|---|---|---|
| `Status` | String | SUCCESS |
| `Message` | Int |  |
| `MerID` |  |  |
| `ShipTradeNo` | String |  |
| `GoodsType` / `LgsType` / `ShipType` |  |  |
| `PartnerId` | String | 3 碼（B2C） |
| `Odno` | String | 8 碼出貨編號 |
| `ValidationNo` | String | 4 碼（**僅 C2C**）|
| `ApiType` | String | **固定 `Print`** |

> 編號組合：B2C 11 碼 = PartnerId(3) + Odno(8)；C2C 12 碼 = Odno(8) + ValidationNo(4)。

---

## <a id="refund-1.0"></a>4.4 退貨便要號（C2B）— `/api/logistics/refund` Ver 1.0

> 官方頁 `#/7/125`。**僅 B2C 大宗寄倉常溫商店可用**；給消費者 12 碼退貨便編號（RefundODNO 8 + ValidationNo 4）至 7-11 ibon 取退貨單。

### 請求參數（EncryptInfo）

| 參數 | 必要 | 類型 | 說明 | 備註 |
|---|---|---|---|---|
| `MerID` | Y |  |  |  |
| `Timestamp` | Y |  |  |  |
| `ShipTradeNo` | C | String | UNi 物流序號 | 與 TradeNo 至少有一個（其實兩者擇一）|
| `TradeNo` | C | Int | UNi 序號 |  |
| `GoodsType` | Y | Int | **固定 1**（常溫） |  |
| `LgsType` | Y | String | **固定 C2B** |  |
| `ShipType` | Y | Int | 固定 1 |  |
| `TradeAmt` | Y | Int | 商品金額 | 1~20,000 |
| `ServiceType` | Y | Int | 4=退貨付款 / 5=退貨不付款 |  |
| `ShipAmt` | Y | Int | 門市代收金額 | ServiceType=4 必填 1~999；ServiceType=5 為 0 |
| `ShopperName` | C | String | 退貨人姓名 | 有 ShipTradeNo 時自動代入原訂單收件人；否則必填；≤ 10（最長 5 中文 / 10 英文，混合取前 5） |
| `ProcessType` | Y | Int | **固定 1** |  |

### 回傳（解密後）

| 參數 | 類型 | 說明 |
|---|---|---|
| `Status` / `Message` / `MerID` |  |  |
| `LgsType` | String | C2B |
| `ShipType` | Int | 1 |
| `PartnerId` | String | 3 碼 |
| `RefundODNO` | String | **退貨便編號 8 碼** |
| `ValidationNo` | Int | **驗證碼 4 碼** |
| `TradeAmt` / `ShipAmt` | Int |  |
| `DeadlineDate` | String | 繳費期限 `YYYY-MM-DD HH:II:SS`（逾期則訂單取消）|
| `TradeNo` / `ShipTradeNo` | String |  |

ibon 退貨碼 = `RefundODNO + ValidationNo`（共 12 碼）。

---

## <a id="c2c_to_home-1.0"></a>4.5 C2C 待轉宅配（背景）— `/api/logistics/c2c_to_home_delivery` Ver 1.0

> 官方頁 `#/7/304`。買家未取 → 退貨門市 → 商家未取 → 物流中心保管 → 商家在期限內提供宅配地址 → 黑貓宅配退回（運費**貨到付款，由商家承擔**）。

### 請求參數（EncryptInfo）

| 參數 | 必要 | 類型 | 說明 |
|---|---|---|---|
| `MerID` | Y |  |  |
| `Timestamp` | Y |  |  |
| `ShipTradeNo` | Y | String | UNi 物流序號 |
| `Consignee` | Y | String | 收件人姓名 |
| `ConsigneeTel` | Y | String | 收件人連絡電話 |
| `ConsigneeAddress` | Y | String | 收件人地址 |

### 注意事項

1. 期限會在「待轉宅配退回通知信」內告知（貨態 82）；逾期不提供 → 包裹銷毀（貨態 46），衍生費用從 UNi 帳戶扣。
2. 若送出資料填寫有誤，**隔日 13:30 前**重打本 API 更新；超過則需 mail / 客服處理。
3. 完成後最終貨態為 56=已轉宅配退回。

### 回傳（解密後）

```
Status, Message, MerID, ShipTradeNo, Consignee, ConsigneeTel, ConsigneeAddress
```

---

## <a id="query-1.1"></a>6. 物流單查詢 — `/api/logistics/query` Ver 1.1

> 官方頁 `#/7/124`。供 B2C / C2C / HOME / C2B 共用（**注意 1.1 版才支援 HOME**）。

### 請求參數（EncryptInfo）

| 參數 | 必要 | 類型 | 說明 | 備註 |
|---|---|---|---|---|
| `MerID` | Y |  |  |  |
| `Timestamp` | Y |  |  |  |
| `LgsType` | Y | String | B2C/C2C/HOME/C2B |  |
| `ShipTradeNo` | C | String | UNi 物流序號 | 對 B2C/C2C/HOME 必填 |
| `TradeType` | C | Int | 宅配類別（**僅 HOME**） | 1=正物流（預設）/ 2=逆物流 |
| `ReturnOdno` | C | String | 退貨便編號 12 碼 | C2B 必填（RefundODNO 8 + ValidationNo 4）|

> `ShipTradeNo` 與 `ReturnOdno` 二擇一。

### 回傳（B2C/C2C/HOME 共通欄位）

| 參數 | 類型 | 說明 |
|---|---|---|
| `Status` / `Message` / `MerID` |  |  |
| `PartnerId` | String | 3 碼；黑貓固定 `CAT` |
| `MerTradeNo` / `TradeNo` / `ShipTradeNo` | String |  |
| `Odno` | String | 超商出貨單編號 8 碼 / 宅配託運單號 12 碼 |
| `GoodsType` | Int | 1/2/3 |
| `LgsType` | String | B2C/C2C/HOME |
| `ShipType` | Int | 1=7-ELEVEN / 2=黑貓 |
| `ServiceType` | Int | 1=取貨付款 / 3=取貨不付款 |
| `ShipAmt` | Int | 代收金額 |
| `Consignee` | String | 隱碼例 `周*宇` |
| `ConsigneeMobile` | String | 隱碼例 `09＊＊＊＊＊123` |
| `ShipStatus` | Int | 物流貨態狀態碼，見 `notify-and-status.md` |
| `PickupStoreType` | Int | 狀態 81 門市關轉時：1=取件門市 / 2=退件門市（**2025-03-12 新增**）|
| `ShipStatusDesc` | String |  |
| `ShipStatusTime` | DateTime | `YYYY-MM-DD HH:II:SS` |

### 回傳（B2C 額外）
- `StoreID`, `StoreName`

### 回傳（C2C 額外）
- `ValidationNo`（4 碼），`StoreID`, `StoreName`

### 回傳（HOME 額外）
- `FileNo`（重新下載 PDF 用，24h 有效）
- `TradeType`（1/2）
- `ConsigneeAddress`（明碼）

### 回傳（C2B / 帶 ReturnOdno 查詢）

| 參數 | 類型 | 說明 |
|---|---|---|
| `PartnerId` | String | 3 碼 |
| `RefundODNO` | String | 8 碼 |
| `ValidationNo` | String | 4 碼 |
| `LgsType` | String | C2B |
| `ShipType` | Int | 1 |
| `ServiceType` | Int | 4=退貨付款 / 5=退貨不付款 |
| `TradeAmt` | Int | 訂單價值金額 |
| `ShipAmt` | Int | 代收金額 |
| `DeadlineDate` | Date | 繳費期限 |
| `ShipStatus` / `ShipStatusDesc` / `ShipStatusTime` |  |  |

---

## <a id="update-1.1"></a>7. 物流單修改（背景）— `/api/logistics/update` Ver 1.1

> 官方頁 `#/7/129`。**僅未列印出貨單的物流單可改**；**不支援 C2B 退貨便、不支援黑貓退貨單**。

### 請求參數（EncryptInfo）

| 參數 | 必要 | 類型 | 說明 | 備註 |
|---|---|---|---|---|
| `MerID` | Y |  |  |  |
| `Timestamp` | Y |  |  |  |
| `LgsType` | Y | String | B2C/C2C/HOME |  |
| `ShipTradeNo` | C | String | UNi 物流序號 |  |
| `Consignee` | C | String | 收件人姓名 | 超商 ≤ 10（5 中文 / 10 英文，混合取前 5）；宅配 ≤ 30（中/全形 ×2、半形 ×1）|
| `ConsigneeMail` | C | String | 收件人電子信箱 | **僅超商**；Email 格式 |
| `ConsigneeMobile` | C | String | 收件人手機 | 09 開頭半形 |
| `ConsigneeAddress` | C | String | 收件人地址 | **僅 HOME**；最長 120；格式 縣市+鄉鎮市區+段弄巷街+號(+樓) |

### 回傳（共通）

```
Status, Message, MerID, LgsType, ShipTradeNo,
Consignee, ConsigneeMobile,           // 修改後
OriginalConsignee, OriginalConsigneeMobile  // 修改前
```

### 回傳（超商獨有）
- `ConsigneeMail` / `OriginalConsigneeMail`

### 回傳（HOME 獨有）
- `ConsigneeAddress` / `OriginalConsigneeAddress`

> 「如有提交修改才會有此參數」——沒改的欄位不出現。

---

## 7-ELEVEN 編號組合速查

| 場景 | 組合 | 範例 |
|---|---|---|
| B2C 出貨編號（11 碼） | `PartnerId(3) + Odno(8)` |  |
| C2C 出貨編號（12 碼） | `Odno(8) + ValidationNo(4)` |  |
| C2B 退貨編號（12 碼） | `RefundODNO(8) + ValidationNo(4)` |  |
| 數網查件 | https://tracking.shopmore.com.tw/ | 一次最多 6 筆 |

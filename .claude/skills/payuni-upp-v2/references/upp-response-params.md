# PAYUNi UPP V2 — 回傳參數完整參考

> 來源：[#/7/34 — 返回參數](https://docs.payuni.com.tw/web/#/7/34)（逐字保留）

回傳分兩處：**ReturnURL**（前景 Form POST，瀏覽器轉發）和 **NotifyURL**（後端對後端 Form POST）。**交易結果以 NotifyURL 為準**。

兩處的 body 結構相同：外層 4 欄位（Form 欄位）+ 內層 EncryptInfo 解密後的多欄位。

## TOC

- [外層欄位](#外層欄位)
- [內層通用欄位](#內層通用欄位)
- [PaymentType 一覽](#paymenttype-一覽)
- [信用卡 (PaymentType=1)](#信用卡-paymenttype1)
- [虛擬帳號 (PaymentType=2)](#虛擬帳號-paymenttype2)
- [超商代碼 (PaymentType=3)](#超商代碼-paymenttype3)
- [純取貨 / 超商貨到付款 (PaymentType=5 / ShipTag=1)](#純取貨--超商貨到付款-paymenttype5--shiptag1)
- [愛金卡 icash Pay (PaymentType=6)](#愛金卡-icash-pay-paymenttype6)
- [後支付 AFTEE (PaymentType=7)](#後支付-aftee-paymenttype7)
- [LINE Pay (PaymentType=9)](#line-pay-paymenttype9)
- [宅配到付 (PaymentType=10)](#宅配到付-paymenttype10)
- [街口支付 JKoPay (PaymentType=11)](#街口支付-jkopay-paymenttype11)
- [優惠券核銷回傳](#優惠券核銷回傳)

---

## 外層欄位

| 參數 | 說明 | 備註 |
|------|------|------|
| `Status` | 狀態代碼 | `SUCCESS` / `UNKNOWN`（等待授權結果逾期）/ `Unapproved`（訂單待確認，買家會員資格審查中）/ 失敗：見錯誤代碼 |
| `MerID` | 商店代號 | |
| `Version` | 版本 | 固定 `2.0` |
| `EncryptInfo` | 加密字串 | hex |
| `HashInfo` | 加密 Hash | SHA256 大寫 |

---

## 內層通用欄位

| 參數 | 說明 | 備註 |
|------|------|------|
| `Status` | 狀態代碼 | `SUCCESS` / `UNKNOWN` / `UNAPPROVED` / 錯誤碼 |
| `Message` | 狀態說明 | `授權成功`=信用卡授權成功；`(CVS)建立成功`=超商代碼取號成功；`(ATM)建立成功`=ATM 取號成功；`UNKNOWN`=系統忙碌中（60 秒無銀行回應），後續以 NotifyURL 通知，建議 15 分鐘後查詢 |
| `MerID` | 商店代號 | |
| `MerTradeNo` | 商店訂單編號 | 限制長度 25；格式 `[A-Za-z0-9_-]` |
| `TradeNo` | UNi 序號 | PAYUNi 內部交易 ID |
| `TradeAmt` | 訂單金額 | |
| `TradeStatus` | 訂單狀態 | `0`=取號成功；`1`=已付款；`2`=付款失敗；`3`=付款取消；`8`=訂單待確認 |
| `PaymentType` | 支付工具 | 見下表 |
| `Gateway` | 交易標記 | **固定 `2`**=整合式支付頁 (UPP) |
| `BuyerHash` | 買方會員 Token Hash | 需在初次交易帶 `BuyerToken`，並由買方登入或註冊買方會員、完成交易後才會取得 |

---

## PaymentType 一覽

| 值 | 工具 |
|----|------|
| 1 | 信用卡（含分期/紅利/銀聯/Apple Pay/Google Pay/Samsung Pay） |
| 2 | ATM 轉帳 |
| 3 | 代碼（超商代碼） |
| 5 | 貨到付款（超商取貨付款） |
| 6 | 愛金卡 (icash) |
| 7 | 後支付 (Aftee) |
| 9 | LINE Pay |
| 10 | 宅配到付 |
| 11 | JKoPay 街口支付 |

---

## 信用卡 (PaymentType=1)

| 參數 | 說明 | 備註 |
|------|------|------|
| `Card6No` | 卡號前六碼 | |
| `Card4No` | 卡號後四碼 | |
| `CardInst` | 分期數 | |
| `FirstAmt` | 首期金額 | |
| `EachAmt` | 每期金額 | |
| `ResCode` | 回應碼 | |
| `ResCodeMsg` | 回應碼敘述 | |
| `AuthCode` | 授權碼 | |
| `AuthBank` | 授權銀行（代碼）| |
| `AuthBankName` | 授權銀行（名稱）| |
| `AuthType` | 授權類型 | `1`=一次；`2`=分期；`4`=Apple Pay；`5`=Google Pay；`6`=Samsung Pay；`7`=銀聯 |
| `AuthDay` | 授權日期 | 格式 `YYYYMMDD` |
| `AuthTime` | 授權時間 | 格式 `HHIISS` |
| `CreditHash` | 信用卡 Token Hash | Token 專用返回參數；有 `CreditToken` 且授權成功才會壓碼 |
| `CreditLife` | 信用卡 Token 有效日期 | 格式 `MMYY` |
| `CardBank` | 發卡銀行（代碼）| 國內為銀行代碼（3 碼）；非國內為 `-` |
| `CoBrandCode` | 聯名卡代號 | 聯名卡交易識別代號（需事先設定） |

---

## 虛擬帳號 (PaymentType=2)

| 參數 | 說明 | 備註 |
|------|------|------|
| `BankType` | 銀行（代碼）| 參考 [#/7/50 銀行代碼](https://docs.payuni.com.tw/web/#/7/50) |
| `PayNo` | 繳費虛擬帳號 | |
| `PaySet` | 繳費設定 | `1`=一次性 |
| `ExpireDate` | 繳費截止時間 | 格式 `YYYY-MM-DD HH:II:SS` |

---

## 超商代碼 (PaymentType=3)

| 參數 | 說明 | 備註 |
|------|------|------|
| `Store` | 超商（代碼）| `7-ELEVEN` |
| `PayNo` | 繳費代碼 | |
| `ExpireDate` | 繳費截止時間 | 格式 `YYYY-MM-DD HH:II:SS` |

---

## 純取貨 / 超商貨到付款 (PaymentType=5 / ShipTag=1)

| 參數 | 說明 | 備註 |
|------|------|------|
| `PartnerId` | 母代碼 | LgsType=B2C；長度 3 |
| `ShipTradeNo` | UNi 物流序號 | |
| `GoodsType` | 寄件型態 | `1`=常溫；`2`=冷凍 |
| `LgsType` | 物流型態 | `B2C`=大宗寄倉；`C2C`=店到店 |
| `ShipType` | 通路類別 | `1`=7-ELEVEN |
| `ServiceType` | 取貨方式 | `1`=取貨付款；`3`=取貨不付款 |
| `ShipAmt` | 取貨付款金額 | |
| `StoreID` | 取件門市代碼 | |
| `StoreName` | 取件門市名稱 | |
| `StoreAddr` | 取件門市地址 | |
| `Consignee` | 收件人名稱 | 限制長度 10；最長 5 中文字、最短 2 中文字或 4 英文字 |
| `ConsigneeMobile` | 收件人手機 | `09` 開頭半形數字 |
| `ConsigneeMail` | 收件人電子信箱 | |

---

## 愛金卡 icash Pay (PaymentType=6)

| 參數 | 說明 | 備註 |
|------|------|------|
| `PayNo` | 愛金卡交易序號 | |
| `PayTime` | 付款日期時間 | 格式 `YYYY-MM-DD HH:II:SS` |

---

## 後支付 AFTEE (PaymentType=7)

| 參數 | 說明 | 備註 |
|------|------|------|
| `PayNo` | Aftee 交易序號 | |
| `CreateDT` | 交易建立日期時間 | |

---

## LINE Pay (PaymentType=9)

| 參數 | 說明 | 備註 |
|------|------|------|
| `PayNo` | LINE Pay 交易號碼 | |
| `PayTime` | 付款日期時間 | 格式 `YYYY-MM-DD HH:II:SS` |

---

## 宅配到付 (PaymentType=10)

| 參數 | 說明 | 備註 |
|------|------|------|
| `TradeType` | 宅配類別 | 固定 `1`=正物流 |
| `ShipTradeNo` | 物流單號 | |
| `GoodsType` | 寄件型態 | `1`=常溫；`2`=冷凍；`3`=冷藏 |
| `LgsType` | 物流型態 | `HOME`=黑貓宅配 |
| `ShipType` | 通路類別 | `2`=黑貓宅配 |
| `ServiceType` | 取貨方式 | `1`=取貨付款；`3`=取貨不付款 |
| `ShipAmt` | 取貨付款金額 | |
| `Consignee` | 收件人名稱 | |
| `ConsigneeMobile` | 收件人手機 | |
| `ConsigneeTel` | 收件人聯絡電話 | 區碼+號碼，例 `00-00000000`；交易當下沒帶則回 `-` |
| `ConsigneeAddress` | 收件人地址 | |
| `DeliveryTimeTag` | 希望配達時段 | `01`=13 時前；`02`=14–18 時；`04`=不指定 |
| `ProductTypeId` | 商品類別代碼 | `0001`=一般食品；`0002`=名特產/甜產；`0003`=酒/油/醋/醬；`0004`=穀物蔬果；`0005`=水產/肉品；`0006`=3C；`0007`=家電；`0008`=服飾配件；`0009`=生活用品；`0010`=美容彩妝；`0011`=保健食品；`0012`=醫療相關用品；`0013`=寵物用品飼料；`0014`=印刷品；`0015`=其他 |
| `ProdDesc` | 商品名稱 | |

---

## 街口支付 JKoPay (PaymentType=11)

| 參數 | 說明 | 備註 |
|------|------|------|
| `JKoTradeNo` | JKoPay 交易號碼 | |
| `JKoStrCupAmt` | 店家街口券折抵 | |
| `JKoChannel` | 支付工具 | `account`=儲值帳戶；`bank`=銀行帳戶；`creditcard`=信用卡 |
| `PayTime` | 付款日期時間 | 格式 `YYYY-MM-DD HH:II:SS` |

---

## 優惠券核銷回傳

> 當交易有使用優惠券核銷，會額外回傳：

| 參數 | 說明 |
|------|------|
| `PromoCode` | 優惠碼 |
| `DiscountAmt` | 折扣金額 |
| `OrderAmt` | 原訂單金額 |
| `CouponFee` | 核銷費 |
| `CampaignName` | 活動名稱 |
| `CampaignNo` | 活動序號 |
| `CouponName` | 優惠券名稱 |
| `CouponNo` | 優惠券序號 |

---

## 信用卡交易狀態說明（請款 / 退款）

> **交易查詢 API**（`/api/trade/query` Ver 2.0）回傳會額外包含信用卡請款/退款狀態。詳見 `references/api-reference.md`。

| 欄位 | 說明 |
|------|------|
| `CloseStatus` | 請款狀態：`1`=請款申請中；`2`=請款成功；`3`=請款取消；`7`=請款處理中；`9`=未申請 |
| `CloseAmt` | 請款金額 |
| `RefundType` | 退款類型：`2`=退款；`3`=預計退款 |
| `RefundStatus` | 退款狀態：`1`=退款申請中；`2`=退款成功；`3`=退款取消；`8`=退款處理中 |
| `RefundAmt` | 退款金額 |
| `RefundDay` | 退款日期 |
| `RemainAmt` | 剩餘可退款金額 |
| `DataSource` | 查詢結果狀態：`A`=完整資料；`B`=處理中未完整（建議 10 分鐘後再查） |

# PAYUNi UPP V2 — 請求參數完整參考

> 來源：[#/7/34 — 請求參數](https://docs.payuni.com.tw/web/#/7/34)（逐字保留）

## TOC

- [外層 Form POST](#外層-form-post)
- [EncryptInfo 內層 — 通用請求參數](#encryptinfo-內層--通用請求參數)
- [付款方式啟用參數](#付款方式啟用參數)
- [信用卡 Token 請求參數](#信用卡-token-請求參數)
- [買方 Token 請求參數](#買方-token-請求參數)
- [優惠券服務請求參數](#優惠券服務請求參數)
- [物流服務請求參數](#物流服務請求參數)
- [物流參數使用範例](#物流參數使用範例)

---

## 外層 Form POST

| 參數 | 必要 | 類型 | 說明 | 備註 |
|------|------|------|------|------|
| `MerID` | Y | string | 商店代號 | 需與 EncryptInfo 中 MerID 一致 |
| `Version` | Y | string | 版本 | **固定 `2.0`** |
| `EncryptInfo` | Y | string | AES 加密字串 | 見 `encryption.md` |
| `HashInfo` | Y | string | SHA256 加密字串（大寫）| 見 `encryption.md` |

> 平台/代理商模式下可額外帶外層 `IsPlatForm=1`（**不放入 EncryptInfo**）。

---

## EncryptInfo 內層 — 通用請求參數

| 參數 | 必要 | 類型 | 說明 | 備註 |
|------|------|------|------|------|
| `MerID` | Y | string | 商店代號 | |
| `MerTradeNo` | Y | string | 商店訂單編號 | 限制長度 25；格式 `[A-Za-z0-9_-]`；10 分鐘內不可重複 |
| `TradeAmt` | Y | int | 訂單金額 | 見 [#/7/170 訂單金額限制](https://docs.payuni.com.tw/web/#/7/170) |
| `Timestamp` | Y | int | 時間戳記 | 格式 `time()` |
| `ReturnURL` | C | string | 前景通知網址 | 付款完成 Form POST 返回；空值則停留 PAYUNi 結果頁；交易結果以 NotifyURL 為主；格式：完整 URL |
| `DeepLinkURL` | C | string | 深層連結 | 可打開特定 APP/網頁；測試環境會自動忽略；有值時不觸發 ReturnURL；**僅 icash Pay / LINE Pay / 街口支付 / AFTEE 生效** |
| `NotifyURL` | C | string | 背景通知網址 | 將交易資料通知指定網址；**僅限 80 / 443 port** |
| `BackURL` | C | string | 返回商店按鈕網址 | PAYUNi 結果頁的「返回商店」按鈕點擊網址 |
| `UsrMail` | C | string | 消費者信箱 | 預先帶入付款人信箱；若空白由消費者輸入 |
| `UsrMailFix` | C | int | 信箱固定 | `1`=不可修改 |
| `Cardholder` | C | int | 啟用持卡人英文姓名輸入 | 啟用 3D 交易時供發卡行驗證；預設不啟用；`1`=啟用 |
| `ProdDesc` | Y | string | 商品說明 | 長度限制 550，超出截斷；多項以半形 `;` 分隔 |
| `ExpireDate` | C | string | 繳費有效日期 | 格式 `YYYY-MM-DD`；CVS 最大 +7 天，ATM 最大 +180 天；當日須 ≥2 小時繳費時間，否則設為隔日；超過 +7 天則支付頁不顯示 CVS；預設 +7 天 |
| `AtmBankType` | C | string | 指定 ATM 銀行 | 逗號分隔銀行代碼，例：`822,004,013`；參考 [#/7/50 銀行代碼](https://docs.payuni.com.tw/web/#/7/50)；未帶則顯示所有支援銀行 |
| `TradeLExpireSec` | C | int | 付款頁面截止秒數 | 預設 600；範圍 60–600 |
| `API3D` | C | int | 強制 3D | 商店設定關閉 3D 時，可帶 `1` 表示此筆強制 3D；不影響銀聯 |
| `Union3D` | C | int | 銀聯指定 Unionpay | 銀聯卡設為 Expresspay 時，帶 `1` 表示此筆指定 Unionpay |
| `TradeInvoice` | C | int | 電子發票 | `1`=啟用 |
| `Lang` | C | string | 語系 | `en` / `zh-tw`；預設繁中 |

---

## 付款方式啟用參數

> 未帶任何指定支付工具參數，則依後台商店預設值。

| 參數 | 類型 | 說明 |
|------|------|------|
| `Credit` | int | 信用卡一次付清 (`1`=啟用) |
| `CreditInst` | string | 分期，例：`"3,6,9,12"`（支援 3/6/9/12/18/24/30 期） |
| `CreditUnionPay` | int | 信用卡（銀聯）支付 |
| `ATM` | int | 虛擬帳號支付 |
| `CVS` | int | 超商代碼/條碼支付 |
| `ICash` | int | icash Pay 支付 |
| `Aftee` | int | AFTEE 先享後付 |
| `LinePay` | int | LINE Pay |
| `JKoPay` | int | 街口支付 |
| `ApplePay` | int | Apple Pay |
| `GooglePay` | int | Google Pay™ |
| `SamsungPay` | int | Samsung Pay |
| `Ship` | int | 取貨付款（搭配物流參數） |

> 信用卡延伸（紅利、約定/記憶卡號、首次 Token）見下方 Token 段落。

---

## 信用卡 Token 請求參數

> 若需使用「首次信用卡 Token 交易」，於請求參數中追加：

| 參數 | 必要 | 類型 | 說明 | 備註 |
|------|------|------|------|------|
| `CreditToken` | C | string | 信用卡 Token | 付款人綁定資料：會員編號、Email、手機等；如使用 `UseTokenType` 此為必填；長度限制 150；格式 `[A-Z a-z 0-9 @.#$%_-]` |
| `UseTokenType` | C | int | Token 類型 | 如使用此參數 `CreditToken` 為必填。`1`=約定信用卡（消費者可取消約定）；`2`=記憶卡號；`3`=強制約定（消費者無法取消） |
| `CreditShowType` | C | int | 記憶卡號顯示類型 | 預設 `2`。`1`=卡號；`2`=卡號+到期日 |
| `CreditTokenType` | C | int | Token 紀錄類型 | `1`=會員（預設，會員旗下所有商店共用）；`2`=商店（僅限首次交易商店） |
| `CreditTokenExpired` | C | string | Token 有效期間 | 格式 `MMYY`；未帶則以信用卡到期日為主 |

---

## 買方 Token 請求參數

> 「首次買方 Token 交易」用於買方會員身分綁定（前景頁面登入/註冊後完成）。

| 參數 | 必要 | 類型 | 說明 | 備註 |
|------|------|------|------|------|
| `BuyerToken` | C | string | 綁定買方 Token | 買方會員綁定資料：會員編號/Email/手機等；長度限制 150；格式 `[A-Z a-z 0-9 @.#$%_-]` |
| `BuyerTokenType` | C | int | 買方綁定類型 | `1`=會員（預設）；`2`=商店 |

> 「買方 Token 交易」用於後續交易：

| 參數 | 必要 | 類型 | 說明 | 備註 |
|------|------|------|------|------|
| `BuyerHash` | C | string | 買方會員 Token Hash | 首次交易帶 `BuyerToken` 並由 User 透過 UPP 支付頁登入完成交易後即會回傳 `BuyerHash`，後續交易帶入即完成買方驗證綁定 |

---

## 優惠券服務請求參數

| 參數 | 必要 | 類型 | 說明 | 備註 |
|------|------|------|------|------|
| `Coupon` | C | int | 啟用優惠券 | `1`=啟用；`2`=停用 |
| `CouponNotifyURL` | C | string | 優惠券發券背景通知網址 | 將優惠券通知指定網址 |

---

## 物流服務請求參數

> 啟用物流服務（預設含取貨不付款及取貨付款）：

| 參數 | 必要 | 類型 | 說明 | 備註 |
|------|------|------|------|------|
| `ShipTag` | C | int | 啟用物流 | `1`=啟用物流（預設含取貨不付款及取貨付款） |
| `LgsType` | C | string | 物流通路 | `B2C`=大宗寄倉；`C2C`=店到店；`HOME`=黑貓宅配 |
| `ShipType` | C | int | 通路類別 | `1`=7-ELEVEN；`2`=黑貓宅配；B2C/C2C 帶 1，HOME 帶 2 |
| `GoodsType` | C | int | 寄件型態 | `1`=常溫；`2`=冷凍；`3`=冷藏（僅黑貓） |
| `Consignee` | C | string | 取件人姓名 | 長度 ≤10；最長 5 中文字、最短 2 中文字或 4 英文字（真實姓名供超商核對） |
| `ConsigneeMobile` | C | string | 取件人手機 | `09` 開頭半形數字 |
| `ConsigneeAddress` | C | string | 取件人地址 | 黑貓宅配必填；最長 120；格式：縣市+鄉鎮市區+段弄巷街+號(+樓) |
| `ConsigneeAddressFix` | C | int | 收件人地址固定 | `1`=不可修改 |

---

## 物流參數使用範例

> 來源：[#/7/34 — 物流服務請求參數備註](https://docs.payuni.com.tw/web/#/7/34)

| ShipTag=1 | Ship=1 | 指定支付工具參數 | 支付頁顯示結果 |
|:---:|:---:|:---:|---|
| ✓ | | | 取貨付款 + 商店預設支付工具（搭配物流取貨不付款） |
| ✓ | | ✓ | 指定支付工具（搭配物流取貨不付款） |
| ✓ | ✓ | ✓ | 取貨付款 + 指定支付工具（搭配物流取貨不付款） |
| | ✓ | | 取貨付款 |
| | ✓ | ✓ | 取貨付款 + 指定支付工具（無物流） |

備註：
1. `ShipTag=1` 表示啟用物流（預設含取貨不付款及取貨付款）；若有帶支付工具參數，則依參數設定，有帶 `Ship=1` 才有取貨付款。
2. `Ship=1` 且未帶 `ShipTag=1` 時，僅有取貨付款，無取貨不付款。
3. 啟用物流時（`Ship=1` 或 `ShipTag=1`），需傳遞 `LgsType` / `ShipType` / `GoodsType` / `Consignee` / `ConsigneeMobile`。
4. 沒帶 `Ship=1` 或 `ShipTag=1` 時，視為一般交易，無物流服務、不產生物流單。

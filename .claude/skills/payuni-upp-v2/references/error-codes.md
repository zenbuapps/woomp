# PAYUNi 錯誤代碼總表

> 來源：
> - 通用錯誤代碼：[#/7/156](https://docs.payuni.com.tw/web/#/7/156)
> - UPP 錯誤代碼：[#/7/44](https://docs.payuni.com.tw/web/#/7/44)
> - 各 API 專屬錯誤代碼分頁見下表
>
> PAYUNi 錯誤碼採前綴分類：每個 API 有獨立前綴 + 子範圍。
> 前綴的命名規則：
> - `API/DEF` = 通用層
> - `UPP/CREDIT/ATM/CVS/QUERY/CLOSE/CANCEL/BIND_QUERY/BIND_CANCEL/ICASH/AFTEE/LINEPAY/JKO/CANCEL_CVS` = 各 API 層
> - 子範圍：`xx01xxx`=基礎參數錯；`xx02xxx`=解密參數錯；`xx03xxx`=處理錯；`xx04xxx`=異常；`xx05xxx`=風控；`xx06xxx`=其他

## TOC

- [處理流程（驗章 → 解密 → 檢查 Status）](#處理流程驗章--解密--檢查-status)
- [外層 Status 值](#外層-status-值)
- [內層 Status 值](#內層-status-值)
- [所有 API 通用錯誤碼](#所有-api-通用錯誤碼)
- [UPP 錯誤代碼](#upp-錯誤代碼)
- [各 API 專屬錯誤代碼分頁](#各-api-專屬錯誤代碼分頁)
- [信用卡 ResCode 對照](#信用卡-rescode-對照)

---

## 處理流程（驗章 → 解密 → 檢查 Status）

```
1. 取得 PAYUNi 回傳 Form POST / JSON body
2. 檢查外層 Status：
   - "ERROR" → 直接拒絕（無 EncryptInfo）
   - "SUCCESS" / "UNKNOWN" / "Unapproved" / 錯誤碼 → 繼續
3. 驗章：
   const expected = SHA256(hashKey + EncryptInfo + hashIv).toUpperCase()
   if (expected !== HashInfo) → 拒絕（資料被竄改 / 用錯金鑰）
4. 解密 EncryptInfo
5. 檢查內層 Status：
   - "SUCCESS" → 處理交易結果
   - "UNKNOWN" → 等待後續 NotifyURL 或 15 分鐘後查詢
   - "UNAPPROVED" → 訂單待確認（買家會員資格審查中）
   - 其他 → 對照下方錯誤碼表
```

---

## 外層 Status 值

| Status | 說明 |
|--------|------|
| `SUCCESS` | 處理成功（內層仍需檢查）|
| `UNKNOWN` | 等待授權結果逾期（60 秒無銀行回應）|
| `Unapproved` | 訂單待確認（買家會員資格審查中，AFTEE 等支付）|
| `ERROR` | 系統錯誤，**無 EncryptInfo** |
| 錯誤碼字串 | 見下方分類表 |

---

## 內層 Status 值

| Status | 說明 |
|--------|------|
| `SUCCESS` | 成功 |
| `UNKNOWN` | 系統忙碌中，尚未確認交易結果。後續以 NotifyURL 通知，或建議 15 分鐘後發動交易查詢 |
| `UNAPPROVED` | 訂單待確認（買家會員資格審查中）|
| 錯誤碼 | 對照下方各 API 錯誤代碼 |

---

## 所有 API 通用錯誤碼

> 來源：[#/7/156](https://docs.payuni.com.tw/web/#/7/156)

### `API*` 系統層

| Status | 說明 |
|--------|------|
| `API00001` | 無 API 類型 |
| `API00002` | 無 API 版本號 |
| `API00003` | 無 API 對應程式 |
| `API00004` | 無 API 加密資料 |
| `API00005` | 無 API 加密比對資料 |
| `API00007` | Token 已失效 |
| `API00008` | Gateway 錯誤 |
| `API00009` | 已有相同資料處理中 |
| `API00010` | EncryptInfo 格式錯誤 |
| `API00011` | HashInfo 格式錯誤 |
| `API01001` | 執行幕後 3D，未有訂單編號 |
| `API01002` | 執行幕後 3D，未有暫存資訊 |
| `API01003` | 執行幕後 3D，已超過允許時間 |
| `API01004` | 執行幕後 3D，解析資料失敗 |
| `API02001` | SamsungPay 處理異常（RefID）|
| `API02002` | SamsungPay 處理異常（SendDT）|

### `DEF*` 預處理層（解密前）

| Status | 說明 |
|--------|------|
| `DEF01001` | 未有商店代號 |
| `DEF01002` | 資料解密失敗 |
| `DEF01003` | 代理商不存在 |
| `DEF01004` | 代理商狀態不符合 |
| `DEF01005` | 商店不存在 |
| `DEF01006` | 商店狀態不符合 |
| `DEF01007` | Hash 比對不符合 |

---

## UPP 錯誤代碼

> 來源：[#/7/44](https://docs.payuni.com.tw/web/#/7/44)

### `UPP01xxx` 基礎參數（解密前 / 商店設定）

| Status | 說明 |
|--------|------|
| `UPP00000` | 系統異常 |
| `UPP01001` | 已達連線處理上限，請稍後再試 |
| `UPP01002` | 未有商店代號 |
| `UPP01003` | 資料 HASH 比對不符合 |
| `UPP01004` | 資料解密失敗 |
| `UPP01005` | 解密資料不存在 |
| `UPP01006` | 查無符合商店資料 / 查無符合商店（代理商）資料 |
| `UPP01007` | 已存在相同商店訂單編號（10 分鐘內重複） |
| `UPP01008` | 查無符合商店物流資料 |
| `UPP01009` | 商店 B2C 物流服務尚未開通 |
| `UPP01010` | 商店 C2C 物流服務尚未開通 |
| `UPP01011` | 商店未啟用 B2C 物流服務 |
| `UPP01012` | 商店未啟用 C2C 物流服務 |
| `UPP01013` | 商店未啟用 |
| `UPP01014` | 平台商未啟用 |
| `UPP01015` | 收(寄)件人姓名僅支援輸入中文/英文/數字 |
| `UPP01016` | 收(寄)件人的姓名包含 emoji 符號 |

### `UPP02xxx` 解密參數錯誤（最常見）

| Status | 說明 |
|--------|------|
| `UPP02001` | 未有商店代號 |
| `UPP02002` | 未有商店訂單編號 |
| `UPP02003` | 商店訂單編號超過長度限制（>25）|
| `UPP02004` | 商店訂單編號格式錯誤（必須 `[A-Za-z0-9_-]`）|
| `UPP02005` | 未有訂單金額 |
| `UPP02006` | 訂單金額僅可輸入整數 |
| `UPP02007` | 訂單金額格式錯誤 |
| `UPP02008` | 未有時間戳記 |
| `UPP02009` | 時間戳記僅可輸入整數 |
| `UPP02010` | 時間戳記已過期 |
| `UPP02011` | 前景導回網址（ReturnURL）格式錯誤 |
| `UPP02012` | 背景通知網址（NotifyURL）格式錯誤 |
| `UPP02013` | 寄送信箱格式錯誤 |
| `UPP02022` | 無合適的支付工具可供使用 |
| `UPP02029` | 信用卡（一次付清）未啟用 |
| `UPP02030` | ATM 轉帳未啟用 |
| `UPP02031` | 超商代碼未啟用 |
| `UPP02032` | 信用卡（銀聯卡）未啟用 |
| `UPP02034`-`UPP02040` | 信用卡分期 3/6/9/12/18/24/30 期未啟用 |
| `UPP02042` | 繳費期限日期格式錯誤 |
| `UPP02043` | 繳費期限為無效日期 |
| `UPP02044` | 返回網址（BackURL）格式錯誤 |
| `UPP02045` | 交易截止秒數僅可整數 |
| `UPP02046` | 交易截止秒數超出 60–600 範圍 |
| `UPP02048` | 交易時間已截止 |
| `UPP02049`-`UPP02050` | icash / AFTEE 未啟用 |
| `UPP02063` | Apple Pay 未啟用 |
| `UPP02073` | LINE Pay 未啟用 |
| `UPP02075`-`UPP02076` | Google Pay 開關格式錯誤 / 未啟用 |
| `UPP02077` | 無法辨識的銀行別 |
| `UPP02078` | Samsung Pay 未啟用 |
| `UPP02080`-`UPP02082` | 買方 Token 長度/格式/類型錯誤 |
| `UPP02084`-`UPP02085` | 電子發票格式錯誤 / 未啟用 |
| `UPP02086`-`UPP02087` | 信用卡幕後/約定信用卡幕後未提供 |
| `UPP02088`-`UPP02100` | 此交易金額無法使用（信用卡/分期/ApplePay/GooglePay/SamsungPay）|
| `UPP02101`-`UPP02107` | 此交易金額無法使用（ATM/CVS/各物流方式）|
| `UPP02108` | 此交易金額無法使用（AFTEE）|
| `UPP02109`-`UPP02110` | 街口支付未啟用 / 開關格式錯誤 |
| `UPP02111` | 商店代號比對異常 |
| `UPP02112`-`UPP02115` | 優惠券格式錯誤 / 代理商未開啟 / 商店未啟用 / 通知網址錯誤 |

### `UPP03xxx` 處理層異常

| Status | 說明 |
|--------|------|
| `UPP03000` | 交易已逾期 |
| `UPP03001` | 交易 Token 已失效 |
| `UPP03002` | 商店資料不存在 |
| `UPP03003` | 解密資料不存在 |
| `UPP03004` | 查無對應 Gateway |
| `UPP03005` | Gateway 結果空白 |
| `UPP03006` | 送出資料解析失敗 |
| `UPP03007` | 未有任何允許交易方式 |
| `UPP03008` | 訂單不允許此支付工具 |
| `UPP03009`-`UPP03019` | 信用卡卡號/分期數/到期日/CVC 各種格式錯誤 |
| `UPP03020` | 虛擬帳號（ATM）銀行不符合 |
| `UPP03021` | 訂單金額不符合 |
| `UPP03022` | 未有 AppleDetail / GoogleDetail / SamsungDetail 確認資料 |
| `UPP03023`-`UPP03034` | 物流相關（地圖/收件人/手機/地址/配達時段）|
| `UPP03035`-`UPP03046` | 登入資訊 / 載具 / 優惠碼相關 |

### `UPP04xxx` 等待逾時

| Status | 說明 |
|--------|------|
| `UPP04001` | 等待 Token 已失效 |

### `UPP05xxx` 風控

| Status | 說明 |
|--------|------|
| `UPP05001` ~ `UPP05010` | 交易風控（拒絕交易），不額外揭露原因 |

### `UPP06xxx` 其他

| Status | 說明 |
|--------|------|
| `UPP06001` | 顯示 QRCode 未有 TradeNo |
| `UPP06002` | 顯示 QRCode 未有 QRCode 值 |

> 各支付工具的最終授權失敗錯誤碼，回應在內層 `ResCode` / `Message`，請參考下方各 API 子章節。

---

## 各 API 專屬錯誤代碼分頁

| API | 錯誤代碼來源頁 | 前綴 |
|-----|---------------|------|
| 通用 | [#/7/156](https://docs.payuni.com.tw/web/#/7/156) | `API*` `DEF*` |
| 整合支付頁（UPP）| [#/7/44](https://docs.payuni.com.tw/web/#/7/44) | `UPP*` |
| 信用卡幕後（CREDIT）| [#/7/45](https://docs.payuni.com.tw/web/#/7/45) | `CREDIT*` |
| 虛擬帳號幕後（ATM）| [#/7/46](https://docs.payuni.com.tw/web/#/7/46) | `ATM*` |
| 超商代碼幕後（CVS）| [#/7/47](https://docs.payuni.com.tw/web/#/7/47) | `CVS*` |
| 交易查詢 | [#/7/30](https://docs.payuni.com.tw/web/#/7/30) | `QUERY*` |
| 交易請退款 | [#/7/43](https://docs.payuni.com.tw/web/#/7/43) | `CLOSE*` |
| 交易取消授權 | [#/7/42](https://docs.payuni.com.tw/web/#/7/42) | `CANCEL*` |
| 信用卡綁定查詢 | [#/7/48](https://docs.payuni.com.tw/web/#/7/48) | `BIND_QUERY*` |
| 信用卡綁定取消 | [#/7/49](https://docs.payuni.com.tw/web/#/7/49) | `BIND_CANCEL*` |
| icash 交易 / 退款 | [#/7/70](https://docs.payuni.com.tw/web/#/7/70) / [#/7/71](https://docs.payuni.com.tw/web/#/7/71) | `ICASH*` |
| AFTEE 交易 / 確認 / 退款 | [#/7/81](https://docs.payuni.com.tw/web/#/7/81) / [#/7/82](https://docs.payuni.com.tw/web/#/7/82) / [#/7/83](https://docs.payuni.com.tw/web/#/7/83) | `AFTEE*` |
| LINE Pay 交易 / 退款 | [#/7/325](https://docs.payuni.com.tw/web/#/7/325) / [#/7/347](https://docs.payuni.com.tw/web/#/7/347) | `LINEPAY*` |
| JKoPay 交易 / 退款 | [#/7/387](https://docs.payuni.com.tw/web/#/7/387) / [#/7/378](https://docs.payuni.com.tw/web/#/7/378) | `JKO*` |
| 非信用卡退款轉匯 | [#/7/78](https://docs.payuni.com.tw/web/#/7/78) | |
| 物流相關 | [#/7/119](https://docs.payuni.com.tw/web/#/7/119) | |
| 撥款提領查詢 | [#/7/220](https://docs.payuni.com.tw/web/#/7/220) | |
| 取消超商代碼 | [#/7/334](https://docs.payuni.com.tw/web/#/7/334) | `CANCEL_CVS*` |

> 完整錯誤碼數量過多（每個 API 都有 `01xxx`/`02xxx`/`03xxx`/`04xxx` 子分類），有需要時請至對應頁面查找具體碼。
> **常見模式**：
> - `xx01xxx`：基礎參數錯（解密前）
> - `xx02xxx`：解密參數錯（必填欄位 / 格式 / 長度）
> - `xx03xxx`：處理層異常
> - `xx04xxx`：API 異常 / 回傳加密失敗

---

## 信用卡 ResCode 對照

> 信用卡授權失敗時，內層回傳 `ResCode` + `ResCodeMsg`。完整對照需向 PAYUNi 取最新名單，常見：

| ResCode | 說明 |
|---------|------|
| `00` | 授權成功 |
| `01`-`05` | 銀行授權失敗（聯絡發卡行） |
| `12`/`13`/`14` | 無效交易 / 卡號錯誤 / 拒絕往來 |
| `41` | 失卡 |
| `43` | 報遺/盜卡 |
| `51` | 餘額/額度不足 |
| `54` | 過期卡片 |
| `55` | 密碼錯誤 |
| `57`/`58` | 不允許此交易 / 卡片被銀行拒絕 |
| `59`/`62` | 疑似詐欺 / 不允許此卡 |
| `61` | 超過提款限額 |
| `91` | 發卡行無回應 |

> 部分授權銀行另回 4 位數內部碼。詳細對照請看 [#/7/153 信用卡交易狀態說明](https://docs.payuni.com.tw/web/#/7/153)。

---

## 處理建議

1. **`UPP01007`（訂單編號重複）**：實作前要保證 MerTradeNo 全域唯一（用訂單 PK + 時間戳避免衝突）。
2. **`UPP01003` / `DEF01007`（HASH 不符）**：99% 是 HashKey/HashIV 環境變數錯（sandbox vs prod 混淆），檢查 `.env`。
3. **`UPP02022`（無支付工具）**：未啟用任何 `Credit`/`ATM`/`CVS`/... 開關，且後台預設也沒任何啟用。要啟用至少一個。
4. **`UPP02088`-`UPP02108`（金額無法使用）**：金額超出該支付工具的限額（見 [#/7/170 訂單金額限制](https://docs.payuni.com.tw/web/#/7/170)）。
5. **`UPP05xxx`（風控拒絕）**：PAYUNi 不揭露具體原因，需聯繫客服或調整商店風控設定。
6. **`API00009`（已有相同資料處理中）**：通常是 race condition，重試機制要加 idempotency key。

# PAYUNi UNi Embed V3 — 完整錯誤代碼表

> 來源：https://docs.payuni.com.tw/web/#/29/385

錯誤代碼分為三大類：
1. **SDK 前端錯誤**（Code 1xxx / OBJ01xxx） — JavaScript SDK 拋出
2. **token_get API 錯誤**（TOKEN0xxxx）
3. **merchant_trade API 錯誤**（IFTRADE0xxxx / TRADE0xxxx）

---

## 1. SDK 前端錯誤

### 1.1 SDK 操作錯誤（Error: Code XXXX）

| 代碼 | 訊息 |
|------|------|
| `1000` | 尚未設定連動相關 div ID（請參考基本教學 Step 2 initOption 的 elements） |
| `1001` | iframe 連線失敗，無法使用相關 function |
| `1002` | 無法使用分期，PAYUNi 平台上沒有開啟分期選項 |
| `1003` | 填寫分期數有誤，請重新確認 |
| `1004` | function 帶入參數有誤，請重新確認 |
| `1005` | 填寫欄位有誤，請重新確認（請配合 onUpdate 確認狀態） |
| `1006` | 沒有填寫 SDK token |
| `1007` | 非法的跨域溝通，Token 設定的 IFrameDomain 與當前網域端不吻合 |
| `1008` | iframe 嘗試連線時間過長，中斷連線（timeout） |
| `1009` | iframe 無法取得當前網域，中斷連線（timeout） |

### 1.2 SDK Token 錯誤（Error: Token Error (Code: XXXXXX)）

| 代碼 | 訊息 |
|------|------|
| `OBJ01000` | 處理 Token 異常 |
| `OBJ01001` | 查無符合對應類型 |
| `OBJ01002` | 未有 Token |
| `OBJ01003` | Token 已過期 |
| `OBJ01004` | 未有商店資料 |
| `OBJ01005` | 未有訂單資料 |
| `OBJ01006` | 未有任何支付工具可使用 |

---

## 2. token_get API 錯誤（TOKEN0xxxx）

### 2.1 系統錯誤

| 代碼 | 訊息 |
|------|------|
| `TOKEN00000` | 系統異常 |

### 2.2 資料加解密錯誤（TOKEN01xxx）

| 代碼 | 訊息 |
|------|------|
| `TOKEN01001` | 未有商店代號 |
| `TOKEN01002` | 資料 HASH 比對不符合 |
| `TOKEN01003` | 資料解密失敗 |
| `TOKEN01004` | 解密資料不存在 |
| `TOKEN01005` | 查無符合商店資料（含代理商） |
| `TOKEN01006` | 已存在相同商店訂單編號 |

### 2.3 參數檢查錯誤（TOKEN02xxx）

| 代碼 | 訊息 |
|------|------|
| `TOKEN02000` | Token 設定失敗 |
| `TOKEN02001` | AesType，格式錯誤 |
| `TOKEN02002` | 商店未有設定 AesType |
| `TOKEN02003` | 商店 AesType 不符合 |
| `TOKEN02004` | 未有商店代號 |
| `TOKEN02005` | 未有商店訂單編號 |
| `TOKEN02006` | 商店訂單編號，超過長度限制 |
| `TOKEN02007` | 商店訂單編號，格式錯誤（英數字 -_） |
| `TOKEN02008` | 未有訂單金額 |
| `TOKEN02009` | 訂單金額，僅可輸入整數 |
| `TOKEN02010` | 訂單金額，格式錯誤 |
| `TOKEN02011` | 時間戳記，已過期 |
| `TOKEN02012` | 時間戳記，僅可輸入整數 |
| `TOKEN02013` | 時間戳記，已過期 *(注 1)* |
| `TOKEN02014` | 前景通知網址，格式錯誤 |
| `TOKEN02015` | 背景通知網址，格式錯誤 |

> **注 1**：`TOKEN02013` 與 `TOKEN02011` 在官方錯誤代碼頁
> （https://docs.payuni.com.tw/web/#/29/385）原文皆為「時間戳記，已過期」，
> 文字完全相同。這是官方文件原樣，**不是抄寫錯誤**——可能是 PAYUNi 內部
> 細分了兩種「過期」場景但對外揭露相同訊息。除錯時若收到 TOKEN02013，
> 處理方式同 02011（重新產生 Timestamp 後再送一次）。
>
> 反過來，**`TOKEN02014` 的官方訊息是「前景通知網址，格式錯誤」**
> （非「時間戳記，已過期」）——若有看到第三方表格寫成時間戳記，那是抄錯。
| `TOKEN02016` | 綁定類型，格式錯誤 |
| `TOKEN02017` | 未有綁定 Token |
| `TOKEN02018` | 綁定 Token，長度超過限制 |
| `TOKEN02019` | 綁定 Token，格式錯誤 |
| `TOKEN02020` | 綁定 Token 類型，格式錯誤 |
| `TOKEN02021` | 超過額度，未有會員 HASH |
| `TOKEN02022` | Domain，不得空白 |
| `TOKEN02023` | Domain，格式錯誤 |
| `TOKEN02024` | GrantExport 參數值錯誤 |
| `TOKEN02025` | 未有商品說明 |
| `TOKEN02026` | 未有消費者電子信箱 |
| `TOKEN02027` | 消費者電子信箱，格式錯誤 |
| `TOKEN02028` | 未有買方名稱（抬頭） |
| `TOKEN02029` | 未有載具類別 |
| `TOKEN02030` | 無法辨識的載具類別 |
| `TOKEN02031` | 載具資料不可為空 |
| `TOKEN02032` | 載具資料，格式錯誤 |
| `TOKEN02033` | 載具資料，格式錯誤（長度） |
| `TOKEN02034` | 商店不提供捐贈發票選項 |
| `TOKEN02035` | 此捐贈碼不在商店提供範圍內 |
| `TOKEN02036` | 手機條碼不正確 |

### 2.4 商店檢查錯誤（TOKEN03xxx）

| 代碼 | 訊息 |
|------|------|
| `TOKEN03001` | 未有商店資料 |
| `TOKEN03002` | 確認支付工具異常 |
| `TOKEN03003` | 商店資料異常 |
| `TOKEN03004` | 未有設定允許 Domain |
| `TOKEN03005` | 未有設定允許幕後 IP |
| `TOKEN03006` | 設定允許幕後 IP 不符合 |
| `TOKEN03007` | 代理商未開啟撥款指示功能 |
| `TOKEN03008` | 商店未提供約定信用卡交易 |

### 2.5 買方檢查錯誤（TOKEN04xxx）

| 代碼 | 訊息 |
|------|------|
| `TOKEN04001` | 買方會員資料取得（驗證）失敗 |

---

## 3. merchant_trade API 錯誤（IFTRADE0xxxx）

### 3.1 系統錯誤

| 代碼 | 訊息 |
|------|------|
| `IFTRADE00000` | 系統異常 |

### 3.2 資料加解密錯誤（IFTRADE01xxx）

| 代碼 | 訊息 |
|------|------|
| `IFTRADE01001` | 未有商店代號 |
| `IFTRADE01002` | 資料 HASH 比對不符合 |
| `IFTRADE01003` | 資料解密失敗 |
| `IFTRADE01004` | 解密資料不存在 |
| `IFTRADE01005` | 查無符合商店資料（含代理商） |
| `IFTRADE01006` | 已存在相同商店訂單編號 |

### 3.3 參數檢查錯誤（IFTRADE02xxx）

| 代碼 | 訊息 |
|------|------|
| `IFTRADE02001` | AesType，格式錯誤 |
| `IFTRADE02002` | 商店未有設定 AesType |
| `IFTRADE02003` | 商店 AesType 不符合 |
| `IFTRADE02004` | 未有交易 Token |
| `IFTRADE02005` | 未有商店代號 |
| `IFTRADE02006` | 未有商店訂單編號 |
| `IFTRADE02007` | 商店訂單編號，超過長度限制 |
| `IFTRADE02008` | 商店訂單編號，格式錯誤（英數字 -_） |
| `IFTRADE02009` | 未有訂單金額 |
| `IFTRADE02010` | 訂單金額，僅可輸入整數 |
| `IFTRADE02011` | 訂單金額，格式錯誤 |
| `IFTRADE02012` | 未有時間戳記 |
| `IFTRADE02013` | 時間戳記，僅可輸入整數 |
| `IFTRADE02014` | 時間戳記，已過期 |
| `IFTRADE02015` | 前景通知網址，格式錯誤 |
| `IFTRADE02016` | 背景通知網址，格式錯誤 |
| `IFTRADE02017` | 超過額度，未有會員 HASH |
| `IFTRADE02018` | 未有商品說明 |
| `IFTRADE02019` | 未有消費者電子信箱 |
| `IFTRADE02020` | 消費者電子信箱，格式錯誤 |
| `IFTRADE02021` | 未有買方名稱（抬頭） |
| `IFTRADE02022` | 未有載具類別 |
| `IFTRADE02023` | 無法辨識的載具類別 |
| `IFTRADE02024` | 載具資料不可為空 |
| `IFTRADE02025` | 載具資料，格式錯誤 |
| `IFTRADE02026` | 載具資料，格式錯誤（長度） |
| `IFTRADE02027` | 商店不提供捐贈發票選項 |
| `IFTRADE02028` | 此捐贈碼不在商店提供範圍內 |
| `IFTRADE02029` | 手機條碼不正確 |
| `IFTRADE02030` | API3D，格式錯誤 |
| `IFTRADE02031` | 使用者 IP 格式錯誤 |

### 3.4 買方檢查錯誤（IFTRADE03xxx）

| 代碼 | 訊息 |
|------|------|
| `IFTRADE03001` | 買方會員資料取得（驗證）失敗 |

### 3.5 交易檢查錯誤（IFTRADE04xxx）

| 代碼 | 訊息 |
|------|------|
| `IFTRADE04001` | Token 已過期 |
| `IFTRADE04002` | 未有交易設定資料 |
| `IFTRADE04003` | 交易設定資料異常 |

### 3.6 交易設定異常（IFTRADE05xxx）

| 代碼 | 訊息 |
|------|------|
| `IFTRADE05001` | 交易設定異常（原始資料） |
| `IFTRADE05002` | 交易設定異常（輸入資料） |
| `IFTRADE05003` | 交易設定異常（商店資料） |

---

## 4. 信用卡驗證錯誤（TRADE0xxxx，SDK getTradeResult 階段）

### 4.1 系統錯誤

| 代碼 | 訊息 |
|------|------|
| `TRADE00000` | 系統異常 |
| `TRADE00001` | 無 API 對應程式 |

### 4.2 資料加解密錯誤（TRADE01xxx）

| 代碼 | 訊息 |
|------|------|
| `TRADE01001` | 送出資料解析失敗 |
| `TRADE01002` | 送出資料解析失敗（KEY） |
| `TRADE01003` | 送出資料解析失敗（Decrypt） |
| `TRADE01004` | 未有 Token |
| `TRADE01005` | Token 已過期 |
| `TRADE01006` | 未有支付方式 |
| `TRADE01007` | 未有符合支付方式 |

### 4.3 參數檢查錯誤（TRADE02xxx）

| 代碼 | 訊息 |
|------|------|
| `TRADE02001` | 未有解密資料 |
| `TRADE02002` | 支付方式錯誤 |
| `TRADE02003` | 信用卡號，僅可輸入整數 |
| `TRADE02004` | 信用卡號，僅可輸入整數 |
| `TRADE02005` | 信用卡號，長度限制錯誤 |
| `TRADE02006` | 未有信用卡到期日 |
| `TRADE02007` | 信用卡到期日，格式錯誤（MMYY） |
| `TRADE02008` | 信用卡到期日，已逾期 |
| `TRADE02009` | 信用卡末三碼，長度限制錯誤 |
| `TRADE02010` | 信用卡末三碼，格式錯誤 |
| `TRADE02011` | 未有信用卡分期數 |
| `TRADE02012` | 信用卡分期數，期數格式錯誤 |
| `TRADE02013` | 未有信用卡末三碼 |

### 4.4 商店檢查錯誤（TRADE03xxx）

| 代碼 | 訊息 |
|------|------|
| `TRADE03001` | 未有商店資料 |
| `TRADE03002` | 確認支付工具異常 |
| `TRADE03003` | 商店資料異常 |

---

## 5. 通用狀態回傳

當 API 成功送達且解密成功，但業務邏輯失敗時，內層 `Status` 會是以下值：

| Status | 說明 |
|--------|------|
| `SUCCESS` | 成功（授權成功 / 取號成功 / 查詢成功） |
| `UNKNOWN` | 60 秒無銀行回應，等待中；後續以 NotifyURL 通知，或 15 分鐘後 `/api/trade/query`（同 UPP） |
| `UNAPPROVED` | 訂單待確認（買家會員資格審查中） |
| 上述錯誤碼 | 業務邏輯失敗，依錯誤碼處理 |

外層 `Status=ERROR` 時無 `EncryptInfo`，通常為 HashInfo 不符或 MerID 錯誤等基礎錯誤。

---

## 6. 常見排查對照

| 症狀 | 最可能原因 | 對策 |
|------|-----------|------|
| `1006` SDK token 沒填 | createSession 時 token 是 undefined / 空字串 | 確認後端 token_get 已成功回傳，前端拿到後再 createSession |
| `1007` 跨域不吻合 | IFrameDomain 與 window.location.origin 不一致 | 檢查 token_get 階段傳入值，含 `https://`、不含尾端 `/` |
| `1008` / `1009` timeout | 網路慢 / PAYUNi 端故障 | 顯示重試按鈕，重新 token_get → createSession |
| `OBJ01003` Token 過期 | 取 Token 後超過 10 分鐘才用 | 重新 token_get |
| `TOKEN03005` / `TOKEN03006` IP 不符 | 限定幕後 IP 沒設定 / 來源 IP 變了 | 後台「商店資訊 > 串接設定」設定限定 IP；CF Tunnel 注意出口 IP |
| `TOKEN01002` HASH 不符 | HashKey/HashIV 帶錯，或 EncryptInfo 算錯 | 檢查 .env，比對官方範例值（merKey=`12345...`） |
| `IFTRADE04001` Token 過期 | merchant_trade 用了過期的 SDK_TOKEN | 重跑前端 token_get + getTradeResult |
| `IFTRADE01006` 訂單編號重複 | MerTradeNo 10 分鐘內重複 | 訂單編號應全域 unique，加時間戳或 UUID 段 |
| `TRADE02008` 卡片過期 | 消費者卡片到期 | 前端 onUpdate 監測 CardExp 顯示錯誤訊息 |

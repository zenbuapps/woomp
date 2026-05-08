# PAYUNi 錯誤代碼對照表

> 來源：`includes/payuni/v3/Infrastructure/Http/HttpClient.php` — `ERROR_MAPPER`

---

## iframe 連線錯誤（1000–1009）

| 代碼 | 說明 |
|------|------|
| 1000 | Div ID 尚未設定 |
| 1001 | iframe 連線失敗 |
| 1002 | 此商店尚未啟用分期付款功能 |
| 1003 | 分期期數設定不正確 |
| 1004 | 帶入的函式參數有誤 |
| 1005 | 表單欄位輸入有誤（請透過 onUpdate 檢查） |
| 1006 | 尚未取得 SDK Token |
| 1007 | 跨域通訊不合法（網域與 Token 設定不符） |
| 1008 | iframe 連線逾時 |
| 1009 | 無法取得目前的網域（逾時） |

---

## 物件錯誤（OBJ01000–OBJ01006）

| 代碼 | 說明 |
|------|------|
| OBJ01000 | Token 處理例外 |
| OBJ01001 | 沒有符合的類型 |
| OBJ01002 | 沒有 Token |
| OBJ01003 | Token 已過期 |
| OBJ01004 | 沒有商店資料 |
| OBJ01005 | 沒有訂單資料 |
| OBJ01006 | 沒有可用的付款方式 |

---

## TOKEN API 錯誤（Token 取得）

### 系統錯誤

| 代碼 | 說明 |
|------|------|
| TOKEN00000 | 系統例外 |

### 基礎驗證（TOKEN01xxx）

| 代碼 | 說明 |
|------|------|
| TOKEN01001 | 無商店代號 |
| TOKEN01002 | 無 HashInfo |
| TOKEN01003 | HashInfo 不一致 |
| TOKEN01004 | 解密失敗 |
| TOKEN01005 | 無訂單資料 |
| TOKEN01006 | 訂單驗證失敗 |

### 參數驗證（TOKEN02xxx）

| 代碼 | 說明 |
|------|------|
| TOKEN02000 | Token 設定失敗 |
| TOKEN02001 | AES 類型錯誤 |
| TOKEN02002 | 無商店組態 |
| TOKEN02003 | 訂單金額錯誤 |
| TOKEN02004 | 時間戳記錯誤 |
| TOKEN02005 | 通知 URL 錯誤 |
| TOKEN02006 | 回傳 URL 錯誤 |
| TOKEN02007 | 商品描述錯誤 |
| TOKEN02008 | 訂單編號錯誤 |
| TOKEN02009 | 商店代號錯誤 |
| TOKEN02010 | 信用卡 Token 類型錯誤 |
| TOKEN02011 | 信用卡 Token 錯誤 |
| TOKEN02012 | 信用卡 Token 紀錄類型錯誤 |
| TOKEN02013 | 分期期數錯誤 |
| TOKEN02014 | IFrame 網域錯誤 |
| TOKEN02015 | 版本錯誤 |
| TOKEN02016 | 語系錯誤 |
| TOKEN02017 | 無信用卡 Token |
| TOKEN02018 | 合約號碼錯誤 |
| TOKEN02019 | 信用卡 Token 類型未啟用 |
| TOKEN02020 | 信用卡 Token 紀錄異常 |
| TOKEN02021 | 信用卡 Token 紀錄數量超過上限 |
| TOKEN02022 | API3D 參數設定錯誤 |
| TOKEN02023 | 強制約定信用卡設定錯誤 |
| TOKEN02024 | UserIP 參數格式錯誤 |
| TOKEN02025 | 買家Email 格式錯誤 |
| TOKEN02026 | 使用信用卡 Token 需帶入 CreditToken 參數 |
| TOKEN02027 | 使用信用卡 Token 需帶入 CreditTokenType 參數 |
| TOKEN02028 | IFrame 網域驗證失敗 |
| TOKEN02029 | UseTokenType 與設定不一致 |
| TOKEN02030 | 載具類型錯誤 |
| TOKEN02031 | 載具資料不正確 |
| TOKEN02032 | 載具資料驗證失敗 |
| TOKEN02033 | 載具買家名稱錯誤 |
| TOKEN02034 | 載具手機條碼格式錯誤 |
| TOKEN02035 | 載具自然人憑證格式錯誤 |
| TOKEN02036 | 載具捐贈碼格式錯誤 |

### 商店驗證（TOKEN03xxx）

| 代碼 | 說明 |
|------|------|
| TOKEN03001 | 商店資料驗證失敗 |
| TOKEN03002 | 商店配置驗證失敗 |
| TOKEN03003 | 商店狀態異常 |
| TOKEN03004 | 商店未啟用信用卡功能 |
| TOKEN03005 | 商店未啟用分期付款功能 |
| TOKEN03006 | 商店未啟用免跳轉元件功能 |
| TOKEN03007 | 商店 IP 限定驗證失敗 |
| TOKEN03008 | 商店未啟用信用卡 Token 功能 |

### 會員驗證（TOKEN04xxx）

| 代碼 | 說明 |
|------|------|
| TOKEN04001 | 會員資料取得/驗證失敗 |

---

## IFTRADE API 錯誤（交易執行）

### 系統錯誤

| 代碼 | 說明 |
|------|------|
| IFTRADE00000 | 系統例外 |

### 基礎驗證（IFTRADE01xxx）

| 代碼 | 說明 |
|------|------|
| IFTRADE01001 | 無商店代號 |
| IFTRADE01002 | 無 HashInfo |
| IFTRADE01003 | HashInfo 不一致 |
| IFTRADE01004 | 解密失敗 |
| IFTRADE01005 | 無訂單資料 |
| IFTRADE01006 | 訂單驗證失敗 |

### 參數驗證（IFTRADE02xxx）

| 代碼 | 說明 |
|------|------|
| IFTRADE02001 | AES 類型錯誤 |
| IFTRADE02002 | 無交易 Token |
| IFTRADE02003 | 訂單金額錯誤 |
| IFTRADE02004 | 時間戳記錯誤 |
| IFTRADE02005 | 通知 URL 錯誤 |
| IFTRADE02006 | 回傳 URL 錯誤 |
| IFTRADE02007 | 商品描述錯誤 |
| IFTRADE02008 | 訂單編號錯誤 |
| IFTRADE02009 | 商店代號錯誤 |
| IFTRADE02010 | 信用卡 Token 類型錯誤 |
| IFTRADE02011 | 信用卡 Token 錯誤 |
| IFTRADE02012 | 信用卡 Token 紀錄類型錯誤 |
| IFTRADE02013 | 分期期數錯誤 |
| IFTRADE02020 | 載具類型錯誤 |
| IFTRADE02021 | 載具資料不正確 |
| IFTRADE02022 | 載具資料驗證失敗 |
| IFTRADE02023 | 載具買家名稱錯誤 |
| IFTRADE02024 | 載具手機條碼格式錯誤 |
| IFTRADE02025 | 載具自然人憑證格式錯誤 |
| IFTRADE02026 | 載具捐贈碼格式錯誤 |
| IFTRADE02027 | 載具公司統編格式錯誤 |
| IFTRADE02028 | 合約號碼錯誤 |
| IFTRADE02029 | API3D 參數設定錯誤 |
| IFTRADE02030 | UserIP 參數格式錯誤 |

### 會員驗證（IFTRADE03xxx）

| 代碼 | 說明 |
|------|------|
| IFTRADE03001 | 會員資料取得失敗 |

### Token 與交易設定（IFTRADE04xxx–05xxx）

| 代碼 | 說明 |
|------|------|
| IFTRADE04001 | Token 已過期 |
| IFTRADE04002 | 交易設定驗證失敗 |
| IFTRADE04003 | 交易設定異常 |
| IFTRADE05001 | 交易設定異常 (1) |
| IFTRADE05002 | 交易設定異常 (2) |
| IFTRADE05003 | 交易設定異常 (3) |

---

## TRADE API 錯誤（卡片驗證）

### 系統錯誤

| 代碼 | 說明 |
|------|------|
| TRADE00000 | 系統例外 |
| TRADE00001 | API 對應異常 |

### Token 與付款方式（TRADE01xxx）

| 代碼 | 說明 |
|------|------|
| TRADE01001 | 無交易 Token |
| TRADE01002 | Token 已過期 |
| TRADE01003 | 付款方式錯誤 |
| TRADE01004 | 付款方式未啟用 |
| TRADE01005 | 信用卡 Token 處理異常 |
| TRADE01006 | 信用卡 Token Hash 異常 |
| TRADE01007 | 信用卡 Token 約定卡異常 |

### 卡號驗證（TRADE02xxx）

| 代碼 | 說明 |
|------|------|
| TRADE02001 | 卡號驗證失敗 |
| TRADE02002 | 卡號長度錯誤 |
| TRADE02003 | 卡號格式錯誤 |
| TRADE02004 | 到期日驗證失敗 |
| TRADE02005 | 到期日格式錯誤 |
| TRADE02006 | 卡片已過期 |
| TRADE02007 | CVC 驗證失敗 |
| TRADE02008 | CVC 格式錯誤 |
| TRADE02009 | 分期期數錯誤 |
| TRADE02010 | 分期期數未啟用 |
| TRADE02011 | 信用卡 Token 類型錯誤 |
| TRADE02012 | 約定卡付款驗證失敗 |
| TRADE02013 | 信用卡 Token 類型與設定不一致 |

### 商店驗證（TRADE03xxx）

| 代碼 | 說明 |
|------|------|
| TRADE03001 | 商店資料驗證失敗 |
| TRADE03002 | 商店付款工具未啟用 |
| TRADE03003 | 商店分期付款未啟用 |

---

## API 通用錯誤

### 加密與參數（API00xxx）

| 代碼 | 說明 |
|------|------|
| API00001 | API 類型錯誤 |
| API00002 | API 版本錯誤 |
| API00003 | 加密資料錯誤 |
| API00004 | Hash 驗證失敗 |
| API00005 | 解密失敗 |
| API00006 | 商店代號錯誤 |
| API00007 | 無商店代號 |
| API00008 | 無 EncryptInfo |
| API00009 | 無 HashInfo |
| API00010 | 無 Version |
| API00011 | 代理商平台驗證失敗 |

### 3D 驗證（API01xxx）

| 代碼 | 說明 |
|------|------|
| API01001 | 背景 3D 處理錯誤 (1) |
| API01002 | 背景 3D 處理錯誤 (2) |
| API01003 | 背景 3D 處理錯誤 (3) |
| API01004 | 背景 3D 處理錯誤 (4) |

### Samsung Pay（API02xxx）

| 代碼 | 說明 |
|------|------|
| API02001 | Samsung Pay 處理錯誤 (1) |
| API02002 | Samsung Pay 處理錯誤 (2) |

---

## 設定錯誤（DEF01xxx）

| 代碼 | 說明 |
|------|------|
| DEF01001 | 商店代號驗證失敗 |
| DEF01002 | 解密失敗 |
| DEF01003 | 代理商驗證失敗 |
| DEF01004 | 商店狀態異常 |
| DEF01005 | 商店 IP 限定驗證失敗 |
| DEF01006 | 代理商設定異常 |
| DEF01007 | 代理商狀態異常 |

---

## 常見除錯場景

| 症狀 | 可能原因 | 對應錯誤碼 |
|------|---------|-----------|
| iframe 無法載入 | 域名未設定或不符 | 1007, TOKEN02014, TOKEN02028 |
| SDK Token 取得失敗 | 金鑰錯誤或過期 | TOKEN01003, TOKEN01004 |
| 交易失敗 | Token 過期（>10分鐘） | IFTRADE04001, TRADE01002 |
| 分期不可用 | 商店未開通或期數不支援 | 1002, TOKEN03005, TRADE03003 |
| 載具驗證失敗 | 格式不符 | TOKEN02030–02036, IFTRADE02020–02027 |
| 信用卡記憶失敗 | 功能未開通或參數缺少 | TOKEN02019, TOKEN03008, TRADE01005 |

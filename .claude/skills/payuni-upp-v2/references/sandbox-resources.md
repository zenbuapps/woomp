# PAYUNi Sandbox 測試資源與後台驗證

> 來源：
> - 主頁 Sandbox 章節：[#/7/34](https://docs.payuni.com.tw/web/#/7/34)
> - 交易測試資料：[#/7/374](https://docs.payuni.com.tw/web/#/7/374)

## 環境

| 項目 | URL / 值 |
|------|----------|
| 註冊 | `https://sandbox.payuni.com.tw/signup` |
| 後台 | `https://sandbox.payuni.com.tw` |
| API base | `https://sandbox-api.payuni.com.tw` |
| UPP endpoint | `https://sandbox-api.payuni.com.tw/api/upp` |

> Production 對應：`https://api.payuni.com.tw` / `https://www.payuni.com.tw`

## 串接金鑰

登入 Sandbox 後台 → 會員 → 商店清單 → 串接設定 → 取得 **Hash Key (32 字元)** + **Hash IV (16 字元)**。

> Sandbox 與 Production 金鑰**不同**，不可混用。

## 測試卡號

### 信用卡

| 用途 | 卡號 |
|------|------|
| 一次付清成功 | `4147631000000001`（Visa）/ `3560511000000001`（JCB）|
| 一次付清模擬 3D ECI 不符（取消授權）| `4147631000000002` / `3560511000000002` |
| 分期付款（不支援 9 期）| `4147632000000001` / `3560512000000001` |
| 銀聯卡 | `6200000000000001` |

到期日 / CVC：**任意填**（建議到期日 `12/30`、CVC `123`）。

> ⚠️ **不要混用其他金流的測試卡**：PAYUNi sandbox 只接受上表卡號。
> NewebPay 的 `4000-2211-1111-1111` / `4000-2222-2222-2222` 在 PAYUNi 會直接授權失敗（卡號不在 PAYUNi 測試清單內）。
> ECPay sandbox 信用卡見 ECPay-API-Skill；NewebPay 見 newebpay-mpg。

### 行動支付

| 工具 | 測試 |
|------|------|
| Apple Pay | 不限卡號於測試區皆模擬成功 |
| Google Pay | 同上 |
| Samsung Pay | 同上 |
| LINE Pay | Channel ID / Channel Secret Key 可填**任意數字** |
| icash Pay / AFTEE / 街口 | 由 PAYUNi sandbox 模擬完成 |

## ATM / CVS 模擬繳費

ATM 虛擬帳號與 CVS 超商代碼訂單，於 sandbox 後台 **「交易動態 > 模擬繳費」** 按鈕手動觸發，立即收到 NotifyURL 回呼，免等真實繳費流程。

## DeepLinkURL 注意事項

- 測試環境**自動忽略 `DeepLinkURL`** 欄位（視為空值）。
- 若於測試環境有提供 `DeepLinkURL`，**請務必同時提供 `ReturnURL`**——否則交易結果頁無法返回商家。
- 僅 icash Pay / LINE Pay / 街口支付 / AFTEE 才會生效。

## NotifyURL 必須條件

- 限 80/443 port（即 HTTP 預設或 HTTPS 預設）
- 必須為公開可訪問 URL（不能是 localhost / 內網 IP）
- 開發本地測試需用 cloudflare tunnel / ngrok 等 reverse proxy

> 本專案已配置 Cloudflare Tunnel `zenbu-site`：`https://zenbu-site.powerhouse.tw → localhost:6060`。詳見 `scripts/start-tunnel.sh`。

## 後台訂單驗證流程（playwright-cli 自動化）

PAYUNi Sandbox 後台可查詢訂單明細，用於驗證 webhook 結果、模擬 ATM/CVS 繳費、排查 UNKNOWN 狀態，或比對 ReturnURL/NotifyURL 與後台是否一致。

### 認證資訊（`.env`）

| 變數 | 說明 |
|------|------|
| `PAYUNI_SANDBOX_ADMIN_URL` | 後台登入頁（`https://sandbox.payuni.com.tw/auth/merchant`）|
| `PAYUNI_SANDBOX_TAX_ID` | 統編 |
| `PAYUNI_SANDBOX_ACCOUNT` | 登入帳號 |
| `PAYUNI_SANDBOX_PASSWORD` | 登入密碼 |

### 訂單詳情 URL

```
https://sandbox.payuni.com.tw/auth/merchant/detail/{YYYY}%7C%7C%7C{encrypted}
```

| 區段 | 說明 |
|------|------|
| `{YYYY}` | 訂單年份，如 `2026` |
| `%7C%7C%7C` | URL-encoded 的 `\|\|\|` 分隔符 |
| `{encrypted}` | PAYUNi 後台產生的訂單加密字串（hex）|

### playwright-cli 自動化流程

程式化驗證時，使用 `playwright-cli` skill 模擬瀏覽器登入：

```bash
# 1. 開啟瀏覽器並導向後台登入頁
playwright-cli -s=payuni-admin open "$PAYUNI_SANDBOX_ADMIN_URL"

# 2. 填入登入欄位（統編 / 帳號 / 密碼）
#    （需先 snapshot 抓 e refs，這裡示意）
playwright-cli -s=payuni-admin fill e10 "$PAYUNI_SANDBOX_TAX_ID"
playwright-cli -s=payuni-admin fill e11 "$PAYUNI_SANDBOX_ACCOUNT"
playwright-cli -s=payuni-admin fill e12 "$PAYUNI_SANDBOX_PASSWORD"
playwright-cli -s=payuni-admin click e13   # 登入按鈕

# 3. 導向訂單詳情 URL
playwright-cli -s=payuni-admin goto "https://sandbox.payuni.com.tw/auth/merchant/detail/2026%7C%7C%7C${encrypted}"

# 4. 擷取頁面欄位
playwright-cli -s=payuni-admin eval "() => document.body.innerText"
#   → 解析 TradeStatus / TradeAmt / PaymentType / AuthCode / MerTradeNo / TradeNo

# 5. 與本地 webhook log / DB 訂單記錄比對一致性

playwright-cli -s=payuni-admin close
```

### 模擬 ATM / CVS 繳費

ATM 虛擬帳號與 CVS 超商代碼訂單，於後台「**交易動態 > 模擬繳費**」手動觸發 → 立即收到 NotifyURL 回呼。

可用 playwright-cli 自動化：

1. 登入後導向 `/auth/merchant` 交易動態頁
2. 找到對應訂單 → 點擊「模擬繳費」按鈕
3. 確認 NotifyURL handler 收到 `TradeStatus=1`（已付款）

### 注意事項

- **帳密一律從 `.env` 讀取**，**禁止寫死在 SKILL / 程式碼 / commit**
- 後台 session 會逾時，長時間自動化任務需處理重新登入
- Sandbox 與 Production 後台 URL 不同，務必確認連到 sandbox

## 常見 Sandbox 問題

| 症狀 | 原因 | 解法 |
|------|------|------|
| ReturnURL 沒被觸發 | DeepLinkURL 有值（被忽略後沒 fallback） | 同時提供 ReturnURL |
| NotifyURL 沒收到 | port 不是 80/443 / URL 不公開 | 用 cloudflare tunnel / 改用標準 port |
| HashInfo 不一致 | 用了 production 金鑰 | 確認 `.env` 環境變數 |
| 信用卡授權失敗 | 卡號不對 / 不在測試卡號清單中 | 用 `4147631000000001` 等官方測試卡 |
| ATM 取號成功但 NotifyURL 沒到 | 還沒模擬繳費 | 後台「模擬繳費」按鈕手動觸發 |
| `UPP01007` 訂單編號重複 | 10 分鐘內 MerTradeNo 重複 | 用時間戳或 UUID 避免 |

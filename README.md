# Woomp — MorePower Addon for WooCommerce

## 打包發布（Build）

### 需求

- Bash（WSL、Git Bash 或 macOS/Linux 終端機）
- [Composer](https://getcomposer.org/) （已加入 PATH）
- `zip` 指令（WSL Ubuntu 可執行 `sudo apt-get install -y zip` 安裝）

### 執行打包

在 plugin 根目錄執行：

```bash
bash build.sh
```

### 輸出

打包完成後會在 `build/` 目錄產生 zip 檔，檔名帶有版本號：

```
build/woomp-{VERSION}.zip
```

> 版本號自動從 `woomp.php` 的 `Version:` header 讀取。

### 打包內容

| 包含                                                             | 排除                                                                         |
| ---------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| `includes/`, `admin/`, `public/`, `languages/`, `woocommerce/`   | `.git/`, `.idea/`, `tests/`, `build/`                                        |
| `vendor/`（僅正式依賴，重新由 `composer install --no-dev` 產生） | `debug.php`, `phpcs.xml`, `phpunit.xml`, `tailwind.config.cjs`, `.gitignore` |
| `woomp.php`, `init.php`, `uninstall.php` 等主要檔案              | `composer.json`, `composer.lock`                                             |

### 安裝

將 zip 解壓縮後上傳至 `wp-content/plugins/`，或直接從 WordPress 後台上傳安裝。

```
wp-content/plugins/
└── woomp/
    ├── woomp.php
    ├── vendor/
    └── ...
```


# woomp — WooCommerce PayUni 統一金流外掛

整合統一金流 PAYUNi 信用卡 v3（UNi Embed）的 WooCommerce 付款閘道外掛。

---

## E2E 自動化測試

### 說明

`e2e/payuni-checkout.ts` 是一個 TypeScript Playwright 腳本，完整模擬以下流程：

1. 加入商品至購物車
2. 填寫帳單資訊
3. 選取「統一金流 PAYUNi 信用卡 v3」
4. 填入信用卡號、有效期限、安全碼（PayUni UNi Embed cross-origin iframe）
5. 提交訂單，等待跳轉至 order-received 頁面

### 環境需求

- **Node.js** 18+
- **Chromium**（首次使用需安裝）

### 安裝

```bash
cd e2e
npm install
npx playwright install chromium
```

### 執行方式

#### 模式一：直接啟動瀏覽器（無登入狀態）

```bash
cd e2e
npm test
# 或
npx tsx payuni-checkout.ts
```

#### 模式二：連線現有 Chrome（保持登入狀態）

此模式會連線到已開啟的 Chrome，保留所有 Cookie 與登入狀態。

**Step 1：以 remote debugging 模式啟動 Chrome**

```bash
# Windows（Chrome）
"C:\Program Files\Google\Chrome\Application\chrome.exe" --remote-debugging-port=9222 --user-data-dir="C:\chrome-debug-profile"

# macOS
/Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome --remote-debugging-port=9222 --user-data-dir="/tmp/chrome-debug"
```

**Step 2：執行測試腳本（連線至現有 Chrome）**

```bash
cd e2e

# CLI 參數
npx tsx payuni-checkout.ts --cdp-endpoint=http://localhost:9222

# 或 npm script
npm run test:cdp
```

### 測試卡號資訊（沙箱環境）

| 欄位     | 值                   |
|----------|----------------------|
| 卡號     | `4147631000000001`   |
| 有效期限 | `1228`（12/28 任意） |
| 安全碼   | `123`（任意三碼）    |

> ⚠️ 此卡號僅限統一金流**沙箱測試環境**使用，請勿用於正式環境。

### 驗收條件

腳本執行成功時，終端機會輸出：

```
✅ 付款成功！
   訂單 URL：https://payuni-test.powerhouse.tw/checkout/order-received/{訂單編號}/...
   頁面標題：訂單確認
```

並在 `e2e/screenshots/` 目錄儲存以下截圖：
- `before-submit-*.png`：提交前的結帳頁
- `success-*.png`：付款成功的訂單確認頁

### 目錄結構

```
e2e/
├── payuni-checkout.ts   # 主要測試腳本
├── package.json         # npm 設定（playwright, tsx）
├── tsconfig.json        # TypeScript 設定
└── screenshots/         # 執行截圖（自動建立）
```

---

## 開發說明

### 付款閘道架構（v3）

- **Gateway ID**：`payuni-credit-v3`
- **流程**：前端 UNi Embed SDK 取得卡號 token → PHP `process_payment()` server-side 呼叫 PayUni `merchant_trade` API → 成功後重導向至 order-received
- **Webhook**：`/wc-api/payuni_payment_v3/` 接收統一金流非同步通知，補寫 `TradeNo`、`Card4No`

### 主要檔案

| 檔案 | 說明 |
|------|------|
| `includes/payuni/src/gateways/CreditV3.php` | 付款閘道主體，`process_payment()` 執行交易 |
| `includes/payuni/v3/Infrastructure/Http/TradeHandler.php` | server-side 呼叫 PayUni API |
| `includes/payuni/v3/Infrastructure/Http/TradeReqDTO.php` | 組裝加密請求參數 |
| `assets/js/payuni-v3/` | 前端 UNi Embed SDK 整合模組 |

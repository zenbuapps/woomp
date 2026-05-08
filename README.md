# Woomp — MorePower Addon for WooCommerce

WooCommerce 台灣在地化擴充外掛，整合多家金流（PayUni / 綠界 / 立吉富 / 藍新 / LINE Pay）、物流（超商取貨 / 宅配）與電子發票（ECPay / EZPAY / PayNow）。

## 環境需求

- WordPress 6.x+
- WooCommerce 7.1+（HPOS 相容）
- PHP 8.0+
- Node.js 18+
- Composer

## 快速開始

```bash
# 安裝 PHP 依賴
composer install

# 安裝 Node.js 依賴（打包用）
npm install
```

## 打包發布

```bash
npm run build
```

產出 `build/woomp.zip`，版本號自動從 `woomp.php` 的 `Version:` header 讀取。

| 包含                                                           | 排除                                     |
| -------------------------------------------------------------- | ---------------------------------------- |
| `includes/`, `admin/`, `public/`, `languages/`, `woocommerce/` | `.git/`, `.idea/`, `tests/`, `build/`    |
| `vendor/`（僅正式依賴，`composer install --no-dev`）           | `phpcs.xml`, `phpunit.xml`, `.gitignore` |
| `woomp.php`, `init.php`, `uninstall.php` 等主要檔案            | `composer.json`, `composer.lock`         |

## 程式碼檢查

```bash
vendor/bin/phpcs
```

## 測試

本專案有兩層自動化測試：

| 層級                 | 工具                    | 範圍                                        | 目錄             |
| -------------------- | ----------------------- | ------------------------------------------- | ---------------- |
| **PHPUnit 整合測試** | PHPUnit 9 + wp-env      | PHP 內部邏輯（Hook、Gateway、加解密、HPOS） | `tests/phpunit/` |
| **E2E 端對端測試**   | Playwright + TypeScript | 真實瀏覽器操作（結帳、設定頁、後台管理）    | `tests/e2e/`     |

### 環境設定

複製 `.env.example` 為 `.env` 並填入測試站台的實際值：

```bash
cp .env.example .env
```

### PHPUnit 快速開始

需要 Docker Desktop + wp-env：

```bash
# 啟動測試環境
npx @wordpress/env start

# 煙霧測試（最快，~20 個測試）
npx @wordpress/env run tests-cli -- bash -c \
  'cd /var/www/html/wp-content/plugins/woomp && \
   WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit \
   --configuration tests/phpunit/phpunit.xml.dist \
   --no-coverage --testdox --testsuite smoke'

# 全部整合測試（~114 個測試）
npx @wordpress/env run tests-cli -- bash -c \
  'cd /var/www/html/wp-content/plugins/woomp && \
   WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit \
   --configuration tests/phpunit/phpunit.xml.dist \
   --no-coverage --testdox'
```

> 完整文件見 [`tests/phpunit/README.md`](tests/phpunit/README.md)

### E2E 快速開始

需要測試站台可連線（Local by Flywheel / Cloudflare Tunnel）：

```bash
cd tests/e2e
npm install
npx playwright install chromium

# 首次需執行 Setup（啟用模組 + 建立 API Keys）
npx playwright test --project=setup

# 核心 happy flow（最常用）
npm run test:happy-flow

# 全部測試
npm run test:all

# 查看報告
npm run report
```

> 完整文件見 [`tests/e2e/README.md`](tests/e2e/README.md)

## 目錄結構

```
woomp/
├── woomp.php                  # 外掛入口（版本 header）
├── init.php                   # 初始化、常數定義、子外掛載入
├── Compatibility.php          # 相容性處理
├── admin/                     # 後台功能類別
├── public/                    # 前台功能類別
├── includes/                  # 核心類別 + 子外掛模組
│   ├── class-woomp.php        # 主類別（Loader Pattern）
│   ├── payuni/                # PayUni 金流（v1 + v3）
│   ├── ry-woocommerce-tools/  # 綠界 / 藍新 / 速買配
│   ├── paynow-payment/        # 立吉富金流
│   ├── paynow-shipping/       # 立吉富物流
│   ├── paynow-einvoice/       # 立吉富電子發票
│   ├── line-pay-for-woo/      # LINE Pay
│   ├── woomp-ecpay-invoice/   # 綠界電子發票
│   └── woomp-ezpay-invoice/   # EZPAY 電子發票
├── tests/
│   ├── phpunit/               # PHPUnit 整合測試
│   └── e2e/                   # Playwright E2E 測試
├── .env.example               # 測試環境變數範本
├── .wp-env.json               # wp-env Docker 設定
├── composer.json               # PHP 依賴
└── phpcs.xml                  # PHPCS 設定
```

## 開發說明

### 付款閘道架構（PayUni v3）

- **Gateway ID**：`payuni-credit-v3`
- **流程**：前端 UNi Embed SDK 取得卡號 token → PHP `process_payment()` server-side 呼叫 PayUni API → 成功後重導向至 order-received
- **Webhook**：`/wc-api/payuni_payment_v3/` 接收非同步通知

### 測試卡號（PayUni Sandbox）

| 用途     | 卡號                      | 品牌 |
| -------- | ------------------------- | ---- |
| 一般付款 | `4147631000000001`        | Visa |
| 一般付款 | `3560511000000001`        | JCB  |
| 分期付款 | `3560562000000001`        | Visa |
| CVC      | 任意 3 碼（如 `123`）     | —    |
| 到期日   | 任意未來日期（如 `1228`） | —    |

## 授權

GPL-2.0+

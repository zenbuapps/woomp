# Woomp — WooCommerce 好用版擴充

## 專案概述

Woomp（好用版擴充 MorePower Addon for WooCommerce）是一個以**台灣市場**為核心的 WooCommerce 擴充外掛。將多家金流、物流與電子發票服務整合於單一外掛中，並透過設定驅動的模組系統進行管理。

- **版本**：3.4.81
- **授權**：GPL-2.0+
- **需求**：WooCommerce 5.3+、PHP 8.0+
- **主分支**：`master`
- **更新來源**：GitHub（`j7-dev/woomp`），透過 Plugin Update Checker 自動更新

## 技術棧

| 層級 | 技術 | 版本 |
|------|------|------|
| 後端 | PHP、WordPress、WooCommerce | 8.0+ / 6.x / 5.3+ |
| 前端 | jQuery、ES6 Modules（PayUni v3）、Tailwind CSS | — |
| 自動載入 | Composer PSR-4（`J7\Payuni\` → `includes/payuni/v3/`）、a7/autoload | — |
| 程式碼風格 | WordPress Coding Standards（phpcs.xml）、短陣列語法 `[]` | — |
| 建置 | `node build.mjs`（archiver ZIP 打包） | — |
| E2E 測試 | Playwright（TypeScript），位於 `e2e/` | — |
| 依賴套件 | `oberonlai/wp-metabox`、`dennykuo/invoice-porter`、`guzzlehttp/guzzle`、`yahnis-elsts/plugin-update-checker` | ^6.5.8 / ^5.3 |

## 目錄結構

```
woomp/
├── woomp.php                  # 外掛入口（版本 header）
├── init.php                   # 初始化、常數定義、子外掛載入
├── Compatibility.php          # 相容性處理
├── debug.php                  # 除錯工具
├── admin/                     # 後台功能類別
│   ├── settings/              # WooCommerce 設定頁籤
│   ├── resources/             # 後台資源（訂閱、結帳）
│   ├── css/ js/               # 後台靜態資源
│   └── partials/              # 後台模板
├── public/                    # 前台功能類別（結帳、訂單、商品）
│   ├── css/ js/               # 前台靜態資源
│   └── partials/              # 前台模板
├── includes/                  # 核心類別 + 子外掛模組
│   ├── class-woomp.php        # 主類別（Loader Pattern）
│   ├── class-woomp-loader.php # Hook 管理器
│   ├── payuni/                # PayUni 金流（v1 + v3）
│   │   ├── v3/                # PSR-4 命名空間 J7\Payuni\
│   │   └── src/gateways/      # v1 金流閘道類別
│   ├── ry-woocommerce-tools/  # 綠界 / 藍新 / 速買配
│   ├── paynow-payment/        # 立吉富金流
│   ├── paynow-shipping/       # 立吉富物流
│   ├── paynow-einvoice/       # 立吉富電子發票
│   ├── line-pay-for-woo/      # LINE Pay
│   ├── PChomePay-Cart-for-WooCommerce/  # 支付連
│   ├── woomp-ecpay-invoice/   # 綠界電子發票
│   ├── woomp-ezpay-invoice/   # EZPAY 電子發票
│   └── woomp-paynow-shipping/ # 立吉富物流（woomp 版）
├── woocommerce/checkout/      # WC 結帳模板覆寫
├── languages/                 # 多國語系
├── e2e/                       # Playwright E2E 測試
├── composer.json              # PHP 依賴
├── package.json               # Node 建置工具
└── phpcs.xml                  # PHPCS 設定
```

## 測試環境

請使用 playwright MCP 登入 https://payuni-test.powerhouse.tw/ 測試
登入網址: https://payuni-test.powerhouse.tw/wp-admin
帳號: test
密碼: test

https://payuni-test.powerhouse.tw 透過 cloudflare tunnel 連線到本地測試伺服器，**若無法連線請提請開發者開啟測試伺服器**

## 溝通慣例

- **註解 / 文件**：繁體中文
- **程式碼命名**：English
- **Commit 格式**：Conventional Commits（`feat:`, `fix:`, `chore:`, `test:` 等）

## Git 工作流程

- **主分支**：`master`
- **功能分支**：`feature/<名稱>`
- **發佈**：透過 GitHub Releases 發佈 ZIP 檔，經 Plugin Update Checker 自動更新

## 架構決策

### 設定驅動的模組載入
子外掛在 `init.php` 中根據 `get_option('wc_woomp_setting_*')` / `get_option('wc_woomp_enabled_*')` 的值條件式載入。每個金流服務商（綠界、藍新、立吉富、統一金流等）可從 WooCommerce > 設定 > 好用版擴充 獨立啟用或停用。

### Loader Pattern
`Woomp_Loader`（`includes/class-woomp-loader.php`）集中管理 WordPress action/filter 的註冊。透過 `add_action()`/`add_filter()` 方法收集 Hook，再於 `run()` 中批次註冊。

### 金流閘道模式
所有金流閘道繼承 `WC_Payment_Gateway`（或 `WC_Payment_Gateway_CC`）。PayUni v1 使用 `PAYUNI\Gateways\AbstractGateway`。PayUni v3 使用 PSR-4 命名空間 `J7\Payuni\`，搭配 DTO/Infrastructure 分層架構。

### PayUni v3 ES6 模組系統
前端使用原生 ES6 模組：`checkout.js` → `Elements.module.js` → `PayUniService.module.js`。腳本透過 `script_loader_tag` 過濾器以 `type="module"` 方式載入。

## 常用指令

```bash
# 安裝 PHP 依賴
composer install

# 建置發佈用 ZIP
npm run build

# 執行 PHPCS 程式碼檢查
vendor/bin/phpcs

# 執行 E2E 測試
cd e2e && npx playwright test

# 執行特定 E2E 測試
cd e2e && npx playwright test payuni-checkout.ts
```

## 相關文件指引

| 文件 | 用途 |
|------|------|
| `.claude/rules/wordpress.rule.md` | WordPress / PHP 編碼規範（自動套用於 `**/*.php`） |
| `.claude/skills/payuni-embed/SKILL.md` | PayUni Embed 支付整合知識 |
| `.claude/skills/woomp/SKILL.md` | Woomp 專案開發指引 |

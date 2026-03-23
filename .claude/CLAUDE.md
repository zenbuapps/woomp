# Woomp — WooCommerce 好用版擴充

## 專案概述

Woomp（好用版擴充 MorePower Addon for WooCommerce）是一個以**台灣市場**為核心的 WooCommerce 擴充外掛。將多家金流、物流與電子發票服務整合於單一外掛中，並透過設定驅動的模組系統進行管理。

- **授權**：GPL-2.0+
- **需求**：WooCommerce 7.1+、PHP 8.0+
- **主分支**：`master`
- **更新來源**：GitHub（`j7-dev/woomp`），透過 Plugin Update Checker 自動更新

## 技術棧

| 層級       | 技術                                                                                                         | 版本                |
| ---------- | ------------------------------------------------------------------------------------------------------------ | ------------------- |
| 後端       | PHP、WordPress、WooCommerce                                                                                  | 8.0+ / 6.x / 7.1+   |
| HPOS       | WooCommerce High-Performance Order Storage（`woocommerce/src/Features/Orders/CustomOrdersTableController`）  | WC 7.1+，需宣告相容 |
| 前端       | jQuery、ES6 Modules（PayUni v3）、Tailwind CSS                                                               | —                   |
| 自動載入   | Composer PSR-4（`J7\Payuni\` → `includes/payuni/v3/`）、a7/autoload                                          | —                   |
| 程式碼風格 | WordPress Coding Standards（phpcs.xml）、短陣列語法 `[]`                                                     | —                   |
| 建置       | `node build.mjs`（archiver ZIP 打包）                                                                        | —                   |
| E2E 測試   | Playwright（TypeScript），位於 `tests/e2e/`                                                                  | —                   |
| 依賴套件   | `oberonlai/wp-metabox`、`dennykuo/invoice-porter`、`guzzlehttp/guzzle`、`yahnis-elsts/plugin-update-checker` | ^6.5.8 / ^5.3       |

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
├── tests/e2e/                 # Playwright E2E 測試
├── composer.json              # PHP 依賴
└── phpcs.xml                  # PHPCS 設定
```

## 測試環境

測試站台：https://local-turbo.powerhouse.tw/wp-admin
透過 Cloudflare Tunnel 連線至本地測試伺服器。**若無法連線，請提請開發者開啟測試伺服器。**

登入憑證請參照專案根目錄的 `.env` 檔案（格式見 `.env.example`，不進版本控制）。

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
子外掛在 `init.php` 中根據 `get_option('wc_woomp_setting_*')` / `get_option('wc_woomp_enabled_*')` 的值條件式載入。每個金流服務商可從 WooCommerce > 設定 > 好用版擴充 獨立啟用或停用。

### Loader Pattern
`Woomp_Loader`（`includes/class-woomp-loader.php`）集中管理 WordPress action/filter 的註冊。透過 `add_action()`/`add_filter()` 方法收集 Hook，再於 `run()` 中批次註冊。

### 金流閘道模式
所有金流閘道繼承 `WC_Payment_Gateway`（或 `WC_Payment_Gateway_CC`）。PayUni v1 使用 `PAYUNI\Gateways\AbstractGateway`。PayUni v3 使用 PSR-4 命名空間 `J7\Payuni\`，搭配 DTO/Infrastructure 分層架構。

### HPOS 相容性

WooCommerce 7.1 起引入高效能訂單儲存（HPOS），訂單資料改存於專屬資料表而非 `wp_postmeta`。本外掛已完整宣告並實作 HPOS 相容。

**宣告相容**：在 `woomp.php` 的 `before_woocommerce_init` hook 中呼叫 `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)`。

**`Woomp_HPOS_Helper` 輔助類別**主要方法：
- `get_order( $post_id )` — 回傳 `WC_Order` 物件
- `get_order_meta( $order_or_id, $key, $single )` — 讀取訂單 meta
- `update_order_meta( $order_or_id, $key, $value )` — 寫入並自動 `save()`
- `delete_order_meta( $order_or_id, $key )` — 刪除訂單 meta

**訂單 Meta 操作原則**：禁止直接使用 `get_post_meta` / `update_post_meta`，一律透過 `WC_Order` 物件 API。

**Hook 遷移原則**：
- `save_post_shop_order` 已全面改為 `woocommerce_process_shop_order_meta`
- 後台訂單列表欄位、批次操作、Meta Box 均採用 HPOS 雙重註冊（傳統 post 型 + `woocommerce_shop_order_list_table_*` hook 並存）

## 常用指令

```bash
# 安裝 PHP 依賴
composer install

# 建置發佈用 ZIP
npm run build

# 執行 PHPCS 程式碼檢查
vendor/bin/phpcs

# 啟動 / 停止 wp-env 測試環境
npx @wordpress/env start
npx @wordpress/env stop
```

### PHPUnit 整合測試（快速參考）

```bash
# 煙霧測試（最快，適合 pre-commit）
npx @wordpress/env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --testsuite smoke'

# HPOS 完整測試
npx @wordpress/env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --testsuite hpos'

# 全部整合測試
npx @wordpress/env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox'
```

完整 testsuite / group / 輸出格式選項，見 `.claude/skills/woomp/references/testing.md`。

### E2E 測試（Playwright，快速參考）

```bash
cd tests/e2e
npm run test:happy-flow   # 核心 happy flow（最常用）
npm run test:p0           # 最高優先 @P0
npm run test:headed       # 有頭模式 debug
npm run report            # 開啟最後一次 HTML 報告
```

完整 npm scripts 與 Playwright CLI 組合，見 `.claude/skills/woomp/references/testing.md`。

## 相關文件指引

| 文件                                             | 用途                                              |
| ------------------------------------------------ | ------------------------------------------------- |
| `.claude/rules/wordpress-php.md`                 | WordPress / PHP 編碼規範（自動套用於 `**/*.php`） |
| `.claude/skills/payuni-embed/SKILL.md`           | PAYUNi Embed 支付整合知識                         |
| `.claude/skills/woomp/SKILL.md`                  | Woomp 專案開發完整指引                            |
| `.claude/skills/woomp/references/testing.md`     | PHPUnit + E2E 測試指令完整參考                    |

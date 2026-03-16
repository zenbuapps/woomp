# Woomp — WooCommerce 好用版擴充

## 專案概述

Woomp（好用版擴充 MorePower Addon for WooCommerce）是一個以**台灣市場**為核心的 WooCommerce 擴充外掛。將多家金流、物流與電子發票服務整合於單一外掛中，並透過設定驅動的模組系統進行管理。

- **版本**：3.4.81
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
| E2E 測試   | Playwright（TypeScript），位於 `e2e/`                                                                        | —                   |
| 依賴套件   | `oberonlai/wp-metabox`、`dennykuo/invoice-porter`、`guzzlehttp/guzzle`、`yahnis-elsts/plugin-update-checker` | ^6.5.8 / ^5.3       |

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

### HPOS 相容性

WooCommerce 7.1 起引入高效能訂單儲存（HPOS），訂單資料改存於專屬資料表而非 `wp_postmeta`。本外掛已完整宣告並實作 HPOS 相容。

**宣告相容**：在 `woomp.php` 的 `before_woocommerce_init` hook 中呼叫 `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)` 完成相容宣告。

**`Woomp_HPOS_Helper` 輔助類別**：集中提供訂單操作的 HPOS 相容 API，主要方法：
- `get_order( $post_id )` — 回傳 `WC_Order` 物件（取代直接讀取 post）
- `get_order_meta( $order_or_id, $key, $single )` — 讀取訂單 meta
- `update_order_meta( $order_or_id, $key, $value )` — 寫入訂單 meta 並自動呼叫 `$order->save()`
- `delete_order_meta( $order_or_id, $key )` — 刪除訂單 meta

**訂單 Meta 操作原則**：所有訂單相關的 meta 讀寫皆透過 `WC_Order` 物件 API（`get_meta` / `update_meta_data` + `save`），禁止直接使用 `get_post_meta` / `update_post_meta`。

**Hook 遷移原則**：
- `save_post_shop_order` 已全面改為 `woocommerce_process_shop_order_meta`
- 後台訂單列表的自訂欄位（column）、批次操作（bulk action）、Meta Box 均採用 HPOS 雙重註冊：傳統 post 型與 HPOS 的 `woocommerce_shop_order_list_table_*` hook 並存，確保兩種模式下功能正常。
- Meta Box 使用 `woocommerce_process_shop_order_meta` 並透過 `wc_get_order()` 取得訂單物件後操作。

## 常用指令

### 環境與建置

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

### PHPUnit 整合測試

基礎指令格式（需在 wp-env 容器中執行）：

```bash
npx @wordpress/env run tests-cli -- bash -c \
  'cd /var/www/html/wp-content/plugins/woomp && \
   WP_TESTS_DIR=/wordpress-phpunit vendor/bin/phpunit \
   --configuration tests/phpunit/phpunit.xml.dist \
   --no-coverage [參數]'
```

**按 testsuite 執行（建議方式）：**

```bash
# 全部整合測試（預設）
... # 不帶任何 --testsuite 參數

# 最精簡快速煙霧測試（~20 個測試，適合 pre-commit）
... --testsuite smoke

# HPOS 相容性（5 個類別，共 ~100 個測試）
... --testsuite hpos

# 金流相關（閘道 + 回調 + 加解密）
... --testsuite gateway
```

**按 @group 篩選（細粒度）：**

```bash
# 核心架構（初始化 + hooks）
... --group core

# 特定金流廠商
... --group payuni   # PayUni 相關
... --group ecpay    # 綠界相關
... --group paynow   # 立吉富相關

# 其他功能模組
... --group invoice       # 電子發票
... --group shipping      # 物流
... --group order-meta    # 訂單 Meta 讀寫（跨模組）
... --group email         # 郵件通知
... --group settings      # 設定持久化
... --group hpos-compat   # 全部 HPOS 相容性
```

**排除特定群組：**

```bash
# 排除 HPOS 掃描測試（較慢），只跑金流功能
... --group gateway --exclude-group hpos-compat
```

**輸出格式：**

```bash
# 顯示測試標題（每個 test method 的說明）
... --testdox

# 最精簡輸出（只顯示錯誤）
... --no-coverage --quiet

# 輸出 JUnit XML 報表（供 CI 解析）
... --log-junit /tmp/test-results.xml

# 遇到第一個失敗就停止
... --stop-on-failure

# 只跑上次失敗的測試
... --order-by=defects
```

**完整複製可用範例：**

```bash
# 煙霧測試（最快）
npx @wordpress/env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --testsuite smoke'

# HPOS 完整測試
npx @wordpress/env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --testsuite hpos'

# 僅跑 PayUni 相關
npx @wordpress/env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --group payuni'

# 全部整合測試（有 testdox 標題）
npx @wordpress/env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox'
```

### E2E 測試（Playwright）

所有 npm 指令需在 `tests/e2e/` 目錄執行：

```bash
cd tests/e2e
```

**核心業務 happy flow（排除 edge cases）：**

```bash
# 核心目錄（01~05 + A-checkout + B-invoice），最常用
npm run test:happy-flow

# 同上，有頭模式（本地可視化 debug）
npm run test:happy-headed

# 最精簡：核心目錄 × @P0 tag（適合 CI 快速驗證）
npm run test:happy-ci

# 全場 @P0 且非 @edge（tag 雙重篩選）
npm run test:happy
```

**按功能模組：**

```bash
npm run test:core       # 01-core：設定頁 + 閘道註冊
npm run test:checkout   # A-checkout：PayUni 結帳流程
npm run test:invoice    # B-invoice：發票載具 UI
npm run test:ecpay      # 04-ecpay：綠界測試
npm run test:paynow     # 05-paynow：立吉富測試
npm run test:admin      # 07-admin：後台管理
npm run test:frontend   # 08-frontend：前台 UI

# Edge cases / 邊緣案例
npm run test:error      # C-error
npm run test:edge       # D-edge-cases
npm run test:boundary   # E-boundary
```

**按優先級：**

```bash
npm run test:p0   # @P0 最高優先（核心 happy path）
npm run test:p1   # @P1 高優先
npm run test:p2   # @P2 中優先
```

**按 Playwright project：**

```bash
npm run test:payuni-embed   # A-H 目錄（PayUni Embed 舊版測試）
npm run test:integration    # 01-08 目錄（新版整合測試）
npm run test:all            # 全部跑
npx playwright test --project=happy-flow   # 核心業務 happy flow project
```

**開發 / debug 模式：**

```bash
npm run test:headed   # 有頭模式（可看到瀏覽器）
npm run test:debug    # 逐步 debug 模式
npm run report        # 開啟最後一次 HTML 報告
```

**直接使用 Playwright CLI 常用組合：**

```bash
# 跑特定 spec 檔案
npx playwright test tests/02-checkout/checkout-form.spec.ts

# 多 tag 篩選（P0 或 P1）
npx playwright test --grep "@P0|@P1"

# 排除 edge cases tag
npx playwright test --grep-invert "@edge|@boundary|@error"

# 組合：核心目錄 + P0 + 排除錯誤測試（最精簡 CI）
npx playwright test --grep @P0 --grep-invert "@edge|@boundary" tests/01-core/ tests/02-checkout/

# 本地模擬 CI（無頭強制）
npx playwright test --headed=false

# 失敗重試 2 次
npx playwright test --retries=2
```

## 相關文件指引

| 文件                                   | 用途                                              |
| -------------------------------------- | ------------------------------------------------- |
| `.claude/rules/wordpress.rule.md`      | WordPress / PHP 編碼規範（自動套用於 `**/*.php`） |
| `.claude/skills/payuni-embed/SKILL.md` | PayUni Embed 支付整合知識                         |
| `.claude/skills/woomp/SKILL.md`        | Woomp 專案開發指引                                |

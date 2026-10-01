# Woomp — WooCommerce 好用版擴充

## 專案概述

Woomp（好用版擴充 MorePower Addon for WooCommerce）是一個以**台灣市場**為核心的 WooCommerce 擴充外掛。將多家金流、物流與電子發票服務整合於單一外掛中，並透過設定驅動的模組系統進行管理。

- **授權**：GPL-2.0+
- **需求**：WooCommerce 7.1+、PHP 8.0+
- **主分支**：`master`
- **更新來源**：GitHub（`zenbuapps/woomp`），透過 Plugin Update Checker 自動更新

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

測試站台：https://local-test.powerhouse.tw/wp-admin
透過 wp-env（localhost:8888）+ Cloudflare Tunnel 提供對外連線。**若無法連線，請提請開發者啟動 wp-env 並開啟 Cloudflare Tunnel。**

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

ECPay（綠界）閘道基底 `RY_ECPay_Gateway_Base` 已實作後台退款（`supports[] = 'refunds'` + `process_refund()`）：信用卡類（`payment_type = Credit`）退款會同步呼叫綠界 `CreditDetail/DoAction` API，前置以 `QueryTradeInfo` 驗證交易狀態；非信用卡（ATM／超商代碼／超商條碼／WebATM）無線上退款 API，回傳 `WP_Error` 引導商家至綠界後台人工退款。

**退款 Action 採盲試降級序列**：綠界未公開 `DoAction` 的錯誤碼／錯誤訊息對照表，`QueryTradeInfo` 也不回關帳狀態，無法事先判斷交易階段（正式站實測失敗訊息為 `更新失敗.(error_amount_R)`，不含「未關帳」字樣）。官方已載明各狀態對應的 Action 且彼此互斥、狀態不符時只回失敗不產生副作用，故全額退款依序盲試 `R`（已關帳退刷）→ `N`（已授權未關帳放棄）→ `E`→`N`（已請款待關帳）；部分退款只有 `R` 可用（`N`／`E` 皆為全額語意）。`E` 成功但 `N` 失敗時交易停在「已授權未關帳」，會寫入 order note 要求商家至綠界後台人工收尾。

**綠界回應不可用 `parse_str()` 解析**：`QueryTradeInfo/V5` 回應的值完全不做 URL encode（中文、空白、字面 `+` 皆為原文），而 CheckMacValue 是用這些原始值簽的。`parse_str()` 會把字面 `+` 解成空白、把 `%xx` 解碼、把 key 的 `.`／空白換成 `_`，導致重算的驗章必定失敗（品名含 `+` 的訂單退款 100% 失效）。一律改用 `RY_ECPay_Gateway_Api::parse_response_body()`（`explode` 且不 urldecode）。測試中的綠界假回應同理**不可**用 `http_build_query()` 產生，否則測試全綠、正式站全滅。

### 結帳驗證不可依賴動態 `required`

`WC_Checkout::get_checkout_fields()` 會快取欄位，只要有其他外掛在 `woocommerce_checkout_init`（早於 `woocommerce_checkout_process`）讀過欄位，之後在 filter 裡依 `did_action('woocommerce_checkout_process')` 動態改 `required` 就不會生效；另外，沒勾「運送到不同地址」時，WC 會略過整組 shipping 欄位的驗證（issue #136）。因此：
- **必要的業務檢查**（例如超商門市必選）一律掛在 `woocommerce_after_checkout_validation`，直接讀 `$data`。參考 `RY_ECPay_Shipping::validate_cvs_store()`。
- **判斷「是否為結帳送出」**用 `RY_WT::is_checkout_submission()`（看 `woocommerce-process-checkout-nonce` 有沒有送上來），不要只靠 `did_action()`。

**離島超商規則**：只有 `onepage`／`twopage` 模式會由 Woomp 管理離島（提供勾選欄位，並依運送區域的離島郵遞區號判斷能不能送）；`default` 模式一律放行，以 WC 運送區域設定為準（`WooMP_Checkout::get_island_cvs_error()`）。

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

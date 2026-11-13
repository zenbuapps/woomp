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

### PayNow 回呼必須先驗來源

`/wc-api/paynow_payment(_offline)` 是公開網址，任何人都能 POST。三個 handler 取得訂單後一律先過 `PayNow_Payment_Response::is_authentic_callback()`：訂單付款方式為 `paynow-*`、`TotalPrice` 等於訂單金額、`PassCode = sha1(WebNo + OrderNo + TotalPrice + 交易密碼 + TranStatus)`（大小寫不拘，用 `hash_equals`）。官方文件中 ibon／FamiPort／icash 產生代碼時的 PassCode 不含 `TranStatus`（虛擬帳號文件寫含，但取號也一併容許不含），**只對非 S 接受**——取號回傳經過消費者瀏覽器，對 S 也接受就能拿來偽造付款完成。文件註明 `WebNo`「僅用信用卡交易時回傳」，所以回呼沒帶 `WebNo` 不拒絕，PassCode 改用站台設定的 WebNo 計算；有帶但不符才拒絕。驗證通過後還要過 `should_ignore_callback()`：S 只處理 `Woomp_Payment_Callback_Guard::can_mark_paid()` 的訂單（已取消／已退款收到合法 S 只寫訂單備註，不改狀態——`payment_complete()` 允許 cancelled → processing）；非 S 只處理 pending 訂單。找訂單優先用 `Note1`（下單時送的訂單 ID），沒帶才把 `OrderNo` 當 ID，兩者都必須 `get_order_number() === OrderNo`，否則拒絕。驗證失敗以 `error` 等級寫入 `paynow-payment` log（不受除錯開關影響）。

### LINE Pay 回呼必須先驗來源

`/wc-api/wc_gateway_linepay_handler` 是公開 GET 網址：
- **confirm／cancel**：reserve 時 `WC_Gateway_LINEPay::issue_callback_token()` 產生一次性 token 放進 confirmUrl／cancelUrl，訂單 meta 只存 sha256（`_linepay_callback_token_hash`），回呼以 `verify_callback_token()` 比對。沒有雜湊的訂單（升級前 reserve 的）一律拒絕。不用 order key：order key 也出現在信件與感謝頁網址，用途太廣。
- **confirm 鎖**：`acquire_confirm_lock()` 用 `INSERT IGNORE` 進 options（不是 `add_option()`），逾時 120 秒後以條件 UPDATE 搶回。拿到鎖後重新讀訂單，已不是 reserved 就不呼叫 API；已付款訂單進錯誤分支也不可改回 pending／on-hold。
- **顧客退款**：必須登入、是訂單本人、nonce（`get_customer_refund_nonce_action()`）正確、仍在可退款狀態與 60 天內、尚未退過款；退款金額一律是訂單剩餘可退金額，不讀 `cancel_amount`。先呼叫 LINE Pay 退款 API，成功後才 `wc_create_refund( refund_payment: false )`。
- 回呼結束要 `exit`（`redirect_and_exit()`），否則 WC 的 wc-api 會 `status_header(200)` 蓋掉 302。測試用 `woomp_linepay_callback_exit` filter 關掉 exit。

### PayNow 物流回呼的驗證方式

`/wc-api/paynow_choose_cvs_callback` 和 `/wc-api/paynow_shipping_order_callback` 都是公開網址（issue #146），驗證都在 `PayNow_Shipping_Response`：

- **後台改門市**：門市地圖導回是跨站 POST，瀏覽器可能不帶登入 cookie，所以不靠 `current_user_can()` 或 nonce，改用一次性 token。`get_cvs_change_return_url()` 產生 `?cvs_token=`，transient 存「token 雜湊 → 訂單 ID」，有效 12 小時。網址只帶 token，訂單由 transient 決定，所以舊版的 `order_id` 參數一律被拒絕。用 `delete_transient()` 的回傳值判斷誰消耗了 token，重複請求只有一筆會通過。
- **結帳選門市**：不帶 token，也不會更新既有訂單。帶了 `cvs_token` 或 `order_id` 就一定走驗證，不可落回結帳流程。
- **貨態更新**：只接受有 `LogisticNumber` 的訂單。網址帶每站獨立的 `?token=`，帶錯一律拒絕。收到第一筆 token 正確的回呼後自動鎖定（`paynow_shipping_status_callback_token_required`），之後沒帶 token 也拒絕。
- **舊網址（沒帶 token、尚未鎖定）**：不信任回呼內容，先用 `query_order()`（`Get_Order_Info`）向 PayNow 查證。查到的 `PayNowLogisticCode` 和回呼相同才更新，且 `Detail_Status_Description`、`paymentno` 以查詢結果為準；不同回 403，查詢失敗回 503。同一物流單的相同貨態只處理一次（`_paynow_shipping_last_status_callback`），重放不會蓋掉商家手動改的訂單狀態。查詢可由匿名請求觸發，逾時固定 10 秒。查無物流單時 PayNow 仍回 200，`PayNowLogisticCode` 是空字串（正式站實測）。
- **設定頁**：網址透過 `paynow_shipping_settings` filter 加入。實際載入的設定頁是 `Woomp_Setting_Shipping`；`PayNow_Shipping_Settings_Tab` 沒有被任何地方載入，改它不會有效果。
- **Log**：`PayNow_Shipping::log()` 的 `error`／`warning` 不受除錯開關影響。Log 不可包含 token。

PayNow 物流回呼的規格文件在商家後台內，對外查不到，是否有簽章欄位尚未確認。PayNow 查詢結果是否可能比回呼慢（導致真實貨態被判為不符）也尚未用真實物流單確認；若發生，商家改用帶 token 的網址就不會走查證。

### 綠界電子發票明細必須加總等於 SalesAmount

綠界在明細加總和 `SalesAmount` 對不上時會回 `RtnCode 1200031`。`EcpayInvoiceHandler::build_invoice_items()` 的商品、運費、費用都列**含稅**金額，折扣行**不自己重算稅額**，一律用「訂單總額 − 已列出的明細」的差額（issue #137：以前用 `get_total_tax()` 算，那是折扣後的稅，而且含運費稅，只在 WC 沒開稅金時才剛好對）。多數台灣商店沒開 WC 稅金，所以稅相關的 bug 平常看不到，測試要開稅金才測得出來（`EcpayInvoiceItemsTest`）。

### 結帳驗證不可依賴動態 `required`

`WC_Checkout::get_checkout_fields()` 會快取欄位，只要有其他外掛在 `woocommerce_checkout_init`（早於 `woocommerce_checkout_process`）讀過欄位，之後在 filter 裡依 `did_action('woocommerce_checkout_process')` 動態改 `required` 就不會生效；另外，沒勾「運送到不同地址」時，WC 會略過整組 shipping 欄位的驗證（issue #136）。因此：
- **必要的業務檢查**（例如超商門市必選）一律掛在 `woocommerce_after_checkout_validation`，直接讀 `$data`。參考 `RY_ECPay_Shipping::validate_cvs_store()`。
- **判斷「是否為結帳送出」**用 `RY_WT::is_checkout_submission()`（看 `woocommerce-process-checkout-nonce` 有沒有送上來），不要只靠 `did_action()`。
- **寫入門市也要看本次物流**：`woocommerce_checkout_create_order` 掛的是 `RY_ECPay_Shipping::save_checkout_cvs_info()`，本次不是綠界超取就不寫門市（issue #142：宅配訂單帶著殘留 `CVSStoreID` 時，運送地址會被換成門市地址）。`save_cvs_info()` 本身不檢查物流，因為後台改門市（`RY_ECPay_Shipping_Response::map_redirect()`）的資料沒有 `shipping_method`，會直接呼叫它。

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

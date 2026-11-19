# Woomp 測試指令完整參考

## PHPUnit 整合測試

基礎指令格式（需在 wp-env 容器中執行）：

```bash
npx @wordpress/env run tests-cli -- bash -c \
  'cd /var/www/html/wp-content/plugins/woomp && \
   WP_TESTS_DIR=/wordpress-phpunit vendor/bin/phpunit \
   --configuration tests/phpunit/phpunit.xml.dist \
   --no-coverage [參數]'
```

### 按 testsuite 執行（建議方式）

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

### 按 @group 篩選（細粒度）

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

### 排除特定群組

```bash
# 排除 HPOS 掃描測試（較慢），只跑金流功能
... --group gateway --exclude-group hpos-compat
```

### 輸出格式

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

### 完整複製可用範例

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

### 訂閱 suite（PayUni V3 定期定額，issue #147）

```bash
# 容器名稱先用 docker ps -a 確認（多個 wp-env 並存時 npx 會解析錯專案，見 woomp phpunit 容器陷阱）
MSYS_NO_PATHCONV=1 docker exec <woomp-tests-cli> sh -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --testsuite subscription'
```

`subscription` suite 的測試檔：

| 檔案 | 內容 |
| --- | --- |
| `SubscriptionV3BootstrapTest.php` | 閘道過濾（一般結帳空購物車移除訂閱閘道、換卡頁保留 V3 移除 V1、`woomp_payuni_allow_v1_change_payment`） |
| `SubscriptionV3PaymentTest.php` | `get_card_hash()` 續扣 token 優先序、`find_saved_token_id()` 不撿 V1 |
| `SubscriptionV3TokenBindingTest.php`（新） | 首購／續訂單付款成功後 token 綁到訂閱、換卡情境 localize 參數（`USE_TOKEN_TYPE=3`、`PAGE_CONTEXT`） |
| `SubscriptionV3PaymentMethodChangeTest.php`（新） | 換卡流程：同步／3D／webhook、偽造拒絕、冪等、HPOS 開關、續訂單 meta 剝除 |
| `SubscriptionV3MetaboxTest.php` | 訂閱後台 metabox |

注意事項：

- **bootstrap 現在會載入 WooCommerce Subscriptions**（`tests/phpunit/bootstrap.php`，在 WooCommerce 之後、woomp 之前）。檔案不存在時退回 `support/WcsFixture.php` + `WcsFunctionStubs.php` 的替身，測試仍可跑。
- **`WC_USE_TRANSACTIONS` 設為 `false`**：`wcs_create_renewal_order()` 經 `WCS_SQL_Transaction` 送出 `START TRANSACTION`，會隱含 `COMMIT` 掉 `WP_UnitTestCase` 的外層交易，測試建立的付款 token 與 HPOS 訂單因此永久殘留；下一輪 `wp_users` 重新編號，撞號的新客戶就「多出」別人的 token（偶發失敗，例如 T-N5「只有一張 token」）。bootstrap 另會在啟動時清掉 `woocommerce_payment_tokens`／`tokenmeta`。
- **測試中途切 HPOS**：WCS 的 `wcs_get_cached_users_subscription_ids` 快取在開機時就依 HPOS 狀態選定監聽的資料表，中途切換不會跟著換、會一直回空陣列，所以開啟 HPOS 的測試要用 `add_filter( 'wcs_get_cached_users_subscription_ids', '__return_empty_string' )` 略過快取（見 `set_hpos()`）。已有訂單後改 HPOS 選項會丟「未同步訂單」例外，tearDown 用 `delete_option` 還原。
- **Git Bash 跑 `docker exec` 要加 `MSYS_NO_PATHCONV=1`**，否則 `/var/www/...` 路徑會被轉成 Windows 路徑。

---

## E2E 測試（Playwright）

所有 npm 指令需在 `tests/e2e/` 目錄執行：

```bash
cd tests/e2e
```

### 核心業務 happy flow（排除 edge cases）

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

### 按功能模組

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

### 按優先級

```bash
npm run test:p0   # @P0 最高優先（核心 happy path）
npm run test:p1   # @P1 高優先
npm run test:p2   # @P2 中優先
```

### 按 Playwright project

```bash
npm run test:payuni-embed   # A-H 目錄（PayUni Embed 舊版測試）
npm run test:integration    # 01-08 目錄（新版整合測試）
npm run test:all            # 全部跑
npx playwright test --project=happy-flow   # 核心業務 happy flow project
```

### 開發 / debug 模式

```bash
npm run test:headed   # 有頭模式（可看到瀏覽器）
npm run test:debug    # 逐步 debug 模式
npm run report        # 開啟最後一次 HTML 報告
```

### 直接使用 Playwright CLI 常用組合

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

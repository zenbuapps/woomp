# Woomp PHPUnit 整合測試

PHP 整合測試，在真實 WordPress + WooCommerce 環境中驗證外掛初始化、Hook 註冊、金流閘道、加解密、Webhook 回調、HPOS 相容性等 PHP 內部邏輯。

## 環境需求

- Docker Desktop（運行中）
- Node.js 18+
- Composer（`composer install` 已執行）

## 快速開始

```bash
# 1. 啟動 wp-env Docker 測試環境
npx @wordpress/env start

# 2. 執行煙霧測試（最快回饋，~20 個測試）
npx @wordpress/env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --testsuite smoke'

# 3. 執行全部整合測試（~114 個測試）
npx @wordpress/env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox'

# 4. 停止環境
npx @wordpress/env stop
```

## Windows 注意事項

在 Git Bash / MSYS2 環境下，`npx @wordpress/env run` 的引號可能被吃掉。如果遇到指令失敗，改用 Docker 直接執行：

```bash
# 找到 tests-cli 容器名稱
docker ps --format '{{.Names}}' | grep tests-cli

# 直接在容器中執行（需加 MSYS_NO_PATHCONV=1 防止路徑轉換）
MSYS_NO_PATHCONV=1 docker exec <container-name> sh -c \
  'cd /var/www/html/wp-content/plugins/woomp && \
   WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit \
   --configuration tests/phpunit/phpunit.xml.dist \
   --no-coverage --testdox --testsuite smoke'
```

> Alpine 容器中需使用 `sh -c`（非 `bash -c`），且明確呼叫 `php vendor/bin/phpunit`。

## 按 Testsuite 執行

在基礎指令後加上 `--testsuite <name>`：

| Testsuite | 內容 | 測試數量 |
|-----------|------|---------|
| `smoke` | 核心初始化 + 閘道註冊 + HPOS 宣告 + 設定持久化 | ~20 |
| `hpos` | HPOS 相容性（宣告、Helper、Hook、OrderMeta、BugFix） | ~50 |
| `gateway` | 金流（閘道註冊 + 加解密 + Webhook + 回調 + 訂單 meta） | ~30 |
| `integration` | 全部（預設，不帶 `--testsuite` 即可） | ~114 |

```bash
# HPOS 測試
... --testsuite hpos

# 金流測試
... --testsuite gateway
```

## 按 @group 篩選

更細粒度的篩選，可組合使用：

```bash
# 核心架構
... --group core

# 特定金流廠商
... --group payuni
... --group ecpay
... --group paynow

# 功能模組
... --group invoice         # 電子發票
... --group shipping        # 物流
... --group order-meta      # 訂單 Meta
... --group email           # 郵件通知
... --group settings        # 設定持久化
... --group hpos-compat     # HPOS 相容性

# 排除特定群組
... --group gateway --exclude-group hpos-compat
```

## 輸出格式

```bash
# 顯示測試標題（推薦）
... --testdox

# 最精簡（只顯示錯誤）
... --no-coverage --quiet

# JUnit XML（CI 用）
... --log-junit /tmp/test-results.xml

# 遇到第一個失敗就停止
... --stop-on-failure

# 只跑特定測試類別
... --filter=CoreInitializationTest

# 只跑上次失敗的測試
... --order-by=defects
```

## 完整複製可用指令

```bash
# 煙霧測試（pre-commit 快速驗證）
npx @wordpress/env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --testsuite smoke'

# HPOS 完整測試
npx @wordpress/env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --testsuite hpos'

# 僅 PayUni 相關
npx @wordpress/env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --group payuni'

# 全部整合測試
npx @wordpress/env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox'

# 全部 + 第一個失敗就停止
npx @wordpress/env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --stop-on-failure'
```

## 測試檔案結構

```
tests/phpunit/
├── bootstrap.php                          # 測試引導程式
├── phpunit.xml.dist                       # PHPUnit 設定（testsuite 定義）
└── integration/
    ├── CoreInitializationTest.php          # 外掛常數、Autoloader、WC 依賴
    ├── HookRegistrationTest.php           # Hook/Filter 註冊驗證
    ├── GatewayRegistrationTest.php        # 金流 Gateway 類別驗證
    ├── PayuniEncryptionTest.php           # AES-256-GCM 加解密
    ├── PayuniWebhookTest.php              # PayUni Webhook 回調
    ├── EcpayCallbackTest.php              # ECPay CheckMacValue + 回調
    ├── PaynowCallbackTest.php             # PayNow PassCode + 回調
    ├── InvoiceHandlerTest.php             # 發票開立/作廢邏輯
    ├── ShippingMethodTest.php             # 運費計算、可用性檢查
    ├── OrderMetaTest.php                  # 訂單 meta 存取
    ├── SettingsPersistenceTest.php        # WP Options 讀寫
    ├── EmailNotificationTest.php          # Email 內容生成
    ├── HposDeclarationTest.php            # HPOS 相容宣告
    ├── HposHelperTest.php                 # Woomp_HPOS_Helper CRUD
    ├── HposHookRegistrationTest.php       # HPOS 雙重 Hook 註冊
    ├── HposOrderMetaTest.php              # HPOS 訂單 Meta 操作
    └── HposBugFixTest.php                 # HPOS 特定 Bug 修正
```

## wp-env 設定

`.wp-env.json` 配置：

- WordPress 6.8
- PHP 8.2
- WooCommerce latest-stable
- 自動掛載當前外掛目錄

## 注意事項

- 每個測試使用獨立的資料庫 transaction，測試後自動 rollback
- 不需要外部 API 連線（加解密、CheckMacValue 使用測試用金鑰）
- Webhook/回調測試使用 mock HTTP request
- 首次啟動 wp-env 需下載 Docker image，可能需要幾分鐘

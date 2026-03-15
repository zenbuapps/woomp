# Woomp PHPUnit Integration Tests

PHP 整合測試，驗證外掛初始化、Hook 註冊、金流閘道、加解密、Webhook 回調等 PHP 內部邏輯。

## 環境需求

- Docker Desktop
- Node.js 18+
- `@wordpress/env` (wp-env)

## 快速開始

```bash
# 1. 安裝 wp-env（全域）
npm install -g @wordpress/env

# 2. 安裝 PHP 測試依賴
composer install

# 3. 啟動 Docker 測試環境
npx wp-env start

# 4. 執行所有測試
npx wp-env run tests-cli --env-cwd=wp-content/plugins/woomp vendor/bin/phpunit

# 5. 執行特定測試
npx wp-env run tests-cli --env-cwd=wp-content/plugins/woomp vendor/bin/phpunit --filter=CoreInitializationTest

# 6. 停止環境
npx wp-env stop
```

## 測試檔案結構

```
tests/phpunit/
├── bootstrap.php              # 測試引導程式
├── phpunit.xml.dist           # PHPUnit 設定
└── integration/
    ├── CoreInitializationTest.php      # 外掛常數、Autoloader、WC 依賴
    ├── HookRegistrationTest.php        # Hook/Filter 註冊驗證
    ├── GatewayRegistrationTest.php     # 金流 Gateway 類別驗證
    ├── PayuniEncryptionTest.php        # AES-256-GCM 加解密
    ├── PayuniWebhookTest.php           # PayUni Webhook 回調
    ├── EcpayCallbackTest.php           # ECPay CheckMacValue + 回調
    ├── PaynowCallbackTest.php          # PayNow PassCode + 回調
    ├── InvoiceHandlerTest.php          # 發票開立/作廢邏輯
    ├── ShippingMethodTest.php          # 運費計算、可用性檢查
    ├── OrderMetaTest.php               # 訂單 meta 存取
    ├── SettingsPersistenceTest.php     # WP Options 讀寫
    └── EmailNotificationTest.php       # Email 內容生成
```

## 測試覆蓋範圍

| 測試類別 | 覆蓋功能 | 測試數量 |
|---------|---------|---------|
| CoreInitialization | 外掛載入、常數、Autoloader | ~8 |
| HookRegistration | Hook/Filter 註冊優先權 | ~5 |
| GatewayRegistration | 所有金流 Gateway 類別 | ~8 |
| PayuniEncryption | AES-256-GCM 加解密 | ~5 |
| PayuniWebhook | Webhook 回調處理 | ~5 |
| EcpayCallback | CheckMacValue + 回調 | ~5 |
| PaynowCallback | PassCode + 回調 | ~3 |
| InvoiceHandler | 發票開立/作廢 | ~4 |
| ShippingMethod | 運費/可用性 | ~3 |
| OrderMeta | 訂單 meta | ~3 |
| SettingsPersistence | Options 讀寫 | ~2 |
| EmailNotification | Email 內容 | ~2 |
| **合計** | | **~53** |

## 注意事項

- 測試使用 `wp-env` 提供的 WordPress 測試環境（Docker）
- 每個測試使用獨立的資料庫 transaction，測試後自動 rollback
- 不需要外部 API 連線（加解密、CheckMacValue 使用測試用金鑰）
- Webhook/回調測試使用 mock HTTP request

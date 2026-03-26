# 實作計劃：V3 定期定額閘道 V1/V3 共存策略重構

## 概述

將 V1/V3 定期定額閘道的共存控制從自訂 `payuni_subscription_version` option（互斥 select）改為 WooCommerce 原生閘道啟用/停用機制。移除版本切換邏輯，讓管理員可自由啟用任一或兩個閘道，並更新 method_title 以區分體驗差異。

## 範圍模式：HOLD SCOPE

Bug-fix / 重構性質，範圍已定。影響 5 個檔案，所有變更皆為刪減或簡化。

## 需求重述

1. **移除「定期定額版本」select 欄位**：`payuni_subscription_version` option 不再需要
2. **簡化 SubscriptionBootstrap**：移除 `filter_gateways_by_version()` 方法與 `VERSION_OPTION` 常數
3. **更新 method_title**：V3 閘道標題從 `v3` 改為 `免跳轉` 以利消費者區分
4. **更新測試**：刪除版本切換測試，新增 WC 原生啟用/停用測試

## 已知風險

- 低風險：已有使用者設定了 `payuni_subscription_version = v3` 選項，移除後該 option 變成孤兒資料。緩解措施：不主動刪除 DB 中的 option，僅移除 UI 與邏輯即可。

## 架構變更

```
移除/簡化                              更新
─────────────────────────────────      ──────────────────────
settings/gateway.php                   CreditSubscriptionV3.php
  - 移除 select 欄位                     - method_title 改名

SubscriptionBootstrap.php              SubscriptionV3BootstrapTest.php
  - 移除 VERSION_OPTION 常數             - 移除版本切換測試 x4
  - 移除 filter_gateways_by_version()    - 新增 WC 原生控制測試 x2
  - 簡化 conditional_payment_gateways()
                                       SubscriptionV3GatewayTest.php
                                         - 更新 method_title 斷言
```

## 資料流分析

```
woocommerce_available_payment_gateways filter
  │
  ▼
conditional_payment_gateways( $gateways )
  │
  ├─ get_cart_product_types()
  │    ├─ [nil] WC()->cart 為空 → 回傳 []
  │    └─ [normal] 回傳 product types 陣列
  │
  ├─ filter_gateways_by_cart( $gateways, $types )
  │    ├─ [has subscription] → 保留所有閘道
  │    └─ [no subscription] → unset V1 + V3 定期定額
  │
  └─ [已移除] filter_gateways_by_version() ← 本次刪除
```

## 實作步驟（TDD 流程）

### 第一階段：Red — 修改測試使其失敗

1. **更新 SubscriptionV3GatewayTest method_title 斷言**（檔案：`tests/phpunit/integration/SubscriptionV3GatewayTest.php`）
   - 行動：將 `assertStringContainsString('v3', ...)` 改為 `assertStringContainsString('免跳轉', ...)`
   - 原因：反映新的 method_title 命名
   - 依賴：無
   - 風險：低

2. **移除版本切換測試、新增 WC 原生控制測試**（檔案：`tests/phpunit/integration/SubscriptionV3BootstrapTest.php`）
   - 行動：
     - 刪除區塊 3「V1/V3 版本切換」的 4 個測試方法
     - 刪除 `tearDown()` 中的 `delete_option('payuni_subscription_version')`
     - 新增「雙閘道同時啟用時都顯示」測試
     - 新增「僅啟用 V3 時只顯示 V3」測試
   - 原因：版本切換邏輯將被移除，需改驗 WC 原生啟用/停用行為
   - 依賴：無
   - 風險：低

### 第二階段：Green — 修改程式碼使測試通過

3. **更新 CreditSubscriptionV3 method_title**（檔案：`includes/payuni/src/gateways/CreditSubscriptionV3.php`）
   - 行動：`method_title` 從 `'統一金流 PAYUNi 信用卡定期定額 v3'` 改為 `'統一金流 PAYUNi 信用卡定期定額（免跳轉）'`
   - 依賴：步驟 1
   - 風險：低

4. **簡化 SubscriptionBootstrap**（檔案：`includes/payuni/v3/Domains/Subscription/SubscriptionBootstrap.php`）
   - 行動：
     - 移除 `VERSION_OPTION` 常數
     - 移除 `filter_gateways_by_version()` 方法
     - 在 `conditional_payment_gateways()` 中移除對 `filter_gateways_by_version()` 的呼叫
   - 依賴：步驟 2
   - 風險：低

5. **移除設定欄位**（檔案：`includes/payuni/settings/gateway.php`）
   - 行動：移除 `payuni_subscription_version` select 欄位定義
   - 依賴：步驟 4
   - 風險：低

### 第三階段：Refactor — 清理

6. **更新 SubscriptionBootstrap 的 class docblock**
   - 行動：移除「V1/V3 版本共存機制」相關描述
   - 依賴：步驟 4
   - 風險：低

## 測試策略

- 整合測試：subscription testsuite（`--testsuite subscription`）
  - `SubscriptionV3BootstrapTest`：驗證條件顯示邏輯
  - `SubscriptionV3GatewayTest`：驗證閘道屬性
- 執行指令：`npx @wordpress/env run cli -- bash -c 'php /var/www/html/wp-content/plugins/woomp/vendor/bin/phpunit --configuration /var/www/html/wp-content/plugins/woomp/tests/phpunit/phpunit.xml.dist --no-coverage --testdox --testsuite subscription 2>/dev/null'`

## 成功標準

- [ ] `payuni_subscription_version` select 欄位已從設定頁移除
- [ ] `SubscriptionBootstrap` 不再有 `VERSION_OPTION` 常數和 `filter_gateways_by_version()` 方法
- [ ] `conditional_payment_gateways()` 僅調用 `filter_gateways_by_cart()`
- [ ] V3 閘道 `method_title` 為 `統一金流 PAYUNi 信用卡定期定額（免跳轉）`
- [ ] subscription testsuite 全部測試通過

## 預估複雜度：低

影響 5 個檔案，均為刪減或簡單修改。無新增邏輯，不涉及資料庫遷移。

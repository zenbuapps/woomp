# 實作計劃：PayUni V3 定期定額付款閘道

## 概述

為 Woomp 新增 PayUni V3 定期定額付款閘道（`payuni-credit-subscription-v3`），使用 UNi Embed iframe 技術處理首次付款及換卡，使用 server-side CreditHash + merchant_trade API 處理續扣。此閘道與 WooCommerce Subscriptions 整合，支援完整的訂閱生命週期管理，並與 V1 定期定額閘道共存。

## 範圍模式：EXPANSION（擴展）

全新功能，greenfield 開發。但核心架構大量複用 V3 一般信用卡（`CreditV3`）及 V1 定期定額（`CreditSubscription` + `Subscription`）的既有程式碼。

## 需求重述

1. **新 Gateway**：建立 `payuni-credit-subscription-v3` 閘道，宣告 WC Subscriptions 完整 supports
2. **首次付款**：複用 V3 UNi Embed iframe 交易流程，強制啟用 CreditToken（`UseTokenType=2`），不允許分期
3. **零元取 Token**：訂單金額 0 時，透過 iframe 扣 5 元取得 CreditHash，排程 2 分鐘取消授權
4. **已存 Token 直接啟用**：金額 0 且有有效 Token 時，直接 `payment_complete()` 不呼叫 API
5. **排程續扣**：使用 CreditHash + EncryptUtils 加密，POST 到 `/iframe/merchant_trade`（優先），fallback `/api/credit`
6. **Token 搜尋**：新 Token 用 V3 gateway_id，續扣時雙 gateway_id 搜尋（V3 優先、V1 fallback）
7. **失敗重試**：`failed -> pending`，由 WC Subscriptions 內建重試排程管理
8. **換卡**：利用 WC Subscriptions 內建換卡流程，導向結帳頁重新綁卡
9. **V1/V3 共存**：後台設定控制顯示哪一個
10. **分期限制**：訂閱付款不允許分期，前端隱藏分期選項

## 已知風險（來自研究）

| # | 風險 | 嚴重度 | 緩解措施 |
|---|------|--------|---------|
| R1 | `/iframe/merchant_trade` endpoint 在純 token 續扣（無 iframe session）時可能不支援 | 高 | 實作 fallback 到 `/api/credit`，測試階段先驗證 |
| R2 | V1 舊 Token 的 CreditHash 格式可能與 V3 API 不完全相容 | 中 | 雙 gateway_id 搜尋時記錄 log，觀察 V1 Hash 在 V3 API 的表現 |
| R3 | 5 元取消授權排程可能因 Action Scheduler 延遲或 cron 未執行而晚於 2 分鐘 | 低 | 使用 Action Scheduler（WC Subscriptions 依賴）而非 wp_cron，提高可靠度 |
| R4 | WC Subscriptions 外掛未安裝時載入 subscription gateway 會出錯 | 中 | 加入 `class_exists('WC_Subscriptions')` 前置檢查，與 V1 一致 |
| R5 | 3D 驗證流程中 webhook 回傳時，需正確儲存 CreditHash 到 Token | 中 | 在 webhook handler 加入 subscription gateway token 儲存邏輯 |

## 架構變更

### 新增檔案

| 檔案路徑 | 職責 |
|---------|------|
| `includes/payuni/src/gateways/CreditSubscriptionV3.php` | V3 定期定額 Gateway 類別（宣告 supports、process_payment、payment_fields） |
| `includes/payuni/v3/Domains/Subscription/SubscriptionHandler.php` | 訂閱核心邏輯：續扣執行、Token 搜尋、失敗處理、零元取消授權排程 |
| `includes/payuni/v3/Domains/Subscription/SubscriptionBootstrap.php` | 訂閱相關 hooks 註冊：排程扣款、失敗處理、條件顯示、V1/V3 共存 |

### 修改檔案

| 檔案路徑 | 修改內容 |
|---------|---------|
| `includes/payuni/src/apis/Payment.php` | 在 `$allowed_payments` 新增 `payuni-credit-subscription-v3` 閘道 |
| `includes/payuni/v3/Bootstrap.php` | 在 `save_payuni_card_hash()` 的 `$payuni_methods` 加入新 gateway_id |
| `includes/payuni/v3/Infrastructure/Http/TradeHandler.php` | `save_payment_token()` 增加 gateway_id 參數化支援；`update_order_status()` 增加訂閱 token 強制儲存邏輯 |

## 資料流分析

### 首次訂閱付款（金額 > 0）

```
消費者選擇 Gateway ──> payment_fields() ──> process_payment()
        │                    │                     │
        ▼                    ▼                     ▼
  [WCS 未安裝?]     [隱藏分期選項]          [組裝 TradeReqHashDTO]
  [非訂閱商品?]     [隱藏儲存 checkbox]      強制 UseTokenType=2
        │                    │              CreditToken=email
        ▼                    ▼                     │
   不顯示此閘道       顯示 iframe              ┌───▼───┐
                      顯示已存卡片            │ V3 API │
                                             │merchant│
                                             │_trade  │
                                             └───┬───┘
                                                 │
                                      ┌──────────┴──────────┐
                                      ▼                      ▼
                                 [SUCCESS]              [FAILURE]
                                      │                      │
                                      ▼                      ▼
                            payment_complete()      wc_add_notice(error)
                            save Token(CC)          保持 pending
                            + CreditHash
                            訂閱 active
```

### 零元取 Token（金額 = 0，無已存 Token）

```
process_payment() ──> 金額 = 0 判斷 ──> 無有效 Token
        │                                    │
        ▼                                    ▼
  [有已存 Token?] ── YES ──> 直接 payment_complete()
        │
       NO
        │
        ▼
  組裝 TradeAmt=5 + UseTokenType=2
  走 V3 merchant_trade API
        │
        ▼
  ┌─────┴─────┐
  ▼           ▼
SUCCESS    FAILURE
  │           │
  ▼           ▼
save Token  wc_add_notice
排程 2min   返回 failure
取消授權
payment_complete()
```

### 排程續扣

```
WC Subscriptions ──> woocommerce_scheduled_subscription_payment_payuni-credit-subscription-v3
        │
        ▼
  get_card_hash()
  搜尋順序: V3 Token → V1 Token
        │
  ┌─────┴─────┐
  ▼           ▼
 有 Hash    無 Hash
  │           │
  ▼           ▼
 組裝參數   payment_failed()
 EncryptUtils.encrypt()
 POST /iframe/merchant_trade
        │
  ┌─────┴─────────┐
  ▼               ▼
SUCCESS        FAILURE
  │               │
  ▼               ▼
 解密回應     fallback /api/credit
 payment_complete()    │
 儲存 meta    ┌───────┴───────┐
              ▼               ▼
           SUCCESS         FAILURE
              │               │
              ▼               ▼
        payment_complete()  訂單 failed
        儲存 meta           → pending
                            WCS 排程重試
```

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
|-----------|-------------|---------|---------|------------|
| `process_payment()` 首次付款 | PayUni API 回傳非 SUCCESS | 交易失敗 | `wc_add_notice()` + 訂單備註 | 是（結帳頁錯誤訊息） |
| `process_payment()` 首次付款 | SDK Token 取得失敗 | 初始化失敗 | `wc_add_notice()` + log | 是 |
| `process_payment()` 零元取 Token | 5 元扣款失敗 | 交易失敗 | `wc_add_notice()` + log | 是 |
| `cancel_authorization()` 零元取消 | API 呼叫失敗/逾時 | 網路錯誤 | log 記錄，不影響訂閱 | 否（背景） |
| `process_renewal_payment()` 續扣 | CreditHash 過期/無效 | 授權失敗 | `$order->update_status('failed')` + 備註 | 是（Email 通知） |
| `process_renewal_payment()` 續扣 | merchant_trade 不支援純 token | API 不支援 | fallback 到 `/api/credit` | 否（自動） |
| `process_renewal_payment()` 續扣 | 無可用 Token（V3+V1 都沒有） | 資料缺失 | `$order->update_status('failed')` + 備註 | 是 |
| `get_card_hash()` | 客戶無任何 Token | nil path | 回傳空字串，呼叫端處理 failed | 是（Email） |
| `subscription_fail_handler()` | 非本閘道訂單觸發 | 條件不符 | 直接 return，不處理 | 否 |

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
|-----------|---------|--------|--------|------------|---------|
| 首次付款 + 3D 驗證 | Webhook 未回傳 | 是 | E2E | 是（訂單 pending） | 等待 Webhook / 手動處理 |
| 零元取消授權排程 | Action Scheduler 延遲 | 是 | 手動 | 否 | 延遲執行不影響功能 |
| 零元取消授權 | API 失敗 | 是 | 手動 | 否 | Log 記錄，5元會自動過期 |
| 續扣 merchant_trade | endpoint 不支援純 token | 是 | 整合 | 否 | Fallback /api/credit |
| 續扣 fallback | /api/credit 也失敗 | 是 | 整合 | 是 | failed→pending→WCS重試 |
| 換卡 | 新卡無效 | 是 | E2E | 是（錯誤訊息） | 維持原卡 |
| V1 Token 在 V3 API | Hash 格式不相容 | 待測 | 待測 | 是 | V3 API 回傳失敗，WCS 重試 |

## 實作步驟

### 第一階段：Gateway 骨架與註冊（可獨立驗證）

**目標**：新閘道出現在 WooCommerce 後台設定 + 結帳頁面可見

#### 1.1 新增 CreditSubscriptionV3 Gateway 類別

**檔案**：`includes/payuni/src/gateways/CreditSubscriptionV3.php`

- **行動**：
  - 建立類別 `CreditSubscriptionV3 extends AbstractGateway`
  - 定義常數 `public const ID = 'payuni-credit-subscription-v3'`
  - 設定 `$this->id = self::ID`
  - 設定 `$this->method_title = '統一金流 PAYUNi 信用卡定期定額 v3'`
  - 設定 `$this->method_description`
  - 設定 `$this->has_fields = true`
  - 設定完整 `$this->supports` 陣列（參照 spec 的 12 個 supports）
  - `init_form_fields()`：enabled, title, description（不需 installment_options、不需 enable_tokenization，因為訂閱強制 token）
  - `process_payment()`：暫時回傳 failure，第二階段實作
  - `payment_fields()`：複用 CreditV3 的 iframe 容器渲染，但隱藏分期選項、隱藏「儲存付款方式」checkbox
  - `get_detail_after_order_table()`：複用 CreditV3 的邏輯

- **原因**：建立最小可行的 Gateway 骨架，確保閘道可被 WooCommerce 識別
- **依賴**：無
- **風險**：低

#### 1.2 註冊 Gateway 到 WooCommerce

**檔案**：`includes/payuni/src/apis/Payment.php`

- **行動**：
  - 在 `$allowed_payments` 陣列中加入：
    ```php
    \PAYUNI\Gateways\CreditSubscriptionV3::ID => \PAYUNI\Gateways\CreditSubscriptionV3::class,
    ```

- **原因**：讓 WooCommerce 知道這個新閘道
- **依賴**：步驟 1.1
- **風險**：低

#### 1.3 在 Bootstrap 註冊新 gateway_id 的暫存資料處理

**檔案**：`includes/payuni/v3/Bootstrap.php`

- **行動**：
  - 在 `save_payuni_card_hash()` 中的 `$payuni_methods` 陣列加入 `CreditSubscriptionV3::ID`

- **原因**：結帳時需要暫存 SDK Token 等前端資料到訂單 meta
- **依賴**：步驟 1.1
- **風險**：低

#### 1.4 驗證

- [ ] 在 WooCommerce > 設定 > 付款 可看到新閘道
- [ ] 啟用閘道後，結帳頁面可看到（前提：購物車有訂閱商品）
- [ ] 閘道設定頁面可正常保存

---

### 第二階段：條件顯示與 V1/V3 共存

**目標**：閘道只在正確條件下顯示，V1/V3 互斥控制

#### 2.1 新增 SubscriptionBootstrap Hook 註冊

**檔案**：`includes/payuni/v3/Domains/Subscription/SubscriptionBootstrap.php`

- **行動**：
  - 建立 `final class SubscriptionBootstrap`，命名空間 `J7\Payuni\Domains\Subscription`
  - 前置檢查：`if (!class_exists('WC_Subscriptions')) { return; }`
  - 靜態方法 `register_hooks()`：
    - `add_filter('woocommerce_available_payment_gateways', [self, 'conditional_payment_gateways'])`
    - `add_action('woocommerce_scheduled_subscription_payment_payuni-credit-subscription-v3', [self, 'process_renewal_payment'], 10, 2)` -- 第四階段實作
    - `add_action('woocommerce_subscription_renewal_payment_failed', [self, 'subscription_fail_handler'], 99, 2)` -- 第四階段實作

  - `conditional_payment_gateways($available_gateways)`：
    - 購物車不包含 subscription / variable-subscription 類型商品時 → `unset($available_gateways['payuni-credit-subscription-v3'])`
    - 購物車包含訂閱商品時 → 根據後台設定決定顯示 V1 或 V3
    - 後台設定 key：建議使用 `payuni_subscription_version` option（值為 `v1` 或 `v3`），預設 `v1`（向後相容）
    - 當設定為 `v3` 時：`unset($available_gateways['payuni-credit-subscription'])`
    - 當設定為 `v1` 時：`unset($available_gateways['payuni-credit-subscription-v3'])`

- **原因**：定期定額閘道僅在購物車有訂閱商品時才顯示，且 V1/V3 互斥
- **依賴**：步驟 1.1
- **風險**：低

#### 2.2 在 Bootstrap 或 init.php 呼叫 SubscriptionBootstrap

**檔案**：`includes/payuni/v3/Bootstrap.php`

- **行動**：
  - 在 `register_hooks()` 最末呼叫 `\J7\Payuni\Domains\Subscription\SubscriptionBootstrap::register_hooks()`

- **原因**：初始化訂閱相關 hooks
- **依賴**：步驟 2.1
- **風險**：低

#### 2.3 後台設定頁面新增版本選擇

**檔案**：需確認 PayUni 設定頁面位置（可能在 `includes/payuni/src/settings/` 或 WooCommerce 設定分頁）

- **行動**：
  - 在 PayUni 金流設定頁面加入「定期定額版本」下拉選單
  - 選項：`v1`（統一金流定期定額 V1，預設）、`v3`（統一金流定期定額 V3 - UNi Embed）
  - Option key：`payuni_subscription_version`

- **原因**：管理員可控制使用哪個版本的定期定額閘道
- **依賴**：步驟 2.1
- **風險**：低

#### 2.4 驗證

- [ ] 購物車只有一般商品時，不顯示 `payuni-credit-subscription-v3`
- [ ] 購物車有訂閱商品時，根據後台設定僅顯示 V1 或 V3
- [ ] 切換後台設定後，結帳頁面正確反映
- [ ] V1 定期定額功能不受影響

---

### 第三階段：首次付款（核心交易流程）

**目標**：消費者可透過 V3 閘道完成首次訂閱付款

#### 3.1 實作 CreditSubscriptionV3::payment_fields()

**檔案**：`includes/payuni/src/gateways/CreditSubscriptionV3.php`

- **行動**：
  - 輸出 description
  - 已儲存卡片選項：取得 gateway_id 為 `payuni-credit-subscription-v3` **和** `payuni-credit-subscription` 的 Token（用於零元訂閱的已存 Token 判斷）
  - iframe 容器（`put_card_no`, `put_card_exp`, `put_card_cvc`）— 複用 CreditV3 的 HTML 結構
  - **不輸出** 分期選項（`payuni-installment-options` div 不渲染）
  - **不輸出** 「儲存付款方式」checkbox（`token_type_checkbox_area` 不渲染）— 訂閱付款強制 token
  - 輸出隱藏欄位 `payuni_used_token_id`

- **原因**：訂閱付款的 UI 與一般信用卡不同：不分期、強制 token
- **依賴**：步驟 1.1
- **風險**：低

#### 3.2 實作 CreditSubscriptionV3::process_payment()

**檔案**：`includes/payuni/src/gateways/CreditSubscriptionV3.php`

- **行動**：
  - 取得訂單 `$order = wc_get_order($order_id)`
  - 取得訂單總額 `$order_total = (int) $order->get_total()`

  - **金額 > 0 的情況**（含註冊費）：
    1. 組裝 `TradeReqHashDTO`，但強制覆蓋：
       - `UseTokenType = 2`
       - `CreditToken = $order->get_billing_email()`
       - 移除 `CardInst`（不允許分期）
       - `payuni_save_card` 強制設為 true（讓 update_order_status 儲存 token）
    2. 透過 `TradeReqDTO::of()` 加密
    3. 透過 `TradeHandler::execute_trade()` 發送
    4. 處理回應：解密、`update_order_status()`
    5. 回傳 success + redirect

  - **金額 = 0 且有已存 Token 的情況**：
    1. 檢查使用者是否選擇了已存 Token
    2. 驗證 Token 存在且 gateway_id 為 V3 或 V1
    3. 直接 `$order->payment_complete()`
    4. `$order->add_order_note("持有 token 且訂單金額為 0，直接轉為處理中")`
    5. 回傳 success + redirect

  - **金額 = 0 且無已存 Token 的情況**（零元取 Token）：
    1. 走零元取 Token 流程（委派給 `SubscriptionHandler::process_zero_amount_token()`）

- **原因**：首次付款的核心邏輯，處理三種分支路徑
- **依賴**：步驟 3.1, 3.3, 3.4
- **風險**：中

#### 3.3 擴展 TradeReqHashDTO 支援訂閱模式

**行動方式**：不修改 `TradeReqHashDTO`，而是在 `CreditSubscriptionV3::process_payment()` 中直接組裝自訂 trade params array，再呼叫 `EncryptUtils::encrypt()` + `TradeReqDTO` 包裝。

**原因**：訂閱的 trade params 與一般信用卡大致相同，但需要強制 `UseTokenType=2`、移除 `CardInst`。透過在 gateway 層覆蓋參數比修改共用 DTO 更安全。

- **具體做法**：
  - 建立靜態方法 `self::build_subscription_trade_params(\WC_Order $order): array`
  - 此方法複用 `TradeReqHashDTO::of()` 的大部分邏輯，但：
    - 強制設定 `UseTokenType = 2`
    - 強制設定 `CreditToken = $order->get_billing_email()`
    - 不帶 `CardInst`
    - 強制 `payuni_save_card = true`（meta 層面）

- **依賴**：無
- **風險**：低

#### 3.4 新增 SubscriptionHandler 核心邏輯

**檔案**：`includes/payuni/v3/Domains/Subscription/SubscriptionHandler.php`

- **行動**：
  - 建立 `final class SubscriptionHandler`，命名空間 `J7\Payuni\Domains\Subscription`
  - 方法 `process_zero_amount_token(\WC_Order $order): array`：
    1. 組裝 TradeAmt=5 的 trade params（使用與首次付款相同的參數，但金額固定 5 元）
    2. 加密並呼叫 `TradeHandler::execute_trade()`
    3. 解密回應
    4. 若 SUCCESS：
       - 儲存 CreditHash 為 `WC_Payment_Token_CC`（gateway_id = `payuni-credit-subscription-v3`）
       - `$order->payment_complete()`
       - 排程 2 分鐘後取消授權（`SubscriptionHandler::schedule_cancel_authorization()`）
    5. 若 FAILURE：
       - `wc_add_notice()` 顯示錯誤
    6. 回傳 `['result' => 'success'/'failure', 'redirect' => ...]`

  - 方法 `schedule_cancel_authorization(string $trade_no): void`：
    1. 使用 `as_schedule_single_action(time() + 120, 'payuni_v3_cancel_zero_auth', ['trade_no' => $trade_no])`
    2. 對應的 handler 在 SubscriptionBootstrap 中註冊

  - 方法 `cancel_zero_authorization(string $trade_no): void`：
    1. 呼叫 `HttpClient::cancel_trade($trade_no)`
    2. Log 記錄結果（成功/失敗都只記錄，不影響訂閱）

  - 方法 `save_subscription_token(int $customer_id, string $card_hash, string $card_6no, string $card_4no, string $card_exp): void`：
    1. 複用 TradeHandler 的 token 儲存邏輯，但 gateway_id 改為 `CreditSubscriptionV3::ID`
    2. 檢查是否已存在相同 Token（避免重複）

- **原因**：集中管理訂閱特有的邏輯
- **依賴**：步驟 1.1
- **風險**：中

#### 3.5 修改 TradeHandler 支援自訂 gateway_id 的 Token 儲存

**檔案**：`includes/payuni/v3/Infrastructure/Http/TradeHandler.php`

- **行動**：
  - 將 `save_payment_token()` 方法的可見性改為 `public`（目前是 `private`）
  - 新增 `$gateway_id` 參數（預設為 `CreditV3::ID`），取代 hardcoded 的 `CreditV3::ID`
  - 在 `update_order_status()` 中，當訂單的 payment_method 為 `payuni-credit-subscription-v3` 時：
    - 強制 `$should_save_card = true`（訂閱必存 Token）
    - 呼叫 `save_payment_token()` 時傳入 `CreditSubscriptionV3::ID`

- **原因**：訂閱閘道的 Token 需要用不同的 gateway_id 儲存
- **依賴**：步驟 1.1
- **風險**：中（修改共用程式碼，需確保不影響一般信用卡流程）

#### 3.6 驗證

- [ ] 訂閱商品（含註冊費 > 0）結帳成功
- [ ] 交易後 `WC_Payment_Token_CC` 正確儲存，gateway_id = `payuni-credit-subscription-v3`
- [ ] Token 的 CreditHash 正確
- [ ] 訂閱狀態為 active
- [ ] 結帳頁面不顯示分期選項
- [ ] 結帳頁面不顯示「儲存付款方式」checkbox
- [ ] 3D 驗證流程正常（webhook 回傳後正確儲存 Token）
- [ ] 零元訂閱的 5 元扣款成功
- [ ] 2 分鐘後 5 元授權被取消
- [ ] 已存 Token + 金額 0 直接 payment_complete()

---

### 第四階段：排程續扣

**目標**：WC Subscriptions 排程觸發時，使用 CreditHash 自動扣款

#### 4.1 實作 get_card_hash() 雙 gateway_id 搜尋

**檔案**：`includes/payuni/v3/Domains/Subscription/SubscriptionHandler.php`

- **行動**：
  - 方法 `get_card_hash(\WC_Order $order): string`：
    1. 取得 `$customer_id = $order->get_customer_id()`
    2. 先搜尋 `WC_Payment_Tokens::get_customer_tokens($customer_id, CreditSubscriptionV3::ID)`
    3. 若有結果，取最後一個（最新的），回傳 `$token->get_token()`
    4. 若無結果，再搜尋 `WC_Payment_Tokens::get_customer_tokens($customer_id, 'payuni-credit-subscription')`（V1 gateway_id）
    5. 若有結果，取最後一個，回傳 `$token->get_token()`
    6. 若都沒有，回傳 `''`
    7. 每次搜尋都記錄 log

- **原因**：V3 需要向後相容 V1 的 Token
- **依賴**：步驟 1.1
- **風險**：低

#### 4.2 實作 process_renewal_payment()

**檔案**：`includes/payuni/v3/Domains/Subscription/SubscriptionHandler.php`

- **行動**：
  - 方法 `process_renewal_payment(float $amount, \WC_Order $order): void`：
    1. 呼叫 `get_card_hash($order)` 取得 CreditHash
    2. 若 CreditHash 為空：
       - `$order->update_status('failed', '找不到有效的信用卡 Token，無法續扣')`
       - return
    3. 組裝交易參數（不需 SDK Token，不需 3D 驗證）：
       ```php
       $args = [
           'MerID'       => $setting->merchant_id,
           'MerTradeNo'  => $order->get_order_number(),
           'TradeAmt'    => (int) $amount,
           'Timestamp'   => time(),
           'UsrMail'     => $order->get_billing_email(),
           'ProdDesc'    => 訂閱商品描述,
           'CreditToken' => $order->get_billing_email(),
           'CreditHash'  => $card_hash,
           'UseTokenType' => 2,
       ];
       ```
    4. 加密：`EncryptUtils::encrypt($args)` + `EncryptUtils::hash_info()`
    5. 組裝 request body（MerID, Version, EncryptInfo, HashInfo）
    6. 嘗試 POST 到 `/iframe/merchant_trade`：
       - 使用 `wp_remote_post()` 直接呼叫
       - 若成功且回應 Status = SUCCESS → 走成功流程
       - 若失敗 → fallback 到 `/api/credit`（同樣的加密參數）
    7. 成功處理：
       - 解密回應
       - `$order->payment_complete($trade_no)`
       - 儲存 meta（`_payuni_resp_status`, `_payuni_resp_trade_no`, `_payuni_v3_resp`）
       - 訂單備註
    8. 失敗處理：
       - `$order->update_status('failed')`
       - 訂單備註記錄失敗原因

- **原因**：核心續扣邏輯，必須在 server-side 完成（無 iframe）
- **依賴**：步驟 4.1
- **風險**：高（需驗證 merchant_trade 是否支援純 token 續扣）

#### 4.3 實作 subscription_fail_handler()

**檔案**：`includes/payuni/v3/Domains/Subscription/SubscriptionBootstrap.php`

- **行動**：
  - 方法 `subscription_fail_handler(\WC_Subscription $subscription, \WC_Order $last_order): void`：
    1. 檢查 `$last_order->get_status() !== 'failed'` → return
    2. 檢查 `$last_order->get_payment_method() !== CreditSubscriptionV3::ID` → return
    3. 檢查 `$last_order->get_meta('_payuni_resp_status') === 'SUCCESS'` → return（已成功不處理）
    4. `$last_order->update_status('pending')` — 允許 WCS 重新排程扣款

- **原因**：與 V1 邏輯一致，failed 改為 pending 允許重試
- **依賴**：步驟 2.1
- **風險**：低

#### 4.4 在 SubscriptionBootstrap 中連接 hooks

**檔案**：`includes/payuni/v3/Domains/Subscription/SubscriptionBootstrap.php`

- **行動**：
  - `register_hooks()` 中加入：
    ```php
    $handler = new SubscriptionHandler();
    \add_action(
        'woocommerce_scheduled_subscription_payment_payuni-credit-subscription-v3',
        [$handler, 'process_renewal_payment'],
        10,
        2
    );
    \add_action(
        'woocommerce_subscription_renewal_payment_failed',
        [__CLASS__, 'subscription_fail_handler'],
        99,
        2
    );
    \add_action(
        'payuni_v3_cancel_zero_auth',
        [SubscriptionHandler::class, 'cancel_zero_authorization']
    );
    ```

- **原因**：連接 WC Subscriptions 的排程 hook 到 V3 處理邏輯
- **依賴**：步驟 4.2, 4.3, 3.4
- **風險**：低

#### 4.5 驗證

- [ ] 手動觸發 `woocommerce_scheduled_subscription_payment_payuni-credit-subscription-v3` → 續扣成功
- [ ] 續扣後訂單狀態為 processing
- [ ] 訂單 meta 正確儲存（`_payuni_resp_status`, `_payuni_resp_trade_no`, `_payuni_v3_resp`）
- [ ] CreditHash 無效時 → 訂單 failed → 自動改為 pending
- [ ] 無 Token 時 → 訂單 failed + 備註說明
- [ ] merchant_trade fallback 到 /api/credit 正常運作
- [ ] V1 Token 的 CreditHash 可在 V3 續扣中使用

---

### 第五階段：換卡與邊界情況

**目標**：換卡流程正常，各種邊界情況都有處理

#### 5.1 換卡流程支援

**分析**：WC Subscriptions 的換卡流程（My Account > Subscriptions > 更改付款方式）會導向結帳頁面。結帳頁面已有 UNi Embed SDK（Bootstrap 已載入）。消費者在結帳頁輸入新卡，V3 閘道的 `process_payment()` 執行時會取得新的 CreditHash 並儲存新 Token。

**行動**：
  - 確認 `process_payment()` 在換卡場景下正確運作（WCS 換卡時也會呼叫 process_payment）
  - 換卡時金額通常為 0（WCS 不收費），此時走「已存 Token 直接啟用」或「零元取 Token」邏輯
  - 需要特別處理的情況：換卡時消費者必須輸入**新卡**，不能選已存卡片

- **依賴**：步驟 3.2
- **風險**：中

#### 5.2 處理 CREDIT04001 重複訂單編號

**檔案**：`includes/payuni/src/gateways/CreditSubscriptionV3.php`

- **行動**：
  - 在 `process_payment()` 中，若 PayUni 回傳 `IFTRADE01006`（已存在相同商店訂單編號）：
    - 使用 `woomp_copy_order()` 建立新訂單
    - 更新訂閱的 parent_id
    - 重新呼叫 `process_payment()`
  - 此邏輯與 V1 `CreditSubscription::process_payment()` 一致

- **原因**：PayUni 不允許 10 分鐘內重複訂單編號
- **依賴**：步驟 3.2
- **風險**：中

#### 5.3 Webhook 訂閱 Token 儲存

**檔案**：`includes/payuni/v3/Bootstrap.php` 或 `TradeHandler.php`

- **行動**：
  - 在 `handle_notify()` / `update_order_status()` 中，當訂單的 `payment_method` 為 `payuni-credit-subscription-v3` 時：
    - 強制儲存 Token（即使前端沒有勾選「儲存卡片」）
    - Token 的 gateway_id 設為 `CreditSubscriptionV3::ID`
  - 此邏輯已在步驟 3.5 中透過修改 `update_order_status()` 實現

- **原因**：3D 驗證流程中，交易結果由 webhook 回傳，此時需要儲存 CreditHash
- **依賴**：步驟 3.5
- **風險**：低（已在步驟 3.5 覆蓋）

#### 5.4 驗證

- [ ] My Account > Subscriptions > 更改付款方式 → 導向結帳頁
- [ ] 換卡成功後，下次續扣使用新的 CreditHash
- [ ] 換卡失敗時維持原卡
- [ ] 重複訂單編號時正確建立新訂單
- [ ] 3D 驗證 webhook 正確儲存 Token

---

### 第六階段：整合測試與收尾

**目標**：全流程端對端測試，確保品質

#### 6.1 PHPUnit 整合測試

- **測試項目**：
  - `SubscriptionHandler::get_card_hash()` 雙 gateway_id 搜尋邏輯
  - `SubscriptionHandler::process_renewal_payment()` 參數組裝正確性
  - `subscription_fail_handler()` 條件判斷
  - `conditional_payment_gateways()` 篩選邏輯

#### 6.2 E2E 測試（Playwright）

- **測試項目**：
  - 首次訂閱付款（含註冊費）happy flow
  - 零元訂閱取 Token
  - 續扣成功
  - 結帳頁面不顯示分期選項
  - 結帳頁面不顯示「儲存付款方式」checkbox
  - 購物車無訂閱商品時閘道不顯示

#### 6.3 文件更新

- **行動**：
  - 更新 `.claude/skills/payuni-embed/SKILL.md`，加入 V3 定期定額相關知識
  - 更新 `.claude/CLAUDE.md`（如有需要）

---

## PSR-4 Autoload 確認

新增的檔案都在既有的 autoload 範圍內：

| 檔案 | 命名空間 | Autoload 機制 |
|------|---------|-------------|
| `includes/payuni/src/gateways/CreditSubscriptionV3.php` | `PAYUNI\Gateways` | a7/autoload（已配置） |
| `includes/payuni/v3/Domains/Subscription/SubscriptionBootstrap.php` | `J7\Payuni\Domains\Subscription` | Composer PSR-4 `J7\Payuni\` → `includes/payuni/v3/` |
| `includes/payuni/v3/Domains/Subscription/SubscriptionHandler.php` | `J7\Payuni\Domains\Subscription` | Composer PSR-4 |

需要確認 `includes/payuni/v3/Domains/` 目錄是否已存在或需要建立。

## 測試策略

### 單元測試

| 測試檔案 | 覆蓋範圍 |
|---------|---------|
| `tests/phpunit/Subscription/GetCardHashTest.php` | `get_card_hash()` 雙 gateway_id 搜尋、優先序、空值處理 |
| `tests/phpunit/Subscription/FailHandlerTest.php` | `subscription_fail_handler()` 各條件分支 |
| `tests/phpunit/Subscription/ConditionalGatewayTest.php` | `conditional_payment_gateways()` 篩選邏輯 |

### 整合測試

| 測試情境 | 測試方式 |
|---------|---------|
| 首次付款 + Token 儲存 | wp-env + PayUni Sandbox |
| 零元取 Token + 取消授權排程 | wp-env + Action Scheduler 驗證 |
| 續扣（merchant_trade + fallback） | wp-env + PayUni Sandbox |
| V1 Token 在 V3 續扣使用 | wp-env + 手動建立 V1 Token |

### E2E 測試

| 測試情境 | 優先度 |
|---------|-------|
| 訂閱首次付款 happy flow | P0 |
| 零元訂閱 | P0 |
| 閘道條件顯示 | P1 |
| 換卡流程 | P1 |
| 續扣成功 | P0（需手動觸發排程） |

## 依賴項目

| 依賴 | 狀態 | 備註 |
|------|------|------|
| WooCommerce Subscriptions 外掛 | 需確認已安裝 | 測試環境需要 |
| Action Scheduler | WCS 內建 | 用於零元取消授權排程 |
| PayUni Sandbox 帳號 | 已有 | 參照 `.env` |
| V3 merchant_trade 純 token 支援 | **待驗證** | R1 風險 |

## 風險與緩解措施

- **高**：`/iframe/merchant_trade` 純 token 續扣不支援 → 實作 fallback `/api/credit`，第四階段優先測試此路徑
- **中**：修改 `TradeHandler::save_payment_token()` 影響一般信用卡 → 參數化 gateway_id，預設值維持 `CreditV3::ID` 不影響既有行為
- **中**：V1 CreditHash 在 V3 API 不相容 → 雙 gateway_id 搜尋有 log，可快速診斷；V1 Token 不遷移
- **低**：Action Scheduler 排程延遲 → 5 元授權會自然過期，不影響訂閱功能

## 限制條件

1. **不做 V1 Token 遷移**：V1 舊 Token 保持原 gateway_id，透過雙搜尋機制向後相容
2. **不做分期 + 訂閱**：明確不支援，前端隱藏分期選項
3. **不做 PayUni 端的訂閱通知**：暫停/恢復/取消不通知 PayUni API，與 V1 一致
4. **不做 My Account 頁面額外 SDK 載入**：換卡導向結帳頁，複用已有的 SDK
5. **不修改 V1 定期定額程式碼**：V1 保持原樣，僅透過 V1/V3 互斥控制共存

## 預估複雜度：中

核心邏輯大量複用既有 V1 和 V3 程式碼。主要複雜點在於：
1. 三種首次付款路徑（金額>0、金額=0有Token、金額=0無Token）
2. 續扣的 merchant_trade + fallback 機制
3. 雙 gateway_id Token 搜尋

---

## Sub-Issue 拆分（Agent 路由）

### Issue 1: Gateway 骨架 + 註冊 + 條件顯示（第一、二階段）

```markdown
## 執行 Agent

> 請建立一個代理團隊來進行任務。我需要 2 個隊員，請分別使用 **`@wp-workflows:wordpress-master`** 和 **`@wp-workflows:wordpress-master`** 這 2 個 agent 來擔任隊員。
> worktree 共用一個。

---

## 實作計劃

### 階段 1：Gateway 骨架
- [ ] 新增 CreditSubscriptionV3.php Gateway 類別
- [ ] 在 Payment.php 註冊 Gateway
- [ ] 在 Bootstrap.php 註冊暫存資料處理

### 階段 2：條件顯示
- [ ] 新增 SubscriptionBootstrap.php
- [ ] 實作 conditional_payment_gateways
- [ ] 新增後台版本選擇設定

## 驗收條件
- [ ] WooCommerce 後台可看到新閘道並啟用
- [ ] 購物車有訂閱商品 + 設定 V3 時顯示新閘道
- [ ] 購物車無訂閱商品時不顯示
- [ ] V1 功能不受影響
```

### Issue 2: 首次付款 + 零元取 Token（第三階段）

```markdown
## 執行 Agent

> 請建立一個代理團隊來進行任務。我需要 2 個隊員，請分別使用 **`@wp-workflows:wordpress-master`** 和 **`@wp-workflows:wordpress-master`** 這 2 個 agent 來擔任隊員。
> worktree 共用一個。

---

## 實作計劃

### 階段 1：後端核心
- [ ] 實作 payment_fields()
- [ ] 實作 process_payment() 三條路徑
- [ ] 新增 SubscriptionHandler 核心邏輯
- [ ] 修改 TradeHandler 支援自訂 gateway_id

### 階段 2：零元取 Token
- [ ] 實作 process_zero_amount_token()
- [ ] 實作 schedule_cancel_authorization()
- [ ] 實作 cancel_zero_authorization()

## 驗收條件
- [ ] 含註冊費訂閱結帳成功
- [ ] Token 正確儲存（gateway_id 正確）
- [ ] 零元訂閱扣 5 元取 Token 成功
- [ ] 2 分鐘後取消授權
- [ ] 已存 Token + 金額 0 直接完成
- [ ] 3D 驗證流程 Token 正確儲存
```

### Issue 3: 排程續扣 + 失敗處理（第四階段）

```markdown
## 執行 Agent

> 請建立一個代理團隊來進行任務。我需要 1 個隊員，請使用 **`@wp-workflows:wordpress-master`** agent 來擔任隊員。

---

## 實作計劃

- [ ] 實作 get_card_hash() 雙 gateway_id 搜尋
- [ ] 實作 process_renewal_payment() + fallback
- [ ] 實作 subscription_fail_handler()
- [ ] 連接 WCS hooks

## 驗收條件
- [ ] 手動觸發續扣 → 扣款成功
- [ ] 訂單 meta 正確
- [ ] 無 Token 時失敗處理正確
- [ ] failed → pending 重試機制正常
- [ ] V1 Token 可在 V3 續扣使用
```

### Issue 4: 換卡 + 邊界情況 + 測試（第五、六階段）

```markdown
## 執行 Agent

> 請建立一個代理團隊來進行任務。我需要 2 個隊員，請分別使用 **`@wp-workflows:wordpress-master`** 和 **`@wp-workflows:wordpress-master`** 這 2 個 agent 來擔任隊員。

---

## 實作計劃

- [ ] 確認換卡流程正常
- [ ] 處理 IFTRADE01006 重複訂單
- [ ] PHPUnit 測試
- [ ] E2E 測試
- [ ] 文件更新

## 驗收條件
- [ ] 換卡流程端對端正常
- [ ] 重複訂單編號正確處理
- [ ] PHPUnit 測試全部通過
- [ ] E2E P0 測試通過
```

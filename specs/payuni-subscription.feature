Feature: PayUni v1 信用卡訂閱（定期定額）付款
  As a 消費者
  I want 使用信用卡訂閱定期定額服務
  So that 我的訂閱可以自動續費不需手動操作

  Background:
    Given WooCommerce Subscriptions 外掛已啟用
    And PayUni 金流已啟用
    And 信用卡定期定額閘道已啟用（payuni-credit-subscription enabled = yes）
    And 購物車中包含訂閱類型商品

  Rule: 訂閱首次付款

    Scenario: 消費者首次訂閱付款成功（含註冊費）
      Given 訂閱商品包含 50 元的註冊費
      When 消費者輸入信用卡資料並下單
      Then 系統以訂單總額扣款
      And 系統強制設定 save_new_card = 'yes'
      And 交易參數包含 CreditToken（客戶 email）
      And PayUni 回傳成功，包含 CreditHash
      And 系統自動儲存 WC_Payment_Token_CC（gateway_id: payuni-credit-subscription）
      And 訂閱狀態為 active

    Scenario: 消費者首次訂閱付款成功（無註冊費，需 5 元取 Token）
      Given 訂閱商品沒有註冊費（訂單金額為 0）
      And 消費者沒有已儲存的信用卡 Token
      When 消費者輸入信用卡資料並下單
      Then 系統走 build_hash_request 流程，扣款 5 元以取得 CreditHash
      And 系統排程 2 分鐘後取消 5 元授權交易
      And CreditHash 儲存為 WC_Payment_Token_CC
      And 訂閱狀態為 active

    Scenario: 消費者首次訂閱付款（無註冊費，已有 Token）
      Given 訂單金額為 0
      And 消費者已有 payuni-credit-subscription 的 Token
      When 消費者選擇已存卡片並下單
      Then 系統直接呼叫 payment_complete()
      And 訂單備註記錄「持有 token 且訂單金額為 0，直接轉為處理中」
      And 無需發送 PayUni API 請求

  Rule: 定期自動扣款

    Scenario: 定期扣款成功
      Given 訂閱已啟用且消費者有有效的 CreditHash
      When WooCommerce Subscriptions 排程觸發定期扣款
      Then SubscriptionHandler::process_subscription_payment() 被呼叫
      And RequestBuilder::build_subscription_request() 使用已存的 CreditHash
      And 交易參數不包含 API3D（定期扣款不需 3D 驗證）
      And PayUni API 回傳成功
      And 訂單狀態更新為 processing

    Scenario: 定期扣款失敗後自動重試
      Given 定期扣款失敗
      When WooCommerce Subscriptions 觸發 woocommerce_subscription_renewal_payment_failed
      And 訂單狀態為 failed
      And PayUni 回應狀態非 SUCCESS
      Then SubscriptionHandler 將訂單狀態改回 pending
      And 允許下次排程重新扣款

  Rule: CreditHash 取得邏輯

    Scenario: 使用用戶預設 Token
      Given 消費者的預設 Token gateway_id 為 payuni-credit-subscription
      When 定期扣款需要取得 CreditHash
      Then 系統使用消費者的預設 Token

    Scenario: 使用最新的 Token
      Given 消費者的預設 Token gateway_id 不是 payuni-credit-subscription
      When 定期扣款需要取得 CreditHash
      Then 系統取得 payuni-credit-subscription 的所有 Token
      And 使用最後一筆（最新）Token 的 CreditHash

  Rule: 閘道條件顯示

    Scenario: 非訂閱商品不顯示定期定額閘道
      Given 購物車中僅包含一般商品（非 subscription 類型）
      When 消費者進入結帳頁面
      Then payuni-credit-subscription 閘道不出現在付款方式列表中

    Scenario: 訂閱商品顯示定期定額閘道
      Given 購物車中包含 subscription 類型商品
      When 消費者進入結帳頁面
      Then payuni-credit-subscription 閘道出現在付款方式列表中

  Rule: 支援的 WooCommerce Subscriptions 功能

    Scenario: 閘道宣告支援的 Subscriptions 功能
      Then payuni-credit-subscription 閘道 supports 陣列包含：
        | 功能名稱                                          |
        | subscriptions                                     |
        | subscription_cancellation                         |
        | subscription_suspension                           |
        | subscription_reactivation                         |
        | subscription_amount_changes                       |
        | subscription_date_changes                         |
        | subscription_payment_method_change                |
        | subscription_payment_method_change_customer       |
        | subscription_payment_method_change_admin          |
        | multiple_subscriptions                            |
        | tokenization                                      |

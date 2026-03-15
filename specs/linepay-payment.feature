Feature: LINE Pay 金流付款
  As a 消費者
  I want 透過 LINE Pay 完成線上付款
  So that 我可以使用 LINE Pay 錢包快速支付訂單並在需要時退款

  Background:
    Given 網站已啟用 LINE Pay（woocommerce_linepay_enabled = yes）
    And LINE Pay Channel ID 和 Channel Secret Key 已設定
    And 環境設定為 sandbox 或 real

  Rule: 閘道註冊

    Scenario: LINE Pay 閘道正確註冊
      Given LINE Pay 已啟用
      When WooCommerce 載入付款閘道
      Then 應註冊 linepay 閘道
      And 應支援 products 和 refunds

  Rule: Reserve（預約付款）

    Scenario: Reserve 請求成功
      Given Customer 選擇 LINE Pay 付款並提交訂單
      When 呼叫 LINE Pay Reserve API（/v3/payments/request）
      Then 應使用 HMAC-SHA256 簽章
      And 回應應包含 transactionId 和 payment_url
      And _linepay_payment_status 應為 reserved
      And Customer 應被重導至 LINE Pay 付款頁面

    Scenario: Reserve 請求失敗
      Given LINE Pay API 回傳錯誤
      When 處理 Reserve 結果
      Then 訂單狀態應為 failed

  Rule: Confirm（確認付款）

    Scenario: Confirm 成功
      Given Customer 在 LINE Pay 完成付款
      When LINE Pay 回調 request_type=confirm
      Then 應呼叫 Confirm API（/v3/payments/{transactionId}/confirm）
      And _linepay_payment_status 應為 confirmed
      And 訂單應完成付款（payment_complete）

    Scenario: Confirm 金額不符自動退款
      Given Reserve 金額與 Confirm 金額不同
      When Confirm 完成
      Then 應自動發起退款（process_refund_after_confirm）

  Rule: Cancel（取消付款）

    Scenario: Customer 取消 LINE Pay 付款
      Given Customer 在 LINE Pay 頁面點擊取消
      When LINE Pay 回調 request_type=cancel
      Then _linepay_payment_status 應為 cancelled
      And 訂單狀態應為 failed 或 cancelled

  Rule: Refund（退款）

    Scenario: Admin 發起退款
      Given 訂單已確認付款（confirmed）
      When Admin 在後台發起退款
      Then 應呼叫 Refund API（/v3/payments/{transactionId}/refund）
      And 退款成功後 _linepay_payment_status 應為 refunded

    Scenario: Customer 發起退款
      Given linepay_customer_refund 設定包含 wc-processing
      And 訂單在 60 天退款期限內
      When Customer 在帳戶頁點擊「Cancel」
      Then 應建立 WC_Refund 物件
      And 呼叫退款 API
      And 重導至帳戶首頁並顯示退款成功訊息

    Scenario: 超過 60 天退款期限
      Given 訂單建立超過 60 天
      When Customer 查看訂單
      Then 不應顯示「Cancel」退款按鈕

    Scenario: 後台超過 60 天退款期限
      Given 訂單建立超過 60 天
      When Admin 查看訂單
      Then 退款按鈕應替換為「已超過60天退款期限」提示

  Rule: 付款失敗處理

    Scenario: LINE Pay 付款失敗後訂單動作
      Given 訂單付款方式為 linepay 且狀態為 failed
      When Customer 查看帳戶訂單
      Then 不應顯示「pay」和「cancel」按鈕

  Rule: API 簽章

    Scenario: HMAC-SHA256 簽章生成
      Given Channel Secret Key 和請求參數已準備
      When 生成 API 簽章
      Then 應使用 SHA256 算法
      And 簽章內容包含 Channel Secret + URI + Request Body + Nonce

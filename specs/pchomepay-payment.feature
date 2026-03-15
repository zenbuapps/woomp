Feature: 支付連金流付款
  As a 消費者
  I want 透過 PChomePay 支付連完成線上付款
  So that 我可以使用信用卡、ATM、支付連餘額、銀行支付、7-11 超商或 PI 拍錢包等方式支付

  Background:
    Given 網站已啟用支付連（woocommerce_pchomepay_enabled = yes）
    And app_id 和 secret 已設定
    And 付款方式（payment_methods）已勾選

  Rule: 閘道註冊

    Scenario: 支付連閘道正確註冊
      When WooCommerce 載入付款閘道
      Then 應註冊 pchomepay 和 pchomepay_pi 兩種閘道

    Scenario: API 金鑰未設定
      Given app_id 或 secret 為空
      When 初始化閘道
      Then 閘道 enabled 應設為 false

  Rule: 訂單建立

    Scenario: 建立支付連訂單
      Given Customer 選擇 PChomePay 付款
      When Customer 提交訂單
      Then 應呼叫 POST /v1/payment 建立支付連訂單
      And order_id 格式為 AW{Ymd}{wc_order_number}
      And 訂單狀態應為 pending
      And Customer 應重導至 payment_url

    Scenario: 信用卡分期設定
      Given card_installment 包含 CRD_3 和 CRD_12
      When 組建付款參數
      Then card_info 應包含 installment=3 和 installment=12

    Scenario: ATM 過期天數
      Given atm_expiredate 設為 3
      When 組建付款參數
      Then atm_info.expire_days 應為 3

    Scenario: ATM 過期天數超出範圍
      Given atm_expiredate 設為 10
      When 儲存設定
      Then 應自動重設為 5（允許範圍 1-5）

  Rule: Token 認證

    Scenario: 取得 API Token
      Given Token 不存在或已過期
      When 發送 API 請求
      Then 應先呼叫 POST /v1/token 取得新 Token
      And Token 使用 Basic Auth（app_id:secret）

    Scenario: Token 快取
      Given Token 尚未過期（1800 秒內）
      When 發送 API 請求
      Then 應使用快取的 Token

  Rule: 付款確認回調

    Scenario: 信用卡付款確認
      Given 支付連回調 notify_type=order_confirm, pay_type=CARD
      When 處理回調
      Then 訂單應完成付款
      And 訂單備註應記錄「信用卡 付款」
      And card_last_number 啟用時應顯示末四碼

    Scenario: ATM 付款確認
      Given 支付連回調 notify_type=order_confirm, pay_type=ATM
      When 處理回調
      Then 訂單備註應包含「ATM 付款」和虛擬帳號資訊

    Scenario: 7-11 超商付款確認
      Given 支付連回調 notify_type=order_confirm, pay_type=IPL7
      When 處理回調
      Then _pchomepay_paytype 應為 IPL7
      And 訂單應完成付款

  Rule: 審單機制

    Scenario: 訂單進入等待審單
      Given 支付連回調 notify_type=order_audit
      And status_code 為 ORDER_PENDING_CLIENT
      When 處理回調
      Then 訂單狀態應為 wc-awaiting

    Scenario: Admin 過單
      Given 訂單狀態為 awaiting 且付款方式為 pchomepay
      When Admin 選擇「PChomePay - 訂單過單」
      Then 應呼叫 POST /v1/payment/audit 且 status=PASS
      And 訂單備註應記錄「已過單」

    Scenario: Admin 拒絕訂單
      Given 訂單狀態為 awaiting
      When Admin 選擇「PChomePay - 訂單取消」
      Then 應呼叫審單 API 且 status=DENY
      And 訂單備註應記錄「已拒絕」

  Rule: 退款

    Scenario: 全額退款
      Given 訂單已完成付款
      When Admin 發起全額退款
      Then refund_id 格式為 RF{pchomepay_order_id}
      And 應呼叫退款 API

    Scenario: 部分退款（多次）
      Given 訂單已有退款記錄 RF{order_id}-1
      When Admin 再次部分退款
      Then refund_id 應為 RF{order_id}-2

    Scenario: 7-11 退款使用 v1 API
      Given _pchomepay_paytype 為 IPL7
      When 發起退款
      Then 應使用 /v1/refund API

    Scenario: 信用卡退款使用 v2 API
      Given _pchomepay_paytype 為 CARD
      When 發起退款
      Then 應使用 /v2/refund API

  Rule: 訂單過期/失敗

    Scenario: 訂單過期
      Given 支付連回調 notify_type=order_expired
      When 處理回調
      Then 訂單狀態應為 failed

  Rule: 7-11 物流歷程

    Scenario: 查詢 7-11 物流歷程
      Given _pchomepay_paytype 為 IPL7
      When Customer 或 Admin 查詢物流歷程
      Then 應呼叫 /v1/logistic/query/{order_id}/history-page
      And 回傳 history_url 供查看

    Scenario: 前台顯示物流歷程連結
      Given 訂單為 7-11 超商付款
      When Customer 查看帳戶訂單
      Then 應顯示「物流歷程」連結

  Rule: 自訂訂單狀態

    Scenario: 註冊等待審單狀態
      When WordPress 初始化
      Then 應註冊 wc-awaiting 和 wc-awaitingforpcpay 兩個訂單狀態
      And 狀態應顯示在訂單狀態清單中（processing 之後）

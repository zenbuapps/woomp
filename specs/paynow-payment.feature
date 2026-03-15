Feature: 立吉富金流付款
  As a 消費者
  I want 透過立吉富（PayNow）金流完成線上付款
  So that 我可以使用信用卡、虛擬帳號、WebATM、ibon 或條碼繳費

  Background:
    Given 網站已啟用立吉富金流（wc_woomp_setting_paynow_gateway = yes）
    And paynow_payment_web_no 和 paynow_payment_trans_pwd 已設定

  Rule: 閘道註冊與可用性

    Scenario: 立吉富金流閘道正確註冊
      When WooCommerce 載入付款閘道
      Then 應註冊 5 種閘道：paynow-credit, paynow-virtual-account, paynow-webatm, paynow-ibon, paynow-barcode

    Scenario: 最低金額限制
      Given 購物車總金額為 20 元
      When 檢查閘道可用性
      Then 所有立吉富閘道不可用（最低 30 元）

  Rule: 信用卡即時付款

    Scenario: 信用卡付款成功
      Given Customer 選擇立吉富信用卡付款
      When PayNow 回調 TranStatus=S, PayType=01
      Then 訂單應完成付款
      And _paynow_pan_no4 應儲存信用卡末四碼

    Scenario: 信用卡付款失敗
      Given Customer 選擇立吉富信用卡付款
      When PayNow 回調 TranStatus=F
      Then 訂單狀態應為 on-hold
      And _paynow_errdesc 應儲存錯誤描述

  Rule: 虛擬帳號離線付款

    Scenario: 虛擬帳號取號
      Given Customer 選擇立吉富虛擬帳號
      When PayNow 離線回調 PayType=03, TranStatus!=S
      Then _paynow_bank_code 和 _paynow_atm_no 應被儲存
      And 訂單狀態應為 on-hold

    Scenario: 虛擬帳號付款完成
      Given 虛擬帳號已建立
      When PayNow 背景通知 TranStatus=S
      Then 訂單應完成付款

  Rule: ibon 超商代碼

    Scenario: ibon 代碼取號
      Given Customer 選擇立吉富 ibon
      When PayNow 離線回調 PayType=05
      Then _paynow_ibon_no 應被儲存

  Rule: 條碼繳費

    Scenario: 條碼取號
      Given Customer 選擇立吉富條碼繳費
      When PayNow 離線回調 PayType=10
      Then barcode1, barcode2, barcode3 應被儲存

  Rule: 背景通知

    Scenario: 背景通知付款成功
      Given PayNow 發送背景通知（method=paynow_return）
      And TranStatus=S
      When 處理背景通知
      Then 訂單應完成付款
      And 回應 '1'

    Scenario: WebNo 不匹配
      Given PayNow 回調的 WebNo 與設定不同
      When 處理回調
      Then 不應更新訂單狀態
      And 日誌應記錄 WebNo 不匹配

  Rule: PassCode 驗證

    Scenario: PassCode 組建
      Given WebNo=ABC, OrderNo=123, TotalPrice=1000, trans_pwd=secret
      When 計算 PassCode
      Then 應為 SHA1('ABC1231000secret')

  Rule: 前台訂單資訊

    Scenario: 信用卡訂單顯示付款詳情
      Given 訂單使用 paynow-credit 付款
      When 顧客查看訂單詳情
      Then 應顯示 Transaction No、Card Last 4 Num、Trans Status

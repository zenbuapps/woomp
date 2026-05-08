@ignore @command
Feature: V3 已存 Token 直接啟用訂閱

  Background:
    Given PayUni 金流已啟用
    And WooCommerce Subscriptions 外掛已啟用
    And payuni-credit-subscription-v3 閘道已啟用
    And 訂閱商品沒有註冊費（訂單金額為 0）

  Rule: 後置（狀態）- 持有有效 Token 且訂單金額為 0 時應直接完成付款

    Example: 消費者已有 V3 Token 且訂單金額為 0 時直接啟用訂閱
      Given 消費者已有 gateway_id 為 payuni-credit-subscription-v3 的 WC_Payment_Token_CC
      When 消費者選擇已存卡片並下單
      Then 系統直接呼叫 payment_complete()
      And 訂單備註記錄「持有 token 且訂單金額為 0，直接轉為處理中」
      And 無需發送 PayUni API 請求
      And 訂閱狀態為 active

    Example: 消費者已有 V1 Token 且訂單金額為 0 時直接啟用訂閱
      Given 消費者已有 gateway_id 為 payuni-credit-subscription 的 WC_Payment_Token_CC（V1 舊 Token）
      And 消費者沒有 gateway_id 為 payuni-credit-subscription-v3 的 Token
      When 消費者選擇已存卡片並下單
      Then 系統直接呼叫 payment_complete()
      And 訂單備註記錄「持有 token 且訂單金額為 0，直接轉為處理中」
      And 無需發送 PayUni API 請求
      And 訂閱狀態為 active

  Rule: 前置（狀態）- Token 的 gateway_id 必須為 payuni-credit-subscription-v3 或 payuni-credit-subscription

    Example: 僅有一般信用卡閘道 Token 時走零元取 Token 流程
      Given 消費者只有 gateway_id 為 payuni-credit-v3 的 Token（一般信用卡閘道）
      And 消費者沒有 gateway_id 為 payuni-credit-subscription-v3 或 payuni-credit-subscription 的 Token
      When 消費者下單
      Then 系統視為無已存 Token，走零元取 Token 流程

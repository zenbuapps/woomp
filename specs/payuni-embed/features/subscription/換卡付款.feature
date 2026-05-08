@ignore @command
Feature: V3 訂閱換卡付款

  Background:
    Given PayUni 金流已啟用
    And WooCommerce Subscriptions 外掛已啟用

  Rule: 後置（狀態）- 換卡後續扣應使用新的 CreditHash

    Example: 消費者透過 WC Subscriptions 換卡流程成功更換信用卡後續扣使用新卡
      Given payuni-credit-subscription-v3 閘道已啟用
      And 消費者有一個 active 的 payuni-credit-subscription-v3 訂閱
      And 消費者原有 CreditHash 為 "OLD_HASH_123"
      When 消費者在 My Account > Subscriptions 點擊「更改付款方式」
      And WC Subscriptions 導向結帳頁面
      And 消費者選擇 payuni-credit-subscription-v3 閘道
      And 消費者在 UNi Embed iframe 中輸入新的信用卡資料並送出
      Then 系統取得新的 CreditHash "NEW_HASH_456"
      And 新 CreditHash 儲存為 WC_Payment_Token_CC（gateway_id = payuni-credit-subscription-v3）
      And 下次續扣使用新的 CreditHash "NEW_HASH_456"

  Rule: 後置（狀態）- 換卡時允許跨版本切換閘道

    Example: V1 訂閱的消費者換卡時選擇 V3 閘道後訂閱的 payment_method 更新為 V3
      Given payuni-credit-subscription-v3 閘道已啟用
      And payuni-credit-subscription（V1）閘道已啟用
      And 消費者有一個 active 的 payuni-credit-subscription（V1）訂閱
      When 消費者在 My Account > Subscriptions 點擊「更改付款方式」
      And WC Subscriptions 導向結帳頁面
      And 結帳頁面顯示 payuni-credit-subscription-v3 和 payuni-credit-subscription（V1）兩個閘道
      And 消費者選擇 payuni-credit-subscription-v3 閘道
      And 消費者在 UNi Embed iframe 中輸入新的信用卡資料並送出
      Then 訂閱的 payment_method 更新為 "payuni-credit-subscription-v3"
      And 新 CreditHash 儲存為 WC_Payment_Token_CC（gateway_id = payuni-credit-subscription-v3）
      And 後續續扣走 V3 閘道邏輯

    Example: V3 訂閱的消費者換卡時選擇 V1 閘道後訂閱的 payment_method 更新為 V1
      Given payuni-credit-subscription-v3 閘道已啟用
      And payuni-credit-subscription（V1）閘道已啟用
      And 消費者有一個 active 的 payuni-credit-subscription-v3 訂閱
      When 消費者在 My Account > Subscriptions 點擊「更改付款方式」
      And WC Subscriptions 導向結帳頁面
      And 消費者選擇 payuni-credit-subscription（V1）閘道
      And 消費者完成 V1 信用卡表單提交
      Then 訂閱的 payment_method 更新為 "payuni-credit-subscription"
      And 後續續扣走 V1 閘道邏輯

  Rule: 前置（狀態）- 換卡必須重新綁定有效的信用卡

    Example: 消費者換卡時輸入無效卡號後操作失敗
      Given payuni-credit-subscription-v3 閘道已啟用
      And 消費者有一個 active 的 payuni-credit-subscription-v3 訂閱
      When 消費者在換卡頁面的 UNi Embed iframe 中輸入無效的信用卡資料
      And PayUni 回傳交易失敗
      Then 操作失敗，錯誤為「信用卡交易失敗，請確認卡片資訊或聯繫發卡銀行」
      And 訂閱的 CreditHash 維持原卡不變

  Rule: 前置（參數）- 必要參數必須提供

    Example: 消費者換卡時未完整填寫信用卡資料後操作失敗
      Given payuni-credit-subscription-v3 閘道已啟用
      And 消費者有一個 active 的 payuni-credit-subscription-v3 訂閱
      When 消費者在換卡頁面未完整填寫信用卡資料即點擊送出
      Then 操作失敗，錯誤為「請完整填寫信用卡資料」
      And 訂閱的 CreditHash 維持原卡不變

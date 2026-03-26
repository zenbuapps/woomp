@ignore @command
Feature: V3 續扣失敗處理

  Background:
    Given PayUni 金流已啟用
    And payuni-credit-subscription-v3 閘道已啟用
    And WooCommerce Subscriptions 排程觸發定期扣款
    And PayUni API 回傳失敗

  Rule: 後置（狀態）- 續扣失敗時訂單狀態應改為 pending 以允許重試

    Example: 續扣失敗後訂單狀態改為 pending
      Given 定期扣款失敗
      When WooCommerce Subscriptions 觸發 woocommerce_subscription_renewal_payment_failed
      And 訂單狀態為 failed
      And 訂單付款方式為 payuni-credit-subscription-v3
      And PayUni 回應狀態非 SUCCESS
      Then 系統將訂單狀態改回 pending
      And 允許下次排程重新扣款

  Rule: 前置（狀態）- 失敗處理必須驗證付款方式為 V3 定期定額閘道

    Example: 非 V3 定期定額閘道的失敗訂單不處理
      Given 訂單付款方式為 payuni-credit-subscription（V1）
      When woocommerce_subscription_renewal_payment_failed 觸發
      Then V3 的 subscription_fail_handler 不處理此訂單

  Rule: 前置（狀態）- 已成功的訂單不應被重設

    Example: PayUni 回應為 SUCCESS 時不改變訂單狀態
      Given 訂單狀態為 failed
      And 訂單付款方式為 payuni-credit-subscription-v3
      But PayUni 回應狀態為 SUCCESS
      When woocommerce_subscription_renewal_payment_failed 觸發
      Then V3 的 subscription_fail_handler 不處理此訂單

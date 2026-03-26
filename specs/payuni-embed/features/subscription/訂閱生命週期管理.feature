@ignore @command
Feature: V3 訂閱生命週期管理

  Background:
    Given PayUni 金流已啟用
    And payuni-credit-subscription-v3 閘道已啟用
    And WooCommerce Subscriptions 外掛已啟用

  Rule: 後置（狀態）- V3 定期定額閘道必須宣告支援完整的 WC Subscriptions 功能

    Example: 閘道宣告支援的 Subscriptions 功能
      Then V3 定期定額閘道 supports 陣列包含：
        | 功能名稱                                          |
        | products                                          |
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

  Rule: 後置（狀態）- 暫停訂閱時應停止排程扣款

    Example: 消費者暫停訂閱後不再續扣
      Given 消費者有一個 active 的訂閱
      When 消費者在 My Account 暫停訂閱
      Then 訂閱狀態變更為 on-hold
      And WC Subscriptions 暫停排程扣款
      And 不需通知 PayUni API

    Example: 消費者恢復已暫停的訂閱
      Given 消費者有一個 on-hold 的訂閱
      When 消費者在 My Account 恢復訂閱
      Then 訂閱狀態變更為 active
      And WC Subscriptions 恢復排程扣款

    Example: 消費者取消訂閱
      Given 消費者有一個 active 的訂閱
      When 消費者在 My Account 取消訂閱
      Then 訂閱狀態變更為 cancelled
      And WC Subscriptions 移除排程扣款
      And 不需通知 PayUni API

  Rule: 後置（狀態）- 金額變更後續扣應使用新金額

    Example: 管理員調整訂閱金額後下次續扣使用新金額
      Given 消費者有一個每月 500 元的訂閱
      When 管理員將訂閱金額調整為 800 元
      And 下次排程續扣觸發
      Then 續扣金額為 800 元

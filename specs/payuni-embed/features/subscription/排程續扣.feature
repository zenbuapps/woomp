@ignore @command
Feature: V3 排程續扣

  Background:
    Given PayUni 金流已啟用
    And WooCommerce Subscriptions 外掛已啟用
    And payuni-credit-subscription-v3 閘道已啟用
    And 訂閱狀態為 active
    And 消費者有有效的 CreditHash Token

  Rule: 後置（狀態）- 續扣成功時訂單狀態應更新為 processing

    Example: 定期續扣成功
      Given 訂閱每月扣款 500 元
      When WooCommerce Subscriptions 排程觸發定期扣款
      Then 系統使用已存的 CreditHash 組裝交易參數
      And 交易參數不包含 API3D（續扣不需 3D 驗證）
      And 系統 POST 到 /iframe/merchant_trade（優先），若失敗則 fallback 到 /api/credit
      And 訂單狀態更新為 processing
      And 訂閱狀態維持 active

  Rule: 前置（狀態）- CreditHash 必須有效

    Example: CreditHash 無效時續扣失敗後訂單狀態改為 failed
      Given 消費者的 CreditHash 已過期或被撤銷
      When WooCommerce Subscriptions 排程觸發定期扣款
      Then PayUni 回傳非 SUCCESS 狀態
      And 訂單狀態更新為 failed

  Rule: 前置（狀態）- get_card_hash 必須同時搜尋 V3 與 V1 兩個 gateway_id 的 Token

    Example: 消費者只有 V1 Token 時續扣使用 V1 的 CreditHash
      Given 消費者有 gateway_id 為 payuni-credit-subscription 的 Token（V1 舊 Token，CreditHash 為 "V1_HASH_XYZ"）
      And 消費者沒有 gateway_id 為 payuni-credit-subscription-v3 的 Token
      When WooCommerce Subscriptions 排程觸發定期扣款
      Then 系統使用 V1 Token 的 CreditHash "V1_HASH_XYZ" 組裝交易參數

    Example: 消費者同時有 V1 和 V3 Token 時優先使用 V3 的 CreditHash
      Given 消費者有 gateway_id 為 payuni-credit-subscription-v3 的 Token（CreditHash 為 "V3_HASH_NEW"）
      And 消費者有 gateway_id 為 payuni-credit-subscription 的 Token（CreditHash 為 "V1_HASH_OLD"）
      When WooCommerce Subscriptions 排程觸發定期扣款
      Then 系統優先使用 V3 Token 的 CreditHash "V3_HASH_NEW" 組裝交易參數

  Rule: 後置（狀態）- 續扣交易參數應使用 EncryptUtils 加密並走 V3 API

    Example: 續扣交易參數正確組裝
      Given 訂閱每月扣款 500 元
      And 消費者 email 為 "alice@test.com"
      And 消費者 CreditHash 為 "HASH_ABC123"
      When WooCommerce Subscriptions 排程觸發定期扣款
      Then 交易參數應包含：
        | 參數 | 值 |
        | MerID | 商店 MerchantID |
        | TradeAmt | 500 |
        | CreditToken | alice@test.com |
        | CreditHash | HASH_ABC123 |
        | UseTokenType | 2 |
      And 交易參數不包含 API3D
      And 交易參數不包含 SDK Token
      And 參數經 EncryptUtils 加密後 POST 到 /iframe/merchant_trade

@ignore @query
Feature: 查詢已儲存卡片

  系統查詢消費者已儲存的信用卡 Token，用於結帳頁顯示已儲存的付款方式。

  Background:
    Given 系統中有以下金流設定：
      | merchant_id | enable_tokenization |
      | ABC123      | true                |
    And 系統中有以下消費者：
      | userId | name  | email          |
      | 1      | Alice | alice@test.com |
    And 消費者 "Alice" 有以下已儲存卡片：
      | token_id | gateway_id      | last4 | expiry_month | expiry_year | card_type  | is_default |
      | 101      | payuni-credit-v3 | 1234  | 12           | 2028        | visa       | true       |
      | 102      | payuni-credit-v3 | 5678  | 06           | 2027        | mastercard | false      |

  Rule: 前置（狀態）- 記憶卡號功能必須啟用

    Example: 記憶卡號未啟用時回傳空清單
      Given 金流設定 enable_tokenization 為 false
      When 系統查詢消費者 "Alice" 的已儲存卡片
      Then 操作成功
      And 查詢結果應為空清單

  Rule: 前置（狀態）- 消費者必須已登入

    Example: 消費者未登入時回傳空清單
      Given 消費者未登入
      When 系統查詢已儲存卡片
      Then 操作成功
      And 查詢結果應為空清單

  Rule: 後置（回應）- 應回傳該消費者在 payuni-credit-v3 閘道下的所有卡片

    Example: 查詢已登入消費者的已儲存卡片
      Given 消費者 "Alice" 已登入
      When 系統查詢消費者 "Alice" 的已儲存卡片
      Then 操作成功
      And 查詢結果應包含：
        | token_id | last4 | expiry_month | expiry_year | card_type  | is_default |
        | 101      | 1234  | 12           | 2028        | visa       | true       |
        | 102      | 5678  | 06           | 2027        | mastercard | false      |

  Rule: 後置（回應）- 不應回傳其他閘道的卡片

    Example: 不回傳其他金流閘道的已儲存卡片
      Given 消費者 "Alice" 已登入
      And 消費者 "Alice" 在 "other-gateway" 閘道有已儲存卡片
      When 系統查詢消費者 "Alice" 的已儲存卡片
      Then 查詢結果應只包含 gateway_id 為 "payuni-credit-v3" 的卡片

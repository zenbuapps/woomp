@ignore @command
Feature: 儲存付款 Token

  交易成功後，若消費者已勾選「記憶卡號」且功能已啟用，
  系統將 PayUni 回傳的 CreditHash 儲存為 WooCommerce Payment Token (WC_Payment_Token_CC)。

  Background:
    Given 系統中有以下金流設定：
      | merchant_id | enable_tokenization |
      | ABC123      | true                |
    And 系統中有以下消費者：
      | userId | name  | email          |
      | 1      | Alice | alice@test.com |

  Rule: 前置（狀態）- 記憶卡號功能必須啟用

    Example: 記憶卡號未啟用時不儲存 Token
      Given 金流設定 enable_tokenization 為 false
      When 系統嘗試儲存訂單 1001 的付款 Token
      Then 操作失敗，錯誤為「記憶卡號功能未啟用」

  Rule: 前置（狀態）- 消費者必須已登入

    Example: 消費者未登入時不儲存 Token
      Given 消費者未登入
      When 系統嘗試儲存付款 Token
      Then 操作失敗，錯誤為「消費者未登入」

  Rule: 前置（參數）- 交易回應必須包含 CreditHash

    Example: 交易回應無 CreditHash 時不儲存 Token
      Given 交易回應不含 CreditHash
      When 系統嘗試儲存訂單 1001 的付款 Token
      Then 操作失敗，錯誤為「交易回應缺少 CreditHash」

  Rule: 前置（狀態）- 不可與現有 Token 重複

    Example: 已存在相同 Token 時不重複儲存
      Given 消費者 "Alice" 已有 Token，token 值為 "hash_abc123"
      And 交易回應的 CreditHash 為 "hash_abc123"
      When 系統嘗試儲存訂單 1001 的付款 Token
      Then 不應建立新的 Token

  Rule: 後置（狀態）- 應建立 WC_Payment_Token_CC 並正確設定屬性

    Example: 成功儲存 Visa 卡片 Token
      Given 交易回應：CreditHash "hash_new456"，Card6No "411111"，Card4No "1234"，CreditLife "1228"
      When 系統儲存訂單 1001 的付款 Token
      Then 操作成功
      And 新建的 Token 應為：
        | token       | last4 | expiry_month | expiry_year | card_type | gateway_id       | user_id |
        | hash_new456 | 1234  | 12           | 2028        | visa      | payuni-credit-v3 | 1       |

  Rule: 後置（狀態）- 卡片類型應根據 Card6No 的 BIN 碼自動偵測

    Scenario Outline: 根據 BIN 碼偵測卡片類型
      Given 交易回應的 Card6No 為 "<Card6No>"
      When 系統儲存付款 Token
      Then Token 的 card_type 應為 "<card_type>"

      Examples:
        | Card6No | card_type  |
        | 411111  | visa       |
        | 512345  | mastercard |
        | 371234  | amex       |
        | 601111  | discover   |

  Rule: 後置（狀態）- Sandbox 環境 CreditLife 為空時應使用 fallback 到期日

    Example: Sandbox 環境下 CreditLife 為空時使用 fallback
      Given 金流設定 mode 為 "TEST"
      And 交易回應的 CreditLife 為空字串
      When 系統儲存付款 Token
      Then Token 的到期日應為：
        | expiry_month | expiry_year |
        | 12           | 2099        |

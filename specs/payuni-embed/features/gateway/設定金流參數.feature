@ignore @command
Feature: 設定金流參數

  管理員在 WooCommerce 後台設定統一金流 PAYUNi 的商戶資訊與功能開關。
  設定分為全域設定（WordPress options）和閘道設定（WooCommerce gateway settings）。

  Background:
    Given 系統中有以下金流設定：
      | merchant_id | hash_key         | hash_iv          | mode | enable_tokenization | enable_3d_auth | enable_log | installment_options | enable_invoice_carrier |
      | ABC123      | test_hash_key_32 | test_hash_iv_16  | TEST | false               | true           | true       |                     | no                     |

  Rule: 前置（參數）- 必要參數必須提供

    Scenario Outline: 缺少 <缺少參數> 時操作失敗
      When 管理員設定金流參數，merchant_id <merchant_id>，hash_key <hash_key>，hash_iv <hash_iv>
      Then 操作失敗，錯誤為「必要參數未提供」

      Examples:
        | 缺少參數    | merchant_id | hash_key         | hash_iv         |
        | merchant_id |             | test_hash_key_32 | test_hash_iv_16 |
        | hash_key    | ABC123      |                  | test_hash_iv_16 |
        | hash_iv     | ABC123      | test_hash_key_32 |                 |

  Rule: 前置（參數）- mode 必須為 TEST 或 PROD

    Example: mode 為無效值時操作失敗
      When 管理員設定金流參數，mode 為 "INVALID"
      Then 操作失敗，錯誤為「模式必須為 TEST 或 PROD」

  Rule: 前置（參數）- installment_options 的期數必須為有效值

    Example: 分期期數包含無效值時操作失敗
      When 管理員設定分期選項為 "3,6,15"
      Then 操作失敗，錯誤為「分期期數包含無效值：15」

  Rule: 後置（狀態）- 設定應正確儲存

    Example: 設定金流參數後各項設定正確儲存
      When 管理員設定金流參數，merchant_id "XYZ789"，hash_key "new_key_32"，hash_iv "new_iv_16"，mode "PROD"，enable_tokenization true，installment_options "3,6,12"，enable_invoice_carrier "yes"
      Then 操作成功
      And 金流設定應為：
        | merchant_id | hash_key   | hash_iv    | mode | enable_tokenization | installment_options | enable_invoice_carrier |
        | XYZ789      | new_key_32 | new_iv_16  | PROD | true                | 3,6,12              | yes                    |

  Rule: 後置（狀態）- enable_invoice_carrier 預設值應為 no

    Example: 新安裝或升級後 enable_invoice_carrier 預設為關閉
      When 管理員查詢金流設定
      Then enable_invoice_carrier 應為 "no"

  Rule: 後置（狀態）- TEST 與 PROD 模式應使用不同的 API 端點

    Example: TEST 模式使用 sandbox API 端點
      Given 金流設定 mode 為 "TEST"
      When 系統取得 API 端點
      Then API 端點應為 "https://sandbox-api.payuni.com.tw/api"

    Example: PROD 模式使用正式 API 端點
      Given 金流設定 mode 為 "PROD"
      When 系統取得 API 端點
      Then API 端點應為 "https://api.payuni.com.tw/api"

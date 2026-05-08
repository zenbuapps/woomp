@ignore @command
Feature: 初始化結帳頁 SDK

  系統在結帳頁面載入 PayUni UNi Embed SDK，取得 SDK Token 並初始化 iframe 環境。

  Background:
    Given 系統中有以下金流設定：
      | merchant_id | hash_key         | hash_iv         | mode | enabled |
      | ABC123      | test_hash_key_32 | test_hash_iv_16 | TEST | true    |

  Rule: 前置（狀態）- 必須在 WooCommerce 結帳頁面

    Example: 非結帳頁面時不載入 SDK
      Given 當前頁面不是結帳頁
      When 系統初始化結帳頁 SDK
      Then 操作失敗，錯誤為「非結帳頁面，不載入 SDK」

  Rule: 前置（狀態）- 金流閘道必須啟用

    Example: 金流閘道未啟用時不載入 SDK
      Given 金流設定 enabled 為 false
      And 當前頁面是結帳頁
      When 系統初始化結帳頁 SDK
      Then 操作失敗，錯誤為「金流閘道未啟用」

  Rule: 後置（狀態）- 系統應從 PayUni API 取得 SDK Token（有效期 10 分鐘）

    Example: 成功取得 SDK Token
      Given 當前頁面是結帳頁
      When 系統初始化結帳頁 SDK
      Then 操作成功
      And SDK 環境參數應為：
        | env | sdk_token    | enable_3d_auth | enable_tokenization |
        | S   | token_abc123 | true           | false               |

  Rule: 後置（狀態）- SDK Token 取得失敗時應記錄錯誤但不阻斷結帳

    Example: SDK Token API 呼叫失敗時結帳頁仍可載入
      Given PayUni API 回傳錯誤
      When 系統初始化結帳頁 SDK
      Then SDK Token 應為空字串
      And 錯誤日誌應記錄「取得 SDK Token 失敗」

  Rule: 後置（狀態）- 應根據環境載入對應的 SDK 腳本

    Example: TEST 模式載入 sandbox SDK
      Given 金流設定 mode 為 "TEST"
      When 系統初始化結帳頁 SDK
      Then SDK 腳本 URL 應為 "https://sandbox-vendor.payuni.com.tw/sdk/uni-payment.js"

    Example: PROD 模式載入正式 SDK
      Given 金流設定 mode 為 "PROD"
      When 系統初始化結帳頁 SDK
      Then SDK 腳本 URL 應為 "https://vendor.payuni.com.tw/sdk/uni-payment.js"

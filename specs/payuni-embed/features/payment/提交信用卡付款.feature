@ignore @command
Feature: 提交信用卡付款

  消費者在結帳頁提交信用卡付款，系統收集表單資料（含 SDK Token、卡片選擇、分期、載具）
  並暫存至訂單 meta，觸發 process_payment 流程。
  支援兩種付款方式：使用新卡片（透過 SDK iframe）或使用已儲存卡片（透過 Token）。

  Background:
    Given 系統中有以下金流設定：
      | merchant_id | hash_key         | hash_iv         | mode | enable_tokenization | enable_3d_auth | installment_options |
      | ABC123      | test_hash_key_32 | test_hash_iv_16 | TEST | true                | true           | 3,6,12              |
    And 系統中有以下消費者：
      | userId | name  | email          |
      | 1      | Alice | alice@test.com |
    And 系統中有以下訂單：
      | orderId | userId | total | status |
      | 1001    | 1      | 500   | 待付款  |

  Rule: 前置（狀態）- SDK Token 必須有效

    Example: SDK Token 無效時操作失敗
      Given SDK Token 為空字串
      When 消費者 "Alice" 提交訂單 1001 的信用卡付款，SDK Token ""
      Then 操作失敗，錯誤為「SDK Token 無效」

  Rule: 前置（參數）- 新卡片付款時三個卡片欄位必須通過驗證

    Example: 新卡片欄位未全部通過驗證時操作失敗
      Given SDK iframe 的 CardNo 驗證狀態為 valid，CardExp 為 valid，CardCvc 為 invalid
      When 消費者 "Alice" 提交訂單 1001 的新卡片付款
      Then 操作失敗，錯誤為「請確認信用卡安全碼」

  Rule: 前置（參數）- 已儲存卡片付款時 Token 必須屬於當前消費者

    Example: Token 不屬於當前消費者時操作失敗
      Given 消費者 "Alice" 已登入
      And Token ID 999 屬於消費者 "Bob"
      When 消費者 "Alice" 使用已儲存卡片 Token ID 999 提交訂單 1001 的付款
      Then 操作失敗，錯誤為「無效的付款卡片」

  Rule: 前置（狀態）- 訂單金額必須大於或等於最低金額

    Example: 訂單金額低於最低金額時操作失敗
      Given 訂單 1001 的金額為 5
      When 消費者 "Alice" 提交訂單 1001 的信用卡付款
      Then 操作失敗，錯誤為「訂單金額低於最低付款金額」

  Rule: 前置（參數）- 分期期數必須為有效值

    Example: 分期期數為無效值時操作失敗
      When 消費者 "Alice" 提交訂單 1001 的信用卡付款，分期期數為 5
      Then 操作失敗，錯誤為「無效的分期期數」

    Example: 選擇有效分期期數時操作成功
      When 消費者 "Alice" 提交訂單 1001 的信用卡付款，分期期數為 6
      Then 操作成功

  Rule: 後置（狀態）- 表單資料應暫存至訂單 meta

    Example: 提交新卡片付款後暫存資料正確
      When 消費者 "Alice" 提交訂單 1001 的新卡片付款，分期期數為 1，儲存卡片為 true
      Then 操作成功
      And 訂單 1001 的暫存付款資料應為：
        | sdk_token_tmp | payuni_save_card | payuni_installment | payuni_use_saved_token | payuni_saved_token_id |
        | token_abc     | 1                | 1                  | 0                      |                       |

    Example: 使用已儲存卡片付款後暫存資料正確
      Given 消費者 "Alice" 有已儲存卡片 Token ID 101
      When 消費者 "Alice" 使用已儲存卡片 Token ID 101 提交訂單 1001 的付款
      Then 操作成功
      And 訂單 1001 的暫存付款資料應為：
        | sdk_token_tmp | payuni_save_card | payuni_use_saved_token | payuni_saved_token_id |
        | token_abc     | 0                | 1                      | 101                   |

  Rule: 後置（狀態）- 使用已儲存卡片時 SDK 應以 useDefault 模式取得交易結果

    已儲存卡片付款時，SDK iframe 僅顯示 CVC 輸入框（CardNo 和 CardExp 隱藏）。
    消費者仍需輸入安全碼驗證身份，SDK 呼叫 getTradeResult({ useDefault: true })。

    Example: 已儲存卡片付款僅需輸入 CVC
      Given 消費者 "Alice" 已登入
      And 消費者 "Alice" 有已儲存卡片 Token ID 101
      When 消費者 "Alice" 使用已儲存卡片 Token ID 101 提交訂單 1001 的付款
      Then SDK iframe 應隱藏 CardNo 和 CardExp 輸入框
      And SDK iframe 應顯示 CVC 輸入框
      And SDK 應以 useDefault 模式取得交易結果

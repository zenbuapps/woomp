@ignore @command
Feature: 處理交易結果

  系統根據交易結果更新訂單狀態。成功時標記為已付款並儲存交易資訊；
  失敗時維持待付款狀態並寫入失敗備註（含錯誤碼與訊息），讓消費者可從「我的帳戶」重新付款。
  具備冪等性保護，已付款訂單不會重複處理。

  注意：此 Feature 僅適用於「非 3D 直接授權」路徑（raw_response 含 EncryptInfo）。
  3D 驗證流程不會進入此 Feature，而是由 process_payment 直接記錄 meta + order_note
  並維持訂單為「等待付款中」，交由 Webhook（處理交易回調.feature）處理最終結果。
  詳見 specs/features/payuni/fix-3d-process-payment-spec.md。

  Background:
    Given 系統中有以下訂單：
      | orderId | status | total |
      | 1001    | 待付款  | 500   |

  Rule: 前置（狀態）- 已付款訂單不可重複處理（冪等性）

    Example: 已付款訂單收到重複交易結果時跳過處理
      Given 訂單 1001 的狀態為 "已付款"
      When 系統處理訂單 1001 的交易結果，Status 為 "SUCCESS"
      Then 訂單 1001 的狀態應維持 "已付款"
      And 不應觸發 payment_complete

  Rule: 後置（狀態）- SUCCESS 時訂單應標記為已付款

    Example: 交易成功時完成付款
      When 系統處理訂單 1001 的交易結果，Status 為 "SUCCESS"，TradeNo 為 "PAY202601010001"，Card4No 為 "1234"
      Then 操作成功
      And 訂單 1001 的狀態應為 "已付款"
      And 訂單 1001 的 meta 應包含：
        | _payuni_resp_status | _payuni_resp_trade_no | _payuni_card_number |
        | SUCCESS             | PAY202601010001       | 1234                |

  Rule: 後置（狀態）- 非 SUCCESS 時訂單應維持待付款並寫入失敗備註

    Example: 交易失敗時維持待付款並寫入失敗 order_note
      When 系統處理訂單 1001 的交易結果，Status 為 "ERROR"，Message 為 "餘額不足"
      Then 操作成功
      And 訂單 1001 的狀態應為 "待付款"
      And 訂單 1001 的備註應包含 "ERROR" 和 "餘額不足"
      And 訂單 1001 的 meta 應包含：
        | _payuni_resp_status | _payuni_resp_message |
        | ERROR               | 餘額不足              |

  Rule: 後置（狀態）- 處理完成後應清除暫存資料

    Example: 交易處理完成後暫存資料被清除
      Given 訂單 1001 有暫存付款資料
      When 系統處理訂單 1001 的交易結果，Status 為 "SUCCESS"
      Then 訂單 1001 不應有 sdk_token_tmp 和 payuni_save_card 等暫存 meta

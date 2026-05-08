@ignore @command
Feature: 處理交易回調

  PayUni 透過 Webhook (POST /wc-api/payuni_notify) 發送交易結果通知。
  系統解密驗證後更新訂單狀態，並視需要儲存付款 Token。
  具備冪等性保護。

  Background:
    Given 系統中有以下金流設定：
      | merchant_id | hash_key         | hash_iv         |
      | ABC123      | test_hash_key_32 | test_hash_iv_16 |
    And 系統中有以下訂單：
      | orderId | status | total |
      | 1001    | 待付款  | 500   |

  Rule: 前置（參數）- 請求必須包含 EncryptInfo 和 HashInfo

    Example: 請求缺少 EncryptInfo 時回傳錯誤
      Given Webhook 請求不含 EncryptInfo
      When PayUni 發送交易回調
      Then 回傳 HTTP 錯誤回應
      And 錯誤日誌應記錄「處理回調通知失敗」

  Rule: 前置（參數）- HashInfo 簽章驗證必須通過

    Example: HashInfo 驗證失敗時回傳錯誤
      Given Webhook 請求的 HashInfo 與計算值不符
      When PayUni 發送交易回調
      Then 回傳 HTTP 錯誤回應

  Rule: 前置（狀態）- MerTradeNo 對應的訂單必須存在

    Example: 找不到對應訂單時回傳錯誤
      Given Webhook 請求解密後 MerTradeNo 為 "9999"
      And 訂單 9999 不存在
      When PayUni 發送交易回調
      Then 回傳 HTTP 錯誤回應
      And 錯誤日誌應記錄「找不到訂單: 9999」

  Rule: 後置（狀態）- 訂單狀態應依交易結果更新

    Example: Webhook 通知交易成功時完成付款
      Given Webhook 請求解密後結果為 Status "SUCCESS"，MerTradeNo "1001"，TradeNo "PAY202601010001"
      When PayUni 發送交易回調
      Then 回傳 HTTP 成功回應
      And 訂單 1001 的狀態應為 "已付款"

  Rule: 後置（狀態）- 已付款訂單不應重複處理（冪等性）

    Example: 已付款訂單收到重複 Webhook 時不重複完成
      Given 訂單 1001 的狀態為 "已付款"
      And Webhook 請求解密後結果為 Status "SUCCESS"，MerTradeNo "1001"
      When PayUni 發送交易回調
      Then 回傳 HTTP 成功回應
      And 訂單 1001 的狀態應維持 "已付款"
      And 不應觸發 payment_complete

  Rule: 後置（狀態）- Webhook 處理成功後應儲存付款 Token（若適用）

    Example: Webhook 觸發 Token 儲存
      Given 訂單 1001 的 payuni_save_card 為 "1"
      And 金流設定 enable_tokenization 為 true
      And Webhook 請求解密後包含 CreditHash "hash_webhook_123"
      When PayUni 發送交易回調
      Then 應嘗試儲存付款 Token

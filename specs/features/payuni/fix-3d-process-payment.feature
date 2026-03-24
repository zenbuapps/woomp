@ignore @command
Feature: 修正 3D 驗證流程 process_payment 誤判

  CreditV3::process_payment() 在 3D 驗證流程（raw_response 不含 EncryptInfo）時，
  不應呼叫 update_order_status()，訂單應維持「等待付款中」，
  僅儲存 _payuni_v3_resp meta 並寫入 order_note 記錄 3D 建立狀態，
  由後續 Webhook 決定最終訂單狀態。

  Background:
    Given 系統中有以下訂單：
      | orderId | status | total | paymentMethod  |
      | 1001    | 待付款  | 500   | payuni-credit-v3 |
    And PayUni 3D 驗證已啟用（payuni_3d_auth = yes）

  Rule: 前置（狀態）- PayUni 回應不含 EncryptInfo 時視為 3D 流程

    Example: 回應不含 EncryptInfo 時進入 3D 流程分支
      Given PayUni /iframe/merchant_trade 回傳以下 raw_response：
        | Status  | Message       | EncryptInfo |
        | SUCCESS | 建立幕後3D成功 |             |
      When 系統執行訂單 1001 的 process_payment
      Then 系統應辨識為 3D 驗證流程
      And 不應呼叫 TradeHandler::update_order_status()

    Example: 回應含 EncryptInfo 時進入直接授權流程
      Given PayUni /iframe/merchant_trade 回傳包含 EncryptInfo 的加密回應
      When 系統執行訂單 1001 的 process_payment
      Then 系統應辨識為直接授權流程
      And 應呼叫 TradeHandler::process_notify() 解密
      And 應呼叫 TradeHandler::update_order_status() 更新訂單

  Rule: 後置（狀態）- 3D 流程時訂單應維持「等待付款中」

    Example: 3D 建立成功後訂單狀態不變
      Given PayUni /iframe/merchant_trade 回傳以下 raw_response：
        | Status  | Message       |
        | SUCCESS | 建立幕後3D成功 |
      When 系統執行訂單 1001 的 process_payment
      Then 操作成功
      And 訂單 1001 的狀態應維持 "待付款"
      And 不應呼叫 payment_complete()

  Rule: 後置（狀態）- 3D 流程時應儲存 _payuni_v3_resp meta

    Example: 3D 建立回應儲存至訂單 meta
      Given PayUni /iframe/merchant_trade 回傳以下 raw_response：
        | Status  | Message       |
        | SUCCESS | 建立幕後3D成功 |
      When 系統執行訂單 1001 的 process_payment
      Then 訂單 1001 的 _payuni_v3_resp meta 應包含：
        | Status  | Message       |
        | SUCCESS | 建立幕後3D成功 |

  Rule: 後置（狀態）- 3D 流程時應寫入 order_note 記錄 3D 建立狀態

    Example: 3D 建立成功後寫入 order_note
      Given PayUni /iframe/merchant_trade 回傳以下 raw_response：
        | Status  | Message       |
        | SUCCESS | 建立幕後3D成功 |
      When 系統執行訂單 1001 的 process_payment
      Then 訂單 1001 的 order_note 應包含「3D 驗證已建立，等待 webhook 回傳交易結果」
      And 訂單 1001 的 order_note 應包含「狀態碼：SUCCESS」
      And 訂單 1001 的 order_note 應包含「交易訊息：建立幕後3D成功」

  Rule: 後置（狀態）- 3D 流程時不應清除暫存資料

    Example: 3D 流程後暫存資料保留
      Given 訂單 1001 有暫存付款資料：
        | sdk_token_tmp  | payuni_save_card |
        | test_sdk_token | 1                |
      And PayUni /iframe/merchant_trade 回傳不含 EncryptInfo 的回應
      When 系統執行訂單 1001 的 process_payment
      Then 訂單 1001 的 sdk_token_tmp meta 應為 "test_sdk_token"
      And 訂單 1001 的 payuni_save_card meta 應為 "1"

  Rule: 後置（回應）- 3D 流程應回傳 success 並導向 order_received

    Example: 3D 流程 process_payment 回傳 success
      Given PayUni /iframe/merchant_trade 回傳不含 EncryptInfo 的回應
      When 系統執行訂單 1001 的 process_payment
      Then process_payment 回傳值應為：
        | result  | redirect                              |
        | success | 訂單 1001 的 order_received_url        |

  Rule: 前置（參數）- 必要參數必須提供

    Example: 無效的 order_id 時操作失敗
      When 系統執行訂單 9999 的 process_payment
      Then 操作失敗

  Rule: 後置（狀態）- 3D 流程後 Webhook 應正常處理首次付款

    Example: 3D 流程後 Webhook 通知付款成功
      Given 訂單 1001 狀態為 "待付款"
      And 訂單 1001 的 _payuni_v3_resp meta 已存在（3D 流程已執行）
      When PayUni Webhook 到達，解密後 Status 為 "SUCCESS"，TradeNo 為 "PAY202603230001"
      Then 訂單 1001 的狀態應為 "已付款"
      And 訂單 1001 的 order_note 應包含「統一金流 PAYUNi 交易紀錄（Webhook）」

    Example: 3D 流程後 Webhook 通知付款失敗
      Given 訂單 1001 狀態為 "待付款"
      And 訂單 1001 的 _payuni_v3_resp meta 已存在（3D 流程已執行）
      When PayUni Webhook 到達，解密後 Status 為 "ERROR"，Message 為 "不支援分期"
      Then 訂單 1001 的狀態應為 "失敗"
      And 訂單 1001 的 order_note 應包含「統一金流 PAYUNi 信用卡付款失敗」

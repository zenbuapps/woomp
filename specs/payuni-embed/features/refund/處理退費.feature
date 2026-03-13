@ignore @command
Feature: 處理退費

  管理員從 WooCommerce 後台發起退費，系統加密退費參數並呼叫 PayUni /api/trade/close API。

  Background:
    Given 系統中有以下金流設定：
      | merchant_id | hash_key         | hash_iv         | mode |
      | ABC123      | test_hash_key_32 | test_hash_iv_16 | TEST |
    And 系統中有以下訂單：
      | orderId | status | total | payuni_trade_no |
      | 1001    | 已付款  | 500   | PAY202601010001 |

  Rule: 前置（狀態）- 訂單必須有 PayUni 交易編號

    Example: 訂單無交易編號時操作失敗
      Given 訂單 1002 無 payuni_trade_no
      When 管理員對訂單 1002 發起退費，金額 500
      Then 操作失敗

  Rule: 前置（參數）- 退費金額必須大於 0

    退費金額的上限驗證由 PayUni API 負責，系統僅驗證金額大於 0。
    支援部分退費：管理員可指定任意正數金額，WooCommerce 原生退費 UI 會限制不超過訂單金額。

    Example: 退費金額為 0 時操作失敗
      When 管理員對訂單 1001 發起退費，金額 0
      Then 操作失敗

    Example: 部分退費操作成功
      When 管理員對訂單 1001 發起退費，金額 200，原因 "部分退貨"
      Then 操作成功
      And 訂單 1001 應新增備註包含「統一金流退費紀錄」和「退費原因：部分退貨」

  Rule: 後置（狀態）- 退費成功時應新增訂單備註

    Example: 退費成功時記錄退費紀錄
      When 管理員對訂單 1001 發起退費，金額 500，原因 "客戶要求"
      Then 操作成功
      And 訂單 1001 應新增備註包含「統一金流退費紀錄」和「退費原因：客戶要求」

  Rule: 後置（狀態）- 退費請求應以加密方式送出

    Example: 退費請求正確加密並送出
      When 管理員對訂單 1001 發起退費，金額 500
      Then 系統應發送 POST 請求至 "https://sandbox-api.payuni.com.tw/api/trade/close"
      And 請求參數應包含 CloseType 為 2

  Rule: 後置（狀態）- 退費失敗時應回傳 false

    Example: PayUni API 回傳非 SUCCESS 時退費失敗
      Given PayUni 退費 API 回傳 Status 為 "ERROR"
      When 管理員對訂單 1001 發起退費，金額 500
      Then 操作失敗

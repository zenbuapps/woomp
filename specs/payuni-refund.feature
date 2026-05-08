Feature: PayUni 退款處理
  As a 管理員
  I want 透過後台管理介面對 PayUni 交易進行退款
  So that 可以處理消費者的退貨退款需求

  Background:
    Given PayUni 金流已啟用
    And 訂單已使用 PayUni 閘道（payuni-credit / payuni-credit-v3）完成付款
    And 訂單 meta 中有 _payuni_resp_trade_no

  Rule: v1 退款流程（AbstractGateway::process_refund）

    Scenario: v1 退款成功
      Given 訂單使用 payuni-credit 付款
      When 管理員在後台發起 500 元退款
      Then 系統發送 POST 到 api/trade/close
      And 請求參數包含 TradeNo、TradeAmt=500、CloseType=2
      And PayUni 回傳 Status = 'SUCCESS'
      And 訂單備註記錄「統一金流退費紀錄」和退費結果
      And process_refund 回傳 true

    Scenario: v1 退款失敗
      When PayUni API 回傳 Status 非 'SUCCESS'
      Then process_refund 回傳 false

  Rule: v3 退款流程（CreditV3Gateway::process_refund）

    Scenario: v3 退款 — 交易已請款成功（CloseStatus=2）
      Given 訂單使用 payuni-credit-v3 付款
      And PayUni 交易 CloseStatus = '2'
      When 管理員發起 1000 元退款
      Then 系統先呼叫 query_trade 查詢交易狀態
      And 系統呼叫 refund() POST 到 /api/trade/close
      And 訂單備註記錄「PayUni 退款成功，金額 NT$1,000」

    Scenario: v3 退款 — 交易請款申請中（CloseStatus=1）
      Given PayUni 交易 CloseStatus = '1'
      When 管理員發起退款
      Then 系統呼叫 cancel_trade() POST 到 /api/trade/cancel
      And 訂單備註記錄「PayUni 取消請款成功」

    Scenario: v3 退款 — 交易已取消（CloseStatus=3）
      Given PayUni 交易 CloseStatus = '3'
      When 管理員發起退款
      Then 訂單備註記錄「無需退款」
      And process_refund 回傳 true

    Scenario: v3 退款 — 找不到交易編號
      Given 訂單 meta 中沒有 _payuni_resp_trade_no
      When 管理員發起退款
      Then process_refund 回傳 WP_Error「找不到 PayUni 交易編號，無法退款」

    Scenario: v3 退款 — 退款金額為 0
      When 管理員輸入退款金額 0
      Then process_refund 回傳 WP_Error「退款金額必須大於 0」

  Rule: 訂單狀態變更退款（Refund::handle_refund）

    Scenario: 訂單狀態改為 refunded 時自動退款
      Given 訂單使用 payuni-credit 付款
      When 訂單狀態從 processing 變更為 refunded
      Then Refund::handle_refund 被觸發
      And 系統查詢交易 CloseStatus
      And 根據 CloseStatus 執行退款或取消授權
      And 訂單備註記錄退款結果

    Scenario: 非 PayUni 訂單狀態改為 refunded 不處理
      Given 訂單使用其他付款方式（如 linepay）
      When 訂單狀態變更為 refunded
      Then Refund::handle_refund 不執行任何退款操作

  Rule: 5 元取消授權

    Scenario: 記憶卡號 5 元交易取消授權
      Given 系統執行了 5 元扣款以取得 CreditHash
      When Action Scheduler 在 2 分鐘後觸發 payuni_cancel_trade_by_trade_no
      Then 系統發送 POST 到 api/trade/cancel
      And 如果該訂單 meta no_checkout = 'yes'，訂單狀態改為 cancelled

  Rule: 取消信用卡綁定

    Scenario: 取消已綁定的信用卡
      Given 消費者有已綁定的 CreditHash
      When 系統呼叫 cancel_credit_bind($CreditHash)
      Then 系統發送 POST 到 api/credit_bind/cancel
      And 請求參數包含 UseTokenType=1、BindVal=$CreditHash

  Rule: PayUni 退款 API 端點一覽

    Scenario: API 端點對應表
      Then PayUni 退款相關 API 端點如下：
        | 操作     | 端點                  | 說明                |
        | 退款     | api/trade/close       | CloseType=2 發起退款 |
        | 取消授權 | api/trade/cancel      | 取消交易授權          |
        | 查詢交易 | api/trade/query       | Version=2.0 查詢狀態 |
        | 取消綁卡 | api/credit_bind/cancel| UseTokenType=1       |

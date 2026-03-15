Feature: PayUni v1 信用卡分期付款
  As a 消費者
  I want 使用信用卡分期付款方式購物
  So that 可以將大額消費分攤到多個月繳付

  Background:
    Given PayUni 金流已啟用
    And 信用卡分期閘道已啟用（payuni-credit-installment enabled = yes）
    And 後台已設定可用的分期期數（例如 3、6、12 期）
    And 訂單金額大於等於 10 元

  Rule: 分期付款基本流程

    Scenario: 消費者選擇 6 期分期成功付款
      Given 後台啟用了 3 期、6 期、12 期分期選項
      When 消費者輸入有效的信用卡資料
      And 消費者在下拉選單中選擇 6 期
      And 消費者點擊下單
      Then 系統在交易參數中加入 CardInst = 6
      And PayUni 回傳 Status = 'SUCCESS'
      And 訂單 meta 儲存 _payuni_resp_card_inst = '6'
      And 訂單 meta 儲存 _payuni_resp_first_amt（首期金額）
      And 訂單 meta 儲存 _payuni_resp_each_amt（每期金額）
      And 訂單狀態更新為 processing

    Scenario: 消費者未選擇分期期數
      When 消費者未在下拉選單中選擇任何分期期數
      And 消費者點擊下單
      Then 結帳頁顯示錯誤訊息「Credit card installment period is required」
      And 付款流程中止

  Rule: 分期付款交易明細

    Scenario: 消費者檢視分期付款訂單明細
      Given 消費者已使用 payuni-credit-installment 完成分期付款
      When 消費者檢視訂單詳情頁面
      Then 頁面顯示交易明細表格
      And 表格包含：狀態碼、交易訊息、交易編號、卡號末四碼
      And 表格額外包含：分期期數、首期金額、每期金額

  Rule: 分期付款後台設定

    Scenario: 管理員設定可用分期期數
      Given 管理員進入 payuni-credit-installment 閘道設定頁面
      When 管理員在 number_of_periods multiselect 中選擇 3 期和 6 期
      And 儲存設定
      Then 結帳頁面的分期下拉選單僅顯示 3 期和 6 期選項

  Rule: v3 分期付款整合

    Scenario: v3 UNi Embed 消費者選擇分期付款
      Given payuni-credit-v3 閘道已啟用
      And 閘道設定中 installment_options 包含 [3, 6, 12]
      When 消費者在分期付款下拉選單選擇 12 期
      And 消費者完成付款
      Then TradeReqHashDTO 中 CardInst = 12
      And 前端 PayUniService 在 getTradeResult config 中傳遞 cardInst = 12

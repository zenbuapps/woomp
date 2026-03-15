Feature: EZPAY 電子發票作廢
  As a 店家管理員
  I want 作廢已開立的 ezPay 電子發票
  So that 在退款或取消訂單時正確處理發票

  Background:
    Given EZPAY 電子發票模組已啟用
    And ezPay API 金鑰已設定

  Rule: 自動作廢模式

    Scenario: 訂單退款時自動作廢發票
      Given 作廢模式為「自動」（wc_woomp_ezpay_invoice_invalid_mode = auto）
      And 自動作廢觸發狀態為「已退款」（wc_woomp_ezpay_invoice_invalid_at = wc-refunded）
      And 訂單 #123 已開立發票（_ezpay_invoice_status = 1）
      When 訂單 #123 狀態變更為「已退款」
      Then 系統先查詢發票資訊（info API）
      And 系統呼叫 ezPay invalid API 作廢發票
      And 訂單 meta _ezpay_invoice_number 清空
      And 訂單 meta _ezpay_invoice_result 清空
      And 訂單備註新增作廢結果

    Scenario: 發票未開立時不觸發作廢
      Given 訂單 #123 未開立發票（_ezpay_invoice_status != 1）
      When 訂單狀態變更為觸發狀態
      Then 系統不呼叫 ezPay API

  Rule: 手動作廢模式

    Scenario: 管理員在訂單列表手動作廢發票
      Given 訂單 #123 已開立發票且顯示發票號碼
      When 管理員點擊「作廢發票」按鈕
      Then 瀏覽器彈出確認對話框「確定要刪除此筆發票」
      And 管理員確認後送出 AJAX 請求（action: invalid_invoice_ezpay）

    Scenario: 管理員在訂單編輯頁作廢發票
      Given 訂單已開立發票
      And metabox 顯示「作廢發票」按鈕
      When 管理員點擊「作廢發票」按鈕
      Then 系統透過 AJAX 呼叫 ezPay API 作廢發票

  Rule: 作廢流程需先查詢發票

    Scenario: 作廢前先查詢發票以取得 RandomNum
      Given 訂單 #123 已開立發票
      And _ezpay_invoice_result 中包含 MerchantOrderNo, InvoiceNumber, RandomNum
      When 觸發作廢
      Then 系統先呼叫 info API（SearchType=0）查詢發票資訊
      And 再呼叫 invalid API 傳入 InvoiceNumber, InvalidReason='發票作廢', RandomNum

  Rule: API 回傳處理

    Scenario: 作廢成功
      Given ezPay API 回傳 isOK = true 且 code = 'SUCCESS'
      When 處理回傳結果
      Then 訂單備註寫入「ezPay電子發票作廢結果」含發票號碼與作廢時間
      And _ezpay_invoice_number 清空
      And _ezpay_invoice_result 清空

    Scenario: 作廢失敗但錯誤碼為 LIB10005
      Given ezPay API 回傳錯誤碼 LIB10005
      When 處理回傳結果
      Then 訂單備註寫入錯誤訊息
      And 仍然清空 _ezpay_invoice_number 與 _ezpay_invoice_result

    Scenario: 作廢失敗（一般錯誤）
      Given ezPay API 回傳其他錯誤
      When 處理回傳結果
      Then 訂單備註寫入錯誤訊息與錯誤碼
      And 不清空發票 meta 資料

  # 備註：EZPAY 不支援折讓功能
  # EzPayInvoiceHandler 中僅實作 generate_invoice() 與 invalid_invoice()
  # 無折讓相關方法或 UI

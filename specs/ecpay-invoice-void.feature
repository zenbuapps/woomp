Feature: 綠界電子發票作廢
  As a 店家管理員
  I want 作廢已開立的綠界電子發票
  So that 在退款或取消訂單時正確處理發票

  Background:
    Given 綠界電子發票模組已啟用
    And 綠界 API 金鑰已設定

  Rule: 自動作廢模式

    Scenario: 訂單退款時自動作廢發票
      Given 作廢模式為「自動」（wc_woomp_ecpay_invoice_invalid_mode = auto）
      And 自動作廢觸發狀態為「已退款」（wc_woomp_ecpay_invoice_invalid_at = wc-refunded）
      And 訂單 #123 已開立發票（_ecpay_invoice_status = 1）
      When 訂單 #123 狀態變更為「已退款」
      Then 系統呼叫綠界 Invoice/IssueInvalid API 作廢發票
      And 訂單 meta _ecpay_invoice_status 更新為 0
      And 訂單 meta _ecpay_invoice_number 清空
      And 訂單備註新增作廢結果

    Scenario: 發票未開立時不觸發作廢
      Given 作廢模式為「自動」
      And 訂單 #123 未開立發票（_ecpay_invoice_status != 1）
      When 訂單 #123 狀態變更為觸發狀態
      Then 系統不呼叫綠界 API

  Rule: 手動作廢模式

    Scenario: 管理員在訂單列表手動作廢發票
      Given 訂單 #123 已開立發票且顯示發票號碼
      When 管理員點擊「作廢發票」按鈕
      Then 瀏覽器彈出確認對話框「確定要刪除此筆發票」
      And 管理員確認後送出 AJAX 請求（action: invalid_invoice）

    Scenario: 管理員在訂單編輯頁作廢發票
      Given 訂單已開立發票
      And 訂單編輯頁 metabox 顯示「作廢發票」按鈕
      When 管理員點擊「作廢發票」按鈕
      Then 系統透過 AJAX 呼叫綠界 API 作廢發票

  Rule: 作廢可用訂單狀態

    Scenario Outline: 不同訂單狀態的自動作廢
      Given 作廢觸發狀態設定為 <status>
      And 訂單已開立發票
      When 訂單狀態變更為 <status>
      Then 發票自動作廢

      Examples:
        | status     |
        | wc-refunded |
        | wc-failed   |

  Rule: API 回傳處理

    Scenario: 作廢成功
      Given 綠界 API 回傳 RtnCode = 1
      When 處理回傳結果
      Then 訂單備註寫入「Ecpay invalid invoice result」含發票號碼
      And _ecpay_invoice_status 設為 0
      And _ecpay_invoice_number 清空

    Scenario: 發票已被作廢（重複作廢）
      Given 綠界 API 回傳 RtnCode = 5070453
      When 處理回傳結果
      Then 系統視為成功
      And _ecpay_invoice_status 設為 0
      And _ecpay_invoice_number 清空

    Scenario: 作廢失敗
      Given 綠界 API 回傳其他 RtnCode
      When 處理回傳結果
      Then 訂單備註寫入錯誤訊息
      And 不更新發票狀態 meta

Feature: 立吉富電子發票開立
  As a 店家管理員
  I want 透過立吉富 PayNow API 為訂單開立電子發票
  So that 符合台灣電子發票法規並提供顧客發票

  Background:
    Given 立吉富電子發票模組已啟用（wc_settings_tab_active_paynow_einvoice = yes）
    And 商家編號（mem_cid）與密碼（mem_password）已設定

  Rule: 自動開立模式

    Scenario: 訂單狀態變更時自動開立發票
      Given 開立模式為「自動」（wc_settings_tab_issue_mode = auto）
      And 自動開立觸發狀態為「處理中」（wc_settings_tab_issue_at = processing）
      And 訂單 #123 尚未開立發票（_paynow_ei_issued != yes）
      When 訂單 #123 狀態變更為「處理中」
      Then 系統透過 SOAP 呼叫 UploadInvoice_Patch API
      And 訂單 meta _paynow_ei_issued 更新為 'yes'
      And 訂單 meta _paynow_ei_result_invoice_number 更新為發票號碼
      And 訂單備註新增「E-Invoice issued successfully.」
      And 系統額外呼叫 Get_InvoiceURL_I 取得發票查詢 URL
      And 訂單 meta _paynow_invoice_url 更新為發票 URL

    Scenario: 手動模式下訂單狀態變更不自動開立
      Given 開立模式為「手動」（wc_settings_tab_issue_mode = manual）
      When 訂單狀態變更為任何狀態
      Then 系統不自動開立發票

    Scenario: 已開立發票的訂單不重複開立
      Given 訂單 #123 已開立發票（_paynow_ei_issued = yes）
      When 訂單狀態再次變更為觸發狀態
      Then 系統不重複呼叫 API

  Rule: 手動開立模式

    Scenario: 管理員在訂單編輯頁手動開立發票
      Given 訂單 #123 尚未開立發票
      And 訂單編輯頁顯示「PayNow E-Invoice」metabox
      And metabox 中顯示「Issue E-Invoice」按鈕
      When 管理員點擊「Issue E-Invoice」按鈕
      Then 前端送出 AJAX GET 請求（action: paynow_issue_einvoice）
      And 系統驗證 Nonce（paynow_issue_einvoice）
      And 系統呼叫 API 開立發票

    Scenario: 管理員手動開立時 Nonce 驗證失敗
      When Nonce 驗證失敗
      Then 回傳 JSON error「unsecure ajax call」

    Scenario: 已開立發票時手動開立被拒絕
      Given 訂單 #123 已開立發票
      When 管理員嘗試手動開立
      Then 回傳 JSON error「E-Invoice is already issued.」

  Rule: 批次開立模式

    Scenario: 管理員在訂單列表批次開立發票
      Given 管理員在訂單列表選取訂單 #123, #456, #789
      When 執行批次動作「Issue PayNow E-Invoice」
      Then 系統將三筆訂單的資料組成 CSV 格式
      And 透過單一 SOAP 請求批次開立
      And 每筆成功的訂單分別更新 meta 與備註
      And 頁面顯示「X E-Invoice are issued」通知

  Rule: 發票索取類型 — B2C（個人）

    Scenario: 個人發票 — 雲端會員載具（預設）
      Given 顧客選擇索取方式為「個人」
      And 載具類型為空（雲端會員載具）
      When 開立發票
      Then CarrierType = ''
      And CarrierID_1 = ''

    Scenario: 個人發票 — 手機條碼
      Given 顧客選擇索取方式為「個人」
      And 載具類型為「手機條碼」（ei_carrier_type_mobile_code）
      And 載具編號為「/ABC1234」
      When 開立發票
      Then CarrierType = '3J0002'
      And CarrierID_1 = '/ABC1234'
      And CarrierID_2 = '/ABC1234'

    Scenario: 個人發票 — 自然人憑證
      Given 顧客選擇索取方式為「個人」
      And 載具類型為「自然人憑證」（ei_carrier_type_cdc_code）
      When 開立發票
      Then CarrierType = 'CQ0001'

    Scenario: 個人發票 — 悠遊卡
      Given 顧客選擇索取方式為「個人」
      And 載具類型為「悠遊卡」（ei_carrier_type_easycard_code）
      When 開立發票
      Then CarrierType = '1K0001'

  Rule: 發票索取類型 — B2B（公司）

    Scenario: 公司發票
      Given 顧客選擇索取方式為「公司」
      And 公司名稱（buyer_name）為「測試公司」
      And 統一編號（ubn）為「12345678」
      When 開立發票
      Then buyer_id = '12345678'
      And buyer_name = '測試公司'

  Rule: 發票索取類型 — 捐贈

    Scenario: 捐贈發票
      Given 顧客選擇索取方式為「捐贈」
      And 捐贈機構愛心碼為「25885」
      When 開立發票
      Then LoveCode = '25885'（截斷至 8 字元）
      And buyer_id = ''
      And buyer_add = ''

  Rule: 商品明細

    Scenario: 訂單含商品、運費與費用
      Given 訂單包含商品 A（數量 2，總額 200）、運費 60 元、費用 30 元
      When 組裝發票資料
      Then 產生 3 列 CSV 資料
      And 每列包含 Description, Quantity, UnitPrice, Amount 等欄位
      And 運費與費用的 Quantity = 1

  Rule: 稅務設定

    Scenario Outline: 不同稅率類型
      Given 稅率設定為 <tax_type>
      When 組裝發票資料
      Then ItemTaxtype = '<tax_type>'

      Examples:
        | tax_type | 說明         |
        | 1        | 應稅（5%）   |
        | 2        | 零稅率（0%） |
        | 3        | 免稅（0%）   |

  Rule: 買方地址處理

    Scenario: 地址前綴 BRING 不寄送紙本
      Given 訂單有帳單地址
      When 組裝買方地址
      Then buyer_add = 'BRING' + state + city + address_1 + address_2
      # BRING 前綴表示保留地址資訊但不寄送紙本發票

  Rule: 作廢發票

    Scenario: 管理員手動作廢已開立的發票
      Given 訂單 #123 已開立發票
      And metabox 顯示「Cancel E-Invoice」按鈕
      When 管理員點擊「Cancel E-Invoice」按鈕
      Then 前端送出 AJAX GET 請求（action: paynow_cancel_einvoice）
      And 系統透過 SOAP 呼叫 CancelInvoice_I API
      And 成功時（回傳 'S'）：
        更新 _paynow_ei_issued = 'no'
        刪除 _paynow_ei_result_invoice_number
        刪除 _paynow_invoice_url
        訂單備註「Cancel E-Invoice Successfully:」+ 發票號碼
      And 失敗時（回傳 'F_...'）：
        訂單備註「Cancel E-Invoice Failed:」+ 錯誤訊息

  Rule: 測試模式

    Scenario: 測試模式使用測試環境
      Given 測試模式已啟用（wc_settings_tab_paynow_einvoice_sandbox = yes）
      When 建構 Paynow_Einvoice
      Then API URL 為 https://testinvoice.paynow.com.tw

    Scenario: 正式模式使用正式環境
      Given 測試模式未啟用
      When 建構 Paynow_Einvoice
      Then API URL 為 https://invoice.paynow.com.tw

  Rule: 訂單詳情顯示

    Scenario: 前台訂單詳情顯示發票資訊
      Given 訂單已開立發票
      When 顧客查看訂單詳情頁
      Then 顯示「PayNow E-Invoice Details」區塊
      And 顯示索取方式、發票號碼
      And 若有發票 URL 則顯示為超連結

    Scenario: 後台訂單列表顯示發票圖示
      Given 訂單列表包含 paynow_einvoice 欄位
      When 管理員查看訂單列表
      Then 已開立發票的訂單顯示 issued 圖示（含發票號碼 title）
      And 未開立發票的訂單顯示 unissue 圖示

  Rule: 結帳欄位驗證

    Scenario: 公司發票必填驗證
      Given 顧客選擇索取方式為「公司」
      When 公司名稱或統一編號為空
      Then 顯示錯誤「Please input the company name and Unified Business NO」

    Scenario: 載具編號必填驗證
      Given 顧客選擇索取方式為「個人」
      And 選擇了非雲端的載具類型
      When 載具編號為空
      Then 顯示錯誤「Please input the carrier number」

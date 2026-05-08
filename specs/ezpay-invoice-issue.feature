Feature: EZPAY 電子發票開立
  As a 店家管理員
  I want 透過藍新 ezPay API 為訂單開立電子發票
  So that 符合台灣電子發票法規並提供顧客發票

  Background:
    Given EZPAY 電子發票模組已啟用（wc_woomp_enabled_ezpay_invoice = yes）
    And ezPay API 金鑰已設定（merchant_id, hashkey, hashiv）

  Rule: 自動開立模式

    Scenario: 訂單狀態變更時自動開立發票
      Given 開立模式為「自動」（wc_woomp_ezpay_invoice_issue_mode = auto）
      And 自動開立觸發狀態為「處理中」（wc_woomp_ezpay_invoice_issue_at = processing）
      And 訂單 #123 的發票尚未開立（_ezpay_invoice_number 為空）
      When 訂單 #123 狀態變更為「處理中」
      Then 系統呼叫 ezPay API 開立發票
      And 訂單備註新增開立結果
      And 訂單 meta _ezpay_invoice_number 更新為回傳的發票號碼
      And 訂單 meta _ezpay_invoice_result 更新為完整回傳物件

  Rule: 手動開立模式

    Scenario: 管理員在訂單列表手動開立發票
      Given 開立模式為「手動」（wc_woomp_ezpay_invoice_issue_mode = manual）
      When 管理員在訂單列表點擊「開立發票」按鈕
      Then 前端送出 AJAX 請求（action: gen_invoice_ezpay）
      And 系統先更新訂單的發票 meta 資料
      And 系統呼叫 ezPay API 開立發票

    Scenario: 管理員在訂單編輯頁手動開立發票
      Given 管理員在訂單編輯頁的「藍新 ezPay 電子發票」metabox 中
      When 管理員點擊「開立發票」按鈕
      Then 系統更新發票 meta 並呼叫 ezPay API

    Scenario: 管理員更新發票資料不開立
      Given 管理員在 metabox 中修改了發票欄位
      When 管理員點擊「更新發票資料」按鈕
      Then 前端送出 AJAX 請求（action: update_invoice_ezpay）
      And 系統僅更新 _ezpay_invoice_data meta，不呼叫 API

  Rule: 發票類型 — B2C（個人）

    Scenario: 個人發票 — 手機條碼
      Given 顧客選擇發票類型為「個人」
      And 個人發票選項為「手機條碼」
      And 載具編號為「/ABC+123」
      When 開立發票
      Then Category = 'B2C'
      And CarrierType = 0（手機條碼）
      And CarrierNum = "/ABC+123"
      And PrintFlag = 'N'

    Scenario: 個人發票 — 自然人憑證
      Given 顧客選擇發票類型為「個人」
      And 個人發票選項為「自然人憑證」
      And 載具編號為「AB12345678901234」
      When 開立發票
      Then CarrierType = 1（自然人憑證）
      And CarrierNum = "AB12345678901234"
      And PrintFlag = 'N'

    Scenario: 個人發票 — ezPay 電子發票載具
      Given 顧客選擇發票類型為「個人」
      And 個人發票選項為「ezPay 電子發票載具」
      When 開立發票
      Then CarrierType = 2
      And CarrierNum = rawurlencode(顧客 email)
      And KioskPrintFlag = 1
      And PrintFlag = 'N'

    Scenario: 個人發票 — 雲端電子發票載具
      Given 顧客選擇發票類型為「個人」
      And 個人發票選項為「雲端電子發票載具」
      When 開立發票
      Then CarrierType = 2
      And CarrierNum = rawurlencode(顧客 email)
      And KioskPrintFlag = 1

    Scenario: 個人發票 — 無載具無捐贈時列印
      Given 顧客選擇發票類型為「個人」
      And 載具類型為空
      And 無捐贈
      When 開立發票
      Then PrintFlag = 'Y'（列印紙本）

  Rule: 發票類型 — B2B（公司）

    Scenario: 公司發票
      Given 顧客選擇發票類型為「公司」
      And 公司名稱為「測試公司」
      And 統一編號為「12345678」
      When 開立發票
      Then Category = 'B2B'
      And BuyerName = "測試公司"
      And BuyerUBN = "12345678"
      And CarrierType = ''
      And PrintFlag = 'Y'

  Rule: 發票類型 — 捐贈

    Scenario: 捐贈發票
      Given 顧客選擇發票類型為「捐贈」
      And 捐贈碼為「25885」
      When 開立發票
      Then LoveCode = "25885"
      And CarrierType = ''

  Rule: 商品明細

    Scenario: 含折價券與運費的訂單
      Given 訂單包含商品 A（數量 2，小計 200）
      And 折價券 COUPON1 折扣 30 元
      And 運費為 60 元
      When 開立發票
      Then ItemName = "商品A|COUPON1|運費"
      And ItemCount = "2|1|1"
      And ItemUnit = "件|式|式"
      And ItemPrice = "100|-30|60"
      And ItemAmt = "200|-30|60"

    Scenario: 商品名稱超過 30 字元自動截斷
      Given 訂單包含名稱超過 30 字元的商品
      When 組裝發票明細
      Then 商品名稱截斷至 30 字元（UTF-8）

  Rule: 稅額計算

    Scenario: 固定 5% 稅率計算
      Given 訂單總額為 1050 元
      When 計算稅額
      Then Amt = 1000（round(1050 / 1.05)）
      And TaxAmt = 50（1050 - 1000）
      And TotalAmt = 1050
      And TaxType = 1
      And TaxRate = 5

  Rule: 測試模式

    Scenario: 測試模式使用測試環境
      Given 測試模式已啟用（wc_woomp_ezpay_invoice_testmode_enabled = yes）
      When 建構 EzPayInvoiceHandler
      Then 使用測試環境 API（cinv.ezpay.com.tw）
      And 使用測試商家編號與金鑰

    Scenario: 正式模式使用正式環境
      Given 測試模式未啟用
      When 建構 EzPayInvoiceHandler
      Then 使用正式環境 API（inv.ezpay.com.tw）
      And 使用正式商家編號與金鑰

  Rule: API 回傳處理

    Scenario: 開立成功
      Given ezPay API 回傳 code = 'SUCCESS'
      When 處理回傳結果
      Then 訂單備註寫入「ezPay電子發票開立結果」含發票號碼與開立時間
      And _ezpay_invoice_number 設為 InvoiceNumber
      And _ezpay_invoice_result 設為完整回傳物件

    Scenario: 開立失敗
      Given ezPay API 回傳非 SUCCESS
      When 處理回傳結果
      Then 訂單備註寫入失敗訊息
      And _ezpay_invoice_result 設為錯誤訊息文字

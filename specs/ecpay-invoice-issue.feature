Feature: 綠界電子發票開立
  As a 店家管理員
  I want 透過綠界 API 為訂單開立電子發票
  So that 符合台灣電子發票法規並提供顧客發票

  Background:
    Given 綠界電子發票模組已啟用（wc_woomp_enabled_ecpay_invoice = yes）
    And 綠界 API 金鑰已設定（merchant_id, hashkey, hashiv）

  Rule: 自動開立模式

    Scenario: 訂單狀態變更時自動開立發票
      Given 開立模式為「自動」（wc_woomp_ecpay_invoice_issue_mode = auto）
      And 自動開立觸發狀態為「處理中」（wc_woomp_ecpay_invoice_issue_at = wc-processing）
      And 訂單 #123 的發票尚未開立（_ecpay_invoice_status = 0 或 _ecpay_invoice_number 為空）
      When 訂單 #123 狀態變更為「處理中」
      Then 系統呼叫綠界 Invoice/Issue API 開立發票
      And 訂單備註新增開立結果資訊
      And 訂單 meta _ecpay_invoice_status 更新為 1
      And 訂單 meta _ecpay_invoice_number 更新為綠界回傳的發票號碼

    Scenario: 已開立發票的訂單不重複開立
      Given 訂單 #123 已開立發票（_ecpay_invoice_number 不為空）
      When 訂單 #123 狀態再次變更為觸發狀態
      Then 系統不呼叫綠界 API
      And 不產生任何變更

  Rule: 手動開立模式

    Scenario: 管理員在訂單列表手動開立發票
      Given 開立模式為「手動」（wc_woomp_ecpay_invoice_issue_mode = manual）
      When 管理員在訂單列表點擊「開立發票」按鈕
      Then 前端送出 AJAX 請求（action: gen_invoice）
      And 系統先更新訂單的發票 meta 資料
      And 系統呼叫綠界 API 開立發票
      And 前端顯示開立結果 alert
      And 頁面重新載入

    Scenario: 管理員在訂單編輯頁手動開立發票
      Given 管理員在訂單編輯頁的「綠界電子發票(好用版)」metabox 中
      When 管理員點擊「開立發票」按鈕
      Then 前端送出 AJAX 請求包含目前 metabox 中的發票欄位值
      And 系統更新發票 meta 並呼叫綠界 API

    Scenario: AJAX 開立時 Nonce 驗證失敗
      Given 管理員送出開立請求
      When Nonce 驗證失敗
      Then 系統回傳 JSON error「發生錯誤，不合法的請求來源！」

  Rule: 零元訂單不開立

    Scenario: 訂單金額為零時不開立發票
      Given 訂單 #456 的 order_total 為 0
      When 觸發開立發票
      Then 系統直接 return 不呼叫 API

    Scenario: 訂閱商品的零元訂單仍顯示發票欄位
      Given 購物車包含訂閱類型商品（product_type 包含 subscription）
      And 購物車總金額為 0
      When 顧客進入結帳頁
      Then 發票欄位仍然顯示

  Rule: 發票類型處理

    Scenario: 個人發票 — 雲端發票（無載具）
      Given 顧客選擇發票類型為「個人」
      And 個人發票選項為「雲端發票」
      When 開立發票
      Then CarruerType = 1（會員載具）
      And Print = 0（不列印）
      And CarruerNum 為空

    Scenario: 個人發票 — 手機條碼
      Given 顧客選擇發票類型為「個人」
      And 個人發票選項為「手機條碼」
      And 載具編號為「/ABC+123」
      When 開立發票
      Then CarruerType = 3
      And CarruerNum = "/ABC+123"
      And Print = 0

    Scenario: 個人發票 — 自然人憑證
      Given 顧客選擇發票類型為「個人」
      And 個人發票選項為「自然人憑證」
      And 載具編號為「AB12345678901234」
      When 開立發票
      Then CarruerType = 2
      And CarruerNum = "AB12345678901234"
      And Print = 0

    Scenario: 個人發票 — 紙本發票
      Given 顧客選擇發票類型為「個人」
      And 個人發票選項為「紙本發票」
      When 開立發票
      Then CarruerType 為空
      And Print = 1（強制列印）

    Scenario: 公司發票
      Given 顧客選擇發票類型為「公司」
      And 統一編號為「12345678」
      And 公司名稱為「測試公司」
      When 開立發票
      Then CustomerIdentifier = "12345678"
      And CustomerName = "測試公司"
      And Print = 0
      And Donation = 0

    Scenario: 捐贈發票
      Given 顧客選擇發票類型為「捐贈」
      And 捐贈碼為「25885」
      When 開立發票
      Then Donation = 1
      And LoveCode = "25885"
      And Print = 0

  Rule: 商品明細計算

    Scenario: 含運費與折扣的訂單
      Given 訂單包含商品 A（數量 2，小計含稅 200）
      And 運費含稅為 60 元
      And 手動折扣為 -10 元
      When 開立發票
      Then 發票明細包含商品 A（ItemCount=2, ItemPrice=100, ItemAmount=200）
      And 發票明細包含運費（ItemCount=1, ItemPrice=60, ItemAmount=60）
      And 發票明細包含折扣（ItemCount=1, ItemPrice=-10, ItemAmount=-10）
      And SalesAmount = 250

    Scenario: 金額微差校正（2 元內）
      Given 因計算精度導致 items 加總與 order_total 差 1 元
      When 開立發票
      Then 系統將 order_total 調整為 items 加總金額

  Rule: 測試模式

    Scenario: 測試模式使用測試環境參數
      Given 測試模式已啟用（wc_woomp_ecpay_invoice_testmode_enabled = yes）
      When 開立發票
      Then API URL 為 https://einvoice-stage.ecpay.com.tw/Invoice/Issue
      And MerchantID 為 2000132
      And HashKey 為 ejCk326UnaZWKisg
      And HashIV 為 q9jcZX8Ib9LM8wYk

    Scenario: 正式模式優先使用 RY 插件金鑰
      Given 測試模式未啟用
      And RY_WEI_ecpay_MerchantID 已設定
      When 開立發票
      Then 使用 RY_WEI_ecpay_* 系列選項作為 API 金鑰

  Rule: API 回傳處理

    Scenario: 開立成功
      Given 綠界 API 回傳 RtnCode = 1
      When 處理回傳結果
      Then 訂單備註寫入「Invoice issue result」含發票號碼與開立時間
      And _ecpay_invoice_status 設為 1
      And _ecpay_invoice_number 設為回傳的 InvoiceNumber

    Scenario: 開立失敗
      Given 綠界 API 回傳 RtnCode != 1
      When 處理回傳結果
      Then 訂單備註寫入「Invoice issue faild」含錯誤訊息
      And 不更新 _ecpay_invoice_status

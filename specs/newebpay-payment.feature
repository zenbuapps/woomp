Feature: 藍新金流付款
  As a 消費者
  I want 透過藍新金流（NewebPay）提供的付款方式完成線上付款
  So that 我可以使用信用卡、ATM、超商代碼或條碼等方式支付訂單

  Background:
    Given 網站已啟用藍新金流（RY_WT_enabled_newebpay_gateway = yes）
    And 藍新金流已設定 MerchantID、HashKey、HashIV

  Rule: 閘道註冊

    Scenario: 藍新金流閘道正確註冊
      When WooCommerce 載入付款閘道
      Then 應註冊 6 種基本閘道加上可選的 6 種獨立分期閘道（3/6/12/18/24/30 期）

  Rule: 加密與安全

    Scenario: TradeInfo 加密驗證
      Given 付款參數已組建完成
      When 產生付款表單
      Then TradeInfo 應使用 AES-256-CBC 加密
      And TradeSha 應為 SHA256(HashKey={key}&{TradeInfo}&HashIV={iv}) 的大寫值

    Scenario: 回調 TradeSha 驗證
      Given 藍新回調包含 TradeInfo 和 TradeSha
      When 驗證回調
      Then 應計算 TradeSha 並與回傳值比對
      And 驗證通過後解密 TradeInfo 取得付款結果

  Rule: 信用卡付款

    Scenario: 信用卡即時付款成功
      Given Customer 選擇藍新信用卡付款
      When 藍新回調 Status=SUCCESS 且包含 PayTime
      Then 訂單應完成付款並設為 processing

  Rule: ATM 虛擬帳號

    Scenario: ATM 取號成功
      When 藍新回調 Status=SUCCESS 且包含 BankCode
      Then 訂單 meta 應儲存 _newebpay_atm_BankCode、_newebpay_atm_vAccount、_newebpay_atm_ExpireDate
      And 訂單狀態應為 on-hold

    Scenario: ATM 過期天數上限
      Given 管理員設定 ATM 過期天數為 200
      When 儲存設定
      Then 過期天數應被重設為 3（允許範圍 1-180）

  Rule: 超商代碼付款

    Scenario: CVS 取號成功
      When 藍新回調 Status=SUCCESS 且包含 CodeNo（無 BankCode）
      Then _newebpay_cvs_PaymentNo 和 _newebpay_cvs_ExpireDate 應被儲存
      And 訂單狀態應為 on-hold

  Rule: 條碼繳費

    Scenario: BARCODE 取號成功
      When 藍新回調 Status=SUCCESS 且包含 Barcode_1
      Then _newebpay_barcode_Barcode1/2/3 和 ExpireDate 應被儲存
      And 訂單狀態應為 on-hold

  Rule: 超商物流整合

    Scenario: 藍新超商物流取貨付款
      Given 訂單使用 ry_newebpay_shipping_cvs 物流
      And 付款方式為信用卡
      When 產生付款表單
      Then CVSCOM 參數應為 1（付款取貨）

    Scenario: 藍新超商物流貨到付款
      Given 訂單使用 ry_newebpay_shipping_cvs 物流
      And 付款方式為 cod
      When 產生付款表單
      Then CVSCOM 參數應為 2（貨到付款）

    Scenario: 超商物流門市資訊從金流回調取得
      Given 訂單使用藍新超商物流
      When 藍新回調包含 StoreCode 欄位
      Then 應儲存 _shipping_cvs_store_ID、_shipping_cvs_store_name、_shipping_cvs_store_address
      And 應建立 _newebpay_shipping_info meta

  Rule: 付款失敗

    Scenario: 未知狀態碼的付款回調
      When 藍新回調 Status 不是 SUCCESS
      Then 訂單應設為 failed
      And 訂單備註應包含錯誤碼和訊息

  Rule: 設定驗證

    Scenario: 藍新金流未設定 API 金鑰
      Given MerchantID 為空
      When 儲存設定
      Then 應顯示啟用失敗訊息
      And newebpay_gateway 應設為 no

    Scenario: 藍新物流需要金流啟用
      Given 藍新金流未啟用
      When 嘗試啟用藍新物流
      Then 應顯示「NewebPay shipping method need enable NewebPay gateway」
      And newebpay_shipping 應設為 no

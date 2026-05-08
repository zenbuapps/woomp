Feature: 綠界金流付款
  As a 消費者
  I want 透過綠界科技提供的多種付款方式完成線上付款
  So that 我可以使用信用卡、ATM 轉帳、超商代碼或條碼繳費等方式支付訂單

  Background:
    Given 網站已啟用綠界金流（RY_WT_enabled_ecpay_gateway = yes）
    And 綠界金流已設定 MerchantID、HashKey、HashIV（測試模式使用預設值 3002599）
    And WooCommerce 已安裝且版本 >= 5.3

  Rule: 閘道註冊與可用性檢查

    Scenario: 綠界金流閘道正確註冊至 WooCommerce
      Given 綠界金流已啟用
      When WooCommerce 載入付款閘道
      Then 應註冊以下閘道：
        | Gateway ID                         | 類別                                    | 付款類型  |
        | ry_ecpay_credit                    | RY_ECPay_Gateway_Credit                 | Credit    |
        | ry_ecpay_credit_installment        | RY_ECPay_Gateway_Credit_Installment     | Credit    |
        | ry_ecpay_webatm                    | RY_ECPay_Gateway_Webatm                 | WebATM    |
        | ry_ecpay_atm                       | RY_ECPay_Gateway_Atm                    | ATM       |
        | ry_ecpay_cvs                       | RY_ECPay_Gateway_Cvc                    | CVS       |
        | ry_ecpay_barcode                   | RY_ECPay_Gateway_Barcode                | BARCODE   |

    Scenario: 獨立分期付款閘道在選項啟用時註冊
      Given wmp_ecpay_credit_installment 選項為 yes
      When WooCommerce 載入付款閘道
      Then 應額外註冊 3/6/12/18/24 期獨立分期閘道（WMP_ECPay_Gateway_Credit_Installment_3 等）

    Scenario: CVS 付款最低金額限制
      Given 購物車總金額為 25 元
      When 檢查 ry_ecpay_cvs 是否可用
      Then 該閘道不可用（最低 31 元）

    Scenario: BARCODE 付款最低金額限制
      Given 購物車總金額為 10 元
      When 檢查 ry_ecpay_barcode 是否可用
      Then 該閘道不可用（最低 16 元）

    Scenario: 自訂最低/最高金額限制
      Given ry_ecpay_atm 設定 min_amount=100, max_amount=50000
      And 購物車總金額為 60000 元
      When 檢查 ry_ecpay_atm 是否可用
      Then 該閘道不可用（超過最高金額）

  Rule: 信用卡一次付清

    Scenario: 信用卡一次付清成功付款
      Given Customer 選擇「綠界信用卡」付款方式
      When Customer 提交訂單
      Then 訂單備註應包含「Pay via ECPay Credit」
      And 庫存應減少
      And 瀏覽器應重導至綠界付款頁面
      And 表單應包含 ChoosePayment=Credit 參數

    Scenario: 信用卡付款完成回調
      Given 訂單已透過綠界信用卡付款
      When ECPay 回調 RtnCode=1
      Then 訂單狀態應變更為 processing（payment_complete）
      And 訂單備註應包含「Payment completed」

  Rule: 信用卡分期付款

    Scenario: 信用卡分期付款選擇期數
      Given Customer 選擇「綠界信用卡分期」付款方式
      And 可選期數為 3, 6, 12
      When Customer 選擇 6 期並提交訂單
      Then 訂單 meta _ecpay_payment_number_of_periods 應為 6
      And 送至綠界的表單應包含 CreditInstallment=6

    Scenario: 獨立分期閘道（3期）
      Given Customer 選擇「綠界信用卡（分三期）」付款方式
      When Customer 提交訂單
      Then 訂單備註應包含「使用綠界信用卡分期付款（分三期）」
      And number_of_periods 固定為 3

  Rule: ATM 虛擬帳號付款

    Scenario: ATM 付款建立虛擬帳號
      Given Customer 選擇「綠界 ATM」付款方式
      When ECPay 回調 RtnCode=2（取號成功）
      Then 訂單 meta 應儲存 _ecpay_atm_BankCode、_ecpay_atm_vAccount、_ecpay_atm_ExpireDate
      And 訂單狀態應變更為 on-hold
      And 訂單備註應包含銀行代碼、虛擬帳號、轉帳期限

    Scenario: ATM 付款完成
      Given 訂單狀態為 on-hold（等待 ATM 轉帳）
      When ECPay 回調 RtnCode=1（付款成功）
      Then 訂單狀態應變更為 processing（payment_complete）

    Scenario: ATM 付款期限驗證
      Given 管理員設定 ATM 過期天數為 70
      When 儲存設定
      Then 過期天數應被重設為預設值 3（允許範圍 1-60）

  Rule: 超商代碼付款

    Scenario: CVS 超商代碼付款取號
      Given Customer 選擇「綠界超商代碼」付款方式
      When ECPay 回調 RtnCode=10100073（取號成功）
      Then 訂單 meta 應儲存 _ecpay_cvs_PaymentNo、_ecpay_cvs_ExpireDate
      And 訂單狀態應變更為 on-hold

  Rule: 條碼繳費

    Scenario: BARCODE 條碼繳費取號
      Given Customer 選擇「綠界條碼繳費」付款方式
      When ECPay 回調 RtnCode=10100073（取號成功）且付款類型為 BARCODE
      Then 訂單 meta 應儲存 _ecpay_barcode_Barcode1/2/3、_ecpay_barcode_ExpireDate
      And 訂單狀態應變更為 on-hold

  Rule: WebATM 即時付款

    Scenario: WebATM 付款成功
      Given Customer 選擇「綠界 WebATM」付款方式
      When ECPay 回調 RtnCode=1
      Then 訂單應完成付款（payment_complete）

  Rule: 付款失敗處理

    Scenario: 付款失敗
      Given 訂單尚未付款
      When ECPay 回調 RtnCode=10100058
      Then 訂單狀態應變更為 failed
      And 訂單備註應包含失敗原因

    Scenario: 已付款訂單收到失敗回調
      Given 訂單已完成付款
      When ECPay 回調 RtnCode=10100058
      Then 訂單應保持原狀態
      And 訂單備註應記錄「Payment failed within paid order」

  Rule: 安全驗證

    Scenario: CheckMacValue 驗證失敗
      Given ECPay 回調包含無效的 CheckMacValue
      When 系統驗證回調
      Then 應回應 '0|' 錯誤
      And 日誌應記錄驗證失敗

    Scenario: 訂單編號前綴驗證
      Given 管理員設定訂單前綴為 'test@123'
      When 儲存設定
      Then 前綴應被清空（僅允許英文字母和數字）

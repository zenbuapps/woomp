Feature: 綠界 ECPay 後台退款
  As a 管理員
  I want 在 WooCommerce 後台對綠界訂單發起退款時，信用卡類自動同步呼叫綠界退款 API
  So that 不需再登入綠界後台手動退刷，也不會誤把無退款 API 的付款方式當成系統故障

  Background:
    Given 網站已啟用綠界金流（RY_WT_enabled_ecpay_gateway = yes）
    And 綠界金流已設定 MerchantID、HashKey、HashIV（測試模式使用預設值 3002599）
    And 訂單已使用某一 ECPay 閘道完成付款，訂單 meta 有 _ecpay_MerchantTradeNo
    And 所有 ECPay 閘道（RY_ECPay_Gateway_Base 子類別）皆宣告 supports[] = 'refunds'

  Rule: 信用卡退款 — 主路徑（Action=R 退刷）

    Scenario: 信用卡全額退款成功
      Given 訂單使用 ry_ecpay_credit 付款，總金額 1000 元，且已有綠界 TradeNo
      When 管理員在後台發起 1000 元退款
      Then 系統先呼叫 QueryTradeInfo 驗證交易狀態
      And 系統呼叫 CreditDetail/DoAction，Action=R，TotalAmount=1000
      And 綠界回傳 RtnCode=1
      And 訂單備註記錄「綠界退款成功（退刷 R，金額 1000）」
      And process_refund 回傳 true

    Scenario: 信用卡部分退款成功
      Given 訂單總金額 1000 元
      When 管理員發起 300 元部分退款
      Then DoAction 送出的 TotalAmount 應為 300（而非訂單全額）
      And process_refund 回傳 true

  Rule: 信用卡退款 — 未關帳降級（Action=N 放棄授權）

    Scenario: 交易尚未關帳且為全額退款時降級為放棄授權
      Given 訂單總金額 1000 元，管理員發起全額退款
      When 第一次 DoAction（Action=R）回傳 RtnMsg 包含「未關帳」
      Then 系統改送第二次 DoAction，Action=N
      And 第二次回傳 RtnCode=1 時，訂單備註記錄「尚未關帳，改以放棄授權 N」
      And process_refund 回傳 true

    Scenario: 未關帳但非全額退款時不降級
      Given 管理員發起未達訂單總額的部分退款
      When DoAction（Action=R）回傳 RtnMsg 包含「未關帳」
      Then process_refund 回傳 WP_Error，訊息包含綠界回傳的 RtnMsg
      And 不觸發 Action=N 降級重試

  Rule: 信用卡分期限制

    Scenario: 分期付款僅支援全額退款
      Given 訂單使用信用卡分期閘道（gateway id 包含 installment）
      When 管理員發起未達訂單總額的部分退款
      Then process_refund 回傳 WP_Error「信用卡分期付款僅支援全額退款，無法部分退款」
      And 不呼叫任何綠界 API

  Rule: 非信用卡付款方式 — 人工退款指引

    Scenario Outline: 非信用卡閘道退款回傳人工退款指引
      Given 訂單使用 <閘道類別> 付款（payment_type = <付款類型>）
      When 管理員在後台發起退款
      Then process_refund 回傳 WP_Error，訊息包含「請登入綠界廠商後台」
      And 不呼叫任何綠界 API

      Examples:
        | 閘道類別                 | 付款類型 |
        | RY_ECPay_Gateway_Atm     | ATM      |
        | RY_ECPay_Gateway_Cvc     | CVS      |
        | RY_ECPay_Gateway_Barcode | BARCODE  |
        | RY_ECPay_Gateway_Webatm  | WebATM   |

  Rule: 退款前置驗證（fail-closed）

    Scenario: 找不到訂單中止退款
      Given 傳入的 order_id 查無對應訂單
      When 管理員發起退款
      Then process_refund 回傳 WP_Error「找不到訂單，無法退款」

    Scenario: 查無綠界交易編號中止退款
      Given 訂單尚未完成付款（transaction_id 為空）
      When 管理員發起退款
      Then process_refund 回傳 WP_Error「查無綠界交易編號，無法透過綠界退款」
      And 不呼叫任何綠界 API

    Scenario: QueryTradeInfo 連線失敗中止退款
      Given 呼叫綠界 QueryTradeInfo 時網路連線失敗
      When 管理員發起退款
      Then process_refund 回傳 WP_Error
      And 僅嘗試 QueryTradeInfo 一次，不送出 DoAction 請求

    Scenario: 訂單尚未付款完成中止退款
      Given QueryTradeInfo 回傳 TradeStatus 非 1
      When 管理員發起退款
      Then process_refund 回傳 WP_Error「訂單尚未完成付款，無法退款」
      And 不送出 DoAction 請求

    Scenario: DoAction 失敗且非「未關帳」原因
      Given DoAction 回傳 RtnCode 非 1 且 RtnMsg 不包含「未關帳」
      When 管理員發起退款
      Then process_refund 回傳 WP_Error，訊息包含綠界回傳的 RtnMsg
      And 不觸發 Action=N 降級重試

  Rule: 安全驗證

    Scenario: DoAction 請求的 CheckMacValue 正確性
      Given 系統送出 CreditDetail/DoAction 請求
      Then 請求所帶的 CheckMacValue 應與依綠界官方演算法（SHA256）獨立重算的結果一致

    Scenario: QueryTradeInfo 回應 CheckMacValue 驗證失敗中止流程
      Given QueryTradeInfo 回應的 CheckMacValue 與重算結果不符
      When 系統以 hash_equals 驗證回應（verify_response_check_value）
      Then process_refund 回傳 WP_Error，流程中止且不標記已退款

    Scenario Outline: DoAction 回應綁定防偽（回應不含 CheckMacValue）
      Given 綠界 CreditDetail/DoAction 回應依官方規格（2885.md）不含 CheckMacValue
      And 回應的 <欄位> 與本訂單不符
      When 系統驗證 DoAction 回應（改以 MerchantTradeNo／TradeNo 綁定原訂單，回應真實性由 TLS 保護）
      Then process_refund 回傳 WP_Error，流程中止且不標記已退款

      Examples:
        | 欄位            |
        | MerchantTradeNo |
        | TradeNo         |

  Rule: 退款相關 API 端點一覽

    Scenario: API 端點對應表
      Then 綠界退款相關 API 端點如下：
        | 操作           | 端點                      | 說明                               |
        | 前置查詢交易   | Cashier/QueryTradeInfo/V5 | 驗證 TradeStatus=1 才允許退款       |
        | 退刷/放棄授權  | CreditDetail/DoAction     | Action=R 退刷；未關帳降級 Action=N  |
      And 測試（Stage）環境不支援 DoAction 真實授權，僅正式環境可實際退刷

Feature: 郵件通知
  As a 顧客
  I want 在訂單狀態變更時收到適當的付款資訊和物流通知郵件
  So that 我可以及時完成付款和取貨

  Background:
    Given WooCommerce 與 Woomp 外掛已啟用
    And WooCommerce 郵件系統正常運作

  Rule: 等待付款郵件附加付款資訊

    Scenario: 綠界 ATM 訂單的等待付款郵件
      Given 訂單 #100 使用 ry_ecpay_atm 付款方式
      And 訂單 meta 包含 _ecpay_atm_BankCode、_ecpay_atm_vAccount、_ecpay_atm_ExpireDate
      When WooCommerce 發送 customer_on_hold_order 郵件
      Then 郵件訂單表格後方附加「付款資訊」區塊
      And 顯示轉帳銀行名稱
      And 顯示銀行代碼
      And 顯示 ATM 繳費帳號（每 4 碼一組）
      And 顯示付款截止日（本地化日期格式）

    Scenario: 綠界超商代碼訂單的等待付款郵件
      Given 訂單 #101 使用 ry_ecpay_cvs 付款方式
      And 訂單 meta 包含 _ecpay_cvs_PaymentNo、_ecpay_cvs_ExpireDate
      When WooCommerce 發送 customer_on_hold_order 郵件
      Then 郵件附加「付款資訊」區塊
      And 顯示繳費代碼
      And 顯示付款期限（日期 + 時間）

    Scenario: 綠界超商條碼訂單的等待付款郵件
      Given 訂單 #102 使用 ry_ecpay_barcode 付款方式
      And 訂單 meta 包含 _ecpay_barcode_Barcode1、Barcode2、Barcode3、ExpireDate
      When WooCommerce 發送 customer_on_hold_order 郵件
      Then 郵件附加「付款資訊」區塊
      And 顯示 3 組條碼（使用 Libre Barcode 39 Text 字型渲染）
      And 顯示付款期限

    Scenario: 非綠界付款方式不附加額外資訊
      Given 訂單 #103 使用 payuni-credit 付款方式
      When WooCommerce 發送 customer_on_hold_order 郵件
      Then 郵件不附加額外的「付款資訊」區塊

    Scenario: 非等待付款郵件不附加資訊
      Given 訂單 #100 使用 ry_ecpay_atm 付款方式
      When WooCommerce 發送 customer_processing_order 郵件
      Then 郵件不附加「付款資訊」區塊

  Rule: 郵件觸發 Action 註冊

    Scenario: 綠界物流和 ATM 郵件 action 被註冊
      When WooCommerce 郵件系統初始化
      Then 以下 actions 被註冊為郵件觸發器：
        | action 名稱                                |
        | ry_ecpay_shipping_cvs_to_store             |
        | ry_ecpay_shipping_cvs_to_transporting      |
        | ry_ecpay_shipping_cvs_get_remind           |
        | ry_ecpay_shipping_cvs_get_expired          |
        | ry_ecpay_shipping_atm_transfer_remind      |

  Rule: 超商取貨到期前一天通知

    Scenario: 發送超商取貨提醒（到期前一天）
      Given 今天是 2026-03-15
      And 訂單 #200 狀態為 ry-at-cvs（到達超商）
      And 訂單 #200 的 ecpay_cvs_at_store_expired 為 "2026-03-16"
      And 郵件 RY_ECPay_Shipping_Email_Customer_CVS_Get_Remind 已啟用
      When 每日 10:00 排程 wmp_cron_every_morning 觸發
      Then 訂單 #200 的顧客收到取貨提醒郵件

    Scenario: 無到期訂單不發送郵件
      Given 今天是 2026-03-15
      And 沒有訂單的 ecpay_cvs_at_store_expired 為 "2026-03-16"
      When 每日排程觸發
      Then 不發送任何取貨提醒郵件

    Scenario: 郵件已停用不發送
      Given 有訂單明天到期
      And 郵件 RY_ECPay_Shipping_Email_Customer_CVS_Get_Remind 已停用
      When 每日排程觸發
      Then 不發送取貨提醒郵件

  Rule: 超商取貨到期當天通知

    Scenario: 發送超商取貨到期通知
      Given 今天是 2026-03-15
      And 訂單 #201 狀態為 ry-at-cvs
      And 訂單 #201 的 ecpay_cvs_at_store_expired 為 "2026-03-15"
      And 郵件 RY_ECPay_Shipping_Email_Customer_CVS_Get_Expired 已啟用
      When 每日排程觸發
      Then 訂單 #201 的顧客收到取貨到期通知郵件

  Rule: ATM 轉帳到期前一天提醒

    Scenario: 發送 ATM 轉帳提醒
      Given 訂單 #300 使用 ry_ecpay_atm 付款方式
      And 綠界 ATM 到期天數為 3 天
      And 訂單建立於 2026-03-12
      And 訂單 #300 狀態仍為 "on-hold"
      And 郵件 RY_ECPay_Shipping_Email_Customer_ATM_Transfer_Remind 已啟用
      When 到期前一天（2026-03-14）的排程 wmp_cron_atm_deadline_remind 觸發
      Then 顧客收到 ATM 轉帳提醒郵件

    Scenario: 已付款訂單不發送 ATM 提醒
      Given 訂單 #300 使用 ry_ecpay_atm 付款方式
      And 訂單 #300 已在到期前付款，狀態為 "processing"
      When wmp_cron_atm_deadline_remind 排程觸發
      Then 不發送轉帳提醒郵件

    Scenario: 已取消訂單不發送 ATM 提醒
      Given 訂單 #300 使用 ry_ecpay_atm 付款方式
      And 訂單 #300 已被取消，狀態為 "cancelled"
      When wmp_cron_atm_deadline_remind 排程觸發
      Then 不發送轉帳提醒郵件

  Rule: ATM 訂單到期自動取消

    Scenario: 未付款 ATM 訂單到期自動取消
      Given 訂單 #300 使用 ry_ecpay_atm 付款方式
      And 訂單 #300 狀態為 "pending"
      When 到期日的排程 wmp_cron_atm_deadline 觸發
      Then 訂單 #300 狀態更新為 "cancelled"

    Scenario: 暫停中的 ATM 訂單到期自動取消
      Given 訂單 #300 使用 ry_ecpay_atm 付款方式
      And 訂單 #300 狀態為 "on-hold"
      When wmp_cron_atm_deadline 排程觸發
      Then 訂單 #300 狀態更新為 "cancelled"

    Scenario: 已處理的 ATM 訂單不受影響
      Given 訂單 #300 使用 ry_ecpay_atm 付款方式
      And 訂單 #300 狀態為 "processing"
      When wmp_cron_atm_deadline 排程觸發
      Then 訂單 #300 狀態不變

  Rule: 貨到付款感謝頁面

    Scenario: 貨到付款訂單感謝頁顯示說明
      Given 訂單使用 woomp_cod_gateway 付款方式
      And woomp_cod_gateway 設定的 instructions 為「收到貨時以現金付款。」
      When 顧客被導向感謝頁面
      Then 顯示「收到貨時以現金付款。」說明文字

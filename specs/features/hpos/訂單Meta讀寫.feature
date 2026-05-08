@ignore @command
Feature: 訂單 Meta 讀寫 HPOS 相容
  As a Woomp 外掛的各子模組
  I want 在 HPOS 啟用時正確讀寫訂單 meta
  So that 金流回調、發票開立、物流資訊等功能在 HPOS 模式下不會遺失資料

  Background:
    Given WooCommerce 已啟用 HPOS (custom_order_tables)
    And Woomp 外掛已啟用

  Rule: 金流回調 Meta 寫入（核心路徑）

    Scenario Outline: 金流回調正確寫入訂單 meta（HPOS 模式）
      Given 訂單 #<order_id> 存在且狀態為 "pending"
      When <gateway> 金流回調觸發，寫入交易資料
      Then 訂單 meta 透過 $order->update_meta_data() + $order->save() 正確儲存
      And 使用 $order->get_meta('<meta_key>') 可讀取到正確值

      Examples:
        | order_id | gateway  | meta_key                  |
        | 100      | PayNow   | _paynow_tran_no           |
        | 101      | LINE Pay | _linepay_transaction_id   |
        | 102      | PChomePay| _pchomepay_transaction_id |

  Rule: 發票 Meta 讀寫

    Scenario: 綠界發票開立後儲存發票號碼（HPOS 模式）
      Given 訂單 #100 存在
      When 綠界發票 API 回傳成功，發票號碼為 "AB12345678"
      Then _ecpay_invoice_number 透過 $order->update_meta_data() 儲存
      And $order->get_meta('_ecpay_invoice_number') 回傳 "AB12345678"

    Scenario: 立吉富發票讀取訂單載具資訊（HPOS 模式）
      Given 訂單 #100 的 _paynow_ei_carrier_type meta 為 "phone_barcode"
      When 立吉富發票模組讀取載具資訊
      Then 使用 $order->get_meta('_paynow_ei_carrier_type') 取得 "phone_barcode"

  Rule: 物流 Meta 讀寫

    Scenario: 物流單號透過 AJAX 更新（HPOS 模式）
      Given 管理員在訂單列表輸入物流單號 "TRACK001"
      When AJAX 處理器接收 orderId 和 shippingNo
      Then 使用 $order->update_meta_data('wmp_shipping_no', 'TRACK001') + $order->save()
      And 非使用 update_post_meta()

  Rule: 訂閱 (shop_subscription) Meta 讀寫

    Scenario: 訂閱 meta 操作也使用物件 API
      Given WC_Subscriptions 外掛已啟用
      And 訂閱 #300 存在
      When 程式碼需要刪除訂閱的 _schedule_cancelled meta
      Then 使用 $subscription->delete_meta_data('_schedule_cancelled') + $subscription->save()
      And 非使用 delete_post_meta($subscription_id, '_schedule_cancelled')

    Scenario: 訂閱發票管理 meta 操作使用物件 API
      Given WC_Subscriptions 外掛已啟用
      And 管理員在訂閱編輯頁的發票管理 Metabox 中
      When 讀取或寫入發票欄位 meta
      Then 使用 $subscription->get_meta() / $subscription->update_meta_data()
      And 非使用 get_post_meta() / update_post_meta()

  Rule: 不使用 get_post_meta 操作訂單

    Scenario: 全專案無 get_post_meta 用於訂單/訂閱 context
      When 掃描所有 PHP 檔案（排除 vendor/ 和商品 context）
      Then 無任何 get_post_meta / update_post_meta / add_post_meta / delete_post_meta 用於訂單或訂閱 ID
      # [已決] 由 planner 產出的明確檔案清單驅動，已人工確認 25 個檔案的每個呼叫點

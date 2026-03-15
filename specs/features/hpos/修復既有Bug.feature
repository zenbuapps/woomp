@ignore @command
Feature: 修復 HPOS 遷移過程中發現的既有 Bug
  As a 開發者
  I want 在 HPOS 遷移過程中順便修復發現的 Bug
  So that 程式碼品質整體提升

  Background:
    Given Woomp 外掛已啟用

  Rule: LINE Pay 賦值 Bug

    Scenario: 修正 LINE Pay post_type 判斷
      Given LINE Pay 模組已啟用
      When 程式碼判斷 post type 是否為 shop_order
      Then 使用 === 比較運算子（而非 = 賦值）
      And 同時支援 HPOS screen 判斷

  Rule: LINE Pay 退款刪除方式

    Scenario: 修正 LINE Pay 退款刪除（wp_delete_post → $refund->delete）
      Given LINE Pay 模組已啟用
      And 訂單 #100 有一筆退款
      When 程式碼需要刪除退款記錄
      Then 使用 $refund->delete(true)（WC_Order_Refund API）
      And 非使用 wp_delete_post($refund->id, true)（HPOS 下退款非 post，會失效）

  Rule: 過時的訂單物件建立方式

    Scenario: 修正 new WC_Order() 為 wc_get_order()
      When 程式碼需要取得訂單物件
      Then 使用 wc_get_order($order_id)（回傳正確的子類型）
      And 非使用 new WC_Order($order_id)（過時寫法）

  Rule: global $post 在訂單 context 中的使用

    Scenario: Meta Box callback 不依賴 global $post
      Given 任何子模組的 meta box render callback 被呼叫
      When callback 需要取得訂單資訊
      Then 使用 Woomp_HPOS_Helper::get_order($post_or_order) 從 callback 參數取得
      And 非使用 global $post（HPOS 下可能為 null）

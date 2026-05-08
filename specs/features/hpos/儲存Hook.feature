@ignore @command
Feature: 訂單儲存 Hook HPOS 相容
  As a Woomp 外掛的發票模組
  I want 在 HPOS 模式下訂單儲存時正確觸發發票資料更新
  So that 管理員在後台修改發票欄位後資料不會遺失

  Background:
    Given WooCommerce 已啟用 HPOS (custom_order_tables)
    And Woomp 外掛已啟用

  Rule: 綠界發票儲存

    Scenario: HPOS 下綠界發票 meta 在訂單儲存時更新
      Given 綠界發票模組已啟用
      And 管理員在 HPOS 訂單編輯頁修改了發票欄位
      When 管理員按下「更新」按鈕
      Then woocommerce_process_shop_order_meta hook 觸發
      And 發票欄位值正確儲存至訂單 meta

    Scenario: 傳統模式下綠界發票儲存仍正常
      Given HPOS 未啟用
      And 綠界發票模組已啟用
      And 管理員在傳統訂單編輯頁修改了發票欄位
      When 管理員按下「更新」按鈕
      Then 發票欄位值正確儲存至訂單 meta

  Rule: EZPAY 發票儲存

    Scenario: HPOS 下 EZPAY 發票 meta 在訂單儲存時更新
      Given EZPAY 發票模組已啟用
      And 管理員在 HPOS 訂單編輯頁修改了 EZPAY 發票欄位
      When 管理員按下「更新」按鈕
      Then woocommerce_process_shop_order_meta hook 觸發
      And EZPAY 發票欄位值正確儲存至訂單 meta

  Rule: 不使用 save_post_shop_order

    Scenario: 全專案無 save_post_shop_order hook
      When 掃描所有 PHP 檔案
      Then 無任何 add_action('save_post_shop_order', ...) 呼叫

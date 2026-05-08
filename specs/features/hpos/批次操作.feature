@ignore @command
Feature: 批次操作 HPOS 相容
  As a 管理員
  I want 在 HPOS 模式的訂單列表執行批次操作（列印、狀態變更、開票）
  So that 我可以在 HPOS 下繼續使用所有批次功能

  Background:
    Given WooCommerce 已啟用 HPOS (custom_order_tables)
    And Woomp 外掛已啟用
    And 管理員已登入

  Rule: 核心批次操作

    Scenario: HPOS 訂單列表批次狀態變更
      When 管理員在 HPOS 訂單列表選取批次操作選單
      Then 出現「變更為已出貨」和「變更為配送中」選項
      And Hook bulk_actions-woocommerce_page_wc-orders 已註冊

    Scenario: HPOS 訂單列表批次列印綠界託運單
      Given 綠界物流已啟用
      When 管理員在 HPOS 訂單列表選取批次操作選單
      Then 出現綠界託運單列印選項
      And Hook handle_bulk_actions-woocommerce_page_wc-orders 已註冊

  Rule: 發票批次操作

    Scenario: HPOS 訂單列表批次開立發票
      Given 立吉富發票模組已啟用
      When 管理員在 HPOS 訂單列表選取批次操作選單
      Then 出現「批次開立發票」選項

  Rule: 雙重 Hook 註冊

    Scenario: 同時支援傳統和 HPOS 批次 hook
      When Woomp 註冊批次操作 hook
      Then 同時註冊 bulk_actions-edit-shop_order（傳統）
      And 同時註冊 bulk_actions-woocommerce_page_wc-orders（HPOS）

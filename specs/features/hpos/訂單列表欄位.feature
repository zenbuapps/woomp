@ignore @command
Feature: 訂單列表欄位 HPOS 相容
  As a 管理員
  I want 在 HPOS 模式的訂單列表頁看到金流單號、物流單號、發票欄位
  So that 我可以快速查看訂單的關鍵資訊

  Background:
    Given WooCommerce 已啟用 HPOS (custom_order_tables)
    And Woomp 外掛已啟用
    And 管理員已登入

  Rule: 核心欄位（Woomp 核心）

    Scenario: HPOS 訂單列表顯示金流單號和物流單號
      When 管理員進入 HPOS 訂單列表頁（admin.php?page=wc-orders）
      Then 出現「金流單號」和「物流單號」欄位
      And Hook manage_woocommerce_page_wc-orders_columns 已註冊
      And Hook manage_woocommerce_page_wc-orders_custom_column 已註冊

  Rule: 發票欄位（綠界/EZPAY/立吉富）

    Scenario Outline: HPOS 訂單列表顯示發票欄位
      Given <invoice_module> 發票模組已啟用
      When 管理員進入 HPOS 訂單列表頁
      Then 出現「<column_title>」欄位

      Examples:
        | invoice_module | column_title   |
        | 綠界發票       | 電子發票       |
        | EZPAY 發票     | 電子發票       |
        | 立吉富發票     | 電子發票       |

  Rule: 雙重 Hook 註冊

    Scenario: 同時支援傳統和 HPOS 列表 hook
      When Woomp 註冊訂單列表欄位 hook
      Then 同時註冊 manage_shop_order_posts_columns（傳統）
      And 同時註冊 manage_woocommerce_page_wc-orders_columns（HPOS）
      And 兩個 hook 指向相同的 callback 函式

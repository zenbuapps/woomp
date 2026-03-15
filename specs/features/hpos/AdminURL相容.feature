@ignore @command
Feature: Admin URL 與 Screen 判斷 HPOS 相容
  As a Woomp 外掛
  I want 在 HPOS 模式下使用正確的訂單管理 URL 和 Screen 判斷
  So that 管理員不會遇到 404 或功能消失

  Background:
    Given WooCommerce 已啟用 HPOS (custom_order_tables)
    And Woomp 外掛已啟用

  Rule: 訂單列表 URL

    Scenario: HPOS 下訂單列表 URL 正確
      When 程式碼需要產生訂單列表 URL
      Then 使用 admin_url('admin.php?page=wc-orders')（HPOS）
      And 非使用 admin_url('edit.php?post_type=shop_order')

    Scenario: 傳統模式下訂單列表 URL 正確
      Given HPOS 未啟用
      When 程式碼需要產生訂單列表 URL
      Then 使用 admin_url('edit.php?post_type=shop_order')（傳統）

  Rule: Screen 判斷

    Scenario: HPOS 下訂單編輯頁 screen 判斷正確
      When 程式碼需要判斷當前是否為訂單編輯頁
      Then 不僅判斷 $pagenow === 'post.php' && get_post_type() === 'shop_order'
      And 同時判斷 HPOS 的 screen ID（woocommerce_page_wc-orders）

    Scenario: HPOS 下 $current_screen->post_type 判斷相容
      When 程式碼使用 $current_screen->post_type === 'shop_order'
      Then 同時判斷 $current_screen->id 是否為 HPOS screen ID

  Rule: 物流列印跳轉 URL

    Scenario Outline: 物流操作後跳轉 URL 正確（HPOS）
      Given <shipping_module> 物流模組觸發操作完成
      When 需要跳轉回訂單列表
      Then 跳轉 URL 為 HPOS 相容的訂單列表 URL

      Examples:
        | shipping_module |
        | 綠界物流        |
        | 速買配物流      |

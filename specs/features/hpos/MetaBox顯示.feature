@ignore @command
Feature: Meta Box 在 HPOS 訂單編輯頁顯示
  As a 管理員
  I want 在 HPOS 模式的訂單編輯頁看到所有金流/物流/發票 Meta Box
  So that 我可以正常管理訂單的詳細資訊

  Background:
    Given WooCommerce 已啟用 HPOS (custom_order_tables)
    And Woomp 外掛已啟用
    And 管理員已登入且擁有 edit_shop_orders 權限

  Rule: Meta Box Screen 相容

    Scenario Outline: Meta Box 在 HPOS 訂單編輯頁正確註冊
      Given <module> 模組已啟用
      When 管理員進入 HPOS 訂單編輯頁（screen = woocommerce_page_wc-orders）
      Then "<metabox_title>" Meta Box 正常顯示

      Examples:
        | module         | metabox_title          |
        | 立吉富金流     | PayNow 付款資訊        |
        | 立吉富發票     | 立吉富電子發票         |
        | 立吉富物流     | PayNow 物流資訊        |
        | 綠界物流       | 綠界物流資訊           |
        | 藍新物流       | 藍新物流資訊           |
        | 速買配物流     | 速買配物流資訊         |

  Rule: Meta Box Render Callback 參數處理

    Scenario: render callback 收到 WC_Order 物件（HPOS 模式）
      Given HPOS 已啟用
      When add_meta_box 的 render callback 被呼叫
      Then callback 參數為 WC_Order 物件（而非 WP_Post）
      And 模組使用型別判斷取得 order ID 和 meta

    Scenario: render callback 收到 WP_Post 物件（傳統模式）
      Given HPOS 未啟用
      When add_meta_box 的 render callback 被呼叫
      Then callback 參數為 WP_Post 物件
      And 模組使用型別判斷取得 order ID 和 meta

  Rule: add_meta_box screen 參數

    Scenario: add_meta_box 同時支援兩種 screen
      When 任何子模組呼叫 add_meta_box()
      Then screen 參數包含 'shop_order'（傳統模式）
      And screen 參數包含 wc_get_page_screen_id('shop-order')（HPOS 模式）

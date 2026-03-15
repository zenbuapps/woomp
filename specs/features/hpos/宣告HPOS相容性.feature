@ignore @command
Feature: 宣告 HPOS 相容性
  As a Woomp 外掛
  I want 向 WooCommerce 宣告支援 HPOS (Custom Order Tables)
  So that 管理員啟用 HPOS 時不會看到不相容警告，且外掛功能正常運作

  Background:
    Given WooCommerce 7.1+ 已安裝並啟用
    And Woomp 外掛已啟用

  Rule: FeaturesUtil 相容性宣告

    Scenario: 外掛載入時宣告 HPOS 相容性
      When WordPress 觸發 before_woocommerce_init hook
      Then Woomp 呼叫 FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__)
      And WooCommerce 的 HPOS 相容性頁面顯示 Woomp 為「相容」

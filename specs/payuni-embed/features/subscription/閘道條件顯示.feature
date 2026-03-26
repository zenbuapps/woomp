@ignore @query
Feature: V3 定期定額閘道條件顯示

  Background:
    Given PayUni 金流已啟用（wc_woomp_enabled_payuni_gateway = yes）
    And WooCommerce Subscriptions 外掛已啟用

  Rule: 前置（狀態）- 購物車必須包含訂閱類型商品才顯示定期定額閘道

    Example: 購物車僅包含一般商品時不顯示定期定額閘道
      Given payuni-credit-subscription-v3 閘道已啟用
      And payuni-credit-subscription（V1）閘道已啟用
      And 購物車中僅包含一般商品（非 subscription / variable-subscription 類型）
      When 消費者進入結帳頁面
      Then payuni-credit-subscription-v3 閘道不出現在付款方式列表中
      And payuni-credit-subscription（V1）閘道不出現在付款方式列表中

    Example: 購物車包含訂閱商品時顯示已啟用的定期定額閘道
      Given payuni-credit-subscription-v3 閘道已啟用
      And 購物車中包含 subscription 類型商品
      When 消費者進入結帳頁面
      Then payuni-credit-subscription-v3 閘道出現在付款方式列表中

  Rule: 前置（狀態）- V1 與 V3 定期定額閘道的顯示由 WooCommerce 原生閘道啟用/停用控制

    Example: 管理員同時啟用 V1 和 V3 定期定額時結帳頁面兩個閘道都顯示
      Given payuni-credit-subscription-v3 閘道已啟用
      And payuni-credit-subscription（V1）閘道已啟用
      And 購物車中包含 subscription 類型商品
      When 消費者進入結帳頁面
      Then payuni-credit-subscription-v3 閘道出現在付款方式列表中，method_title 為「信用卡定期定額（免跳轉）」
      And payuni-credit-subscription（V1）閘道出現在付款方式列表中，method_title 為「信用卡定期定額」

    Example: 管理員僅啟用 V3 定期定額時結帳頁面僅顯示 V3 閘道
      Given payuni-credit-subscription-v3 閘道已啟用
      And payuni-credit-subscription（V1）閘道未啟用
      And 購物車中包含 subscription 類型商品
      When 消費者進入結帳頁面
      Then payuni-credit-subscription-v3 閘道出現在付款方式列表中
      And payuni-credit-subscription（V1）閘道不出現在付款方式列表中

    Example: 管理員僅啟用 V1 定期定額時結帳頁面僅顯示 V1 閘道
      Given payuni-credit-subscription-v3 閘道未啟用
      And payuni-credit-subscription（V1）閘道已啟用
      And 購物車中包含 subscription 類型商品
      When 消費者進入結帳頁面
      Then payuni-credit-subscription（V1）閘道出現在付款方式列表中
      And payuni-credit-subscription-v3 閘道不出現在付款方式列表中

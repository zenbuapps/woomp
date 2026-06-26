Feature: 外掛初始化與模組載入
  As a 網站管理員
  I want Woomp 外掛能正確初始化並根據設定載入子模組
  So that 我可以選擇性啟用需要的金流、物流、電子發票服務

  Background:
    Given WordPress 已安裝且正在運行
    And Woomp 外掛檔案存在於 wp-content/plugins/woomp/

  Rule: WooCommerce 依賴檢查

    Scenario: WooCommerce 已啟用時正常初始化
      Given WooCommerce 5.3+ 已安裝並啟用
      When WordPress 載入 woomp.php
      Then 外掛常數 WOOMP_VERSION、WOOMP_PLUGIN_URL、WOOMP_PLUGIN_DIR、WOOMP_PLUGIN_BASENAME 被定義
      And Composer autoloader 被載入
      And Woomp 核心類別被實例化並執行 run()

    Scenario: WooCommerce 未啟用時自動停用
      Given WooCommerce 未安裝或未啟用
      When WordPress 載入 woomp.php
      Then Woomp 外掛被自動停用
      And 後台顯示錯誤通知「好用版擴充啟用失敗，需要安裝並啟用 WooCommerce 5.3 以上版本」
      And 初始化流程中止

  Rule: 外掛啟用預設值

    Scenario: 首次啟用外掛時設定預設選項
      Given Woomp 外掛從未被啟用過
      When 管理員啟用 Woomp 外掛
      Then 選項 wc_woomp_setting_mode 被設為 "default"
      And 選項 wc_woomp_setting_billing_country_pos 被設為 "yes"
      And 選項 wc_woomp_setting_tw_address 被設為 "yes"
      And 選項 wc_woomp_setting_one_line_address 被設為 "yes"
      And 選項 wc_woomp_setting_show_phone 被設為 "yes"
      And 選項 wc_woomp_setting_product_variations_ui 被設為 "yes"
      And 選項 wc_woomp_setting_product_variations_frontend_ui 被設為 "yes"
      And 選項 wc_woomp_setting_tw_field_valitdate 被設為 "yes"
      And 選項 woocommerce_ship_to_destination 被設為 "billing"
      And WooCommerce 預設貨到付款描述被修改為「超商取貨付款」

    Scenario: 再次啟用外掛不覆蓋已有設定
      Given 選項 wc_woomp_setting_mode 已存在值 "onepage"
      When 管理員啟用 Woomp 外掛
      Then 選項 wc_woomp_setting_mode 仍為 "onepage"

  Rule: 子模組條件式載入

    Scenario: 啟用 PayUni 金流模組
      Given 選項 wc_woomp_enabled_payuni_gateway 為 "yes"
      When 外掛初始化完成
      Then PayUni 金流模組被載入

    Scenario: 停用 PayUni 金流模組
      Given 選項 wc_woomp_enabled_payuni_gateway 為 "no"
      When 外掛初始化完成
      Then PayUni 金流模組不被載入

    Scenario: 啟用 PayNow 金流模組
      Given 選項 wc_woomp_setting_paynow_gateway 為 "yes"
      And PAYNOW_PLUGIN_URL 常數未被定義
      When 外掛初始化完成
      Then PayNow 金流模組在 init hook (priority 30) 時被載入

    Scenario: 啟用 PayNow 物流模組
      Given 選項 wc_woomp_setting_paynow_shipping 為 "yes"
      And PAYNOW_SHIPPING_PLUGIN_URL 常數未被定義
      When 外掛初始化完成
      Then PayNow 物流模組在 plugins_loaded hook 時被載入
      And 物流方式 paynow_shipping_c2c_711、paynow_shipping_c2c_family、paynow_shipping_c2c_hilife、paynow_shipping_hd_tcat 被註冊

    Scenario: 啟用 PayNow 電子發票模組
      Given 選項 wc_settings_tab_active_paynow_einvoice 為 "yes"
      And PAYNOW_EINVOICE_PLUGIN_URL 常數未被定義
      When 外掛初始化完成
      Then PayNow 電子發票模組被載入並執行 run()

    Scenario: RY WooCommerce Tools 常駐載入
      Given RY_WT_VERSION 常數未被定義
      When 外掛初始化完成
      Then ry-woocommerce-tools 模組被載入
      And RY_WT::ry_init 在 init hook 時執行

    Scenario: RY WooCommerce Tools 已由其他外掛載入
      Given RY_WT_VERSION 常數已被定義
      When 外掛初始化完成
      Then ry-woocommerce-tools 模組不被重複載入

    Scenario: LINE Pay 常駐載入
      When 外掛初始化完成
      Then line-pay-for-woo 模組被載入

    Scenario: 綠界電子發票常駐載入
      When 外掛初始化完成
      Then woomp-ecpay-invoice 模組被載入

    Scenario: ezPay 電子發票常駐載入
      When 外掛初始化完成
      Then woomp-ezpay-invoice 模組被載入

    Scenario: 統一金流常駐載入
      When 外掛初始化完成
      Then payuni/payuni.php 模組被載入

  Rule: 支付連衝突處理

    Scenario: 獨立版 PChomePay 未安裝
      Given PChomePay-Cart-for-WooCommerce 外掛未出現在 active_plugins 中
      When 外掛初始化完成
      Then 內建的 PChomePay 模組被載入

    Scenario: 獨立版 PChomePay 已安裝
      Given PChomePay-Cart-for-WooCommerce/pchomepay.php 出現在 WOOMP_ACTIVE_PLUGINS 中
      And woomp/woomp.php 出現在 WOOMP_ACTIVE_PLUGINS 中
      When 外掛初始化完成
      Then 獨立版 PChomePay 外掛被自動停用
      And 後台顯示通知「PChomePay Gateway for WooCommerce 已停用，請使用好用版擴充支付連金流」

  Rule: 結帳模板覆寫

    Scenario: 一頁式結帳模式覆寫模板
      Given 選項 wc_woomp_setting_mode 為 "onepage"
      When WooCommerce 載入結帳模板
      Then wc_get_template filter 攔截模板路徑
      And 使用 woomp/woocommerce/ 目錄下的模板檔案

    Scenario: 兩頁式結帳模式覆寫模板
      Given 選項 wc_woomp_setting_mode 為 "twopage"
      When WooCommerce 載入結帳模板
      Then wc_get_template filter 攔截模板路徑

    Scenario: 預設結帳模式不覆寫模板
      Given 選項 wc_woomp_setting_mode 為 "default"
      When WooCommerce 載入結帳模板
      Then 使用 WooCommerce 預設模板

  Rule: 版本相容性處理

    Scenario: 首次執行相容性排程
      Given 選項 woomp_compatibility_action_scheduled 不等於 WOOMP_VERSION
      When init hook 觸發
      Then 排程 Action Scheduler 非同步任務 woomp_compatibility_action_scheduler
      And 任務執行時清理非訂單類型的空白 PayNow 發票 meta
      And 更新 woomp_compatibility_action_scheduled 為當前 WOOMP_VERSION

    Scenario: 已執行過相容性排程
      Given 選項 woomp_compatibility_action_scheduled 等於 WOOMP_VERSION
      When init hook 觸發
      Then 不排程新的相容性任務

  Rule: 自動更新機制

    Scenario: 檢查 GitHub 更新
      When plugins_loaded hook 觸發
      Then PucFactory 建立 update checker 連接 https://github.com/zenbuapps/woomp
      And 追蹤 master 分支
      And 啟用 Release Assets

  Rule: 國際化

    Scenario: 載入翻譯檔
      When init hook (priority 20) 觸發
      Then 載入以下 text domains 的翻譯：woomp、ry-woocommerce-tools、ry-woocommerce-tools-pro、ry-woocommerce-ecpay-invoice、paynow-payment、paynow-einvoice、paynow-shipping
      And 翻譯檔從 languages/ 目錄載入

  Rule: 全域修正

    Scenario: 修正感謝頁面登入問題
      When 外掛初始化完成
      Then filter woocommerce_order_received_verify_known_shoppers 回傳 false

  Rule: 信用卡付款描述

    Scenario: 信用卡表單開始時顯示付款描述
      Given 某金流閘道有設定 description
      When woocommerce_credit_card_form_start action 觸發
      Then 該金流閘道的 description 以 wpautop + wptexturize 格式輸出

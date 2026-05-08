Feature: 設定系統（好用版擴充頁籤）
  As a 網站管理員
  I want 在 WooCommerce 設定中管理所有好用版擴充的設定
  So that 我可以集中啟用或停用各金流/物流/電子發票模組及調整結帳行為

  Background:
    Given 管理員已登入且擁有 manage_options 權限
    And WooCommerce 已啟用
    And Woomp 外掛已啟用

  Rule: 設定頁籤結構

    Scenario: WooCommerce 設定中顯示好用版擴充頁籤
      When 管理員進入 WooCommerce > 設定
      Then 出現以下頁籤：「好用版擴充」、「金流設定」、「物流設定」、「電子發票設定」

    Scenario: WooCommerce 子選單顯示好用版擴充連結
      When 管理員展開 WooCommerce 選單
      Then 子選單包含「好用版擴充設定」、「- 金流設定」、「- 物流設定」、「- 電子發票設定」

    Scenario: 外掛列表頁顯示快捷連結
      When 管理員進入外掛列表頁
      Then woomp/woomp.php 列顯示「Settings」、「payment」、「shipping」、「invoice」連結

  Rule: 主設定頁 — 模組啟用管理

    Scenario: 啟用統一金流 PAYUNi
      Given 管理員在「好用版擴充」頁籤
      When 管理員切換「啟用 PAYUNi 金流」為開啟
      And 按下儲存
      Then 選項 wc_woomp_enabled_payuni_gateway 存為 "yes"

    Scenario: 啟用綠界金流
      Given 管理員在「好用版擴充」頁籤
      When 管理員切換「Enable ECPay gateway」為開啟
      And 按下儲存
      Then 對應的 RY_WT 選項被存為 "yes"

    Scenario: 停用所有金流模組
      Given 管理員在「好用版擴充」頁籤
      When 管理員將所有金流模組設為關閉
      And 按下儲存
      Then 所有金流相關選項存為 "no"

  Rule: 主設定頁 — 結帳設定

    Scenario: 設定結帳模式為一頁式
      Given 管理員在「好用版擴充」頁籤
      When 管理員將「結帳流程設定」改為「一頁式結帳」
      And 按下儲存
      Then 選項 wc_woomp_setting_mode 存為 "onepage"

    Scenario: 設定結帳模式為兩頁式
      Given 管理員在「好用版擴充」頁籤
      When 管理員將「結帳流程設定」改為「兩頁式結帳」
      Then 「兩頁式結帳返回購物車文字」欄位顯示
      When 管理員填寫返回購物車文字並儲存
      Then 選項 wc_woomp_setting_mode 存為 "twopage"
      And 選項 wc_woomp_setting_mode_twopage_message 存入自訂文字

    Scenario: 設定結帳模式為預設
      Given 管理員在「好用版擴充」頁籤
      When 管理員將「結帳流程設定」改為「預設」
      Then 「兩頁式結帳返回購物車文字」欄位隱藏
      When 按下儲存
      Then 選項 wc_woomp_setting_mode 存為 "default"

    Scenario: 啟用台灣地址下拉選單
      Given 管理員在「好用版擴充」頁籤
      When 管理員開啟「縣市/鄉鎮市下拉式選單」
      And 按下儲存
      Then 選項 wc_woomp_setting_tw_address 存為 "yes"

    Scenario: 啟用訂單地址欄位整併
      Given 管理員在「好用版擴充」頁籤
      When 管理員開啟「訂單地址欄位整併」
      And 按下儲存
      Then 選項 wc_woomp_setting_one_line_address 存為 "yes"

    Scenario: 設定結帳按鈕文字
      Given 管理員在「好用版擴充」頁籤
      When 管理員在「結帳按鈕文字設定」填入「立即購買」
      And 按下儲存
      Then 選項 wc_woomp_setting_place_order_text 存為「立即購買」

    Scenario: 啟用免運提示
      Given 管理員在「好用版擴充」頁籤
      When 管理員開啟「Free shipping hint」
      Then 免運提示相關設定欄位（文字、顏色）顯示
      When 管理員設定提示文字及顏色並儲存
      Then 免運提示設定被儲存

  Rule: 主設定頁 — 商品設定

    Scenario: 啟用好用版可變商品編輯介面
      Given 管理員在「好用版擴充」頁籤
      When 管理員開啟「可變商品編輯介面」
      And 按下儲存
      Then 選項 wc_woomp_setting_product_variations_ui 存為 "yes"

    Scenario: 啟用虛擬商品自動完成訂單
      Given 管理員在「好用版擴充」頁籤
      When 管理員開啟「虛擬商品自動完成訂單」
      And 按下儲存
      Then 選項 wc_woomp_setting_virtual_product_order_auto_complete 存為 "yes"

  Rule: 金流設定子頁籤

    Scenario: 進入金流設定頁無子區段時預設跳轉
      Given 管理員進入金流設定頁籤但無指定子區段
      When 頁面載入
      Then 自動跳轉至 payuni 子區段

    Scenario: 統一金流已啟用時顯示完整設定
      Given 選項 wc_woomp_enabled_payuni_gateway 為 "yes"
      When 管理員進入金流設定 > 統一金流子區段
      Then 顯示 PayUni 金流完整設定表單

    Scenario: 統一金流未啟用時顯示提示
      Given 選項 wc_woomp_enabled_payuni_gateway 為 "no"
      When 管理員進入金流設定 > 統一金流子區段
      Then 顯示「尚未啟用統一金流金流」提示
      And 提供前往主設定頁的連結

    Scenario: 綠界訂單編號前綴驗證 — 合法值
      Given 管理員在綠界金流設定子區段
      When 管理員填入訂單編號前綴 "ABC123" 並儲存
      Then 前綴值 "ABC123" 被正常儲存

    Scenario: 綠界訂單編號前綴驗證 — 非法值
      Given 管理員在綠界金流設定子區段
      When 管理員填入訂單編號前綴 "ABC-123!@#" 並儲存
      Then 前綴值被清空
      And 顯示錯誤通知「綠界訂單編號前綴只能允許英文大小寫及數字，且總字數10位內」

    Scenario: 支付連子區段條件式顯示
      Given PChomePay-Cart-for-WooCommerce 未作為獨立外掛啟用
      When 管理員進入金流設定頁籤
      Then 子區段列表包含「支付連」

  Rule: 物流設定子頁籤

    Scenario: 進入物流設定頁無子區段時預設跳轉
      Given 管理員進入物流設定頁籤但無指定子區段
      When 頁面載入
      Then 自動跳轉至 ecpay 子區段

    Scenario: 立吉富物流已啟用時顯示完整設定
      Given 選項 wc_woomp_setting_paynow_shipping 為 "yes"
      When 管理員進入物流設定 > 立吉富子區段
      Then 顯示立吉富物流完整設定包含：Debug Log、寄件人資訊、物流狀態對應、API 設定

    Scenario: 立吉富物流未啟用時顯示提示
      Given 選項 wc_woomp_setting_paynow_shipping 為 "no"
      When 管理員進入物流設定 > 立吉富子區段
      Then 顯示「尚未啟用立吉富物流」提示

  Rule: 電子發票設定子頁籤

    Scenario: 進入電子發票設定頁無子區段時預設跳轉
      Given 管理員進入電子發票設定頁籤但無指定子區段
      When 頁面載入
      Then 自動跳轉至 ecpay 子區段

    Scenario: 綠界電子發票已啟用時顯示完整設定
      Given 選項 wc_woomp_enabled_ecpay_invoice 為 "yes"
      When 管理員進入電子發票設定 > 綠界(好用版)子區段
      Then 顯示綠界電子發票設定包含：Debug Log、訂單編號前綴、開立模式、開立狀態、作廢模式、載具類型、捐贈機構、API 金鑰

    Scenario: ezPay 電子發票已啟用時顯示完整設定
      Given 選項 wc_woomp_enabled_ezpay_invoice 為 "yes"
      When 管理員進入電子發票設定 > 藍新 ezPay 子區段
      Then 顯示 ezPay 設定包含測試/正式環境的 API 金鑰

    Scenario: 立吉富電子發票已啟用時顯示完整設定
      Given 選項 wc_settings_tab_active_paynow_einvoice 為 "yes"
      When 管理員進入電子發票設定 > 立吉富子區段
      Then 顯示立吉富設定包含：測試模式、Merchant ID/Password、開立模式、稅別、載具類型（雲端/手機/自然人/悠遊卡）、捐贈機構

  Rule: 設定頁面 UI 美化

    Scenario: Checkbox 顯示為 Toggle 開關
      Given 管理員在「好用版擴充」頁籤
      When 頁面載入
      Then 所有 class 為 "toggle" 的 checkbox 以 Toggle 開關樣式顯示
      And 每個 Toggle 旁顯示「停用 / 啟用」文字

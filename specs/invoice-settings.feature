Feature: 電子發票設定管理
  As a 店家管理員
  I want 在 WooCommerce 後台設定電子發票服務參數
  So that 電子發票能正確開立與作廢

  Background:
    Given 管理員已登入 WordPress 後台
    And WooCommerce 已安裝並啟用
    And Woomp 外掛已啟用

  Rule: 設定頁面結構

    Scenario: 電子發票設定頁籤存在
      When 管理員進入 WooCommerce > 設定
      Then 設定頁籤列表包含「電子發票設定」

    Scenario: 電子發票設定包含三個子區段
      When 管理員進入電子發票設定頁面
      Then 顯示三個子區段：「綠界(好用版)」「藍新 ezPay」「立吉富」

    Scenario: 預設導向綠界區段
      When 管理員進入電子發票設定頁面且未指定區段
      Then 自動跳轉至綠界(好用版)區段

  Rule: 綠界設定

    Scenario: 綠界模組未啟用時顯示提示
      Given 綠界電子發票模組未啟用（wc_woomp_enabled_ecpay_invoice != yes）
      When 管理員查看綠界設定區段
      Then 顯示「尚未啟用綠界電子發票」
      And 提供連結至模組啟用設定頁面

    Scenario: 綠界模組已啟用時顯示完整設定
      Given 綠界電子發票模組已啟用
      When 管理員查看綠界設定區段
      Then 顯示以下設定區塊：
        | 區塊             | 欄位                                   |
        | 一般設定          | 除錯日誌、訂單編號前綴                    |
        | 發票選項          | 開立模式、觸發狀態、作廢模式、作廢狀態、載具類型、捐贈機構 |
        | 商家資料設定       | 測試模式、商家編號、HashKey、HashIV        |

    Scenario: 設定綠界開立模式為自動
      Given 管理員在綠界設定區段
      When 將開立模式設為「自動」
      And 選擇觸發狀態為「處理中」
      And 儲存設定
      Then wc_woomp_ecpay_invoice_issue_mode = 'auto'
      And wc_woomp_ecpay_invoice_issue_at = 'wc-processing'

    Scenario: 設定綠界作廢模式為自動
      Given 管理員在綠界設定區段
      When 將作廢模式設為「自動」
      And 選擇作廢狀態為「已退款」
      And 儲存設定
      Then wc_woomp_ecpay_invoice_invalid_mode = 'auto'
      And wc_woomp_ecpay_invoice_invalid_at = 'wc-refunded'

    Scenario: 設定綠界載具類型
      Given 管理員在綠界設定區段
      When 選擇允許的載具類型為「手機條碼」與「自然人憑證」
      And 儲存設定
      Then wc_woomp_ecpay_invoice_carrier_type = ['手機條碼', '自然人憑證']
      And 結帳頁面的載具選項僅顯示這兩種

    Scenario: 設定綠界捐贈機構
      Given 管理員在綠界設定區段
      When 在捐贈機構欄位輸入：
        """
        25885|伊甸社會福利基金會
        919|創世基金會
        """
      And 儲存設定
      Then 結帳頁面的捐贈碼下拉選單包含兩個機構

    Scenario: 綠界測試模式啟用
      Given 管理員在綠界設定區段
      When 勾選「測試模式」
      And 儲存設定
      Then 系統使用測試環境 API（einvoice-stage.ecpay.com.tw）
      And 使用內建的測試用 MerchantID/HashKey/HashIV

    Scenario: 綠界正式模式優先使用 RY 插件金鑰
      Given 管理員未啟用測試模式
      And RY_WEI_ecpay_MerchantID 已設定
      When 系統讀取 API 金鑰
      Then 優先使用 RY_WEI_ecpay_* 系列選項
      And 忽略 wc_woomp_ecpay_invoice_* 金鑰設定

    Scenario: 綠界自動開立觸發狀態的 JS 互動
      Given 管理員在綠界設定區段
      When 開立模式選擇「手動」
      Then 自動開立觸發狀態欄位隱藏
      When 開立模式選擇「自動」
      Then 自動開立觸發狀態欄位顯示

  Rule: EZPAY 設定

    Scenario: EZPAY 模組未啟用時顯示提示
      Given EZPAY 電子發票模組未啟用
      When 管理員查看 EZPAY 設定區段
      Then 顯示「尚未啟用 ezPay 電子發票」

    Scenario: EZPAY 模組已啟用時顯示完整設定
      Given EZPAY 電子發票模組已啟用
      When 管理員查看 EZPAY 設定區段
      Then 顯示設定區塊包含：
        | 區塊                  | 欄位                                   |
        | 一般設定               | 除錯日誌、訂單編號前綴                    |
        | 發票選項               | 開立模式、觸發狀態、作廢模式、作廢狀態、載具類型、捐贈機構 |
        | 商家資料設定（測試模式）  | 測試模式開關、測試商家編號、測試HashKey、測試HashIV |
        | 商家資料設定（正式環境）  | 正式商家編號、正式HashKey、正式HashIV      |

    Scenario: EZPAY 載具類型選項
      Given 管理員在 EZPAY 設定區段
      When 查看載具類型多選欄位
      Then 選項包含「雲端電子發票載具」「手機條碼」「自然人憑證」
      # 注意：EZPAY 無「紙本發票」選項

    Scenario: EZPAY 分離測試與正式環境金鑰
      Given 管理員在 EZPAY 設定區段
      When 分別設定測試與正式環境的商家編號與金鑰
      Then 測試模式啟用時使用 _test 後綴的選項值
      And 正式模式使用無後綴的選項值

  Rule: 立吉富設定

    Scenario: 立吉富模組未啟用時顯示提示
      Given 立吉富電子發票模組未啟用
      When 管理員查看立吉富設定區段
      Then 顯示「尚未啟用立吉富電子發票」

    Scenario: 立吉富模組已啟用時顯示完整設定
      Given 立吉富電子發票模組已啟用
      When 管理員查看立吉富設定區段
      Then 顯示設定欄位：
        | 欄位           | 類型      | 說明                       |
        | 測試模式        | checkbox | 啟用/停用測試環境            |
        | 除錯日誌        | checkbox | 啟用/停用日誌記錄            |
        | 商家編號        | text     | mem_cid                   |
        | 商家密碼        | text     | mem_password              |
        | 開立模式        | radio    | 自動 / 手動                |
        | 觸發狀態        | select   | 排除 cancelled/refunded/failed |
        | 課稅類別        | select   | 應稅(5%) / 零稅率 / 免稅    |
        | 雲端會員載具     | checkbox | 啟用/停用                   |
        | 手機條碼        | checkbox | 啟用/停用                   |
        | 自然人憑證      | checkbox | 啟用/停用                   |
        | 悠遊卡         | checkbox | 啟用/停用                   |
        | 捐贈機構        | textarea | 愛心碼|社福團體名稱          |

    Scenario: 立吉富載具類型可個別啟停
      Given 管理員在立吉富設定區段
      When 取消勾選「悠遊卡」載具
      And 儲存設定
      Then 結帳頁面的載具類型不包含「悠遊卡」選項

    Scenario: 立吉富課稅類別設定
      Given 管理員在立吉富設定區段
      When 選擇課稅類別為「應稅(5%)」
      And 儲存設定
      Then 開立發票時 ItemTaxtype = 1 且 tax_rate = 5

    Scenario: 立吉富觸發狀態排除負面狀態
      Given 管理員在立吉富設定區段
      When 查看自動開立觸發狀態下拉選單
      Then 選項不包含「已取消」「已退款」「失敗」
      And 包含「待處理」「處理中」「保留」「已完成」等狀態

  Rule: 設定儲存

    Scenario: 儲存綠界設定
      Given 管理員修改了綠界設定
      When 點擊「儲存變更」
      Then 系統透過 WC_Admin_Settings::save_fields() 儲存所有欄位
      And 新設定立即生效

    Scenario: 儲存 EZPAY 設定
      Given 管理員修改了 EZPAY 設定
      When 點擊「儲存變更」
      Then 設定儲存至 wp_options 表
      And 新設定立即生效

    Scenario: 儲存立吉富設定
      Given 管理員修改了立吉富設定
      When 點擊「儲存變更」
      Then 設定儲存至 wp_options 表

  Rule: 服務商設定差異

    Scenario: 各服務商的獨特設定項目
      When 比較三家服務商的設定
      Then 綠界獨有設定：RY 插件金鑰相容、紙本發票載具選項
      And EZPAY 獨有設定：分離的測試/正式環境金鑰、雲端電子發票載具
      And 立吉富獨有設定：課稅類別、悠遊卡載具、捐贈機構可個別啟停、個別載具 checkbox 啟停

Feature: 結帳流程
  As a 顧客
  I want 在結帳頁面流暢地完成購買
  So that 我可以快速選擇物流、金流並填寫資訊

  Background:
    Given WooCommerce 與 Woomp 外掛已啟用
    And 購物車中有商品

  Rule: 結帳模式 — 一頁式

    Scenario: 購物車頁面自動跳轉至結帳頁
      Given 選項 wc_woomp_setting_mode 為 "onepage"
      And 購物車有商品
      When 顧客進入購物車頁面
      Then 自動跳轉至結帳頁面

    Scenario: 結帳頁顯示購物車內容
      Given 選項 wc_woomp_setting_mode 為 "onepage"
      When 顧客進入結帳頁
      Then 結帳表單前方顯示購物車商品列表
      And 可在結帳頁直接修改數量

    Scenario: 結帳頁面結構（一頁式/兩頁式共通）
      Given 選項 wc_woomp_setting_mode 為 "onepage" 或 "twopage"
      When 顧客進入結帳頁
      Then 頁面順序為：訂單審查（物流選項） → 帳單資訊 → 運送資訊
      And 付款方式顯示在物流選項下方
      And 下單按鈕移至帳單資訊區域底部
      And 最大寬度為 800px 且置中

  Rule: 結帳模式 — 兩頁式

    Scenario: 兩頁式結帳顯示返回購物車提示
      Given 選項 wc_woomp_setting_mode 為 "twopage"
      And 選項 wc_woomp_setting_mode_twopage_message 為「若需修改商品數量，請<a href="/cart">點此回到購物車</a>」
      When 顧客進入結帳頁
      Then 頁面頂部顯示返回購物車的提示文字

    Scenario: 兩頁式結帳不顯示購物車
      Given 選項 wc_woomp_setting_mode 為 "twopage"
      When 顧客進入結帳頁
      Then 不在結帳頁顯示購物車內容
      And 訂單小計從 order_review 移至表單前方

  Rule: 結帳模式 — 預設

    Scenario: 預設模式使用 WooCommerce 原版結帳
      Given 選項 wc_woomp_setting_mode 為 "default"
      When 顧客進入結帳頁
      Then 使用 WooCommerce 原版結帳模板
      And 不進行任何 DOM 重排

  Rule: 台灣地址下拉選單

    Scenario: 台灣地址啟用下拉選單
      Given 選項 wc_woomp_setting_tw_address 為 "yes"
      And 國家選擇為台灣 (TW)
      When 結帳頁面載入
      Then 縣市欄位變為下拉選單
      And 鄉鎮市區欄位變為下拉選單
      And 郵遞區號自動填入
      And 原始的 postcode、state、city 欄位被隱藏

    Scenario: 切換至非台灣國家
      Given 目前國家為台灣，顯示台灣地址下拉選單
      When 顧客將國家切換為日本 (JP)
      Then twzipcode 外掛被銷毀
      And 下拉選單被隱藏
      And 原始的文字輸入欄位恢復顯示

    Scenario: 預設隱藏離島縣市
      Given 選項 wc_woomp_setting_tw_address 為 "yes"
      And 國家為台灣
      When 結帳頁面載入
      Then 縣市下拉選單中金門縣、澎湖縣、連江縣被停用（disabled）

  Rule: 國家欄位置頂

    Scenario: 國家欄位移至物流選項前
      Given 選項 wc_woomp_setting_billing_country_pos 為 "yes"
      When 結帳頁面載入
      Then 帳單國家欄位移至 #order_review 最前方

    Scenario: 運送到不同地址時同步國家
      Given 選項 wc_woomp_setting_billing_country_pos 為 "yes"
      And 顧客勾選「運送到不同地址」
      When 顧客將運送國家改為美國 (US)
      Then 帳單國家自動同步為美國

  Rule: 超商取貨欄位處理

    Scenario: 選擇超商取貨時取消地址必填
      Given 結帳模式為一頁式或兩頁式
      And 顧客選擇的物流方式為 ry_ecpay_shipping_cvs_711
      When 結帳欄位被驗證
      Then 帳單的 postcode、state、city、address_1 不為必填
      And 運送的 first_name、last_name、phone 不為必填

    Scenario: 選擇超商取貨時隱藏地址欄位
      Given 結帳模式為一頁式或兩頁式
      When 顧客選擇超商取貨物流方式
      Then 帳單地址相關欄位被隱藏
      And 運送地址欄位被隱藏

    Scenario: 切換為宅配時顯示地址欄位
      Given 顧客已選擇超商取貨（地址欄位隱藏中）
      When 顧客切換為宅配物流方式
      Then 帳單地址欄位恢復顯示
      And 離島勾選欄位恢復顯示

  Rule: 離島運送處理

    Scenario: 顯示離島勾選框
      Given 運送區域有設定台灣離島郵遞區號
      And 購物車包含非虛擬商品
      And 國家為台灣
      When 結帳頁面載入
      Then 出現「寄送到離島區域」checkbox

    Scenario: 勾選離島後切換可選縣市
      Given 顯示「寄送到離島區域」checkbox
      When 顧客勾選此選項
      Then 下拉選單中僅顯示金門縣、澎湖縣、連江縣（排除沒送到的）
      And 本島縣市被停用
      And 郵遞區號設為 209

    Scenario: 取消離島勾選恢復本島
      Given 顧客已勾選離島選項
      When 顧客取消勾選
      Then 下拉選單恢復顯示本島縣市
      And 離島縣市被停用
      And 郵遞區號設為 110

    Scenario: 離島超商選擇警告
      Given 顧客未勾選「寄送到離島區域」
      When 結帳更新時偵測到選擇的超商地址包含「金門縣」
      Then 彈出 alert「您選擇的超商不在運送範圍內！」

  Rule: 虛擬商品結帳

    Scenario: 虛擬商品隱藏地址欄位
      Given 選項 wc_woomp_setting_virtual_product_address 為 "yes"
      And 購物車中全部為虛擬商品
      When 結帳頁面載入
      Then 帳單地址欄位（address_1、address_2、city、postcode、country、state）被移除

    Scenario: 混合商品保留地址欄位
      Given 選項 wc_woomp_setting_virtual_product_address 為 "yes"
      And 購物車中包含虛擬商品和實體商品
      When 結帳頁面載入
      Then 帳單地址欄位正常顯示

    Scenario: 0 元購物車隱藏訂單審查
      Given 購物車總金額為 0
      When 結帳頁面更新
      Then #order_review 區域被隱藏

  Rule: 表單驗證（台灣限定）

    Scenario: 姓名長度驗證 — 太短
      Given 選項 wc_woomp_setting_tw_field_valitdate 為 "yes"
      And 國家為台灣 (TW)
      When 顧客填入 billing_last_name 為「王」（1 個字）
      And 提交結帳表單
      Then 顯示驗證錯誤「姓名欄位 至少兩個字以上」

    Scenario: 電話長度驗證 — 非 10 碼
      Given 選項 wc_woomp_setting_tw_field_valitdate 為 "yes"
      And 國家為台灣 (TW)
      When 顧客填入 billing_phone 為 "091234567"（9 碼）
      And 提交結帳表單
      Then 顯示驗證錯誤「聯絡電話 長度有誤，必須為 10 碼」

    Scenario: 電話長度驗證 — 正確
      Given 選項 wc_woomp_setting_tw_field_valitdate 為 "yes"
      And 國家為台灣 (TW)
      When 顧客填入 billing_phone 為 "0912345678"（10 碼）
      And 提交結帳表單
      Then 電話欄位驗證通過

    Scenario: 離島超商驗證
      Given 選項 wc_woomp_setting_tw_field_valitdate 為 "yes"
      And 顧客未勾選離島
      And 超商地址包含「金門縣」
      When 提交結帳表單
      Then 顯示驗證錯誤「外島超商 您選擇的運送方式不在運送範圍內」

  Rule: 結帳按鈕文字

    Scenario: 自訂結帳按鈕文字
      Given 選項 wc_woomp_setting_place_order_text 為「立即購買」
      When 結帳頁面載入
      Then 下單按鈕文字為「立即購買」

    Scenario: 未設定自訂文字使用預設
      Given 選項 wc_woomp_setting_place_order_text 為空
      When 結帳頁面載入
      Then 下單按鈕文字為 WooCommerce 預設

  Rule: 免運提示

    Scenario: 顯示距離免運的金額
      Given 選項 wc_woomp_setting_free_shipping_hint 為 "yes"
      And 某物流方式設定 min_amount 為 1000
      And 購物車金額為 800
      When 結帳頁面載入
      Then 在該物流方式名稱旁顯示「差 200 元」標籤

    Scenario: 達到免運金額顯示免運標籤
      Given 選項 wc_woomp_setting_free_shipping_hint 為 "yes"
      And 某物流方式設定 min_amount 為 1000
      And 購物車金額為 1200
      When 結帳頁面載入
      Then 在該物流方式名稱旁顯示「免運」標籤

    Scenario: 運費為 0 顯示免運標籤
      Given 選項 wc_woomp_setting_free_shipping_hint 為 "yes"
      And 某物流方式的運費為 0
      When 結帳頁面載入
      Then 在該物流方式名稱旁顯示「免運」標籤

  Rule: 選填文字移除

    Scenario: 結帳頁面不顯示「(選填)」文字
      When 結帳頁面載入
      Then 所有非必填欄位的「(optional)/(選填)」文字被移除

  Rule: Billing 與 Shipping 同步

    Scenario: 帳單姓名電話同步至運送欄位
      Given 結帳模式為一頁式或兩頁式
      When 顧客在帳單欄位填入 first_name、last_name、phone
      Then 運送欄位的 first_name、last_name、phone 即時同步更新

  Rule: 主題相容 CSS

    Scenario: 載入主題專用樣式
      Given 目前使用的 WordPress 主題為 "Flatsome"
      When 結帳頁面載入
      Then 額外載入 public/css/themes/flatsome.css

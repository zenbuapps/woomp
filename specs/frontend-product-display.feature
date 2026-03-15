Feature: 前台商品展示
  As a 顧客
  I want 在商品頁面以直覺的方式選擇變化類型
  So that 我可以快速找到並選擇想要的商品規格

  Background:
    Given WooCommerce 與 Woomp 外掛已啟用
    And 選項 wc_woomp_setting_product_variations_frontend_ui 為 "yes"
    And 商品為可變商品且有多個變化類型

  Rule: 標籤式選項（tag）

    Scenario: 顯示標籤式變化選擇器
      Given 商品屬性「顏色」的 attribute_type 設為 "tag"
      And 選項有「紅色」、「藍色」、「綠色」
      When 顧客進入商品頁面
      Then 「顏色」屬性以標籤形式顯示
      And 原始下拉選單被隱藏
      And 每個選項為可點擊的標籤方塊

    Scenario: 選擇標籤
      Given 標籤式變化選擇器已顯示
      When 顧客點擊「紅色」標籤
      Then 「紅色」標籤背景色改為選中色
      And 其他標籤恢復預設背景色（#efefef）
      And 對應的 select 元素值更新為「紅色」
      And WooCommerce 變化類型價格和圖片更新

    Scenario: 標籤使用自訂顏色
      Given 選項 wc_woomp_setting_product_variations_frontend_ui＿bg_color 為 "#ff0000"
      And 選項 wc_woomp_setting_product_variations_frontend_ui＿text_color 為 "#ffffff"
      When 顧客選擇某個標籤
      Then 選中標籤背景色為 #ff0000
      And 選中標籤文字色為 #ffffff

    Scenario: 標籤使用預設顏色
      Given 未設定自訂背景色和文字色
      When 顧客選擇某個標籤
      Then 選中標籤背景色為「加入購物車」按鈕的背景色
      And 選中標籤文字色為白色

    Scenario: 缺貨變化類型顯示刪除線
      Given 「紅色」變化類型已缺貨且不允許候補
      When 顧客進入商品頁面
      Then 「紅色」標籤顯示刪除線
      And 「紅色」標籤透明度降低
      And 「紅色」標籤無法點擊

  Rule: 單選方塊（radio）

    Scenario: 顯示不斷行單選方塊
      Given 商品屬性「尺寸」的 attribute_type 設為 "radio"
      And 選項有「S」、「M」、「L」
      When 顧客進入商品頁面
      Then 「尺寸」屬性以 inline radio button 形式顯示
      And 原始下拉選單被隱藏

    Scenario: 顯示每行 1 個的單選方塊
      Given 商品屬性「尺寸」的 attribute_type 設為 "radio-one"
      When 顧客進入商品頁面
      Then 「尺寸」屬性每行顯示 1 個 radio button（width: 100%）

    Scenario: 顯示每行 2 個的單選方塊
      Given 商品屬性「尺寸」的 attribute_type 設為 "radio-two"
      When 顧客進入商品頁面
      Then 「尺寸」屬性每行顯示 2 個 radio button（width: 50%）

    Scenario: 選擇 radio 選項
      Given radio 變化選擇器已顯示
      When 顧客點擊「M」選項
      Then 「M」的 radio 被選中
      And 對應的 select 元素值更新
      And WooCommerce 變化類型資訊更新

  Rule: 下拉選單（select）

    Scenario: 顯示下拉選單
      Given 商品屬性「材質」的 attribute_type 設為 "select"
      When 顧客進入商品頁面
      Then 「材質」屬性以 WooCommerce 原版下拉選單形式顯示

  Rule: 預設類型行為

    Scenario: 全域預設為標籤式
      Given 選項 wc_woomp_setting_product_variations_frontend_ui＿default 為 "yes"
      And 商品屬性未設定 attribute_type
      When 顧客進入商品頁面
      Then 屬性以標籤式（tag）顯示

    Scenario: 全域預設為下拉選單
      Given 選項 wc_woomp_setting_product_variations_frontend_ui＿default 為 "no"
      And 商品屬性未設定 attribute_type
      When 顧客進入商品頁面
      Then 屬性以 WooCommerce 原版下拉選單顯示

  Rule: WPC Bundle 外掛相容

    Scenario: WPC Product Bundle 商品不受影響
      Given 商品類型為 "woosg"（grouped 商品）
      When 顧客進入商品頁面
      Then 所有屬性使用 WooCommerce 原版下拉選單
      And 不套用 Woomp 的變化類型渲染

    Scenario: WPC Product Bundle (woosb) 商品不受影響
      Given 商品類型為 "woosb"（bundle 商品）
      When 顧客進入商品頁面
      Then 所有屬性使用 WooCommerce 原版下拉選單

  Rule: 變化類型可用性即時更新

    Scenario: 選擇第一個屬性後更新第二個屬性的可用性
      Given 商品有「顏色」和「尺寸」兩個屬性
      And 「紅色 + L」的變化類型已缺貨
      When 顧客選擇「紅色」
      Then woocommerce_update_variation_values 事件觸發
      And 「L」選項被停用（radio disabled / label 加上 variation-out-of-stock class）

  Rule: 前台訂單物流查詢

    Scenario: 顯示綠界物流單號
      Given 訂單 #100 有 _ecpay_shipping_info meta
      When 顧客在我的帳戶查看訂單 #100
      Then 顯示「Ecpay Shipping details」區塊
      And 列出所有物流單號

    Scenario: 顯示手動輸入的物流單號
      Given 訂單 #101 有 wmp_shipping_no meta 為 "TRACK001"
      When 顧客在我的帳戶查看訂單 #101
      Then 顯示「Shipping details」區塊
      And 顯示物流單號 "TRACK001"

    Scenario: 無物流單號不顯示額外區塊
      Given 訂單 #102 無任何物流相關 meta
      When 顧客在我的帳戶查看訂單 #102
      Then 不顯示額外的物流詳情區塊

  Rule: 前台功能停用狀態

    Scenario: 前台變化類型 UI 停用
      Given 選項 wc_woomp_setting_product_variations_frontend_ui 為 "no"
      When 顧客進入可變商品頁面
      Then 所有屬性使用 WooCommerce 原版下拉選單
      And 不載入 variation_radio_buttons filter

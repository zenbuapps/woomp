Feature: 後台商品管理
  As a 網站管理員
  I want 使用好用版的可變商品編輯介面
  So that 我可以更直覺地管理商品屬性和變化類型

  Background:
    Given 管理員已登入
    And WooCommerce 與 Woomp 外掛已啟用
    And 選項 wc_woomp_setting_product_variations_ui 為 "yes"

  Rule: 好用版介面啟用與切換

    Scenario: 新商品預設啟用好用版介面
      Given 全域設定 wc_woomp_setting_product_variations_ui 為 "yes"
      When 管理員建立新的可變商品
      Then 商品自動啟用好用版變化類型介面
      And post meta '_is_active_woomp_ui' 設為 "yes"

    Scenario: 切換至原版介面
      Given 商品 #50 目前使用好用版介面
      When 管理員點擊「切換原版變化類型介面」按鈕
      Then post meta '_is_active_woomp_ui' 更新為 "no"
      And 頁面重新載入後顯示 WooCommerce 原版介面
      And 出現「切換好用版 Woo 變化類型介面」按鈕

    Scenario: 切換回好用版介面
      Given 商品 #50 目前使用原版介面
      When 管理員點擊「切換好用版 Woo 變化類型介面」按鈕
      Then post meta '_is_active_woomp_ui' 更新為 "yes"
      And 頁面重新載入後顯示好用版介面

    Scenario: 全域停用好用版介面
      Given 選項 wc_woomp_setting_product_variations_ui 為 "no"
      When 管理員進入任何商品編輯頁
      Then WooMP_Product 類別完全不初始化
      And 使用 WooCommerce 原版介面

  Rule: 好用版介面 — Tab 排列

    Scenario: 可變商品預設顯示「商品屬性」Tab
      Given 管理員正在編輯一個可變商品
      And 好用版介面已啟用
      When 頁面載入
      Then 「商品屬性」Tab 預設為啟用狀態
      And 「商品屬性」和「變化類型」Tab 移至最上方

    Scenario: 非可變商品不改變 Tab 排列
      Given 管理員正在編輯一個簡單商品
      When 頁面載入
      Then Tab 排列維持 WooCommerce 原版

  Rule: 好用版介面 — 屬性值標籤式輸入

    Scenario: 新增屬性值
      Given 管理員在商品屬性面板中新增一個自訂屬性
      When 管理員在輸入框輸入「紅色」並按 Enter
      Then 「紅色」以標籤 (tag) 形式顯示在下方
      And textarea 的值更新為包含「紅色」

    Scenario: 按按鈕新增屬性值
      Given 管理員在屬性值輸入框輸入「藍色」
      When 管理員點擊「新增」按鈕
      Then 「藍色」以標籤形式顯示

    Scenario: 不允許重複屬性值
      Given 屬性已有「紅色」標籤
      When 管理員輸入「紅色」並按 Enter
      Then 不新增重複的標籤
      And 輸入框清空

    Scenario: 移除屬性值
      Given 屬性有「紅色」和「藍色」兩個標籤
      When 管理員點擊「紅色」的移除按鈕
      Then 「紅色」標籤被移除
      And textarea 的值更新為只包含「藍色」

  Rule: 好用版介面 — 自動新增變化類型

    Scenario: 儲存屬性後自動新增變化類型
      Given 管理員已新增屬性「顏色」包含「紅色」和「藍色」
      When 管理員按下儲存屬性
      Then 彈出確認對話框「商品屬性已更新，是否自動新增變化類型？」
      When 管理員點擊確認
      Then 系統呼叫 woocommerce_link_all_variations 自動建立變化類型
      And 「變化類型」Tab 顯示「已更新」提示

    Scenario: 拒絕自動新增變化類型
      Given 管理員按下儲存屬性後彈出確認對話框
      When 管理員點擊取消
      Then 不自動建立變化類型

  Rule: 好用版介面 — 變化類型價格

    Scenario: 變化類型標題列顯示價格輸入框
      Given 商品有多個變化類型
      When 管理員點擊「變化類型」Tab
      Then 每個變化類型的標題列顯示「定價」和「折扣價」輸入框
      And 已有的價格值被同步顯示

    Scenario: 修改變化類型定價
      Given 變化類型標題列顯示價格輸入框
      When 管理員在定價輸入框輸入 "100"
      Then 隱藏的原始定價欄位值同步更新為 "100"
      And 「儲存變更」按鈕被啟用

    Scenario: 未填定價提示
      Given 商品有 3 個變化類型
      And 其中一個變化類型未填定價
      When 管理員點擊「變化類型」Tab
      Then 「變化類型」Tab 標籤顯示紅色「定價未填」提示

  Rule: 好用版介面 — 自動清理

    Scenario: 自動移除空值變化類型
      Given 有一個變化類型的屬性值為空
      When 管理員點擊「變化類型」Tab
      Then 1.5 秒後該空值變化類型被自動移除

  Rule: 前台變化類型介面設定

    Scenario: 設定屬性為標籤式選項
      Given 選項 wc_woomp_setting_product_variations_frontend_ui 為 "yes"
      And 管理員在商品屬性設定面板
      When 管理員將「設定前台變化類型介面」改為「標籤式選項」
      And 按下「儲存屬性」
      Then post meta 'attribute_{name}_type' 存為 "tag"

    Scenario: 設定屬性為單選方塊（不斷行）
      When 管理員將「設定前台變化類型介面」改為「單選方塊(不斷行)」
      And 按下「儲存屬性」
      Then post meta 'attribute_{name}_type' 存為 "radio"

    Scenario: 設定屬性為單選方塊（每行1個）
      When 管理員將「設定前台變化類型介面」改為「單選方塊(每行放1個選項)」
      And 按下「儲存屬性」
      Then post meta 'attribute_{name}_type' 存為 "radio-one"

    Scenario: 設定屬性為單選方塊（每行2個）
      When 管理員將「設定前台變化類型介面」改為「單選方塊(每行放2個選項)」
      And 按下「儲存屬性」
      Then post meta 'attribute_{name}_type' 存為 "radio-two"

    Scenario: 設定屬性為下拉選單
      When 管理員將「設定前台變化類型介面」改為「下拉選單」
      And 按下「儲存屬性」
      Then post meta 'attribute_{name}_type' 存為 "select"

  Rule: 變化類型可用性

    Scenario: 缺貨且不允許候補的變化類型被停用
      Given 商品有一個變化類型
      And 該變化類型無庫存且 backorders_allowed 為 false
      When 前台載入商品頁面
      Then 該變化類型被標記為不可用

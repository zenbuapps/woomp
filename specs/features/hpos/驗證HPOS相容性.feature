@ignore @query
Feature: 驗證 HPOS 相容性
  As a 管理員
  I want 確認 Woomp 在 HPOS 啟用和停用兩種模式下都能正常運作
  So that 我可以安心切換 HPOS 設定

  Background:
    Given Woomp 外掛已啟用
    And 所有子模組已啟用

  Rule: HPOS 啟用模式驗證

    Scenario: HPOS 啟用 — 訂單列表功能正常
      Given WooCommerce 已啟用 HPOS
      When 管理員進入訂單列表頁
      Then 金流單號欄位顯示
      And 物流單號欄位顯示
      And 批次操作選單包含所有自訂選項

    Scenario: HPOS 啟用 — 訂單編輯頁 Meta Box 正常
      Given WooCommerce 已啟用 HPOS
      And 訂單 #100 使用立吉富金流付款
      When 管理員進入訂單 #100 的編輯頁面
      Then 所有相關 Meta Box 正常顯示
      And Meta 資料正確讀取

    Scenario: HPOS 啟用 — 金流回調正常寫入
      Given WooCommerce 已啟用 HPOS
      And 訂單 #100 狀態為 pending
      When 金流服務商發送付款成功回調
      Then 訂單 meta 正確寫入 HPOS 表
      And 訂單狀態正確更新

  Rule: HPOS 停用模式驗證（向後相容）

    Scenario: HPOS 停用 — 所有功能仍正常
      Given WooCommerce 未啟用 HPOS（傳統 post 模式）
      When 管理員進入訂單列表頁
      Then 金流單號欄位顯示
      And 物流單號欄位顯示
      And 批次操作選單包含所有自訂選項

    Scenario: HPOS 停用 — 訂單編輯頁 Meta Box 正常
      Given WooCommerce 未啟用 HPOS
      And 訂單 #100 使用立吉富金流付款
      When 管理員進入訂單 #100 的編輯頁面
      Then 所有相關 Meta Box 正常顯示

  Rule: WooCommerce 相容性頁面

    Scenario: Woomp 在相容性頁面顯示為相容
      Given WooCommerce 已啟用 HPOS
      When 管理員進入 WooCommerce > 設定 > 進階 > 功能
      Then Woomp 在 HPOS 相容性清單中顯示為「相容」

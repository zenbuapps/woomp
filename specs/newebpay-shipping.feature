Feature: 藍新物流超商取貨
  As a 消費者
  I want 透過藍新金流的超商取貨服務選擇門市取貨
  So that 我可以在便利商店取得訂購的商品

  Background:
    Given 網站已啟用藍新物流（RY_WT_enabled_newebpay_shipping = yes）
    And 藍新金流已啟用且設定完成

  Rule: 物流方式可用性

    Scenario: 藍新超商取貨物流註冊
      When WooCommerce 載入運送方式
      Then 應註冊 ry_newebpay_shipping_cvs 物流方式

    Scenario: 金流未啟用時物流不可用
      Given 藍新金流未啟用
      When 嘗試啟用藍新物流
      Then 應顯示錯誤且物流設為停用

    Scenario: API 金鑰驗證
      Given MerchantID、HashKey、HashIV 已設定
      When 檢查物流方式可用性
      Then 物流方式應可用

  Rule: 付款方式限制

    Scenario: 使用藍新物流時限制付款方式
      Given Customer 選擇藍新超商取貨
      When 結帳頁載入付款方式
      Then 僅顯示 ry_newebpay_* 閘道和 cod（貨到付款）
      And 其他付款方式應被移除

  Rule: COD 貨到付款流程

    Scenario: 藍新超商貨到付款訂單處理
      Given Customer 選擇藍新超商取貨 + cod 付款
      When Customer 提交訂單
      Then 訂單狀態應先設為 pending（非一般 cod 的 processing）
      And 重導至收據頁
      And 收據頁應產生藍新 MPG 表單（CVSCOM=2）

  Rule: 結帳欄位

    Scenario: 超商取貨時隱藏運送地址
      Given Customer 選擇藍新超商取貨
      When 結帳頁面更新
      Then 所有運送欄位應加入 ry-hide class

    Scenario: 送出訂單時移除地址必填
      Given Customer 使用藍新超商取貨
      When 送出結帳表單
      Then shipping_first_name, shipping_address_1 等欄位不再 required

  Rule: 運費計算

    Scenario: 基本運費計算
      Given ry_newebpay_shipping_cvs 運費設為 60 元
      When 計算運費
      Then 運費應為 60 元

    Scenario: 滿額免運
      Given requires = min_amount, min_amount = 1000
      And 購物車小計為 1200 元
      When 計算運費
      Then 運費應為 0 元

    Scenario: 重量加收運費
      Given weight_plus_cost = 5（每 5 公斤一件）
      And 購物車總重量為 12 公斤
      When 計算運費
      Then 應計算 3 件運費（ceil(12/5)=3）

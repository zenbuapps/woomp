Feature: 立吉富物流配送
  As a 消費者或管理員
  I want 透過立吉富（PayNow）物流服務完成超商取貨或黑貓宅配
  So that 商品能正確配送至消費者選擇的門市或地址

  Background:
    Given 網站已啟用立吉富物流
    And paynow_shipping_user_account 和 paynow_shipping_api_code 已設定

  Rule: 物流方式註冊

    Scenario: 超商取貨物流方式註冊
      When WooCommerce 載入運送方式
      Then 應註冊 9 種 CVS 物流方式和 3 種 HD 物流方式

    Scenario: 物流方式可用性檢查
      Given paynow_shipping_c2c_711 已加入運送區域
      When 檢查可用性
      Then 應根據 enabled 狀態和商品運送類別判斷

  Rule: 超商選店流程

    Scenario: 顧客選擇 7-11 門市
      Given Customer 選擇 paynow_shipping_c2c_711
      When Customer 點擊「選擇超商」按鈕
      Then 應呼叫立吉富選店 API（Choselogistics）
      And Logistic_serviceID 應為 01（7-11）
      And apicode 應使用 DES-EDE3-ECB 加密

    Scenario: 全家冷凍需額外欄位
      Given Customer 選擇 paynow_shipping_b2c_family_frozen
      When 結帳送出驗證
      Then paynow_reservedno（預約編號）應為必填
      And paynow_shipdate（出貨日期）應為必填

  Rule: 結帳欄位驗證

    Scenario: 超商取貨未選門市
      Given Customer 選擇超商取貨但未選擇門市
      When 送出結帳表單
      Then 應顯示錯誤「Please select a CVS store.」

    Scenario: 手機格式驗證
      Given Customer 輸入手機號碼 '123'
      When 送出結帳表單
      Then 應顯示「Shipping Phone format is invalid」

    Scenario: 合法手機格式
      Given Customer 輸入手機號碼 '0912345678'
      When 驗證手機格式
      Then 應通過驗證（10-11 位，0 開頭）

  Rule: 物流單建立

    Scenario: 訂單處理中自動建立物流單
      Given 訂單使用立吉富超商取貨
      And 訂單尚無物流單號
      When 訂單狀態變更為 processing
      Then 應呼叫 Add_Order API 建立物流單
      And 回應應包含 LogisticNumber、paymentno、validationno

    Scenario: 代收貨款物流單
      Given 訂單付款方式為 cod
      When 建立物流單
      Then DeliverMode 應為 01（取貨付款）

    Scenario: 取貨不付款物流單
      Given 訂單付款方式為信用卡
      When 建立物流單
      Then DeliverMode 應為 02（取貨不付款）

    Scenario: 黑貓常溫宅配物流單
      Given 訂單使用 paynow_shipping_hd_tcat
      When 建立物流單
      Then DeliveryType 應為 0001（常溫）
      And 包裹規格為 Weight=5, Length=5, Width=4, Height=3

    Scenario: 黑貓冷凍宅配
      Given 訂單使用 woomp_paynow_shipping_hd_tcat_frozen
      When 建立物流單
      Then DeliveryType 應為 0003（冷凍）

    Scenario: 重新取號
      Given 訂單已有物流單號且 Status != 1
      When 再次觸發取號
      Then 應呼叫 ReNewOrder API
      And 回應應包含新的 LogisticNumber

  Rule: 物流單管理

    Scenario: 取消物流單
      Given Admin 點擊「取消物流單」
      When 呼叫 CancelOrder API（DELETE 方法）
      Then 成功回應應包含 'S'
      And 應自動重新查詢物流狀態

    Scenario: 後台重選超商自動取消重建
      Given Admin 在後台變更超商門市
      When 觸發 paynow_after_admin_changed_cvs_store
      Then 應先取消舊物流單
      And 成功後自動建立新物流單

    Scenario: 查詢物流狀態
      Given Admin 點擊「更新配送狀態」
      When 呼叫 Get_Order_Info API
      Then 應更新 LogisticNumber、Status、Delivery_Status、PayNowLogisticCode 等 meta

  Rule: 物流標籤列印

    Scenario: 列印 7-11 C2C 標籤
      Given 訂單物流服務為 7-11 C2C
      When Admin 列印標籤
      Then 應呼叫 /api/Order711 API
      And 使用 RenewOrderNo 作為訂單編號

    Scenario: 列印黑貓標籤
      Given 訂單物流服務為黑貓
      When Admin 列印標籤
      Then 應呼叫 /Member/Order/PrintBlackCatLabel API（POST）
      And 回傳 PDF 格式

    Scenario: 批量列印
      Given Admin 選取多筆訂單
      When 選擇「Print Shipping Label」批量操作
      Then 應先依物流服務分類訂單
      And 產生各物流服務的列印 URL

  Rule: 加密機制

    Scenario: API 請求加密
      Given 物流單參數 JSON 已組建
      When 加密傳送
      Then 應使用 DES-EDE3 + OPENSSL_NO_PADDING 加密
      And 金鑰為固定值 '123456789070828783123456'
      And 結果 Base64 編碼

  Rule: 地址顯示

    Scenario: 超商取貨地址格式
      Given 訂單使用超商取貨
      When 顯示運送地址
      Then 應顯示格式：門市名稱 (門市代號) + 門市地址 + 聯絡電話

    Scenario: 黑貓宅配地址格式
      Given 訂單使用黑貓宅配
      When 顯示運送地址
      Then 應顯示格式：郵遞區號 + 縣市 + 地址 + 聯絡電話

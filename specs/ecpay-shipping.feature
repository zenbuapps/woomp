Feature: 綠界物流配送
  As a 消費者或管理員
  I want 透過綠界科技的物流服務完成超商取貨或宅配
  So that 商品能送達消費者手中並追蹤配送狀態

  Background:
    Given 網站已啟用綠界物流（RY_WT_enabled_ecpay_shipping = yes）
    And 綠界物流已設定 MerchantID、HashKey、HashIV
    And 寄件人名稱、手機號碼已正確設定

  Rule: 物流方式註冊與可用性

    Scenario: 綠界物流方式正確註冊
      Given 綠界物流已啟用
      When WooCommerce 載入運送方式
      Then 應註冊 9 種物流方式（5 種 CVS + 4 種 Home）

    Scenario: API 金鑰未設定時物流不可用
      Given MerchantID 為空
      When 檢查物流方式是否可用
      Then 所有綠界物流方式不可用

    Scenario: 運送目的地為 billing_only 時 CVS 停用
      Given woocommerce_ship_to_destination 為 billing_only
      When 更新運送設定
      Then ecpay_shipping_cvs_type 應設為 disable
      And CVS 相關物流方式應被停用

  Rule: 超商選店流程

    Scenario: 顧客選擇超商門市
      Given Customer 選擇「綠界 7-11 取貨」物流方式
      When Customer 點擊「選擇超商」按鈕
      Then 應開啟綠界超商地圖頁面
      And POST 參數應包含 LogisticsType=CVS, LogisticsSubType=UNIMART（或 UNIMARTC2C）

    Scenario: 超商門市資訊回傳
      Given Customer 在綠界地圖選擇了門市
      When 綠界回調超商資訊
      Then 結帳頁應顯示門市名稱、地址、電話
      And CVSStoreID 應填入隱藏欄位

    Scenario: 外島門市運費重新計算
      Given Customer 原本選擇本島門市
      When Customer 改選外島門市（CVSOutSide 改變）
      Then shipping rate 快取應被清除
      And 運費應重新計算

  Rule: 結帳欄位處理

    Scenario: 超商取貨時隱藏一般地址欄位
      Given Customer 選擇超商取貨物流
      When 結帳頁面載入
      Then 一般運送地址欄位（address_1, city, state, postcode）應被隱藏
      And 超商資訊欄位（CVSStoreName, CVSAddress, CVSTelephone）應顯示

    Scenario: 超商取貨欄位驗證
      Given Customer 選擇超商取貨物流但未選擇門市
      When Customer 提交訂單
      Then CVSStoreName 為 required，驗證應失敗

  Rule: 訂單建立與物流單取號

    Scenario: 超商取貨訂單儲存門市資訊
      Given Customer 選擇 7-11 門市「中山門市(123456)」
      When 訂單建立
      Then _shipping_cvs_store_ID 應為 123456
      And _shipping_cvs_store_name 應為「中山門市」
      And shipping_address_1 應設為門市地址

    Scenario: 自動取號（on-hold）
      Given ecpay_shipping_auto_get_no = yes
      And 訂單尚無物流資訊
      When 訂單狀態變更為 on-hold
      Then 應排程 WC Queue 取得物流編號

    Scenario: 超商取貨 C2C 物流單建立
      Given ecpay_shipping_cvs_type = C2C
      And 訂單使用 7-11 取貨
      When 呼叫取號 API
      Then LogisticsSubType 應為 UNIMARTC2C
      And API 應回傳 AllPayLogisticsID、CVSPaymentNo、CVSValidationNo

    Scenario: 代收貨款物流單
      Given 訂單付款方式為 cod（貨到付款）
      When 建立第一張物流單
      Then IsCollection 應為 Y
      And CollectionAmount 應為訂單金額

    Scenario: 多件包裹取號
      Given 物流項目的 no_count = 3
      When 呼叫取號 API
      Then 應連續建立 3 張物流單
      And 僅第一張為代收貨款，後續 IsCollection=N

  Rule: 宅配物流

    Scenario: 黑貓常溫宅配
      Given Customer 選擇「綠界黑貓宅配」
      When 建立物流單
      Then LogisticsType 應為 Home
      And LogisticsSubType 應為 TCAT
      And Temperature 應為 0001
      And 應包含 SenderZipCode、SenderAddress、ReceiverAddress

    Scenario: 黑貓冷凍宅配
      Given Customer 選擇「綠界黑貓冷凍宅配」
      When 建立物流單
      Then Temperature 應為 0003

    Scenario: 郵局宅配
      Given Customer 選擇「綠界郵局宅配」
      When 建立物流單
      Then LogisticsSubType 應為 POST
      And GoodsWeight 應為 1

  Rule: 物流狀態追蹤

    Scenario: 超商配送中
      Given 物流單已建立
      When 綠界回調狀態碼 2030
      Then 訂單狀態應變更為 wmp-in-transit

    Scenario: 超商已到店
      Given 物流狀態為配送中
      When 綠界回調狀態碼 2063
      Then 訂單狀態應變更為 ry-at-cvs
      And 應發送到店通知 Email

    Scenario: 超商逾期未取
      When 綠界回調狀態碼 2074
      Then 訂單狀態應變更為 ry-out-cvs

    Scenario: 超商取貨完成（自動完成啟用）
      Given ecpay_shipping_auto_completed = yes
      When 綠界回調狀態碼 2067
      Then 訂單狀態應變更為 completed

    Scenario: 黑貓已送達
      When 綠界回調狀態碼 3003
      Then 訂單狀態應變更為 completed

  Rule: 物流標籤列印

    Scenario: 列印單一訂單超商物流單（C2C）
      Given 訂單有 LogisticsSubType=UNIMARTC2C 的物流資訊
      When Admin 點擊列印物流單
      Then 應產生表單提交至 PrintUniMartC2COrderInfo API

    Scenario: 批量列印中華郵政宅配單
      Given 選取多筆訂單
      When Admin 選擇「列印中華郵政宅配單(綠界)」批量操作
      Then 應先為無物流單的訂單取號
      And 然後重導至列印頁面

  Rule: 設定驗證

    Scenario: 寄件人名稱驗證
      Given 寄件人名稱為空
      When 儲存綠界物流設定
      Then 應顯示驗證失敗訊息（名稱長度 1-10 字元）
      And 物流應停用

    Scenario: 寄件人手機格式驗證
      Given 寄件人手機為 0912345
      When 儲存綠界物流設定
      Then 應顯示手機格式錯誤（應為 09xxxxxxxx）

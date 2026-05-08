Feature: PayUni v1 信用卡付款
  As a 消費者
  I want 使用信用卡在站內完成付款
  So that 不需跳轉到外部頁面即可完成交易

  Background:
    Given PayUni 金流已啟用（wc_woomp_enabled_payuni_gateway = yes）
    And 信用卡閘道已啟用（payuni-credit enabled = yes）
    And 商店已設定 MerchantID、Hash Key、Hash IV
    And 訂單金額大於等於最低金額 10 元

  Rule: 站內信用卡付款基本流程

    Scenario: 消費者使用新卡片成功付款（無 3D 驗證）
      Given 3D 驗證已關閉（payuni_3d_auth = no）
      And 消費者未勾選「儲存付款資訊」
      When 消費者輸入有效的信用卡號、有效期限、安全碼
      And 消費者點擊下單按鈕
      Then 系統以 AES-256-GCM 加密交易參數
      And 系統發送 POST 請求到 PayUni api/credit 端點
      And PayUni 回傳 Status = 'SUCCESS'
      And 訂單狀態更新為 processing
      And 訂單 meta 儲存 _payuni_resp_trade_no
      And 訂單 meta 儲存 _payuni_card_number（末四碼）
      And 消費者被導向訂單完成頁面

    Scenario: 消費者使用新卡片成功付款（啟用 3D 驗證）
      Given 3D 驗證已啟用（payuni_3d_auth = yes）
      When 消費者輸入有效的信用卡資料並下單
      Then 系統在交易參數中加入 API3D=1 和 ReturnURL
      And PayUni 回傳 Status = 'SUCCESS' 且包含 3D 驗證 URL
      And 消費者被導向 PayUni 3D 驗證頁面
      And 3D 驗證完成後 PayUni POST 通知到 wc-api/payuni_notify_card
      And ResponseHandler 解密回應並更新訂單狀態為 processing

  Rule: 信用卡付款錯誤處理

    Scenario: 消費者未填寫信用卡號碼
      When 消費者未填寫信用卡號碼即點擊下單
      Then 結帳頁顯示錯誤訊息「Credit card number is required」
      And 付款流程中止

    Scenario: 消費者未填寫有效期限
      When 消費者未填寫有效期限即點擊下單
      Then 結帳頁顯示錯誤訊息「Credit card expired date is required」

    Scenario: 消費者未填寫安全碼
      When 消費者未填寫安全碼即點擊下單
      Then 結帳頁顯示錯誤訊息「Credit card security code is required」

    Scenario: PayUni 回傳交易失敗
      When PayUni API 回傳 Status 非 'SUCCESS'
      Then 結帳頁顯示 PayUni 回傳的錯誤訊息
      And 付款結果為 failed

    Scenario: PayUni 回傳相同訂單編號錯誤（CREDIT04001）
      When PayUni API 回傳 Status = 'CREDIT04001'
      Then 系統呼叫 woomp_copy_order() 建立新訂單
      And 使用新訂單編號重新執行 process_payment

  Rule: 訂單完成頁交易明細顯示

    Scenario: 消費者檢視訂單交易明細
      Given 消費者已使用 payuni-credit 成功付款
      When 消費者檢視訂單詳情頁面
      Then 頁面顯示交易明細表格
      And 表格包含：狀態碼、交易訊息、交易編號、卡號末四碼

  Rule: API 加解密流程

    Scenario: 加密交易請求
      When 系統需要發送交易請求
      Then 系統使用 http_build_query 將參數序列化
      And 使用 openssl_encrypt 以 aes-256-gcm 演算法加密
      And 密鑰為 Hash Key，初始化向量為 Hash IV
      And 加密結果與 tag 以 ':::' 串接後轉為 hex 字串
      And HashInfo 為 SHA-256(HashKey + EncryptInfo + HashIV) 的大寫 hex

    Scenario: 解密交易回應
      When 系統收到 PayUni 加密回應
      Then 系統將 hex 字串轉為 binary
      And 以 ':::' 分割取得加密資料與 tag
      And 使用 openssl_decrypt 以 aes-256-gcm 解密
      And 使用 parse_str 將結果轉為關聯陣列

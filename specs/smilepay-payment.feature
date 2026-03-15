Feature: 速買配金流付款
  As a 消費者
  I want 透過速買配（SmilePay）提供的付款方式完成線上付款
  So that 我可以使用信用卡、ATM、7-11 ibon、全家 FamiPort 或條碼等方式支付

  Background:
    Given 網站已啟用速買配金流（RY_WT_enabled_smilepay_gateway = yes）
    And PHP 已安裝 simplexml 擴充
    And 速買配已設定 Dcvc、Rvg2c、Verify_key、Rot_check

  Rule: 閘道註冊

    Scenario: 速買配金流閘道正確註冊
      When WooCommerce 載入付款閘道
      Then 應註冊 6 種閘道（credit, webatm, atm, cvs_711, cvs_fami, barcode）

    Scenario: 缺少 simplexml 擴充
      Given PHP 未安裝 simplexml
      When 嘗試啟用速買配
      Then 應顯示錯誤且自動停用

  Rule: 信用卡付款（網頁模式）

    Scenario: 信用卡付款重導至速買配
      Given Customer 選擇速買配信用卡
      When 收據頁載入
      Then AJAX 應取得速買配付款 URL
      And 瀏覽器應重導至速買配付款頁面（Pay_zg=1）

    Scenario: 信用卡付款成功回調
      Given 速買配回調 Classif=A
      And Amount 等於訂單金額
      When 驗證碼驗證通過
      Then 訂單應完成付款

    Scenario: 信用卡付款失敗
      Given 速買配回調 Classif=A, Response_id=0
      When 處理回調
      Then 訂單狀態應為 failed
      And 訂單備註應包含 Errdesc 錯誤訊息

  Rule: ATM 虛擬帳號（API 取號模式）

    Scenario: ATM 取號成功
      Given Customer 選擇速買配 ATM
      When API 回應 Status=1
      Then _smilepay_atm_BankCode 和 _smilepay_atm_vAccount 應被儲存
      And _smilepay_atm_ExpireDate 應被儲存
      And 訂單狀態應為 on-hold

  Rule: 超商代碼付款（API 取號模式）

    Scenario: 7-11 ibon 取號成功
      Given Customer 選擇速買配 7-11 代碼（Pay_zg=4）
      When API 回應 Status=1
      Then _smilepay_cvs_PaymentNo 應為 IbonNo
      And 訂單狀態應為 on-hold

    Scenario: 全家 FamiPort 取號成功
      Given Customer 選擇速買配全家代碼（Pay_zg=6）
      When API 回應 Status=1
      Then _smilepay_cvs_PaymentNo 應為 FamiNO
      And 訂單狀態應為 on-hold

  Rule: 條碼繳費（API 取號模式）

    Scenario: 條碼取號成功
      Given Customer 選擇速買配條碼繳費（Pay_zg=3）
      When API 回應 Status=1
      Then _smilepay_barcode_Barcode1/2/3 應被儲存
      And 訂單狀態應為 on-hold

  Rule: 驗證碼機制

    Scenario: 回調驗證碼計算
      Given Rot_check = 9527, 訂單金額 = 1000, SmilePayNO = 12345
      When 計算驗證碼
      Then 取 Rot_check 末 4 碼 = 9527（左補 0）
      And 訂單金額左補 0 至 8 位 = 00001000
      And SmilePayNO 末 4 碼 = 2345（左補 9）
      And 串接 = 9527000010002345
      And 偶數位總和 x 9 + 奇數位總和 x 3 = 驗證碼

    Scenario: 驗證碼不匹配
      Given 回調的 Mid_smilepay 與計算值不同
      When 驗證回調
      Then 應回應 '0|' 錯誤

  Rule: 回調編碼處理

    Scenario: BIG-5 編碼轉換
      Given 速買配回調資料為 BIG-5 編碼
      When 接收回調
      Then 應使用 mb_convert_encoding 轉為 UTF-8

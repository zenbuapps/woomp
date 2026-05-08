Feature: PayUni v3 前端 SDK 互動
  As a 消費者
  I want 在安全的 iframe 中輸入信用卡資料
  So that 我的卡片資訊不會經過商店伺服器，確保安全性

  Background:
    Given 消費者已進入結帳頁面
    And payuni-credit-v3 閘道已啟用
    And PayUni SDK (uni-payment.js) 已載入
    And SDK Token 已取得

  Rule: SDK 初始化與 iframe 渲染

    Scenario: SDK 成功初始化並渲染 iframe
      When WooCommerce 觸發 updated_checkout 事件
      Then checkout.js 延遲 300ms 後建立 Elements 實例
      And Elements 檢測到選中 payuni-credit-v3 付款方式
      And PayUniService 呼叫 UniPayment.createSession(SDK_TOKEN, options)
      And SDK 在 put_card_no 容器中渲染信用卡號 iframe
      And SDK 在 put_card_exp 容器中渲染有效期限 iframe
      And SDK 在 put_card_cvc 容器中渲染安全碼 iframe
      And FormState 設為 isReady = true

    Scenario: 切換到其他付款方式後再切回
      Given SDK iframe 已渲染
      When 消費者切換到其他付款方式再切回 payuni-credit-v3
      Then Elements 檢測到 iframe 已存在
      And 跳過重複渲染（使用 PayUniService 單例）

    Scenario: SDK 未載入
      Given UniPayment 全域物件不存在
      When PayUniService 嘗試初始化
      Then console 顯示 '[PayUni] SDK 未載入'
      And SDK 不會初始化

  Rule: 表單驗證狀態追蹤

    Scenario: SDK 回報所有欄位驗證通過
      When 消費者正確填寫卡號、有效期限、安全碼
      And SDK onUpdate 回報 {CardNo: true, CardExp: true, CardCvc: true}
      Then FormState.isAllValid() 回傳 true

    Scenario: SDK 回報部分欄位未通過
      When 消費者填寫了錯誤的卡號
      And SDK onUpdate 回報 {CardNo: false}
      Then FormState.isAllValid() 回傳 false
      And FormState.getInvalidFields() 回傳 ['CardNo']

    Scenario: Token 模式下忽略 SDK 的 CardNo:null
      Given 消費者已有綁定的記憶卡號
      And sdkTokenCardActive = true
      When SDK onUpdate 回報 {CardNo: null}
      Then FormState 不更新 CardNo 狀態（刪除該 status）
      And CardNo 維持先前設定的 true 值

  Rule: 結帳按鈕攔截

    Scenario: 攔截下單按鈕點擊事件
      Given 消費者選中 payuni-credit-v3 付款方式
      When 消費者點擊 #place_order 按鈕
      Then 事件被 preventDefault 和 stopPropagation 攔截
      And PayUniService 執行 processCheckout 流程

    Scenario: 非 PayUni 付款方式不攔截
      Given 消費者選中其他付款方式（如 payuni-atm）
      When 消費者點擊 #place_order 按鈕
      Then 事件不被攔截
      And WooCommerce 正常處理結帳

    Scenario: 防止重複提交
      Given 結帳表單處於 processing 狀態
      When 消費者再次點擊下單
      Then processCheckout 直接 return
      And 不發送重複請求

  Rule: 新卡片結帳流程（4 步驟）

    Scenario: Step 1 — 從 SDK 取得交易結果
      When PayUniService 呼叫 SDK getTradeResult(config)
      Then config 包含 cardInst（分期期數）
      And config 包含 useDefault（是否使用 token 模式卡號）
      And 若勾選記憶卡號 → config 包含 useTokenType = 1

    Scenario: Step 2 — 送出 WooCommerce 結帳
      When ApiService.submitCheckout() 送出表單
      Then 序列化結帳表單所有欄位
      And 附加 additionalData（sdk_token_tmp, payuni_save_card, payuni_installment, 載具資料）
      And POST 到 wc_checkout_params.checkout_url
      And 回應必須包含 EncryptInfo, HashInfo, MerID, Version, ApiUrl

    Scenario: Step 3 — 執行 PayUni 幕後交易
      When ApiService.sendTradeRequest() 發送交易請求
      Then POST 到 ApiUrl（/iframe/merchant_trade）
      And Content-Type 為 application/x-www-form-urlencoded
      And 請求 body 包含 {MerID, Version, EncryptInfo, HashInfo}
      And 回應 Status 必須為 'SUCCESS'

    Scenario: Step 4 — 導向完成頁面
      When 交易成功
      Then window.location.href 設為 checkoutResponse.redirect
      And 消費者看到訂單完成頁面

  Rule: 已儲存卡片結帳流程

    Scenario: 選擇已儲存的卡片
      Given 消費者有已儲存的卡片（Token ID = 42）
      When 消費者選擇該卡片的 radio button
      Then CardNo 和 CardExp 的 form-group 被隱藏
      And 記憶卡號 checkbox 區域被隱藏
      And payuni_used_token_id hidden input 設為 42

    Scenario: 切回使用新卡片
      Given 消費者先前選擇了已儲存的卡片
      When 消費者選擇「使用新卡片」radio button
      Then 所有 form-group 恢復顯示
      And sdkTokenCardActive 重置為 false
      And payuni_used_token_id 清空

  Rule: 分期付款前端交互

    Scenario: 分期選擇在 WC 更新後保留
      Given 消費者選擇了 6 期分期
      When WooCommerce 觸發 updated_checkout（payment section 重新渲染）
      Then #payuni_installment select 恢復為 6 期

  Rule: 發票載具前端交互

    Scenario: 選擇手機條碼顯示對應輸入框
      When 消費者在 #payuni_carrier_type 選擇「手機條碼」
      Then #payuni_carrier_info_row_3J0002 顯示
      And #payuni_inv_buyer_name_row 顯示
      And 其他載具輸入框隱藏

    Scenario: 選擇捐贈發票不需買方名稱
      When 消費者選擇「捐贈發票」
      Then #payuni_carrier_info_row_Donate 顯示
      And #payuni_inv_buyer_name_row 隱藏

    Scenario: 選擇會員載具不需載具資訊
      When 消費者選擇「會員載具」
      Then 所有載具資訊輸入框隱藏
      And #payuni_inv_buyer_name_row 顯示

  Rule: 錯誤訊息顯示

    Scenario: 顯示 WooCommerce 風格的錯誤訊息
      When 發生錯誤
      Then UIHelper 在結帳表單前插入 .woocommerce-NoticeGroup-checkout
      And 錯誤訊息經過 HTML 跳脫處理
      And 頁面捲動到錯誤訊息位置

    Scenario: PayUni 錯誤代碼轉換
      When 交易回傳錯誤代碼 'IFTRADE04001'
      Then 系統透過 ERROR_MAPPER 轉換為「Token已過期」
      And 顯示給消費者

  Rule: ES6 模組依賴關係

    Scenario: 模組依賴圖
      Then 模組依賴關係如下：
        | 模組                    | 依賴                                                       |
        | checkout.js             | env.module.js, Elements.module.js                          |
        | Elements.module.js      | PayUniService.module.js, env.module.js, utils.module.js, constants.module.js |
        | PayUniService.module.js | env.module.js, utils.module.js, constants.module.js, FormState, UIHelper, ApiService |
        | FormState.module.js     | (無外部依賴)                                                |
        | UIHelper.module.js      | env.module.js, constants.module.js                          |
        | ApiService.module.js    | env.module.js, constants.module.js                          |
        | env.module.js           | window.payuni_payment_v3_checkout_params, window.jQuery      |
        | utils.module.js         | env.module.js, constants.module.js                          |
        | constants.module.js     | (無外部依賴)                                                |

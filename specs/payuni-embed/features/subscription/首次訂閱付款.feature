@ignore @command
Feature: V3 首次訂閱付款

  Background:
    Given PayUni 金流已啟用
    And WooCommerce Subscriptions 外掛已啟用
    And payuni-credit-subscription-v3 閘道已啟用
    And PayUni SDK Token 取得成功
    And 購物車中包含訂閱類型商品

  Rule: 後置（狀態）- 首次付款成功後必須儲存 CreditHash 為 WC_Payment_Token_CC

    Example: 消費者首次訂閱付款成功（含註冊費）
      Given 訂閱商品包含 500 元的註冊費
      And 消費者在結帳頁面選擇 payuni-credit-subscription-v3 閘道
      When 消費者在 UNi Embed iframe 中輸入有效信用卡資料並下單
      Then 系統以訂單總額 500 元執行 merchant_trade 交易
      And 交易參數強制包含 UseTokenType=2 和 CreditToken=消費者email
      And PayUni 回傳成功，包含 CreditHash
      And 系統自動儲存 WC_Payment_Token_CC（gateway_id = payuni-credit-subscription-v3）
      And 訂閱狀態為 active

  Rule: 前置（狀態）- 訂閱付款必須強制儲存卡號（不顯示儲存 checkbox）

    Example: 訂閱付款時不顯示儲存卡號選項
      Given 消費者在結帳頁面選擇 payuni-credit-subscription-v3 閘道
      When 結帳頁面渲染付款方式
      Then 不顯示「儲存付款方式」checkbox
      And 系統在背景強制設定 UseTokenType=2

  Rule: 前置（狀態）- 3D 驗證流程必須正確處理

    Example: 啟用 3D 驗證時首次訂閱付款成功
      Given 3D 驗證已啟用（payuni_3d_auth = yes）
      When 消費者完成信用卡資料填寫並下單
      Then 交易參數包含 API3D=1
      And 3D 驗證完成後透過 webhook 回傳交易結果
      And webhook 處理時儲存 CreditHash 為 WC_Payment_Token_CC
      And 訂閱狀態為 active

  Rule: 前置（狀態）- 訂閱付款不允許分期

    Example: 訂閱付款時不顯示分期選項
      Given 消費者在結帳頁面選擇 payuni-credit-subscription-v3 閘道
      When 結帳頁面渲染付款方式
      Then 不顯示分期付款下拉選單
      And 交易參數不包含 CardInst

  Rule: 前置（參數）- 必要參數必須提供

    Example: 消費者未填寫信用卡資料時操作失敗
      Given 消費者在結帳頁面選擇 payuni-credit-subscription-v3 閘道
      When 消費者未完整填寫信用卡資料即點擊下單
      Then 操作失敗，錯誤為「請完整填寫信用卡資料」

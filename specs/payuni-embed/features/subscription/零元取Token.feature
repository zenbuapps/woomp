@ignore @command
Feature: V3 零元訂閱取得 Token

  Background:
    Given PayUni 金流已啟用
    And WooCommerce Subscriptions 外掛已啟用
    And payuni-credit-subscription-v3 閘道已啟用
    And 訂閱商品沒有註冊費（訂單金額為 0）
    And 消費者沒有 gateway_id 為 payuni-credit-subscription-v3 或 payuni-credit-subscription 的已儲存 Token

  Rule: 後置（狀態）- 零元訂閱應透過 UNi Embed iframe 扣 5 元取得 CreditHash

    Example: 零元訂閱成功取得 CreditHash
      Given 消費者在結帳頁面選擇 payuni-credit-subscription-v3 閘道
      When 消費者在 UNi Embed iframe 中輸入有效信用卡資料並下單
      Then 系統以 TradeAmt=5 執行 merchant_trade 交易
      And 交易參數強制包含 UseTokenType=2 和 CreditToken=消費者email
      And PayUni 回傳成功，包含 CreditHash
      And CreditHash 儲存為 WC_Payment_Token_CC（gateway_id = payuni-credit-subscription-v3）
      And 訂閱狀態為 active

  Rule: 後置（狀態）- 5 元扣款應排程 2 分鐘後取消授權

    Example: 5 元扣款成功後排程取消授權
      Given 零元訂閱的 5 元 merchant_trade 交易成功
      When CreditHash 已儲存
      Then 系統排程 2 分鐘後取消 5 元授權交易
      And 取消授權不影響已儲存的 CreditHash

  Rule: 前置（參數）- 必要參數必須提供

    Example: 消費者未填寫信用卡資料時操作失敗
      Given 消費者在結帳頁面選擇 payuni-credit-subscription-v3 閘道
      When 消費者未完整填寫信用卡資料即點擊下單
      Then 操作失敗，錯誤為「請完整填寫信用卡資料」

  Rule: 前置（狀態）- 3D 驗證流程必須正確處理

    Example: 啟用 3D 驗證時零元取 Token 成功
      Given 3D 驗證已啟用（payuni_3d_auth = yes）
      When 消費者完成信用卡資料填寫並下單
      Then 交易參數包含 API3D=1
      And 3D 驗證完成後透過 webhook 回傳交易結果
      And webhook 處理時儲存 CreditHash 為 WC_Payment_Token_CC
      And 排程 2 分鐘後取消 5 元授權
      And 訂閱狀態為 active

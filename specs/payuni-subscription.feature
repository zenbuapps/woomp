Feature: PayUni v1 信用卡訂閱（定期定額）付款
  As a 消費者
  I want 使用信用卡訂閱定期定額服務
  So that 我的訂閱可以自動續費不需手動操作

  Background:
    Given WooCommerce Subscriptions 外掛已啟用
    And PayUni 金流已啟用
    And 信用卡定期定額閘道已啟用（payuni-credit-subscription enabled = yes）
    And 購物車中包含訂閱類型商品

  Rule: 訂閱首次付款

    Scenario: 消費者首次訂閱付款成功（含註冊費）
      Given 訂閱商品包含 50 元的註冊費
      When 消費者輸入信用卡資料並下單
      Then 系統以訂單總額扣款
      And 系統強制設定 save_new_card = 'yes'
      And 交易參數包含 CreditToken（客戶 email）
      And PayUni 回傳成功，包含 CreditHash
      And 系統自動儲存 WC_Payment_Token_CC（gateway_id: payuni-credit-subscription）
      And 訂閱狀態為 active

    Scenario: 消費者首次訂閱付款成功（無註冊費，需 5 元取 Token）
      Given 訂閱商品沒有註冊費（訂單金額為 0）
      And 消費者沒有已儲存的信用卡 Token
      When 消費者輸入信用卡資料並下單
      Then 系統走 build_hash_request 流程，扣款 5 元以取得 CreditHash
      And 系統排程 2 分鐘後取消 5 元授權交易
      And CreditHash 儲存為 WC_Payment_Token_CC
      And 訂閱狀態為 active

    Scenario: 消費者首次訂閱付款（無註冊費，已有 Token）
      Given 訂單金額為 0
      And 消費者已有 payuni-credit-subscription 的 Token
      When 消費者選擇已存卡片並下單
      Then 系統直接呼叫 payment_complete()
      And 訂單備註記錄「持有 token 且訂單金額為 0，直接轉為處理中」
      And 無需發送 PayUni API 請求

  Rule: 定期自動扣款

    Scenario: 定期扣款成功
      Given 訂閱已啟用且消費者有有效的 CreditHash
      When WooCommerce Subscriptions 排程觸發定期扣款
      Then SubscriptionHandler::process_subscription_payment() 被呼叫
      And RequestBuilder::build_subscription_request() 使用已存的 CreditHash
      And 交易參數不包含 API3D（定期扣款不需 3D 驗證）
      And PayUni API 回傳成功
      And 訂單狀態更新為 processing

    Scenario: 定期扣款失敗後自動重試
      Given 定期扣款失敗
      When WooCommerce Subscriptions 觸發 woocommerce_subscription_renewal_payment_failed
      And 訂單狀態為 failed
      And PayUni 回應狀態非 SUCCESS
      Then SubscriptionHandler 將訂單狀態改回 pending
      And 允許下次排程重新扣款

  Rule: CreditHash 取得邏輯

    Scenario: 使用用戶預設 Token
      Given 消費者的預設 Token gateway_id 為 payuni-credit-subscription
      When 定期扣款需要取得 CreditHash
      Then 系統使用消費者的預設 Token

    Scenario: 使用最新的 Token
      Given 消費者的預設 Token gateway_id 不是 payuni-credit-subscription
      When 定期扣款需要取得 CreditHash
      Then 系統取得 payuni-credit-subscription 的所有 Token
      And 使用最後一筆（最新）Token 的 CreditHash

  Rule: 閘道條件顯示

    Scenario: 非訂閱商品不顯示定期定額閘道
      Given 購物車中僅包含一般商品（非 subscription 類型）
      When 消費者進入結帳頁面
      Then payuni-credit-subscription 閘道不出現在付款方式列表中

    Scenario: 訂閱商品顯示定期定額閘道
      Given 購物車中包含 subscription 類型商品
      When 消費者進入結帳頁面
      Then payuni-credit-subscription 閘道出現在付款方式列表中

  Rule: 支援的 WooCommerce Subscriptions 功能

    Scenario: 閘道宣告支援的 Subscriptions 功能
      Then payuni-credit-subscription 閘道 supports 陣列包含：
        | 功能名稱                                          |
        | subscriptions                                     |
        | subscription_cancellation                         |
        | subscription_suspension                           |
        | subscription_reactivation                         |
        | subscription_amount_changes                       |
        | subscription_date_changes                         |
        | subscription_payment_method_change                |
        | subscription_payment_method_change_customer       |
        | subscription_payment_method_change_admin          |
        | multiple_subscriptions                            |
        | tokenization                                      |

  Rule: PayUni V3 換卡（payuni-credit-subscription-v3，issue #147）

    Background:
      Given 信用卡定期定額 V3 閘道已啟用（payuni-credit-subscription-v3）
      And 訂閱客戶已登入，並進入「我的帳號 → 訂閱 → 更改付款」換卡頁

    Scenario: 換卡頁只顯示 V3 閘道
      Given 訂閱目前使用 V1（payuni-credit-subscription）
      Then 換卡頁保留 payuni-credit-subscription-v3
      And 換卡頁不顯示 payuni-credit-subscription
      And woomp_payuni_allow_v1_change_payment filter 回傳 true 時才保留 V1

    Scenario: 一般結帳空購物車仍過濾訂閱閘道
      Given 購物車為空且不是換卡請求
      When 消費者進入結帳頁面
      Then V1 與 V3 訂閱閘道都不出現

    Scenario: 換卡只授權 5 元，不扣每期金額
      Given 訂閱每期金額為 9450 元，WCS 已把訂閱 total 歸零
      When 客戶送出換卡表單
      Then 系統收到訂閱物件，一律走換卡流程
      And PayUni 交易 TradeAmt 為 5、UseTokenType 為 3
      And MerTradeNo 符合 {訂閱ID}-C{n} 格式
      And 系統不呼叫 payment_complete()，也不呼叫 woomp_copy_order()

    Scenario: 客戶原本有 V1 預設 token，仍須重新綁卡
      Given 客戶有 V1 預設 token
      When 客戶送出換卡表單
      Then 系統仍向 PayUni 發出 5 元綁卡請求
      And 不直接沿用 V1 token 完成

    Scenario: 不需 3D，同步綁卡成功
      When PayUni 同步回傳 SUCCESS 且含 CreditHash
      Then 系統建立 V3 token 並設為客戶預設 token
      And 訂閱付款方式改為 payuni-credit-subscription-v3，_payuni_token_id 指向新 token
      And 系統排程 2 分鐘後取消 5 元授權
      And 訂閱與其待付款續訂單的狀態不變
      And 導回訂閱檢視頁

    Scenario: 授權成功但沒有 CreditHash
      When PayUni 回傳 SUCCESS 但沒有 CreditHash
      Then 付款方式不變
      And 訂閱備註記錄「綁卡未完成」
      And 客戶看到錯誤提示

    Scenario: 沒有 sdk_token 或訪客
      When 表單沒有 sdk_token_tmp，或客戶不是訂閱本人
      Then 回傳 failure 並顯示提示，不發出任何 PayUni 請求

    Scenario: 需 3D 驗證，付款方式延後到 webhook 才更新
      When PayUni 回傳 3D 驗證網址
      Then 客戶被導向 PayUni 網域（allowed_redirect_hosts 只在本次請求放行 payuni.com.tw）
      And 付款方式仍是原本的閘道
      And 訂閱有 _payuni_change_pending，檢視頁顯示「綁卡處理中」

    Scenario: 3D 完成後 webhook 成功
      Given MerTradeNo 為該訂閱 attempts 內最新一次嘗試
      When PayUni POST /wc-api/payuni_notify，Status 為 SUCCESS 且 TradeAmt 為 5
      Then 系統綁卡並更新付款方式，排程取消 5 元
      And 客戶先前勾選「同時更新所有訂閱」時，同客戶其他訂閱也切換並帶上 token
      And 同一 TradeNo 重複通知只處理一次

    Scenario: 3D 失敗或中途離開
      When webhook 回傳非 SUCCESS，或客戶關閉頁面
      Then 付款方式維持原狀
      And 失敗時訂閱備註記錄原因、清除 pending

    Scenario: 舊嘗試的成功通知
      Given 客戶已重新發起換卡，舊 MerTradeNo 不是最新一次嘗試
      When 舊 MerTradeNo 收到 SUCCESS
      Then 系統只排程取消該筆 5 元授權，不綁定、不覆蓋最新結果

    Scenario Outline: 偽造的換卡通知一律拒絕
      When 通知的 <異常> 
      Then 系統拒絕、寫入 error log，不做任何變更
      Examples:
        | 異常                              |
        | MerTradeNo 不在該訂閱的 attempts  |
        | MerTradeNo 的 ID 不是訂閱         |
        | MerID 與商店設定不符              |
        | Status 為 SUCCESS 但 TradeAmt 不是 5 |

    Scenario: 換卡暫存資料不複製到續訂單
      When WCS 建立續訂單
      Then 續訂單不含 _payuni_change_* 與 _payuni_mer_trade_no
      And 續訂單保留 _payuni_token_id

  Rule: 續扣 CreditHash 取得優先序（V3 定期定額）

    Scenario: 依序取得續扣 token
      When 續訂單需要 CreditHash
      Then 系統依下列順序取第一個有效 token（存在、屬於訂單客戶、閘道符合、token 值非空）
        | 順序 | 來源                                                  |
        | 1    | 續訂單所屬訂閱的 _payuni_token_id                     |
        | 2    | 續訂單自己的 _payuni_token_id                         |
        | 3    | 客戶預設 token（僅限 payuni-credit-subscription-v3）  |
        | 4    | 客戶最新的 payuni-credit-subscription-v3 token        |
        | 5    | 客戶最新的 payuni-credit-v3 token                     |
        | 6    | 客戶最新的 V1 token（最後手段，寫 warning log）       |

    Scenario: 舊 V1 預設 token 不搶先
      Given 客戶的預設 token 是 V1，另有一張非預設的 V3 token
      When 續訂單需要 CreditHash
      Then 使用 V3 token

    Scenario: 訂閱綁定的 token 無效則往下一層
      Given 訂閱的 _payuni_token_id 指向已刪除、他人或 V1 的 token
      When 續訂單需要 CreditHash
      Then 跳過該 token 並往下一層尋找

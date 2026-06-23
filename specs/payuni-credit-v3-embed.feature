Feature: PayUni v3 UNi Embed 信用卡付款
  As a 消費者
  I want 使用免跳轉的 iframe 信用卡表單完成付款
  So that 擁有更流暢的結帳體驗，信用卡資料不經過商店伺服器

  Background:
    Given PayUni 金流已啟用（wc_woomp_enabled_payuni_gateway = yes）
    And payuni-credit-v3 閘道已啟用
    And 商店已設定 MerchantID、Hash Key、Hash IV
    And PayUni SDK Token 取得成功

  Rule: UNi Embed 免跳轉信用卡付款

    Scenario: 消費者使用新卡片成功付款
      Given 消費者在結帳頁面選擇「統一金流 PAYUNi 信用卡 v3」
      And PayUni SDK iframe 已渲染在 put_card_no, put_card_exp, put_card_cvc 容器中
      When 消費者在 iframe 中輸入有效的信用卡號、有效期限、安全碼
      And SDK onUpdate 回報所有欄位 status 為 true
      And 消費者點擊下單按鈕
      Then 前端呼叫 SDK getTradeResult() 取得加密交易結果
      And 前端送出 WooCommerce 結帳請求（含 sdk_token_tmp）
      And 後端 process_payment 組裝 TradeReqDTO 加密參數
      And 後端呼叫 PayUni /iframe/merchant_trade 執行幕後授權
      And TradeHandler 解密回應並更新訂單狀態為 processing
      And 前端收到 redirect URL 並導向訂單完成頁面

    Scenario: 消費者使用新卡片成功付款（啟用 3D 驗證）
      Given 3D 驗證已啟用（payuni_3d_auth = yes）
      When 消費者完成信用卡資料填寫並下單
      Then 交易參數包含 API3D=1
      And PayUni 可能要求 3D 驗證（由 PayUni 控制是否觸發）
      And 交易結果透過 webhook 通知 wc-api/payuni_notify

    Scenario: 消費者使用已儲存的卡片付款
      Given 記憶卡號功能已啟用（enable_tokenization = yes）
      And 消費者已有儲存的信用卡 Token
      When 消費者選擇已儲存的卡片（radio button）
      Then 前端隱藏 CardNo 和 CardExp 輸入框
      And 前端設定 payuni_use_saved_token = '1' 和 payuni_saved_token_id
      And 前端呼叫 SDK getTradeResult({ useDefault: true })
      And 後端從 WC_Payment_Tokens 取得 CreditHash
      And 後端在交易參數中加入 UseTokenType=2 和 CreditToken

  Rule: 前端表單驗證

    Scenario: 消費者未完整填寫信用卡資料
      Given SDK iframe 已載入
      When 消費者點擊下單但 CardCvc 欄位未通過驗證
      Then 前端顯示錯誤訊息「安全碼 填寫有誤，請重新確認」
      And 結帳流程中止，不送出請求

    Scenario: SDK iframe 連線逾時
      When SDK iframe 連線時間過長
      Then 前端顯示錯誤訊息「信用卡輸入框連線逾時，請重新整理頁面」
      And FormState 設為 isReady = false

    Scenario: SDK Token 取得失敗
      Given SDK Token 取得過程中發生錯誤
      When 結帳頁面載入
      Then SDK Token 為空字串
      And 前端 console 顯示 '[PayUni] SDK Token 未設定'
      And SDK 不會初始化
      And 結帳頁顯示可見錯誤訊息「信用卡付款目前無法使用，請稍後再試或改用其他付款方式」
      And FormState 設為 isReady = false
      And 消費者點擊下單時被攔截並再次顯示該錯誤訊息

    Scenario: SDK Token 取得失敗（管理員檢視）
      Given SDK Token 取得過程中發生錯誤
      And 目前登入者具 manage_woocommerce 權限
      When 結帳頁面載入
      Then 後端透過 INIT_ERROR_DETAIL 帶出失敗原因
      And 結帳頁錯誤訊息額外附上「（管理員資訊：取得 SDK Token 失敗: ...）」

    Scenario: SDK 主程式未載入
      Given PayUni uni-payment.js 因網路或載入失敗未就緒
      When 結帳頁面載入且消費者選擇「統一金流 PAYUNi 信用卡 v3」
      Then 前端 console 顯示 '[PayUni] SDK 未載入，請確認 uni-payment.js 已正確引入'
      And 結帳頁顯示可見錯誤訊息「信用卡付款元件載入失敗，請重新整理頁面，若持續發生請聯繫網站管理員」
      And FormState 設為 isReady = false

  Rule: 分期付款整合

    Scenario: 消費者選擇分期付款
      Given 閘道設定中啟用了 3 期、6 期、12 期分期選項
      When 消費者在分期下拉選單中選擇 6 期
      And 消費者完成付款
      Then 交易參數中 CardInst = 6
      And 訂單以分期方式完成交易

    Scenario: 消費者選擇不支援的分期期數
      Given 閘道僅啟用 3 期和 6 期
      When 交易參數中 CardInst = 12
      Then 系統拋出例外「不支援的分期期數：12 期」

  Rule: 發票載具整合

    Scenario: 消費者選擇手機條碼載具
      When 消費者在載具類型下拉選單選擇「手機條碼」
      And 輸入手機條碼 /ABC1234
      Then 交易參數包含 CarrierType='3J0002', CarrierInfo='/ABC1234'
      And InvBuyerName 為消費者填寫的買方名稱或帳單姓名

    Scenario: 消費者選擇捐贈發票
      When 消費者選擇「捐贈發票」
      And 輸入捐贈碼 168
      Then 交易參數包含 CarrierType='Donate', CarrierInfo='168'
      And 不需要填寫買方名稱

    Scenario: 消費者輸入格式錯誤的手機條碼
      When 消費者輸入手機條碼 ABC1234（缺少 / 開頭）
      Then CreditV3Gateway::validate_fields() 回傳 false
      And 顯示錯誤訊息「手機條碼格式不正確，格式為 / 開頭加上 7 碼大寫英數字」

    Scenario: 消費者選擇公司發票
      When 消費者選擇「公司發票（統編）」
      And 輸入統一編號 12345678
      Then 交易參數包含 CarrierType='Company', CarrierInfo='12345678'

  Rule: 退款處理

    Scenario: 管理員發起退款（交易已請款成功）
      Given 訂單已使用 payuni-credit-v3 付款完成
      And PayUni 交易 CloseStatus = '2'（請款成功）
      When 管理員在後台發起退款
      Then CreditV3Gateway::process_refund() 先查詢交易狀態
      And 呼叫 HttpClient::refund() POST 到 /api/trade/close
      And 訂單備註記錄退款成功金額

    Scenario: 管理員發起退款（交易請款申請中）
      Given PayUni 交易 CloseStatus = '1'（請款申請中）
      When 管理員發起退款
      Then 系統呼叫 HttpClient::cancel_trade() POST 到 /api/trade/cancel
      And 訂單備註記錄取消請款成功

  Rule: 訂單完成頁面顯示

    Scenario: 消費者檢視 v3 訂單交易明細
      Given 消費者已使用 payuni-credit-v3 成功付款
      When 消費者檢視訂單詳情頁面
      Then 頁面顯示交易明細：狀態碼、交易訊息、交易編號、卡號末四碼

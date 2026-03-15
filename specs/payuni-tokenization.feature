Feature: PayUni 記憶卡號（Tokenization）
  As a 登入的消費者
  I want 儲存我的信用卡資訊以便下次快速結帳
  So that 不需要每次購物都重新輸入卡號

  Background:
    Given 消費者已登入
    And PayUni 金流已啟用
    And 閘道已啟用 tokenization 支援

  Rule: v1 結帳時記憶卡號

    Scenario: 消費者勾選儲存卡號並成功付款
      Given payuni-credit 閘道已啟用 tokenization
      When 消費者輸入信用卡資料
      And 消費者勾選「儲存付款資訊，下次付款更方便」
      And 消費者完成付款
      Then 交易參數包含 CreditToken = 消費者 email
      And PayUni 回傳 CreditHash
      And 系統建立 WC_Payment_Token_CC
      And Token gateway_id = 'payuni-credit'
      And Token card_type = 'visa'
      And Token 設為預設

    Scenario: 消費者使用已儲存的卡片付款
      Given 消費者已有儲存的 payuni-credit Token
      When 消費者在結帳頁選擇已儲存的卡片
      Then 系統跳過卡號、有效期限、安全碼驗證
      And 交易參數使用 CreditHash 取代 CardNo/CardExpired/CardCVC

  Rule: v1 My Account 新增付款方式

    Scenario: 消費者在 My Account 新增卡片（無 3D 驗證）
      Given 3D 驗證已關閉
      When 消費者在 My Account > Payment Methods 頁面填寫卡片資料
      Then 系統建立金額 5 元的臨時訂單
      And 系統禁止發送所有 WooCommerce Email
      And 系統發送 5 元扣款請求到 PayUni
      And PayUni 回傳 CreditHash
      And 系統儲存 WC_Payment_Token_CC
      And 系統排程 2 分鐘後取消 5 元授權
      And 臨時訂單被刪除
      And 消費者被導回 payment-methods 頁面

    Scenario: 消費者在 My Account 新增卡片（啟用 3D 驗證）
      Given 3D 驗證已啟用
      When 消費者填寫卡片資料
      Then 消費者被導向 PayUni 3D 驗證頁面
      And 驗證完成後 PayUni 通知回調
      And 系統儲存 Token 並取消 5 元授權

  Rule: v3 UNi Embed 記憶卡號

    Scenario: v3 SDK Token 請求包含記憶卡號參數
      Given payuni-credit-v3 啟用記憶卡號功能
      When 系統取得 SDK Token
      Then 請求參數包含 UseTokenType=2, CreditToken=消費者email, CreditTokenType=1

    Scenario: v3 消費者勾選記憶卡號
      Given payuni-credit-v3 啟用記憶卡號功能
      When PayUni SDK 觸發 useTokenType 事件
      Then 前端顯示記憶卡號 checkbox 區域
      And 若消費者已有綁定卡號，顯示已綁定卡號資訊

    Scenario: v3 儲存新的 Token
      Given 消費者勾選記憶卡號 checkbox
      When 交易成功
      Then TradeHandler 建立 WC_Payment_Token_CC
      And gateway_id = 'payuni-credit-v3'
      And card_type 依卡號前綴判斷（4=visa, 5=mastercard, 3=amex, 6=discover）
      And 不儲存重複的 Token（比對 token_key）
      And 第一張卡自動設為預設

    Scenario: v3 消費者使用已儲存卡片
      Given 消費者選擇已儲存的卡片（radio button 非 'new'）
      When 消費者點擊下單
      Then 前端隱藏 CardNo 和 CardExp 輸入框
      And 前端隱藏記憶卡號 checkbox
      And 前端呼叫 SDK getTradeResult({ useDefault: true })
      And additionalData 包含 payuni_use_saved_token='1', payuni_saved_token_id

    Scenario: v3 已儲存卡片的 CreditHash 無效
      Given 消費者選擇了已儲存的卡片
      When 後端從 WC_Payment_Tokens 取得的 CreditHash 為空
      Then 系統拋出例外「已儲存的卡片資料無效或已過期，請使用新卡片付款。」

  Rule: Token 顯示格式

    Scenario: v1 已儲存卡片的顯示格式
      Given 消費者有已儲存的 Token（末四碼 1234，到期 12/25）
      When 結帳頁面顯示已儲存的付款方式
      Then 顯示格式為「卡號末四碼：1234（到期日 12 / 25）」

    Scenario: v3 已儲存卡片的顯示格式
      Given 消費者有已儲存的 Token（末四碼 5678，到期 06/28）
      When 結帳頁面顯示已儲存的卡片選項
      Then 顯示格式為「**** **** **** 5678 (到期: 06/2028)」

  Rule: 訂閱閘道的強制記憶卡號

    Scenario: 訂閱付款方式不顯示儲存選項
      Given 消費者使用 payuni-credit-subscription 付款
      When 結帳頁面渲染付款方式
      Then 不顯示「儲存付款方式」checkbox（由 payuni.php filter 返回空字串）
      And 系統在背景強制儲存卡號（save_new_card = 'yes'）

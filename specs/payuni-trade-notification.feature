Feature: PayUni 交易通知處理
  As a 商店系統
  I want 正確處理 PayUni 發送的交易通知
  So that 訂單狀態能即時反映實際交易結果

  Background:
    Given PayUni 金流已啟用
    And 商店已設定正確的 Hash Key 和 Hash IV

  Rule: v1 信用卡交易通知

    Scenario: 3D 驗證完成後的前景通知
      Given 消費者已完成 3D 驗證
      When PayUni 透過 ReturnURL redirect 到 wc-api/payuni_notify_card
      And 通知包含 EncryptInfo 參數
      Then 系統使用 AES-256-GCM 解密通知資料
      And 從 MerTradeNo 解析訂單 ID（以 '-' 分割取第一段）
      And 驗證交易 Status = 'SUCCESS'
      And 呼叫 payment_complete() 更新訂單
      And 消費者被 redirect 到訂單完成頁面

    Scenario: 3D 驗證失敗
      When PayUni 3D 驗證通知 Status 非 'SUCCESS'
      Then 訂單備註記錄所有回應資料
      And 訂單狀態更新為 failed
      And 消費者被 redirect 到訂單完成頁面

    Scenario: Hash Request（5 元取 Token）通知
      When PayUni 通知 TradeAmt = '5' 且 AuthType = '1'
      Then 系統識別為 Hash Request
      And 排程 2 分鐘後取消授權（payuni_cancel_trade_by_trade_no）
      And 如果訂單 no_checkout = 'yes'，redirect 到 payment-methods 頁面

    Scenario: WC_Subscription 訂單通知
      Given 訂單類型為 WC_Subscription
      When 交易成功通知到達
      Then 訂單狀態更新為 active（而非 processing）

  Rule: v1 ATM 交易通知

    Scenario: ATM 繳費完成通知
      Given 消費者已完成 ATM 轉帳
      When PayUni 發送背景通知到 wc-api/payuni_notify_atm
      And $_REQUEST['Status'] = 'SUCCESS'
      Then 系統解密通知取得 PayTime 和 Account5No
      And 訂單狀態更新為 processing
      And 訂單備註記錄繳費時間和轉帳後五碼

  Rule: v1 CVS 交易通知

    Scenario: CVS 繳費完成通知
      Given 消費者已完成超商繳費
      When PayUni 發送背景通知到 wc-api/payuni_notify_cvs
      And $_REQUEST['Status'] = 'SUCCESS'
      Then 系統解密通知資料
      And 訂單狀態更新為 processing
      And 訂單備註記錄繳費結果

  Rule: v3 UNi Embed 交易通知

    Scenario: v3 交易成功通知
      When PayUni 發送 POST 通知到 wc-api/payuni_notify
      And 通知包含 EncryptInfo 和 HashInfo
      Then TradeHandler 驗證 HashInfo 完整性
      And 使用 AES-256-GCM 解密取得交易結果
      And 訂單狀態更新為 processing
      And 回應 wp_send_json_success()

    Scenario: v3 Hash 驗證失敗
      When PayUni 通知的 HashInfo 與計算值不匹配
      Then 系統拋出例外「Hash 驗證失敗」
      And 回應 wp_send_json_error()
      And 記錄錯誤日誌

    Scenario: v3 重複通知（冪等性保護）
      Given 訂單已成功付款
      And 訂單 meta _payuni_resp_trade_no = 'TRD123456'
      When PayUni 再次發送相同 TradeNo = 'TRD123456' 的通知
      Then 系統識別為重複通知
      And 記錄日誌「Webhook 重複通知，已略過」
      And 回應 wp_send_json_success()
      And 不重複呼叫 payment_complete()

    Scenario: v3 訂單已付款但需補充 Token
      Given 訂單在 process_payment 中已呼叫 payment_complete
      And 消費者要求記憶卡號
      When 後續 webhook 通知到達（含 CreditHash）
      Then 系統僅更新交易細節 meta
      And 儲存 Payment Token（若尚未存在）
      And 不重複執行付款完成動作

    Scenario: v3 通知找不到訂單
      When PayUni 通知的 MerTradeNo 對應的訂單不存在
      Then 系統拋出例外「找不到訂單: {MerTradeNo}」
      And 回應 wp_send_json_error()

  Rule: 通知端點一覽

    Scenario: PayUni 通知端點對應表
      Then 系統支援以下通知端點：
        | 端點 URL                       | 版本 | 付款方式  | Hook 名稱                        |
        | wc-api/payuni_notify_card      | v1   | 信用卡    | woocommerce_api_payuni_notify_card |
        | wc-api/payuni_notify_atm       | v1   | ATM      | woocommerce_api_payuni_notify_atm  |
        | wc-api/payuni_notify_cvs       | v1   | CVS      | woocommerce_api_payuni_notify_cvs  |
        | wc-api/payuni_notify           | v3   | 信用卡    | woocommerce_api_payuni_notify      |

  Rule: 通知加解密規格

    Scenario: AES-256-GCM 加解密流程
      Given Hash Key 和 Hash IV 已設定
      When 系統收到 EncryptInfo（hex 編碼字串）
      Then 步驟 1: hex2bin 將 hex 轉為 binary
      And 步驟 2: 以 ':::' 分割取得 encrypted_data 和 base64_tag
      And 步驟 3: base64_decode(tag) 取得 GCM auth tag
      And 步驟 4: openssl_decrypt(encrypted_data, 'aes-256-gcm', hash_key, 0, hash_iv, tag)
      And 步驟 5: parse_str 將 URL 編碼字串轉為關聯陣列

    Scenario: SHA-256 Hash 驗證
      When 系統需要驗證 HashInfo
      Then 計算 SHA-256(hash_key + EncryptInfo + hash_iv)
      And 轉為大寫 hex 字串
      And 比對計算結果與收到的 HashInfo

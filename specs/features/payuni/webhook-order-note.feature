@ignore @command
Feature: PayUni v3 Webhook 結果寫入 order_note

  Background:
    Given PayUni v3 信用卡閘道已啟用（payuni-credit-v3）
    And 商店已設定正確的 MerchantID、Hash Key、Hash IV
    And 系統中有以下訂單：
      | orderId | paymentMethod   | status  | totalAmount |
      | 100     | payuni-credit-v3 | pending | 1500        |

  Rule: 後置（狀態）- Webhook 付款成功時訂單備註應包含 "PayUni Webhook: Payment successful"

    Example: Webhook 首次到達且訂單尚未付款後寫入成功備註
      Given 訂單 100 尚未付款
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後 Status = "SUCCESS"，TradeNo = "TRD20260323001"
      Then 操作成功
      And 訂單 100 的 order_note 應包含「PayUni Webhook: Payment successful」

  Rule: 後置（狀態）- 冪等性路徑的 Webhook 到達時訂單備註也應包含 "PayUni Webhook: Payment successful"

    Example: 訂單已由幕後授權完成付款後 Webhook 到達仍寫入成功備註
      Given 訂單 100 已由 process_payment 幕後授權完成付款
      And 訂單 100 的 _payuni_resp_trade_no 為空（幕後授權回應不含 TradeNo）
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後 Status = "SUCCESS"，TradeNo = "TRD20260323001"
      Then 操作成功
      And 訂單 100 的 order_note 應包含「PayUni Webhook: Payment successful」
      And 訂單 100 的 _payuni_resp_trade_no 應更新為 "TRD20260323001"

    Example: 重複 Webhook 通知到達仍寫入成功備註
      Given 訂單 100 已完成付款
      And 訂單 100 的 _payuni_resp_trade_no = "TRD20260323001"
      When PayUni 再次發送相同 TradeNo = "TRD20260323001" 的 webhook 通知
      Then 操作成功
      And 訂單 100 的 order_note 應包含「PayUni Webhook: Payment successful」
      And 不重複呼叫 payment_complete()

  Rule: 後置（狀態）- 幕後授權路徑的 order_note 應為不含交易編號的成功訊息

    Example: 幕後授權成功後寫入不含交易編號的備註
      Given 訂單 100 尚未付款
      When CreditV3Gateway 的 process_payment 幕後授權回應 Status = "SUCCESS"
      And 回應不含 TradeNo 欄位（僅有 Status, Message, URL）
      Then 操作成功
      And 訂單 100 的 order_note 應包含「統一金流 PAYUNi 信用卡付款成功（幕後授權）」
      And 訂單 100 的 order_note 不應包含空的交易編號

  Rule: 前置（狀態）- Webhook 通知必須通過 Hash 驗證

    Example: Hash 驗證失敗時不寫入成功備註
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知的 HashInfo 與計算值不匹配
      Then 操作失敗，錯誤為「Hash 驗證失敗」
      And 訂單 100 的 order_note 不應包含「PayUni Webhook: Payment successful」

  Rule: 前置（參數）- Webhook 通知必須包含 EncryptInfo 和 HashInfo

    Example: 缺少加密資料時不寫入成功備註
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知缺少 EncryptInfo 或 HashInfo
      Then 操作失敗，錯誤為「缺少加密資料」
      And 訂單 100 的 order_note 不應包含「PayUni Webhook: Payment successful」

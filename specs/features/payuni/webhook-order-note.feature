@ignore @command
Feature: PayUni v3 信用卡 Webhook/前景授權 order_note 結構化改善

  Background:
    Given PayUni v3 信用卡閘道已啟用（payuni-credit-v3）
    And 商店已設定正確的 MerchantID、Hash Key、Hash IV
    And 系統中有以下訂單：
      | orderId | paymentMethod    | status  | totalAmount |
      | 100     | payuni-credit-v3 | pending | 1500        |

  # === W1: Webhook 首次成功付款 ===

  Rule: 後置（狀態）- Webhook 首次成功付款時訂單備註應為結構化 HTML 格式

    Example: Webhook 首次到達且訂單尚未付款後寫入結構化成功備註
      Given 訂單 100 尚未付款
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後交易結果為：
        | Status  | Message  | TradeNo         | Card4No | AuthCode | CardInst | FirstAmt | EachAmt |
        | SUCCESS | 付款成功 | TRD20260323001  | 1234    | A12345   | 0        |          |         |
      Then 操作成功
      And 訂單 100 的 order_note 應包含以下 HTML 結構：
        | 欄位       | 值              |
        | 標題       | 統一金流 PAYUNi 交易紀錄（Webhook） |
        | 狀態碼     | SUCCESS         |
        | 交易訊息   | 付款成功        |
        | 交易編號   | TRD20260323001  |
        | 卡號末四碼 | 1234            |
        | 授權碼     | A12345          |

  Rule: 後置（狀態）- 分期交易時備註應額外包含分期欄位

    Example: Webhook 到達且交易含分期資訊後備註包含分期欄位
      Given 訂單 100 尚未付款
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後交易結果為：
        | Status  | Message  | TradeNo         | Card4No | AuthCode | CardInst | FirstAmt | EachAmt |
        | SUCCESS | 付款成功 | TRD20260323001  | 1234    | A12345   | 6        | 300      | 200     |
      Then 操作成功
      And 訂單 100 的 order_note 應額外包含：
        | 欄位       | 值  |
        | 分期期數   | 6   |
        | 首期金額   | 300 |
        | 每期金額   | 200 |

  Rule: 後置（狀態）- 非分期交易時備註不應包含分期欄位

    Example: CardInst 為 0 時備註不含分期欄位
      Given 訂單 100 尚未付款
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後交易結果為：
        | Status  | Message  | TradeNo         | Card4No | AuthCode | CardInst | FirstAmt | EachAmt |
        | SUCCESS | 付款成功 | TRD20260323001  | 1234    | A12345   | 0        |          |         |
      Then 操作成功
      And 訂單 100 的 order_note 不應包含「分期期數」
      And 訂單 100 的 order_note 不應包含「首期金額」
      And 訂單 100 的 order_note 不應包含「每期金額」

  # === W2: 前景授權成功 ===

  Rule: 後置（狀態）- 前景授權成功時備註標題應為「前景授權」

    Example: process_payment 前景授權成功後寫入結構化備註
      Given 訂單 100 尚未付款
      When CreditV3Gateway 的 process_payment 前景授權回應交易結果為：
        | Status  | Message  | TradeNo         | Card4No | AuthCode | CardInst | FirstAmt | EachAmt |
        | SUCCESS | 付款成功 | TRD20260323001  | 1234    | A12345   | 0        |          |         |
      Then 操作成功
      And 訂單 100 的 order_note 應包含以下 HTML 結構：
        | 欄位       | 值              |
        | 標題       | 統一金流 PAYUNi 交易紀錄（前景授權） |
        | 狀態碼     | SUCCESS         |
        | 交易訊息   | 付款成功        |
        | 交易編號   | TRD20260323001  |
        | 卡號末四碼 | 1234            |
        | 授權碼     | A12345          |

  # === W3: 冪等路徑 B（訂單已付款，Webhook 補傳） ===

  Rule: 後置（狀態）- 訂單已付款時 Webhook 仍應寫入完整結構化備註

    Example: 訂單已由前景授權完成付款後 Webhook 到達仍寫入完整備註
      Given 訂單 100 已由 process_payment 前景授權完成付款
      And 訂單 100 的 _payuni_resp_trade_no 為空
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後交易結果為：
        | Status  | Message  | TradeNo         | Card4No | AuthCode | CardInst | FirstAmt | EachAmt |
        | SUCCESS | 付款成功 | TRD20260323001  | 1234    | A12345   | 0        |          |         |
      Then 操作成功
      And 訂單 100 的 order_note 應包含以下 HTML 結構：
        | 欄位       | 值              |
        | 標題       | 統一金流 PAYUNi 交易紀錄（Webhook） |
        | 狀態碼     | SUCCESS         |
        | 交易訊息   | 付款成功        |
        | 交易編號   | TRD20260323001  |
        | 卡號末四碼 | 1234            |
        | 授權碼     | A12345          |
      And 訂單 100 的 _payuni_resp_trade_no 應更新為 "TRD20260323001"
      And 不重複呼叫 payment_complete()

  # === W4: 重複通知冪等路徑 A（Bootstrap） ===

  Rule: 後置（狀態）- 重複通知時備註應為簡單標記不帶交易細節

    Example: 相同 TradeNo 的 Webhook 重複到達時寫入簡單標記
      Given 訂單 100 已完成付款
      And 訂單 100 的 _payuni_resp_trade_no = "TRD20260323001"
      When PayUni 再次發送相同 TradeNo = "TRD20260323001" 的 webhook 通知
      Then 操作成功
      And 訂單 100 的 order_note 應為「PayUni Webhook：重複通知，已略過」
      And 訂單 100 的 order_note 不應包含「<strong>」
      And 不重複呼叫 payment_complete()

  # === W5: 付款失敗 ===

  Rule: 後置（狀態）- 付款失敗時備註應為結構化 HTML 格式

    Example: Webhook 付款失敗後寫入結構化失敗備註
      Given 訂單 100 尚未付款
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後交易結果為：
        | Status | Message  | TradeNo         | Card4No | AuthCode | CardInst | FirstAmt | EachAmt |
        | FAILED | 餘額不足 | TRD20260323002  | 5678    |          | 0        |          |         |
      Then 操作失敗
      And 訂單 100 的狀態應變更為 "failed"
      And 訂單 100 的 order_note 應包含以下 HTML 結構：
        | 欄位       | 值              |
        | 標題       | 統一金流 PAYUNi 信用卡付款失敗 |
        | 狀態碼     | FAILED          |
        | 交易訊息   | 餘額不足        |
        | 交易編號   | TRD20260323002  |
        | 卡號末四碼 | 5678            |
        | 授權碼     |                 |

  # === 前置條件 ===

  Rule: 前置（狀態）- Webhook 通知必須通過 Hash 驗證

    Example: Hash 驗證失敗時不寫入任何備註
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知的 HashInfo 與計算值不匹配
      Then 操作失敗，錯誤為「Hash 驗證失敗」
      And 訂單 100 的 order_note 不應包含「統一金流」

  Rule: 前置（參數）- Webhook 通知必須包含 EncryptInfo 和 HashInfo

    Example: 缺少加密資料時不寫入任何備註
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知缺少 EncryptInfo 或 HashInfo
      Then 操作失敗，錯誤為「缺少加密資料」
      And 訂單 100 的 order_note 不應包含「統一金流」

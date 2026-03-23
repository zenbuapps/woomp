@ignore @command
Feature: PayUni v3 order_note 重構 -- LABEL_MAP 驅動全欄位輸出

  將 build_order_note_html() 從硬編碼欄位清單改為 LABEL_MAP 驅動，
  印出 $trade_result 全部欄位（已知 key 顯示中文 label，未知 key 顯示原始 key name），
  空值欄位不顯示，分期欄位群組條件保留。

  Background:
    Given PayUni v3 信用卡閘道已啟用（payuni-credit-v3）
    And 商店已設定正確的 MerchantID、Hash Key、Hash IV
    And 系統中有以下訂單：
      | orderId | paymentMethod    | status  | totalAmount |
      | 100     | payuni-credit-v3 | pending | 1500        |

  # === 顯示順序：LABEL_MAP 定義順序優先，未知 key 按原始順序附加 ===

  Rule: 後置（狀態）- 已知欄位應按 LABEL_MAP 定義順序顯示中文 label

    Example: Webhook 成功且所有已知欄位皆有值時按 LABEL_MAP 順序完整輸出
      Given 訂單 100 尚未付款
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後交易結果為：
        | Key           | Value           |
        | Status        | SUCCESS         |
        | Message       | 付款成功        |
        | MerID         | M12345678       |
        | MerTradeNo    | 202603230001    |
        | Gateway       | credit          |
        | TradeNo       | TRD20260323001  |
        | TradeAmt      | 1500            |
        | TradeStatus   | 1               |
        | PaymentType   | CREDIT          |
        | CardBank      | 812             |
        | Card6No       | 411111          |
        | Card4No       | 1234            |
        | CardInst      | 0               |
        | ResCode       | 00              |
        | ResCodeMsg    | 交易成功        |
        | AuthCode      | A12345          |
        | AuthBank      | 812             |
        | AuthBankName  | 台新銀行        |
        | AuthType      | 1               |
        | AuthDay       | 20260323        |
        | AuthTime      | 143022          |
      Then 操作成功
      And 訂單 100 的 order_note 應包含以下結構（按此順序）：
        | 順序 | label          | 值              |
        | 標題 | 統一金流 PAYUNi 交易紀錄（Webhook） | |
        | 1    | 狀態碼         | SUCCESS         |
        | 2    | 交易訊息       | 付款成功        |
        | 3    | 商店代號       | M12345678       |
        | 4    | 商店訂單編號   | 202603230001    |
        | 5    | 付款方式       | credit          |
        | 6    | 交易編號       | TRD20260323001  |
        | 7    | 交易金額       | 1500            |
        | 8    | 交易狀態       | 1               |
        | 9    | 付款類型       | CREDIT          |
        | 10   | 發卡銀行代碼   | 812             |
        | 11   | 卡號前六碼     | 411111          |
        | 12   | 卡號末四碼     | 1234            |
        | 13   | 回應碼         | 00              |
        | 14   | 回應訊息       | 交易成功        |
        | 15   | 授權碼         | A12345          |
        | 16   | 收單銀行代碼   | 812             |
        | 17   | 收單銀行名稱   | 台新銀行        |
        | 18   | 授權類型       | 1               |
        | 19   | 授權日期       | 20260323        |
        | 20   | 授權時間       | 143022          |
      And 訂單 100 的 order_note 不應包含「分期期數」
      And 訂單 100 的 order_note 不應包含「首期金額」
      And 訂單 100 的 order_note 不應包含「每期金額」

  # === 空值過濾：isset && !== '' ===

  Rule: 後置（狀態）- 空值欄位不應顯示

    Example: 部分已知欄位為空字串時該欄位不出現在備註中
      Given 訂單 100 尚未付款
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後交易結果為：
        | Key           | Value           |
        | Status        | SUCCESS         |
        | Message       | 付款成功        |
        | MerID         | M12345678       |
        | MerTradeNo    | 202603230001    |
        | Gateway       | credit          |
        | TradeNo       | TRD20260323001  |
        | TradeAmt      | 1500            |
        | TradeStatus   | 1               |
        | PaymentType   | CREDIT          |
        | CardBank      |                 |
        | Card6No       |                 |
        | Card4No       | 1234            |
        | CardInst      | 0               |
        | ResCode       | 00              |
        | ResCodeMsg    | 交易成功        |
        | AuthCode      | A12345          |
        | AuthBank      |                 |
        | AuthBankName  |                 |
        | AuthType      | 1               |
        | AuthDay       | 20260323        |
        | AuthTime      | 143022          |
      Then 操作成功
      And 訂單 100 的 order_note 不應包含「發卡銀行代碼」
      And 訂單 100 的 order_note 不應包含「卡號前六碼」
      And 訂單 100 的 order_note 不應包含「收單銀行代碼」
      And 訂單 100 的 order_note 不應包含「收單銀行名稱」
      And 訂單 100 的 order_note 應包含「卡號末四碼：1234」

  # === 分期欄位群組條件：CardInst > 1 才顯示三個分期欄位 ===

  Rule: 後置（狀態）- 分期交易時備註應包含分期群組欄位

    Example: CardInst 大於 1 時顯示分期期數、首期金額、每期金額
      Given 訂單 100 尚未付款
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後交易結果為：
        | Key           | Value           |
        | Status        | SUCCESS         |
        | Message       | 付款成功        |
        | TradeNo       | TRD20260323001  |
        | Card4No       | 1234            |
        | AuthCode      | A12345          |
        | CardInst      | 6               |
        | FirstAmt      | 300             |
        | EachAmt       | 200             |
      Then 操作成功
      And 訂單 100 的 order_note 應包含：
        | label    | 值  |
        | 分期期數 | 6   |
        | 首期金額 | 300 |
        | 每期金額 | 200 |

  Rule: 後置（狀態）- 非分期交易時不顯示分期群組欄位

    Example: CardInst 為 0 時不顯示分期相關欄位
      Given 訂單 100 尚未付款
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後交易結果為：
        | Key           | Value           |
        | Status        | SUCCESS         |
        | Message       | 付款成功        |
        | TradeNo       | TRD20260323001  |
        | Card4No       | 1234            |
        | AuthCode      | A12345          |
        | CardInst      | 0               |
        | FirstAmt      |                 |
        | EachAmt       |                 |
      Then 操作成功
      And 訂單 100 的 order_note 不應包含「分期期數」
      And 訂單 100 的 order_note 不應包含「首期金額」
      And 訂單 100 的 order_note 不應包含「每期金額」

    Example: CardInst 為 1（一次付清）時不顯示分期相關欄位
      Given 訂單 100 尚未付款
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後交易結果為：
        | Key           | Value           |
        | Status        | SUCCESS         |
        | Message       | 付款成功        |
        | TradeNo       | TRD20260323001  |
        | Card4No       | 1234            |
        | AuthCode      | A12345          |
        | CardInst      | 1               |
        | FirstAmt      | 1500            |
        | EachAmt       | 0               |
      Then 操作成功
      And 訂單 100 的 order_note 不應包含「分期期數」
      And 訂單 100 的 order_note 不應包含「首期金額」
      And 訂單 100 的 order_note 不應包含「每期金額」

  # === 未知 key 處理 ===

  Rule: 後置（狀態）- 不在 LABEL_MAP 中的未知 key 應以原始 key name 顯示並排在已知欄位之後

    Example: PayUni 回傳新增的未知欄位時以原始 key name 附加於已知欄位之後
      Given 訂單 100 尚未付款
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後交易結果為：
        | Key            | Value           |
        | Status         | SUCCESS         |
        | Message        | 付款成功        |
        | TradeNo        | TRD20260323001  |
        | Card4No        | 1234            |
        | AuthCode       | A12345          |
        | CardInst       | 0               |
        | NewField1      | abc123          |
        | AnotherNewKey  | xyz789          |
      Then 操作成功
      And 訂單 100 的 order_note 在所有已知中文 label 欄位之後應包含：
        | label         | 值     |
        | NewField1     | abc123 |
        | AnotherNewKey | xyz789 |

    Example: 未知欄位為空值時不顯示
      Given 訂單 100 尚未付款
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後交易結果為：
        | Key            | Value           |
        | Status         | SUCCESS         |
        | Message        | 付款成功        |
        | TradeNo        | TRD20260323001  |
        | Card4No        | 1234            |
        | AuthCode       | A12345          |
        | CardInst       | 0               |
        | NewField1      |                 |
        | AnotherNewKey  | xyz789          |
      Then 操作成功
      And 訂單 100 的 order_note 不應包含「NewField1」
      And 訂單 100 的 order_note 應包含「AnotherNewKey：xyz789」

  # === 全欄位不排除（Q6 決策 B） ===

  Rule: 後置（狀態）- 所有有值欄位皆應顯示不排除任何欄位

    Example: CreditHash 與 CoBrandCode 等可選欄位有值時也應顯示
      Given 訂單 100 尚未付款
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後交易結果為：
        | Key           | Value                            |
        | Status        | SUCCESS                          |
        | Message       | 付款成功                         |
        | TradeNo       | TRD20260323001                   |
        | Card4No       | 1234                             |
        | AuthCode      | A12345                           |
        | CardInst      | 0                                |
        | CreditHash    | a1b2c3d4e5f6                     |
        | CreditLife    | 0328                             |
        | CoBrandCode   | V                                |
      Then 操作成功
      And 訂單 100 的 order_note 應包含：
        | label        | 值               |
        | 信用卡 Hash  | a1b2c3d4e5f6     |
        | 有效期限     | 0328             |
        | 聯名卡代碼   | V                |

  # === 呼叫處不變 ===

  Rule: 後置（狀態）- build_order_note_html 的 4 個呼叫處簽名與標題不變

    Example: W1 Webhook 首次成功使用「交易紀錄（Webhook）」標題
      Given 訂單 100 尚未付款
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後交易結果 Status 為 "SUCCESS"
      Then 訂單 100 的 order_note 標題應為「統一金流 PAYUNi 交易紀錄（Webhook）」

    Example: W2 前景授權成功使用「交易紀錄（前景授權）」標題
      Given 訂單 100 尚未付款
      When CreditV3Gateway 的 process_payment 前景授權回應 Status 為 "SUCCESS"
      Then 訂單 100 的 order_note 標題應為「統一金流 PAYUNi 交易紀錄（前景授權）」

    Example: W5 付款失敗使用「信用卡付款失敗」標題
      Given 訂單 100 尚未付款
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後交易結果 Status 為 "FAILED"
      Then 訂單 100 的 order_note 標題應為「統一金流 PAYUNi 信用卡付款失敗」

  # === 前景授權完整輸出範例 ===

  Rule: 後置（狀態）- 前景授權路徑應與 Webhook 路徑使用相同 LABEL_MAP 邏輯

    Example: 前景授權成功且含分期時完整輸出所有有值欄位
      Given 訂單 100 尚未付款
      When CreditV3Gateway 的 process_payment 前景授權回應交易結果為：
        | Key           | Value           |
        | Status        | SUCCESS         |
        | Message       | 付款成功        |
        | MerID         | M12345678       |
        | MerTradeNo    | 202603230001    |
        | Gateway       | credit          |
        | TradeNo       |                 |
        | TradeAmt      | 1500            |
        | TradeStatus   | 1               |
        | PaymentType   | CREDIT          |
        | CardBank      | 812             |
        | Card6No       | 411111          |
        | Card4No       | 1234            |
        | CardInst      | 3               |
        | FirstAmt      | 500             |
        | EachAmt       | 500             |
        | ResCode       | 00              |
        | ResCodeMsg    | 交易成功        |
        | AuthCode      | B67890          |
        | AuthBank      | 812             |
        | AuthBankName  | 台新銀行        |
        | AuthType      | 1               |
        | AuthDay       | 20260323        |
        | AuthTime      | 150000          |
      Then 操作成功
      And 訂單 100 的 order_note 標題應為「統一金流 PAYUNi 交易紀錄（前景授權）」
      And 訂單 100 的 order_note 不應包含「交易編號」
      And 訂單 100 的 order_note 應包含：
        | label    | 值  |
        | 分期期數 | 3   |
        | 首期金額 | 500 |
        | 每期金額 | 500 |

  # === 失敗路徑完整輸出範例 ===

  Rule: 後置（狀態）- 付款失敗時同樣以 LABEL_MAP 邏輯輸出所有有值欄位

    Example: 付款失敗時輸出所有有值的已知與未知欄位
      Given 訂單 100 尚未付款
      When PayUni 發送 v3 webhook 通知到 wc-api/payuni_notify
      And 通知解密後交易結果為：
        | Key           | Value           |
        | Status        | FAILED          |
        | Message       | 餘額不足        |
        | MerID         | M12345678       |
        | MerTradeNo    | 202603230001    |
        | Gateway       | credit          |
        | TradeNo       | TRD20260323002  |
        | TradeAmt      | 1500            |
        | TradeStatus   | 0               |
        | PaymentType   | CREDIT          |
        | CardBank      |                 |
        | Card6No       |                 |
        | Card4No       | 5678            |
        | CardInst      | 0               |
        | ResCode       | 51              |
        | ResCodeMsg    | 餘額不足        |
        | AuthCode      |                 |
        | AuthBank      |                 |
        | AuthBankName  |                 |
        | AuthType      |                 |
        | AuthDay       |                 |
        | AuthTime      |                 |
      Then 操作失敗
      And 訂單 100 的狀態應變更為 "failed"
      And 訂單 100 的 order_note 標題應為「統一金流 PAYUNi 信用卡付款失敗」
      And 訂單 100 的 order_note 應包含「狀態碼：FAILED」
      And 訂單 100 的 order_note 應包含「交易訊息：餘額不足」
      And 訂單 100 的 order_note 應包含「交易編號：TRD20260323002」
      And 訂單 100 的 order_note 應包含「卡號末四碼：5678」
      And 訂單 100 的 order_note 應包含「回應碼：51」
      And 訂單 100 的 order_note 應包含「回應訊息：餘額不足」
      And 訂單 100 的 order_note 不應包含「發卡銀行代碼」
      And 訂單 100 的 order_note 不應包含「卡號前六碼」
      And 訂單 100 的 order_note 不應包含「授權碼」
      And 訂單 100 的 order_note 不應包含「分期期數」

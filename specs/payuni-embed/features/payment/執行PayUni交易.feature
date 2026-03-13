@ignore @command
Feature: 執行 PayUni 交易

  系統將訂單資料加密後呼叫 PayUni /iframe/merchant_trade API 執行信用卡交易。
  加密方式為 AES-256-GCM，簽章為 SHA256 HMAC。

  Background:
    Given 系統中有以下金流設定：
      | merchant_id | hash_key         | hash_iv         | mode |
      | ABC123      | test_hash_key_32 | test_hash_iv_16 | TEST |
    And 系統中有以下訂單：
      | orderId | userId | total | email          | status |
      | 1001    | 1      | 500   | alice@test.com | 待付款  |

  Rule: 前置（狀態）- 訂單必須有有效的暫存付款資料

    Example: 訂單缺少暫存付款資料時操作失敗
      Given 訂單 1001 無暫存付款資料
      When 系統對訂單 1001 執行 PayUni 交易
      Then 操作失敗，錯誤為「缺少付款暫存資料」

  Rule: 後置（狀態）- 交易參數應以 AES-256-GCM 加密並以 SHA256 HMAC 簽章

    Example: 交易參數正確加密並送出
      When 系統對訂單 1001 執行 PayUni 交易
      Then 系統應發送 POST 請求至 "https://sandbox-api.payuni.com.tw/api/iframe/merchant_trade"
      And 請求內容應包含：
        | MerID  | Version | EncryptInfo | HashInfo |
        | ABC123 | 1.0     | (加密字串)   | (簽章字串) |

  Rule: 後置（狀態）- 加密前的交易參數應包含完整欄位

    Example: 新卡片交易的加密前參數
      Given 訂單 1001 的暫存資料：sdk_token "token_abc"，分期期數 6，3D驗證啟用
      When 系統組裝訂單 1001 的交易參數
      Then 加密前參數應包含：
        | MerID  | MerTradeNo | Token     | TradeAmt | API3D | CardInst |
        | ABC123 | 1001       | token_abc | 500      | 1     | 6        |

    Example: 已儲存卡片交易的加密前參數應包含 CreditToken 和 UseTokenType
      Given 訂單 1001 使用已儲存卡片，CreditHash 為 "hash_abc123"
      When 系統組裝訂單 1001 的交易參數
      Then 加密前參數應包含：
        | UseTokenType | CreditToken    |
        | 2            | alice@test.com |

  Rule: 後置（狀態）- 載具資訊應包含在交易參數中

    Example: 手機條碼載具的交易參數
      Given 訂單 1001 選擇手機條碼載具 "/ABC1234"，買方名稱 "Alice Chen"
      When 系統組裝訂單 1001 的交易參數
      Then 加密前參數應包含：
        | CarrierType | CarrierInfo | InvBuyerName |
        | 3J0002      | /ABC1234    | Alice Chen   |

  Rule: 後置（回應）- 非 3D 交易應同步回傳完整授權結果

    Example: 非 3D 直接授權回應包含完整交易結果
      Given 訂單 1001 的交易參數不含 API3D
      When 系統對訂單 1001 執行 PayUni 交易
      Then PayUni 回應的 EncryptInfo 解密後應包含：
        | Status  | Message      | TradeNo         | MerTradeNo | TradeAmt | TradeStatus | Card4No |
        | SUCCESS | 信用卡授權成功 | PAY202601010001 | 1001       | 500      | 1           | 1234    |

    Example: 非 3D 交易逾時回傳 UNKNOWN 後由 Webhook 補送結果
      Given 銀行回應超過 60 秒
      When 系統對訂單 1001 執行 PayUni 交易
      Then PayUni 回應的 EncryptInfo 解密後 Status 應為 "UNKNOWN"
      And 後續交易結果由 Webhook (NotifyURL) 補送

  Rule: 後置（回應）- 3D 交易應回傳 3D 驗證導頁 URL

    Example: 3D 交易回傳 3D 驗證 URL（非完整交易結果）
      Given 訂單 1001 的交易參數含 API3D=1
      When 系統對訂單 1001 執行 PayUni 交易
      Then PayUni 回應的 EncryptInfo 解密後應包含：
        | Status  | Message      | URL                    |
        | SUCCESS | 建立幕後3D成功 | (3D 驗證導頁網址)       |
      And 回應不包含 TradeNo 和 TradeStatus 等授權結果欄位
      And 最終交易結果由 Webhook (NotifyURL) 回傳

@ignore @command
Feature: 解密驗證交易回應

  系統解密 PayUni 直接授權回應的 EncryptInfo，驗證 HashInfo 完整性，取得交易結果。

  Background:
    Given 系統中有以下金流設定：
      | merchant_id | hash_key         | hash_iv         |
      | ABC123      | test_hash_key_32 | test_hash_iv_16 |

  Rule: 前置（參數）- 回應必須包含 EncryptInfo 和 HashInfo

    Example: 回應缺少 EncryptInfo 時操作失敗
      Given PayUni 回應不含 EncryptInfo
      When 系統解密驗證交易回應
      Then 操作失敗，錯誤為「回應缺少加密資訊」

  Rule: 前置（參數）- HashInfo 驗證必須通過

    Example: HashInfo 驗證失敗時操作失敗
      Given PayUni 回應的 HashInfo 與計算值不符
      When 系統解密驗證交易回應
      Then 操作失敗，錯誤為「回應簽章驗證失敗」

  Rule: 後置（回應）- 解密後應取得完整交易結果

    Example: 成功解密交易回應
      Given PayUni 回應包含有效的 EncryptInfo 和 HashInfo
      When 系統解密驗證交易回應
      Then 操作成功
      And 解密後交易結果應包含：
        | Status  | Message | TradeNo         | MerTradeNo | TradeAmt | Card4No |
        | SUCCESS | 交易成功 | PAY202601010001 | 1001       | 500      | 1234    |

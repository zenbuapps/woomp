@ignore @command
Feature: V3 續扣成功處理

  Background:
    Given PayUni 金流已啟用
    And payuni-credit-subscription-v3 閘道已啟用
    And WooCommerce Subscriptions 排程觸發定期扣款
    And PayUni API 回傳成功

  Rule: 後置（狀態）- 續扣成功時訂單應標記為已付款

    Example: 續扣成功後訂單完成
      Given 訂閱每月扣款 500 元
      When PayUni 回傳 Status = 'SUCCESS' 且 TradeNo = 'P20260325001'
      Then 系統呼叫 $order->payment_complete('P20260325001')
      And 訂單備註記錄交易成功資訊
      And 訂閱狀態維持 active

  Rule: 後置（狀態）- 續扣成功時應儲存交易回應 meta

    Example: 續扣回應 meta 正確儲存
      Given 訂閱每月扣款 500 元
      When PayUni 回傳 Status = 'SUCCESS' 且 TradeNo = 'P20260325001'
      Then 訂單 meta 應包含：
        | meta_key | meta_value |
        | _payuni_resp_status | SUCCESS |
        | _payuni_resp_trade_no | P20260325001 |
      And 訂單 meta 儲存 _payuni_v3_resp（完整解密陣列）

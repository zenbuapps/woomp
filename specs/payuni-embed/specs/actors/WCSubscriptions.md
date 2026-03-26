# WCSubscriptions

## 描述
WooCommerce Subscriptions 外掛，負責管理訂閱商品的生命週期、排程扣款觸發、訂閱狀態轉換。

## 關鍵屬性
- 排程引擎：使用 Action Scheduler 管理定期扣款排程
- Hook 機制：透過 `woocommerce_scheduled_subscription_payment_{gateway_id}` 觸發特定閘道的續扣
- 失敗處理：透過 `woocommerce_subscription_renewal_payment_failed` 通知閘道處理失敗
- 狀態管理：active, on-hold, cancelled, expired, pending-cancel

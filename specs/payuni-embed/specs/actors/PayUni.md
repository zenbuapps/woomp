# PayUni

## 描述
統一金流 PAYUNi 外部支付服務。提供 UNi Embed SDK（前端 iframe）和 REST API（後端交易處理）。

## 關鍵屬性
- SDK：UNi Embed iframe，處理卡片輸入、3D 驗證挑戰頁面、Token 綁定事件
- API 端點：
  - `/iframe/token_get` — 取得 SDK Token
  - `/iframe/merchant_trade` — 執行信用卡交易
  - `/api/trade/close` — 退費/關帳
- 環境：
  - TEST: `https://sandbox-api.payuni.com.tw/api`
  - PROD: `https://api.payuni.com.tw/api`
- Webhook：POST 至商店的 `/wc-api/payuni_notify` 發送非同步交易結果
- 加密協議：AES-256-GCM（EncryptInfo）+ SHA256 HMAC（HashInfo）

# 信用卡記憶卡號（Tokenization）

> 來源：`v3/Shared/Enums/EUseTokenType.php`、`v3/Infrastructure/Http/TradeHandler.php`、`src/gateways/CreditV3.php`
> 官方文件：https://docs.payuni.com.tw/web/#/7/156

## UseTokenType 枚舉

```php
enum EUseTokenType:int {
    case OPTIONAL_BIND  = 1;  // 約定信用卡，消費者可自行取消約定
    case REMEMBER_CARD  = 2;  // 記憶卡號（預設），記憶卡號+到期日
    case FORCE_BIND     = 3;  // 強制約定信用卡，消費者無法取消
}
```

本專案使用 `REMEMBER_CARD (2)` 作為預設模式。

---

## 首次綁卡流程

### 1. 取得 SDK Token（含 Token 參數）

```php
// HttpClient::get_sdk_token() 中的加密參數
$encryptInfo = [
    'MerID'           => $merchant_id,
    'Timestamp'       => time(),
    'IFrameDomain'    => site_url(),
    'UseTokenType'    => 2,                // 記憶卡號模式
    'CreditToken'     => $user_email,      // 用戶 email 作為識別碼
    'CreditTokenType' => 1                 // 1=會員級（跨商店共用）
];
```

### 2. 前端 SDK 顯示記憶勾選框

當 `UseTokenType` 設定時，SDK 在 `#put_token_type` 容器渲染勾選框。

### 3. 使用者完成交易

前端提交時附加 `payuni_save_card: true`。

### 4. 交易回應包含 Token 資訊

```php
// TradeHandler 回應中的 Token 欄位
[
    'CreditHash' => 'abc123...',    // 信用卡 Token Hash
    'CreditLife' => '1228',          // 卡片到期日 MMYY
    'Card6No'    => '414763',        // 卡號前 6 碼
    'Card4No'    => '0001',          // 卡號末 4 碼
]
```

### 5. 儲存為 WC Payment Token

```php
// TradeHandler::update_order_status() 中的 Token 儲存邏輯
$token = new \WC_Payment_Token_CC();
$token->set_gateway_id('payuni-credit-v3');
$token->set_token($credit_hash);           // CreditHash 或 fallback
$token->set_last4($card4no);
$token->set_card_type($card_type);          // visa/mastercard/amex/discover
$token->set_expiry_month($exp_month);       // 從 CreditLife 解析
$token->set_expiry_year($exp_year);         // 從 CreditLife 解析（2000+YY）
$token->set_user_id($user_id);
$token->save();
```

**卡片類型偵測**（依 Card6No 首碼）：

| 首碼 | 卡別 |
|------|------|
| 4 | Visa |
| 5 | Mastercard |
| 3 | American Express |
| 6 | Discover |

**到期日解析**（CreditLife 格式 MMYY）：
- 月份：前 2 碼
- 年份：`2000 + 後 2 碼`
- Fallback（無 CreditLife）：`12/2099`

**Token 去重**：儲存前檢查是否已存在相同 token 值，避免重複儲存。

---

## 使用已儲存卡片流程

### 1. 前端選擇已儲存卡片

```html
<!-- CreditV3.php payment_fields() 渲染 -->
<input type="radio" name="payuni_used_token_id" value="{token_id}">
**** **** **** {last4} (到期: {month}/{year})
```

### 2. 前端呼叫 SDK

```javascript
const result = await sdk.getTradeResult({ useDefault: true });
```

### 3. 提交表單

```javascript
{
    payuni_use_saved_token: '1',
    payuni_saved_token_id: token_id,
    payuni_installment: installment_period,
    // + carrier data
}
```

### 4. 後端處理

`CreditV3::process_payment()` 偵測 `payuni_use_saved_token === '1'`，使用對應的 Token 進行交易。

---

## CreditToken 欄位說明

| 欄位 | 說明 | 格式限制 |
|------|------|---------|
| CreditToken | 信用卡 Token 識別碼 | 長度 ≤ 150，`[A-Za-z0-9@.#$%_-]` |
| CreditHash | 交易回傳的 Token Hash | 唯一識別已綁定卡片 |
| CreditLife | 卡片到期日 | MMYY 格式（如 `1228`） |
| CreditTokenType | Token 紀錄類型 | 1=會員（跨商店），2=商店（單商店） |

---

## 前端 Token 類型常數

```javascript
// constants.module.js
const TOKEN_TYPE = {
    NONE: 0,             // 不使用
    REMEMBER_CARD: 1,    // 記憶卡號（前端定義，對應後端 UseTokenType=2）
    SUBSCRIPTION_CARD: 2 // 約定扣款
};
```

> **注意**：前端 `TOKEN_TYPE.REMEMBER_CARD = 1` 與後端 `EUseTokenType::REMEMBER_CARD = 2` 數值不同，前端在呼叫 `getTradeResult` 時會轉換。

---

## 啟用條件

信用卡記憶功能需滿足：

1. WooCommerce 設定 `woocommerce_payuni-credit-v3_settings[enable_tokenization]` = `yes`
2. 使用者已登入（Guest 無法儲存卡片）
3. PAYUNi 後台已開通信用卡記憶功能

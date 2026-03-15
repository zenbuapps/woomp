# PAYUNi SDK 前端整合

> 來源：`includes/payuni/v3/Applications/assets/js/*.module.js`、`includes/payuni/v3/Bootstrap.php`

## SDK CDN

| 環境 | URL |
|------|-----|
| Sandbox | `https://sandbox-vendor.payuni.com.tw/sdk/uni-payment.js` |
| Production | `https://vendor.payuni.com.tw/sdk/uni-payment.js` |

載入方式：`<script type="text/javascript" src="{SDK_URL}"></script>`

全域物件：`window.UniPayment`

---

## SDK 初始化

### createSession

```javascript
const sdk = UniPayment.createSession(SDK_TOKEN, {
    // iframe 樣式
    style: {
        color: '#000000',
        errorColor: '#FF0000',
        fontSize: '14px',
        fontWeight: '400',
        lineHeight: '24px'
    }
});
```

`SDK_TOKEN` 由後端透過 `/iframe/token_get` 取得，有效期 10 分鐘。

### start()

```javascript
await sdk.start();  // 初始化 iframe，渲染卡號輸入欄位
```

---

## iframe 容器 HTML

SDK 會將 iframe 渲染到以下 DOM 容器中：

```html
<div id="put_card_no"></div>    <!-- 卡號 -->
<div id="put_card_exp"></div>   <!-- 到期日 -->
<div id="put_card_cvc"></div>   <!-- 安全碼 -->
<div id="put_token_type"></div> <!-- 記憶卡號勾選框（Tokenization 啟用時） -->
```

對應常數定義：

```javascript
const IFRAME_ELEMENTS = {
    CardNo: 'put_card_no',
    CardExp: 'put_card_exp',
    CardCvc: 'put_card_cvc',
    CardTokenType: 'put_token_type'
};
```

---

## SDK 事件

### onUpdate — 表單狀態變更

```javascript
sdk.onUpdate((state) => {
    // state.status: { CardNo: bool|null, CardExp: bool|null, CardCvc: bool|null }
    // state.event: 'statusUpdate' | 'useTokenType' | 'loaded'
    // state.data: 事件附帶資料（僅特定事件）
});
```

#### statusUpdate 事件

回報每個欄位的驗證狀態：
- `null` — 尚未互動
- `true` — 驗證通過
- `false` — 驗證失敗

#### useTokenType 事件

使用者勾選/取消記憶卡號時觸發：

```javascript
{
    event: 'useTokenType',
    data: {
        cardNo: '414712******2716',  // 遮蔽的卡號（或 null）
        tokenTypeText: '記憶信用卡',  // 顯示文字
        tokenType: '2'               // '1'=約定, '2'=記憶, '3'=強制約定
    }
}
```

#### loaded 事件

iframe 載入完成時觸發。

### getTradeResult — 取得加密交易資料

```javascript
const result = await sdk.getTradeResult({
    cardInst: 1,          // 分期期數（1=一次付清）
    useDefault: false,    // true=使用已儲存卡片
    useTokenType: 2       // Token 類型（選填）
});

// result: { Status, EncryptInfo, HashInfo, MerID, Version }
```

---

## `window.payuni_payment_v3_checkout_params`

由 `Bootstrap.php` 透過 `wp_localize_script` 注入的前端參數：

```javascript
{
    ENV: 'P' | 'S',                    // P=正式, S=沙箱
    SDK_TOKEN: 'token_string',         // SDK Token
    USE_INST: true | false,            // 是否啟用分期
    ENABLE_3D_AUTH: true | false,      // 是否啟用 3D 驗證
    INST_OPTIONS: [3, 6, 12],          // 可用分期期數
    ENABLE_TOKENIZATION: true | false, // 是否啟用信用卡記憶
    ERROR_MAPPER: { ... }              // 錯誤代碼對照表
}
```

---

## 前端 Checkout 流程

### 新卡片付款

```
1. 驗證 SDK 已就緒 (FormState.isReady())
2. 驗證所有欄位通過 (FormState.isAllValid())
3. sdk.getTradeResult({ cardInst, useTokenType })
4. 提交 WC checkout 表單，附加：
   - sdk_token_tmp: SDK Token
   - payuni_save_card: 是否記憶卡號
   - payuni_installment: 分期期數
   - payuni_carrier_type / carrier_info / inv_buyer_name
5. 後端回應 { EncryptInfo, HashInfo, MerID, Version, ApiUrl }
6. 前端 POST 至 ApiUrl（merchant_trade）
7. 收到 SUCCESS → 重導向至 order-received
```

### 已儲存卡片付款

```
1. sdk.getTradeResult({ useDefault: true })
2. 提交 WC checkout 表單，附加：
   - payuni_use_saved_token: '1'
   - payuni_saved_token_id: Token ID
   - payuni_installment / carrier data
3. 後端處理交易
4. 重導向至 order-received
```

---

## 關鍵 WooCommerce Selectors

```javascript
const WC_SELECTORS = {
    CHECKOUT_FORM: 'form.checkout',
    PLACE_ORDER_BTN: '#place_order',
    PAYUNI_CREDIT_V3: '#payment_method_payuni-credit-v3',
    PAYMENT_METHODS: 'input[type="radio"][name="payment_method"]',
    NOTICE_GROUP: '.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout',
    TOKEN_TYPE_CHECKBOX_AREA: '#token_type_checkbox_area',
    TOKEN_TYPE_CONTAINER: '#put_token_type',
    TOKEN_TYPE_TEXT: '#token_type_text',
    TOKEN_TYPE_CHECKBOX: '#type-checkbox'
};
```

---

## 錯誤處理

前端攔截的特殊錯誤碼：

| 錯誤碼 | 說明 | 處理方式 |
|--------|------|---------|
| 1008 | iframe 連線逾時 | 提示使用者重新整理 |
| 1007 | 跨域通訊不合法（域名與 Token 設定不符） | 檢查 IFrameDomain 設定 |

其他錯誤碼透過 `ERROR_MAPPER`（由後端注入）轉換為使用者可讀訊息。

# Woomp 開發指引 SKILL

## 架構概覽

Woomp 是一個單體式 WooCommerce 外掛，將多個子外掛（金流、物流、發票）整合於統一的設定介面下。每個子外掛可獨立啟用或停用。

### 技術棧
- **PHP 8.0+** / WordPress 6.x / WooCommerce 5.3+
- **jQuery** + **ES6 Modules**（PayUni v3 前端）
- **Composer**：PSR-4 自動載入（`J7\Payuni\`）、a7/autoload（舊版）
- **PHPCS**：WordPress Coding Standards（Tab 縮排、短陣列語法、PHP 8.0+ 相容）
- **Playwright**：E2E 測試（TypeScript）

### 核心依賴（composer.json）
| 套件 | 版本 | 用途 |
|------|------|------|
| `oberonlai/wp-metabox` | ^1.0 | Metabox 建構器 |
| `a7/autoload` | ^2.1 | 舊版 PayUni v1 自動載入器 |
| `dennykuo/invoice-porter` | ^0.2.2 | 發票工具 |
| `guzzlehttp/guzzle` | ^6.5.8 | HTTP 客戶端 |
| `yahnis-elsts/plugin-update-checker` | ^5.3 | 基於 GitHub 的外掛更新機制 |

---

## 完整目錄結構

```
woomp/
├── woomp.php                          # 外掛入口點（版本 header：3.4.81）
├── init.php                           # 常數定義、WC 檢查、子外掛載入、工具函式
├── Compatibility.php                  # 相容性輔助工具
├── debug.php                          # 除錯工具
│
├── admin/                             # === 後台功能 ===
│   ├── class-woomp-admin.php          # 後台 Hook、資源載入、帳單/運送 meta
│   ├── class-woomp-product.php        # 商品後台客製化
│   ├── class-woomp-order.php          # 訂單管理、物流單號更新
│   ├── class-woomp-email.php          # 自訂郵件動作
│   ├── class-woomp-payment-cod-clone.php  # 貨到付款複製
│   ├── class-woomp-shipping-flat-rate.php # 自訂統一運費
│   ├── settings/
│   │   ├── class-woomp-setting.php        # 主設定頁籤（好用版擴充）
│   │   ├── class-woomp-setting-gateway.php    # 金流子頁籤
│   │   ├── class-woomp-setting-shipping.php   # 物流子頁籤
│   │   └── class-woomp-setting-invoice.php    # 發票子頁籤
│   ├── resources/
│   │   ├── shop_subscription/index.php    # 訂閱資源
│   │   └── class-checkout.php             # 結帳後台資源
│   ├── css/                           # 後台樣式表
│   ├── js/                            # 後台腳本
│   └── partials/                      # 後台模板片段
│
├── public/                            # === 前台功能 ===
│   ├── class-woomp-public.php         # 前台資源載入、虛擬商品自動完成訂單
│   ├── class-woomp-checkout.php       # 結帳頁客製化（欄位順序、驗證）
│   ├── class-woomp-order.php          # 前台訂單顯示
│   ├── class-woomp-product.php        # 前台商品顯示（可變商品 UI）
│   ├── css/                           # 前台樣式表
│   │   └── themes/                    # 佈景主題專屬覆寫
│   ├── js/                            # 前台腳本
│   └── partials/                      # 前台模板片段
│
├── includes/                          # === 核心類別 + 子外掛模組 ===
│   ├── class-woomp.php                # 主外掛類別（Loader Pattern）
│   ├── class-woomp-loader.php         # Hook 管理器（add_action/add_filter 收集器）
│   ├── class-woomp-logger.php         # 日誌工具
│   ├── class-woomp-i18n.php           # 國際化
│   ├── class-woomp-activator.php      # 啟用邏輯
│   ├── class-woomp-deactivator.php    # 停用邏輯
│   │
│   ├── payuni/                        # --- PayUni（統一金流）---
│   │   ├── payuni.php                 # PayUni 入口 & v1/v3 啟動
│   │   ├── assets/                    # v1 前端資源（card.js、card.css）
│   │   ├── settings/                  # PayUni 設定
│   │   ├── src/                       # v1 原始碼
│   │   │   └── gateways/
│   │   │       ├── AbstractGateway.php    # 基底閘道（繼承 WC_Payment_Gateway_CC）
│   │   │       ├── Credit.php             # 信用卡 v1
│   │   │       ├── CreditV3.php           # 信用卡 v3（UNi Embed）
│   │   │       ├── CreditInstallment.php  # 分期付款
│   │   │       ├── CreditSubscription.php # 訂閱付款
│   │   │       ├── Atm.php                # ATM 轉帳
│   │   │       ├── Cvs.php                # 超商代碼繳費
│   │   │       ├── Request.php            # API 請求處理
│   │   │       ├── Response.php           # API 回應處理
│   │   │       ├── Refund.php             # 退款處理
│   │   │       └── Subscription.php       # 訂閱處理
│   │   └── v3/                        # v3 PSR-4（命名空間 J7\Payuni\）
│   │       ├── Bootstrap.php              # V3 Hook 註冊 & 交易通知處理
│   │       ├── Contracts/DTOs/            # 資料傳輸物件
│   │       │   ├── SdkDTO.php             # SDK Token 回應
│   │       │   ├── SettingDTO.php         # 閘道設定
│   │       │   ├── TradeReqDTO.php        # 交易請求資料
│   │       │   └── TradeReqHashDTO.php    # 加密交易請求
│   │       ├── Infrastructure/Http/       # HTTP 層
│   │       │   ├── HttpClient.php         # API 客戶端
│   │       │   └── TradeHandler.php       # 交易處理
│   │       ├── Shared/
│   │       │   ├── Enums/                 # 列舉（EMode 等）
│   │       │   ├── Helpers/               # 輔助類別
│   │       │   └── Utils/                 # 工具類別（OrderUtils 等）
│   │       └── Applications/assets/
│   │           ├── css/checkout.css        # V3 結帳樣式
│   │           └── js/                    # V3 ES6 模組
│   │               ├── checkout.js            # 入口點
│   │               ├── env.module.js          # jQuery 別名 & 環境偵測
│   │               ├── Elements.module.js     # iframe 元素管理
│   │               ├── PayUniService.module.js # PayUni SDK 封裝
│   │               ├── FormState.module.js    # 表單狀態管理
│   │               ├── ApiService.module.js   # API 呼叫服務
│   │               ├── UIHelper.module.js     # UI 輔助工具
│   │               ├── constants.module.js    # 選擇器 & 常數
│   │               └── utils.module.js        # 共用工具函式
│   │
│   ├── ry-woocommerce-tools/         # --- 綠界 / 藍新 / 速買配 ---
│   │   ├── class.ry-wt.main.php      # 主類別（常駐載入）
│   │   ├── icon/                      # 付款圖示
│   │   ├── style/                     # 樣式
│   │   ├── templates/                 # 模板
│   │   └── woocommerce/              # WC 模板覆寫
│   │
│   ├── paynow-payment/               # --- 立吉富金流（條件式載入）---
│   │   ├── includes/class-paynow-payment.php  # 金流主類別
│   │   ├── admin/ public/             # 後台 & 前台
│   │   └── languages/                 # 翻譯檔
│   │
│   ├── paynow-shipping/              # --- 立吉富物流（條件式載入）---
│   │   ├── includes/
│   │   │   ├── shippings/             # 物流方式類別
│   │   │   │   ├── abstract-paynow-shipping.php
│   │   │   │   ├── class-paynow-shipping-c2c-711.php    # 7-11 超商取貨
│   │   │   │   ├── class-paynow-shipping-c2c-family.php # 全家超商取貨
│   │   │   │   ├── class-paynow-shipping-c2c-hilife.php # 萊爾富超商取貨
│   │   │   │   └── class-paynow-shipping-hd-tcat.php    # 黑貓宅配
│   │   │   └── api/                   # 物流 API
│   │   ├── assets/ templates/ languages/
│   │
│   ├── paynow-einvoice/              # --- 立吉富電子發票（條件式載入）---
│   ├── line-pay-for-woo/             # --- LINE Pay（常駐載入）---
│   ├── PChomePay-Cart-for-WooCommerce/ # --- 支付連（條件式載入）---
│   ├── woomp-ecpay-invoice/          # --- 綠界電子發票（常駐載入）---
│   │   ├── src/                       # 發票原始碼
│   │   └── assets/                    # 發票靜態資源
│   ├── woomp-ezpay-invoice/          # --- EZPAY 電子發票（常駐載入）---
│   │   ├── src/                       # 發票原始碼
│   │   └── assets/                    # 發票靜態資源
│   └── woomp-paynow-shipping/        # --- 立吉富物流 v2（條件式載入）---
│
├── woocommerce/checkout/             # WC 結帳模板覆寫
├── languages/                         # 多國語系 .po/.mo 檔案
├── e2e/                               # Playwright E2E 測試
│   ├── playwright.config.ts           # Playwright 設定
│   ├── payuni-checkout.ts             # 結帳流程測試
│   ├── payuni-installment.ts          # 分期付款測試
│   ├── payuni-invoice-carrier.ts      # 發票載具測試
│   └── payuni-tokenization.ts         # 記憶卡號測試
├── composer.json                      # PHP 依賴 & PSR-4 自動載入
├── package.json                       # Node.js 建置工具
├── build.mjs                          # 建置腳本（ZIP 打包）
└── phpcs.xml                          # PHPCS 設定
```

---

## 模組載入機制

### 入口流程
```
woomp.php -> init.php -> includes/class-woomp.php -> Woomp::run()
                      -> （條件式子外掛載入）
                      -> includes/payuni/payuni.php
```

### init.php 中的條件式載入
每個服務商根據 WordPress 選項載入：

| 選項鍵 | 模組 | 預設值 |
|--------|------|--------|
| `wc_woomp_enabled_payuni_gateway` | PayUni 金流 | `no` |
| `wc_woomp_enabled_payuni_shipping` | PayUni 物流 | `no` |
| `RY_WT::$option_prefix . 'enabled_ecpay_gateway'` | 綠界金流 | `no` |
| `RY_WT::$option_prefix . 'enabled_ecpay_shipping'` | 綠界物流 | `no` |
| `RY_WT::$option_prefix . 'enabled_newebpay_gateway'` | 藍新金流 | `no` |
| `RY_WT::$option_prefix . 'enabled_newebpay_shipping'` | 藍新物流 | `no` |
| `RY_WT::$option_prefix . 'enabled_smilepay_gateway'` | 速買配金流 | `no` |
| `RY_WT::$option_prefix . 'enabled_smilepay_shipping'` | 速買配物流 | `no` |
| `wc_woomp_setting_paynow_gateway` | 立吉富金流 | `no` |
| `wc_woomp_setting_paynow_shipping` | 立吉富物流 | `no` |
| `wc_settings_tab_active_paynow_einvoice` | 立吉富電子發票 | `no` |
| `wc_woomp_enabled_ecpay_invoice` | 綠界電子發票 | `no` |
| `wc_woomp_enabled_ezpay_invoice` | EZPAY 電子發票 | `no` |
| `woocommerce_linepay_enabled` | LINE Pay | `no` |
| `woocommerce_pchomepay_enabled` | 支付連 | `no` |

常駐載入：`ry-woocommerce-tools`、`line-pay-for-woo`、`woomp-ecpay-invoice`、`woomp-ezpay-invoice`、PayUni v3 `Bootstrap::register_hooks()`。

---

## 金流閘道整合模式

### AbstractGateway（PayUni v1）

```php
namespace PAYUNI\Gateways;

// 基底類別繼承 WC_Payment_Gateway_CC 以支援信用卡功能
abstract class AbstractGateway extends \WC_Payment_Gateway_CC {
    public $id;
    protected $mer_id;
    protected $hash_key;
    protected $hash_iv;
    // ... 共用閘道屬性
}
```

### 建立新的金流閘道

```php
namespace PAYUNI\Gateways;

class MyNewGateway extends AbstractGateway {
    public const ID = 'payuni-my-new';

    public function __construct() {
        $this->id = self::ID;
        parent::__construct();
        $this->method_title = '我的新閘道';
        $this->supports = [ 'products', 'refunds' ];
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option( 'title' );

        \add_action(
            "woocommerce_update_options_payment_gateways_{$this->id}",
            [ $this, 'process_admin_options' ]
        );
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title'   => __( '啟用/停用', 'woocommerce' ),
                'type'    => 'checkbox',
                'label'   => sprintf( __( '啟用 %s', 'woomp' ), $this->method_title ),
                'default' => 'no',
            ],
            'title' => [
                'title'   => __( '標題', 'woocommerce' ),
                'type'    => 'text',
                'default' => $this->method_title,
            ],
        ];
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        // ... 付款處理邏輯
        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        ];
    }
}
```

### 註冊金流閘道
金流閘道透過各子外掛初始化檔案中的 `woocommerce_payment_gateways` 過濾器註冊。

---

## 物流方式整合模式

```php
// 物流方式繼承 WC_Shipping_Method
class PayNow_Shipping_C2C_711 extends Abstract_PayNow_Shipping {
    // 7-11 超商取貨（C2C 店到店）
}

// 透過 woocommerce_shipping_methods 過濾器註冊
add_filter( 'woocommerce_shipping_methods', function( $methods ) {
    $methods['paynow_shipping_c2c_711'] = 'PayNow_Shipping_C2C_711';
    return $methods;
});
```

可用物流類型：
- **C2C**（店到店）：7-11、全家、萊爾富
- **HD**（宅配）：黑貓宅急便

---

## 電子發票整合模式

三家發票服務商，皆透過設定開關控制：
- **綠界**（`woomp-ecpay-invoice/`）：使用 `wc_woomp_enabled_ecpay_invoice`
- **EZPAY**（`woomp-ezpay-invoice/`）：使用 `wc_woomp_enabled_ezpay_invoice`
- **立吉富**（`paynow-einvoice/`）：使用 `wc_settings_tab_active_paynow_einvoice`

發票載具類型包含：手機條碼、自然人憑證、統一編號（公司戶）。

---

## PayUni v3 前端架構

### ES6 模組系統
入口：`checkout.js` -> `Elements.module.js` -> `PayUniService.module.js`

```
checkout.js                 # jQuery ready + updated_checkout 事件綁定
├── env.module.js           # 匯出 jQuery 為 $、環境偵測
├── Elements.module.js      # 管理 PayUni SDK iframe 生命週期
│   ├── PayUniService.module.js  # PayUni UNi Embed SDK 封裝
│   ├── FormState.module.js      # 結帳表單狀態管理
│   ├── ApiService.module.js     # AJAX API 呼叫
│   └── UIHelper.module.js       # DOM 操作輔助工具
├── constants.module.js     # CSS 選擇器、iframe 元素 ID
└── utils.module.js         # 共用工具函式
```

### 腳本載入方式
```php
// 在 Bootstrap::enqueue_checkout_scripts() 中
wp_enqueue_script( 'uni-payment', $sdk_url, [], '3.0.0', true );
wp_enqueue_script( 'uni-payment-checkout', '.../checkout.js', [ 'uni-payment', 'jquery' ], WOOMP_VERSION, true );

// 透過過濾器設定 script type="module"
add_filter( 'script_loader_tag', [ __CLASS__, 'modify_script_type' ], 10, 3 );
```

### 前端參數（wp_localize_script）
```php
wp_localize_script( 'uni-payment-checkout', 'payuni_payment_v3_checkout_params', [
    'ENV'                 => 'P' 或 'S',         // 正式環境或沙箱
    'SDK_TOKEN'           => $sdk_token,          // 一次性 SDK Token
    'USE_INST'            => $enable_installment, // 是否啟用分期
    'ENABLE_3D_AUTH'      => $enable_3d_auth,     // 3D 驗證
    'INST_OPTIONS'        => $installment_options, // 分期期數選項
    'ENABLE_TOKENIZATION' => $enable_tokenization, // 記憶卡號
    'ERROR_MAPPER'        => HttpClient::$error_mapper,
]);
```

---

## PayUni v3 PSR-4 結構

```
J7\Payuni\
├── Bootstrap              # Hook 註冊、腳本載入、交易通知處理
├── Contracts\DTOs\
│   ├── SdkDTO             # SDK Token 回應對應
│   ├── SettingDTO          # 閘道設定（單例模式）
│   ├── TradeReqDTO         # 交易請求資料
│   └── TradeReqHashDTO     # 加密交易請求
├── Infrastructure\Http\
│   ├── HttpClient          # PayUni API HTTP 客戶端
│   └── TradeHandler        # 交易處理 & 訂單狀態更新
└── Shared\
    ├── Enums\              # EMode（正式/沙箱）等
    ├── Helpers\            # 輔助類別
    └── Utils\
        └── OrderUtils      # 訂單 meta 操作、結帳欄位擴充
```

---

## 記憶卡號（Tokenization）模式

PayUni v3 支援信用卡記憶卡號：
1. 前端：PayUni SDK 透過 iframe 收集卡片資訊，回傳 `CardHash`
2. 結帳：`Bootstrap::save_payuni_card_hash()` 將 hash 暫存於訂單 meta
3. 付款：`CreditV3` 將 `CardHash` 連同 `UseToken` 旗標送至 PayUni API
4. WooCommerce Token：透過 `WC_Payment_Token` 儲存，供回訪客戶使用

閘道宣告支援：`$this->supports = [ 'products', 'refunds', 'tokenization' ];`

---

## 設定頁面架構

設定位於 **WooCommerce > 設定 > 好用版擴充**（`woomp_setting` 頁籤）：

### 主頁籤（`class-woomp-setting.php`）
- 各服務商啟用/停用開關（PAYUNi、綠界、藍新、速買配、立吉富、LINE Pay、支付連）
- 結帳模式（預設 / 一頁式 / 兩頁式）
- 台灣地址下拉選單、欄位驗證、虛擬商品選項
- 可變商品 UI 設定
- 免運提示

### 子頁籤（透過 `set_more_tabs()`）
- `class-woomp-setting-gateway.php` - 金流專屬設定
- `class-woomp-setting-shipping.php` - 物流專屬設定
- `class-woomp-setting-invoice.php` - 發票專屬設定

---

## E2E 測試（Playwright）

### 設定（`e2e/playwright.config.ts`）
- **基本 URL**：`https://payuni-test.powerhouse.tw`（沙箱環境）
- **瀏覽器**：Chromium，非無頭模式
- **Workers**：1（序列執行，避免 PayUni 沙箱並發衝突）
- **逾時**：每個測試 60 秒、每個動作 15 秒

### 測試檔案
| 檔案 | 測試範圍 |
|------|----------|
| `payuni-checkout.ts` | 基本結帳流程 |
| `payuni-installment.ts` | 分期付款 |
| `payuni-invoice-carrier.ts` | 發票載具選擇 |
| `payuni-tokenization.ts` | 記憶卡號 |

### 執行測試
```bash
cd e2e
npm install
npx playwright test                          # 執行所有測試
npx playwright test payuni-checkout.ts       # 執行特定測試
npx playwright test --headed                 # 開啟瀏覽器執行
```

---

## WooCommerce 模板覆寫

`woocommerce/checkout/` 中的自訂結帳模板在結帳模式設為 `onepage` 或 `twopage` 時啟用（由 `wc_woomp_setting_mode` 選項控制）。透過 `wc_get_template` 過濾器攔截模板路徑。

---

## 常用指令

```bash
# PHP 依賴
composer install
composer dump-autoload

# 程式碼風格檢查
vendor/bin/phpcs
vendor/bin/phpcbf                    # 自動修正

# 建置發佈用 ZIP
npm run build                        # 使用 build.mjs 搭配 archiver

# E2E 測試
cd e2e && npx playwright test
cd e2e && npx playwright test --ui   # 互動式 UI 模式

# 外掛更新
# 透過 GitHub Releases 自動更新（master 分支）
```

---

## 關鍵模式總覽

| 模式 | 實作方式 | 位置 |
|------|----------|------|
| Loader Pattern | `Woomp_Loader` 收集 Hook 後批次註冊 | `includes/class-woomp-loader.php` |
| 抽象閘道 | 所有 PayUni 金流閘道的基底類別 | `includes/payuni/src/gateways/AbstractGateway.php` |
| 設定驅動載入 | `get_option()` 控制模組啟用 | `init.php` |
| Metabox 建構器 | `oberonlai/wp-metabox` Composer 套件 | 各後台檔案 |
| 外掛更新檢查 | `yahnis-elsts/plugin-update-checker` | `init.php`（底部） |
| ES6 模組系統 | 原生模組搭配 `type="module"` | `includes/payuni/v3/Applications/assets/js/` |
| DTO 模式 | 型別化資料物件用於 API 通訊 | `includes/payuni/v3/Contracts/DTOs/` |
| 模板覆寫 | `wc_get_template` 過濾器用於結帳頁 | `init.php` + `woocommerce/checkout/` |

---
paths:
  - "**/*.php"
---

# Woomp 的 WordPress / PHP 編碼規範

## WordPress 編碼標準

本專案透過 `phpcs.xml` 強制執行 WordPress Coding Standards。主要規則：

- **縮排**：使用 Tab（4 格寬度），強制精確縮排
- **陣列語法**：僅允許短陣列 `[]`（禁止使用 `array()` 長語法）
- **PHP 相容性**：8.0+（`testVersion: 8.0-`）
- **排除規則**：不要求 Yoda 條件式、允許短三元運算子、不強制 `FileName` 規則

執行 `vendor/bin/phpcs` 檢查。排除路徑：`vendor/`、`node_modules/`、`tests/`、`js/`、`release/`。

## 命名慣例

- **選項**：`wc_woomp_setting_*` 或 `wc_woomp_enabled_*` 前綴
- **函式**：全域函式使用 `woomp_` 前綴（例如 `woomp_copy_order()`）
- **常數**：`WOOMP_*` 前綴（例如 `WOOMP_PLUGIN_URL`、`WOOMP_PLUGIN_DIR`）
- **類別**：PascalCase 搭配 `Woomp_` 前綴用於核心類別（例如 `Woomp_Loader`、`Woomp_Admin`）
- **子外掛**：各服務商使用自有前綴（`PayNow_`、`RY_WT::`、`PAYUNI\`）
- **Hook**：自訂 Hook 使用 `woomp_*` 前綴（例如 `woomp_payuni_log`）

## 安全性規範

- 輸入一律使用 `sanitize_*()` 函式清理（例如 `sanitize_text_field()`、`sanitize_email()`）
- 輸出一律使用 `esc_html()`、`esc_attr()`、`esc_url()`、`wp_kses_post()` 跳脫
- 直接資料庫查詢一律使用 `$wpdb->prepare()`
- 表單安全使用 `wp_create_nonce()` / `wp_verify_nonce()`
- 權限檢查使用 `current_user_can()`
- 備註：`WordPress.Security.EscapeOutput.OutputNotEscaped` 已在 phpcs.xml 中排除，但輸出仍應進行跳脫處理

## Hook 組織方式

### Loader Pattern（核心 Woomp）
核心 Hook 透過 `includes/class-woomp.php` 中的 `Woomp_Loader` 註冊：

```php
// 在 Woomp::define_admin_hooks() 中
$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
$this->loader->add_filter( 'woocommerce_admin_billing_fields', $plugin_admin, 'custom_order_meta', 10, 1 );
```

### 直接註冊（子外掛）
子外掛使用直接的 `add_action()`/`add_filter()` 呼叫：

```php
// 在 PayUni Bootstrap 中
\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_checkout_scripts' ] );
\add_filter( 'script_loader_tag', [ __CLASS__, 'modify_script_type' ], 10, 3 );
```

### 設定頁籤模式
設定使用 WooCommerce Settings API，透過靜態 `init()` 方法初始化：

```php
class Woomp_Setting {
    public static function init() {
        add_filter( 'woocommerce_settings_tabs_array', __CLASS__ . '::add_settings_tab', 50 );
        add_action( 'woocommerce_settings_tabs_woomp_setting', __CLASS__ . '::settings_tab' );
        add_action( 'woocommerce_update_options_woomp_setting', __CLASS__ . '::update_settings' );
    }
}
```

## 金流閘道模式

所有金流閘道繼承 `WC_Payment_Gateway` 或 `WC_Payment_Gateway_CC`：

```php
namespace PAYUNI\Gateways;

class CreditV3 extends AbstractGateway {
    public const ID = 'payuni-credit-v3';

    public function __construct() {
        $this->id = self::ID;
        parent::__construct();
        $this->has_fields = true;
        $this->method_title = '統一金流 PAYUNi 信用卡 v3';
        $this->supports = [ 'products', 'refunds', 'tokenization' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option( 'title' );
        \add_action( "woocommerce_update_options_payment_gateways_{$this->id}", [ $this, 'process_admin_options' ] );
    }
}
```

金流閘道關鍵方法：`init_form_fields()`、`process_payment()`、`process_refund()`、`payment_fields()`。

當單一基底類別涵蓋多種付款方式時（如 ECPay `RY_ECPay_Gateway_Base` 同時涵蓋信用卡／ATM／超商代碼／超商條碼／WebATM），`process_refund()` 可依 `payment_type` 等屬性分流：可線上退款的類型轉呼叫廠商 API，其餘類型直接回傳 `WP_Error`（繁中訊息說明並非系統故障、引導商家至廠商後台人工退款），避免整個閘道一律回傳失敗或誤判為錯誤。範例：`includes/ry-woocommerce-tools/woocommerce/gateways/ecpay/includes/ecpay-gateway-base.php`。

## 物流方式模式

物流方式繼承 `WC_Shipping_Method`，透過 `woocommerce_shipping_methods` 過濾器註冊：

```php
function add_paynow_shipping_methods( $methods ) {
    $methods['paynow_shipping_c2c_711']    = 'PayNow_Shipping_C2C_711';
    $methods['paynow_shipping_c2c_family'] = 'PayNow_Shipping_C2C_Family';
    return $methods;
}
add_filter( 'woocommerce_shipping_methods', 'add_paynow_shipping_methods' );
```

## PSR-4 自動載入（PayUni v3）

`J7\Payuni\` 命名空間透過 Composer PSR-4 對應至 `includes/payuni/v3/`：

```
J7\Payuni\Bootstrap         -> includes/payuni/v3/Bootstrap.php
J7\Payuni\Contracts\DTOs\*  -> includes/payuni/v3/Contracts/DTOs/
J7\Payuni\Infrastructure\*  -> includes/payuni/v3/Infrastructure/
J7\Payuni\Shared\*          -> includes/payuni/v3/Shared/
```

舊版 PayUni v1 使用 `a7/autoload` 載入 `includes/payuni/src/`。

## 子外掛條件式載入

子外掛在 `init.php` 中根據 WordPress 選項條件式載入：

```php
// 立吉富金流 - 僅在啟用時載入
if ( 'yes' === get_option( 'wc_woomp_setting_paynow_gateway' ) ) {
    require_once PAYNOW_PLUGIN_DIR . 'includes/class-paynow-payment.php';
    Paynow_Payment::init();
}

// PayUni - 金流或物流任一啟用時載入
if ( wc_string_to_bool( get_option( 'wc_woomp_enabled_payuni_gateway' ) )
     || wc_string_to_bool( get_option( 'wc_woomp_enabled_payuni_shipping' ) ) ) {
    \A7\autoload( WOOMP_PLUGIN_DIR . 'includes/payuni/src' );
}
```

常駐載入模組：`ry-woocommerce-tools`、`line-pay-for-woo`、`woomp-ecpay-invoice`、`woomp-ezpay-invoice`、`payuni`（v3 Bootstrap）。

## 類別宣告

依據 `phpcs.xml`，類別應宣告為 `final`（由 `Universal.Classes.RequireFinalClass` 強制）。Trait 中的方法必須為 `final`（由 `Universal.FunctionDeclarations.RequireFinalMethodsInTraits` 強制）。

例外：抽象基底類別如 `AbstractGateway` 自然不受此限制。

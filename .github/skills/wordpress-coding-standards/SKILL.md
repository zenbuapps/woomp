---
name: wordpress-coding-standards
description: WordPress / PHP 編碼標準完整參考手冊，涵蓋安全性、命名規範、型別系統、架構模式、Hook 系統、WooCommerce 相容等。供 wordpress-reviewer 及 wordpress-master agent 使用。
---

# WordPress / PHP 編碼標準完整參考

本文件為 WordPress Plugin / Theme 開發的完整編碼標準，供 `wordpress-reviewer` 審查時參考。

---

## 一、檔案結構標準

### 1.1 嚴格型別宣告

所有 PHP 檔案必須在開頭宣告 `declare(strict_types=1)`。

```php
// ❌ 不好的做法
<?php

namespace MyPlugin\Domain;

class ProductService {}

// ✅ 正確的做法
<?php
/**
 * @license GPL-2.0+
 */

declare(strict_types=1);

namespace MyPlugin\Domain\Product;

/**
 * 商品服務
 */
class ProductService {}
```

### 1.2 直接存取防護

非入口 PHP 檔案必須在開頭加上防護。

```php
// ✅ 正確
<?php
defined( 'ABSPATH' ) || exit;
```

---

## 二、命名規範

| 類型 | 規範 | 範例 |
|------|------|------|
| Class | `CamelCase` | `ProductService`、`StatusEnum` |
| Method / 函式 | `snake_case` | `get_product`、`process_submission` |
| 變數 | `snake_case` | `$product_id`、`$api_client` |
| 常數 / Enum Case | `UPPER_SNAKE_CASE` | `DAY_IN_SECONDS`、`ACTIVE` |
| Hook 名稱 | `{plugin_prefix}_{context}` | `my_plugin_before_submission` |

```php
// ✅ 正確的命名方式
class ProductService {
    private int $max_retry_count = 3;

    public function get_product_list(): array { /* ... */ }
}

// ❌ 錯誤的命名方式
class productService {
    private int $maxRetryCount;

    public function getProductList(): array { /* ... */ }
}
```

---

## 三、全域函式反斜線

在命名空間下使用全域函式時，必須加上反斜線 `\`。

```php
// ✅ 正確
$result = \get_posts( [ 'post_type' => 'post' ] );
$label  = \__( '標籤文字', 'my-plugin' );
$url    = \admin_url( 'admin.php' );
\add_action( 'init', [ __CLASS__, 'init' ] );
\add_filter( 'the_content', [ __CLASS__, 'filter_content' ] );
\do_action( 'my_plugin_loaded' );
$value = \apply_filters( 'my_plugin_value', $default );

// ❌ 沒有加上反斜線
$result = get_posts( [ 'post_type' => 'post' ] );
$label  = __( '標籤文字', 'my-plugin' );
```

---

## 四、PHPDoc 繁體中文說明

所有類別、方法必須有 PHPDoc 繁體中文說明，並完整標註參數與回傳型別。

```php
// ❌ 不好的做法
function get_product($id) {
    return get_post_meta($id, '_product_data', true);
}

// ✅ 正確的做法
/**
 * 根據 ID 取得商品詳細資料
 *
 * @param int $product_id 商品 ID
 *
 * @return ProductDTO 商品資料
 * @throws \RuntimeException 當商品不存在時拋出異常
 */
public function get_product( int $product_id ): ProductDTO {
    $post = \get_post( $product_id );

    if ( ! $post instanceof \WP_Post ) {
        throw new \RuntimeException( "商品 ID {$product_id} 不存在" );
    }

    return ProductDTO::from_post( $post );
}
```

---

## 五、DTO 資料傳輸物件

將散亂的 array 封裝為強型別 DTO，提升可讀性、IDE 補全、型別安全。

```php
// ❌ 不好的做法
$data = [
    'product_id' => 123,
    'name'       => 'PHP 進階課程',
    'price'      => 1999,
];
$id = $data['product_id']; // 沒有型別檢查，容易拼錯 key

// ✅ 正確的做法
declare(strict_types=1);

namespace MyPlugin\Domain\Product\DTOs;

/**
 * 商品資料傳輸物件
 */
class ProductDTO {

    /**
     * 建構子
     *
     * @param int    $product_id 商品 ID
     * @param string $name       商品名稱
     * @param int    $price      商品價格
     */
    public function __construct(
        public readonly int $product_id,
        public readonly string $name,
        public readonly int $price,
    ) {}

    /**
     * 從陣列建立 DTO
     *
     * @param array<string, mixed> $data 原始資料
     *
     * @return self
     */
    public static function from_array( array $data ): self {
        return new self(
            product_id: (int) ( $data['product_id'] ?? 0 ),
            name:       (string) ( $data['name'] ?? '' ),
            price:      (int) ( $data['price'] ?? 0 ),
        );
    }
}
```

---

## 六、Enum 取代魔術字串

使用 PHP 8.1 原生 enum 取代常數或魔術字串。

```php
// ❌ 不好的做法
define( 'STATUS_ACTIVE', 'active' );

function get_label( string $status ): string {
    if ( $status === 'active' ) {
        return '啟用';
    }
}

// ✅ 正確的做法
/**
 * 狀態枚舉
 */
enum StatusEnum: string {
    /** 啟用 */
    case ACTIVE = 'active';
    /** 停用 */
    case INACTIVE = 'inactive';
    /** 待審核 */
    case PENDING = 'pending';

    /**
     * 取得狀態的繁體中文標籤
     *
     * @return string 繁體中文標籤
     */
    public function label(): string {
        return match ( $this ) {
            self::ACTIVE   => \__( '啟用', 'my-plugin' ),
            self::INACTIVE => \__( '停用', 'my-plugin' ),
            self::PENDING  => \__( '待審核', 'my-plugin' ),
        };
    }
}
```

---

## 七、字串與陣列風格

```php
// ✅ 優先使用雙引號插值
$text = "這是 {$variable} 的文字";

// ✅ 其次使用 sprintf
$text = \sprintf( '這是 %1$s 的文字 %2$s', $variable1, $variable2 );

// ❌ 避免使用 . 拼接
$text = '這是 ' . $variable . ' 的文字';

// ✅ 使用短語法陣列
$items = [ 'a', 'b', 'c' ];

// ❌ 避免使用 array()
$items = array( 'a', 'b', 'c' );
```

### Heredoc 輸出 HTML

```php
// ❌ 不好的做法
function render_notice( string $message, string $type ): string {
    return '<div class="notice notice-' . $type . '">' .
        '<p>' . $message . '</p>' .
        '</div>';
}

// ✅ 正確的做法
/**
 * 渲染後台通知 HTML
 *
 * @param string $message 通知訊息
 * @param string $type    通知類型 (success|error|warning|info)
 *
 * @return string 通知 HTML
 */
function render_notice( string $message, string $type ): string {
    return <<<HTML
    <div class="notice notice-{$type}">
        <p>{$message}</p>
    </div>
    HTML;
}
```

---

## 八、安全性

### 8.1 SQL 注入防護

```php
// ❌ 危險：SQL 注入漏洞
$results = $wpdb->get_results(
    "SELECT * FROM {$wpdb->posts} WHERE post_author = {$user_id}"
);

// ✅ 安全：使用 prepare()
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->posts} WHERE post_author = %d",
        $user_id
    )
);
```

### 8.2 XSS 輸出防護

```php
// ❌ 危險：直接輸出未處理的使用者資料
echo $_GET['message'];

// ✅ 安全：依據上下文使用對應的 escape 函式
echo \esc_html( $message );          // 一般文字
echo \esc_attr( $attribute );        // HTML 屬性
echo \esc_url( $url );               // URL
echo \wp_kses_post( $html_content ); // 允許部分 HTML 標籤
```

### 8.3 CSRF 防護（Nonce）

```php
// ✅ 表單加入 nonce
\wp_nonce_field( 'my_plugin_save_settings', 'my_plugin_nonce' );

// ✅ 驗證 nonce
\check_admin_referer( 'my_plugin_save_settings', 'my_plugin_nonce' );

// ✅ AJAX nonce 驗證
\check_ajax_referer( 'my_plugin_ajax_nonce', 'nonce' );
```

### 8.4 能力檢查

```php
// ✅ 操作前驗證能力
if ( ! \current_user_can( 'manage_options' ) ) {
    \wp_die( \__( '您沒有權限執行此操作', 'my-plugin' ) );
}
```

### 8.5 資料清洗

```php
// ✅ 儲存前清洗輸入
$title   = \sanitize_text_field( $_POST['title'] ?? '' );
$content = \wp_kses_post( $_POST['content'] ?? '' );
$id      = \absint( $_POST['id'] ?? 0 );
$email   = \sanitize_email( $_POST['email'] ?? '' );
$url     = \esc_url_raw( $_POST['url'] ?? '' );
```

---

## 九、WordPress Hook 系統

### 9.1 提供擴展點

```php
// ✅ 正確的做法：提供 action 和 filter 擴展點
/**
 * 處理表單提交
 *
 * @param SubmissionDTO $dto 提交資料
 *
 * @return void
 */
public function process_submission( SubmissionDTO $dto ): void {
    /**
     * 提交前的 action hook
     *
     * @param SubmissionDTO $dto 提交資料
     */
    \do_action( 'my_plugin_before_submission', $dto );

    /**
     * 過濾提交資料
     *
     * @param SubmissionDTO $dto 提交資料
     */
    $dto = \apply_filters( 'my_plugin_submission_data', $dto );

    $this->save_to_database( $dto );
    $this->send_email( $dto );

    /**
     * 提交後的 action hook
     *
     * @param SubmissionDTO $dto 提交資料
     */
    \do_action( 'my_plugin_after_submission', $dto );
}
```

### 9.2 hook 名稱慣例

```
{plugin_prefix}_{動作/狀態}
{plugin_prefix}_{物件}_{動作}

範例：
my_plugin_before_order_created
my_plugin_order_status_changed
my_plugin_filter_product_price
```

---

## 十、WooCommerce 開發

### 10.1 HPOS（高效能訂單儲存）相容

```php
// ✅ 同時相容 HPOS 與傳統儲存
/** @var \WC_Order|false $order */
$order = \wc_get_order( $order_id );

if ( ! $order instanceof \WC_Order ) {
    return;
}

// 使用物件方法，不直接操作 postmeta
$meta_value = $order->get_meta( '_my_meta_key', true );
$order->update_meta_data( '_my_meta_key', $new_value );
$order->save();

// ❌ 不相容 HPOS 的做法
$meta_value = \get_post_meta( $order_id, '_my_meta_key', true );
\update_post_meta( $order_id, '_my_meta_key', $new_value );
```

### 10.2 宣告 HPOS 相容性

```php
\add_action( 'before_woocommerce_init', function (): void {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );
```

### 10.3 區塊結帳與傳統結帳相容

```php
// 傳統結帳 hook
\add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'process_order' ], 10, 3 );

// 區塊結帳 hook（Store API）
\add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'process_order_block' ] );

// 通用做法：使用 woocommerce_checkout_order_created（WC 8.2+ 兩者都會觸發）
\add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'handle_order_created' ] );
```

---

## 十一、REST API

### 11.1 路由注冊標準

```php
\add_action( 'rest_api_init', function (): void {
    \register_rest_route(
        'my-plugin/v1',
        '/products/(?P<id>\d+)',
        [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_product' ],
            'permission_callback' => function (): bool {
                return \current_user_can( 'read' );
            },
            'args'                => [
                'id' => [
                    'validate_callback' => fn( $param ) => is_numeric( $param ),
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]
    );
} );
```

### 11.2 REST 回應格式

```php
// ✅ 成功回應
return new \WP_REST_Response( $data, 200 );

// ✅ 錯誤回應
return new \WP_Error(
    'product_not_found',
    \__( '商品不存在', 'my-plugin' ),
    [ 'status' => 404 ]
);
```

---

## 十二、繼承類注意事項

```php
// ✅ 正確：保持與父類一致
class ChildService extends ParentService {
    protected function process( $data, $context ): void {
        // 父類 process 是 protected 且 $data 沒有型別，這裡也不加
        parent::process( $data, $context );
    }
}

// ❌ 錯誤：擅自加型別、改可見性、省略參數
class ChildService extends ParentService {
    public function process( array $data ): void {
        parent::process( $data, null );
    }
}
```

---

## 十三、多語系字串

```php
// text_domain 請從 copilot-instructions.md 或 plugin.php 查找
\__( '這是一段文字', 'my-plugin' );
\esc_html__( '安全的文字', 'my-plugin' );
\esc_attr__( '屬性文字', 'my-plugin' );
\wp_kses_post( \__( '<strong>粗體文字</strong>', 'my-plugin' ) );
```

---

## 十四、Log 記錄

```php
// WordPress error_log（通用）
\error_log( \wp_json_encode( $debug_data ) );

// WooCommerce Logger（WC 專案）
$logger = \wc_get_logger();
$logger->error( '錯誤訊息', [ 'source' => 'my-plugin' ] );
```

**Log 檔案位置：**
- WordPress debug log：`/wp-content/debug.log`
- WooCommerce log：`/wp-content/uploads/wc-logs/`

**開啟除錯模式（wp-config.php）：**

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

---

## 快速審查對照表

| 類別 | 常見問題 | 嚴重性 |
|------|---------|--------|
| 安全 | 未使用 `$wpdb->prepare()` | 🔴 |
| 安全 | 未 escape 輸出 | 🔴 |
| 安全 | 缺少 nonce 驗證 | 🔴 |
| 安全 | 缺少能力檢查 | 🔴 |
| 安全 | 缺少 `defined('ABSPATH')` | 🟠 |
| 型別 | 缺少 `strict_types` | 🟠 |
| 型別 | 使用魔術字串取代 enum | 🟠 |
| 架構 | 直接操作裸 array 取代 DTO | 🟠 |
| 架構 | 字串拼接 HTML（應用 heredoc） | 🟠 |
| 命名 | 全域函式未加反斜線 | 🟠 |
| WooCommerce | 直接 `get_post_meta` 操作訂單 | 🟠 |
| WooCommerce | 未宣告 HPOS 相容 | 🟠 |
| REST API | `permission_callback` 返回 true | 🔴 |
| 效能 | 迴圈中 N+1 查詢 | 🟠 |
| 品質 | 函式超過 50 行 | 🟡 |
| 品質 | 巢狀超過 4 層 | 🟠 |

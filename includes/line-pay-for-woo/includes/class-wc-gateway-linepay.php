<?php

/**
 * LINEPay Payment Gateway
 *
 * @class   WC_Gateway_LINEPay
 * @extends WC_Payment_Gateway
 * @version 3.0.0
 * @author  LINEPay
 */
class WC_Gateway_LINEPay extends WC_Payment_Gateway {
    
    
    /**
     * The logger object
     *
     * @var WC_Gateway_LINEpay_Logger
     */
    private static $logger;

    /** @var array 各幣別的小數位數 */
    public $linepay_currency_scales;

    /** @var string[] LINE Pay 支援的幣別 */
    public $linepay_supported_currencies;

    /** @var array 管理員／顧客可退款的訂單狀態 */
    public $linepay_supported_order_statuses;

    /** @var bool 是否啟用沙盒模式 */
    public $enable_sandbox;

    /** @var string 目前環境（real／sandbox） */
    public $linepay_evn_status;

    /** @var string 付款類型 */
    public $linepay_payment_type;

    /** @var string 付款動作（授權／請款） */
    public $linepay_payment_action;

    /** @var bool 是否啟用 LINE Pay 運送資訊 */
    public $linepay_shipping_enabled;

    /** @var array 日誌設定（enabled、level） */
    public $linepay_log_info;

    /** @var array 各環境的 Channel ID／Secret */
    public $linepay_channel_info;

    /** @var array 管理員／顧客可退款狀態設定 */
    public $linepay_refundable_statuses;

    /** @var bool|WP_Error 閘道是否可用（不可用時為 WP_Error） */
    public $linepay_is_valid;

    /** @var string LINE Pay 標誌圖片網址 */
    public $linepay_logo;

    /** confirmUrl／cancelUrl token 的 sha256 雜湊（訂單 meta） */
    const META_CALLBACK_TOKEN_HASH = '_linepay_callback_token_hash';

    /** reserve 時送給 LINE Pay 的金額（訂單 meta），confirm 時比對訂單金額是否被改過 */
    const META_RESERVED_AMOUNT = '_linepay_reserved_amount';

    /** confirm 鎖的 option 名稱前綴 */
    const CONFIRM_LOCK_PREFIX = 'woomp_linepay_confirm_lock_';

    /** confirm 鎖逾時秒數（confirm API timeout 40 秒 + 查詢 20 秒，再留餘裕） */
    const CONFIRM_LOCK_TTL = 120;

    /**
     * 產生 confirmUrl／cancelUrl 用的 token，並把雜湊寫入訂單 meta（呼叫端負責 save）
     *
     * 每次 reserve 都重新產生，舊的 confirm／cancel 網址隨即失效。
     *
     * @param WC_Order $order 訂單
     *
     * @return string 明文 token（只放進網址，不存）
     */
    public static function issue_callback_token( WC_Order $order ) {
        $token = wp_generate_password( 32, false, false );
        $order->update_meta_data( self::META_CALLBACK_TOKEN_HASH, hash( 'sha256', $token ) );
        return $token;
    }

    /**
     * 驗證回呼網址上的 token 是否屬於這張訂單
     *
     * 沒有存 token 雜湊的訂單（升級前 reserve 的）一律不通過：寧可讓少數升級當下付款中的訂單重新付款，
     * 也不讓舊網址可被任何人用訂單編號觸發。
     *
     * @param WC_Order $order 訂單
     * @param mixed    $token 網址上的 token
     *
     * @return bool
     */
    public static function verify_callback_token( WC_Order $order, $token ) {
        $stored = (string) $order->get_meta( self::META_CALLBACK_TOKEN_HASH );
        if ( '' === $stored || ! is_string( $token ) || '' === $token ) {
            return false;
        }
        return hash_equals( $stored, hash( 'sha256', $token ) );
    }

    /**
     * 取得某張訂單的 confirm 鎖（原子操作）
     *
     * 不用 add_option()：它是 SELECT 後再 INSERT ... ON DUPLICATE KEY UPDATE，兩個請求可能都回傳成功。
     * 這裡用 INSERT IGNORE（唯一鍵衝突時 0 rows affected）；鎖逾時則以「值等於舊值才更新」的條件 UPDATE 搶回。
     *
     * @param int $order_id Order ID.
     *
     * @return bool 是否取得鎖
     */
    public static function acquire_confirm_lock( $order_id ) {
        global $wpdb;

        $name = self::CONFIRM_LOCK_PREFIX . absint( $order_id );
        $now  = time();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $name,
                (string) $now
            )
        );
        if ( 1 === (int) $inserted ) {
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $locked_at = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
        if ( null === $locked_at || ( $now - (int) $locked_at ) < self::CONFIRM_LOCK_TTL ) {
            return false;
        }

        // 鎖已逾時（上一個請求可能中途死掉）：只有值還是舊值的那個請求能搶到.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                (string) $now,
                $name,
                (string) $locked_at
            )
        );
        return 1 === (int) $updated;
    }

    /**
     * 釋放 confirm 鎖
     *
     * @param int $order_id Order ID.
     */
    public static function release_confirm_lock( $order_id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( $wpdb->options, [ 'option_name' => self::CONFIRM_LOCK_PREFIX . absint( $order_id ) ] );
    }

    
    /**
     * LINEPay Gateway
     */
    public function __construct() {
        
        // Define the default status of LINEPay Gateway.
        $this->init_gateway_data();
        
        // Define information to be supported by LINEPay Gateway.
        $this->init_gateway_supports();
        
        // Define form field to show in admin setting.
        $this->init_form_fields();
        
        // Extract data registered in form field.
        $this->init_merchant_data();
        
        $this->init_linepay_logo();
        
        $this->init_settings();
        
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        
        static::$logger = WC_Gateway_LINEPay_Logger::get_instance( $this->linepay_log_info );
        
        // exception class.
        include_once 'class-wc-gateway-linepay-exception.php';
    }
    
    /**
     * Initialize the default state of the Gateway.
     */
    private function init_gateway_data() {
        $this->id = 'linepay';
        $this->title = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->has_fields = false;
        $this->order_button_text = __( 'Pay with LINE Pay', 'woomp' );
        $this->method_title = __( 'LINE Pay Gateway', 'woomp' );
        $this->method_description = __(
            'Payments are received through the LINE Pay gateway, which supports USD, JPY, TWD, and THB. In order to use LINE Pay, you must have a Channel ID and Channel SecretKey.',
            'woomp'
        );
    }
    
    /**
     * Initialize the information to be supported by LINEPay Gateway.
     * -Purchase or refund
     * -Supported countries
     * -Support currency
     * -Information on refund status of manager and buyer
     */
    private function init_gateway_supports() {
        // Support refund function.
        $this->supports = [
            'products',
            'refunds',
        ];
        
        // Currency scale information.
        $this->linepay_currency_scales = [
            'TWD' => 0,
        ];
        
        // LINE Pay supported currency.
        $this->linepay_supported_currencies = [ 'TWD' ];
        $this->linepay_supported_order_statuses = [
            WC_Gateway_LINEPay_Const::USER_STATUS_ADMIN    => [
                'pending',
                'processing',
                'on-hold',
                'completed',
                'cancelled',
                'refunded',
                'failed'
            ],
            // The customer's refund information is set to be available only when the product is not received.
            WC_Gateway_LINEPay_Const::USER_STATUS_CUSTOMER => [
                'processing'
                /* 'pending', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' */
            ],
        ];
    }
    
    /**
     * Form fields Initialize the information.
     *
     * @see WC_Settings_API::init_form_fields()
     * @see WC_Gateway_LINEPay_Settings->get_form_fields()
     */
    public function init_form_fields() {
        
        add_action( 'woocommerce_admin_field_media', [ $this, 'add_custom_logo_field' ], 10, 1 );
        
        include_once 'class-wc-gateway-linepay-settings.php';
        
        $settings = new WC_Gateway_LINEPay_Settings( $this );
        $this->form_fields = $settings->get_form_fields();
    }
    
    /**
     * Initialize the information registered in form fields to the fields of LINEPay Gateway.
     * Fields newly defined for LINEPay are separated by prefixing with linepay_.
     */
    protected function init_merchant_data() {
        
        $this->enabled = wc_string_to_bool( get_option( 'linepay_enabled' ) );
        $this->enable_sandbox = wc_string_to_bool( get_option( 'linepay_sandbox' ) );
        $this->linepay_evn_status = ( $this->enable_sandbox ) ? WC_Gateway_LINEPay_Const::ENV_SANDBOX : WC_Gateway_LINEPay_Const::ENV_REAL;
        $this->linepay_payment_type = get_option( 'linepay_payment_type' );
        $this->linepay_payment_action = get_option( 'linepay_payment_action' );
        
        $this->linepay_shipping_enabled = false;
        
        $this->linepay_log_info = [
            'enabled' => wc_string_to_bool( get_option( 'linepay_log_enabled' ) ),
            'level'   => get_option( 'linepay_log_level', WC_Gateway_LINEPay_Logger::LOG_LEVEL_NONE ),
        ];
        
        $this->linepay_channel_info = [
            WC_Gateway_LINEPay_Const::ENV_REAL    => [
                'channel_id'     => get_option( 'linepay_channel_id' ),
                'channel_secret' => get_option( 'linepay_channel_secret' ),
            ],
            WC_Gateway_LINEPay_Const::ENV_SANDBOX => [
                'channel_id'     => get_option( 'linepay_sandbox_channel_id' ),
                'channel_secret' => get_option( 'linepay_sandbox_channel_secret' ),
            ],
        ];
        
        $this->linepay_refundable_statuses = [
            WC_Gateway_LINEPay_Const::USER_STATUS_ADMIN    => get_option( 'linepay_admin_refund' ),
            WC_Gateway_LINEPay_Const::USER_STATUS_CUSTOMER => get_option( 'linepay_customer_refund' ),
        ];
        
        // LINEPay Gateway Check whether it is used.
        $this->linepay_is_valid = $this->is_valid_for_use();
        if( is_wp_error( $this->linepay_is_valid ) ) {
            $this->enabled = false;
        }
    }
    
    /**
     * When paying with LINE Pay, the logo image is returned.
     * If no custom logo is specified, the default logo is returned.
     */
    protected function init_linepay_logo() {
        
        $this->linepay_logo = get_option( 'linepay_custom_logo' );
        
        // If custom logo is not registered, use default logo image.
        if( !$this->linepay_logo ) {
            $this->linepay_logo = $this->get_linepay_logo_path( get_option( 'linepay_general_logo_size' ) );
            $this->linepay_logo = plugins_url( $this->linepay_logo, plugin_dir_path( __FILE__ ) );
        }
        
        $this->icon = $this->linepay_logo;
        
        add_filter( 'woocommerce_gateway_icon', [ $this, 'set_transparent_icon' ], 10, 2 );
    }
    
    
    /**
     * Provides order statuses information for each requester.
     *
     * @param string $requester => const:WC_Gateway_LINEPay_Const::USER_STATUS_ADMIN|CUSTOMER.
     *
     * @return array
     * @see wc_get_order_statuses()
     */
    function wc_get_order_statuses( $requester ) {
        
        $all_order_statuses = wc_get_order_statuses();
        $new_order_statuses = [];
        $order_statuses = $this->linepay_supported_order_statuses[$requester];
        
        foreach ( $order_statuses as $value ) {
            $new_key = 'wc-' . $value;
            $new_order_statuses[$new_key] = $all_order_statuses[$new_key];
        }
        
        return $new_order_statuses;
    }
    
    /**
     * Override the Admin option.
     */
    public function admin_options() {
        wp_enqueue_script( 'jquery' );
        wp_enqueue_media();
        
        wp_register_script(
                                                                                    'logo-uploader', untrailingslashit(
                                                                                                       plugins_url(
                                                                                                           '../',
                                                                                                           __FILE__
                                                                                                       )
                                                                                                   ) . WC_Gateway_LINEPay_Const::RESOURCE_JS_LOGO_UPLOADER,
                                                                                    [], '1.0.0', true
        );
        wp_enqueue_script( 'logo-uploader' );
        
        ?>
        <h3><?php echo ( !empty( $this->method_title ) ) ? $this->method_title : __(
                'Settings', 'woocommerce'
            ); ?></h3>
        
        <?php if( is_wp_error( $this->linepay_is_valid ) ) : ?>
            <div class="updated woocommerce-message">
                <div class="squeezer">
                    <h4><?php echo $this->linepay_is_valid->get_error_message(); ?></h4>
                </div>
            </div>
        <?php endif; ?>

        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
        <?php
    }
    
    /**
     * Process payments and return results.
     * To pay with LINE Pay, reserve-api is first called.
     * Override the parent process_payment function.
     * return the success and redirect in an array. e.g:
     * return array(
     *    'result'   => 'success',
     *    'redirect' => $this->get_return_url( $order )
     * );
     *
     * @param int $order_id Order ID.
     *
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->update_meta_data( '_linepay_payment_status', null );
            $order->update_meta_data( '_linepay_reserved_transaction_id', null );
            $order->save();
        }

        // reserve.
        return $this->process_payment_reserve( $order_id );
    }
    
    /**
     * Process the refund and return the result.
     *
     * This method is called only when the administrator processes it.
     * When the administrator requests a refund, the process_refund() method is called through WC_AJAX::refund_line_items().
     *
     * The woocommerce_delete_shop_order_transients action
     * This action occurs immediately before woocommerce completes the refund process when a manager requests a refund and gives a json response.
     *
     * @param int    $order_id
     * @param float  $amount
     * @param string $reason
     *
     * @return bool|WP_Error
     * @see WC_AJAX::refund_line_items()
     * @see woocommerce::action - woocommerce_delete_shop_order_transients
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        return $this->process_refund_request(
            WC_Gateway_LINEPay_Const::USER_STATUS_ADMIN, $order_id, $amount, $reason
        );
    }
    
    
    /**
     * Call LINE Pay's reserve-api and return the result.
     * Change the order status according to the api call result.
     *
     * Request successful
     * -post-meta fixes
     *
     * Request failed
     * -fix order_status
     *
     * @param int $order_id Order ID.
     *
     * @return array
     */
    private function process_payment_reserve( $order_id ) {
        
        try {
            $order = wc_get_order( $order_id );
            $product_info = [ 'packages' => $this->get_product_info( $order ) ];
            $order_id = $order->get_id();
            $currency = $order->get_currency();
            $std_amount = $this->get_standardized( $order->get_total(), $currency );
            
            // Check if the currency is the accuracy of the $amount that can be expressed.
            if( !$this->valid_currency_scale( $std_amount ) ) {
                throw new WC_Gateway_LINEPay_Exception(
                    sprintf(
                        WC_Gateway_LINEPay_Const::LOG_TEMPLATE_RESERVE_UNVALID_CURRENCY_SCALE, $order_id, $std_amount,
                        $currency, $this->get_currency_scale( $currency ), $this->get_amount_precision( $amount )
                    )
                );
            }
            
            $url = $this->get_request_url( WC_Gateway_LINEPay_Const::REQUEST_TYPE_RESERVE );
            $body = [
                'orderId'  => $order_id,
                'amount'   => $std_amount,
                'currency' => $currency,
            ];

            // confirmUrl／cancelUrl 是公開網址，帶上綁定本次 reserve 的一次性 token，
            // 只存雜湊；回呼時以 verify_callback_token() 比對，避免他人取消或提前 confirm 別人的訂單。
            $callback_token = self::issue_callback_token( $order );
            $order->update_meta_data( self::META_RESERVED_AMOUNT, $std_amount );

            $redirect_urls = [
                'redirectUrls' => [
                    'confirmUrl'     => esc_url_raw(
                        add_query_arg(
                            [
                                'request_type' => WC_Gateway_LINEPay_Const::REQUEST_TYPE_CONFIRM,
                                'order_id'     => $order_id,
                                'token'        => $callback_token,
                            ], home_url( WC_Gateway_LINEPay_Const::URI_CALLBACK_HANDLER )
                        )
                    ),
                    'confirmUrlType' => WC_Gateway_LINEPay_Const::CONFIRM_URLTYPE_CLIENT,
                    // 使用者的畫面跳轉到商家confirmUrl，完成付款流程
                    'cancelUrl'      => esc_url_raw(
                        add_query_arg(
                            [
                                'request_type' => WC_Gateway_LINEPay_Const::REQUEST_TYPE_CANCEL,
                                'order_id'     => $order_id,
                                'token'        => $callback_token,
                            ], home_url( WC_Gateway_LINEPay_Const::URI_CALLBACK_HANDLER )
                        )
                    ),
                ],
            ];
            
            $options = [
                'options' => [
                    'payment' => [
                        'payType' => strtoupper( $this->linepay_payment_type ),
                        'capture' => $this->is_captured(),
                    ],
                    'extra'   => [
                        'branchName' => get_bloginfo( 'name' ),
                    ],
                ],
            ];
            
            $info = static::execute( $this, $url, array_merge( $body, $product_info, $redirect_urls, $options ) );
            static::$logger->error( 'linepay_response', [ $info ] );
            
            
            // On request failure.
            if( is_wp_error( $info ) ) {
                throw new WC_Gateway_LINEPay_Exception( '', $info );
            }
            
            // Upon successful request.
            $order->update_meta_data( '_linepay_payment_status', WC_Gateway_LINEPay_Const::PAYMENT_STATUS_RESERVED );
            $order->update_meta_data( '_linepay_reserved_transaction_id', $info->transactionId );
            $order->update_meta_data( '_linepay_refund_info', [] );
            $order->save();

            // 回傳 paymentUrl 導向付款頁面.
            return [
                'result'   => 'success',
                'redirect' => $info->paymentUrl->web,
            ];
        }
        catch ( WC_Gateway_LINEPay_Exception $e ) {
            $info = $e->getInfo();
            static::$logger->error( 'process_payment_reserve', ( is_wp_error( $info ) ) ? $info : $e->getMessage() );
            
            wc_add_wp_error_notices(
                new WP_Error(
                    'process_payment_reserve',
                    __( 'Unable to process payment request. Please try again.', 'woocommerce-gateway-linepay' )
                )
            );
            
            // Initialize order stored in session.
            WC()->session->set( 'order_awaiting_payment', false );
            
            // Failed when WC_Order exists.
            if( $order instanceof WC_Order ) {
                $order->update_status( 'failed' );
            }
            
            if ( $order instanceof WC_Order ) {
                $order->update_meta_data( '_linepay_payment_status', WC_Gateway_LINEPay_Const::PAYMENT_STATUS_FAILED );
                $order->save();
            }

            return [
                'result'   => 'failure',
                'redirect' => wc_get_cart_url(),
            ];
        }
    }
    
    /**
     * Call confirm-api of LINE Pay and move to the page that matches the result.
     *
     * If reserve-api is successfully called, according to the registered confirmUrl
     * Call handle_callback of woocommerce.
     *
     * Request successful
     * -Go to order result page
     *
     * Request failed
     * -Send failure message
     * -Go to order detail page
     *
     * @param int $order_id Order ID.
     *
     * @see WC_Gateway_LINEPay_Handler->handle_callback()
     */
    public function process_payment_confirm( $order_id ) {
        $redirect = $this->confirm_payment( $order_id );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * 執行 confirm 並回傳要導向的網址（不 exit，方便測試）
     *
     * 同一張訂單同時只會有一個 confirm 在執行（acquire_confirm_lock()）；
     * 拿到鎖後重新讀取訂單，已不是 reserved（例如另一個請求已完成付款）就直接導回，不再呼叫 API。
     *
     * @param int $order_id Order ID.
     *
     * @return string 導向網址
     */
    public function confirm_payment( $order_id ) {
        $order_id = absint( $order_id );

        if ( ! self::acquire_confirm_lock( $order_id ) ) {
            // 另一個 confirm 正在處理，不可同時呼叫 API（第二個會失敗並把已付款訂單打回 pending）。
            static::$logger->error( 'process_payment_confirm', sprintf( '[order_id: %s] - another confirm is in progress, skip', $order_id ) );
            $order = wc_get_order( $order_id );
            return $order ? $this->get_return_url( $order ) : wc_get_cart_url();
        }

        try {
            return $this->confirm_payment_locked( $order_id );
        } finally {
            self::release_confirm_lock( $order_id );
        }
    }

    /**
     * 已取得鎖之後的 confirm 流程
     *
     * @param int $order_id Order ID.
     *
     * @return string 導向網址
     */
    private function confirm_payment_locked( $order_id ) {

        try {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return wc_get_cart_url();
            }

            // 拿到鎖後重新確認狀態：已被另一個請求完成（或已取消）就不再呼叫 confirm API.
            if ( WC_Gateway_LINEPay_Const::PAYMENT_STATUS_RESERVED !== $order->get_meta( '_linepay_payment_status' ) ) {
                return $this->get_return_url( $order );
            }

            // 已付款／已取消／已退款的訂單不可再 confirm（否則會請款並把訂單改回處理中）.
            if ( ! Woomp_Payment_Callback_Guard::can_mark_paid( $order ) ) {
                throw new WC_Gateway_LINEPay_Exception(
                    sprintf( '[order_id: %s][status: %s] - order cannot be marked as paid, skip confirm', $order_id, $order->get_status() )
                );
            }

            $amount = $order->get_total();
            $currency = $order->get_currency();
            $std_amount = $this->get_standardized( $amount );

            // 1st verification of the amount：reserve 送給 LINE Pay 的金額必須等於目前訂單金額
            // （reserve 後訂單被改金額時，不可用新金額 confirm）。舊版 reserve 沒有存金額時略過。
            // HPOS 下 `_order_total` 不在 postmeta，需用 WC_Order::get_total()。
            $reserved_amount = $order->get_meta( self::META_RESERVED_AMOUNT );
            if ( '' !== (string) $reserved_amount ) {
                $reserved_std_amount = $this->get_standardized( $reserved_amount, $currency );
                if ( $std_amount !== $reserved_std_amount ) {
                    throw new WC_Gateway_LINEPay_Exception(
                        sprintf(
                            WC_Gateway_LINEPay_Const::LOG_TEMPLATE_CONFIRM_FAILURE_MISMATCH_ORDER_AMOUNT, $std_amount,
                            $reserved_std_amount
                        )
                    );
                }
            }

            // api call.
            $reserved_transaction_id = $order->get_meta( '_linepay_reserved_transaction_id' );
            $url = $this->get_request_url(
                WC_Gateway_LINEPay_Const::REQUEST_TYPE_CONFIRM, [ 'transaction_id' => $reserved_transaction_id ]
            );
            $body = [
                'amount'   => $std_amount,
                'currency' => $currency,
            ];
            
            $info = static::execute( $this, $url, $body, 40 );
            
            // On request failure.
            if( is_wp_error( $info ) ) {
                throw new WC_Gateway_LINEPay_Exception( '', $info );
            }
            
            $confirmed_amount = 0;
            foreach ( $info->payInfo as $item ) {
                $this->line_write_log( $item );
                $confirmed_amount += (float) $item->amount;
            }
            
            // Refunds will be processed if the amount at Reserve is different from the amount after Confirm.
            $std_confirmed_amount = $this->get_standardized( $confirmed_amount );
            
            if( $std_amount !== $std_confirmed_amount ) {
                $refund_result = 'Refund Failure';
                if( !is_wp_error( $this->request_refund_api( $order, $reserved_transaction_id, $std_amount ) ) ) {
                    $refund_result = 'Refund Success';
                }
                
                throw new WC_Gateway_LINEPay_Exception(
                    sprintf(
                        WC_Gateway_LINEPay_Const::LOG_TEMPLATE_REFUND_FAILURE_AFTER_CONFIRM, $order_id, $std_amount,
                        $std_confirmed_amount, $refund_result
                    )
                );
            }
            
            $order->payment_complete( $info->transactionId );
            $order->update_meta_data( '_linepay_transaction_balanced_amount', $std_confirmed_amount );
            $order->update_meta_data( '_linepay_payment_status', WC_Gateway_LINEPay_Const::PAYMENT_STATUS_CONFIRMED );
            $order->delete_meta_data( self::META_CALLBACK_TOKEN_HASH );
            $order->save();

            // cart initialization.
            if ( WC()->cart ) {
                WC()->cart->empty_cart();
            }

            return $this->get_return_url( $order );
        }
        catch ( WC_Gateway_LINEPay_Exception $e ) {
            $info = $e->getInfo();
            static::$logger->error( 'process_payment_confirm', ( is_wp_error( $info ) ) ? $info : $e->getMessage() );

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return wc_get_cart_url();
            }

            // 已付款（或已由另一個請求 confirm）的訂單，錯誤分支不得把狀態打回 pending／on-hold，也不改 payment status.
            if ( $order->is_paid() || WC_Gateway_LINEPay_Const::PAYMENT_STATUS_CONFIRMED === $order->get_meta( '_linepay_payment_status' ) ) {
                return $this->get_return_url( $order );
            }

            // Initialize order stored in session.
            // FIXME: not sure the purpose.
            if ( WC()->session ) {
                wc_add_wp_error_notices(
                    new WP_Error(
                        'process_payment_confirm',
                        __( 'Unable to confirm payment. Please contact support.', 'woocommerce-gateway-linepay' )
                    )
                );
                WC()->session->set( 'order_awaiting_payment', false );
            }

            // 已取消／已退款的訂單不改狀態，只留紀錄.
            if ( ! Woomp_Payment_Callback_Guard::can_mark_paid( $order ) ) {
                $order->add_order_note( 'LINE Pay confirm 未執行：訂單目前狀態不可標為已付款（' . $order->get_status() . '）。' );
                return $this->get_return_url( $order );
            }

            $reserved_transaction_id = $order->get_meta( '_linepay_reserved_transaction_id' );
            $detail_url = $this->get_request_url(
                WC_Gateway_LINEPay_Const::REQUEST_TYPE_DETAILS, [ 'transaction_id' => $reserved_transaction_id ]
            );
            
            $detail_body = [ 'transactionId' => $reserved_transaction_id ];
            
            $detail_info = static::execute( $this, $detail_url, http_build_query( $detail_body ), 20, 'GET' );
            
            if( !is_wp_error( $detail_info ) ) {
                $pay_status = '';
                if( is_array( $detail_info ) && isset( $detail_info[0]->payStatus ) ) {
                    $pay_status = $detail_info[0]->payStatus;
                }
                $order->update_status(
                    'pending', 'LINE Pay 執行 Confirm API 失敗，查詢 LINE Pay 付款狀態為：' . $pay_status
                );
                $order->set_transaction_id( $reserved_transaction_id );
            }
            else {
                $order->update_status( 'on-hold', $detail_info->get_error_message() );
            }

            // FIXME:  not sure purpose.
            $order->update_meta_data( '_linepay_payment_status', WC_Gateway_LINEPay_Const::PAYMENT_STATUS_FAILED );
            $order->save();

            if ( WC()->cart ) {
                WC()->cart->empty_cart();
            }
            return $this->get_return_url( $order );
        }
    }


    /**
     * When canceling after payment request, the information used for payment is initialized.
     *
     * If you cancel after calling reserve-api, according to the registered cancelUrl
     * Call hanle_callback of woocommerce.
     *
     * @param int $order_id
     *
     * @see     WC_Gateway_LINEPay_Handler->handle_callback()
     */
    function process_payment_cancel( $order_id ) {
        if ( ! $this->cancel_payment( $order_id ) ) {
            return;
        }

        wp_redirect( wc_get_cart_url() );
        exit;
    }

    /**
     * 將 reserved 的訂單標為已取消（不 exit，方便測試）
     *
     * 已付款或已不是待付款的訂單不處理。
     *
     * @param int $order_id Order ID.
     *
     * @return bool 是否已取消
     */
    public function cancel_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        if ( ! Woomp_Payment_Callback_Guard::can_mark_paid( $order ) ) {
            static::$logger->error( 'process_payment_cancel', sprintf( '[order_id: %s][status: %s] - order is not awaiting payment, ignore cancel', $order->get_id(), $order->get_status() ) );
            return false;
        }

        $reserved_transaction_id = $order->get_meta( '_linepay_reserved_transaction_id' );

        $order->update_meta_data( '_linepay_payment_status', WC_Gateway_LINEPay_Const::PAYMENT_STATUS_CANCELLED );
        $order->delete_meta_data( self::META_CALLBACK_TOKEN_HASH );
        $order->save();
        $order->update_status( 'cancelled' );

        // Initialize order stored in session.
        if ( WC()->session ) {
            WC()->session->set( 'order_awaiting_payment', false );
            wc_add_wp_error_notices(
                new WP_Error( 'process_payment_cancel', __( 'Payment canceled.', 'woocommerce-gateway-linepay' ) )
            );
        }
        static::$logger->error(
            'process_payment_cancel', sprintf(
                                        WC_Gateway_LINEPay_Const::LOG_TEMPLATE_PAYMENT_CANCEL, $order_id,
                                        $reserved_transaction_id
                                    )
        );

        return true;
    }
    
    /**
     * Request LINEPay's refund-api and return the result.
     *
     * @param string $requester     => const:WC_Gateway_LINEPay_Const::USER_STATUS_ADMIN|CUSTOMER
     * @param int    $order_id
     * @param string $refund_amount => wc_format_decimal()
     * @param string $reason
     *
     * @return boolean(true) |WP_Error
     */
    function process_refund_request( $requester, $order_id, $refund_amount, $reason = '' ) {
        
        $order = wc_get_order( $order_id );
        $std_refund_amount = $this->get_standardized( $refund_amount );

        if( ! $order ) {

            return new WP_Error(
                'process_refund_request',
                sprintf( __( 'Unable to find order #%s', 'woocommerce-gateway-linepay' ), $order_id ), [
                    'requester'     => $requester,
                    'order_id'      => $order_id,
                    'refund_amount' => $std_refund_amount,
                ]
            );
        }
        
        $std_amount = $this->get_standardized( $order->get_total(), $order->get_currency() );
        $transaction_id = $order->get_transaction_id();
        $order_id = $order->get_id();
        $order_status = $order->get_status();

        // If the requester is in a non-refundable state.
        if( !in_array( 'wc-' . $order_status, (array) $this->linepay_refundable_statuses[$requester], true ) ) {
            
            return new WP_Error(
                'process_refund_request',
                __( 'Unable to refund order due to its current status.', 'woocommerce-gateway-linepay' ), [
                    'requester'      => $requester,
                    'order_id'       => $order_id,
                    'transaction_id' => $transaction_id,
                    'order_status'   => $order_status,
                ]
            );
        }
        
        // Customer standard verification.
        if( WC_Gateway_LINEPay_Const::USER_STATUS_CUSTOMER === $requester ) {
            
            $std_balanced_amount = $this->get_standardized(
                $order->get_meta( '_linepay_transaction_balanced_amount' ), $order->get_currency()
            );
            // Only the transaction itself can be canceled. No refund is possible if the refund amount is different from the total.
            // 顧客只能全額退款：退款金額、交易餘額都必須等於訂單金額（不信任呼叫端傳入的金額）.
            if( $std_amount !== $std_balanced_amount || $std_amount !== $std_refund_amount ) {
                
                return new WP_Error(
                    'process_refund_request',
                    __( 'Refund amount does not match total purchase amount.', 'woocommerce-gateway-linepay' ), [
                        'requester'      => $requester,
                        'order_id'       => $order_id,
                        'transaction_id' => $transaction_id,
                        'order_status'   => $order_status,
                        'amount'         => $std_amount,
                        'refund_amount'  => $std_refund_amount,
                    ]
                );
            }
        }
        
        $result = $this->request_refund_api( $order, $transaction_id, $std_refund_amount, $requester, $reason );

        // When the customer successfully refunds, the order status is canceled.
        if( true === $result && $requester === WC_Gateway_LINEPay_Const::USER_STATUS_CUSTOMER ) {
            $order->update_status( 'cancelled' );
        }
        
        return $result;
    }
    
    /**
     * Call the refund API and store the information DB according to the result.
     * 1. Save refund information in the form of serialized array
     * 2. After refund, the balance of the transaction amount is stored in string form.
     *
     * @param WC_Order      $order_id
     * @param string        $transaction_id
     * @param number|string $refund_amount
     * @param string        $requestrer
     *
     * @return boolean(true)|WP_Error
     */
    private function request_refund_api( $order, $transaction_id, $refund_amount, $requester = null, $reason = '' ) {
        
        $order_id = $order->get_id();
        $std_refund_amount = $this->get_standardized( $refund_amount );
        
        $url = $this->get_request_url(
            WC_Gateway_LINEPay_Const::REQUEST_TYPE_REFUND, [ 'transaction_id' => $transaction_id ]
        );
        $body = [ 'refundAmount' => $std_refund_amount ];
        $info = static::execute( $this, $url, $body );
        
        // On request failure.
        if( is_wp_error( $info ) ) {
            static::$logger->error( 'request_refund_api', $info );
            return $info;
        }
        
        // Save refund transaction information.
        // 舊資料存的是 serialize 後的字串，reserve 時存的是空陣列；兩種都要能讀
        // （原本對陣列呼叫 unserialize()，PHP 8 會丟 TypeError，退款 API 成功後 meta 卻沒寫入）.
        $refund_info = maybe_unserialize( $order->get_meta( '_linepay_refund_info' ) );
        if( !is_array( $refund_info ) ) {
            $refund_info = [];
        }

        $refund_info[$info->refundTransactionId] = [
            'requester' => $requester,
            'reason'    => $reason,
            'date'      => $info->refundTransactionDate,
        ];
        $order->update_meta_data( '_linepay_refund_info', serialize( $refund_info ) );

        // Amount balance revision.
        $balanced_amount = $order->get_meta( '_linepay_transaction_balanced_amount' );
        $new_balanced_amount = $this->get_standardized(
            floatval( $balanced_amount ) - floatval( $std_refund_amount ), $order->get_currency()
        );
        $order->update_meta_data( '_linepay_transaction_balanced_amount', $new_balanced_amount );
        $order->save();
        
        return true;
    }
    
    
    /**
     * Returns whether the currency is supported.
     *
     * @param string $currency
     *
     * @return  boolean
     */
    private function is_supported_currency( $currency ) {
        return in_array( $currency, $this->linepay_supported_currencies );
    }
    
    /**
     * Returns HOST information that matches the environmental information of LINEPay Gateway.
     *
     * @return string
     */
    private function get_request_host() {
        $host = '';
        
        switch ( $this->linepay_evn_status ) {
            case WC_Gateway_LINEPay_Const::ENV_SANDBOX:
                $host = WC_Gateway_LINEPay_Const::HOST_SANDBOX;
                break;
            
            case WC_Gateway_LINEPay_Const::ENV_REAL:
            default:
                $host = WC_Gateway_LINEPay_Const::HOST_REAL;
                break;
        }
        
        return $host;
    }
    
    /**
     * Returns the uri that matches the request type.
     * If the uri contains variables, it is combined with args to create a new uri.
     *
     * @param string $type => const:WC_Gateway_LINEPay_Const::REQUEST_TYPE_RESERVE|CONFIRM|CANCEL|REFUND
     * @param array  $args
     *
     * @return string
     */
    private function get_request_uri( $type, $args ) {
        $uri = '';
        
        switch ( $type ) {
            case WC_Gateway_LINEPay_Const::REQUEST_TYPE_RESERVE:
                $uri = WC_Gateway_LINEPay_Const::URI_RESERVE;
                break;
            case WC_Gateway_LINEPay_Const::REQUEST_TYPE_CONFIRM:
                $uri = WC_Gateway_LINEPay_Const::URI_CONFIRM;
                break;
            case WC_Gateway_LINEPay_Const::REQUEST_TYPE_DETAILS:
                $uri = WC_Gateway_LINEPay_Const::URI_DETAILS;
                break;
            case WC_Gateway_LINEPay_Const::REQUEST_TYPE_REFUND:
                $uri = WC_Gateway_LINEPay_Const::URI_REFUND;
                break;
        }
        
        $new_uri = $uri;
        foreach ( $args as $key => $value ) {
            $new_uri = str_replace( '{' . $key . '}', $value, $new_uri );
        }
        
        return $new_uri;
    }
    
    /**
     * Whether to capture or not is returned according to the payment type of LINEPay.
     *
     * @return boolean
     */
    private function is_captured() {
        
        switch ( $this->linepay_payment_action ) {
            case WC_Gateway_LINEPay_Const::PAYMENT_ACTION_AUTH_CAPTURE:
                return true;
            
            case WC_Gateway_LINEPay_Const::PAYMENT_ACTION_AUTH:
            default:
                return false;
        }
    }
    
    /**
     * Returns whether LINEPay Gateway can be used.
     * -Accepted currency
     * -Input channel information
     *
     * @return boolean|WP_Error
     */
    private function is_valid_for_use() {
        
        // Return if not already used.
        if( !$this->enabled ) {
            return false;
        }
        
        // Accepted Currency.
        $cur_currency = get_woocommerce_currency();
        if( !$this->is_supported_currency( $cur_currency ) ) {
            return new WP_Error(
                'linepay_not_supported_currency', sprintf(
                '[%s] ' . __( 'Unsupported currency.', 'woocommerce-gateway-linepay' ), $cur_currency
            ),  $cur_currency
            );
        }
        
        // Channel information by usage environment.
        $channel_info = $this->get_channel_info();
        if( empty( $channel_info['channel_id'] ) || empty( $channel_info['channel_secret'] ) ) {
            
            return new WP_Error(
                'linepay_empty_channel_info', sprintf(
                                                '[%s] ' . __(
                                                    'You have not entered your channel information.',
                                                    'woocommerce-gateway-linepay'
                                                ), $this->linepay_evn_status
                                            )
            );
        }
        
        return true;
    }
    
    
    /**
     * Returns channel information that matches the environment information of LINEPay Gateway.
     *
     * @return array
     */
    private function get_channel_info() {
        return $this->linepay_channel_info[$this->linepay_evn_status];
    }
    
    /**
     * Returns the URL that matches the request type.
     *
     * @param string $type => const:WC_Gateway_LINEPay_Const::REQUEST_TYPE_RESERVE|CONFIRM|CANCEL|REFUND
     * @param array  $args
     *
     * @return string
     */
    private function get_request_url( $type, $args = [] ) {
        $host = $this->get_request_host();
        $uri = $this->get_request_uri( $type, $args );
        
        return $host . $uri;
    }
    
    public function line_write_log( $log ) {
        if( is_array( $log ) || is_object( $log ) ) {
            error_log( print_r( $log, true ) );
        }
        else {
            error_log( $log );
        }
    }
    
    /**
     * Returns the array to be transferred to reserve-api based on the order information.
     * The array contains productName and productImageUrl.
     *
     * productName
     * -1: Name of the first item
     * -2 or more: first item name + remaining items
     *
     * productImageUrl
     * -URL information of the first item
     *
     * @param WC_Order $order
     *
     * @return array
     */
    private function get_product_info( $order ) {
        $packages = [];
        $items = $order->get_items();
        $order_amount = 0;
        
        // item_lines.
        if( count( $items ) > 0 ) {
            
            $products = [];
            $total_amount = 0;
            
            $this->line_write_log( $items );
            
            $first_item = $items[array_key_first( $items )];
            $wc_product = wc_get_product( $first_item->get_product_id() );
            $this->line_write_log( $wc_product );
            $order_name = $wc_product->get_name();
            
            if( count( $items ) > 1 ) {
                $order_name = $order_name . '等共' . $order->get_item_count() . '個商品';
            }
            
            $product = [
                'id'       => $first_item->get_product_id(),
                'name'     => sanitize_text_field( $order_name ),
                'quantity' => 1,
                'price'    => $order->get_total(),
            ];
            
            // 取第一個商品的圖案.
            $thumbnail_image_urls = wp_get_attachment_image_src(
                get_post_thumbnail_id( $first_item->get_product_id() )
            );
            
            if( isset( $thumbnail_image_urls[0] ) ) {
                $product['imageUrl'] = $thumbnail_image_urls[0];
            }
            
            array_push( $products, $product );
            
            array_push(
                $packages, [
                             'id'       => 'WC-ITEMS||' . $order->get_id(),
                             'name'     => sanitize_text_field( 'WC_ITEMS' ),
                             'amount'   => $this->get_standardized( $order->get_total() ),
                             'products' => $products,
                         ]
            );
        } //end items.
        
        $this->line_write_log( $packages );
        
        return $packages;
    }
    
    
    /**
     * Returns the default logo image address of LINE Pay that fits the entered logo size.
     *
     * @param String $general_logo_size The logo size.
     *
     * @return String $logo_path
     */
    private function get_linepay_logo_path( $general_logo_size ) {
        return WC_Gateway_LINEPay_Const::RESOURCE_IMG_LOGO_OFFICIAL_PREFIX . $this->get_linepay_logo(
                $general_logo_size
            );
    }
    
    
    /**
     * Returns the name of the default logo image of LinePay that fits the entered logo size.
     *
     * @param String $general_logo_size The logo size.
     *
     * @return String $img_name
     */
    private function get_linepay_logo( $general_logo_size ) {
        
        switch ( $general_logo_size ) {
            
            case '1':
                return WC_Gateway_LINEPay_Const::RESOURCE_IMG_LOGO_OFFICIAL_1;
            case '2':
                return WC_Gateway_LINEPay_Const::RESOURCE_IMG_LOGO_OFFICIAL_2;
            case '3':
                return WC_Gateway_LINEPay_Const::RESOURCE_IMG_LOGO_OFFICIAL_3;
            case '4':
                return WC_Gateway_LINEPay_Const::RESOURCE_IMG_LOGO_OFFICIAL_4;
            case '6':
                return WC_Gateway_LINEPay_Const::RESOURCE_IMG_LOGO_OFFICIAL_6;
            case '5':
            default:
                return WC_Gateway_LINEPay_Const::RESOURCE_IMG_LOGO_OFFICIAL_5;
        }
    }
    
    /**
     * When paying with LINE Pay, the background color of the logo image is changed to transparent.
     *
     * @param String $icon_tag
     * @param String $id
     *
     * @return String $icon_tag
     */
    public function set_transparent_icon( $icon_tag, $id ) {
        
        if( 'linepay' !== $id ) {
            return $icon_tag;
        }
        
        return str_replace( '/>', 'style="background:transparent;"', $icon_tag );
    }
    
    /**
     * Returns the scale of the received currency code
     * Use BaseCurrencyCode when there is no information received
     *
     * @param string $currency_code The currency_code.
     *
     * @return number
     */
    private function get_currency_scale( $currency_code = null ) {
        
        if( null === $currency_code ) {
            $currency_code = get_woocommerce_currency();
        }
        
        $currency_code = strtoupper( $currency_code );
        
        if( in_array( $currency_code, $this->linepay_currency_scales, true ) ) {
            return $this->linepay_currency_scales[$currency_code];
        }
        else {
            // Scale of unset currency is set to 0.
            return 0;
        }
    }
    
    /**
     * Returns the number_format for the currency
     *
     * @param number|string $amount
     * @param string        $currency
     *
     * @return string
     */
    private function get_standardized( $amount, $currency = null ) {
        $scale = $this->get_currency_scale();
        
        if( is_string( $amount ) ) {
            $amount = floatval( $amount );
        }
        
        return number_format( (float) $amount, (float) $scale, '.', '' );
    }
    
    
    /**
     * Check if the scale of the $amount received based on the basic currency code is appropriate.
     *
     * @param number $amount
     * @param        $currency_code
     *
     * @return boolean
     */
    private function valid_currency_scale( $amount, $currency_code = null ) {
        return ( $this->get_currency_scale( $currency_code ) >= $this->get_amount_precision( $amount ) );
    }
    
    /**
     * Returns the decimal point accuracy of the passed $amount
     *
     * @param number $amount
     *
     * @return number
     */
    private function get_amount_precision( $amount = 0 ) {
        if( is_string( $amount ) ) {
            $amount = (float) $amount;
        }
        $strl = strlen( $amount );
        
        $strp = strpos( $amount, '.' );
        $strp = ( false !== $strp ) ? $strp + 1 : $strl;
        
        return ( $strl - $strp );
    }
    
    /**
     * Sends a request based on the transmitted information and returns the result.
     * When requesting LINEPay, create a header to be used in common.
     *
     * @param WC_Payment_Gateway $linepay_gateway
     * @param string             $url
     * @param array              $body
     * @param int                $timeout
     *
     * @return mixed|WP_Error Return info object or WP_Error
     */
    private static function execute( $linepay_gateway, $url, $body = null, $timeout = 20, $method = 'POST' ) {
        
        $channel_info = $linepay_gateway->get_channel_info();
        
        $request_time = static::generate_request_time();
        
        $request_body = '';
        if( !is_null( $body ) ) {
            if( is_array( $body ) ) {
                $request_body = wp_json_encode( $body );
            }
            else {
                $request_body = $body;
            }
        }
        
        $headers = [
            'content-type'               => 'application/json; charset=UTF-8',
            'X-LINE-ChannelId'           => $channel_info['channel_id'],
            'X-LINE-Authorization-Nonce' => $request_time,
            'X-LINE-Authorization'       => static::generate_signature(
                $channel_info['channel_secret'], $url, $request_body, $request_time
            ),
        ];
        
        $request_args = [
            'httpversion' => '1.1',
            'timeout'     => $timeout,
            'headers'     => $headers,
        ];
        
        if( is_array( $body ) ) {
            $request_args = array_merge( $request_args, [ 'body' => wp_json_encode( $body ) ] );
        }
        
        // static::$logger->error( '[request] http_request', 'url : '. $url );
        // static::$logger->error( '[request] http_request', 'http method is POST - '. json_encode( $request_args ) );
        
        if( 'POST' === $method ) {
            $response = wp_remote_post( $url, $request_args );
        }
        elseif( 'GET' === $method ) {
            $response = wp_remote_get( $url, $request_args );
        }
        
        // static::$logger->error( '[response] http_response_not_success', 'http response code is ' . $http_status . json_encode( $response) );
        
        static::$logger->info( 'before linepay_response', [
            'type'     => getType( $response ),
            'response' => print_r( $response, true ),
        ] );
        
        // maybe timeout
        if( is_wp_error( $response ) ) {
            return $response;
        }
        
        $http_status = (int) $response['response']['code'];
        if( 200 !== $http_status ) {
            
            return new WP_Error(
                '[request] http_response_not_success', 'http response code is ' . $http_status, [
                                                         'url' => $url,
                                                     ]
            );
        }
        
        $response_body = static::json_custom_decode( wp_remote_retrieve_body( $response ) );
        $linepay_return_code = $response_body->returnCode;
        
        if( '0000' !== $linepay_return_code ) {
            return new WP_Error(
                '[request] linepay_response_failure', 'linepay return code is ' . $linepay_return_code, $response_body
            );
        }
        
        return $response_body->info;
    }
    
    /**
     * Hange large integer to json's string format.
     *
     * @param String $json The json string.
     *
     * @return mixed
     */
    private static function json_custom_decode( $json ) {
        if( version_compare( PHP_VERSION, '5.4.0', '>=' ) ) {
            return json_decode( $json, false, 512, JSON_BIGINT_AS_STRING );
        }
        else {
            return json_decode( preg_replace( '/:\s?(\d{14,})/', ': "${1}"', $json ) );
        }
    }
    
    /**
     * Generate signature
     *
     * @param [type] $channel_secret
     * @param [type] $url
     * @param [type] $request_body
     * @param [type] $nonce
     *
     * @return void
     */
    private static function generate_signature( $channel_secret, $url, $request_body, $nonce ) {
        $url_path = wp_parse_url( $url, PHP_URL_PATH );
        $data = $channel_secret . $url_path . $request_body . $nonce;
        return base64_encode( hash_hmac( WC_Gateway_LINEPay_Const::AUTH_ALGRO, $data, $channel_secret, true ) );
    }
    
    private static function generate_request_time() {
        return date( WC_Gateway_LINEPay_Const::REQUEST_TIME_FORMAT ) . '' . ( explode( '.', microtime( true ) )[1] );
    }
}

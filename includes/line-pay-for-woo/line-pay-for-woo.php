<?php

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.


/**
 * Plugin updates
 * Localization
 */
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'woocommerce-gateway-linepay', false, trailingslashit( dirname( plugin_basename( __FILE__ ) ) . '/languages' ) );
	},
	20
	);

/**
 *
 * WC_Gateway_LINEPay_Handler
 * 1. woocommerce WC_Gateway_LINEPay register
 * 2. Added to be refundable on the User Account tab
 * 3. Handle callback requests
 *
 * @class WC_Gateway_LINEPay_Handler
 * @version 1.0.0
 * @author LINEPay
 */
class WC_Gateway_LINEPay_Handler {


	/**
	 * The logger object
	 *
	 * @var WC_Gateway_LINEpay_Logger
	 */
	private static $logger;

	/** @var array|false LINE Pay 閘道設定（woocommerce_linepay_settings 選項值） */
	public $linepay_settings;

	/**
	 * Constructor function
	 */
	public function __construct() {
		add_action( 'plugins_loaded', [ $this, 'init_wc_gateway_linepay_handler' ] );
	}

	/**
	 * WC_Gateway_LINEPay_Handler Initialize
	 */
	public function init_wc_gateway_linepay_handler() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) || !wc_string_to_bool( get_option( 'woocommerce_linepay_enabled' ) )) {
			return;
		}

		include_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-gateway-linepay-const.php';
		include_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-gateway-linepay-logger.php';
		include_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-gateway-linepay.php';

		add_filter( 'woocommerce_payment_gateways', [ $this, 'add_gateway' ] );
		add_filter( 'woocommerce_my_account_my_orders_title', [ $this, 'append_script_for_refund_action' ] );
		add_filter( 'woocommerce_my_account_my_orders_actions', [ $this, 'change_customer_order_action' ], 10, 2 );
		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), [ $this, 'handle_callback' ] );
		add_action( 'woocommerce_order_item_add_action_buttons', [ $this, 'linepay_refund_button_replace' ], 10, 3 );

		// linepay setting.
		$this->linepay_settings = get_option( 'woocommerce_linepay_settings' );

		// logger.
		if ( wc_string_to_bool( get_option( 'linepay_log_enabled' ) ) ) {
			$linepay_log_info = [
				'enabled' => wc_string_to_bool( get_option( 'linepay_log_enabled' ) ),
				'level'   => ( '' !== get_option( 'linepay_log_enabled' ) ) ? get_option( 'linepay_log_level' ) : WC_Gateway_LINEPay_Logger::LOG_LEVEL_NONE,
			];

			static::$logger = WC_Gateway_LINEPay_Logger::get_instance( $linepay_log_info );
		}
	}

	/**
	 * WooCommerce LINEPay payment provider
	 *
	 * @see woocommerce::filter - woocommerce_payment_gateways
	 * @param array $methods Payment methods.
	 * @return array
	 */
	public function add_gateway( $methods ) {
		if ( wc_string_to_bool( get_option( 'woocommerce_linepay_enabled' ) ) ) {
			$methods[] = 'WC_Gateway_LINEPay';
		}
		return $methods;
	}

	/**
	 * LINEPay payment provider에서 보내는 Callback 요청을 처리한다.
	 *
	 * The callback can only handle the following states:
	 * payment status   : reserved
	 * -> request type  : confirm, cancel
	 *
	 * payment status   : confirmed
	 *  -> request type : refund
	 *
	 * If it cannot be processed, an error log is left.
	 *
	 * @see woocommerce::action - woocommerce_api_
	 */
	public function handle_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- confirm／cancel 以訂單 token 驗證，refund 於 process_refund_by_customer() 驗 nonce.
		$order_id     = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$request_type = isset( $_GET['request_type'] ) ? sanitize_key( wp_unslash( $_GET['request_type'] ) ) : '';
		$token        = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		try {
			if ( empty( $order_id ) ) {
				throw new Exception( sprintf( WC_Gateway_LINEPay_Const::LOG_TEMPLATE_HANDLE_CALLBANK_NOT_FOUND_ORDER_ID, $order_id, __( 'Unable to process callback.', 'woocommerce_gateway_linepay' ) ) );
			}

			$order_obj = wc_get_order( $order_id );
			if ( ! $order_obj ) {
				throw new Exception( sprintf( WC_Gateway_LINEPay_Const::LOG_TEMPLATE_HANDLE_CALLBANK_NOT_FOUND_ORDER_ID, $order_id, __( 'Unable to process callback.', 'woocommerce_gateway_linepay' ) ) );
			}

			if ( ! Woomp_Payment_Callback_Guard::is_payment_method( $order_obj, 'linepay' ) ) {
				Woomp_Payment_Callback_Guard::reject( 'linepay', 'payment method is not linepay', [ 'order_id' => $order_id, 'request_type' => $request_type ] );
				return;
			}

			$payment_status = $order_obj->get_meta( '_linepay_payment_status' );

			$linepay_gateway = new WC_Gateway_LINEPay();

			switch ( $request_type ) {
				case WC_Gateway_LINEPay_Const::REQUEST_TYPE_CONFIRM:
				case WC_Gateway_LINEPay_Const::REQUEST_TYPE_CANCEL:
					// 公開網址：沒有 reserve 時發出的 token 一律拒絕（任何人只要知道訂單編號就能取消或提前 confirm）.
					if ( ! WC_Gateway_LINEPay::verify_callback_token( $order_obj, $token ) ) {
						Woomp_Payment_Callback_Guard::reject( 'linepay', 'invalid callback token', [ 'order_id' => $order_id, 'request_type' => $request_type ] );
						return;
					}

					if ( WC_Gateway_LINEPay_Const::PAYMENT_STATUS_RESERVED !== $payment_status ) {
						// 例如 confirm 重送：訂單已完成，直接帶回訂單頁，不改任何資料.
						self::log_error( 'handle_callback', sprintf( WC_Gateway_LINEPay_Const::LOG_TEMPLATE_HANDLE_CALLBANK_NOT_FOUND_REQUREST, $order_id, $payment_status, $request_type, 'payment is not reserved, ignore' ) );
						self::redirect_and_exit( $linepay_gateway->get_return_url( $order_obj ) );
						return;
					}

					if ( WC_Gateway_LINEPay_Const::REQUEST_TYPE_CONFIRM === $request_type ) {
						$linepay_gateway->process_payment_confirm( $order_id );
					} else {
						$linepay_gateway->process_payment_cancel( $order_id );
						// 訂單已不是待付款（cancel_payment() 拒絕）時才會回到這裡.
						self::redirect_and_exit( $linepay_gateway->get_return_url( $order_obj ) );
					}
					return;

				case WC_Gateway_LINEPay_Const::REQUEST_TYPE_REFUND:
					if ( WC_Gateway_LINEPay_Const::PAYMENT_STATUS_CONFIRMED !== $payment_status ) {
						break;
					}
					$this->process_refund_by_customer( $linepay_gateway, $order_id );
					return;
			}

			self::log_error( 'handle_callback', sprintf( WC_Gateway_LINEPay_Const::LOG_TEMPLATE_HANDLE_CALLBANK_NOT_FOUND_REQUREST, $order_id, $payment_status, $request_type, __( 'Unable to process callback.', 'woocommerce_gateway_linepay' ) ) );
		} catch ( Exception $e ) {
			// Leave error log.
			self::log_error( 'handle_callback', $e->getMessage() );
		}
	}

	/**
	 * 寫 error log；LINE Pay 自己的 logger 沒開時改寫 WC logger（原本 logger 為 null 時會 fatal）
	 *
	 * @param string $context 來源
	 * @param mixed  $message 訊息
	 */
	private static function log_error( $context, $message ) {
		if ( static::$logger ) {
			static::$logger->error( $context, $message );
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $context . ': ' . ( is_scalar( $message ) ? $message : wc_print_r( $message, true ) ), [ 'source' => 'linepay' ] );
		}
	}

	/**
	 * 顧客自助退款的 nonce action
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function get_customer_refund_nonce_action( $order_id ) {
		return 'woomp_linepay_customer_refund_' . absint( $order_id );
	}

	/**
	 * 顧客自助退款是否開放給這張訂單（設定開啟、付款方式、狀態、60 天期限）
	 *
	 * @param WC_Order $order 訂單
	 * @return bool
	 */
	public static function is_customer_refund_available( $order ) {
		$refundable_statuses = get_option( 'linepay_customer_refund' );
		if ( ! $refundable_statuses || ! is_array( $refundable_statuses ) ) {
			return false;
		}
		if ( 'linepay' !== $order->get_payment_method() ) {
			return false;
		}
		if ( ! in_array( 'wc-' . $order->get_status(), $refundable_statuses, true ) ) {
			return false;
		}
		$date_created = $order->get_date_created();
		if ( ! $date_created ) {
			return false;
		}
		$refund_expired = strtotime( $date_created->date( 'Y-m-d H:i:s' ) . ' -8 hour' ) + ( 60 * 86400 );
		return time() < $refund_expired;
	}

	/**
	 * 驗證顧客自助退款請求
	 *
	 * 必須：已登入、是訂單本人、nonce 正確、付款方式為 LINE Pay、已 confirm、仍在可退款狀態與期限內、尚未退過款。
	 *
	 * @param WC_Order|false $order 訂單
	 * @param mixed          $nonce 請求帶的 nonce
	 * @return true|WP_Error
	 */
	public static function validate_customer_refund_request( $order, $nonce ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'linepay_refund_order_not_found', 'order not found' );
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'linepay_refund_not_logged_in', 'customer refund requires login' );
		}
		$customer_id = (int) $order->get_customer_id();
		if ( 0 === $customer_id || get_current_user_id() !== $customer_id ) {
			return new WP_Error( 'linepay_refund_not_owner', 'current user is not the order customer' );
		}
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::get_customer_refund_nonce_action( $order->get_id() ) ) ) {
			return new WP_Error( 'linepay_refund_bad_nonce', 'invalid nonce' );
		}
		if ( ! Woomp_Payment_Callback_Guard::is_payment_method( $order, 'linepay' ) ) {
			return new WP_Error( 'linepay_refund_not_linepay', 'payment method is not linepay' );
		}
		if ( WC_Gateway_LINEPay_Const::PAYMENT_STATUS_CONFIRMED !== $order->get_meta( '_linepay_payment_status' ) ) {
			return new WP_Error( 'linepay_refund_not_confirmed', 'payment is not confirmed' );
		}
		if ( ! self::is_customer_refund_available( $order ) ) {
			return new WP_Error( 'linepay_refund_not_available', 'customer refund is not available for this order' );
		}
		// 顧客只能全額退款一次：已有任何退款就不再開放.
		if ( (float) $order->get_total_refunded() > 0 || (float) $order->get_remaining_refund_amount() <= 0 ) {
			return new WP_Error( 'linepay_refund_already_refunded', 'order already refunded' );
		}
		return true;
	}

	/**
	 * 回應顧客自助退款結果
	 *
	 * 「我的帳號」的取消按鈕以 jQuery AJAX 請求並讀 JSON（success / data.info）；直接開網址則導回訂單列表。
	 *
	 * @param bool   $success 是否成功
	 * @param string $message 訊息
	 */
	private function respond_customer_refund( $success, $message ) {
		$is_xhr = isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && 'xmlhttprequest' === strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) );
		if ( $is_xhr ) {
			if ( $success ) {
				wp_send_json_success();
			}
			wp_send_json_error( [ 'info' => $message ] );
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			wc_add_notice( $message, $success ? 'success' : 'error' );
		}
		self::redirect_and_exit( wc_get_account_endpoint_url( 'orders' ) );
	}

	/**
	 * 導向並結束請求
	 *
	 * wc-api 的 handler 回來後 WC 會 status_header(200) + wp_die('-1')，不 exit 的話 302 會被蓋成 200，瀏覽器不會跳轉。
	 * 測試可用 woomp_linepay_callback_exit filter 關掉 exit。
	 *
	 * @param string $url 導向網址
	 */
	private static function redirect_and_exit( $url ) {
		wp_safe_redirect( $url );
		if ( apply_filters( 'woomp_linepay_callback_exit', true ) ) {
			exit;
		}
	}

	/**
	 * Process the user's refund request.
	 *
	 * If the user requests a refund, WC_AJAX::refund_line_items() is executed when the administrator requests.
	 * Since it cannot be called first, define a new method to handle it.
	 *
	 * 先驗證身分與 nonce，退款金額一律由伺服器決定（訂單剩餘可退金額，顧客只能全額退）；
	 * 先呼叫 LINE Pay 退款 API，成功後才建立 WC 退款紀錄（不再呼叫閘道）。
	 *
	 * @see WC_AJAX::refund_line_items()
	 * @param WC_Gateway_LINEPay $linepay_gateway
	 * @param int                $order_id
	 */
	public function process_refund_by_customer( $linepay_gateway, $order_id ) {
		$order = wc_get_order( $order_id );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce 於 validate_customer_refund_request() 驗證.
		$nonce         = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$refund_reason = ( isset( $_GET['reason'] ) ) ? sanitize_text_field( wp_unslash( $_GET['reason'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$valid = self::validate_customer_refund_request( $order, $nonce );
		if ( is_wp_error( $valid ) ) {
			Woomp_Payment_Callback_Guard::reject(
				'linepay',
				'customer refund rejected: ' . $valid->get_error_code(),
				[
					'order_id' => absint( $order_id ),
					'user_id'  => get_current_user_id(),
				]
			);
			$this->respond_customer_refund( false, __( 'Unable to process refund. Please try again.', 'woocommerce_gateway_linepay' ) );
			return;
		}

		// 不信任請求帶的 cancel_amount：退款金額 = 訂單剩餘可退金額（validate 已確認尚未退過款，即訂單總額）.
		$refund_amount = wc_format_decimal( $order->get_remaining_refund_amount() );

		$line_items       = [];
		$items            = $order->get_items();
		$shipping_methods = $order->get_shipping_methods();

		// items.
		foreach ( $items as $item_id => $item ) {
			$line_tax_data          = $item['line_tax_data'];
			$line_item              = [
				'qty'          => $item['qty'],
				'refund_total' => wc_format_decimal( $item['line_total'] ),
				'refund_tax'   => $line_tax_data['total'],
			];
			$line_items[ $item_id ] = $line_item;
		}

		// shipping.
		foreach ( $shipping_methods as $shipping_id => $shipping ) {
			$line_item                  = [
				'refund_total' => wc_format_decimal( $shipping['cost'] ),
				'refund_tax'   => $shipping['taxes'],
			];
			$line_items[ $shipping_id ] = $line_item;
		}

		// 先向 LINE Pay 退款：原本先 wc_create_refund() 會讓訂單變成 refunded，
		// 之後 process_refund_request() 的狀態檢查必定失敗，LINE Pay 從未真的退款，但畫面顯示退款完成.
		$result = $linepay_gateway->process_refund_request( WC_Gateway_LINEPay_Const::USER_STATUS_CUSTOMER, $order_id, $refund_amount, $refund_reason );

		if ( true !== $result ) {
			self::log_error( 'process_refund_request_by_customer', $result );
			$this->respond_customer_refund( false, __( 'Unable to process refund. Please try again.', 'woocommerce_gateway_linepay' ) );
			return;
		}

		try {
			// LINE Pay 已退款，只建立 WC 退款紀錄，不再呼叫閘道；庫存已由取消訂單回補.
			$refund = wc_create_refund(
				[
					'amount'         => $refund_amount,
					'reason'         => $refund_reason,
					'order_id'       => $order_id,
					'line_items'     => $line_items,
					'refund_payment' => false,
					'restock_items'  => false,
				]
			);

			if ( is_wp_error( $refund ) ) {
				throw new Exception( $refund->get_error_message() );
			}

			// Item quantity return.
			// foreach ( $items as $item_id => $item ) {
			// $qty      = $item['qty'];
			// $_product = wc_get_product( $item_id );

			// if ( $_product && $_product->exists() && $_product->managing_stock() ) {
			// $old_stock = wc_stock_amount( $_product->stock );

			// $new_quantity = wc_update_product_stock( $_product, $qty, 'increase', true );

			// $order->add_order_note( sprintf( __( 'Item #%1$s stock increased from %2$s to %3$s.', 'woocommerce' ), $order_item['product_id'], $old_stock, $new_quantity ) );

			// do_action( 'woocommerce_restock_refunded_item', $_product->id, $old_stock, $new_quantity, $order );
			// }
			// }

			wc_delete_shop_order_transients( $order_id );
		} catch ( Exception $e ) {
			// 錢已退，只是 WC 紀錄沒建成：留訂單備註讓商家手動補，不回報失敗（避免顧客重複申請）.
			self::log_error( 'process_refund_by_customer', $e->getMessage() );
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note( sprintf( 'LINE Pay 已完成顧客退款 %s，但建立 WooCommerce 退款紀錄失敗：%s，請手動補登。', $refund_amount, $e->getMessage() ) );
			}
		}

		$this->respond_customer_refund( true, __( 'Refund complete.', 'woocommerce_gateway_linepay' ) );
	}

	/**
	 * Register a script file to help consumers with refund processing and a script to contain language information to be used internally.
	 * I used the woocommerce_my_account_my_orders_title filter to register only the first time when my account is loaded.
	 * Therefore, no change is made to the title.
	 *
	 * @see woocommerce::filter - woocommerce_my_account_my_orders_title
	 * @param String $title
	 * @return String
	 */
	public function append_script_for_refund_action( $title ) {

		// Registration of consumer refund processing script.
		wp_register_script( 'wc-gateway-linepay-customer-refund-action', untrailingslashit( plugins_url( '/', __FILE__ ) ) . WC_Gateway_LINEPay_Const::RESOURCE_JS_CUSTOMER_REFUND_ACTION );
		wp_enqueue_script( 'wc-gateway-linepay-customer-refund-action' );

		// Register language information to be used in script.
		$lang_process_refund = __( 'Processing refund...', 'woocommerce-gateway-linepay' );
		$lang_request_refund = __( 'Request refund for order {order_id}', 'woocommerce-gateway-linepay' );
		$lang_cancel         = __( 'Cancel', 'woocommerce-gateway-linepay' );

		$lang_script = '<script>
					function linepay_lang_pack() {
						return { \'process_refund\': \'' . $lang_process_refund . '\',
								\'request_refund\':\'' . $lang_request_refund . '\',
								\'cancel\':\'' . $lang_cancel . '\'
							};
					}
				</script>';
		echo $lang_script;

		return $title;
	}

	/**
	 *
	 * Add a user's refund action for each order in my account.
	 * You can change the user's refund status in the admin setting.
	 * Actions that can be re-purchased or canceled when Linepay payment fails.
	 *
	 * @see woocommerce:filter - woocommerce_my_account_my_orders_actions
	 * @param array    $actions
	 * @param WC_Order $order
	 * @return array
	 */
	public function change_customer_order_action( $actions, $order ) {
		$order_status   = $order->get_status();
		$payment_method = $order->get_payment_method();

		switch ( $order_status ) {
			case 'failed':
				if ( 'linepay' !== $payment_method ) {
					break;
				}

				unset( $actions['pay'] );
				unset( $actions['cancel'] );

				break;
		}

		if ( self::is_customer_refund_available( $order ) ) {
			// 退款金額由伺服器決定，網址不再帶 cancel_amount；nonce 綁定這張訂單與目前登入的使用者.
			$actions['cancel'] = [
				'url'  => esc_url_raw(
					add_query_arg(
						[
							'request_type' => WC_Gateway_LINEPay_Const::REQUEST_TYPE_REFUND,
							'order_id'     => $order->get_id(),
							'_wpnonce'     => wp_create_nonce( self::get_customer_refund_nonce_action( $order->get_id() ) ),
						],
						home_url( WC_Gateway_LINEPay_Const::URI_CALLBACK_HANDLER )
					)
				),
				'name' => __( 'Cancel', 'woocommerce-gateway-linepay' ),
			];
		}

		return $actions;
	}
	public function linepay_refund_button_replace( $order ) {
		$is_order_screen = false;

		// 傳統模式：透過 global $post 判斷
		global $post;
		if ( ! empty( $post ) && $post->post_type === 'shop_order' && strpos( $_SERVER['REQUEST_URI'], 'post.php?post=' ) !== false ) {
			$is_order_screen = true;
		}

		// HPOS 模式：透過 current_screen 判斷
		if ( ! $is_order_screen && function_exists( 'get_current_screen' ) && get_current_screen() && in_array( get_current_screen()->id, Woomp_HPOS_Helper::get_order_screen_ids(), true ) ) {
			$is_order_screen = true;
		}

		if ( ! $is_order_screen ) {
			return;
		}

		$date_created = $order->get_date_created();
		if ( ! $date_created ) {
			return;
		}

		$order_status   = $order->get_status();
		$payment_method = $order->get_payment_method();
		$refund_expired = strtotime( $date_created->date( 'Y-m-d H:i:s' ) . ' -8 hour' ) + ( 60 * 86400 );
		$output         = '';
		if ( get_option( 'linepay_customer_refund' ) && time() > $refund_expired && $payment_method === 'linepay' ) {

			$output = '
                <script>

                var replaceHtml = "<p style=\"text-align:start;\">已超過60天退款期限，無法由LinePay退款</p>";

                jQuery(function () {
                        jQuery(".refund-items").replaceWith(replaceHtml);
                    });
                </script>';

		}
		echo $output;
	}
}

$GLOBALS['wc_gateway_linepay_handler'] = new WC_Gateway_LINEPay_Handler();

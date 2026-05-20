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

		try {
			$order_id = wp_unslash( $_GET['order_id'] );
			if ( empty( $order_id ) ) {
				throw new Exception( sprintf( WC_Gateway_LINEPay_Const::LOG_TEMPLATE_HANDLE_CALLBANK_NOT_FOUND_ORDER_ID, $order_id, __( 'Unable to process callback.', 'woocommerce_gateway_linepay' ) ) );
			}

			$request_type   = wp_unslash( $_GET['request_type'] );
			$order_obj      = wc_get_order( $order_id );
			if ( ! $order_obj ) {
				throw new Exception( sprintf( WC_Gateway_LINEPay_Const::LOG_TEMPLATE_HANDLE_CALLBANK_NOT_FOUND_ORDER_ID, $order_id, __( 'Unable to process callback.', 'woocommerce_gateway_linepay' ) ) );
			}
			$payment_status = $order_obj->get_meta( '_linepay_payment_status' );

			$linepay_gateway = new WC_Gateway_LINEPay();
			if ( WC_Gateway_LINEPay_Const::PAYMENT_STATUS_RESERVED === $payment_status ) {
				switch ( $request_type ) {
					case WC_Gateway_LINEPay_Const::REQUEST_TYPE_CONFIRM:
						// static::$logger->debug( 'handle_callback', 'process_payment_confirm' );
						$linepay_gateway->process_payment_confirm( $order_id );
						break;
					case WC_Gateway_LINEPay_Const::REQUEST_TYPE_CANCEL:
						$linepay_gateway->process_payment_cancel( $order_id );
						break;
				}
			} elseif ( WC_Gateway_LINEPay_Const::PAYMENT_STATUS_CONFIRMED === $payment_status ) {
				switch ( $request_type ) {
					case WC_Gateway_LINEPay_Const::REQUEST_TYPE_REFUND:
						$this->process_refund_by_customer( $linepay_gateway, $order_id );
						break;
				}
			}

			static::$logger->error( 'handle_callback', sprintf( WC_Gateway_LINEPay_Const::LOG_TEMPLATE_HANDLE_CALLBANK_NOT_FOUND_REQUREST, $order_id, $payment_status, $request_type, __( 'Unable to process callback.', 'woocommerce_gateway_linepay' ) ) );
		} catch ( Exception $e ) {
			// Leave error log.
			static::$logger->error( 'handle_callback', $e->getMessage() );
		}
	}

	/**
	 * Process the user's refund request.
	 *
	 * If the user requests a refund, WC_AJAX::refund_line_items() is executed when the administrator requests.
	 * Since it cannot be called first, define a new method to handle it.
	 *
	 * Create a refund order to return the quantity, total amount, tax, and request a refund.
	 *
	 * @see WC_AJAX::refund_line_items()
	 * @param WC_Gateway_LINEPay $linepay_gateway
	 * @param int                $order_id
	 * @throws Exception
	 */
	public function process_refund_by_customer( $linepay_gateway, $order_id ) {
		$order         = wc_get_order( $order_id );
		$refund_amount = wc_format_decimal( sanitize_text_field( wp_unslash( $_GET['cancel_amount'] ) ) );
		$refund_reason = ( isset( $_GET['reason'] ) ) ? sanitize_text_field( wp_unslash( $_GET['reason'] ) ) : '';

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

		try {
			$refund = wc_create_refund(
				[
					'amount'     => $refund_amount,
					'reason'     => $refund_reason,
					'order_id'   => $order_id,
					'line_items' => $line_items,
				]
			);

			if ( is_wp_error( $refund ) ) {
				throw new Exception( $refund->get_error_message() );
			}

			// Refund processing
			$result = $linepay_gateway->process_refund_request( WC_Gateway_LINEPay_Const::USER_STATUS_CUSTOMER, $order_id, $refund_amount, $refund_reason );

			if ( is_wp_error( $result ) || ! $result ) {
				static::$logger->error( 'process_refund_request_by_customer', $result );
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

			wc_add_notice( __( 'Refund complete.', 'woocommerce_gateway_linepay' ) );
			wp_safe_redirect( wc_get_account_endpoint_url( 'dashboard' ) );

		} catch ( Exception $e ) {

			if ( $refund && is_a( $refund, 'WC_Order_Refund' ) ) {
				$refund->delete( true );
			}

			wc_add_wp_error_notices( new WP_Error( 'process_refund_by_customer', __( 'Unable to process refund. Please try again.', 'woocommerce_gateway_linepay' ) ) );
			// wp_send_json_error( array( 'info' => $e->getMessage() ) );
			wp_safe_redirect( wc_get_account_endpoint_url( 'dashboard' ) );
		}
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

		$refund_expired = strtotime( $order->get_date_created()->date( 'Y-m-d H:i:s' ) . ' -8 hour' ) + ( 60 * 86400 );

		if ( get_option( 'linepay_customer_refund' ) && time() < $refund_expired && $payment_method === 'linepay' ) {
			if ( in_array( 'wc-' . $order_status, get_option( 'linepay_customer_refund' ) ) ) {
				$actions['cancel'] = [
					'url'  => esc_url_raw(
						add_query_arg(
							[
								'request_type'  => WC_Gateway_LINEPay_Const::REQUEST_TYPE_REFUND,
								'order_id'      => $order->get_id(),
								'cancel_amount' => $order->get_total(),
							],
							home_url( WC_Gateway_LINEPay_Const::URI_CALLBACK_HANDLER )
						)
					),
					'name' => __( 'Cancel', 'woocommerce-gateway-linepay' ),
				];
			}
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

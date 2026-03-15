<?php
/**
 * Class PayNow_Shipping_Order_Admin file.
 *
 * @package paynow
 */

defined( 'ABSPATH' ) || exit;

/**
 * The admin related functions.
 */
class PayNow_Shipping_Order_Admin {

	/**
	 * The instance object.
	 *
	 * @var PayNow_Shipping_Order_Admin
	 */
	protected static $instance = null;

	/**
	 * Initialize the class and add hooks.
	 *
	 * @return PayNow_Shipping_Order_Admin
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();

			add_filter( 'woocommerce_admin_shipping_fields', [ self::$instance, 'paynow_shipping_cvs_fields' ], 10, 1 );
			add_filter( 'woocommerce_order_actions', [ self::$instance, 'paynow_order_actions' ] );
			add_filter( 'woocommerce_order_action_renew_paynow_logistic_no', [ PayNow_Shipping_Request::get_instance(), 'paynow_get_logistic_no' ] );
			add_filter( 'woocommerce_order_action_create_paynow_shipping_order', [ PayNow_Shipping_Request::get_instance(), 'paynow_get_logistic_no' ] );

			add_action( 'add_meta_boxes', [ 'PayNow_Shipping_Order_Meta_Box', 'add_meta_box' ], 40, 2 );

			add_action( 'woocommerce_admin_order_data_after_shipping_address', [ self::$instance, 'add_choose_cvs_btn_after_shipping_address' ] );
			add_action( 'admin_enqueue_scripts', [ self::$instance, 'paynow_admin_choose_cvs_script' ] );

		}
		return self::$instance;
	}

	/**
	 * Admin shipping fields for PayNow shipping order
	 *
	 * @param array $shipping_fields The admin shipping fields.
	 * @return array
	 */
	public static function paynow_shipping_cvs_fields( $shipping_fields ) {
		global $theorder;
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $theorder ) || ! ( $theorder instanceof \WC_Order ) ) {
			if ( isset( $_POST['post_ID'] ) ) {
				$theorder = wc_get_order( wc_clean( wp_unslash( $_POST['post_ID'] ) ) );
			} else {
				return $shipping_fields;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$shipping_method = false;

		$items_shipping = $theorder->get_items( 'shipping' );
		$items_shipping = array_shift( $items_shipping );

		if ( $items_shipping ) {
			$shipping_method = PayNow_Shipping::is_paynow_shipping_cvs( $items_shipping->get_method_id() );
		}

		if ( false !== $shipping_method ) {

			$shipping_fields['paynow_storeid']      = [
				'label' => __( 'Store ID', 'paynow-shipping' ),
				'show'  => false,
			];
			$shipping_fields['paynow_storename']    = [
				'label' => __( 'Store Name', 'paynow-shipping' ),
				'show'  => false,
			];
			$shipping_fields['paynow_storeaddress'] = [
				'label' => __( 'Store Address', 'paynow-shipping' ),
				'show'  => false,
			];

			$shipping_fields['phone'] = [
				'label' => __( 'Shipping Phone', 'paynow-shipping' ),
			];
		} elseif ( $items_shipping ) {
			if ( PayNow_Shipping::is_paynow_shipping_hd( $items_shipping->get_method_id() ) ) {
				$shipping_fields['phone'] = [
					'label' => __( 'Shipping Phone', 'paynow-shipping' ),
				];
			}
		}

		return $shipping_fields;
	}

	/**
	 * The action for get PayNow Logistic Number.
	 *
	 * @param array $order_actions The order actions.
	 * @return array
	 */
	public static function paynow_order_actions( $order_actions ) {
		global $theorder;

		foreach ( $theorder->get_items( 'shipping' ) as $item_id => $item ) {
			if ( PayNow_Shipping::is_paynow_shipping( $item->get_method_id() ) !== false ) {
				if ( ! empty( $theorder->get_meta( PayNow_Shipping_Order_Meta::LogisticNumber ) && $theorder->get_meta( PayNow_Shipping_Order_Meta::Status ) !== '1' ) ) {
					$order_actions['renew_paynow_logistic_no'] = __( 'Get new PayNow Logistic Number', 'paynow-shipping' );
				} else {
					$order_actions['create_paynow_shipping_order'] = __( 'Create PayNow Shipping Order', 'paynow-shipping' );
				}
			}
		}
		return apply_filters( 'paynow_admin_order_actions', $order_actions );
	}

	public static function add_choose_cvs_btn_after_shipping_address( $order ) {

		foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
			if ( PayNow_Shipping::is_paynow_shipping_cvs( $item->get_method_id() ) ) {
				echo '<p class="form-field form-field-wide">
				<button type="button" class="button paynow-choose-cvs">' . __( 'Change CVS store', 'paynow-shipping' ) . '</button></p><p class="form-field form-field-wide">'
				. __( 'The PayNow shipping order will be revoked and recreated after changed the cvs store.', 'paynow-shipping' ) . '
				</p>';
				return;
			}
		}
	}

	public static function paynow_admin_choose_cvs_script() {
		global $pagenow;

		// HPOS 相容：傳統模式檢查 post.php + shop_order，HPOS 模式檢查 admin.php + wc-orders 頁面
		$is_order_edit = false;
		$order         = null;

		if ( 'post.php' === $pagenow && isset( $_GET['post'] ) && 'shop_order' === get_post_type( absint( $_GET['post'] ) ) ) {
			$is_order_edit = true;
			$order         = wc_get_order( absint( $_GET['post'] ) );
		} elseif ( 'admin.php' === $pagenow && isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] && isset( $_GET['id'] ) ) {
			$is_order_edit = true;
			$order         = wc_get_order( absint( $_GET['id'] ) );
		}

		if ( $is_order_edit && $order ) {

			foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
				if ( PayNow_Shipping::is_paynow_shipping_cvs( $item->get_method_id() ) ) {
					$iv              = utf8_encode( '12345678' );
					$key             = utf8_encode( '123456789070828783123456' ); // key length = 24.
					$encrypt_apicode = openssl_encrypt( PayNow_Shipping::$apicode, 'DES-EDE3', $key, OPENSSL_ZERO_PADDING );

					$cvs_data = [
						'source'             => 'paynow_admin_choose_cvs_script',
						'methods'            => $item->get_method_id(),
						'is_paynow_cvs'      => true,
						'user_account'       => PayNow_Shipping::$user_account,
						'orderno'            => '',
						'apicode'            => $encrypt_apicode,
						'Logistic_serviceID' => $order->get_meta( PayNow_Shipping_Order_Meta::LogisticServiceId ),
						'returnUrl'          => esc_url( WC()->api_request_url( 'paynow_choose_cvs_callback' ) . '?order_id=' . $order->get_id() ),
						'ajax_url'           => PayNow_Shipping::$api_url . '/Member/Order/Choselogistics',
					];

					wp_register_script( 'wmp-admin-paynow-shipping', PAYNOW_SHIPPING_PLUGIN_URL . 'assets/js/paynow-pro-admin-choose-cvs.js', [ 'jquery' ], null, false );

					wp_localize_script(
						'wmp-admin-paynow-shipping',
						'PayNowInfo',
						[
							'postData' => $cvs_data,
						]
					);

					wp_enqueue_script( 'wmp-admin-paynow-shipping' );
					return;
				}
			}
		}
	}
}

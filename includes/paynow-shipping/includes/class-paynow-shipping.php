<?php
/**
 * PayNow_Shipping class file.
 *
 * @package paynow
 */

defined( 'ABSPATH' ) || exit;

/**
 * PayNow_Shipping main class for handling all checkout related process.
 */
class PayNow_Shipping {

	/**
	 * PayNow_Shipping instance.
	 *
	 * @var PayNow_Shipping
	 */
	private static $instance;

	/**
	 * Whether or not logging is enabled.
	 *
	 * @var boolean
	 */
	public static $log_enabled = false;

	/**
	 * WC_Logger instance.
	 *
	 * @var WC_Logger Logger instance
	 * */
	public static $log = false;

	/**
	 * PayNow shipping user account.
	 *
	 * @var string
	 */
	public static $user_account;

	/**
	 * PayNow shipping api code.
	 *
	 * @var string
	 */
	public static $apicode;

	/**
	 * Whether or not test mode is enabled.
	 *
	 * @var boolean
	 */
	public static $testmode;

	/**
	 * The API url for PayNow api calls.
	 *
	 * @var string
	 */
	public static $api_url;

	/**
	 * The shipping status update callback url.
	 *
	 * @var string
	 */
	public static $shipping_status_url;

	/**
	 * Change to this order status when product is located at sender's cvs.
	 *
	 * @var string
	 */
	public static $order_status_at_sender_cvs;

	/**
	 * Change to this order status when product is located at receiver's cvs.
	 *
	 * @var string
	 */
	public static $order_status_at_receiver_cvs;

	/**
	 * Change to this order status when product is pickuped or received.
	 *
	 * @var string
	 */
	public static $order_status_pickuped;

	/**
	 * Change to this order status when product is returned.
	 *
	 * @var string
	 */
	public static $order_status_returned;

	/**
	 * The shipping data saved in javascript array.
	 *
	 * @var array
	 */
	protected static $js_data;

	/**
	 * CVS shipping methods
	 *
	 * @var array
	 */
	public static $cvs_methods = [
		'paynow_shipping_c2c_711'                 => 'PayNow_Shipping_C2C_711',
		'paynow_shipping_c2c_family'              => 'PayNow_Shipping_C2C_Family',
		'paynow_shipping_c2c_hilife'              => 'PayNow_Shipping_C2C_Hilife',
		'paynow_shipping_b2c_711'                 => 'PayNow_Shipping_B2C_711',
		'paynow_shipping_b2c_family'              => 'PayNow_Shipping_B2C_Family',
		'paynow_shipping_b2c_711_frozen'          => 'PayNow_Shipping_B2C_711_Frozen',
		'paynow_shipping_b2c_family_frozen'       => 'PayNow_Shipping_B2C_Family_Frozen',
		'woomp_paynow_shipping_c2c_711_frozen'    => 'WOOMP_PayNow_Shipping_C2C_711_Frozen',
		'woomp_paynow_shipping_c2c_family_frozen' => 'WOOMP_PayNow_Shipping_C2C_Family_Frozen',
	];

	/**
	 * Home delivery methods (TCAT)
	 *
	 * @var array
	 */
	public static $hd_methods = [
		'paynow_shipping_hd_tcat'                    => 'PayNow_Shipping_HD_TCat',
		'woomp_paynow_shipping_hd_tcat_frozen'       => 'WOOMP_PayNow_Shipping_HD_TCat_Frozen',
		'woomp_paynow_shipping_hd_tcat_refrigerated' => 'WOOMP_PayNow_Shipping_HD_TCat_Refrigerated',
	];

	/**
	 * Class constructor. Do nothing.
	 */
	public function __construct() {
	}

	/**
	 * Class init function. Hook needed actions and filters.
	 *
	 * @return void
	 */
	public static function init() {

		self::get_instance();

		self::$log_enabled = 'yes' === get_option( 'paynow_shipping_debug_log_enabled', 'no' );

		self::$user_account = get_option( 'paynow_shipping_user_account' );
		self::$apicode      = get_option( 'paynow_shipping_api_code' );
		self::$testmode     = wc_string_to_bool( get_option( 'paynow_shipping_testmode_enabled' ) );
		self::$api_url      = ( self::$testmode ) ? 'https://testlogistic.paynow.com.tw' : 'https://logistic.paynow.com.tw';

		self::$shipping_status_url = add_query_arg( 'wc-api', 'shipping_status_callback', home_url( '/' ) );

		self::$order_status_at_sender_cvs   = get_option( 'paynow_shipping_order_status_at_sender_cvs' );
		self::$order_status_at_receiver_cvs = get_option( 'paynow_shipping_order_status_at_receiver_cvs' );
		self::$order_status_pickuped        = get_option( 'paynow_shipping_order_status_pickuped' );
		self::$order_status_returned        = get_option( 'paynow_shipping_order_status_returned' );

		// 顯示結帳欄位.
		add_filter( 'woocommerce_checkout_fields', [ self::get_instance(), 'paynow_shpping_cvs_field' ], 9999 );

		// 顯示選擇超商按鈕.
		add_action( 'woocommerce_review_order_after_shipping', [ self::get_instance(), 'paynow_after_shipping_rate' ] );

		// 設定選擇超商 API 所需要的資料.
		add_action( 'woocommerce_review_order_after_shipping', [ self::get_instance(), 'paynow_setup_shipping_info' ] );

		// 當 shipping method 改變時，回傳 cvs 資料.
		add_filter( 'woocommerce_update_order_review_fragments', [ self::get_instance(), 'shipping_choose_cvs_info' ] );

		// 將不必要的運送欄位取消.
		add_action( 'woocommerce_checkout_process', [ self::get_instance(), 'paynow_shipping_fields_validation' ] );

		// 結帳時將超商資料儲存至訂單 meta.
		add_action( 'woocommerce_checkout_create_order', [ self::get_instance(), 'paynow_save_order_shipping_meta' ], 20, 2 );

		// 在結帳頁載入 js.
		add_action( 'wp_enqueue_scripts', [ self::get_instance(), 'paynow_checkout_enqueue_scripts' ], 9 );

		// 改變地址的顯示方式.
		add_filter( 'woocommerce_order_formatted_shipping_address', [ self::get_instance(), 'paynow_raw_shipping_address' ], 10, 2 );
		add_filter( 'woocommerce_localisation_address_formats', [ self::get_instance(), 'paynow_address_format' ] );
		add_filter( 'woocommerce_formatted_address_replacements', [ self::get_instance(), 'paynow_shipping_address_replacements' ], 10, 2 );

		// add store info for google map.
		add_filter( 'woocommerce_shipping_address_map_url_parts', [ self::get_instance(), 'paynow_shipping_address_map' ], 10, 2 );

		add_action( 'woocommerce_order_details_after_order_table', [ self::get_instance(), 'paynow_shipping_detail_after_order_table' ], 10, 1 );

		add_action( 'admin_enqueue_scripts', [ self::get_instance(), 'paynow_enqueue_admin_script' ] );

		// pro function
		add_filter( 'bulk_actions-edit-shop_order', [ self::get_instance(), 'paynow_register_shipping_bulk_actions' ] );
		add_action( 'wp_ajax_paynow_pre_print_label', [ self::get_instance(), 'paynow_ajax_pre_print_label' ], 10 );
	}

	/**
	 * 顯示超商運送欄位
	 *
	 * @param array $fields Shipping fields.
	 * @return array Shipping fields
	 */
	public static function paynow_shpping_cvs_field( $fields ) {
		return get_paynow_shipping_cvs_field( $fields );
	}


	/**
	 * 顯示選擇超商按鈕。僅在結帳頁面且需要超商運送時顯示，黑貓宅配不需要
	 */
	public static function paynow_after_shipping_rate() {
		if ( WC()->session->get( 'chosen_shipping_methods' ) === null ) {
			return;
		}
		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
		$chosen_method           = strstr( $chosen_shipping_methods[0], ':', true );

		if ( is_checkout() && self::needs_cvs( $chosen_method ) ) {
			wc_get_template( 'cart/choose-cvs.php', [], '', PAYNOW_SHIPPING_TEMPLATE_DIR );
		}
	}

	/**
	 * 設定 CVS 需要的資料
	 *
	 * @return void
	 */
	public static function paynow_setup_shipping_info() {
		self::$js_data = [];

		if ( WC()->session->get( 'chosen_shipping_methods' ) === null ) {
			return;
		}

		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
		$chosen_method_id        = strstr( $chosen_shipping_methods[0], ':', true );

		$apicode = self::$apicode;
		// 手動 Zero Padding
		$block_size = 8;
		$pad        = $block_size - ( strlen($apicode) % $block_size );
		$apicode   .= str_repeat("\0", $pad);

		$iv  = utf8_encode( '12345678' );
		$key = utf8_encode( '123456789070828783123456' ); // key length = 24.

		// $encrypt_apicode = openssl_encrypt( $apicode, 'DES-EDE3', $key, OPENSSL_ZERO_PADDING );
		$encrypt_apicode = openssl_encrypt( $apicode, 'DES-EDE3-ECB', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
		$encrypt_apicode = str_replace(' ', '+', base64_encode($encrypt_apicode));

		self::$js_data['shipping_data']['methods']            = $chosen_method_id;
		self::$js_data['shipping_data']['Logistic_serviceID'] = PayNow_Shipping_Logistic_Service::get_service_id( $chosen_method_id );

		if ( self::is_paynow_shipping_cvs( $chosen_method_id ) ) {
			self::$js_data['shipping_data']['source']        = 'shipping_choose_cvs';
			self::$js_data['shipping_data']['is_paynow_cvs'] = true;
			self::$js_data['shipping_data']['user_account']  = self::$user_account;
			self::$js_data['shipping_data']['orderno']       = '';
			self::$js_data['shipping_data']['apicode']       = $encrypt_apicode;
			self::$js_data['shipping_data']['returnUrl']     = esc_url( WC()->api_request_url( 'paynow_choose_cvs_callback' ) . '?cid=' . WC()->cart->get_cart_hash() );
			self::$js_data['shipping_data']['ajax_url']      = self::$api_url . '/Member/Order/Choselogistics';
		}
	}

	/**
	 * Setup shipping info for js, please refer to /assets/js/paynow-shipping-public.js
	 *
	 * @param array $fragments The fragments array.
	 * @return array
	 */
	public static function shipping_choose_cvs_info( $fragments ) {

		if ( WC()->session->get( 'chosen_shipping_methods' ) === null ) {
			return $fragments;
		}

		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
		$chosen_method_id        = strstr( $chosen_shipping_methods[0] ?? '', ':', true );

		if ( ! empty( self::$js_data ) ) {

			$logistic_service_id     = PayNow_Shipping_Logistic_Service::get_service_id( $chosen_method_id );
			$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
			$chosen_method_id        = strstr( $chosen_shipping_methods[0], ':', true );

			if ( self::is_paynow_shipping_cvs( $chosen_method_id ) ) {
				self::$js_data['shipping_data']['Logistic_serviceID'] = $logistic_service_id;
				if ( array_key_exists( 'methods', self::$js_data['shipping_data'] ) ) {
					self::$js_data['shipping_data']['methods'] = $chosen_method_id;
				}
			} elseif ( self::is_paynow_shipping_hd( $chosen_method_id ) ) {
				self::$js_data['shipping_data']                       = [];
				self::$js_data['shipping_data']['Logistic_serviceID'] = $logistic_service_id;
				self::$js_data['shipping_data']['methods']            = $chosen_method_id;
			} else {
				self::$js_data['shipping_data'] = [];
			}

			$fragments['paynow_shipping_info'] = apply_filters( 'paynow_setup_cvs_data', self::$js_data, $chosen_shipping_methods );

		}

		return $fragments;
	}

	/**
	 * 如果運送方式需要超商資訊，檢查是否有選擇超商
	 *
	 * @return void
	 */
	public static function paynow_shipping_fields_validation() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$shipping_methods = isset( $_POST['shipping_method'] ) ? wc_clean( wp_unslash( $_POST['shipping_method'] ) ) : [];

		self::log( 'shipping method: ' . wc_print_r( $shipping_methods, true ) );
		$need_cvs       = false;
		$need_hd        = false;
		$is_fami_frozen = false;

		// 是否需要超商資訊.
		foreach ( $shipping_methods as $method ) {
			$method = strstr( $method, ':', true );
			if ( array_key_exists( $method, self::$cvs_methods ) ) {
				$need_cvs = true;
				break;
			}
		}

		// 是否需要宅配手機資訊.
		foreach ( $shipping_methods as $method ) {
			$method = strstr( $method, ':', true );
			if ( array_key_exists( $method, self::$hd_methods ) ) {
				$need_hd = true;
				break;
			}
		}

		// 是否為全家大宗冷凍.
		foreach ( $shipping_methods as $method ) {
			$method = strstr( $method, ':', true );
			if ( 'paynow_shipping_b2c_family_frozen' === $method ) {
				$is_fami_frozen = true;
				break;
			}
		}

		if ( $need_cvs && empty( wc_clean( wp_unslash( $_POST['paynow_storeid'] ) ) ) ) {
			wc_add_notice( __( 'Please select a CVS store.', 'paynow-shipping' ), 'error' );
		}

		$shipping_phone = isset( $_POST['shipping_method'] ) ? wc_clean( wp_unslash( $_POST['shipping_phone'] ) ) : '';

		if ( $need_cvs || $need_hd ) {
			if ( ! preg_match( '/^[0][1-9]{1,3}[0-9]{6,8}$/', $shipping_phone ) || strlen( $shipping_phone ) < 10 || strlen( $shipping_phone ) > 11 ) {
				wc_add_notice( __( 'Shipping Phone format is invalid', 'paynow-shipping' ), 'error' );
			}
		}

		// avoid empty country error.
		if ( empty( $_POST['shipping_country'] ) ) {
			$_POST['shipping_country'] = 'TW';
		}

		if ( $need_cvs ) {
			if ( $is_fami_frozen ) {
				add_filter( 'woocommerce_checkout_fields', [ self::get_instance(), 'setup_family_frozen_shipping_fields_requirements' ], 9999 );
			} else {
				add_filter( 'woocommerce_checkout_fields', [ self::get_instance(), 'setup_cvs_shipping_fields_requirements' ], 9999 );
			}
		} elseif ( $need_hd ) {
			add_filter( 'woocommerce_checkout_fields', [ self::get_instance(), 'setup_hd_shipping_fields_requirements' ], 9999 );
		} else {
			add_filter( 'woocommerce_checkout_fields', [ self::get_instance(), 'remove_shipping_phone_required' ], 9999 );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Remove unrequired shipping fields on checkout for Family frozen shipping methods
	 *
	 * @param array $fields The checkout shipping fields.
	 * @return array
	 */
	public static function setup_family_frozen_shipping_fields_requirements( $fields ) {
		$fields['shipping']['shipping_country']['required']   = false;
		$fields['shipping']['shipping_address_1']['required'] = false;
		$fields['shipping']['shipping_address_2']['required'] = false;
		$fields['shipping']['shipping_city']['required']      = false;
		$fields['shipping']['shipping_state']['required']     = false;
		$fields['shipping']['shipping_postcode']['required']  = false;

		$fields['shipping']['shipping_phone']['required']      = true;
		$fields['shipping']['paynow_storeid']['required']      = true;
		$fields['shipping']['paynow_storename']['required']    = true;
		$fields['shipping']['paynow_storeaddress']['required'] = true;
		$fields['shipping']['paynow_reservedno']['required']   = true;
		$fields['shipping']['paynow_shipdate']['required']     = true;
		return $fields;
	}

	/**
	 * Remove unrequired shipping fields on checkout for CVS shipping methods
	 *
	 * @param array $fields The checkout shipping fields.
	 * @return array
	 */
	public static function setup_cvs_shipping_fields_requirements( $fields ) {
		$fields['shipping']['shipping_country']['required']   = false;
		$fields['shipping']['shipping_address_1']['required'] = false;
		$fields['shipping']['shipping_address_2']['required'] = false;
		$fields['shipping']['shipping_city']['required']      = false;
		$fields['shipping']['shipping_state']['required']     = false;
		$fields['shipping']['shipping_postcode']['required']  = false;

		$fields['shipping']['shipping_phone']['required']      = true;
		$fields['shipping']['paynow_storeid']['required']      = true;
		$fields['shipping']['paynow_storename']['required']    = true;
		$fields['shipping']['paynow_storeaddress']['required'] = true;
		$fields['shipping']['paynow_reservedno']['required']   = false;
		$fields['shipping']['paynow_shipdate']['required']     = false;
		return $fields;
	}

	/**
	 * If tcat shipping, set required for shipping_phone field.
	 *
	 * @param array $fields The checkout shipping fields.
	 * @return array
	 */
	public static function setup_hd_shipping_fields_requirements( $fields ) {
		$fields['shipping']['shipping_phone']['required'] = true;
		return $fields;
	}

	/**
	 * If not CVS shipping, remove required for shipping_phone field.
	 *
	 * @param array $fields The checkout shipping fields.
	 * @return array
	 */
	public static function remove_shipping_phone_required( $fields ) {
		$fields['shipping']['shipping_phone']['required'] = false;
		return $fields;
	}

	/**
	 * 訂單成立時，儲存超商資訊
	 *
	 * @param WC_Order $order the order to save shipping info.
	 * @param array    $data the order data.
	 */
	public static function paynow_save_order_shipping_meta( $order, $data ) {

		// 如果沒有超商資訊，則不儲存.
		if ( ! empty( $data['paynow_storeid'] ) ) {

			$order->set_shipping_company( '' );
			$order->set_shipping_address_2( '' );
			$order->set_shipping_city( '' );
			$order->set_shipping_state( '' );
			$order->set_shipping_postcode( '' );

			$order->update_meta_data( PayNow_Shipping_Order_Meta::LogisticServiceId, $data['paynow_service'] ); // service id.
			$order->update_meta_data( PayNow_Shipping_Order_Meta::StoreId, $data['paynow_storeid'] );
			$order->update_meta_data( PayNow_Shipping_Order_Meta::StoreName, $data['paynow_storename'] );
			$order->update_meta_data( PayNow_Shipping_Order_Meta::StoreAddr, $data['paynow_storeaddress'] );

			// 將 shipping_address_1 設定為超商地址.
			$order->set_shipping_address_1( $data['paynow_storeaddress'] );

			do_action( 'paynow_shipping_save_cvs_order_meta', $order, $data );

		}

		// 如果是黑貓宅急便，要儲存 paynow_service = 06.
		if ( $order->has_shipping_method( 'paynow_shipping_hd_tcat' ) || $order->has_shipping_method( 'woomp_paynow_shipping_hd_tcat_refrigerated' ) || $order->has_shipping_method( 'woomp_paynow_shipping_hd_tcat_frozen' ) ) {
			$order->update_meta_data( PayNow_Shipping_Order_Meta::LogisticServiceId, PayNow_Shipping_Logistic_Service::TCAT );

			if ( $order->has_shipping_method( 'paynow_shipping_hd_tcat' ) ) {
				$order->update_meta_data( PayNow_Shipping_Order_Meta::DeliveryType, '0001' ); // 常溫.
			}

			if ( $order->has_shipping_method( 'woomp_paynow_shipping_hd_tcat_refrigerated' ) ) {
				$order->update_meta_data( PayNow_Shipping_Order_Meta::DeliveryType, '0002' ); // 冷藏.
			}

			if ( $order->has_shipping_method( 'woomp_paynow_shipping_hd_tcat_frozen' ) ) {
				$order->update_meta_data( PayNow_Shipping_Order_Meta::DeliveryType, '0003' );// 冷凍.
			}
		}

		if ( isset( $data['shipping_phone'] ) ) {
			if ( version_compare( WC_VERSION, '5.6.0', '<' ) ) {
				$order->update_meta_data( '_shipping_phone', $data['shipping_phone'] );
			}
		}

		$order->save();
	}

	/**
	 * Enqueue JS/CSS on checkout page
	 *
	 * @return void
	 */
	public static function paynow_checkout_enqueue_scripts() {

		if ( ! is_checkout() || WC()->session->get( 'chosen_shipping_methods' ) === null ) {
			return;
		}

		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
		$chosen_method_id        = strstr( $chosen_shipping_methods[0] ?? '', ':', true );

		$iv              = utf8_encode( '12345678' );
		$key             = utf8_encode( '123456789070828783123456' ); // key length = 24.
		$encrypt_apicode = openssl_encrypt( self::$apicode, 'DES-EDE3', $key, OPENSSL_ZERO_PADDING );

		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );

		// @todo need to check if selected shipping method is cvs
		$cvs_data = [
			'source'             => 'paynow_checkout_enqueue_scripts',
			'methods'            => $chosen_method_id,
			'is_paynow_cvs'      => true,
			'user_account'       => self::$user_account,
			'orderno'            => '',
			'apicode'            => $encrypt_apicode,
			'Logistic_serviceID' => PayNow_Shipping_Logistic_Service::get_service_id( $chosen_method_id ),
			'returnUrl'          => esc_url( WC()->api_request_url( 'paynow_choose_cvs_callback' ) . '?cid=' . WC()->cart->get_cart_hash() ),
			'ajax_url'           => self::$api_url . '/Member/Order/Choselogistics',
		];

		wp_register_script( 'paynow-shipping', PAYNOW_SHIPPING_PLUGIN_URL . 'assets/js/paynow-shipping-public.js', [ 'jquery' ], '1.0.12', true );
		wp_localize_script( 'paynow-shipping', 'paynow_shipping_object', $cvs_data );
		wp_enqueue_script( 'paynow-shipping' );

		wp_enqueue_style( 'paynow-shipping', PAYNOW_SHIPPING_PLUGIN_URL . 'assets/css/paynow-shipping-public.css', [], '1.0.0', 'all' );
	}

	/**
	 * Eneuque admin js
	 *
	 * @return void
	 */
	public static function paynow_enqueue_admin_script() {

		wp_enqueue_script( 'jquery-ui-dialog' );

		wp_enqueue_style( 'paynow-shipping-admin', PAYNOW_SHIPPING_PLUGIN_URL . 'assets/css/paynow-shipping-admin.css', [], '1.0.0', 'all' );

		wp_enqueue_script( 'paynow-shipping-admin', PAYNOW_SHIPPING_PLUGIN_URL . 'assets/js/paynow-shipping-admin.js', [ 'jquery' ], '1.0.0', false );
		wp_localize_script(
			'paynow-shipping-admin',
			'paynow_shipping',
			[
				'ajax_url'     => admin_url( 'admin-ajax.php' ),
				'security'     => wp_create_nonce( 'paynow-shipping-order' ),
				'translations' => [
					'shipping_create_order_failed'  => __( 'Shipping create order failed.', 'paynow-shipping' ),
					'shipping_renew_order_failed'   => __( 'Shipping renew order failed.', 'paynow-shipping' ),
					'shipping_status_update_failed' => __( 'Shipping status update failed.', 'paynow-shipping' ),
					'cancel_shipping_failed'        => __( 'Shipping order cancel failed.', 'paynow-shipping' ),
				],
			]
		);

		wp_enqueue_script( 'paynow-pro-admin', PAYNOW_SHIPPING_PLUGIN_URL . 'assets/js/paynow-pro-admin.js', [ 'jquery' ], '1.0.0', false );
		wp_localize_script(
			'paynow-pro-admin',
			'paynow_shipping_object',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'security' => wp_create_nonce( 'paynow-pro' ),
			]
		);
	}

	/**
	 * Return the raw address for paynow shipping order, non-formatted way.
	 *
	 * @param array    $raw_address The raw address.
	 * @param WC_Order $order The order.
	 * @return array
	 */
	public static function paynow_raw_shipping_address( $raw_address, $order ) {

		if ( $order->get_meta( PayNow_Shipping_Order_Meta::StoreId ) ) {

			$raw_address['paynow_storeid']      = $order->get_meta( PayNow_Shipping_Order_Meta::StoreId );
			$raw_address['paynow_storename']    = $order->get_meta( PayNow_Shipping_Order_Meta::StoreName );
			$raw_address['paynow_storeaddress'] = $order->get_meta( PayNow_Shipping_Order_Meta::StoreAddr );
			$raw_address['phone']               = self::paynow_get_shipping_phone( $order );
			$raw_address['country']             = 'PNCVS';

		} else {

			if ( self::paynow_get_shipping_phone( $order ) ) {
				$raw_address['phone'] = self::paynow_get_shipping_phone( $order );
			}

			if ( $order->get_meta( PayNow_Shipping_Order_Meta::LogisticServiceId ) === PayNow_Shipping_Logistic_Service::TCAT ) {
				$raw_address['country'] = 'PNHD';
			}
		}

		return $raw_address;
	}

	/**
	 * PayNow address format.
	 *
	 * @param array $address_formats The address formats.
	 * @return array
	 */
	public static function paynow_address_format( $address_formats ) {

		$address_formats['TW'] = "{postcode}\n{country} {state} {city}\n{address_1} {address_2}\n{company} {last_name} {first_name}";
		if ( is_admin() ) {
			// 超商.
			$address_formats['PNCVS'] = "{paynow_storename} ({paynow_storeid})\n{paynow_storeaddress}\n{last_name} {first_name}\n";
			// 宅配.
			$address_formats['PNHD'] = "{postcode}\n {state} {city}\n{address_1} {address_2}\n{company} {last_name} {first_name}\n";
		} else {
			// 超商.
			$address_formats['PNCVS'] = "{paynow_storename} ({paynow_storeid})\n{paynow_storeaddress}\n{last_name} {first_name}\n"
			. '<p class="woocommerce-customer-details--phone">{phone}</p>';

			// 宅配.
			$address_formats['PNHD'] = "{postcode}\n {state} {city}\n{address_1} {address_2}\n{company} {last_name} {first_name}\n"
			. '<p class="woocommerce-customer-details--phone">{phone}</p>';
		}
		return $address_formats;
	}

	/**
	 * Replace address placeholders
	 *
	 * @param array $replacements The replacements.
	 * @param array $args The data to replace the placeholders.
	 * @return array
	 */
	public static function paynow_shipping_address_replacements( $replacements, $args ) {
		if ( isset( $args['paynow_storeid'] ) ) {
			if ( isset( $args['paynow_storeid'] ) ) {
				$replacements['{paynow_storeid}'] = $args['paynow_storeid'];
			}

			if ( isset( $args['paynow_storename'] ) ) {
				$replacements['{paynow_storename}'] = $args['paynow_storename'];
			}

			if ( isset( $args['paynow_storeaddress'] ) ) {
				$replacements['{paynow_storeaddress}'] = $args['paynow_storeaddress'];
			}
		}
		if ( isset( $args['phone'] ) ) {
			$replacements['{phone}'] = $args['phone'];
		}
		return $replacements;
	}

	/**
	 * Add shipping address info for google map
	 *
	 * @param array    $address The shipping address array.
	 * @param WC_Order $order The order.
	 * @return array
	 */
	public static function paynow_shipping_address_map( $address, $order ) {

		if ( $order->get_meta( PayNow_Shipping_Order_Meta::StoreName ) ) {
			$address['storename'] = $order->get_meta( PayNow_Shipping_Order_Meta::StoreName );
			unset( $address['address_2'] );
			unset( $address['country'] );
			unset( $address['city'] );
			unset( $address['state'] );
			unset( $address['postcode'] );
			unset( $address['company'] );
		}

		return $address;
	}

	/**
	 * Display shipping detail for PayNow shipping.
	 *
	 * @param WC_Order $order The order to display shipping detail.
	 * @return void
	 */
	public static function paynow_shipping_detail_after_order_table( $order ) {
		$table_title      = '<h2>' . __( 'PayNow Shipping Detail', 'paynow-shipping' ) . '</h2><table class="shop_table paynow_shipping_details"><tbody>';
		$table_html       = '';
		$shipping_methods = $order->get_items( 'shipping' );

		// TODO: put html to template php.
		foreach ( $shipping_methods as $item_id => $item ) {

			if ( self::is_paynow_shipping( $item->get_method_id() ) ) {

				if ( empty( $table_html ) ) {
					$table_html .= $table_title;
				}
				$table_html .= '<tr><td><strong>' . __( 'PayNow Logistic Number', 'paynow-shipping' ) . '</strong></td>';
				$table_html .= '<td>' . $order->get_meta( PayNow_Shipping_Order_Meta::LogisticNumber ) . '</td></tr>';

				$table_html .= '<tr><td><strong>' . __( 'Logistic Service', 'paynow-shipping' ) . '</strong></td>';
				$table_html .= '<td>' . $order->get_meta( PayNow_Shipping_Order_Meta::LogisticService ) . '</td></tr>';

				$logistic_code      = $order->get_meta( PayNow_Shipping_Order_Meta::LogisticCode );
				$logistic_code_desc = $order->get_meta( PayNow_Shipping_Order_Meta::DetailStatusDesc );
				$logistic_desc_txt  = ( ! empty( $logistic_code ) ) ? '(' . $logistic_code . ')' . $logistic_code_desc : $logistic_code_desc;
				$table_html        .= '<tr><td><strong>' . __( 'Detail Status Desc', 'paynow-shipping' ) . '</strong></td>';
				$table_html        .= '<td>' . $logistic_desc_txt . '</td></tr>';

				$table_html .= '<tr><td><strong>' . __( 'Payment NO', 'paynow-shipping' ) . '</strong></td>';

				$payment_no    = $order->get_meta( PayNow_Shipping_Order_Meta::PaymentNo );
				$validation_no = $order->get_meta( PayNow_Shipping_Order_Meta::ValidationNo );
				$service_id    = $order->get_meta( PayNow_Shipping_Order_Meta::LogisticServiceId );
				// 物流商託運編號.
				if ( PayNow_Shipping_Logistic_Service::SEVEN === $service_id || PayNow_Shipping_Logistic_Service::SEVENBULK === $service_id ) {
					$shipping_no = $payment_no . $validation_no;
				} else {
					$shipping_no = $payment_no;
				}
				$table_html .= '<td>' . $shipping_no . '</td></tr>';

				if ( self::is_paynow_shipping_cvs( $item->get_method_id() ) ) {
					$store_date  = $order->get_meta( PayNow_Shipping_Order_Meta::StoreDate );
					$table_html .= '<tr><td><strong>' . __( 'Store Date', 'paynow-shipping' ) . '</strong></td>';
					$table_html .= '<td>' . ( ( ! empty( $store_date ) ) ? $store_date : '未到店' ) . '</td></tr>';

					$store_time  = $order->get_meta( PayNow_Shipping_Order_Meta::StoreTime );
					$table_html .= '<tr><td><strong>' . __( 'Store Time', 'paynow-shipping' ) . '</strong></td>';
					$table_html .= '<td>' . ( ( ! empty( $store_time ) ) ? $store_time : '未到店' ) . '</td></tr>';
				}
			}
		}

		if ( ! empty( $table_html ) ) {
			$table_html .= '</tbody></table>';
		}

		if ( ! empty( $table_html ) ) {
			echo $table_html;
		}
	}

	/**
	 * Get the totle weight of the order
	 *
	 * @param WC_Order $order The order to get total weight.
	 * @return int
	 */
	public static function get_order_total_weight( $order ) {
		$total_weight = 0;
		foreach ( $order->get_items() as $item_id => $product_item ) {
			$quantity       = $product_item->get_quantity(); // get quantity.
			$product        = $product_item->get_product(); // get the WC_Product object.
			$product_weight = $product->get_weight(); // get the product weight.

			$total_weight += floatval( $product_weight * $quantity );
		}
		return $total_weight;
	}

	/**
	 * Check if the order need cvs.
	 *
	 * @param string $method_id The shipping method id.
	 * @return boolean
	 */
	public static function needs_cvs( $method_id ) {
		if ( substr( $method_id, 0, 19 ) === 'paynow_shipping_c2c' || substr( $method_id, 0, 19 ) === 'paynow_shipping_b2c' || substr( $method_id, 0, 25 ) === 'woomp_paynow_shipping_c2c' ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * 判斷運送方式是否為 PayNow 的超商運送(包含 C2C 和 B2C)
	 *
	 * @param string $shipping_method_id The shipping method.
	 * @return boolean
	 */
	public static function is_paynow_shipping_cvs( $shipping_method_id ) {
		foreach ( self::$cvs_methods as $method => $method_class ) {
			if ( strpos( $shipping_method_id, $method ) === 0 ) {
				return $method;
			}
		}

		return false;
	}

	/**
	 * 判斷運送方式是否為 PayNow 的運送方式
	 *
	 * @param string $shipping_method_id The shipping method id.
	 * @return boolean
	 */
	public static function is_paynow_shipping( $shipping_method_id ) {
		if ( strpos( $shipping_method_id, 'paynow_shipping' ) === 0 || strpos( $shipping_method_id, 'woomp_paynow_shipping' ) === 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * 判斷運送方式是否為 PayNow 的宅配運送(黑貓)
	 *
	 * @param string $shipping_method_id The shipping method id.
	 * @return boolean
	 */
	public static function is_paynow_shipping_hd( $shipping_method_id ) {
		foreach ( self::$hd_methods as $method => $method_class ) {
			if ( strpos( $shipping_method_id, $method ) === 0 ) {
				return $method;
			}
		}

		return false;
	}

	/**
	 * WooCommerce 5.6 支援 shipping phone
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public static function paynow_get_shipping_phone( $order ) {
		if ( version_compare( WC_VERSION, '5.6.0', '>=' ) ) {
			return $order->get_shipping_phone();
		} else {
			return $order->get_meta( '_shipping_phone' );
		}
	}

	/**
	 * Bulk print label action
	 *
	 * @param array $bulk_actions The bulk actions array.
	 * @return array
	 */
	public static function paynow_register_shipping_bulk_actions( $bulk_actions ) {
		$bulk_actions['paynow_bulk_print'] = __( 'Print Shipping Label', 'paynow-shipping' );
		return $bulk_actions;
	}

	public static function paynow_ajax_pre_print_label() {
		if ( ! isset( $_POST['orderIds'] ) ) {
			esc_html_e( 'Missing Ajax Parameter.', 'paynow-shipping' );
			wp_die();
		}
		$order_ids = wc_clean( wp_unslash( $_POST['orderIds'] ) );
		self::log( 'order_idsf:' . $order_ids );
		$order_ids_array = explode( ',', $order_ids );

		// 物流服務對應到訂單的數量
		$service_order_array = [
			PayNow_Shipping_Logistic_Service::SEVEN  => [],
			PayNow_Shipping_Logistic_Service::FAMI   => [],
			PayNow_Shipping_Logistic_Service::HILIFE => [],
			PayNow_Shipping_Logistic_Service::TCAT   => [],
		];
		foreach ( $order_ids_array as $order_id ) {
			self::log( 'order_id:' . $order_id );
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$service = $order->get_meta( PayNow_Shipping_Order_Meta::LogisticServiceId );
				self::log( 'service:' . $service );
				if ( $service && array_key_exists( $service, $service_order_array ) ) {
					$service_order_array[ $service ][] = $order_id;
				}
			}
		}

		wp_send_json( $service_order_array );
		wp_die();
	}

	/**
	 * Log method.
	 *
	 * @param string $message The message to be logged.
	 * @param string $level The log level. Optional. Default 'info'. Possible values: emergency|alert|critical|error|warning|notice|info|debug.
	 * @return void
	 */
	public static function log( $message, $level = 'info' ) {
		// 回呼被拒絕等 error / warning 不受除錯開關影響，否則事後查不到紀錄.
		if ( self::$log_enabled || in_array( $level, [ 'error', 'warning' ], true ) ) {
			if ( empty( self::$log ) ) {
				self::$log = new WC_Logger();
			}
			self::$log->log( $level, $message, [ 'source' => 'paynow-shipping' ] );
		}
	}

	/**
	 * Returns the single instance of the PayNow_Shipping object
	 *
	 * @return PayNow_Shipping
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

<?php
final class RY_ECPay_Shipping {

	public static $log_enabled = false;
	public static $log         = false;
	public static $testmode;

	public static $support_methods = [
		'ry_ecpay_shipping_cvs_711'          => 'RY_ECPay_Shipping_CVS_711',
		'ry_ecpay_shipping_cvs_711_freeze'   => 'RY_ECPay_Shipping_CVS_711_Freeze',
		'ry_ecpay_shipping_cvs_hilife'       => 'RY_ECPay_Shipping_CVS_Hilife',
		'ry_ecpay_shipping_cvs_okmart'       => 'RY_ECPay_Shipping_CVS_OKMart',
		'ry_ecpay_shipping_cvs_family'       => 'RY_ECPay_Shipping_CVS_Family',
		'ry_ecpay_shipping_home_tcat'        => 'RY_ECPay_Shipping_Home_Tcat',
		'ry_ecpay_shipping_home_tcat_freeze' => 'RY_ECPay_Shipping_Home_Tcat_Freeze',
		'ry_ecpay_shipping_home_tcat_frozen' => 'RY_ECPay_Shipping_Home_Tcat_Frozen',
		'ry_ecpay_shipping_home_post'        => 'RY_ECPay_Shipping_Home_Post',
		// 'ry_ecpay_shipping_home_ecan' => 'RY_ECPay_Shipping_Home_Ecan' // 終止服務
	];

	protected static $js_data;


	/**
	 * 訂單狀態與電郵類別的映射
	 *
	 * @var array<string, string>
	 */
	protected static $order_status_email_mapper = [
		'ry-at-cvs'      => 'RY_ECPay_Shipping_Email_Customer_CVS_Store',
		'wmp-in-transit' => 'RY_ECPay_Shipping_Email_Customer_CVS_Transporting',
		// 'wmp-in-transit' => 'RY_ECPay_Shipping_Email_Customer_CVS_Get_Remind',
		'ry-out-cvs'     => 'RY_ECPay_Shipping_Email_Customer_CVS_Get_Expired',
		'wmp-shipped'    => 'WMP_Shipped',
	];


	public static function init() {

		\add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'send_email' ], 10, 3 );

		\add_filter( 'woocommerce_email_classes', [ __CLASS__, 'add_email_class' ] );

		if ( 'yes' !== RY_WT::get_option( 'enabled_ecpay_shipping', 'no' ) ) {
			return;
		}
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/shipping/ry-base.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/abstracts/abstract-ecpay.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/shipping/ecpay/includes/ecpay-shipping-api.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/shipping/ecpay/includes/ecpay-shipping-response.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/shipping/ecpay/includes/ecpay-shipping-base.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/shipping/ecpay/ecpay-shipping-cvs-711.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/shipping/ecpay/ecpay-shipping-cvs-711-freeze.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/shipping/ecpay/ecpay-shipping-cvs-hilife.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/shipping/ecpay/ecpay-shipping-cvs-okmart.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/shipping/ecpay/ecpay-shipping-cvs-family.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/shipping/ecpay/ecpay-shipping-home-tcat.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/shipping/ecpay/ecpay-shipping-home-tcat-freeze.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/shipping/ecpay/ecpay-shipping-home-tcat-frozen.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/shipping/ecpay/ecpay-shipping-home-post.php';

		self::$log_enabled = 'yes' === RY_WT::get_option( 'ecpay_shipping_log', 'no' );

		if ( 'yes' === RY_WT::get_option( 'enabled_ecpay_shipping', 'no' ) ) {
			self::$testmode = 'yes' === RY_WT::get_option( 'ecpay_shipping_testmode', 'yes' );

			RY_ECPay_Shipping_Response::init();

			add_filter( 'woocommerce_shipping_methods', [ __CLASS__, 'add_method' ] );

			add_filter( 'woocommerce_checkout_fields', [ __CLASS__, 'add_cvs_info' ], 9999 );
			add_action( 'woocommerce_after_checkout_validation', [ __CLASS__, 'validate_cvs_store' ], 10, 2 );
			add_action( 'woocommerce_review_order_after_shipping', [ __CLASS__, 'shipping_choose_cvs' ] );
			add_filter( 'woocommerce_update_order_review_fragments', [ __CLASS__, 'shipping_choose_cvs_info' ] );
			add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'save_cvs_info' ], 20, 2 );

			if ('yes' === RY_WT::get_option('ecpay_shipping_auto_get_no', 'yes')) {
				add_action( 'woocommerce_order_status_on-hold', [ __CLASS__, 'get_code' ], 10, 2 );
				add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'get_code' ], 10, 2 );
				add_action( 'wmp_get_ecpay_cvs_code', [ 'RY_ECPay_Shipping_Api', 'get_code' ], 10, 2 );
			}

			add_filter( 'woocommerce_email_actions', [ __CLASS__, 'add_email_action' ] );
		}

		if ( is_admin() ) {
			add_filter( 'woocommerce_get_sections_rytools', [ __CLASS__, 'add_sections' ] );
			add_filter( 'woocommerce_get_settings_rytools', [ __CLASS__, 'add_setting' ], 10, 2 );
			add_action( 'woocommerce_update_options_rytools_ecpay_shipping', [ __CLASS__, 'check_option' ] );

			include_once RY_WT_PLUGIN_DIR . 'woocommerce/shipping/ecpay/includes/ecpay-shipping-admin.php';
		}
	}

	public static function log( $message, $level = 'info' ) {
		if ( self::$log_enabled ) {
			if ( empty( self::$log ) ) {
				self::$log = wc_get_logger();
			}
			self::$log->log(
				$level,
				$message,
				[
					'source'  => 'ry_ecpay_shipping',
					'_legacy' => true,
				]
			);
		}
	}

	public static function add_sections( $sections ) {
		$sections['ecpay_shipping'] = __( 'ECPay shipping options', 'ry-woocommerce-tools' );
		return $sections;
	}

	public static function add_setting( $settings, $current_section ) {
		if ( $current_section == 'ecpay_shipping' ) {
			$settings = include RY_WT_PLUGIN_DIR . 'woocommerce/shipping/ecpay/includes/settings-ecpay-shipping.php';

			if ( 'billing_only' === get_option( 'woocommerce_ship_to_destination' ) ) {
				$key                         = array_search( RY_WT::$option_prefix . 'ecpay_shipping_cvs_type', array_column( $settings, 'id' ) );
				$settings[ $key ]['options'] = [
					'disable' => _x( 'Disable', 'Cvs type', 'ry-woocommerce-tools' ),
				];
				$settings[ $key ]['desc']    = __( 'Cvs only can enable with shipping destination not force to billing address.', 'ry-woocommerce-tools' );
			}
		}
		return $settings;
	}

	public static function check_option() {
		if ( 'yes' == RY_WT::get_option( 'enabled_ecpay_shipping', 'yes' ) ) {
			$enable = true;
			$name   = RY_WT::get_option( 'ecpay_shipping_sender_name' );
			if ( mb_strwidth( $name ) < 1 || mb_strwidth( $name ) > 10 ) {
				$enable = false;
				WC_Admin_Settings::add_error( __( 'Verification failed!', 'ry-woocommerce-tools' ) . ' ' . __( 'Name length between 1 to 10 letter (5 if chinese)', 'ry-woocommerce-tools' ) );
				RY_WT::update_option( 'ecpay_shipping_sender_name', '' );
			}
			if ( ! empty( RY_WT::get_option( 'ecpay_shipping_sender_phone' ) ) ) {
				if ( 1 !== preg_match( '@^\(0\d{1,2}\)\d{6,8}(#\d+)?$@', RY_WT::get_option( 'ecpay_shipping_sender_phone' ) ) ) {
					$enable = false;
					WC_Admin_Settings::add_error( __( 'Verification failed!', 'ry-woocommerce-tools' ) . ' ' . __( 'Phone format (0x)xxxxxxx#xx', 'ry-woocommerce-tools' ) );
					RY_WT::update_option( 'ecpay_shipping_sender_phone', '' );
				}
			}
			if ( 1 !== preg_match( '@^09\d{8}?$@', RY_WT::get_option( 'ecpay_shipping_sender_cellphone' ) ) ) {
				$enable = false;
				WC_Admin_Settings::add_error( __( 'Verification failed!', 'ry-woocommerce-tools' ) . ' ' . __( 'Cellphone format 09xxxxxxxx', 'ry-woocommerce-tools' ) );
				RY_WT::update_option( 'ecpay_shipping_sender_cellphone', '' );
			}
			if ( 'yes' !== RY_WT::get_option( 'ecpay_shipping_testmode', 'yes' ) ) {
				if ( empty( RY_WT::get_option( 'ecpay_shipping_MerchantID' ) ) ) {
					$enable = false;
				}
				if ( empty( RY_WT::get_option( 'ecpay_shipping_HashKey' ) ) ) {
					$enable = false;
				}
				if ( empty( RY_WT::get_option( 'ecpay_shipping_HashIV' ) ) ) {
					$enable = false;
				}
			}
			if ( ! $enable ) {
				WC_Admin_Settings::add_error( __( 'ECPay shipping method failed to enable!', 'ry-woocommerce-tools' ) );
				RY_WT::update_option( 'ecpay_shipping', 'no' );
			}
		}
		if ( ! preg_match( '/^[a-z0-9]*$/i', RY_WT::get_option( 'ecpay_shipping_order_prefix' ) ) ) {
			WC_Admin_Settings::add_error( __( 'Order no prefix only letters and numbers allowed allowed', 'ry-woocommerce-tools' ) );
			RY_WT::update_option( 'ecpay_shipping_order_prefix', '' );
		}
	}

	public static function add_method( $shipping_methods ) {
		$shipping_methods = array_merge( $shipping_methods, self::$support_methods );

		return $shipping_methods;
	}

	public static function get_ecpay_api_info() {
		$cvs_type = RY_WT::get_option( 'ecpay_shipping_cvs_type' );
		if ( self::$testmode ) {
			if ( 'C2C' == $cvs_type ) {
				$MerchantID = '2000933';
				$HashKey    = 'XBERn1YOvpM9nfZc';
				$HashIV     = 'h1ONHk4P4yqbl5LK';
			} else {
				$MerchantID = '2000132';
				$HashKey    = '5294y06JbISpM5x9';
				$HashIV     = 'v77hoKGq4kWxNNIS';
			}
		} else {
			$MerchantID = RY_WT::get_option( 'ecpay_shipping_MerchantID' );
			$HashKey    = RY_WT::get_option( 'ecpay_shipping_HashKey' );
			$HashIV     = RY_WT::get_option( 'ecpay_shipping_HashIV' );
		}

		return [ $MerchantID, $HashKey, $HashIV, $cvs_type ];
	}

	public static function shipping_choose_cvs() {
		$chosen_shipping = wc_get_chosen_shipping_method_ids();
		$chosen_shipping = array_intersect( $chosen_shipping, array_keys( self::$support_methods ) );
		$chosen_shipping = array_shift( $chosen_shipping );
		self::$js_data   = [];

		if ( $chosen_shipping ) {
			if ( strpos( $chosen_shipping, 'cvs' ) === false ) {
				self::$js_data['ecpay_home'] = true;
			} else {
				wc_get_template( 'cart/cart-choose-cvs.php', [], '', RY_WT_PLUGIN_DIR . 'templates/' );

				list($MerchantID, $HashKey, $HashIV, $CVS_type) = self::get_ecpay_api_info();
				$method_class                                   = self::$support_methods[ $chosen_shipping ];

				self::$js_data['postData'] = [
					'MerchantID'       => $MerchantID,
					'LogisticsType'    => $method_class::$LogisticsType,
					'LogisticsSubType' => self::get_logistics_sub_type( $chosen_shipping ),
					'IsCollection'     => 'Y',
					'ServerReplyURL'   => esc_url( WC()->api_request_url( 'ry_ecpay_map_callback' ) ),
				];
			}
		}

		wp_localize_script(
			'ry-shipping',
			'ry_shipping_params',
			array_merge(
				[
					'postUrl' => RY_ECPay_Shipping_Api::get_map_post_url(),
				],
				self::$js_data
			)
		);

		wp_enqueue_script( 'ry-shipping' );
	}

	public static function shipping_choose_cvs_info( $fragments ) {
		if ( ! empty( self::$js_data ) ) {
			$fragments['ecpay_shipping_info'] = self::$js_data;
		}

		return $fragments;
	}

	public static function add_cvs_info( $fields ) {
		$fields['shipping']['LogisticsSubType'] = [
			'required' => false,
			'type'     => 'hidden',
		];
		$fields['shipping']['CVSStoreID']       = [
			'required' => false,
			'type'     => 'hidden',
		];
		$fields['shipping']['shipping_phone']   = [
			'label'    => __( 'Phone', 'ry-woocommerce-tools' ),
			'required' => true,
			'type'     => 'tel',
			'validate' => [ 'phone' ],
			'class'    => [ 'form-row-wide' ],
			'priority' => 100,
		];
		$fields['shipping']['CVSStoreName']     = [
			'label'    => __( 'Store Name', 'ry-woocommerce-tools' ),
			'required' => false,
			'type'     => 'hiddentext',
			'class'    => [ 'form-row-wide', 'cvs-info' ],
			'priority' => 110,
		];
		$fields['shipping']['CVSAddress']       = [
			'label'    => __( 'Store Address', 'ry-woocommerce-tools' ),
			'required' => false,
			'type'     => 'hiddentext',
			'class'    => [ 'form-row-wide', 'cvs-info' ],
			'priority' => 111,
		];
		$fields['shipping']['CVSTelephone']     = [
			'label'    => __( 'Store Telephone', 'ry-woocommerce-tools' ),
			'required' => false,
			'type'     => 'hiddentext',
			'class'    => [ 'form-row-wide', 'cvs-info' ],
			'priority' => 112,
		];

		if ( 'no' == RY_WT::get_option( 'keep_shipping_phone', 'no' ) ) {
			$fields['shipping']['shipping_phone']['class'][] = 'cvs-info';
		}

		if ( is_checkout() ) {
			$chosen_method = isset( WC()->session->chosen_shipping_methods ) ? WC()->session->chosen_shipping_methods : [];
			$used_cvs      = false;
			if ( count( $chosen_method ) ) {
				foreach ( $chosen_method as $method ) {
					$method = strstr( $method, ':', true );
					if ( $method && array_key_exists( $method, self::$support_methods ) && strpos( $method, 'cvs' ) !== false ) {
						$used_cvs = true;
						break;
					}
				}
			}

			if ( $used_cvs ) {
				foreach ( $fields['shipping'] as $key => $filed ) {
					if ( isset( $filed['class'] ) ) {
						if ( ! in_array( 'cvs-info', $filed['class'] ) ) {
							if ( ! in_array( $key, [ 'shipping_first_name', 'shipping_last_name', 'shipping_country', 'shipping_phone' ] ) ) {
								$fields['shipping'][ $key ]['class'][] = 'ry-hide';
							}
						}
					} elseif ( $filed['type'] != 'hidden' ) {
						$fields['shipping'][ $key ]['class'] = [ 'ry-hide' ];
					}
				}
			} else {
				$fields['shipping']['CVSStoreName']['class'][] = 'ry-hide';
				$fields['shipping']['CVSAddress']['class'][]   = 'ry-hide';
				$fields['shipping']['CVSTelephone']['class'][] = 'ry-hide';
			}
		}

		if ( RY_WT::is_checkout_submission() ) {
			$shipping_method = isset( $_POST['shipping_method'] ) ? wc_clean( wp_unslash( $_POST['shipping_method'] ) ) : [];
			$used_cvs        = '' !== self::get_chosen_cvs_method( $shipping_method );

			// 門市是否已選擇改由 validate_cvs_store() 把關，這裡不再把 CVSStoreName 設為必填（issue #136）
			if ( $used_cvs ) {
				$fields['shipping']['shipping_country']['required']   = false;
				$fields['shipping']['shipping_address_1']['required'] = false;
				$fields['shipping']['shipping_address_2']['required'] = false;
				$fields['shipping']['shipping_city']['required']      = false;
				$fields['shipping']['shipping_state']['required']     = false;
				$fields['shipping']['shipping_postcode']['required']  = false;

				$fields['shipping']['shipping_phone']['required'] = true;
			} elseif ( 'no' == RY_WT::get_option( 'keep_shipping_phone', 'no' ) ) {
				$fields['shipping']['shipping_phone']['required'] = false;
			}
		}

		return $fields;
	}

	/**
	 * 取得本次結帳選擇的綠界超商取貨物流 ID
	 *
	 * @param array|string $shipping_methods 結帳送出的物流，例如 [ 'ry_ecpay_shipping_cvs_711:3' ]
	 * @return string 物流 ID（例如 ry_ecpay_shipping_cvs_711）；未選綠界超商取貨時回傳空字串
	 */
	public static function get_chosen_cvs_method( $shipping_methods ): string {
		foreach ( (array) $shipping_methods as $method ) {
			$method    = (string) $method;
			$method_id = false !== strpos( $method, ':' ) ? strstr( $method, ':', true ) : $method;
			if ( array_key_exists( $method_id, self::$support_methods ) && false !== strpos( $method_id, 'cvs' ) ) {
				return $method_id;
			}
		}

		return '';
	}

	/**
	 * 取得物流方式對應的綠界 LogisticsSubType
	 *
	 * 送往綠界選店地圖與結帳驗證共用同一套規則，地圖回傳的 LogisticsSubType 才會一致。
	 *
	 * @param string $method_id 物流 ID（例如 ry_ecpay_shipping_cvs_711）
	 * @return string 例如 UNIMART、UNIMARTC2C
	 */
	public static function get_logistics_sub_type( string $method_id ): string {
		$method_class = self::$support_methods[ $method_id ];
		$cvs_type     = RY_WT::get_option( 'ecpay_shipping_cvs_type' );

		return $method_class::$LogisticsSubType . ( ( 'C2C' == $cvs_type ) ? 'C2C' : '' );
	}

	/**
	 * 結帳時驗證綠界超商取貨門市（issue #136）
	 *
	 * 不依賴結帳欄位的 required 設定：
	 * 1. WC 會快取結帳欄位，其他外掛若在 woocommerce_checkout_process 之前就讀取過欄位，
	 *    add_cvs_info() 動態設定的 required 不會生效。
	 * 2. 未勾選「運送到不同地址」時，WC 會略過整組 shipping 欄位驗證。
	 * WC 在此 hook 之後才決定是否建立訂單，這裡有錯誤就不會建立訂單。
	 *
	 * @param array    $data   WC_Checkout::get_posted_data() 的結果
	 * @param WP_Error $errors 結帳錯誤
	 */
	public static function validate_cvs_store( $data, $errors ) {
		$method_id = self::get_chosen_cvs_method( $data['shipping_method'] ?? [] );
		if ( '' === $method_id ) {
			return;
		}

		foreach ( [ 'CVSStoreID', 'CVSStoreName', 'CVSAddress' ] as $key ) {
			if ( '' === trim( (string) ( $data[ $key ] ?? '' ) ) ) {
				$errors->add( 'ry_ecpay_cvs_store_missing', '請選擇超商取貨門市' );
				return;
			}
		}

		// 切換超商品牌後未重新選店，門市資料會是前一家超商的
		if ( (string) ( $data['LogisticsSubType'] ?? '' ) !== self::get_logistics_sub_type( $method_id ) ) {
			$errors->add( 'ry_ecpay_cvs_store_mismatch', '門市資料與目前選擇的超商不符，請重新選擇門市' );
		}
	}

	public static function save_cvs_info( $order, $data ) {
		if ( ! empty( $data['CVSStoreID'] ) ) {
			$order->set_shipping_company( '' );
			$order->set_shipping_address_2( '' );
			$order->set_shipping_city( '' );
			$order->set_shipping_state( '' );
			$order->set_shipping_postcode( '' );

			$order->add_order_note(
				sprintf(
				/* translators: 1: Store name 2: Store ID */
					__( 'CVS store %1$s (%2$s)', 'ry-woocommerce-tools' ),
					$data['CVSStoreName'],
					$data['CVSStoreID']
				)
			);
			$order->update_meta_data( '_shipping_cvs_store_ID', $data['CVSStoreID'] );
			$order->update_meta_data( '_shipping_cvs_store_name', $data['CVSStoreName'] );
			$order->update_meta_data( '_shipping_cvs_store_address', $data['CVSAddress'] );
			$order->update_meta_data( '_shipping_cvs_store_telephone', $data['CVSTelephone'] );
			$order->set_shipping_address_1( $data['CVSAddress'] );
		}

		if ( version_compare( WC_VERSION, '5.6.0', '<' ) ) {
			if ( isset( $data['shipping_phone'] ) ) {
				$order->update_meta_data( '_shipping_phone', $data['shipping_phone'] );
			}
		}
	}

	public static function get_order_support_shipping( $items ) {
		$found_method = false;
		foreach ( self::$support_methods as $method => $method_class ) {
			if ( strpos( $items->get_method_id(), $method ) === 0 ) {
				$found_method = $method;
			}
		}

		return $found_method;
	}

	public static function get_code( $order_id, $order ) {
		$shipping_list = $order->get_meta( '_ecpay_shipping_info', true );
		if ( ! is_array( $shipping_list ) ) {
			$shipping_list = [];
		}
		if ( count( $shipping_list ) == 0 ) {
			WC()->queue()->schedule_single( time() + 3, 'wmp_get_ecpay_cvs_code', [ $order_id ], '' );
		}
	}

	public static function add_email_class( $emails ) {
		$emails['RY_ECPay_Shipping_Email_Customer_CVS_Store']        = include RY_WT_PLUGIN_DIR . 'woocommerce/emails/ecpay-shipping-customer-cvs-store.php';
		$emails['RY_ECPay_Shipping_Email_Customer_CVS_Transporting'] = include RY_WT_PLUGIN_DIR . 'woocommerce/emails/ecpay-shipping-customer-cvs-transporting.php';
		$emails['RY_ECPay_Shipping_Email_Customer_CVS_Get_Expired']  = include RY_WT_PLUGIN_DIR . 'woocommerce/emails/ecpay-shipping-customer-cvs-get-expired.php';
		$emails['RY_ECPay_Shipping_Email_Customer_CVS_Get_Remind']   = include RY_WT_PLUGIN_DIR . 'woocommerce/emails/ecpay-shipping-customer-cvs-get-remind.php';

		$emails['WMP_Shipped'] = include RY_WT_PLUGIN_DIR . 'woocommerce/emails/wmp-shipped.php';

		return $emails;
	}

	/**
	 * 發送Email
	 *
	 * @param int    $order_id 訂單ID
	 * @param string $status_from 訂單狀態從
	 * @param string $status_to 訂單狀態到
	 */
	public static function send_email( $order_id, $status_from, $status_to ): void {
		if ( isset( self::$order_status_email_mapper[ $status_to ] ) ) {
			$email_instance = \WC()->mailer()->emails[ self::$order_status_email_mapper[ $status_to ] ];

			$order = \wc_get_order( $order_id );
			$email_instance?->trigger( $order_id, $order );
		}
	}

	// 整合進好用版後這個 action 沒作用，所以改寫到 class-woomp-admin.php 裡面
	public static function add_email_action( $actions ) {
		$actions[] = 'ry_ecpay_shipping_cvs_to_store';
		$actions[] = 'ry_ecpay_shipping_cvs_to_transporting';

		return $actions;
	}
}

RY_ECPay_Shipping::init();

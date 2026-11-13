<?php
final class RY_SmilePay_Gateway {

	public static $log_enabled = false;
	public static $log         = false;

	public static function init() {
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/abstracts/abstract-smilepay.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/gateways/smilepay/includes/smilepay-gateway-api.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/gateways/smilepay/includes/smilepay-gateway-response.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/gateways/smilepay/includes/smilepay-gateway-base.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/gateways/smilepay/smilepay-gateway-credit.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/gateways/smilepay/smilepay-gateway-webatm.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/gateways/smilepay/smilepay-gateway-atm.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/gateways/smilepay/smilepay-gateway-cvs-711.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/gateways/smilepay/smilepay-gateway-cvs-fami.php';
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/gateways/smilepay/smilepay-gateway-barcode.php';

		self::$log_enabled = 'yes' === RY_WT::get_option( 'smilepay_gateway_log', 'no' );

		add_filter( 'woocommerce_get_sections_rytools', [ __CLASS__, 'add_sections' ] );
		add_filter( 'woocommerce_get_settings_rytools', [ __CLASS__, 'add_setting' ], 10, 2 );
		add_action( 'woocommerce_update_options_rytools_smilepay_gateway', [ __CLASS__, 'check_option' ] );

		if ( is_admin() ) {
		} else {
			add_action( 'woocommerce_thankyou', [ __CLASS__, 'payment_info' ], 9 );
			add_action( 'woocommerce_view_order', [ __CLASS__, 'payment_info' ], 9 );
		}

		if ( 'yes' === RY_WT::get_option( 'enabled_smilepay_gateway', 'no' ) ) {
			RY_SmilePay_Gateway_Response::init();

			add_filter( 'woocommerce_payment_gateways', [ __CLASS__, 'add_method' ] );

			add_action( 'wp_ajax_RY_SmilePay_getcode', [ __CLASS__, 'get_code' ] );
			add_action( 'wp_ajax_nopriv_RY_SmilePay_getcode', [ __CLASS__, 'get_code' ] );
			add_action( 'wp_ajax_RY_SmilePay_shipping_getcode', [ __CLASS__, 'shipping_get_code' ] );
			add_action( 'wp_ajax_nopriv_RY_SmilePay_shipping_getcode', [ __CLASS__, 'shipping_get_code' ] );
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
					'source'  => 'ry_smilepay_gateway',
					'_legacy' => true,
				]
			);
		}
	}

	public static function add_sections( $sections ) {
		$sections['smilepay_gateway'] = __( 'SmilePay gateway options', 'ry-woocommerce-tools' );

		return $sections;
	}

	public static function add_setting( $settings, $current_section ) {
		if ( $current_section == 'smilepay_gateway' ) {
			$settings = include RY_WT_PLUGIN_DIR . 'woocommerce/gateways/smilepay/includes/settings-smilepay-gateway.php';
		}
		return $settings;
	}

	public static function get_smilepay_api_info() {
		if ( 'yes' === RY_WT::get_option( 'smilepay_gateway_testmode', 'yes' ) ) {
			$Dcvc       = '107';
			$Rvg2c      = '1';
			$Verify_key = '174A02F97A95F72CE301137B3F98D128';
			$Rot_check  = '9527';
		} else {
			$Dcvc       = RY_WT::get_option( 'smilepay_gateway_Dcvc' );
			$Rvg2c      = RY_WT::get_option( 'smilepay_gateway_Rvg2c' );
			$Verify_key = RY_WT::get_option( 'smilepay_gateway_Verify_key' );
			$Rot_check  = RY_WT::get_option( 'smilepay_gateway_Rot_check' );
		}

		return [ $Dcvc, $Rvg2c, $Verify_key, $Rot_check ];
	}

	public static function check_option() {
		if ( 'yes' == RY_WT::get_option( 'smilepay_gateway', 'no' ) ) {
			if ( ! extension_loaded( 'simplexml' ) ) {
				WC_Admin_Settings::add_error( __( 'SmilePay gateway method need php simplexml extension.', 'ry-woocommerce-tools' ) );
				RY_WT::update_option( 'smilepay_gateway', 'no' );
			}

			$enable = true;
			if ( 'yes' !== RY_WT::get_option( 'smilepay_gateway_testmode', 'yes' ) ) {
				if ( empty( RY_WT::get_option( 'smilepay_gateway_Dcvc' ) ) ) {
					$enable = false;
				}
				if ( empty( RY_WT::get_option( 'smilepay_gateway_Rvg2c' ) ) ) {
					$enable = false;
				}
				if ( empty( RY_WT::get_option( 'smilepay_gateway_Verify_key' ) ) ) {
					$enable = false;
				}
				if ( empty( RY_WT::get_option( 'smilepay_gateway_Rot_check' ) ) ) {
					$enable = false;
				}
			}
			if ( ! $enable ) {
				WC_Admin_Settings::add_error( __( 'SmilePay gateway method failed to enable!', 'ry-woocommerce-tools' ) );
				RY_WT::update_option( 'smilepay_gateway', 'no' );
			}
		}
		if ( 'no' == RY_WT::get_option( 'smilepay_gateway', 'no' ) ) {
			if ( 'yes' == RY_WT::get_option( 'smilepay_shipping', 'no' ) ) {
				WC_Admin_Settings::add_error( __( 'SmilePay shipping method need enable SmilePay gateway.', 'ry-woocommerce-tools' ) );
				RY_WT::update_option( 'smilepay_shipping', 'no' );
			}
		}

		if ( ! preg_match( '/^[a-z0-9]*$/i', RY_WT::get_option( 'smilepay_gateway_order_prefix' ) ) ) {
			WC_Admin_Settings::add_error( __( 'Order no prefix only letters and numbers allowed allowed', 'ry-woocommerce-tools' ) );
			RY_WT::update_option( 'smilepay_gateway_order_prefix', '' );
		}
	}

	/**
	 * 取得 AJAX 取號請求的訂單（必須帶正確的 order key）
	 *
	 * 這兩個 AJAX 對匿名使用者開放（訂單付款頁本來就允許訪客），所以用 order key 證明是訂單擁有者；
	 * 不驗的話任何人都能用訂單 ID 拿到含 key 的訂單完成頁網址（個資），或讓訂單重新取號。
	 *
	 * @param bool $shipping 是否為選門市流程（付款方式可為 cod）
	 * @return WC_Order|null
	 */
	public static function get_ajax_order( $shipping = false ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 以 order key 驗證擁有者
		$order_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$key      = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order || '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return null;
		}

		$prefixes = $shipping ? [ 'ry_smilepay_', 'cod' ] : [ 'ry_smilepay_' ];
		if ( ! Woomp_Payment_Callback_Guard::is_payment_method( $order, $prefixes ) ) {
			return null;
		}
		return $order;
	}

	/**
	 * 結束 AJAX 取號請求
	 *
	 * @param WC_Order|null $order 訂單
	 * @param string|false  $url   取號後的網址
	 */
	protected static function finish_ajax_get_code( $order, $url ) {
		if ( ! $order ) {
			wp_die( '-1', '', [ 'response' => 403 ] );
		}
		if ( ! $url ) {
			$url = $order->get_checkout_order_received_url();
		}
		echo esc_url_raw( $url );
		wp_die();
	}

	public static function get_code() {
		$order = self::get_ajax_order();
		$url   = false;
		// 只有尚未取號的訂單才取號；其他狀態（已取號 on-hold、已付款）直接回訂單完成頁，不可重新取號降級。
		if ( $order && $order->has_status( 'pending' ) ) {
			$url = RY_SmilePay_Gateway_Api::get_code( $order );
		}
		self::finish_ajax_get_code( $order, $url );
	}

	public static function shipping_get_code() {
		$order = self::get_ajax_order( true );
		$url   = false;
		if ( $order && $order->has_status( 'pending' ) ) {
			$url = RY_SmilePay_Shipping_Api::get_csv_info( $order );
		}
		self::finish_ajax_get_code( $order, $url );
	}

	public static function payment_info( $order_id ) {
		if ( ! $order_id ) {
			return;
		}

		if ( ! $order = wc_get_order( $order_id ) ) {
			return;
		}

		switch ( $order->get_payment_method() ) {
			case 'ry_smilepay_atm':
				$template_file = 'order/order-smilepay-payment-info-atm.php';
				break;
			case 'ry_smilepay_barcode':
				$template_file = 'order/order-smilepay-payment-info-barcode.php';
				break;
			case 'ry_smilepay_cvs_711':
				$template_file = 'order/order-smilepay-payment-info-cvs-711.php';
				break;
			case 'ry_smilepay_cvs_fami':
				$template_file = 'order/order-smilepay-payment-info-cvs-fami.php';
				break;
		}

		if ( isset( $template_file ) ) {
			$args = [
				'order' => $order,
			];
			wc_get_template( $template_file, $args, '', RY_WT_PLUGIN_DIR . 'templates/' );
		}
	}

	public static function add_method( $methods ) {
		$methods[] = 'RY_SmilePay_Gateway_Credit';
		$methods[] = 'RY_SmilePay_Gateway_Webatm';
		$methods[] = 'RY_SmilePay_Gateway_Atm';
		$methods[] = 'RY_SmilePay_Gateway_Cvs_711';
		$methods[] = 'RY_SmilePay_Gateway_Cvs_Fami';
		$methods[] = 'RY_SmilePay_Gateway_Barcode';

		return $methods;
	}
}

RY_SmilePay_Gateway::init();

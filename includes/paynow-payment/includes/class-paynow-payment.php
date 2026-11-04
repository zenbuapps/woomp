<?php
/**
 * PayNow_Payment class file
 *
 * @package paynow
 */

defined( 'ABSPATH' ) || exit;

/**
 * PayNow_Payment main class for handling all checkout related process.
 */
class PayNow_Payment {

	/**
	 * Class instance
	 *
	 * @var PayNow_Payment
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
	 * Suppoeted payment gateways
	 *
	 * @var array
	 * */
	public static $allowed_payments;

	/**
	 * Constructor
	 */
	public function __construct() {
		// do nothing.
	}

	/**
	 * Initialize class and add hooks
	 *
	 * @return void
	 */
	public static function init() {

		self::get_instance();

		self::$log_enabled = 'yes' === get_option( 'paynow_payment_debug_log_enabled', 'no' );

		require_once PAYNOW_PLUGIN_DIR . 'includes/utils/class-paynow-pay-type.php';
		require_once PAYNOW_PLUGIN_DIR . 'includes/gateways/abstract-paynow-payment.php';
		require_once PAYNOW_PLUGIN_DIR . 'includes/gateways/class-paynow-payment-request.php';
		require_once PAYNOW_PLUGIN_DIR . 'includes/gateways/class-paynow-payment-response.php';
		require_once PAYNOW_PLUGIN_DIR . 'includes/gateways/class-paynow-payment-credit.php';
		require_once PAYNOW_PLUGIN_DIR . 'includes/gateways/class-paynow-payment-barcode.php';
		require_once PAYNOW_PLUGIN_DIR . 'includes/gateways/class-paynow-payment-ibon.php';
		require_once PAYNOW_PLUGIN_DIR . 'includes/gateways/class-paynow-payment-virtual-account.php';
		require_once PAYNOW_PLUGIN_DIR . 'includes/gateways/class-paynow-payment-webatm.php';
		require_once PAYNOW_PLUGIN_DIR . 'includes/admin/meta-boxes/class-paynow-payment-order-meta-boxes.php';

		PayNow_Payment_Order_Meta_Boxes::init();
		PayNow_Payment_Response::init();

		self::$allowed_payments = [
			'paynow-credit'          => 'PayNow_Payment_Credit',
			'paynow-virtual-account' => 'PayNow_Payment_Virtual_Account',
			'paynow-webatm'          => 'PayNow_Payment_WebATM',
			'paynow-ibon'            => 'PayNow_Payment_IBon',
			'paynow-barcode'         => 'PayNow_Payment_Barcode',
		];

		load_plugin_textdomain( 'paynow-payment', false, dirname( PAYNOW_BASENAME ) . '/languages/' );

		// add_filter( 'woocommerce_get_settings_pages', array( Paynow_Payment::get_instance(), 'paynow_add_settings' ), 15 );

		add_filter( 'woocommerce_payment_gateways', [ Paynow_Payment::get_instance(), 'add_paynow_payment_gateway' ] );

		add_filter( 'plugin_action_links_' . PAYNOW_BASENAME, [ Paynow_Payment::get_instance(), 'paynow_add_action_links' ] );
	}

	/**
	 * Add payment gateways
	 *
	 * @param array $methods PayNow payment gateways.
	 * @return array
	 */
	public function add_paynow_payment_gateway( $methods ) {
		$merged_methods = array_merge( $methods, self::$allowed_payments );
		return $merged_methods;
	}

	/**
	 * Plugin action links
	 *
	 * @param array $links The action links array.
	 * @return array
	 */
	public function paynow_add_action_links( $links ) {
		$setting_links = [
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=paynow' ) . '">' . __( 'General Settings', 'paynow-payment' ) . '</a>',
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout' ) . '">' . __( 'Payment Settings', 'paynow-payment' ) . '</a>',
		];
		return array_merge( $links, $setting_links );
	}

	/**
	 * Add settings tab
	 *
	 * @return WC_Settings_Tab_PayNow
	 */
	public function paynow_add_settings() {
		require_once PAYNOW_PLUGIN_DIR . 'includes/settings/class-paynow-payment-settings-tab.php';
		return new WC_Settings_Tab_PayNow();
	}

	/**
	 * Log method.
	 *
	 * @param string $message The message to be logged.
	 * @param string $level The log level. Optional. Default 'info'. Possible values: emergency|alert|critical|error|warning|notice|info|debug.
	 * @return void
	 */
	public static function log( $message, $level = 'info' ) {
		// error 等級（例如回呼驗證失敗）不受除錯開關影響，一律記錄，方便排查訂單沒有自動完成的原因.
		if ( self::$log_enabled || 'error' === $level ) {
			if ( empty( self::$log ) ) {
				self::$log = new WC_Logger();
			}
			self::$log->log( $level, $message, [ 'source' => 'paynow-payment' ] );
		}
	}

	/**
	 * Returns the single instance of the PayNow_Payment object
	 *
	 * @return PayNow_Payment
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

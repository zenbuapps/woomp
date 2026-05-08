<?php
/**
 * PayNow_Abstract_Payment_Gateway class file
 *
 * @package paynow
 */

defined( 'ABSPATH' ) || exit;

/**
 * PayNow_Payment main class for handling all checkout related process.
 */
abstract class PayNow_Abstract_Payment_Gateway extends WC_Payment_Gateway {


	/**
	 *
	 * @var string
	 */
	protected $plugin_name;

	/**
	 * Plugin version
	 *
	 * @var string
	 */
	protected $version;

	/**
	 * Web NO
	 *
	 * @var string
	 */
	protected $web_no;

	/**
	 * Trans Password
	 *
	 * @var string
	 */
	protected $trans_pwd;

	/**
	 * Pay Type
	 *
	 * @var string
	 */
	protected $pay_type;

	/**
	 * Merchant Name
	 *
	 * @var string
	 */
	protected $merchant_name;

	/**
	 * Test mode
	 *
	 * @var boolean
	 */
	protected $testmode;

	/**
	 * API url
	 *
	 * @var string
	 */
	protected $api_url;

	/**
	 * Constructor
	 */
	public function __construct() {

		$this->icon              = $this->get_icon();
		$this->has_fields        = false;
		$this->order_button_text = __( 'Proceed to PayNow', 'paynow-payment' );
		$this->supports          = [
			'products',
		];

		$this->web_no        = strtoupper( get_option( 'paynow_payment_web_no' ) );
		$this->trans_pwd     = get_option( 'paynow_payment_trans_pwd' );
		$this->merchant_name = get_option( 'paynow_payment_merchant_name' ); // 要跟 paynow 會員帳號中的 網站名稱 一樣.
		$this->min_amount    = 30;

		$this->testmode = wc_string_to_bool( get_option( 'paynow_payment_testmode_enabled' ) );
		$this->api_url  = ( $this->testmode ) ? 'https://test.paynow.com.tw/service/etopm.aspx' : 'https://www.paynow.com.tw/service/etopm.aspx';

		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'paynow_payment_detail_after_order_table' ], 10, 1 );
	}

	/**
	 * Payment method settings
	 *
	 * @return void
	 */
	public function admin_options() {
		echo '<h3>' . esc_html( $this->get_method_title() ) . '</h3>';
		echo '<p>' . sprintf( __( '%1$s 是<a href="%2$s" target="_blank">台灣立吉富線上金流的支付系統</a>', 'paynow-payment' ), esc_html( $this->get_method_title() ), esc_url( 'www.paynow.com.tw' ) ) . '</p>';
		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	/**
	 * 後台訂單頁面註冊 meta box（HPOS 相容）
	 *
	 * @deprecated
	 * @param string                $post_type Post type 或 screen ID
	 * @param \WP_Post|\WC_Order $post_or_order Post 或 Order 物件
	 * @return void
	 */
	public function paynow_add_meta_boxes( $post_type, $post_or_order ) {
		$order = Woomp_HPOS_Helper::get_order( $post_or_order );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_payment_method() === $this->id ) {
			foreach ( Woomp_HPOS_Helper::get_order_screen_ids() as $screen ) {
				add_meta_box(
					'paynow-order-meta-boxes',
					__( 'PayNow Payment Detail', 'paynow-payment' ),
					[
						$this,
						'paynow_admin_meta',
					],
					$screen,
					'side',
					'default'
				);
			}
		}
	}

	/**
	 * Check if the gateway is available for use.
	 *
	 * @return bool
	 */
	public function is_available() {
		$is_available = ( 'yes' === $this->enabled );

		if ( WC()->cart && 0 < $this->get_order_total() && 0 < $this->max_amount && $this->max_amount < $this->get_order_total() ) {
			$is_available = false;
		}

		if ( WC()->cart && 0 < $this->get_order_total() && $this->min_amount > $this->get_order_total() ) {
			$is_available = false;
		}

		return $is_available;
	}

	/**
	 * Payment gateway icon output
	 *
	 * @return string
	 */
	public function get_icon() {
		$icon_html  = '';
		$icon_html .= '<img src="' . PAYNOW_PLUGIN_URL . 'paynow-logo.png " style="width: 80px; height:auto;" alt="' . __( 'Paynow Payment Gateway', 'paynow-payment' ) . '" />';
		return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
	}

	/**
	 * Build pass code for api usage.
	 *
	 * @param string $order_no The order number.
	 * @param string $total_price The order total amount.
	 * @return string
	 */
	public function build_pass_code( $order_no, $total_price ) {
		$pass_code_string = $this->web_no . $order_no . $total_price . $this->trans_pwd;
		return sha1( $pass_code_string );
	}

	/**
	 * Return payment gateway method title
	 *
	 * @return string
	 */
	public function get_method_title() {
		return $this->method_title;
	}

	/**
	 * Return PayNow web no
	 *
	 * @return string
	 */
	public function get_web_no() {
		return $this->web_no;
	}

	/**
	 * Return PayNow merchant name
	 *
	 * @return string
	 */
	public function get_merchant_name() {
		return $this->merchant_name;
	}

	/**
	 * Return PayNow payment url
	 *
	 * @return string
	 */
	public function get_api_url() {
		return $this->api_url;
	}

	/**
	 * Return PayNow payment pay type
	 *
	 * @return string
	 */
	public function get_pay_type() {
		return $this->pay_type;
	}

	/**
	 * Build items as string
	 *
	 * @param WC_Order $order The order object.
	 * @return string
	 */
	public function get_items_infos( $order ) {
		$items  = $order->get_items();
		$item_s = '';
		foreach ( $items as $item ) {
			$item_s .= $item['name'] . 'X' . $item['quantity'];
			if ( end( $items )['name'] !== $item['name'] ) {
				$item_s .= ',';
			}
		}
		$resp = ( mb_strlen( $item_s ) > 200 ) ? mb_substr( $item_s, 0, 200 ) : $item_s;
		return $resp;
	}

	/**
	 * Get plugin name
	 *
	 * @return string
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * Get plugin version
	 *
	 * @return string
	 */
	public function get_version() {
		return $this->version;
	}
}

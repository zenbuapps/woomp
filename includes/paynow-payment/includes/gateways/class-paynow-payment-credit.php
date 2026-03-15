<?php
/**
 * PayNow_Payment_Credit class file
 *
 * @package paynow
 */

defined( 'ABSPATH' ) || exit;

/**
 * PayNow_Payment_Credit class for Credit Card payment
 */
class PayNow_Payment_Credit extends PayNow_Abstract_Payment_Gateway {

	/**
	 * Constructor
	 */
	public function __construct() {

		parent::__construct();

		$this->plugin_name = 'paynow-payment-credit';
		$this->version     = '1.0.0';

		$this->id                 = 'paynow-credit';
		$this->method_title       = __( 'PayNow 信用卡付款', 'paynow-payment' );
		$this->method_description = 'PayNow 信用卡付款';

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->pay_type    = PayNow_Pay_Type::CREDIT;

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'receipt_page' ] );
	}

	/**
	 * Setup form fields for payment
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = include PAYNOW_PLUGIN_DIR . 'includes/settings/settings-paynow-payment-credit.php';
	}

	/**
	 * Process payment
	 *
	 * @param string $order_id The order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		global $woocommerce;
		$order = wc_get_order( $order_id );

		// Return thankyou redirect.
		return [
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		];
	}

	/**
	 * Redirect to paynow payment page
	 *
	 * @param WC_Order $order The order object.
	 * @return void
	 */
	public function receipt_page( $order ) {
		$request = new PayNow_Payment_Request( $this );
		$request->build_request_form( $order );
	}

	/**
	 * Order meta fields for payment
	 *
	 * @return array
	 */
	public static function order_metas() {
		return [
			'_paynow_tran_id'     => __( 'Transaction No', 'paynow-payment' ),
			'_paynow_pan_no4'     => __( 'Card Last 4 Num', 'paynow-payment' ),
			'_paynow_tran_status' => __( 'Tran Status', 'paynow-payment' ),
		];
	}

	/**
	 * Display payment detail after order table
	 *
	 * @param WC_Order $order The order object.
	 * @return void
	 */
	public function paynow_payment_detail_after_order_table( $order ) {

		if ( $order->get_payment_method() === $this->id ) {

			echo '<h2>' . esc_html( __( 'PayNow Payment Detail', 'paynow-payment' ) ) . '</h2><table class="shop_table paynow_payment_details"><tbody>';

			echo '<tr><td><strong>' . esc_html( __( 'Transaction No', 'paynow-payment' ) ) . '</strong></td>';
			echo '<td>' . esc_html( $order->get_meta( '_paynow_tran_id' ) ) . '</td></tr>';

			echo '<tr><td><strong>' . esc_html( __( 'Card Last 4 Num', 'paynow-payment' ) ) . '</strong></td>';
			echo '<td>' . esc_html( $order->get_meta( '_paynow_pan_no4' ) ) . '</td></tr>';

			$tran_status = $order->get_meta( '_paynow_tran_status' );
			echo '<tr><td><strong>' . esc_html( __( 'Trans Status', 'paynow-payment' ) ) . '</strong></td>';
			echo '<td>' . esc_html( $tran_status ) . '</td></tr>';

			if ( 'F' === $tran_status ) {
				echo '<tr><td><strong>' . esc_html( __( 'Error Description', 'paynow-payment' ) ) . '</strong></td>';
				echo '<td>' . esc_html( $order->get_meta( '_paynow_errdesc' ) ) . '</td></tr>';
			}

			echo '</tbody></table>';
		}
	}
}

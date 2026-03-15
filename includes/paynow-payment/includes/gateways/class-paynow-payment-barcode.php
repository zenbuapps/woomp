<?php
/**
 * PayNow_Payment_Barcode class file
 *
 * @package paynow
 */

defined( 'ABSPATH' ) || exit;

/**
 * PayNow_Payment_Barcode payment gateway
 */
class PayNow_Payment_Barcode extends PayNow_Abstract_Payment_Gateway {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();

		$this->plugin_name = 'paynow-payment-barcode';
		$this->version     = '1.0.0';

		$this->id                 = 'paynow-barcode';
		$this->method_title       = __( 'PayNow 超商條碼付款', 'paynow-payment' );
		$this->method_description = 'PayNow 超商條碼付款，列印繳費單前往超商繳費';

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		$this->pay_type             = PayNow_Pay_Type::BARCODE;
		$this->barcode_url          = ( $this->testmode ) ? 'https://test.paynow.com.tw/service/return_BarCode.aspx?BNO=' : 'https://paynow.com.tw/service/return_BarCode.aspx?BNO=';
		$this->view_transaction_url = ( $this->testmode ) ? 'https://test.paynow.com.tw/service/return_BarCode.aspx?BNO=%s' : 'https://paynow.com.tw/service/return_BarCode.aspx?BNO=%s';

		$this->order_result_url = add_query_arg( 'wc-api', 'paynow_payment_virtual', home_url( '/' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'receipt_page' ] );
		add_filter( 'paynow_transaction_args_' . $this->id, [ $this, 'paynow_barcode_args' ], 10, 2 );
	}

	/**
	 * Setup form fields for payment
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = include PAYNOW_PLUGIN_DIR . 'includes/settings/settings-paynow-payment-barcode.php';
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
	 * Filter payment api arguments for barcode payment
	 *
	 * @param array    $args The payment api arguments.
	 * @param WC_Order $order The order object.
	 * @return array
	 */
	public function paynow_barcode_args( $args, $order ) {
		return array_merge(
			$args,
			[
				'AtmRespost' => '1',
			]
		);
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
			'_paynow_new_date'    => __( 'New Date', 'paynow-payment' ),
			'_paynow_due_date'    => __( 'Due Date', 'paynow-payment' ),
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

			$tran_id = $order->get_meta( '_paynow_tran_id' );
			echo '<tr><td><strong>' . esc_html( __( 'Transaction No', 'paynow-payment' ) ) . '</strong></td>';
			echo '<td><a href="' . esc_html( $this->barcode_url ) . esc_html( $tran_id ) . '" target="_blank">' . esc_html( $tran_id ) . '</a></td></tr>';

			echo '<tr><td><strong>' . esc_html( __( 'New Date', 'paynow-payment' ) ) . '</strong></td>';
			echo '<td>' . esc_html( $order->get_meta( '_paynow_new_date' ) ) . '</td></tr>';

			echo '<tr><td><strong>' . esc_html( __( 'Due Date', 'paynow-payment' ) ) . '</strong></td>';
			echo '<td>' . esc_html( $order->get_meta( '_paynow_due_date' ) ) . '</td></tr>';

			echo '<tr><td><strong>' . esc_html( __( 'Tran Status', 'paynow-payment' ) ) . '</strong></td>';
			$tran_status = $order->get_meta( '_paynow_tran_status' );
			$pay_status  = ( 'F' === $tran_status ) ? __( 'Unpaid', 'paynow-payment' ) : __( 'Paid', 'paynow-payment' );
			echo '<td>' . esc_html( $pay_status ) . '</td></tr>';

			echo '</tbody></table>';
		}
	}
}

<?php
/**
 * Payuni_Payment_CVS class file
 *
 * @package payuni
 */

namespace PAYUNI\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Payuni_Payment_ATM class for Credit Card payment
 */
class Cvs extends AbstractGateway {

	/**
	 * Constructor
	 */
	public function __construct() {

		parent::__construct();

		$this->plugin_name       = 'payuni-payment-cvs';
		$this->version           = '1.0.0';
		$this->has_fields        = true;
		$this->order_button_text = __( 'PAYUNi CVS', 'woomp' );

		$this->id                 = 'payuni-cvs';
		$this->method_title       = __( 'PAYUNi CVS Payment', 'woomp' );
		$this->method_description = __( 'Pay with PAYUNi CVS', 'woomp' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title            = $this->get_option( 'title' );
		$this->description      = $this->get_option( 'description' );
		$this->api_endpoint_url = 'api/cvs';

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			[
				$this,
				'process_admin_options',
			]
		);
		add_filter( 'payuni_transaction_args_' . $this->id, [ $this, 'add_args' ], 10, 2 );
	}

	/**
	 * Setup form fields for payment
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = [
			'enabled'     => [
				'title'   => __( 'Enable/Disable', 'woocommerce' ),
				'type'    => 'checkbox',
				/* translators: %s: Gateway method title */
				'label'   => sprintf( __( 'Enable %s', 'woomp' ), $this->method_title ),
				'default' => 'no',
			],
			'title'       => [
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'default'     => $this->method_title,
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'woocommerce' ),
				'type'        => 'textarea',
				'css'         => 'width: 400px;',
				'default'     => $this->order_button_text,
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
				'desc_tip'    => true,
			],
		];
	}


	/**
	 * Filter payment api arguments for atm
	 *
	 * @param array    $args  The payment api arguments.
	 * @param WC_Order $order The order object.
	 *
	 * @return array
	 */
	public function add_args( $args, $order ) {
		$data = [
			'NotifyURL' => home_url( 'wc-api/payuni_notify_cvs' ),
		];

		return array_merge(
			$args,
			$data
		);
	}

	/**
	 * Process payment
	 *
	 * @param string $order_id The order id.
	 *
	 * @return array
	 */
	public function process_payment( $order_id ): array {

		$order = \wc_get_order( $order_id );

		if ( ! $order ) {
			return [
				'result'   => 'failure',
				'redirect' => \wc_get_cart_url(),
			];
		}

		$request = new Request( new self() );
		$resp    = $request->build_request( $order );

		return [
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		];
	}

	/**
	 * Display payment detail after order table
	 *
	 * @param WC_Order $order The order object.
	 *
	 * @return void
	 */
	public function get_detail_after_order_table( $order ) {
		if ( $order->get_payment_method() === $this->id ) {

			$status      = $order->get_meta( '_payuni_resp_status', true );
			$message     = $order->get_meta( '_payuni_resp_message', true );
			$trade_no    = $order->get_meta( '_payuni_resp_trade_no', true );
			$bank        = $order->get_meta( '_payuni_resp_bank', true );
			$bank_no     = $order->get_meta( '_payuni_resp_bank_no', true );
			$bank_expire = $order->get_meta( '_payuni_resp_bank_expire', true );

			$html = '
				<h2 class="woocommerce-order-details__title">交易明細</h2>
				<div class="responsive-table">
					<table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
						<tbody>
							<tr>
								<th>狀態碼：</th>
								<td>' . esc_html( $status ) . '</td>
							</tr>
							<tr>
								<th>交易訊息：</th>
								<td>' . esc_html( $message ) . '</td>
							</tr>
							<tr>
								<th>交易編號：</th>
								<td>' . esc_html( $trade_no ) . '</td>
							</tr>
							<tr>
								<th>轉帳銀行代碼：</th>
								<td>' . esc_html( $bank ) . '</td>
							</tr>
							<tr>
								<th>轉帳銀行帳號：</th>
								<td>' . esc_html( $bank_no ) . '</td>
							</tr>
							<tr>
								<th>轉帳期限：</th>
								<td>' . esc_html( $bank_expire ) . '</td>
							</tr>
						</tbody>
					</table>
				</div>
			';
			echo $html;
		}
	}
}

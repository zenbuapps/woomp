<?php
/**
 * Payuni_Payment_Credit class file
 *
 * @package payuni
 */

namespace PAYUNI\Gateways;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Payuni_Payment_Credit class for Credit Card payment
 */
class Credit extends AbstractGateway {


	/**
	 * Constructor
	 */
	public function __construct() {

		parent::__construct();

		$this->plugin_name = 'payuni-payment-credit';
		$this->version     = '1.0.0';
		$this->has_fields  = true;
		// $this->order_button_text = __( '統一金流 PAYUNi 信用卡', 'woomp' );

		$this->id                 = 'payuni-credit';
		$this->method_title       = __( '統一金流 PAYUNi 信用卡', 'woomp' );
		$this->method_description = __( '透過統一金流 PAYUNi 信用卡進行站內付款', 'woomp' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title            = $this->get_option( 'title' );
		$this->description      = $this->get_option( 'description' );
		$this->supports         = [ 'products', 'refunds', 'tokenization' ];
		$this->api_endpoint_url = 'api/credit';

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			[
				$this,
				'process_admin_options',
			]
		);
		add_filter( 'payuni_transaction_args_' . $this->id, [ $this, 'add_args' ], 10, 3 );
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
	 * 針對信用卡付款額外添加傳入的 API 參數
	 * 整理過後應該不用添加新的參數，所以直接 return
	 *
	 * @param array                                                                         $args  The payment api arguments.
	 * @see PAYUNI\Gateways\Request::get_transaction_args()
	 * @param WC_Order                                                                      $order The order object.
	 * @param ?array{number:string, expiry:string, cvc:string, token_id:string, new:string} $card_data 卡片資料
	 *
	 * @return array
	 */
	public function add_args( array $args, WC_Order $order, ?array $card_data ): array {
		return $args;
	}

	/**
	 * Process payment
	 *
	 * @param string $order_id The order id.
	 *
	 * @return array{result:string, redirect:string}
	 */
	public function process_payment( $order_id ): array {
		$instance  = new self();
		$card_data = $instance->get_card_data();

		$request = new Request( $instance );

		$order = \wc_get_order( $order_id );

		$result = $request->build_request( $order, $card_data );

		// 已存在相同訂單編號：先反查前次結果，未成功才開新訂單重刷（見 AbstractGateway::recover_or_copy_on_duplicate）。
		if ( 'CREDIT04001' === $result['status_code'] ) {
			$result = $this->recover_or_copy_on_duplicate( $order );
		}

		unset($result['status_code']);
		return $result;
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

			$status   = $order->get_meta( '_payuni_resp_status', true );
			$message  = $order->get_meta( '_payuni_resp_message', true );
			$trade_no = $order->get_meta( '_payuni_resp_trade_no', true );
			$card_4no = $order->get_meta( '_payuni_card_number', true );

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
								<th>卡號末四碼：</th>
								<td>' . esc_html( $card_4no ) . '</td>
							</tr>
						</tbody>
					</table>
				</div>
			';
			echo $html;
		}
	}
}

<?php
/**
 * Payuni_Payment_ATM class file
 *
 * @package payuni
 */

namespace PAYUNI\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Payuni_Payment_ATM class for Credit Card payment
 */
class Atm extends AbstractGateway {


	/**
	 * Constructor
	 */
	public function __construct() {

		parent::__construct();

		$this->plugin_name = 'payuni-payment-atm';
		$this->version     = '1.0.0';
		$this->has_fields  = true;
		// $this->order_button_text = __( 'PAYUNi ATM', 'woomp' );

		$this->id                 = 'payuni-atm';
		$this->method_title       = __( '統一金流 PAYUNi ATM', 'woomp' );
		$this->method_description = __( '透過統一金流 PAYUNi ATM 進行付款', 'woomp' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title            = $this->get_option( 'title' );
		$this->description      = $this->get_option( 'description' );
		$this->api_endpoint_url = 'api/atm';
		$this->supports         = [ 'products' ];

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			[
				$this,
				'process_admin_options',
			]
		);
		add_filter( 'payuni_transaction_args_' . $this->id, [ $this, 'add_args' ], 10, 2 );
		add_action( 'payuni_atm_check', [ $this, 'atm_expire' ], 10, 1 );
		add_action( 'woocommerce_email_order_meta', [ $this, 'get_account_info' ], 10, 3 );
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
	 * Display payment fields
	 */
	public function payment_fields() {
		$description = $this->get_description();
		if ( $description ) {
			echo wpautop( wptexturize( $description ) );
		}
		?>
		<div style="margin-top: 10px">
			<p style="margin-bottom:5px">請選擇轉帳銀行</p>
			<div class="payuni_radio">
				<select name="<?php echo esc_html( $this->id ); ?>-bank" class="select">
					<option value="004">台灣銀行</option>
					<option value="822">中信銀行</option>
					<option value="013">國泰世華</option>
				</select>
			</div>
		</div>
		<?php
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
		$data              = [];
		$data['BankType']  = (string) $order->get_meta( '_' . $this->id . '-bank' );
		$data['NotifyURL'] = home_url( 'wc-api/payuni_notify_atm' );

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

		if ( isset( $_POST[ $this->id . '-bank' ] ) && ! empty( $_POST[ $this->id . '-bank' ] ) ) {
			$order->update_meta_data( '_' . $this->id . '-bank', sanitize_text_field( $_POST[ $this->id . '-bank' ] ) );
		}

		$request = new Request( new self() );
		$result  = $request->build_request( $order );

		// 如果已存在相同訂單編號，就創立新的訂單編號
		if ( 'ATM04001' === $result['status_code'] ) {
			$new_order_id = \woomp_copy_order($order);
			$result       = $this->process_payment($new_order_id);
			/**
			* 原本是不需要這段的
			* 但如果因為統一金判斷"相同訂單編號"，我們需要創建新訂單，重跑一次 process_payment
			* 但這就不屬於 ajax 請求，不會 redirect
			* 所以這邊才需要手動作 redirect
			*
			* @see WC_Checkout::process_order_payment()
			*/
			\add_filter('wp_doing_ajax', '__return_true');
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
								<th>交易訊息：</th>
								<td>' . esc_html( $message ) . '</td>
							</tr>';

			if ( 'SUCCESS' === $status ) {
				$html .= '
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
				';
			}
			$html .= '
					</tbody>
				</table>
			</div>
			';
			echo $html;
		}
	}

	/**
	 * Set atm transfer expire check
	 *
	 * @param int $order_id The order id.
	 *
	 * @return void
	 */
	public function atm_expire( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( 'pending' === $order->get_status() ) {
			$order->update_status( 'cancelled' );
			$order->add_order_note( '<strong>統一金流繳費紀錄</strong><br>超過繳費期限，該訂單已取消！', true );
			$order->save();
		}
	}

	/**
	 * Display payment detail in email
	 *
	 * @param WC_Order $order         The order object.
	 * @param bool     $sent_to_admin Whether the email is for admin.
	 * @param bool     $plain_text    Whether the email is plain text.
	 *
	 * @return void
	 */
	public function get_account_info( $order, $sent_to_admin, $plain_text ) {
		if ( $order->get_payment_method() === $this->id && 'on-hold' === $order->get_status() && $order->get_meta( '_payuni_resp_bank', true ) ) {

			$trade_no    = $order->get_meta( '_payuni_resp_trade_no', true );
			$bank        = $order->get_meta( '_payuni_resp_bank', true );
			$bank_no     = $order->get_meta( '_payuni_resp_bank_no', true );
			$bank_expire = $order->get_meta( '_payuni_resp_bank_expire', true );

			$html = '
				<style>.payuni-account-info+.payuni-account-info{display:none}</style>
				<div style="margin-bottom:40px" class="payuni-account-info">
					<h2 class="woocommerce-order-details__title">轉帳資訊</h2>
					<div class="responsive-table">
						<table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
							<tbody>
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
				</div>
			';
			echo $html;
		}
	}
}

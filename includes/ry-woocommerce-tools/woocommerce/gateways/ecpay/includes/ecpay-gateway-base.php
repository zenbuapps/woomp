<?php
class RY_ECPay_Gateway_Base extends WC_Payment_Gateway {

	public static $log_enabled = false;
	public static $log         = false;

	public function __construct() {
		// 宣告支援 WooCommerce 後台退款：信用卡走綠界 DoAction API 同步退刷，非信用卡回人工退款指引。
		$this->supports[] = 'refunds';

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );

		if ( $this->enabled ) {
			add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'receipt_page' ] );
		}
	}

	/**
	 * WooCommerce 後台退款進入點
	 *
	 * 依付款方式分流：信用卡類（payment_type = Credit）轉呼叫綠界 DoAction 退款 API；
	 * 非信用卡（ATM／超商代碼／超商條碼／網路 ATM）綠界無線上退款 API，回傳 WP_Error 指引商家人工退款。
	 * 採 fail-closed：任何不確定或失敗一律回 WP_Error 中止，不標記已退款。
	 *
	 * @param int        $order_id 訂單 ID。
	 * @param float|null $amount   退款金額（null 表示全額）。
	 * @param string     $reason   退款原因。
	 * @return bool|WP_Error 成功回傳 true；失敗回傳 WP_Error。
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'ry_ecpay_refund_no_order', __( '找不到訂單，無法退款。', 'ry-woocommerce-tools' ) );
		}

		// 僅信用卡類（payment_type = Credit）支援綠界線上退款 API。
		if ( 'Credit' !== $this->payment_type ) {
			return new WP_Error(
				'ry_ecpay_refund_unsupported_method',
				__( '此付款方式（ATM／超商代碼／超商條碼／網路 ATM）不支援綠界線上退款 API，並非系統故障。請登入綠界廠商後台手動辦理退款，或聯繫綠界客服。', 'ry-woocommerce-tools' )
			);
		}

		return RY_ECPay_Gateway_Api::process_credit_refund( $order, $amount, $reason, $this );
	}

	public function get_icon() {
		$icon_html = '<img src="' . esc_attr( RY_WT_PLUGIN_URL . 'icon/ecpay_logo.png' ) . '" alt="' . esc_attr__( 'ECPay', 'ry-woocommerce-tools' ) . '">';

		return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
	}

	public function process_admin_options() {
		$filed_name           = 'woocommerce_' . $this->id . '_min_amount';
		$_POST[ $filed_name ] = (int) $_POST[ $filed_name ];
		if ( $_POST[ $filed_name ] < 0 ) {
			$_POST[ $filed_name ] = 0;
			WC_Admin_Settings::add_error( sprintf( __( '%s minimum amount out of range. Set as default value.', 'ry-woocommerce-tools' ), $this->method_title ) );
		}

		parent::process_admin_options();
	}

	public function receipt_page( $order_id ) {
		if ( $order = wc_get_order( $order_id ) ) {
			RY_ECPay_Gateway_Api::checkout_form( $order, $this );
			WC()->cart->empty_cart();
		}
	}
}

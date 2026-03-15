<?php
/**
 * PayNow_Payment_Order_Meta_Boxes class file
 *
 * @package paynow
 */

defined( 'ABSPATH' ) || exit;

/**
 * PayNow_Payment main class for handling all checkout related process.
 */
class PayNow_Payment_Order_Meta_Boxes {

	/**
	 * Class instance
	 *
	 * @var PayNow_Payment_Order_Meta_Boxes
	 */
	private static $instance;

	/**
	 * Constructor
	 */
	public function __construct() {
		// do nothing.
	}

	/**
	 * Initialize class andd add hooks
	 *
	 * @return void
	 */
	public static function init() {
		self::get_instance();

		add_action( 'add_meta_boxes', [ self::get_instance(), 'paynow_add_meta_boxes' ] );
	}

	/**
	 * 註冊後台訂單 meta box（HPOS 相容）
	 *
	 * @param string                $post_type Post type 或 screen ID
	 * @param \WP_Post|\WC_Order $post_or_order Post 或 Order 物件
	 * @return void
	 */
	public function paynow_add_meta_boxes( $post_type, $post_or_order ) {

		$order = Woomp_HPOS_Helper::get_order( $post_or_order );
		if ( ! $order ) {
			return;
		}

		if ( array_key_exists( $order->get_payment_method(), Paynow_Payment::$allowed_payments ) ) {
			foreach ( Woomp_HPOS_Helper::get_order_screen_ids() as $screen ) {
				add_meta_box(
					'paynow-order-meta-boxes',
					__( 'PayNow Payment Detail', 'taishin-payment' ),
					[
						self::get_instance(),
						'paynow_order_admin_meta_box',
					],
					$screen,
					'side',
					'default'
				);
			}
		}
	}

	/**
	 * Meta box 內容輸出（HPOS 相容）
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Post 或 Order 物件
	 * @return void
	 */
	public function paynow_order_admin_meta_box( $post_or_order ) {

		$order = Woomp_HPOS_Helper::get_order( $post_or_order );
		if ( ! $order ) {
			return;
		}

		$payment_method = $order->get_payment_method();
		$gateway        = Paynow_Payment::$allowed_payments[ $payment_method ];

		foreach ( $gateway::order_metas() as $key => $value ) {
			echo '<div><strong>' . esc_html( $value ) . ':</strong> ' . esc_html( $order->get_meta( $key ) ) . '</div>';
		}

		$tran_status = $order->get_meta( '_paynow_tran_status' );
		$errordesc   = $order->get_meta( '_paynow_errdesc' );
		if ( 'F' === $tran_status && $errordesc ) {
			echo '<div><strong>' . esc_html( __( 'Payment Error Description', 'paynow-payment' ) ) . ':</strong> ' . esc_html( $errordesc ) . '</div>';
		}
	}

	/**
	 * Returns the single instance of the PayNow_Shipping object
	 *
	 * @return PayNow_Payment_Order_Meta_Boxes
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

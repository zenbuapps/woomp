<?php

namespace PAYUNI\Posts\ShopOrder;

use PAYUNI\Gateways\CreditSubscription;
use PAYUNI\Gateways\Request;

defined( 'ABSPATH' ) || exit;

class Ajax {

	private $request;

	public static function init() {
		$class = new self();
		add_action( 'wp_ajax_payuni_subscription_pay_manual', [ $class, 'build_request' ] );
	}

	public function __construct() {
		$this->request = new Request( new CreditSubscription() );
	}

	/**
	 * 手動請款
	 */
	public function build_request() {
		try {
			if ( ! wp_verify_nonce( $_POST['nonce'], 'pay_manual' ) ) { // phpcs:ignore
				\wp_send_json_error( __( '發生錯誤，不合法的請求來源！', 'woomp' ) );
				return;
			}

			// 手動請款會直接向顧客的信用卡扣款，只有能編輯訂單的後台人員可以操作。
			if ( ! \current_user_can( 'edit_shop_orders' ) ) {
				\wp_send_json_error( __( '權限不足，無法手動請款！', 'woomp' ), 403 );
				return;
			}

			$order_id = (int) ( $_POST['orderId'] ?? 0 ); // phpcs:ignore

			if ( ! $order_id ) {
				\wp_send_json_error( __( '訂單編號錯誤！', 'woomp' ) );
				return;
			}

			$order = \wc_get_order( $order_id );

			// 只允許 V1 定期定額訂單：Request 是以 CreditSubscription 閘道建立，扣的是該閘道的 token。
			if ( ! $order instanceof \WC_Order || 'payuni-credit-subscription' !== $order->get_payment_method() ) {
				\wp_send_json_error( __( '此訂單不是統一金流定期定額訂單，無法手動請款！', 'woomp' ) );
				return;
			}

			$amount = $order->get_total();
			$this->request->build_subscription_request( (float) $amount, $order );

			\wp_send_json_success( __( '扣款成功！即將刷新頁面', 'woomp' ) );

			\wp_die();
		} catch (\Throwable $th) {
			ob_start();
			var_dump( $th );
			\J7\WpUtils\Classes\ErrorLog::info( 'error: ' . ob_get_clean() );
		}
	}
}

Ajax::init();

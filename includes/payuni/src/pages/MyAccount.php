<?php
/**
 * MyAccount class
 */

namespace PAYUNI\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * MyAccount class
 */
class MyAccount {
	/**
	 * Initialize class and add hooks
	 *
	 * @return void
	 */
	public static function init() {
		$class = new self();
		\add_filter(
			'woocommerce_my_account_my_orders_actions',
			[
				$class,
				'change_customer_order_action',
			],
			10,
			2
		);
		\add_action( 'wp_enqueue_scripts', [ $class, 'enqueue_my_account_script' ] );
		// \add_action( 'woocommerce_payment_token_set_default', [ $class, 'update_credit_hash' ], 30, 2 );
	}

	public function change_customer_order_action( $actions, $order ) {
		$order_status   = $order->get_status();
		$payment_method = $order->get_payment_method();

		switch ( $order_status ) {
			case 'failed':
				if ( 'linepay' !== $payment_method ) {
					break;
				}

				unset( $actions['pay'] );
				unset( $actions['cancel'] );

				break;
		}

		// $refund_expired = strtotime($order->get_date_created()->date('Y-m-d H:i:s') . ' -8 hour') + (60 * 86400);

		return $actions;
	}

	public function enqueue_my_account_script() {
		if ( is_account_page() ) {
			wp_register_script( 'payuni_my_account_script', WOOMP_PLUGIN_URL . 'includes/payuni/assets/my-account.js', [ 'jquery' ], '1.1.8', true );
			wp_localize_script(
				'payuni_my_account_script',
				'payuni_my_account_script_params',
				[
					'ajax_url'   => admin_url( 'admin-ajax.php' ),
					'ajax_nonce' => wp_create_nonce( 'payuni_refund' ),
					'user_id'    => get_current_user_id(),
				]
			);
			wp_enqueue_script( 'payuni_my_account_script' );
		}
	}

	/**
	 * 當用戶設定信用卡為預設時，更新訂閱的上層訂單中的信用卡資訊
	 *
	 * @see https://github.com/zenbuapps/woomp/issues/37
	 *
	 * @param integer              $token_id token_id.
	 * @param \WC_PAYMENT_TOKEN_CC $token 信用卡 token 資訊.
	 *
	 * @deprecated 2024-12-12 付款方式可以從  wp_woocommerce_payment_tokens 拿，只要有 user_id 就可以，不需要從上層訂單拿
	 * @return void
	 */
	public function update_credit_hash( int $token_id, \WC_PAYMENT_TOKEN_CC $token ): void {
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return;
		}

		$user_id = $token->get_user_id(); // <--正確的用戶
		if ( ! $user_id ) {
			return;
		}

		$gateway_id            = $token->get_gateway_id(); // 'payuni-credit-subscription'
		$payment_gateway_title = '';
		if (function_exists('WC')) {
			$available_gateways = \WC()->payment_gateways->get_available_payment_gateways();
			if (isset($available_gateways[ $gateway_id ])) {
				$payment_gateway_title = $available_gateways[ $gateway_id ]->get_title();
			}
		}

		// find all subscriptions for this user.
		$subscriptions = \wcs_get_users_subscriptions( $user_id );
		// get subscription post parent id.
		$parent_order_ids = [];
		foreach ( $subscriptions as $subscription ) {
			$subscription_id = $subscription->get_id();
			$subscription->set_payment_method( $gateway_id );
			$subscription->set_payment_method_title( $payment_gateway_title );
			$subscription->save();

			// 取得父訂單ID.
			$parent_id          = $subscription->get_parent_id();
			$parent_order_ids[] = $parent_id;
		}

		foreach ( $parent_order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$order->set_payment_method( $gateway_id );
			$order->set_payment_method_title( $payment_gateway_title );
			$order->update_meta_data( '_payuni_token_id', $token->get_token() );
			$order->update_meta_data( '_payuni_card_hash', $token->get_token() );
			$order->update_meta_data( '_payuni_card_number', $token->get_last4() );
			$order->update_meta_data( '_payuni_resp_card_bank', '不確定' );
			$order->update_meta_data( '_payuni_resp_message', '修改預設付款方式' );
			$order->save();
		}
	}
}

MyAccount::init();

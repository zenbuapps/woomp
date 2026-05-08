<?php
/**
 * PayUni V3 定期定額手動扣款 AJAX 處理
 *
 * 處理後台管理員手動觸發定期定額扣款的 AJAX 請求。
 *
 * @package J7\Payuni\Domains\Subscription
 */

declare(strict_types=1);

namespace J7\Payuni\Domains\Subscription;

use PAYUNI\Gateways\CreditSubscriptionV3;

\defined( 'ABSPATH' ) || exit;

/**
 * 定期定額手動扣款 AJAX 處理器
 *
 * @since 1.0.0
 */
final class SubscriptionAjax {

	/**
	 * 註冊 AJAX action
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_action( 'wp_ajax_payuni_v3_subscription_pay_manual', [ new self(), 'handle_manual_payment' ] );
	}

	/**
	 * 處理手動扣款請求
	 *
	 * 驗證 nonce、權限與訂單條件後，
	 * 委派給 SubscriptionHandler 執行續扣。
	 *
	 * @return void
	 */
	public function handle_manual_payment(): void {
		try {
			// 驗證 nonce
			if ( ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST['nonce'] ?? '' ) ), 'payuni_v3_pay_manual' ) ) {
				\wp_send_json_error( \__( '發生錯誤，不合法的請求來源！', 'woomp' ) );
				\wp_die();
				return;
			}

			// 驗證權限
			if ( ! \current_user_can( 'edit_shop_orders' ) ) {
				\wp_send_json_error( \__( '您沒有權限執行此操作', 'woomp' ) );
				\wp_die();
				return;
			}

			// 取得並驗證訂單 ID
			$order_id = \absint( $_POST['orderId'] ?? 0 );

			if ( ! $order_id ) {
				\wp_send_json_error( \__( '訂單編號錯誤！', 'woomp' ) );
				\wp_die();
				return;
			}

			$order = \wc_get_order( $order_id );

			if ( ! $order instanceof \WC_Order ) {
				\wp_send_json_error( \__( '訂單不存在！', 'woomp' ) );
				\wp_die();
				return;
			}

			// 驗證付款方式
			if ( CreditSubscriptionV3::ID !== $order->get_payment_method() ) {
				\wp_send_json_error( \__( '此訂單不是 V3 定期定額付款方式', 'woomp' ) );
				\wp_die();
				return;
			}

			// 執行續扣
			$handler = new SubscriptionHandler();
			$handler->process_renewal_payment( (float) $order->get_total(), $order );

			// 重新讀取訂單狀態
			$order = \wc_get_order( $order_id );

			if ( $order instanceof \WC_Order && 'SUCCESS' === $order->get_meta( '_payuni_resp_status' ) ) {
				\wp_send_json_success( \__( '扣款成功！即將刷新頁面', 'woomp' ) );
			} else {
				\wp_send_json_error( \__( '扣款失敗，請查看訂單備註', 'woomp' ) );
			}

			\wp_die();
		} catch ( \Throwable $e ) {
			\do_action( 'woomp_payuni_log', 'error', "手動扣款例外：{$e->getMessage()}", [] );
			\wp_send_json_error( \__( '扣款過程發生異常，請查看訂單備註', 'woomp' ) );
			\wp_die();
		}
	}
}

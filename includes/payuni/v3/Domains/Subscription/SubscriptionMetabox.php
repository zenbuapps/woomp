<?php
/**
 * PayUni V3 定期定額手動扣款 Metabox
 *
 * 在訂單編輯頁側邊欄顯示手動扣款按鈕，
 * 僅針對 V3 定期定額閘道、pending 狀態、且非 SUCCESS 的訂單。
 *
 * @package J7\Payuni\Domains\Subscription
 */

declare(strict_types=1);

namespace J7\Payuni\Domains\Subscription;

use PAYUNI\Gateways\CreditSubscriptionV3;

\defined( 'ABSPATH' ) || exit;

/**
 * 定期定額手動扣款 Metabox
 *
 * @since 1.0.0
 */
final class SubscriptionMetabox {

	/**
	 * 註冊 Metabox
	 *
	 * 在 admin_init 時根據條件決定是否顯示手動扣款 Metabox。
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_action( 'admin_init', [ __CLASS__, 'maybe_add_metabox' ], 99 );
	}

	/**
	 * 條件式加入 Metabox
	 *
	 * HPOS 相容：優先讀取 $_GET['id']，再 fallback 到 $_GET['post']。
	 *
	 * @return void
	 */
	public static function maybe_add_metabox(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_GET['id'] ) ? \absint( $_GET['id'] ) : ( isset( $_GET['post'] ) ? \absint( $_GET['post'] ) : 0 );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $order_id ) {
			return;
		}

		$order = \wc_get_order( $order_id );

		if ( ! self::should_show_metabox( $order ) ) {
			return;
		}

		\add_meta_box(
			'payuni_subscription_v3',
			\__( '統一金流定期定額（V3）', 'woomp' ),
			[ __CLASS__, 'render_metabox' ],
			[ 'shop_order', 'woocommerce_page_wc-orders' ],
			'side',
			'default',
			[ 'order_id' => $order_id ]
		);
	}

	/**
	 * 判斷是否應顯示手動扣款 Metabox
	 *
	 * 條件：
	 * - 訂單存在且為 WC_Order 實例
	 * - payment_method 為 V3 定期定額閘道
	 * - PayUni 回應非 SUCCESS
	 * - 訂單狀態為 pending
	 *
	 * @param mixed $order 訂單物件（可能為 null 或非 WC_Order）
	 *
	 * @return bool 是否應顯示
	 */
	public static function should_show_metabox( $order ): bool {
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		if ( CreditSubscriptionV3::ID !== $order->get_payment_method() ) {
			return false;
		}

		if ( 'SUCCESS' === $order->get_meta( '_payuni_resp_status' ) ) {
			return false;
		}

		if ( 'pending' !== $order->get_status() ) {
			return false;
		}

		return true;
	}

	/**
	 * 渲染 Metabox 內容
	 *
	 * @param mixed                $post_or_order WP_Post 或 WC_Order（HPOS 模式）
	 * @param array<string, mixed> $metabox       Metabox 參數（含 args）
	 *
	 * @return void
	 */
	public static function render_metabox( $post_or_order, array $metabox ): void {
		$order_id = $metabox['args']['order_id'] ?? 0;

		if ( ! $order_id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_button 內部已處理
		echo self::render_button( $order_id );
	}

	/**
	 * 渲染手動扣款按鈕 HTML
	 *
	 * @param int $order_id 訂單 ID
	 *
	 * @return string 按鈕 HTML
	 */
	public static function render_button( int $order_id ): string {
		$escaped_order_id = \absint( $order_id );

		return <<<HTML
		<div style="margin-top:20px">
			<button class="button button-primary btnPayuniV3SubscriptionPayManual" type="button" value='{$escaped_order_id}'>手動扣款</button>
		</div>
		HTML;
	}
}

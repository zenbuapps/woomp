<?php
/**
 * PayUni V3 定期定額 Bootstrap
 *
 * 負責註冊訂閱相關的 Hook 與條件顯示邏輯。
 * V1/V3 閘道的啟用/停用由 WooCommerce 原生閘道設定控制。
 *
 * @package J7\Payuni\Domains\Subscription
 */

declare(strict_types=1);

namespace J7\Payuni\Domains\Subscription;

\defined( 'ABSPATH' ) || exit;

/**
 * 定期定額訂閱 Bootstrap
 *
 * @since 1.0.0
 */
final class SubscriptionBootstrap {

	/**
	 * V1 定期定額閘道 ID
	 *
	 * @var string
	 */
	private const V1_GATEWAY_ID = 'payuni-credit-subscription';

	/**
	 * V3 定期定額閘道 ID
	 *
	 * @var string
	 */
	private const V3_GATEWAY_ID = 'payuni-credit-subscription-v3';

	/**
	 * 註冊所有 Hooks
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// 條件顯示付款閘道
		\add_filter(
			'woocommerce_available_payment_gateways',
			[ __CLASS__, 'conditional_payment_gateways' ],
			10,
			1
		);

		// V3 排程續扣（Stage 4 實作）
		\add_action(
			'woocommerce_scheduled_subscription_payment_' . self::V3_GATEWAY_ID,
			[ __CLASS__, 'process_scheduled_payment' ],
			10,
			2
		);

		// 續扣失敗處理（Stage 4 實作）
		\add_action(
			'woocommerce_subscription_renewal_payment_failed',
			[ __CLASS__, 'subscription_fail_handler' ],
			99,
			2
		);

		// 零元授權取消排程
		\add_action(
			'payuni_cancel_zero_authorization',
			[ SubscriptionHandler::class, 'cancel_zero_authorization' ],
			10,
			1
		);

		// 後台手動扣款 Metabox
		SubscriptionMetabox::register();

		// 後台手動扣款 AJAX
		SubscriptionAjax::register();

		// 後台 JS
		\add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_scripts' ] );
	}

	/**
	 * 條件顯示付款閘道
	 *
	 * 根據購物車商品類型控制訂閱閘道的顯示。
	 * V1/V3 的啟用/停用由 WooCommerce 原生閘道設定控制。
	 *
	 * @param array $available_gateways 可用的付款閘道
	 *
	 * @return array 過濾後的付款閘道
	 */
	public static function conditional_payment_gateways( array $available_gateways ): array {
		// 僅在結帳流程中依購物車內容過濾訂閱閘道。
		// 在 My Account → 新增付款方式 / 管理付款方式 等頁面，
		// 購物車通常為空或不含訂閱商品，過濾會把 V1/V3 全部移除，
		// 導致用戶看不到任何信用卡欄位、無法新增或更換卡片。
		if ( ! \is_checkout() ) {
			return $available_gateways;
		}

		// 收集購物車中的商品類型
		$product_types = self::get_cart_product_types();

		// 根據購物車商品類型過濾（非訂閱商品時隱藏訂閱閘道）
		$available_gateways = self::filter_gateways_by_cart( $available_gateways, $product_types );

		return $available_gateways;
	}

	/**
	 * 根據購物車商品類型過濾閘道
	 *
	 * 購物車不包含 subscription 或 variable-subscription 類型商品時，
	 * 移除所有定期定額閘道（V1 和 V3）。
	 *
	 * @param array    $gateways      可用的付款閘道
	 * @param string[] $product_types 購物車中的商品類型
	 *
	 * @return array 過濾後的付款閘道
	 */
	public static function filter_gateways_by_cart( array $gateways, array $product_types ): array {
		$has_subscription = \in_array( 'subscription', $product_types, true )
			|| \in_array( 'variable-subscription', $product_types, true );

		if ( ! $has_subscription ) {
			unset( $gateways[ self::V1_GATEWAY_ID ] );
			unset( $gateways[ self::V3_GATEWAY_ID ] );
		}

		return $gateways;
	}

	/**
	 * 排程續扣處理
	 *
	 * 由 WooCommerce Subscriptions 排程觸發，委派給 SubscriptionHandler 處理。
	 *
	 * @param float     $amount 續扣金額
	 * @param \WC_Order $order  續扣訂單
	 *
	 * @return void
	 */
	public static function process_scheduled_payment( $amount, $order ): void {
		$handler = new SubscriptionHandler();
		$handler->process_renewal_payment( (float) $amount, $order );
	}

	/**
	 * 續扣失敗處理
	 *
	 * 當 PayUni V3 續扣的訂單狀態為 failed，且 PayUni 回應不是 SUCCESS 時，
	 * 將訂單狀態改回 pending 以允許重試。
	 *
	 * @param mixed $subscription 訂閱物件（WC_Subscription）
	 * @param mixed $last_order   最後一筆訂單（WC_Order）
	 *
	 * @return void
	 */
	public static function subscription_fail_handler( $subscription, $last_order ): void {
		if ( ! $last_order instanceof \WC_Order ) {
			return;
		}

		if ( 'failed' !== $last_order->get_status() ) {
			return;
		}

		if ( self::V3_GATEWAY_ID !== $last_order->get_payment_method() ) {
			return;
		}

		if ( 'SUCCESS' === $last_order->get_meta( '_payuni_resp_status' ) ) {
			return;
		}

		$last_order->update_status( 'pending' );
	}

	/**
	 * 取得購物車中的商品類型
	 *
	 * @return string[] 商品類型陣列
	 */
	private static function get_cart_product_types(): array {
		if ( empty( WC()->cart ) ) {
			return [];
		}

		$product_types = [];
		$cart_contents = WC()->cart->get_cart_contents();

		foreach ( $cart_contents as $values ) {
			$product_types[] = \WC_Product_Factory::get_product_type( $values['product_id'] );
		}

		return $product_types;
	}

	/**
	 * 載入後台訂單編輯頁的 JS 腳本
	 *
	 * 僅在訂單編輯頁（shop_order 或 woocommerce_page_wc-orders）載入，
	 * 用於手動扣款按鈕的 AJAX 互動。
	 *
	 * @return void
	 */
	public static function enqueue_admin_scripts(): void {
		$screen = \get_current_screen();

		if ( ! $screen || ! \in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}

		$js_url = \plugins_url( 'assets/admin-subscription.js', __FILE__ );
		\wp_enqueue_script(
			'payuni-v3-subscription-admin',
			$js_url,
			[ 'jquery', 'jquery-blockui' ],
			'1.0.0',
			true
		);
		\wp_localize_script(
			'payuni-v3-subscription-admin',
			'payuni_v3_subscription_params',
			[
				'ajax_nonce' => \wp_create_nonce( 'payuni_v3_pay_manual' ),
			]
		);
	}
}

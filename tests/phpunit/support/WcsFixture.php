<?php
/**
 * 訂閱測試用的 WooCommerce Subscriptions 替身
 *
 * tests/phpunit/bootstrap.php 目前不會載入 WooCommerce Subscriptions（WCS），
 * 但 PayUni V3 的 token 優先序／綁定邏輯只需要下列四個函式，
 * 以及「訂閱物件能讀寫 meta」這個行為：
 *
 * - wcs_is_subscription()
 * - wcs_get_subscriptions_for_order()
 * - wcs_get_subscriptions_for_renewal_order()
 * - wcs_get_subscription()
 *
 * 本檔在 WCS 未載入時提供替身（以一般 WC_Order 充當訂閱物件，meta 一樣走 WC_Order API，
 * 所以 HPOS 下行為一致）；WCS 已載入時改用真正的 wcs_create_subscription()，測試不必修改。
 *
 * 替身函式只認得本 fixture 登記過的訂閱，其他訂單一律回傳「不是訂閱／沒有訂閱」，
 * 與 WCS 未載入時的行為等價，不會影響其他測試類別。
 *
 * @package Woomp\Tests\Support
 */

/**
 * WCS 測試替身
 */
final class Woomp_Test_Wcs_Fixture {

	/**
	 * 登記的訂閱：訂閱 ID => [ 'parent' => int, 'renewals' => int[] ]
	 *
	 * @var array<int, array{parent: int, renewals: int[]}>
	 */
	private static array $registry = [];

	/**
	 * 是否為真正的 WCS
	 *
	 * @return bool
	 */
	public static function is_real(): bool {
		return \class_exists( 'WC_Subscription' ) && \function_exists( 'wcs_create_subscription' ) && ! self::stubs_defined();
	}

	/**
	 * 替身函式是否由本檔定義
	 *
	 * @return bool
	 */
	private static function stubs_defined(): bool {
		return \defined( 'WOOMP_TEST_WCS_STUBS' );
	}

	/**
	 * 啟用替身（WCS 未載入時）並清空登記
	 *
	 * @return void
	 */
	public static function install(): void {
		self::$registry = [];

		if ( \class_exists( 'WC_Subscription' ) ) {
			return;
		}

		require_once __DIR__ . '/WcsFunctionStubs.php';
	}

	/**
	 * 取得替身登記（給替身函式用）
	 *
	 * @return array<int, array{parent: int, renewals: int[]}>
	 */
	public static function registry(): array {
		return self::$registry;
	}

	/**
	 * 訂閱「啟用中」狀態
	 *
	 * 替身是 WC_Order，沒有 active 狀態，改用 on-hold；兩者都不是 WC 的「已付款」狀態。
	 *
	 * @return string
	 */
	public static function active_status(): string {
		return self::is_real() ? 'active' : 'on-hold';
	}

	/**
	 * 建立訂閱
	 *
	 * @param \WC_Order $parent      母單
	 * @param int       $customer_id 客戶 ID
	 * @param string    $payment_method 訂閱的付款方式
	 *
	 * @return \WC_Order 訂閱（真 WCS 時為 WC_Subscription）
	 */
	public static function create_subscription( \WC_Order $parent, int $customer_id, string $payment_method = 'payuni-credit-subscription-v3' ): \WC_Order {
		if ( self::is_real() ) {
			$subscription = \wcs_create_subscription(
				[
					'order_id'         => $parent->get_id(),
					'customer_id'      => $customer_id,
					'billing_period'   => 'month',
					'billing_interval' => 1,
					'status'           => 'active',
				]
			);
			$subscription->set_payment_method( $payment_method );
			$subscription->save();

			return $subscription;
		}

		$subscription = \wc_create_order();
		$subscription->set_customer_id( $customer_id );
		$subscription->set_payment_method( $payment_method );
		$subscription->set_total( 9450 );
		$subscription->set_status( 'on-hold' );
		$subscription->save();

		self::$registry[ $subscription->get_id() ] = [
			'parent'   => $parent->get_id(),
			'renewals' => [],
		];

		return $subscription;
	}

	/**
	 * 建立屬於某訂閱的續訂單
	 *
	 * @param \WC_Order $subscription 訂閱
	 * @param int       $customer_id  客戶 ID
	 * @param string    $status       續訂單狀態
	 *
	 * @return \WC_Order 續訂單
	 */
	public static function create_renewal_order( \WC_Order $subscription, int $customer_id, string $status = 'pending' ): \WC_Order {
		$renewal = \wc_create_order();
		$renewal->set_customer_id( $customer_id );
		$renewal->set_billing_email( 'test@example.com' );
		$renewal->set_total( 9450 );
		$renewal->set_payment_method( 'payuni-credit-subscription-v3' );
		$renewal->set_status( $status );

		if ( self::is_real() ) {
			$renewal->update_meta_data( '_subscription_renewal', $subscription->get_id() );
		} else {
			self::$registry[ $subscription->get_id() ]['renewals'][] = $renewal->get_id();
		}

		$renewal->save();

		if ( ! self::is_real() ) {
			// 上面 get_id() 在 save() 之前可能為 0，保險起見用存檔後的 ID 補登記
			self::$registry[ $subscription->get_id() ]['renewals'] = \array_values(
				\array_unique( \array_filter( \array_merge( self::$registry[ $subscription->get_id() ]['renewals'], [ $renewal->get_id() ] ) ) )
			);
		}

		return $renewal;
	}
}

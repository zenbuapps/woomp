<?php
/**
 * WCS 函式替身（只在 WooCommerce Subscriptions 未載入時才會被 require）
 *
 * 只認得 Woomp_Test_Wcs_Fixture 登記過的訂閱。
 *
 * @package Woomp\Tests\Support
 */

if ( ! defined( 'WOOMP_TEST_WCS_STUBS' ) ) {
	define( 'WOOMP_TEST_WCS_STUBS', true );
}

if ( ! function_exists( 'wcs_is_subscription' ) ) {
	/**
	 * 是否為（已登記的）訂閱
	 *
	 * @param mixed $subscription 訂閱物件或 ID
	 *
	 * @return bool
	 */
	function wcs_is_subscription( $subscription ): bool {
		$id = $subscription instanceof \WC_Abstract_Order ? $subscription->get_id() : (int) $subscription;

		return isset( Woomp_Test_Wcs_Fixture::registry()[ $id ] );
	}
}

if ( ! function_exists( 'wcs_get_subscription' ) ) {
	/**
	 * 取得（已登記的）訂閱
	 *
	 * @param mixed $the_subscription 訂閱物件或 ID
	 *
	 * @return \WC_Order|false
	 */
	function wcs_get_subscription( $the_subscription ) {
		if ( ! wcs_is_subscription( $the_subscription ) ) {
			return false;
		}
		$id = $the_subscription instanceof \WC_Abstract_Order ? $the_subscription->get_id() : (int) $the_subscription;

		return wc_get_order( $id );
	}
}

if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
	/**
	 * 取得訂單所屬的訂閱（母單／續訂單）
	 *
	 * @param mixed $order 訂單物件或 ID
	 * @param array $args  order_type 篩選（parent／renewal／any）
	 *
	 * @return array<int, \WC_Order>
	 */
	function wcs_get_subscriptions_for_order( $order, $args = [] ): array {
		$order_id = $order instanceof \WC_Abstract_Order ? $order->get_id() : (int) $order;
		$types    = (array) ( $args['order_type'] ?? [ 'parent', 'renewal' ] );
		$any      = in_array( 'any', $types, true );
		$found    = [];

		foreach ( Woomp_Test_Wcs_Fixture::registry() as $sub_id => $row ) {
			$is_parent  = ( $row['parent'] === $order_id ) && ( $any || in_array( 'parent', $types, true ) );
			$is_renewal = in_array( $order_id, $row['renewals'], true ) && ( $any || in_array( 'renewal', $types, true ) );

			if ( $is_parent || $is_renewal ) {
				$found[ $sub_id ] = wc_get_order( $sub_id );
			}
		}

		return $found;
	}
}

if ( ! function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
	/**
	 * 取得續訂單所屬的訂閱
	 *
	 * @param mixed $order 訂單物件或 ID
	 *
	 * @return array<int, \WC_Order>
	 */
	function wcs_get_subscriptions_for_renewal_order( $order ): array {
		return wcs_get_subscriptions_for_order( $order, [ 'order_type' => 'renewal' ] );
	}
}

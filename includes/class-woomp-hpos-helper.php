<?php
/**
 * HPOS 相容性工具類別
 *
 * 提供 HPOS（High-Performance Order Storage）相關的工具方法，
 * 讓外掛在 HPOS 啟用與傳統模式下都能正常運作。
 *
 * @package    Woomp
 * @subpackage Woomp/includes
 * @since      3.4.82
 * @license    GPL-2.0+
 */

defined( 'ABSPATH' ) || exit;

/**
 * HPOS 相容性工具類別
 *
 * 封裝所有與 HPOS 模式判斷、訂單物件取得、Screen ID、Admin URL 相關的邏輯，
 * 避免各模組重複實作相同的相容性判斷。
 *
 * @since 3.4.82
 */
final class Woomp_HPOS_Helper {

	/**
	 * 從 meta box callback 參數取得 WC_Order 物件
	 *
	 * HPOS 模式下 meta box callback 會收到 WC_Order 物件，
	 * 傳統模式下則收到 WP_Post 物件。
	 * 此方法統一處理兩種情況，回傳 WC_Order 或 null。
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Meta box callback 傳入的參數
	 *
	 * @return \WC_Order|null 訂單物件，若無法取得則回傳 null
	 */
	public static function get_order( $post_or_order ) {
		if ( $post_or_order instanceof \WC_Order ) {
			return $post_or_order;
		}

		if ( $post_or_order instanceof \WP_Post ) {
			$order = wc_get_order( $post_or_order->ID );
			return $order instanceof \WC_Order ? $order : null;
		}

		return null;
	}

	/**
	 * 取得 HPOS 相容的訂單編輯 screen ID 陣列
	 *
	 * HPOS 啟用時，訂單編輯頁面的 screen ID 為 woocommerce_page_wc-orders；
	 * 傳統模式下為 shop_order。此方法回傳包含兩者的陣列，
	 * 適用於 add_meta_box()、current_screen 判斷等場景。
	 *
	 * @return string[] 訂單編輯頁面的 screen ID 陣列
	 */
	public static function get_order_screen_ids() {
		$screen_ids = [
			'shop_order',
			'woocommerce_page_wc-orders',
		];

		// 動態取得 WC 的 HPOS screen ID（以防未來版本改名）。
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screen_ids[] = wc_get_page_screen_id( 'shop-order' );
		}

		return array_unique( $screen_ids );
	}

	/**
	 * 取得 HPOS 相容的訂單列表 admin URL
	 *
	 * HPOS 啟用時，訂單列表頁面的 URL 為 admin.php?page=wc-orders；
	 * 傳統模式下為 edit.php?post_type=shop_order。
	 *
	 * @return string 訂單列表頁面的完整 admin URL
	 */
	public static function get_order_list_url() {
		if ( self::is_hpos_enabled() ) {
			return admin_url( 'admin.php?page=wc-orders' );
		}

		return admin_url( 'edit.php?post_type=shop_order' );
	}

	/**
	 * 取得 HPOS 相容的單一訂單編輯 admin URL
	 *
	 * HPOS 啟用時，訂單編輯頁面的 URL 為 admin.php?page=wc-orders&action=edit&id={order_id}；
	 * 傳統模式下為 post.php?post={order_id}&action=edit。
	 *
	 * @param int $order_id 訂單 ID
	 *
	 * @return string 訂單編輯頁面的完整 admin URL
	 */
	public static function get_order_edit_url( $order_id ) {
		if ( self::is_hpos_enabled() ) {
			return admin_url( "admin.php?page=wc-orders&action=edit&id={$order_id}" );
		}

		return admin_url( "post.php?post={$order_id}&action=edit" );
	}

	/**
	 * 判斷 HPOS 是否啟用
	 *
	 * 透過 WooCommerce 的 OrderUtil 類別判斷目前是否使用
	 * Custom Order Tables（HPOS）儲存訂單資料。
	 *
	 * @return bool HPOS 是否啟用
	 */
	public static function is_hpos_enabled() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		return false;
	}
}

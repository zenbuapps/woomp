<?php
/**
 * Compatibility 不同版本間的相容性設定
 */

declare (strict_types = 1);

namespace J7\Woomp;

/**
 * Class Compatibility
 */
final class Compatibility {



	const AS_COMPATIBILITY_ACTION = 'woomp_compatibility_action_scheduler';

	/**
	 * Singleton instance
	 *
	 * @var self
	 */
	private static $instance;

	/**
	 * Constructor
	 */
	public function __construct() {
		// 排程只執行一次的兼容設定
		\add_action( 'init', [ __CLASS__, 'compatibility_action_scheduler' ] );
		\add_action( self::AS_COMPATIBILITY_ACTION, [ __CLASS__, 'compatibility' ]);
	}

	/**
	 * Get the singleton instance
	 *
	 * @param mixed ...$args Arguments
	 *
	 * @return self
	 */
	public static function instance(...$args) { // phpcs:ignore
		if ( null === self::$instance ) {
			self::$instance = new self(...$args);
		}

		return self::$instance;
	}


	/**
	 * 排程只執行一次的兼容設定
	 *
	 * @return void
	 */
	public static function compatibility_action_scheduler(): void {
		$scheduled_version = \get_option('woomp_compatibility_action_scheduled');
		if ($scheduled_version === WOOMP_VERSION) {
			return;
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		\as_enqueue_async_action( self::AS_COMPATIBILITY_ACTION, [] );
	}

	/**
	 * 執行排程
	 *
	 * @return void
	 */
	public static function compatibility(): void {

		/**
		 * ============== START 相容性代碼 ==============
		 */

		self::delete_post_meta();

		/**
		 * ============== END 相容性代碼 ==============
		 */

		// ❗不要刪除此行，註記已經執行過相容設定
		\update_option('woomp_compatibility_action_scheduled', WOOMP_VERSION);
	}

	/**
	 * 刪除 post meta
	 *
	 * @return void
	 */
	public static function delete_post_meta(): void {

		$meta_keys        = [
			'_paynow_ei_issue_type',
			'_paynow_ei_carrier_type',
			'_paynow_ei_buyer_name',
			'_paynow_ei_ubn',
			'_paynow_ei_carrier_num',
			'_paynow_ei_donate_org',
		];
		$meta_keys_string = implode( "','", $meta_keys );

		$post_types        = [
			'shop_order',
			'shop_subscription',
			'shop_order_refund',
		];
		$post_types_string = \implode( "','", $post_types );

		global $wpdb;
		$results = $wpdb->query(
			\wp_unslash(
				$wpdb->prepare(
				"DELETE pm FROM $wpdb->postmeta pm
		LEFT JOIN $wpdb->posts p ON pm.post_id = p.ID
		WHERE pm.meta_value = '' AND pm.meta_key IN (%s) AND p.post_type NOT IN (%s)",
				$meta_keys_string,
				$post_types_string
			)
				)
		);

		// TEST 印出 ErrorLog 記得移除
		\J7\WpUtils\Classes\ErrorLog::info($results, 'delete_post_meta result');
	}
}

Compatibility::instance();

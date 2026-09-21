<?php
/**
 * PayUni V2 付款完成互斥鎖
 *
 * @package payuni
 */

namespace PAYUNI\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * 以 wp_options.option_name 的 UNIQUE 索引實作跨行程互斥鎖
 *
 * 為什麼需要：補上 NotifyURL 後，同一筆 3D 交易的「幕後通知」與「瀏覽器導回」
 * 幾乎同時抵達同一個 handler，兩個 PHP 行程可能同時通過 is_payuni_completed()
 * 這種 check-then-act 檢查，重複執行 payment_complete()——重複扣庫存、重複寄信、
 * 重複建立付款 Token、_payuni_order_suffix 加兩次。
 *
 * 為什麼不用 add_option() / set_transient() / wp_cache_add()：
 * 前兩者內部都是先 SELECT 再 INSERT ... ON DUPLICATE KEY UPDATE，中間有窗口且回傳值
 * 不可靠（WP 6.8 option.php:1119-1143）；wp_cache_add() 在沒裝持久化物件快取的站上
 * 永遠回 true，是比沒有保護更危險的偽陽性。INSERT IGNORE 由 MySQL 的 UNIQUE 索引
 * 保證單一贏家，是本專案唯一可用的原子操作。
 */
final class PaidLock {

	/**
	 * 鎖鍵值寫入 wp_options 時的 option_name 前綴
	 *
	 * @var string
	 */
	private const PREFIX = 'payuni_v2_lock_';

	/**
	 * 鎖存活秒數；超過視為前一行程異常中止（fatal / timeout），可被搶走
	 *
	 * @var int
	 */
	private const TTL = 90;

	/**
	 * 嘗試取得鎖
	 *
	 * @param string $key 鎖鍵值。建議用 MerTradeNo（PayUni 限長 25，加前綴後遠小於 option_name 的 191）。
	 *
	 * @return bool 取得為 true；已被其他行程持有為 false。
	 */
	public static function acquire( string $key ): bool {
		global $wpdb;

		$name = self::PREFIX . $key;
		$now  = \time();

		// INSERT IGNORE：撞到 UNIQUE 索引時不報錯，affected rows = 0。
		// autoload 設 no，避免污染 alloptions 快取。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				$name,
				(string) $now
			)
		);

		if ( 1 === (int) $inserted ) {
			return true;
		}

		// 搶過期的鎖：前一個行程 fatal 沒跑到 release，不能讓訂單永遠鎖死。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$holder = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name )
		);

		if ( null === $holder || ( $now - (int) $holder ) < self::TTL ) {
			return false;
		}

		// UPDATE ... WHERE option_value = 舊值 同樣是原子的：只有一個行程會 affected rows = 1。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stolen = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				(string) $now,
				$name,
				(string) $holder
			)
		);

		return 1 === (int) $stolen;
	}

	/**
	 * 釋放鎖
	 *
	 * 不走 delete_option()：那會先 SELECT 再 DELETE 並操作物件快取，
	 * 這個鍵值純屬內部使用，不需要進快取。
	 *
	 * @param string $key 鎖鍵值。
	 *
	 * @return void
	 */
	public static function release( string $key ): void {
		global $wpdb;

		$name = self::PREFIX . $key;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->options, [ 'option_name' => $name ], [ '%s' ] );
		\wp_cache_delete( $name, 'options' );
	}
}

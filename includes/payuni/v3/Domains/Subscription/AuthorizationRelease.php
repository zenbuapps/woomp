<?php
/**
 * PayUni V3 綁卡 5 元授權的釋放（取消授權或退款）
 *
 * 零元首購與定期定額換卡都用 5 元授權換 CreditHash，之後必須把這 5 元還給客戶。
 * PayUni 商店多半設定「自動請款」（sandbox 實測授權約 3 秒就 `CloseStatus=2`），
 * 已請款的交易無法取消授權（`trade/cancel` 回 CANCEL03001），只能退款（`trade/close` CloseType=2）。
 * 正式商店也可能是手動請款，所以不能假設哪一種，要先查再決定：
 *
 * 1. `trade/query` 查交易：
 *    - 已取消（TradeStatus=3）或已有退款紀錄（RefundStatus=1/2/8）→ 已釋放，不再動作。
 *    - 已請款（CloseStatus=2）→ 退款 5 元。
 *    - 請款申請中／處理中（CloseStatus=1/7）→ 取消與退款都不成立，稍後重試。
 *    - 其他（未請款）→ 取消授權；取消回「已請款」（查詢後才被請款）→ 改走退款。
 *    - 查詢失敗 → 直接取消授權，回「已請款」再退款。
 * 2. 安全：查到的金額不是 5 元一律不自動處理（排程只帶 TradeNo，不可誤退正常交易）。
 * 3. 冪等：每個 TradeNo 一筆 option 狀態（`woomp_payuni_auth_release_{TradeNo}`）。
 *    - 取得處理權用條件 UPDATE／INSERT IGNORE（compare-and-swap），同時執行只有一個會動作。
 *    - 已有結果（cancelled／refunded／released／not_authorized／manual）直接回報，不再呼叫 PayUni。
 *    - 送出退款前先記 `refund_sent`；退款結果不明（逾時、非 JSON）時之後只查詢、不再送第二次退款，
 *      PayUni 明確拒絕（PayuniApiException）才清掉，因為那代表沒有退成。
 * 4. 重試最多 MAX_TRIES 次（間隔 RETRY_DELAY），用完轉人工並寫訂單／訂閱備註。
 *
 * 排程參數維持只有 TradeNo（相容升級前已排入的排程），寫備註的對象 ID 存在狀態裡。
 *
 * @package J7\Payuni\Domains\Subscription
 */

declare(strict_types=1);

namespace J7\Payuni\Domains\Subscription;

use J7\Payuni\Infrastructure\Http\HttpClient;
use J7\Payuni\Infrastructure\Http\PayuniApiException;

\defined( 'ABSPATH' ) || exit;

/**
 * 綁卡授權釋放
 */
final class AuthorizationRelease {

	/**
	 * 排程 hook（沿用既有名稱，已排入的舊排程照常執行）
	 *
	 * @var string
	 */
	public const HOOK = 'payuni_cancel_zero_authorization';

	/**
	 * 狀態 option 前綴
	 *
	 * @var string
	 */
	public const OPTION_PREFIX = 'woomp_payuni_auth_release_';

	/**
	 * 綁卡授權金額
	 *
	 * @var int
	 */
	private const BINDING_AMOUNT = 5;

	/**
	 * 第一次執行的延遲（秒）
	 *
	 * @var int
	 */
	private const INITIAL_DELAY = 120;

	/**
	 * 重試間隔（秒）
	 *
	 * @var int
	 */
	private const RETRY_DELAY = 600;

	/**
	 * 最多執行次數
	 *
	 * @var int
	 */
	private const MAX_TRIES = 5;

	/**
	 * 處理權逾時（秒）：持有者中途死掉時，逾時後可被接手
	 *
	 * @var int
	 */
	private const LOCK_TTL = 300;

	/**
	 * 取消授權回這些碼代表已請款，要改走退款 [待確認：PayUni 未公開完整 CANCEL 錯誤碼表，目前只有 sandbox 實測的這一個]
	 *
	 * @var string[]
	 */
	private const CAPTURED_CODES = [ 'CANCEL03001' ];

	/**
	 * 已有結果的狀態
	 *
	 * @var string[]
	 */
	private const FINAL_STATUSES = [ 'cancelled', 'refunded', 'released', 'not_authorized', 'manual' ];

	/**
	 * 排程釋放，並記下要寫備註的訂單／訂閱
	 *
	 * @param string $trade_no  PayUni 交易編號
	 * @param int    $object_id 訂單或訂閱 ID（0 表示不寫備註）
	 *
	 * @return void
	 */
	public static function schedule( string $trade_no, int $object_id = 0 ): void {
		if ( '' === $trade_no ) {
			return;
		}

		$state = self::read_state( $trade_no );
		if ( null === $state ) {
			self::insert_state(
				$trade_no,
				[
					'status'    => 'scheduled',
					'object_id' => $object_id,
					'tries'     => 0,
					'time'      => \time(),
				]
			);
		} else {
			if ( \in_array( (string) ( $state['status'] ?? '' ), self::FINAL_STATUSES, true ) ) {
				return; // 已有結果（例如導回與背景通知各排了一次），不再排程
			}
			if ( $object_id > 0 && empty( $state['object_id'] ) ) {
				self::compare_and_swap( $trade_no, $state, \array_merge( $state, [ 'object_id' => $object_id ] ) );
			}
		}

		self::enqueue( $trade_no, self::INITIAL_DELAY );
	}

	/**
	 * 執行一次釋放（排程 callback）
	 *
	 * @param string $trade_no PayUni 交易編號
	 *
	 * @return string 結果：cancelled／refunded／released／not_authorized／manual（已有結果）、retry（已重排程）、busy（別的程序處理中）
	 */
	public static function run( string $trade_no ): string {
		if ( '' === $trade_no ) {
			return 'manual';
		}

		$state = self::acquire( $trade_no );
		if ( \is_string( $state ) ) {
			return $state;
		}

		$state['tries'] = (int) ( $state['tries'] ?? 0 ) + 1;

		[ $outcome, $reason ] = self::decide_and_act( $trade_no, $state );

		if ( 'retry' === $outcome && $state['tries'] >= self::MAX_TRIES ) {
			$outcome = 'manual';
			$reason  = \sprintf( '已重試 %d 次仍無法完成（%s）', $state['tries'], $reason );
		}

		self::finish( $trade_no, $state, $outcome, $reason );

		return $outcome;
	}

	/**
	 * 依查詢結果決定取消或退款，並執行
	 *
	 * @param string $trade_no PayUni 交易編號
	 * @param array  $state    狀態（可能被更新 refund_sent）
	 *
	 * @return array{0: string, 1: string} [ 結果, 原因 ]
	 */
	private static function decide_and_act( string $trade_no, array &$state ): array {
		$trade = self::query( $trade_no );

		if ( null === $trade ) {
			if ( ! empty( $state['refund_sent'] ) ) {
				return [ 'retry', '上次退款結果不明且目前查不到交易狀態，等待下次查詢確認，不重送退款' ];
			}
			return self::cancel_or_refund( $trade_no, $state, '查詢交易失敗，直接嘗試取消授權' );
		}

		$amount = $trade['TradeAmt'] ?? null;
		if ( ! \is_numeric( $amount ) || (int) $amount !== self::BINDING_AMOUNT ) {
			return [ 'manual', \sprintf( '查到的交易金額為 %s，不是 5 元綁卡授權，未自動取消或退款', \is_scalar( $amount ) ? (string) $amount : '未知' ) ];
		}

		$trade_status  = (string) ( $trade['TradeStatus'] ?? '' );
		$close_status  = (string) ( $trade['CloseStatus'] ?? '' );
		$refund_status = (string) ( $trade['RefundStatus'] ?? '' );

		if ( '3' === $trade_status || \in_array( $refund_status, [ '1', '2', '8' ], true ) ) {
			return [ 'released', \sprintf( 'PayUni 顯示交易已取消或已退款（TradeStatus=%s，RefundStatus=%s）', $trade_status, $refund_status ?: '-' ) ];
		}

		if ( \in_array( $trade_status, [ '2', '4' ], true ) ) {
			return [ 'not_authorized', \sprintf( '交易未授權成功（TradeStatus=%s），無需釋放', $trade_status ) ];
		}

		if ( '1' !== $trade_status ) {
			return [ 'retry', \sprintf( '交易狀態尚未確定（TradeStatus=%s）', $trade_status ?: '-' ) ];
		}

		if ( ! empty( $state['refund_sent'] ) ) {
			return [ 'retry', '上次退款結果不明，PayUni 尚未顯示退款紀錄，等待下次查詢確認，不重送退款' ];
		}

		if ( '2' === $close_status ) {
			return self::refund( $trade_no, $state, '已請款（CloseStatus=2）' );
		}

		if ( \in_array( $close_status, [ '1', '7' ], true ) ) {
			return [ 'retry', \sprintf( '請款處理中（CloseStatus=%s），暫時無法取消或退款', $close_status ) ];
		}

		return self::cancel_or_refund( $trade_no, $state, \sprintf( '未請款（CloseStatus=%s）', $close_status ?: '-' ) );
	}

	/**
	 * 取消授權；回「已請款」改走退款
	 *
	 * @param string $trade_no PayUni 交易編號
	 * @param array  $state    狀態
	 * @param string $context  判斷依據（寫進原因）
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function cancel_or_refund( string $trade_no, array &$state, string $context ): array {
		try {
			( new HttpClient() )->cancel_trade( $trade_no );
			return [ 'cancelled', $context . '，已取消授權' ];
		} catch ( PayuniApiException $e ) {
			if ( self::is_captured( $e ) ) {
				self::log( 'warning', "5 元綁卡授權取消失敗（已請款），改走退款：{$e->getMessage()}", $trade_no, $state );
				return self::refund( $trade_no, $state, $context . '，取消授權時已請款（' . $e->getMessage() . '）' );
			}
			return [ 'retry', '取消授權失敗：' . $e->getMessage() ];
		} catch ( \Throwable $e ) {
			return [ 'retry', '取消授權請求失敗：' . $e->getMessage() ];
		}
	}

	/**
	 * 退款 5 元（每個 TradeNo 在結果確認前只送一次）
	 *
	 * @param string $trade_no PayUni 交易編號
	 * @param array  $state    狀態
	 * @param string $context  判斷依據
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function refund( string $trade_no, array &$state, string $context ): array {
		// 先落地「已送出退款」，送出後才死掉也不會被下一次重送
		$sent = \array_merge( $state, [ 'refund_sent' => \time() ] );
		if ( ! self::compare_and_swap( $trade_no, self::current_row( $trade_no, $state ), $sent ) ) {
			return [ 'retry', '無法記錄退款狀態，暫不送出退款' ];
		}
		$state = $sent;

		try {
			( new HttpClient() )->refund( $trade_no, self::BINDING_AMOUNT );
			return [ 'refunded', $context . '，已退款 5 元' ];
		} catch ( PayuniApiException $e ) {
			// PayUni 明確拒絕 = 沒有退成，下次可以再退
			unset( $state['refund_sent'] );
			return [ 'retry', '退款失敗：' . $e->getMessage() ];
		} catch ( \Throwable $e ) {
			return [ 'retry', '退款請求結果不明：' . $e->getMessage() ];
		}
	}

	/**
	 * 查詢交易，回傳該 TradeNo 的那一筆；查不到或失敗為 null
	 *
	 * @param string $trade_no PayUni 交易編號
	 *
	 * @return array|null
	 */
	private static function query( string $trade_no ): ?array {
		try {
			$result = ( new HttpClient() )->query_trade( $trade_no );
		} catch ( \Throwable $e ) {
			self::log( 'warning', "5 元綁卡授權釋放：查詢交易失敗：{$e->getMessage()}", $trade_no, [] );
			return null;
		}

		if ( 'SUCCESS' !== ( $result['Status'] ?? '' ) ) {
			return null;
		}

		$rows = $result['Result'] ?? [ $result ];
		foreach ( (array) $rows as $row ) {
			if ( \is_array( $row ) && $trade_no === (string) ( $row['TradeNo'] ?? '' ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * 取消授權失敗的原因是否為「已請款」
	 *
	 * @param PayuniApiException $e 例外
	 *
	 * @return bool
	 */
	private static function is_captured( PayuniApiException $e ): bool {
		return $e->has_status( self::CAPTURED_CODES ) || false !== \strpos( $e->get_inner_message(), '已存在請款' );
	}

	// ──────────────────────────────────────────────
	// 狀態（option）
	// ──────────────────────────────────────────────

	/**
	 * 取得處理權
	 *
	 * @param string $trade_no PayUni 交易編號
	 *
	 * @return array|string 取得時回傳狀態（已標 processing）；否則回傳結果字串（既有結果或 busy）
	 */
	private static function acquire( string $trade_no ) {
		$now   = \time();
		$state = self::read_state( $trade_no );

		if ( null === $state ) {
			$state = [
				'status'    => 'processing',
				'object_id' => 0,
				'tries'     => 0,
				'time'      => $now,
			];
			return self::insert_state( $trade_no, $state ) ? $state : 'busy';
		}

		$status = (string) ( $state['status'] ?? '' );
		if ( \in_array( $status, self::FINAL_STATUSES, true ) ) {
			self::log( 'info', "5 元綁卡授權已處理過（{$status}），略過", $trade_no, $state );
			return $status;
		}

		if ( 'processing' === $status && ( $now - (int) ( $state['time'] ?? 0 ) ) < self::LOCK_TTL ) {
			return 'busy';
		}

		$next = \array_merge(
			$state,
			[
				'status' => 'processing',
				'time'   => $now,
			]
		);

		return self::compare_and_swap( $trade_no, $state, $next ) ? $next : 'busy';
	}

	/**
	 * 寫入結果、備註與 log；需要重試就重排程
	 *
	 * @param string $trade_no PayUni 交易編號
	 * @param array  $state    狀態
	 * @param string $outcome  結果
	 * @param string $reason   原因
	 *
	 * @return void
	 */
	private static function finish( string $trade_no, array $state, string $outcome, string $reason ): void {
		$next = \array_merge(
			$state,
			[
				'status' => $outcome,
				'reason' => \mb_substr( $reason, 0, 500 ),
				'time'   => \time(),
			]
		);
		self::compare_and_swap( $trade_no, self::current_row( $trade_no, $state ), $next );

		if ( 'retry' === $outcome ) {
			self::enqueue( $trade_no, self::RETRY_DELAY );
			self::log( 'warning', \sprintf( '5 元綁卡授權釋放第 %d 次未完成，%d 分鐘後重試：%s', $state['tries'], self::RETRY_DELAY / 60, $reason ), $trade_no, $next );
			return;
		}

		$level = 'manual' === $outcome ? 'error' : 'info';
		self::log( $level, "5 元綁卡授權釋放結果 {$outcome}：{$reason}", $trade_no, $next );
		self::add_note( (int) ( $state['object_id'] ?? 0 ), $trade_no, $outcome, $reason );
	}

	/**
	 * 寫訂單／訂閱備註
	 *
	 * @param int    $object_id 訂單或訂閱 ID
	 * @param string $trade_no  PayUni 交易編號
	 * @param string $outcome   結果
	 * @param string $reason    原因
	 *
	 * @return void
	 */
	private static function add_note( int $object_id, string $trade_no, string $outcome, string $reason ): void {
		if ( $object_id <= 0 ) {
			return;
		}
		$order = \wc_get_order( $object_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$labels = [
			'cancelled'      => 'PayUni 5 元綁卡授權已取消',
			'refunded'       => 'PayUni 5 元綁卡授權已請款，已改為退款 5 元',
			'released'       => 'PayUni 5 元綁卡授權已取消或退款，無需處理',
			'not_authorized' => 'PayUni 5 元綁卡授權未成立，無需處理',
			'manual'         => 'PayUni 5 元綁卡授權無法自動取消或退款，請至 PayUni 後台人工處理',
		];

		$order->add_order_note(
			\esc_html( \sprintf( '%s（交易編號：%s）。%s', $labels[ $outcome ] ?? $outcome, $trade_no, $reason ) )
		);
	}

	/**
	 * 加入排程（Action Scheduler 優先）
	 *
	 * @param string $trade_no PayUni 交易編號
	 * @param int    $delay    延遲秒數
	 *
	 * @return void
	 */
	private static function enqueue( string $trade_no, int $delay ): void {
		// Action Scheduler 有自己的 queue runner 與重試；wp-cron 在快取／停用 cron 的站點容易遺失（icba 實測）。
		if ( \function_exists( 'as_schedule_single_action' ) ) {
			\as_schedule_single_action( \time() + $delay, self::HOOK, [ $trade_no ] );
			return;
		}

		\wp_schedule_single_event( \time() + $delay, self::HOOK, [ $trade_no ] );
	}

	/**
	 * Option 名稱（TradeNo 只留安全字元）
	 *
	 * @param string $trade_no PayUni 交易編號
	 *
	 * @return string
	 */
	private static function option_name( string $trade_no ): string {
		return self::OPTION_PREFIX . \substr( (string) \preg_replace( '/[^A-Za-z0-9_-]/', '', $trade_no ), 0, 100 );
	}

	/**
	 * 直接讀資料庫（不走 options 快取，才能做 compare-and-swap）
	 *
	 * @param string $trade_no PayUni 交易編號
	 *
	 * @return array|null 不存在為 null
	 */
	private static function read_state( string $trade_no ): ?array {
		$raw = self::read_raw( $trade_no );
		if ( null === $raw ) {
			return null;
		}
		$state = \json_decode( $raw, true );

		return \is_array( $state ) ? $state : [];
	}

	/**
	 * 讀原始 option 值
	 *
	 * @param string $trade_no PayUni 交易編號
	 *
	 * @return string|null
	 */
	private static function read_raw( string $trade_no ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap 必須讀到資料庫最新值。
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::option_name( $trade_no ) ) );

		return null === $raw ? null : (string) $raw;
	}

	/**
	 * 目前資料庫的狀態（給 compare-and-swap 當舊值）；讀不到就用記憶體中的
	 *
	 * @param string $trade_no PayUni 交易編號
	 * @param array  $fallback 記憶體中的狀態
	 *
	 * @return array
	 */
	private static function current_row( string $trade_no, array $fallback ): array {
		return self::read_state( $trade_no ) ?? $fallback;
	}

	/**
	 * 新增狀態（已存在就失敗）
	 *
	 * @param string $trade_no PayUni 交易編號
	 * @param array  $state    狀態
	 *
	 * @return bool
	 */
	private static function insert_state( string $trade_no, array $state ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT IGNORE 才是原子的「不存在才建立」，add_option() 不是。
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::option_name( $trade_no ),
				(string) \wp_json_encode( $state )
			)
		);
		self::flush_cache( $trade_no );

		return 1 === (int) $inserted;
	}

	/**
	 * 值仍是 $expected 才寫入 $next（compare-and-swap）
	 *
	 * @param string $trade_no PayUni 交易編號
	 * @param array  $expected 預期的舊值
	 * @param array  $next     新值
	 *
	 * @return bool
	 */
	private static function compare_and_swap( string $trade_no, array $expected, array $next ): bool {
		global $wpdb;

		$old = self::read_raw( $trade_no );
		if ( null === $old ) {
			return self::insert_state( $trade_no, $next );
		}
		$decoded = \json_decode( $old, true );
		if ( ( \is_array( $decoded ) ? $decoded : [] ) != $expected ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- 陣列比對內容，不比鍵順序以外的型別差異。
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 條件 UPDATE 確保同時只有一個程序寫入成功。
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				(string) \wp_json_encode( $next ),
				self::option_name( $trade_no ),
				$old
			)
		);
		self::flush_cache( $trade_no );

		return 1 === (int) $updated;
	}

	/**
	 * 清掉 options 快取（get_option() 才讀得到新值）
	 *
	 * @param string $trade_no PayUni 交易編號
	 *
	 * @return void
	 */
	private static function flush_cache( string $trade_no ): void {
		\wp_cache_delete( self::option_name( $trade_no ), 'options' );
		\wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * 寫 log（context 不含卡號、CreditHash）
	 *
	 * @param string $level    等級
	 * @param string $message  訊息
	 * @param string $trade_no PayUni 交易編號
	 * @param array  $state    狀態
	 *
	 * @return void
	 */
	private static function log( string $level, string $message, string $trade_no, array $state ): void {
		\do_action(
			'woomp_payuni_log',
			$level,
			$message,
			[
				'handler'   => 'authorization_release',
				'trade_no'  => $trade_no,
				'object_id' => (int) ( $state['object_id'] ?? 0 ),
				'tries'     => (int) ( $state['tries'] ?? 0 ),
			]
		);
	}
}

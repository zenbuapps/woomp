<?php
/**
 * PayUni V2 待付款訂單補償對帳器
 *
 * Issue #127：V2 信用卡開 3D 驗證時只送 ReturnURL 不送 NotifyURL，顧客付完款關閉瀏覽器分頁
 * → 網站永遠收不到結果 → 訂單停在 pending → 數日後被「未付款訂單逾期取消」清掉，但錢已經收了。
 *
 * 補上 NotifyURL 是主要修復；本類別是第二層保險：即使幕後通知也遲到或漏收，
 * 依然會主動向 PayUni 反查交易狀態並補完訂單。
 *
 * 與 V3 的 J7\Payuni\Domains\Reconciliation\PendingReconciler 是兩個獨立實作：
 * V3 版第一行就用 CreditV3::ID 擋掉 V2 訂單，且補完動作走 V3 的 TradeHandler（final、
 * token gateway id 一律回 payuni-credit-v3、讀 V3 的 payuni_save_card meta）。
 * 本類別只重用 V3 的 HttpClient::query_trade_by_mer_no()，補完動作委派 Response::complete_paid_order()
 * 以對齊 V2 語意（_payuni_order_suffix 遞增、訂閱單走 active、V2 的 8 個 _payuni_resp_* meta）。
 *
 * @package payuni
 */

namespace PAYUNI\Gateways;

use Payuni\APIs\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * V2 待付款訂單補償對帳器
 */
final class PendingReconciler {

	/**
	 * Action Scheduler / cron hook 名稱
	 *
	 * @var string
	 */
	public const HOOK = 'payuni_v2_reconcile_pending';

	/**
	 * 「查到 PayUni 已付款但站上訂單已被取消／失敗」的標記 meta
	 *
	 * 值為「TradeNo|偵測時間」。這類訂單不自動改狀態（WooCommerce 取消時已回補庫存，
	 * 自動改回 processing 不會重新扣庫存 → 超賣；顧客也可能已收到取消通知），
	 * 只留標記供人工處理與程式化查詢。
	 *
	 * @var string
	 */
	public const META_LOST_PAYMENT = '_payuni_lost_payment_detected';

	/**
	 * 目前已嘗試到第幾階的 meta（不放進排程 args，保持 as_next_scheduled_action 去重簡單）
	 *
	 * @var string
	 */
	private const META_ATTEMPT = '_payuni_reconcile_attempt';

	/**
	 * 排程階梯（秒）
	 *
	 * 180  ：3DS 正常完成在 2 分鐘內，這一階抓「通知漏收」的絕大多數。
	 * 900  ：對應 PayUni 官方對 UNKNOWN 狀態的建議「15 分鐘後再發動交易查詢」。
	 * 2700 ：涵蓋發卡行延遲授權，且遠早於「逾期取消」排程動手的時間。
	 *
	 * @var int[]
	 */
	private const DELAYS = [ 180, 900, 2700 ];

	/**
	 * 適用的 V2 信用卡閘道（與 Request::$is_credit 白名單一致）
	 *
	 * @var string[]
	 */
	private const GATEWAYS = [ 'payuni-credit', 'payuni-credit-installment', 'payuni-credit-subscription' ];

	/**
	 * 註冊 hooks
	 *
	 * @return void
	 */
	public static function init(): void {
		\add_action( self::HOOK, [ __CLASS__, 'reconcile' ], 10, 2 );
	}

	/**
	 * 排程一次延遲補償查詢
	 *
	 * 於 Request::build_request() 進入 3D 驗證分支時呼叫。直接授權成功的訂單不需排程。
	 *
	 * @param \WC_Order $order   訂單物件。
	 * @param int       $attempt 第幾階（0 起算）。
	 *
	 * @return void
	 */
	public static function schedule( \WC_Order $order, int $attempt = 0 ): void {
		$delays = self::get_delays( $order );

		if ( ! isset( $delays[ $attempt ] ) ) {
			return;
		}

		$order_id  = $order->get_id();
		$args      = [ $order_id, $attempt ];
		$timestamp = \time() + (int) $delays[ $attempt ];

		// 優先使用 Action Scheduler（WooCommerce 內建，具 queue runner 與重試機制，
		// 不受低流量站或主機停用 wp-cron 而遺失事件影響）。
		if ( \function_exists( 'as_schedule_single_action' ) ) {
			// 去重：結帳重試可能重複進入 3D 分支。
			if ( \function_exists( 'as_next_scheduled_action' ) && \as_next_scheduled_action( self::HOOK, $args ) ) {
				return;
			}
			\as_schedule_single_action( $timestamp, self::HOOK, $args );
			return;
		}

		if ( \wp_next_scheduled( self::HOOK, $args ) ) {
			return;
		}
		\wp_schedule_single_event( $timestamp, self::HOOK, $args );
	}

	/**
	 * 執行補償查詢（Action Scheduler / cron 回呼）
	 *
	 * @param int|string $order_id 訂單 ID。
	 * @param int|string $attempt  第幾階。
	 *
	 * @return void
	 */
	public static function reconcile( $order_id, $attempt = 0 ): void {
		$order_id = (int) $order_id;
		$attempt  = (int) $attempt;
		$order    = \wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! \in_array( $order->get_payment_method(), self::GATEWAYS, true ) ) {
			return;
		}

		// 已由通知補完 → 結束階梯。
		if ( Response::is_payuni_completed( $order ) ) {
			self::log( "#{$order_id} 補償查詢略過：訂單已完成（通知已補完）" );
			return;
		}

		$status = $order->get_status();

		// 已被取消／失敗的訂單：只查不改，查到已付款就告警。
		if ( \in_array( $status, [ 'cancelled', 'failed' ], true ) ) {
			self::check_lost_payment( $order );
			return;
		}

		if ( 'pending' !== $status ) {
			self::log( "#{$order_id} 補償查詢略過：訂單狀態 {$status} 不在處理範圍" );
			return;
		}

		$order->update_meta_data( self::META_ATTEMPT, $attempt );
		$order->save();

		$outcome = self::query_and_settle( $order );

		if ( 'unresolved' !== $outcome ) {
			return;
		}

		$next = $attempt + 1;

		if ( isset( self::get_delays( $order )[ $next ] ) ) {
			self::schedule( $order, $next );
			return;
		}

		// 階梯跑完仍無定論：留下人工待辦，不要再一次無聲失敗。
		// issue #127 損失 NT$16,800 的關鍵就在於「整件事沒有任何人知道」。
		$order->add_order_note(
			'<strong>⚠ 統一金流補償對帳已用盡重試</strong><br>'
			. '多次查詢仍無法確認交易結果，請至統一金流後台以商店訂單編號 '
			. \esc_html( self::get_mer_trade_no( $order ) )
			. ' 人工核對，確認是否已扣款。'
		);
		$order->save();

		self::log( "#{$order_id} 補償對帳用盡重試，交由人工核對", 'error' );

		/**
		 * 補償對帳階梯用盡仍無法確認交易結果。
		 *
		 * @param \WC_Order $order 訂單物件。
		 */
		\do_action( 'woomp_payuni_v2_reconcile_exhausted', $order );
	}

	/**
	 * 立即同步查詢並補完（供 CREDIT04001 反查使用）
	 *
	 * @param \WC_Order $order   訂單物件。
	 * @param int       $timeout 逾時秒數；HttpClient 內建 60 秒，用在結帳同步流程會把顧客晾在那裡。
	 *
	 * @return bool 已補完為 true；其餘（未付款／金額不符／查詢失敗）一律 false，由呼叫端降級。
	 */
	public static function reconcile_now( \WC_Order $order, int $timeout = 10 ): bool {
		if ( Response::is_payuni_completed( $order ) ) {
			return true;
		}

		$shorten = static function () use ( $timeout ) {
			return $timeout;
		};

		\add_filter( 'http_request_timeout', $shorten, 999 );

		try {
			return 'completed' === self::query_and_settle( $order );
		} finally {
			\remove_filter( 'http_request_timeout', $shorten, 999 );
		}
	}

	/**
	 * 查詢並結算
	 *
	 * @param \WC_Order $order 訂單物件。
	 *
	 * @return string completed（已補完）|failed（PayUni 確認未成功，終態）|unresolved（無定論）。
	 */
	private static function query_and_settle( \WC_Order $order ): string {
		$mer_trade_no = self::get_mer_trade_no( $order );

		if ( '' === $mer_trade_no ) {
			return 'unresolved';
		}

		try {
			$result = self::query_trade( $mer_trade_no );
		} catch ( \Throwable $e ) {
			// 安全降級：查不到就什麼都不動，最差與現況相同。
			self::log( "#{$order->get_id()} 補償查詢失敗：{$e->getMessage()}", 'error' );
			return 'unresolved';
		}

		$trade = self::pick_result_row( $result, $mer_trade_no );

		if ( null === $trade ) {
			self::log( "#{$order->get_id()} 補償查詢：Result 內無 MerTradeNo={$mer_trade_no} 的交易" );
			return 'unresolved';
		}

		// DataSource=B 代表 PayUni 端仍在處理，資料不完整，不可據以判斷。
		if ( 'B' === (string) ( $trade['DataSource'] ?? '' ) ) {
			return 'unresolved';
		}

		$trade_status = (string) ( $trade['TradeStatus'] ?? '' );

		// TradeStatus：0=取號成功 9=未付款 1=已付款 2=付款失敗 3=付款取消 4=交易逾期 8=訂單待確認。
		// CloseStatus 是請款／關帳狀態，剛授權的信用卡單尚未請款，不可用於判斷授權是否成功。
		if ( '1' === $trade_status ) {
			return self::settle_paid( $order, $trade ) ? 'completed' : 'unresolved';
		}

		if ( \in_array( $trade_status, [ '2', '3', '4' ], true ) ) {
			$order->add_order_note(
				\sprintf( '統一金流補償對帳：交易確認未成功（TradeStatus=%s），維持現有狀態。', $trade_status )
			);
			$order->save();
			return 'failed';
		}

		$order->add_order_note(
			\sprintf( '統一金流補償對帳：交易尚未有結果（TradeStatus=%s），稍後再查。', '' !== $trade_status ? $trade_status : '空' )
		);
		$order->save();

		return 'unresolved';
	}

	/**
	 * 交易確認已付款，補完訂單
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @param array     $trade /trade/query 的單筆結果。
	 *
	 * @return bool
	 */
	private static function settle_paid( \WC_Order $order, array $trade ): bool {
		if ( ! self::amount_matches( $order, $trade ) ) {
			$order->add_order_note(
				\sprintf(
					'<strong>⚠ 統一金流補償對帳：金額不符</strong><br>訂單金額 %d，統一金流回報 %d（交易編號 %s）。<br>為避免誤判已付款，未變更訂單狀態，請人工確認。',
					self::order_amount( $order ),
					(int) ( $trade['TradeAmt'] ?? 0 ),
					\esc_html( (string) ( $trade['TradeNo'] ?? '' ) )
				)
			);
			$order->save();

			self::log( "#{$order->get_id()} 補償對帳金額不符：訂單 " . self::order_amount( $order ) . ' / 統一金流 ' . (int) ( $trade['TradeAmt'] ?? 0 ), 'error' );
			return false;
		}

		$original_status = $order->get_status();

		// 交給 Response 的唯一寫入路徑，確保補完的訂單與正常通知寫入完全相同的 V2 meta 集合。
		$formatted = Response::get_formatted_decrypted_data(
			\array_merge(
				$trade,
				[
					'Status'  => 'SUCCESS',
					'Message' => '補償對帳查得已付款',
				]
			)
		);

		$done = Response::complete_paid_order( $order, $formatted, '補償對帳' );

		if ( $done ) {
			$order->add_order_note(
				\sprintf(
					'統一金流補償對帳：未收到交易通知，主動查得交易成功並補完訂單（原狀態：%s，交易編號：%s）。',
					$original_status,
					\esc_html( (string) ( $trade['TradeNo'] ?? '' ) )
				)
			);
			$order->save();
			self::log( "#{$order->get_id()} 補償對帳成功並完成訂單，TradeNo=" . (string) ( $trade['TradeNo'] ?? '' ) );
		}

		return $done;
	}

	/**
	 * 已取消／失敗的訂單：只查不改，查到 PayUni 已付款就告警
	 *
	 * @param \WC_Order $order 訂單物件。
	 *
	 * @return void
	 */
	private static function check_lost_payment( \WC_Order $order ): void {
		// 已告警過就不重複寄信。
		if ( '' !== (string) $order->get_meta( self::META_LOST_PAYMENT ) ) {
			return;
		}

		$mer_trade_no = self::get_mer_trade_no( $order );

		if ( '' === $mer_trade_no ) {
			return;
		}

		try {
			$trade = self::pick_result_row( self::query_trade( $mer_trade_no ), $mer_trade_no );
		} catch ( \Throwable $e ) {
			self::log( "#{$order->get_id()} 取消／失敗訂單反查失敗：{$e->getMessage()}", 'error' );
			return;
		}

		if ( null === $trade || '1' !== (string) ( $trade['TradeStatus'] ?? '' ) ) {
			return;
		}

		self::alert_lost_payment( $order, $trade );
	}

	/**
	 * 告警四件事：訂單備註、標記 meta、結構化 log、站台管理員通知信。全部不改訂單狀態。
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @param array     $trade /trade/query 的單筆結果。
	 *
	 * @return void
	 */
	private static function alert_lost_payment( \WC_Order $order, array $trade ): void {
		$trade_no   = (string) ( $trade['TradeNo'] ?? '' );
		$trade_amt  = (int) ( $trade['TradeAmt'] ?? 0 );
		$order_amt  = self::order_amount( $order );
		$amount_msg = $trade_amt === $order_amt ? '與訂單金額相符' : "與訂單金額 {$order_amt} 不符";

		$order->add_order_note(
			'<strong>⚠ 統一金流已扣款，但本訂單已為「' . \wc_get_order_status_name( $order->get_status() ) . '」</strong><br>'
			. "交易編號：{$trade_no}<br>統一金流回報金額：{$trade_amt}（{$amount_msg}）<br>"
			. '未自動變更訂單狀態（取消時庫存已回補、顧客可能已收到取消通知），請人工確認後決定補單或退款。'
		);
		$order->update_meta_data( self::META_LOST_PAYMENT, $trade_no . '|' . \time() );
		$order->save();

		self::log( "#{$order->get_id()} 偵測到已扣款但訂單為 {$order->get_status()}，TradeNo={$trade_no} 金額={$trade_amt}", 'error' );

		$admin_email = (string) \get_option( 'admin_email' );
		if ( '' !== $admin_email && \is_email( $admin_email ) ) {
			\wp_mail(
				$admin_email,
				\sprintf( '[%s] 統一金流已扣款但訂單 #%s 已被取消，需人工處理', \wp_specialchars_decode( \get_bloginfo( 'name' ) ), $order->get_order_number() ),
				"訂單 #{$order->get_order_number()}（狀態：{$order->get_status()}）\n"
				. "統一金流交易編號：{$trade_no}\n"
				. "統一金流回報金額：{$trade_amt}（{$amount_msg}）\n\n"
				. "系統未自動變更訂單狀態，請登入後台確認後決定補單或退款：\n"
				. $order->get_edit_order_url()
			);
		}
	}

	/**
	 * 送出交易時實際使用的 MerTradeNo
	 *
	 * 優先讀送出當下落地的 meta；舊訂單沒有這個 meta 時，以與 Request::get_transaction_args()
	 * 完全相同的規則重建（不是 V3 的 get_order_number()——V2 用的是 get_id() 加 suffix）。
	 *
	 * @param \WC_Order $order 訂單物件。
	 *
	 * @return string
	 */
	private static function get_mer_trade_no( \WC_Order $order ): string {
		$stored = (string) $order->get_meta( '_payuni_mer_trade_no' );

		if ( '' !== $stored ) {
			return $stored;
		}

		$suffix = $order->get_meta( '_payuni_order_suffix' ) ? '-' . $order->get_meta( '_payuni_order_suffix' ) : '';

		return $order->get_id() . $suffix;
	}

	/**
	 * 從 Result 陣列挑出對應的那一筆
	 *
	 * 比對 MerTradeNo 而非盲取 Result[0]：同一張訂單可能重刷多次，各有不同流水號。
	 *
	 * @param array  $result       解密後的查詢結果。
	 * @param string $mer_trade_no 商店訂單編號。
	 *
	 * @return array|null
	 */
	private static function pick_result_row( array $result, string $mer_trade_no ): ?array {
		$rows = $result['Result'] ?? [];

		if ( ! \is_array( $rows ) || ! $rows ) {
			return null;
		}

		foreach ( $rows as $row ) {
			if ( \is_array( $row ) && (string) ( $row['MerTradeNo'] ?? '' ) === $mer_trade_no ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * 以商店訂單編號查詢交易
	 *
	 * V2 唯一與 V3 namespace 接觸的地方，刻意收斂成單一方法：
	 * V3 的 SettingDTO 讀的憑證正是 V2 的舊 option key（payuni_payment_merchant_no 等），
	 * EncryptUtils 與 V2 的 Payment::encrypt/decrypt 逐行等價且多了解密失敗防呆，
	 * 且 V2 與 V3 共用同一個載入條件（payuni.php），不會出現「只載入其中一邊」。
	 * 將來若要脫鉤，只需要換掉這一個方法。
	 *
	 * @param string $mer_trade_no 商店訂單編號。
	 *
	 * @return array
	 * @throws \RuntimeException V3 HttpClient not loaded.
	 */
	private static function query_trade( string $mer_trade_no ): array {
		if ( ! \class_exists( \J7\Payuni\Infrastructure\Http\HttpClient::class ) ) {
			throw new \RuntimeException( 'PayUni v3 HttpClient 未載入，無法執行補償對帳查詢' );
		}

		return ( new \J7\Payuni\Infrastructure\Http\HttpClient() )->query_trade_by_mer_no( $mer_trade_no );
	}

	/**
	 * 排程階梯（可由站方調整）
	 *
	 * @param \WC_Order $order 訂單物件。
	 *
	 * @return int[]
	 */
	private static function get_delays( \WC_Order $order ): array {
		/**
		 * 調整補償對帳的重試階梯（秒）。
		 *
		 * @param int[]     $delays 預設 [ 180, 900, 2700 ]。
		 * @param \WC_Order $order  訂單物件。
		 */
		$delays = \apply_filters( 'woomp_payuni_v2_reconcile_delays', self::DELAYS, $order );

		return \is_array( $delays ) ? \array_values( $delays ) : self::DELAYS;
	}

	/**
	 * 訂單金額（與送往 PayUni 的 TradeAmt 同樣取整）
	 *
	 * @param \WC_Order $order 訂單物件。
	 *
	 * @return int
	 */
	private static function order_amount( \WC_Order $order ): int {
		return (int) \round( (float) $order->get_total() );
	}

	/**
	 * PayUni 回報金額是否與訂單金額相符
	 *
	 * 交易編號對得上不代表金額對得上（訂單可能被後台改過、或 woomp_copy_order() 產生的分身）。
	 * 金額不符時寧可不動，留人工判斷。
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @param array     $trade /trade/query 的單筆結果。
	 *
	 * @return bool
	 */
	private static function amount_matches( \WC_Order $order, array $trade ): bool {
		return self::order_amount( $order ) === (int) ( $trade['TradeAmt'] ?? 0 );
	}

	/**
	 * 記錄 log（同時走 V2 的 Payment::log 與 V3 的 woomp_payuni_log 管線）
	 *
	 * @param string $message 訊息。
	 * @param string $level   等級。
	 *
	 * @return void
	 */
	private static function log( string $message, string $level = 'info' ): void {
		Payment::log( '[補償對帳] ' . $message, $level );
		\do_action( 'woomp_payuni_log', $level, '[V2 補償對帳] ' . $message, [] );
	}
}

PendingReconciler::init();

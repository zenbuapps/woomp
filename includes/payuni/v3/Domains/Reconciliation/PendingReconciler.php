<?php
/**
 * PayUni V3 待付款訂單補償對帳器
 *
 * 解決 issue #125：3DS 信用卡訂單完成 100% 依賴背景 NotifyURL webhook，
 * 當 webhook 遲到或漏收時，顧客已扣款但訂單永遠停在「等待付款中」，
 * 連帶出貨、電子發票、通知信全部卡住。
 *
 * 本類別於 process_payment 進入 3DS pending 分支時排程一次延遲查詢，
 * 主動向 PayUni 以 MerTradeNo 反查交易狀態，補完 webhook 未完成的訂單。
 *
 * @package J7\Payuni\Domains\Reconciliation
 */

declare(strict_types=1);

namespace J7\Payuni\Domains\Reconciliation;

use J7\Payuni\Infrastructure\Http\HttpClient;
use J7\Payuni\Infrastructure\Http\TradeHandler;
use PAYUNI\Gateways\CreditV3;

\defined('ABSPATH') || exit;

/**
 * 待付款訂單補償對帳器
 */
final class PendingReconciler {

	/** @var string Action Scheduler / cron hook 名稱 */
	public const HOOK = 'payuni_v3_reconcile_pending';

	/** @var int 預設延遲秒數（訂單建立後多久主動查詢） */
	private const DEFAULT_DELAY = 120;

	/**
	 * 排程一次延遲補償查詢
	 *
	 * 於 CreditV3::process_payment() 進入 3DS 驗證（pending）分支時呼叫。
	 * 直接授權成功（已有 TradeNo）的訂單不需排程。
	 *
	 * @param \WC_Order $order 訂單物件
	 *
	 * @return void
	 */
	public static function schedule( \WC_Order $order ): void {
		$order_id = $order->get_id();

		// 允許商家調整延遲秒數（預設 120 秒）。
		$delay = (int) \apply_filters( 'woomp_payuni_v3_reconcile_delay', self::DEFAULT_DELAY, $order );
		if ( $delay < 0 ) {
			$delay = self::DEFAULT_DELAY;
		}

		// 優先使用 Action Scheduler（WooCommerce 內建，具 queue runner 與重試機制，
		// 不受低流量站或主機停用 wp-cron 而遺失事件影響）。與 SubscriptionHandler 相同策略。
		if ( \function_exists( 'as_schedule_single_action' ) ) {
			// 去重：同一訂單已排程則不重複（避免結帳重試造成多筆排程）。
			if ( \function_exists( 'as_next_scheduled_action' )
				&& \as_next_scheduled_action( self::HOOK, [ $order_id ] ) ) {
				return;
			}
			\as_schedule_single_action( \time() + $delay, self::HOOK, [ $order_id ] );
			return;
		}

		if ( \wp_next_scheduled( self::HOOK, [ $order_id ] ) ) {
			return;
		}
		\wp_schedule_single_event( \time() + $delay, self::HOOK, [ $order_id ] );
	}

	/**
	 * 執行補償查詢（Action Scheduler / cron 回呼）
	 *
	 * 若訂單仍為 pending，向 PayUni 以 MerTradeNo 反查交易狀態：
	 * - TradeStatus=1（已付款）→ 補完訂單（payment_complete）。
	 * - 其餘（未付款／失敗／查無）→ 維持 pending，允許顧客重試。
	 * 查詢失敗僅記錄，不改動訂單（安全降級，最差與現況相同）。
	 *
	 * @param int $order_id 訂單 ID
	 *
	 * @return void
	 */
	public static function reconcile( int $order_id ): void {
		$order = \wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// 僅處理 PayUni v3 信用卡訂單。
		if ( CreditV3::ID !== $order->get_payment_method() ) {
			return;
		}

		// 冪等：webhook 可能已於 120 秒內補完，狀態已非 pending 則不動作。
		if ( 'pending' !== $order->get_status() ) {
			\do_action( 'woomp_payuni_log', 'info', "#{$order_id} 補償查詢略過：訂單已非 pending（webhook 已補完）", [
				'order_id' => $order_id,
				'status'   => $order->get_status(),
			] );
			return;
		}

		try {
			// 以送出交易時相同的 MerTradeNo 反查（TradeReqHashDTO 送 get_order_number()），
			// 不依賴 webhook 的 wc_get_order 反查邏輯，繞過訂單序號／前綴外掛造成的反查落差（issue 假設 A）。
			$mer_trade_no = $order->get_order_number();
			$result       = ( new HttpClient() )->query_trade_by_mer_no( $mer_trade_no );

			// /trade/query 回傳 Result 陣列，取第一筆。
			$trade        = $result['Result'][0] ?? [];
			$trade_status = (string) ( $trade['TradeStatus'] ?? '' );
			$trade_no     = (string) ( $trade['TradeNo'] ?? '' );

			// TradeStatus=1 代表已付款（與 ECPay 退款前置查詢判讀慣例一致）；
			// CloseStatus 為「請款/關帳」狀態，剛授權的信用卡單尚未請款，不可用於判斷授權是否成功。
			if ( '1' === $trade_status && '' !== $trade_no ) {
				// 映射為 TradeHandler::update_order_status 可辨識的結構（補上 Status=SUCCESS）。
				$mapped = \array_merge( $trade, [ 'Status' => 'SUCCESS' ] );
				( new TradeHandler() )->update_order_status( $order, $mapped, true );

				$order->add_order_note(
					\sprintf( 'PayUni 補償查詢：webhook 未於時限內補完，已主動查得交易成功並完成訂單（TradeNo=%s）。', $trade_no )
				);
				\do_action( 'woomp_payuni_log', 'info', "#{$order_id} 補償查詢成功並完成訂單", [
					'order_id' => $order_id,
					'trade_no' => $trade_no,
				] );
				return;
			}

			// 尚未成功 → 維持 pending。
			$order->add_order_note(
				\sprintf(
					'PayUni 補償查詢：交易尚未成功（TradeStatus=%s），維持等待付款中。',
					'' !== $trade_status ? $trade_status : '查無交易'
				)
			);
			$order->save();
			\do_action( 'woomp_payuni_log', 'info', "#{$order_id} 補償查詢：交易尚未成功，維持 pending", [
				'order_id'     => $order_id,
				'trade_status' => $trade_status,
			] );
		} catch ( \Throwable $e ) {
			// 安全降級：查詢失敗不改動訂單狀態，僅記錄，最差與現況相同。
			\do_action( 'woomp_payuni_log', 'error', "#{$order_id} 補償查詢失敗: {$e->getMessage()}", [
				'order_id' => $order_id,
			] );
		}
	}
}

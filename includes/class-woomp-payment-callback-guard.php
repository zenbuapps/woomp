<?php
/**
 * 金流回呼共用驗證
 *
 * 所有金流服務商的回呼網址（wc-api、瀏覽器導回）都是公開網址，任何人都能 POST。
 * 改訂單付款狀態前，各 handler 都必須依序通過：
 *   1. 來源驗證：簽章（金鑰不可為空）或伺服器主動向金流商查詢
 *   2. 訂單綁定：訂單付款方式屬於本金流、交易編號與訂單上存的一致
 *   3. 金額：回呼／查詢金額等於訂單金額
 *   4. 狀態轉換：只有尚未付款且可付款的訂單才能被標成已付款（已取消、已退款不可被重放改回）
 *
 * 本類別只提供 2～4 的共用判斷與記錄；簽章演算法各家不同，留在各金流模組。
 *
 * @package Woomp
 */

defined( 'ABSPATH' ) || exit;

/**
 * 金流回呼共用驗證
 */
final class Woomp_Payment_Callback_Guard {

	/**
	 * Log source
	 */
	const LOG_SOURCE = 'woomp-payment-callback';

	/**
	 * 可以被回呼標成「已付款」的訂單狀態
	 *
	 * 不含 cancelled／refunded：WC 的 payment_complete() 允許 cancelled → processing，
	 * 消費者可以把自己付款時拿到的合法成功通知，在商家取消／退款後重送。
	 *
	 * @return string[]
	 */
	public static function get_payable_statuses(): array {
		return (array) apply_filters( 'woomp_payment_callback_payable_statuses', [ 'pending', 'on-hold', 'failed' ] );
	}

	/**
	 * 訂單目前是否可以被回呼標成已付款
	 *
	 * @param WC_Order $order 訂單
	 */
	public static function can_mark_paid( WC_Order $order ): bool {
		if ( $order->is_paid() ) {
			return false;
		}
		return $order->has_status( self::get_payable_statuses() );
	}

	/**
	 * 金鑰是否都已設定（任一為空就視為未設定）
	 *
	 * 金鑰為空時，簽章可被任何人算出，驗章必須 fail-closed。
	 *
	 * @param mixed ...$secrets 金鑰
	 */
	public static function has_secrets( ...$secrets ): bool {
		if ( empty( $secrets ) ) {
			return false;
		}
		foreach ( $secrets as $secret ) {
			if ( ! is_scalar( $secret ) || '' === trim( (string) $secret ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 常數時間、不分大小寫比對簽章；任一方為空一律 false
	 *
	 * @param mixed $known 自己算的簽章
	 * @param mixed $user  回呼帶來的簽章
	 */
	public static function signature_equals( $known, $user ): bool {
		if ( ! is_string( $known ) || ! is_string( $user ) || '' === $known || '' === $user ) {
			return false;
		}
		return hash_equals( strtoupper( $known ), strtoupper( $user ) );
	}

	/**
	 * 訂單付款方式是否屬於本金流
	 *
	 * @param WC_Order        $order    訂單
	 * @param string|string[] $prefixes 付款方式 ID 或前綴（例如 'ry_ecpay_'）
	 */
	public static function is_payment_method( WC_Order $order, $prefixes ): bool {
		$method = (string) $order->get_payment_method();
		if ( '' === $method ) {
			return false;
		}
		foreach ( (array) $prefixes as $prefix ) {
			$prefix = (string) $prefix;
			if ( '' !== $prefix && 0 === strpos( $method, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 回呼金額是否等於訂單金額
	 *
	 * 台灣金流多以整數元送出：接受與訂單總額相差 < 0.01，或等於無條件進位／四捨五入後的整數。
	 *
	 * @param WC_Order $order  訂單
	 * @param mixed    $amount 回呼金額
	 */
	public static function amount_matches( WC_Order $order, $amount ): bool {
		if ( ! is_numeric( $amount ) ) {
			return false;
		}
		$amount = (float) $amount;
		$total  = (float) $order->get_total();

		if ( abs( $amount - $total ) < 0.01 ) {
			return true;
		}
		return abs( $amount - ceil( $total ) ) < 0.01 || abs( $amount - round( $total ) ) < 0.01;
	}

	/**
	 * 記錄被拒絕的回呼（error 等級，不受各金流除錯開關影響）
	 *
	 * 不可把金鑰、token、完整卡號寫進 context。
	 *
	 * @param string $provider 金流代號
	 * @param string $reason   拒絕原因
	 * @param array  $context  其他資訊
	 */
	public static function reject( string $provider, string $reason, array $context = [] ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf( '[%s] callback rejected: %s %s', $provider, $reason, $context ? wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) : '' ),
				[ 'source' => self::LOG_SOURCE ]
			);
		}
		do_action( 'woomp_payment_callback_rejected', $provider, $reason, $context );
	}
}

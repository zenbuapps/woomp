<?php
/**
 * PayUni V3 log 遮罩
 *
 * 沿用 V1 `PAYUNI\APIs\Payment::mask_log_message()` 的做法（敏感欄位整個換成「[已遮罩]」、遞迴處理巢狀陣列），
 * 但 V1 類別只在啟用 PayUni 金流時才載入，V3 的 log 不能依賴它，所以在 V3 自己提供一份。
 *
 * 遮罩欄位：
 * - CreditHash：約定信用卡代碼，搭配商店金鑰即可幕後扣款。
 * - Card6No：卡號前六碼（與末四碼合起來會縮小卡號範圍；末四碼 Card4No 保留供客服辨識）。
 * - CardNo／CardCVC／CardExpired：完整卡號與驗證資料（V3 不應出現，防萬一）。
 * - card_hash／card_6no：TradeHandler 除錯 log 使用的別名。
 * - sdk_token／sdk_token_tmp：前端一次性 SDK token。
 *
 * @package J7\Payuni\Shared\Utils
 */

declare( strict_types = 1 );

namespace J7\Payuni\Shared\Utils;

/**
 * Log 遮罩
 */
final class LogMaskUtils {

	/**
	 * 遮罩後的值
	 *
	 * @var string
	 */
	public const MASK = '[已遮罩]';

	/**
	 * 要遮罩的欄位（小寫比對）
	 *
	 * @var string[]
	 */
	private const SENSITIVE_KEYS = [
		'credithash',
		'card6no',
		'cardno',
		'cardcvc',
		'cardexpired',
		'card_hash',
		'card_6no',
		'sdk_token',
		'sdk_token_tmp',
	];

	/**
	 * 遞迴遮罩敏感欄位（不改原陣列，回傳新陣列）
	 *
	 * @param array $data 要寫進 log 的資料
	 *
	 * @return array
	 */
	public static function mask( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( \in_array( \strtolower( (string) $key ), self::SENSITIVE_KEYS, true ) ) {
				$data[ $key ] = ( null === $value || '' === $value ) ? $value : self::MASK;
				continue;
			}
			if ( \is_array( $value ) ) {
				$data[ $key ] = self::mask( $value );
			}
		}

		return $data;
	}
}

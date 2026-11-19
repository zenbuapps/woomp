<?php
/**
 * PayUni API 回應的驗章、解密與失敗判斷
 *
 * PayUni 外層 Status 不是 SUCCESS 時（例如 CANCEL03001）仍會附上 EncryptInfo，真正的原因在解密後的內層 Message。
 * 以前 HttpClient 只看外層、對照表沒有的碼一律寫「未知錯誤」，排查時看不到原因。
 *
 * @package J7\Payuni\Infrastructure\Http
 */

declare( strict_types = 1 );

namespace J7\Payuni\Infrastructure\Http;

use J7\Payuni\Shared\Utils\EncryptUtils;

/**
 * 回應解碼
 */
final class ResponseDecoder {

	/**
	 * 驗章後解密 EncryptInfo；缺資料、驗章失敗或解不開回空陣列
	 *
	 * @param array $response 外層回應
	 *
	 * @return array
	 */
	public static function decrypt_verified( array $response ): array {
		$encrypt_info = $response['EncryptInfo'] ?? '';
		$hash_info    = $response['HashInfo'] ?? '';
		if ( ! \is_string( $encrypt_info ) || ! \is_string( $hash_info ) || '' === $encrypt_info || '' === $hash_info ) {
			return [];
		}
		if ( ! EncryptUtils::has_secrets() || ! \hash_equals( EncryptUtils::hash_info( $encrypt_info ), \strtoupper( $hash_info ) ) ) {
			return [];
		}

		return EncryptUtils::decrypt( $encrypt_info );
	}

	/**
	 * 外層 Status 不是 SUCCESS 時，組出含內層原因的例外
	 *
	 * @param array                $response     外層回應
	 * @param array<string,string> $error_mapper 錯誤碼對照表
	 *
	 * @return PayuniApiException
	 */
	public static function failure( array $response, array $error_mapper ): PayuniApiException {
		$outer = (string) ( $response['Status'] ?? '' );
		$inner = self::decrypt_verified( $response );

		return new PayuniApiException(
			self::message( $outer, $inner, $error_mapper ),
			$outer,
			(string) ( $inner['Status'] ?? '' ),
			(string) ( $inner['Message'] ?? '' )
		);
	}

	/**
	 * 異動類 API（取消授權、退款）：外層 SUCCESS 只代表 PayUni 收到請求，內層也必須是 SUCCESS
	 *
	 * @param array                $response     外層回應（外層已是 SUCCESS）
	 * @param array<string,string> $error_mapper 錯誤碼對照表
	 *
	 * @return array 解密後的內層
	 * @throws PayuniApiException When the inner Status is not SUCCESS.
	 */
	public static function require_inner_success( array $response, array $error_mapper ): array {
		$inner = self::decrypt_verified( $response );
		if ( 'SUCCESS' !== ( $inner['Status'] ?? '' ) ) {
			// 訊息只進 log 與訂單備註（寫入時再跳脫），不直接輸出
			$exception = new PayuniApiException(
				self::message( '', $inner, $error_mapper ),
				(string) ( $response['Status'] ?? '' ),
				(string) ( $inner['Status'] ?? '' ),
				(string) ( $inner['Message'] ?? '' )
			);
			throw $exception;
		}

		return $inner;
	}

	/**
	 * 錯誤碼 + 對照表說明 + 解密後的內層狀態與訊息
	 *
	 * @param string               $outer        外層 Status（空字串表示外層成功）
	 * @param array                $inner        解密後的內層
	 * @param array<string,string> $error_mapper 錯誤碼對照表
	 *
	 * @return string
	 */
	private static function message( string $outer, array $inner, array $error_mapper ): string {
		$inner_status  = (string) ( $inner['Status'] ?? '' );
		$inner_message = (string) ( $inner['Message'] ?? '' );
		$code          = ( '' !== $outer && 'SUCCESS' !== $outer ) ? $outer : $inner_status;
		$mapped        = (string) ( $error_mapper[ $code ] ?? '' );
		$description   = '' !== $mapped ? $mapped : ( '' !== $inner_message ? $inner_message : '未知錯誤' );
		$message       = ( '' !== $code ? $code : 'UNKNOWN' ) . ': ' . $description;

		if ( '' !== $inner_status || '' !== $inner_message ) {
			$message .= \sprintf( '（PayUni 回應 %s：%s）', '' !== $inner_status ? $inner_status : '-', '' !== $inner_message ? $inner_message : '-' );
		}

		return $message;
	}
}

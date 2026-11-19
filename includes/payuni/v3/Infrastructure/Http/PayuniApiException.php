<?php
/**
 * PayUni API 明確回覆失敗的例外
 *
 * 與網路錯誤、非 JSON 回應等「結果不明」的例外區分：這個例外代表 PayUni 已收到請求並明確拒絕
 * （外層或內層 Status 不是 SUCCESS），呼叫端可以依狀態碼決定下一步（例如取消授權回「已請款」改走退款）。
 *
 * @package J7\Payuni\Infrastructure\Http
 */

declare( strict_types = 1 );

namespace J7\Payuni\Infrastructure\Http;

/**
 * PayUni 明確失敗
 */
final class PayuniApiException extends \Exception {

	/**
	 * 外層 Status（錯誤碼或 SUCCESS）
	 *
	 * @var string
	 */
	private string $outer_status;

	/**
	 * 解密後的內層 Status（解不開為空字串）
	 *
	 * @var string
	 */
	private string $inner_status;

	/**
	 * 解密後的內層 Message（解不開為空字串）
	 *
	 * @var string
	 */
	private string $inner_message;

	/**
	 * Constructor
	 *
	 * @param string $message       例外訊息
	 * @param string $outer_status  外層 Status
	 * @param string $inner_status  內層 Status
	 * @param string $inner_message 內層 Message
	 */
	public function __construct( string $message, string $outer_status, string $inner_status = '', string $inner_message = '' ) {
		parent::__construct( $message );
		$this->outer_status  = $outer_status;
		$this->inner_status  = $inner_status;
		$this->inner_message = $inner_message;
	}

	/**
	 * 外層 Status
	 *
	 * @return string
	 */
	public function get_outer_status(): string {
		return $this->outer_status;
	}

	/**
	 * 內層 Status
	 *
	 * @return string
	 */
	public function get_inner_status(): string {
		return $this->inner_status;
	}

	/**
	 * 內層 Message
	 *
	 * @return string
	 */
	public function get_inner_message(): string {
		return $this->inner_message;
	}

	/**
	 * 外層或內層是否為指定錯誤碼之一
	 *
	 * @param string[] $codes 錯誤碼
	 *
	 * @return bool
	 */
	public function has_status( array $codes ): bool {
		return \in_array( $this->outer_status, $codes, true ) || \in_array( $this->inner_status, $codes, true );
	}
}

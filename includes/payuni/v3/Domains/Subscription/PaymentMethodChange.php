<?php
/**
 * PayUni V3 定期定額「更換付款方式」
 *
 * 第一階段（issue #147）只註冊 WCS 的付款 meta：
 * - `woocommerce_subscription_payment_meta`：告訴 WCS V3 需要 `_payuni_token_id`，
 *   「同時更新所有訂閱」與後台改付款方式才會帶上它。
 * - `woocommerce_subscription_validate_payment_meta_{V3}`：寫入的 token 必須是訂閱客戶自己的 V3 token。
 *
 * @package J7\Payuni\Domains\Subscription
 */

declare(strict_types=1);

namespace J7\Payuni\Domains\Subscription;

\defined( 'ABSPATH' ) || exit;

/**
 * 定期定額換卡流程
 */
final class PaymentMethodChange {

	/**
	 * V3 定期定額閘道 ID
	 *
	 * @var string
	 */
	private const V3_GATEWAY_ID = 'payuni-credit-subscription-v3';

	/**
	 * 訂閱綁定的 token meta key
	 *
	 * @var string
	 */
	public const META_TOKEN_ID = '_payuni_token_id';

	/**
	 * 可綁到 V3 訂閱的 token 閘道
	 *
	 * @var string[]
	 */
	private const ALLOWED_TOKEN_GATEWAYS = [ self::V3_GATEWAY_ID, 'payuni-credit-v3' ];

	/**
	 * 註冊 Hooks
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		\add_filter( 'woocommerce_subscription_payment_meta', [ __CLASS__, 'add_payment_meta' ], 10, 2 );
		\add_action(
			'woocommerce_subscription_validate_payment_meta_' . self::V3_GATEWAY_ID,
			[ __CLASS__, 'validate_payment_meta' ],
			10,
			2
		);
	}

	/**
	 * 註冊 V3 的付款 meta 欄位
	 *
	 * @param array $payment_meta 各閘道的付款 meta
	 * @param mixed $subscription 訂閱物件
	 *
	 * @return array
	 */
	public static function add_payment_meta( $payment_meta, $subscription ): array {
		$payment_meta = \is_array( $payment_meta ) ? $payment_meta : [];
		$value        = $subscription instanceof \WC_Order ? (string) $subscription->get_meta( self::META_TOKEN_ID ) : '';

		$payment_meta[ self::V3_GATEWAY_ID ] = [
			'post_meta' => [
				self::META_TOKEN_ID => [
					'value' => $value,
					'label' => 'PayUni V3 Token ID',
				],
			],
		];

		return $payment_meta;
	}

	/**
	 * 驗證 V3 付款 meta
	 *
	 * 空值通過（保留後台手動改成 V3、續扣走 fallback 的做法）；
	 * 非空必須是訂閱客戶自己的 V3 token。
	 *
	 * @param mixed $payment_meta 此閘道的付款 meta
	 * @param mixed $subscription 訂閱物件
	 *
	 * @return void
	 * @throws \Exception When the token is not the subscriber's own PayUni V3 token.
	 */
	public static function validate_payment_meta( $payment_meta, $subscription ): void {
		$raw = \is_array( $payment_meta ) ? ( $payment_meta['post_meta'][ self::META_TOKEN_ID ]['value'] ?? '' ) : '';
		$raw = \is_scalar( $raw ) ? \trim( (string) $raw ) : '';

		if ( '' === $raw ) {
			return;
		}

		if ( ! \ctype_digit( $raw ) || ! $subscription instanceof \WC_Order ) {
			throw new \Exception( 'PayUni V3 Token ID 格式不正確。' );
		}

		$customer_id = (int) $subscription->get_customer_id();
		$token       = \WC_Payment_Tokens::get( (int) $raw );

		if ( ! $token instanceof \WC_Payment_Token
			|| $customer_id <= 0
			|| (int) $token->get_user_id() !== $customer_id
			|| ! \in_array( $token->get_gateway_id(), self::ALLOWED_TOKEN_GATEWAYS, true ) ) {
			throw new \Exception( 'PayUni V3 Token ID 無效：必須是此訂閱客戶自己的 PayUni V3 信用卡 Token。' );
		}
	}
}

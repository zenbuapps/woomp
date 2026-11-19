<?php
/**
 * PayUni V3 定期定額「更換付款方式」付款 meta 整合測試（issue #147）
 *
 * 範圍（第一階段）：T-V1。WCS 後台／換卡流程依賴 `_payuni_token_id` 這個訂閱 meta：
 * - `woocommerce_subscription_payment_meta`：註冊 V3 的付款 meta 欄位，
 *   否則「同時更新所有訂閱」會因為沒有付款 meta 而失敗。
 * - `woocommerce_subscription_validate_payment_meta_payuni-credit-subscription-v3`：
 *   驗證寫入的 token 必須是該訂閱客戶自己的 V3 token。
 *
 * WooCommerce Subscriptions 未載入時，需要真正訂閱物件的案例會 markTestSkipped；
 * 「hook 有沒有註冊」的案例不需要 WCS，仍會執行。
 *
 * 後續階段（T-C*、T-N*、T-M1）會陸續加到本檔。
 *
 * 執行指令：
 * docker exec <woomp-tests-cli> sh -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --filter SubscriptionV3PaymentMethodChange'
 *
 * @package Woomp\Tests\Integration
 */

require_once dirname( __DIR__ ) . '/support/WcsFixture.php';

/**
 * V3 定期定額付款 meta 測試類別
 *
 * @group subscription
 * @group payuni
 */
final class SubscriptionV3PaymentMethodChangeTest extends WP_UnitTestCase {

	private const GATEWAY_ID = 'payuni-credit-subscription-v3';
	private const VALIDATE_HOOK = 'woocommerce_subscription_validate_payment_meta_payuni-credit-subscription-v3';
	private const META_FILTER   = 'woocommerce_subscription_payment_meta';

	/**
	 * 測試用客戶 ID
	 *
	 * @var int
	 */
	private int $customer_id;

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wc_woomp_enabled_payuni_gateway', 'yes' );
		update_option( 'payuni_payment_testmode', 'yes' );
		update_option( 'payuni_payment_merchant_no_test', 'TEST_MERCHANT' );
		update_option( 'payuni_payment_hash_key_test', 'TEST_HASH_KEY_1234567890123456' );
		update_option( 'payuni_payment_hash_iv_test', 'TEST_HASH_IV_123456' );

		$this->customer_id = $this->factory()->user->create( [ 'role' => 'customer' ] );
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		foreach ( \WC_Payment_Tokens::get_customer_tokens( $this->customer_id ) as $token ) {
			\WC_Payment_Tokens::delete( $token->get_id() );
		}

		parent::tearDown();
	}

	// ──────────────────────────────────────────────
	// 不需要 WCS：hook 註冊
	// ──────────────────────────────────────────────

	/**
	 * T-V1：付款 meta 的驗證 hook 與欄位 filter 必須由 PaymentMethodChange 註冊
	 *
	 * @group smoke
	 */
	public function test_T_V1_付款meta相關hook已註冊(): void {
		if ( ! class_exists( \J7\Payuni\Domains\Subscription\PaymentMethodChange::class ) ) {
			$this->fail( '尚未實作: J7\Payuni\Domains\Subscription\PaymentMethodChange（付款 meta hooks 的註冊處）' );
		}

		$this->assertNotFalse(
			has_action( self::VALIDATE_HOOK ),
			'應註冊 ' . self::VALIDATE_HOOK . ' 驗證 token 歸屬'
		);
		$this->assertNotFalse(
			has_filter( self::META_FILTER ),
			'應註冊 ' . self::META_FILTER . '，讓 WCS 知道 V3 需要 _payuni_token_id'
		);
	}

	// ──────────────────────────────────────────────
	// 需要 WCS：付款 meta 驗證與欄位
	// ──────────────────────────────────────────────

	/**
	 * T-V1：付款 meta 的 token 是別人的 → 丟例外
	 *
	 * @group security
	 */
	public function test_T_V1_驗證別人的token丟例外(): void {
		$subscription = $this->create_subscription_or_skip();
		$other        = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$foreign      = $this->create_token( $other, 'foreign_hash', self::GATEWAY_ID );

		$this->expectException( \Exception::class );
		do_action( self::VALIDATE_HOOK, $this->meta_with_token( (string) $foreign->get_id() ), $subscription );
	}

	/**
	 * T-V1：付款 meta 的 token 是 V1 token → 丟例外
	 *
	 * @group edge
	 */
	public function test_T_V1_驗證v1_token丟例外(): void {
		$subscription = $this->create_subscription_or_skip();
		$v1_token     = $this->create_token( $this->customer_id, 'v1_hash', 'payuni-credit-subscription' );

		$this->expectException( \Exception::class );
		do_action( self::VALIDATE_HOOK, $this->meta_with_token( (string) $v1_token->get_id() ), $subscription );
	}

	/**
	 * T-V1：付款 meta 的 token 是客戶自己的 V3 token → 通過
	 *
	 * @group happy
	 */
	public function test_T_V1_驗證自己的v3_token通過(): void {
		$subscription = $this->create_subscription_or_skip();
		$own          = $this->create_token( $this->customer_id, 'own_hash', self::GATEWAY_ID );

		if ( false === has_action( self::VALIDATE_HOOK ) ) {
			$this->fail( '尚未實作: ' . self::VALIDATE_HOOK );
		}

		do_action( self::VALIDATE_HOOK, $this->meta_with_token( (string) $own->get_id() ), $subscription );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * T-V1：空值通過（保留後台手動改成 V3、續扣走 fallback 的做法）
	 *
	 * @group edge
	 */
	public function test_T_V1_驗證空值通過(): void {
		$subscription = $this->create_subscription_or_skip();

		if ( false === has_action( self::VALIDATE_HOOK ) ) {
			$this->fail( '尚未實作: ' . self::VALIDATE_HOOK );
		}

		do_action( self::VALIDATE_HOOK, $this->meta_with_token( '' ), $subscription );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * T-V1：woocommerce_subscription_payment_meta 對 V3 回傳 post_meta._payuni_token_id
	 *
	 * @group happy
	 */
	public function test_T_V1_付款meta欄位包含payuni_token_id(): void {
		$subscription = $this->create_subscription_or_skip();
		$token        = $this->create_token( $this->customer_id, 'bound_hash', self::GATEWAY_ID );
		$subscription->update_meta_data( '_payuni_token_id', (string) $token->get_id() );
		$subscription->save();

		$meta = apply_filters( self::META_FILTER, [], $subscription );

		$this->assertArrayHasKey( self::GATEWAY_ID, $meta, '應為 V3 閘道註冊付款 meta' );
		$this->assertArrayHasKey( '_payuni_token_id', $meta[ self::GATEWAY_ID ]['post_meta'] ?? [], '付款 meta 應含 post_meta._payuni_token_id' );
		$this->assertSame(
			(string) $token->get_id(),
			(string) $meta[ self::GATEWAY_ID ]['post_meta']['_payuni_token_id']['value'],
			'欄位值應為訂閱目前綁定的 token ID'
		);
	}

	// ──────────────────────────────────────────────
	// Helper
	// ──────────────────────────────────────────────

	/**
	 * 建立真正的 WC_Subscription；WCS 未載入就跳過
	 *
	 * @return \WC_Subscription
	 */
	private function create_subscription_or_skip() {
		if ( ! class_exists( 'WC_Subscription' ) || ! function_exists( 'wcs_create_subscription' ) ) {
			$this->markTestSkipped( 'WooCommerce Subscriptions 未載入（tests/phpunit/bootstrap.php 目前不載入 WCS）' );
		}

		$subscription = wcs_create_subscription(
			[
				'customer_id'      => $this->customer_id,
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'status'           => 'active',
			]
		);
		$this->assertNotWPError( $subscription );
		$subscription->set_payment_method( self::GATEWAY_ID );
		$subscription->save();

		return $subscription;
	}

	/**
	 * 組出 WCS 傳給 validate hook 的 payment_meta（單一閘道那一段）
	 *
	 * @param string $token_id 要寫入的 _payuni_token_id
	 *
	 * @return array
	 */
	private function meta_with_token( string $token_id ): array {
		return [
			'post_meta' => [
				'_payuni_token_id' => [ 'value' => $token_id ],
			],
		];
	}

	/**
	 * 建立測試用 Payment Token
	 *
	 * @param int    $user_id    使用者 ID
	 * @param string $value      Token 值
	 * @param string $gateway_id 閘道 ID
	 *
	 * @return \WC_Payment_Token_CC
	 */
	private function create_token( int $user_id, string $value, string $gateway_id ): \WC_Payment_Token_CC {
		$token = new \WC_Payment_Token_CC();
		$token->set_token( $value );
		$token->set_gateway_id( $gateway_id );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( $user_id );
		$token->save();

		return $token;
	}
}

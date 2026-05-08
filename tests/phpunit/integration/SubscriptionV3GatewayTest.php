<?php
/**
 * PayUni V3 定期定額閘道整合測試
 *
 * 驗證 CreditSubscriptionV3 閘道的骨架、註冊、supports 宣告、
 * form_fields 設定、以及與 V1 的共存邏輯。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * V3 定期定額閘道測試類別
 *
 * @covers \PAYUNI\Gateways\CreditSubscriptionV3
 * @group gateway
 * @group subscription
 * @group payuni
 */
final class SubscriptionV3GatewayTest extends WP_UnitTestCase {

	/**
	 * Gateway 實例
	 *
	 * @var \PAYUNI\Gateways\CreditSubscriptionV3|null
	 */
	private $gateway;

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wc_woomp_enabled_payuni_gateway', 'yes' );

		if ( ! class_exists( \PAYUNI\Gateways\CreditSubscriptionV3::class ) ) {
			$this->markTestSkipped( 'CreditSubscriptionV3 class not found' );
		}

		// AbstractGateway 在 PHP 8.2 會產生動態屬性 deprecation（$min_amount 等），
		// 這是 V1 的既有問題，不影響 V3 測試。
		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$this->gateway = new \PAYUNI\Gateways\CreditSubscriptionV3();
		error_reporting( $previous );
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		$this->gateway = null;
		parent::tearDown();
	}

	// ──────────────────────────────────────────────
	// 1. Gateway 基本屬性
	// ──────────────────────────────────────────────

	/**
	 * @testdox Gateway ID 為 payuni-credit-subscription-v3
	 */
	public function test_gateway_id() {
		$this->assertSame(
			'payuni-credit-subscription-v3',
			$this->gateway->id,
			'Gateway ID 應為 payuni-credit-subscription-v3'
		);
	}

	/**
	 * @testdox Gateway 有正確的 method_title
	 */
	public function test_method_title() {
		$this->assertStringContainsString(
			'PAYUNi',
			$this->gateway->method_title,
			'method_title 應包含 PAYUNi'
		);
		$this->assertStringContainsString(
			'定期定額',
			$this->gateway->method_title,
			'method_title 應包含定期定額'
		);
		$this->assertStringContainsString(
			'免跳轉',
			$this->gateway->method_title,
			'method_title 應包含 免跳轉'
		);
	}

	/**
	 * @testdox Gateway has_fields 為 true
	 */
	public function test_has_fields() {
		$this->assertTrue(
			$this->gateway->has_fields,
			'has_fields 應為 true（需渲染 iframe）'
		);
	}

	/**
	 * @testdox Gateway 有定義 const ID
	 */
	public function test_has_id_constant() {
		$this->assertSame(
			'payuni-credit-subscription-v3',
			\PAYUNI\Gateways\CreditSubscriptionV3::ID,
			'應定義 ID 常數'
		);
	}

	// ──────────────────────────────────────────────
	// 2. Supports 宣告
	// ──────────────────────────────────────────────

	/**
	 * @testdox Gateway 支援 products
	 */
	public function test_supports_products() {
		$this->assertTrue( $this->gateway->supports( 'products' ) );
	}

	/**
	 * @testdox Gateway 支援 subscriptions
	 */
	public function test_supports_subscriptions() {
		$this->assertTrue( $this->gateway->supports( 'subscriptions' ) );
	}

	/**
	 * @testdox Gateway 支援 subscription_cancellation
	 */
	public function test_supports_subscription_cancellation() {
		$this->assertTrue( $this->gateway->supports( 'subscription_cancellation' ) );
	}

	/**
	 * @testdox Gateway 支援 subscription_suspension
	 */
	public function test_supports_subscription_suspension() {
		$this->assertTrue( $this->gateway->supports( 'subscription_suspension' ) );
	}

	/**
	 * @testdox Gateway 支援 subscription_reactivation
	 */
	public function test_supports_subscription_reactivation() {
		$this->assertTrue( $this->gateway->supports( 'subscription_reactivation' ) );
	}

	/**
	 * @testdox Gateway 支援 subscription_amount_changes
	 */
	public function test_supports_subscription_amount_changes() {
		$this->assertTrue( $this->gateway->supports( 'subscription_amount_changes' ) );
	}

	/**
	 * @testdox Gateway 支援 subscription_date_changes
	 */
	public function test_supports_subscription_date_changes() {
		$this->assertTrue( $this->gateway->supports( 'subscription_date_changes' ) );
	}

	/**
	 * @testdox Gateway 支援 subscription_payment_method_change
	 */
	public function test_supports_subscription_payment_method_change() {
		$this->assertTrue( $this->gateway->supports( 'subscription_payment_method_change' ) );
	}

	/**
	 * @testdox Gateway 支援 subscription_payment_method_change_customer
	 */
	public function test_supports_subscription_payment_method_change_customer() {
		$this->assertTrue( $this->gateway->supports( 'subscription_payment_method_change_customer' ) );
	}

	/**
	 * @testdox Gateway 支援 subscription_payment_method_change_admin
	 */
	public function test_supports_subscription_payment_method_change_admin() {
		$this->assertTrue( $this->gateway->supports( 'subscription_payment_method_change_admin' ) );
	}

	/**
	 * @testdox Gateway 支援 multiple_subscriptions
	 */
	public function test_supports_multiple_subscriptions() {
		$this->assertTrue( $this->gateway->supports( 'multiple_subscriptions' ) );
	}

	/**
	 * @testdox Gateway 支援 tokenization
	 */
	public function test_supports_tokenization() {
		$this->assertTrue( $this->gateway->supports( 'tokenization' ) );
	}

	/**
	 * @testdox Gateway 不支援 refunds（訂閱不需退款）
	 */
	public function test_does_not_support_refunds() {
		$this->assertFalse( $this->gateway->supports( 'refunds' ) );
	}

	// ──────────────────────────────────────────────
	// 3. Form Fields 設定
	// ──────────────────────────────────────────────

	/**
	 * @testdox Form fields 包含 enabled、title、description
	 */
	public function test_form_fields_has_basic_fields() {
		$fields = $this->gateway->form_fields;
		$this->assertArrayHasKey( 'enabled', $fields );
		$this->assertArrayHasKey( 'title', $fields );
		$this->assertArrayHasKey( 'description', $fields );
	}

	/**
	 * @testdox Form fields 不包含 installment_options（訂閱不允許分期）
	 */
	public function test_form_fields_no_installment_options() {
		$fields = $this->gateway->form_fields;
		$this->assertArrayNotHasKey(
			'installment_options',
			$fields,
			'訂閱閘道不應有分期選項'
		);
	}

	/**
	 * @testdox Form fields 不包含 enable_tokenization（訂閱強制 token）
	 */
	public function test_form_fields_no_enable_tokenization() {
		$fields = $this->gateway->form_fields;
		$this->assertArrayNotHasKey(
			'enable_tokenization',
			$fields,
			'訂閱閘道強制 token，不需要 enable_tokenization 設定'
		);
	}

	// ──────────────────────────────────────────────
	// 4. Gateway 註冊
	// ──────────────────────────────────────────────

	/**
	 * @testdox Gateway 已註冊到 WooCommerce payment gateways
	 */
	public function test_gateway_registered_in_woocommerce() {
		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$gateways = WC()->payment_gateways()->payment_gateways();
		error_reporting( $previous );
		$gateway_ids = array_map(
			function ( $gw ) {
				return $gw->id;
			},
			$gateways
		);

		$this->assertContains(
			'payuni-credit-subscription-v3',
			$gateway_ids,
			'payuni-credit-subscription-v3 應已註冊到 WooCommerce。已註冊：' . implode( ', ', $gateway_ids )
		);
	}

	/**
	 * @testdox Gateway 繼承自 WC_Payment_Gateway
	 */
	public function test_extends_wc_payment_gateway() {
		$this->assertInstanceOf(
			'WC_Payment_Gateway',
			$this->gateway,
			'應繼承自 WC_Payment_Gateway'
		);
	}
}

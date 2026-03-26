<?php
/**
 * PayUni V3 定期定額手動扣款 Metabox 整合測試
 *
 * 驗證 SubscriptionMetabox 顯示條件、SubscriptionAjax 註冊、
 * 以及 SubscriptionBootstrap 的 admin_enqueue_scripts hook 註冊。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * V3 定期定額手動扣款 Metabox 測試類別
 *
 * @covers \J7\Payuni\Domains\Subscription\SubscriptionMetabox
 * @covers \J7\Payuni\Domains\Subscription\SubscriptionAjax
 * @group subscription
 * @group payuni
 */
final class SubscriptionV3MetaboxTest extends WP_UnitTestCase {

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

		// 設定 PayUni 測試憑證
		update_option( 'payuni_payment_testmode', 'yes' );
		update_option( 'payuni_payment_merchant_no_test', 'TEST_MERCHANT' );
		update_option( 'payuni_payment_hash_key_test', 'TEST_HASH_KEY_1234567890123456' );
		update_option( 'payuni_payment_hash_iv_test', 'TEST_HASH_IV_123456' );

		// 重置 SettingDTO 單例
		$ref  = new \ReflectionClass( \J7\Payuni\Contracts\DTOs\SettingDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$this->customer_id = $this->factory()->user->create( [
			'role' => 'customer',
		] );
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		// 重置 SettingDTO 單例
		$ref  = new \ReflectionClass( \J7\Payuni\Contracts\DTOs\SettingDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		parent::tearDown();
	}

	// ──────────────────────────────────────────────
	// 1. Metabox 顯示條件測試
	// ──────────────────────────────────────────────

	/**
	 * @testdox should_show_metabox：payment_method 為 V3 + pending + 非 SUCCESS 應回傳 true
	 */
	public function test_should_show_metabox_v3_pending_not_success(): void {
		$order = $this->create_test_order( $this->customer_id );
		$order->set_payment_method( 'payuni-credit-subscription-v3' );
		$order->update_status( 'pending' );
		$order->save();

		$result = \J7\Payuni\Domains\Subscription\SubscriptionMetabox::should_show_metabox( $order );

		$this->assertTrue( $result, 'V3 + pending + 非 SUCCESS 應顯示 metabox' );
	}

	/**
	 * @testdox should_show_metabox：payment_method 為 V3 + processing 不應顯示
	 */
	public function test_should_not_show_metabox_v3_processing(): void {
		$order = $this->create_test_order( $this->customer_id );
		$order->set_payment_method( 'payuni-credit-subscription-v3' );
		$order->update_status( 'processing' );
		$order->save();

		$result = \J7\Payuni\Domains\Subscription\SubscriptionMetabox::should_show_metabox( $order );

		$this->assertFalse( $result, 'V3 + processing 不應顯示 metabox' );
	}

	/**
	 * @testdox should_show_metabox：payment_method 為 V3 + pending + SUCCESS 不應顯示
	 */
	public function test_should_not_show_metabox_v3_pending_success(): void {
		$order = $this->create_test_order( $this->customer_id );
		$order->set_payment_method( 'payuni-credit-subscription-v3' );
		$order->update_status( 'pending' );
		$order->update_meta_data( '_payuni_resp_status', 'SUCCESS' );
		$order->save();

		$result = \J7\Payuni\Domains\Subscription\SubscriptionMetabox::should_show_metabox( $order );

		$this->assertFalse( $result, 'V3 + pending + SUCCESS 不應顯示 metabox' );
	}

	/**
	 * @testdox should_show_metabox：payment_method 為 V1 不應顯示
	 */
	public function test_should_not_show_metabox_v1_gateway(): void {
		$order = $this->create_test_order( $this->customer_id );
		$order->set_payment_method( 'payuni-credit-subscription' );
		$order->update_status( 'pending' );
		$order->save();

		$result = \J7\Payuni\Domains\Subscription\SubscriptionMetabox::should_show_metabox( $order );

		$this->assertFalse( $result, 'V1 閘道不應顯示 V3 metabox' );
	}

	/**
	 * @testdox should_show_metabox：payment_method 為一般信用卡不應顯示
	 */
	public function test_should_not_show_metabox_regular_credit(): void {
		$order = $this->create_test_order( $this->customer_id );
		$order->set_payment_method( 'payuni-credit-v3' );
		$order->update_status( 'pending' );
		$order->save();

		$result = \J7\Payuni\Domains\Subscription\SubscriptionMetabox::should_show_metabox( $order );

		$this->assertFalse( $result, '一般信用卡不應顯示 V3 定期定額 metabox' );
	}

	/**
	 * @testdox should_show_metabox：null 訂單不應顯示
	 */
	public function test_should_not_show_metabox_null_order(): void {
		$result = \J7\Payuni\Domains\Subscription\SubscriptionMetabox::should_show_metabox( null );

		$this->assertFalse( $result, 'null 訂單不應顯示 metabox' );
	}

	// ──────────────────────────────────────────────
	// 2. Metabox 按鈕渲染測試
	// ──────────────────────────────────────────────

	/**
	 * @testdox render_button 應輸出包含正確 CSS class 和 order_id 的按鈕
	 */
	public function test_render_button_contains_correct_class_and_value(): void {
		$order = $this->create_test_order( $this->customer_id );
		$order->set_payment_method( 'payuni-credit-subscription-v3' );
		$order->save();

		$output = \J7\Payuni\Domains\Subscription\SubscriptionMetabox::render_button( $order->get_id() );

		$this->assertStringContainsString(
			'btnPayuniV3SubscriptionPayManual',
			$output,
			'按鈕應包含 btnPayuniV3SubscriptionPayManual CSS class'
		);
		$this->assertStringContainsString(
			"value='" . $order->get_id() . "'",
			$output,
			'按鈕 value 應為 order_id'
		);
		$this->assertStringContainsString(
			'手動扣款',
			$output,
			'按鈕應顯示「手動扣款」文字'
		);
	}

	// ──────────────────────────────────────────────
	// 3. Ajax action 註冊測試
	// ──────────────────────────────────────────────

	/**
	 * @testdox SubscriptionAjax::register 應註冊 wp_ajax_payuni_v3_subscription_pay_manual action
	 */
	public function test_ajax_action_registered(): void {
		// register() 應在 bootstrap 時已呼叫
		\J7\Payuni\Domains\Subscription\SubscriptionAjax::register();

		$this->assertGreaterThan(
			0,
			has_action( 'wp_ajax_payuni_v3_subscription_pay_manual' ),
			'應註冊 wp_ajax_payuni_v3_subscription_pay_manual action'
		);
	}

	// ──────────────────────────────────────────────
	// 4. Bootstrap 整合：admin_enqueue_scripts 註冊
	// ──────────────────────────────────────────────

	/**
	 * @testdox SubscriptionBootstrap 應註冊 admin_enqueue_scripts hook
	 */
	public function test_admin_enqueue_scripts_hook_registered(): void {
		$this->assertGreaterThan(
			0,
			has_action(
				'admin_enqueue_scripts',
				[ \J7\Payuni\Domains\Subscription\SubscriptionBootstrap::class, 'enqueue_admin_scripts' ]
			),
			'應註冊 admin_enqueue_scripts hook'
		);
	}

	// ──────────────────────────────────────────────
	// 5. Metabox 類別存在性測試
	// ──────────────────────────────────────────────

	/**
	 * @testdox SubscriptionMetabox 類別應存在
	 */
	public function test_metabox_class_exists(): void {
		$this->assertTrue(
			class_exists( \J7\Payuni\Domains\Subscription\SubscriptionMetabox::class ),
			'SubscriptionMetabox 類別應存在'
		);
	}

	/**
	 * @testdox SubscriptionAjax 類別應存在
	 */
	public function test_ajax_class_exists(): void {
		$this->assertTrue(
			class_exists( \J7\Payuni\Domains\Subscription\SubscriptionAjax::class ),
			'SubscriptionAjax 類別應存在'
		);
	}

	// ──────────────────────────────────────────────
	// Helper 方法
	// ──────────────────────────────────────────────

	/**
	 * 建立測試用訂單
	 *
	 * @param int $customer_id 客戶 ID
	 * @param int $total       訂單金額
	 *
	 * @return \WC_Order 訂單物件
	 */
	private function create_test_order( int $customer_id, int $total = 1000 ): \WC_Order {
		$order = \wc_create_order();
		$order->set_customer_id( $customer_id );
		$order->set_billing_email( 'test@example.com' );
		$order->set_total( $total );
		$order->set_payment_method( 'payuni-credit-subscription-v3' );
		$order->save();

		return $order;
	}
}

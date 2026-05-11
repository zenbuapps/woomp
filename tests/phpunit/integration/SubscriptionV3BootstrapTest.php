<?php
/**
 * PayUni V3 定期定額 Bootstrap 整合測試
 *
 * 驗證 SubscriptionBootstrap 的條件顯示邏輯、Hook 註冊、
 * 以及 V1/V3 共存機制。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * V3 定期定額 Bootstrap 測試類別
 *
 * @covers \J7\Payuni\Domains\Subscription\SubscriptionBootstrap
 * @group subscription
 * @group payuni
 */
final class SubscriptionV3BootstrapTest extends WP_UnitTestCase {

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'wc_woomp_enabled_payuni_gateway', 'yes' );
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	// ──────────────────────────────────────────────
	// 1. Hook 註冊
	// ──────────────────────────────────────────────

	/**
	 * @testdox register_hooks 應註冊 woocommerce_available_payment_gateways filter
	 */
	public function test_registers_conditional_payment_gateways_filter(): void {
		$this->assertGreaterThan(
			0,
			has_filter(
				'woocommerce_available_payment_gateways',
				[ \J7\Payuni\Domains\Subscription\SubscriptionBootstrap::class, 'conditional_payment_gateways' ]
			),
			'應註冊 woocommerce_available_payment_gateways filter'
		);
	}

	/**
	 * @testdox register_hooks 應註冊 V3 排程續扣 action
	 */
	public function test_registers_scheduled_subscription_payment_action(): void {
		$this->assertGreaterThan(
			0,
			has_action(
				'woocommerce_scheduled_subscription_payment_payuni-credit-subscription-v3',
				[ \J7\Payuni\Domains\Subscription\SubscriptionBootstrap::class, 'process_scheduled_payment' ]
			),
			'應註冊 woocommerce_scheduled_subscription_payment_payuni-credit-subscription-v3 action'
		);
	}

	/**
	 * @testdox register_hooks 應註冊續扣失敗處理 action
	 */
	public function test_registers_renewal_payment_failed_action(): void {
		$this->assertGreaterThan(
			0,
			has_action(
				'woocommerce_subscription_renewal_payment_failed',
				[ \J7\Payuni\Domains\Subscription\SubscriptionBootstrap::class, 'subscription_fail_handler' ]
			),
			'應註冊 woocommerce_subscription_renewal_payment_failed action'
		);
	}

	// ──────────────────────────────────────────────
	// 2. 條件顯示：非訂閱商品時隱藏 V3 定期定額
	// ──────────────────────────────────────────────

	/**
	 * @testdox 購物車無訂閱商品時應移除 V3 定期定額閘道
	 */
	public function test_removes_v3_subscription_when_no_subscription_product(): void {
		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$gateways = [
			'payuni-credit-v3'               => new \PAYUNI\Gateways\CreditV3(),
			'payuni-credit-subscription-v3'  => new \PAYUNI\Gateways\CreditSubscriptionV3(),
		];
		error_reporting( $previous );

		// 模擬空購物車（沒有訂閱商品）
		$result = \J7\Payuni\Domains\Subscription\SubscriptionBootstrap::filter_gateways_by_cart( $gateways, [] );

		$this->assertArrayNotHasKey(
			'payuni-credit-subscription-v3',
			$result,
			'非訂閱商品購物車不應顯示 V3 定期定額'
		);
		$this->assertArrayHasKey(
			'payuni-credit-v3',
			$result,
			'一般信用卡閘道應保留'
		);
	}

	/**
	 * @testdox 購物車有 subscription 類型商品時應保留 V3 定期定額閘道
	 */
	public function test_keeps_v3_subscription_when_subscription_product(): void {
		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$gateways = [
			'payuni-credit-v3'               => new \PAYUNI\Gateways\CreditV3(),
			'payuni-credit-subscription-v3'  => new \PAYUNI\Gateways\CreditSubscriptionV3(),
		];
		error_reporting( $previous );

		$product_types = [ 'subscription' ];
		$result = \J7\Payuni\Domains\Subscription\SubscriptionBootstrap::filter_gateways_by_cart( $gateways, $product_types );

		$this->assertArrayHasKey(
			'payuni-credit-subscription-v3',
			$result,
			'訂閱商品購物車應保留 V3 定期定額'
		);
	}

	/**
	 * @testdox 購物車有 variable-subscription 類型商品時應保留 V3 定期定額閘道
	 */
	public function test_keeps_v3_subscription_when_variable_subscription_product(): void {
		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$gateways = [
			'payuni-credit-subscription-v3' => new \PAYUNI\Gateways\CreditSubscriptionV3(),
		];
		error_reporting( $previous );

		$product_types = [ 'variable-subscription' ];
		$result = \J7\Payuni\Domains\Subscription\SubscriptionBootstrap::filter_gateways_by_cart( $gateways, $product_types );

		$this->assertArrayHasKey(
			'payuni-credit-subscription-v3',
			$result,
			'可變訂閱商品購物車應保留 V3 定期定額'
		);
	}

	// ──────────────────────────────────────────────
	// 3. WC 原生閘道啟用/停用控制（V1/V3 共存）
	// ──────────────────────────────────────────────

	/**
	 * @testdox V1 和 V3 定期定額閘道同時存在於 available_gateways 時兩個都保留
	 */
	public function test_both_subscription_gateways_shown_when_both_available(): void {
		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$gateways = [
			'payuni-credit-v3'               => new \PAYUNI\Gateways\CreditV3(),
			'payuni-credit-subscription'     => new \PAYUNI\Gateways\CreditSubscription(),
			'payuni-credit-subscription-v3'  => new \PAYUNI\Gateways\CreditSubscriptionV3(),
		];
		error_reporting( $previous );

		// 有訂閱商品時，conditional_payment_gateways 不應額外移除任何已啟用的閘道
		$product_types = [ 'subscription' ];
		$result = \J7\Payuni\Domains\Subscription\SubscriptionBootstrap::filter_gateways_by_cart( $gateways, $product_types );

		$this->assertArrayHasKey(
			'payuni-credit-subscription',
			$result,
			'V1 定期定額閘道已啟用時應保留'
		);
		$this->assertArrayHasKey(
			'payuni-credit-subscription-v3',
			$result,
			'V3 定期定額閘道已啟用時應保留'
		);
		$this->assertArrayHasKey(
			'payuni-credit-v3',
			$result,
			'一般信用卡閘道應保留'
		);
	}

	/**
	 * @testdox 僅 V3 定期定額閘道存在於 available_gateways 時只顯示 V3
	 */
	public function test_only_v3_shown_when_only_v3_available(): void {
		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$gateways = [
			'payuni-credit-v3'               => new \PAYUNI\Gateways\CreditV3(),
			'payuni-credit-subscription-v3'  => new \PAYUNI\Gateways\CreditSubscriptionV3(),
		];
		error_reporting( $previous );

		$product_types = [ 'subscription' ];
		$result = \J7\Payuni\Domains\Subscription\SubscriptionBootstrap::filter_gateways_by_cart( $gateways, $product_types );

		$this->assertArrayHasKey(
			'payuni-credit-subscription-v3',
			$result,
			'V3 定期定額閘道已啟用時應保留'
		);
		$this->assertArrayNotHasKey(
			'payuni-credit-subscription',
			$result,
			'V1 定期定額閘道未啟用時不應出現'
		);
	}

	/**
	 * @testdox conditional_payment_gateways 不再呼叫 filter_gateways_by_version（該方法已移除）
	 */
	public function test_no_version_filter_method_exists(): void {
		$this->assertFalse(
			method_exists(
				\J7\Payuni\Domains\Subscription\SubscriptionBootstrap::class,
				'filter_gateways_by_version'
			),
			'filter_gateways_by_version 方法應已移除'
		);
	}

	// ──────────────────────────────────────────────
	// 4. 一般商品購物車中應同時移除 V1 和 V3 定期定額
	// ──────────────────────────────────────────────

	/**
	 * @testdox 一般商品購物車應同時移除 V1 和 V3 定期定額閘道
	 */
	public function test_removes_both_subscription_gateways_for_normal_products(): void {
		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$gateways = [
			'payuni-credit-v3'               => new \PAYUNI\Gateways\CreditV3(),
			'payuni-credit-subscription'     => new \PAYUNI\Gateways\CreditSubscription(),
			'payuni-credit-subscription-v3'  => new \PAYUNI\Gateways\CreditSubscriptionV3(),
		];
		error_reporting( $previous );

		// 一般商品
		$product_types = [ 'simple' ];
		$result = \J7\Payuni\Domains\Subscription\SubscriptionBootstrap::filter_gateways_by_cart( $gateways, $product_types );

		$this->assertArrayNotHasKey(
			'payuni-credit-subscription',
			$result,
			'一般商品不應顯示 V1 定期定額'
		);
		$this->assertArrayNotHasKey(
			'payuni-credit-subscription-v3',
			$result,
			'一般商品不應顯示 V3 定期定額'
		);
		$this->assertArrayHasKey(
			'payuni-credit-v3',
			$result,
			'一般商品應保留一般信用卡閘道'
		);
	}

	// ──────────────────────────────────────────────
	// 5. 非結帳頁面不應過濾訂閱閘道（#114）
	// ──────────────────────────────────────────────

	/**
	 * @testdox 非結帳頁面（例如 My Account 新增付款方式）不應移除訂閱閘道
	 *
	 * Regression test for #114: 修正前 conditional_payment_gateways 沒有
	 * is_checkout() 保護，導致在 /my-account/add-payment-method/ 頁面，
	 * V1 和 V3 訂閱閘道會被一併移除，用戶看不到任何信用卡欄位、無法新增
	 * 或更換付款方式。
	 */
	public function test_keeps_subscription_gateways_outside_checkout(): void {
		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$gateways = [
			'payuni-credit-v3'               => new \PAYUNI\Gateways\CreditV3(),
			'payuni-credit-subscription'     => new \PAYUNI\Gateways\CreditSubscription(),
			'payuni-credit-subscription-v3'  => new \PAYUNI\Gateways\CreditSubscriptionV3(),
		];
		error_reporting( $previous );

		// PHPUnit 預設不是 checkout context，所以 is_checkout() 回傳 false
		$this->assertFalse(
			is_checkout(),
			'測試前提：當前不是 checkout 頁面'
		);

		$result = \J7\Payuni\Domains\Subscription\SubscriptionBootstrap::conditional_payment_gateways( $gateways );

		$this->assertArrayHasKey(
			'payuni-credit-subscription',
			$result,
			'非結帳頁應保留 V1 訂閱閘道（讓用戶能在 My Account 新增付款方式）'
		);
		$this->assertArrayHasKey(
			'payuni-credit-subscription-v3',
			$result,
			'非結帳頁應保留 V3 訂閱閘道（讓用戶能在 My Account 新增付款方式）'
		);
		$this->assertArrayHasKey(
			'payuni-credit-v3',
			$result,
			'非結帳頁應保留一般信用卡閘道'
		);
	}
}

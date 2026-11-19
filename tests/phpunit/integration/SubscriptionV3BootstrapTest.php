<?php
/**
 * PayUni V3 定期定額 Bootstrap 整合測試
 *
 * 驗證 SubscriptionBootstrap 的條件顯示邏輯、Hook 註冊、
 * 以及 V1/V3 共存機制。
 *
 * issue #147 第三階段（開放換卡頁，紅燈）：T-F2～T-F5。
 * 換卡頁 = is_checkout() + order-pay endpoint + 換卡請求（PaymentMethodChange::is_change_request()）。
 *
 * 執行指令：
 * docker exec <woomp-tests-cli> sh -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --filter SubscriptionV3BootstrapTest'
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

	private const V1_ID = 'payuni-credit-subscription';
	private const V3_ID = 'payuni-credit-subscription-v3';

	/**
	 * 是否進入過 order-pay 情境（tearDown 還原用）
	 *
	 * @var bool
	 */
	private bool $in_order_pay_context = false;

	/**
	 * 進入情境前的 $wp->query_vars['order-pay']（null 表示原本沒有）
	 *
	 * @var mixed
	 */
	private $saved_order_pay_var = null;

	/**
	 * 進入情境前的 $_GET
	 *
	 * @var array
	 */
	private array $saved_get = [];

	/**
	 * 進入情境前的換卡旗標
	 *
	 * @var bool
	 */
	private bool $saved_change_flag = false;

	/**
	 * 改過 enabled 的 WC 閘道實例原值：閘道 ID => 原 enabled
	 *
	 * 不呼叫 WC_Payment_Gateways::init() 重載：閘道建構子會重複註冊 hook（例如存 token 的 action），
	 * 汙染同一輪的其他測試（T-N5 會看到 3 張 token）。
	 *
	 * @var array<string, string>
	 */
	private array $saved_gateway_enabled = [];

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
		$this->leave_order_pay_context();
		remove_all_filters( 'woomp_payuni_allow_v1_change_payment' );

		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$wc_gateways = WC()->payment_gateways()->payment_gateways();
		error_reporting( $previous );
		foreach ( $this->saved_gateway_enabled as $id => $enabled ) {
			if ( isset( $wc_gateways[ $id ] ) ) {
				$wc_gateways[ $id ]->enabled = $enabled;
			}
		}
		$this->saved_gateway_enabled = [];
		$this->reset_one_gateway_supports_cache();

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

	// ──────────────────────────────────────────────
	// 6. 換卡頁（order-pay + 換卡請求）閘道過濾（issue #147 第三階段）
	// ──────────────────────────────────────────────

	/**
	 * T-F2：換卡頁保留 V3、移除 V1
	 *
	 * 現況：is_checkout() 為真就一律依購物車過濾，換卡頁購物車是空的，V3 被移除，
	 * 客戶在換卡頁看不到 V3 信用卡欄位。V1 綁卡走 PayUni 正在停用的幕後授權 API，換卡頁不開放。
	 *
	 * @testdox T-F2 換卡頁（order-pay + 換卡請求）應保留 V3 定期定額並移除 V1
	 * @group security
	 */
	public function test_T_F2_換卡頁保留V3並移除V1(): void {
		$this->enter_order_pay_context( true );
		$this->assert_context_is_change_page();

		$result = \J7\Payuni\Domains\Subscription\SubscriptionBootstrap::conditional_payment_gateways( $this->make_gateways() );

		$this->assertArrayHasKey( self::V3_ID, $result, '換卡頁必須保留 V3 定期定額，否則客戶無法更換到 V3' );
		$this->assertArrayNotHasKey( self::V1_ID, $result, '換卡頁預設不開放 V1（filter woomp_payuni_allow_v1_change_payment 預設 false）' );
		$this->assertArrayHasKey( 'payuni-credit-v3', $result, '一般信用卡閘道不受影響，仍保留' );
	}

	/**
	 * T-F3：換卡情境下 WCS 判定「可以換付款方式」
	 *
	 * WCS can_subscription_be_updated_to_new_payment_method() 需要
	 * one_gateway_supports('subscription_payment_method_change_customer')，
	 * 該檢查看的是「過濾後」的可用閘道；V3 被濾掉整站就沒有任何閘道支援客戶換卡。
	 *
	 * @testdox T-F3 換卡情境下 can_be_updated_to(new-payment-method) 應為 true 且 V3 在可用閘道中
	 */
	public function test_T_F3_換卡情境可更新付款方式(): void {
		if ( ! class_exists( 'WC_Subscription' ) || ! function_exists( 'wcs_create_subscription' ) ) {
			$this->markTestSkipped( 'WooCommerce Subscriptions 未載入' );
		}

		// 啟用 V3（V1 也啟用，模擬舊站同時存在）：直接改 WC 已註冊的閘道實例，tearDown 還原。
		$previous    = error_reporting( E_ALL & ~E_DEPRECATED );
		$wc_gateways = WC()->payment_gateways()->payment_gateways();
		error_reporting( $previous );
		foreach ( [ self::V1_ID, self::V3_ID ] as $id ) {
			$this->assertArrayHasKey( $id, $wc_gateways, "前置：{$id} 已註冊到 WC" );
			$this->saved_gateway_enabled[ $id ] = (string) $wc_gateways[ $id ]->enabled;
			$wc_gateways[ $id ]->enabled        = 'yes';
		}
		$this->reset_one_gateway_supports_cache();

		$customer_id  = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$subscription = wcs_create_subscription(
			[
				'customer_id'      => $customer_id,
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'status'           => 'active',
			]
		);
		$this->assertNotWPError( $subscription );
		$subscription->set_payment_method( self::V1_ID );
		$subscription->set_total( 9450 );
		$subscription->save();
		$subscription->update_dates( [ 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ] );
		$subscription = wcs_get_subscription( $subscription->get_id() );

		// 前置：非結帳頁不過濾，V3 必須已啟用且可用，否則下面的紅燈原因不是過濾器。
		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$this->assertArrayHasKey( self::V3_ID, WC()->payment_gateways()->get_available_payment_gateways(), '前置：V3 已啟用且在非結帳頁可用' );
		error_reporting( $previous );

		$this->enter_order_pay_context( true, (string) $subscription->get_id() );
		$this->assert_context_is_change_page();

		$previous  = error_reporting( E_ALL & ~E_DEPRECATED );
		$available = WC()->payment_gateways()->get_available_payment_gateways();
		error_reporting( $previous );

		$this->assertArrayHasKey( self::V3_ID, $available, '換卡情境下 V3 必須出現在可用閘道中（前置：V3 已啟用）' );
		$this->assertArrayNotHasKey( self::V1_ID, $available, '換卡情境下 V1 不應出現在可用閘道中' );

		// 重置快取，讓 WCS 以「過濾後」的結果重算。
		$this->reset_one_gateway_supports_cache();
		$this->assertTrue(
			$subscription->can_be_updated_to( 'new-payment-method' ),
			'換卡情境下 WCS 必須判定可更新付款方式（需要有閘道支援 subscription_payment_method_change_customer）'
		);
	}

	/**
	 * T-F4：order-pay 但不是換卡、訂單也沒有訂閱 → 仍移除 V1、V3（回歸防護）
	 *
	 * @testdox T-F4 order-pay 但不是換卡且無訂閱時仍應移除 V1 和 V3 定期定額
	 * @group edge
	 */
	public function test_T_F4_order_pay_非換卡仍移除訂閱閘道(): void {
		$this->enter_order_pay_context( false );

		$this->assertTrue( is_checkout(), '測試前提：目前是結帳頁' );
		$this->assertTrue( is_wc_endpoint_url( 'order-pay' ), '測試前提：目前是 order-pay endpoint' );
		$this->assertFalse( \J7\Payuni\Domains\Subscription\PaymentMethodChange::is_change_request(), '測試前提：不是換卡請求' );

		$result = \J7\Payuni\Domains\Subscription\SubscriptionBootstrap::conditional_payment_gateways( $this->make_gateways() );

		$this->assertArrayNotHasKey( self::V1_ID, $result, 'order-pay 非換卡不應顯示 V1 定期定額' );
		$this->assertArrayNotHasKey( self::V3_ID, $result, 'order-pay 非換卡不應顯示 V3 定期定額' );
		$this->assertArrayHasKey( 'payuni-credit-v3', $result, '一般信用卡閘道應保留' );
	}

	/**
	 * T-F5：filter woomp_payuni_allow_v1_change_payment 回 true 時換卡頁保留 V1
	 *
	 * @testdox T-F5 woomp_payuni_allow_v1_change_payment 回 true 時換卡頁應保留 V1（V3 也保留）
	 */
	public function test_T_F5_filter允許時換卡頁保留V1(): void {
		add_filter( 'woomp_payuni_allow_v1_change_payment', '__return_true' );
		$this->enter_order_pay_context( true );
		$this->assert_context_is_change_page();

		$result = \J7\Payuni\Domains\Subscription\SubscriptionBootstrap::conditional_payment_gateways( $this->make_gateways() );

		$this->assertArrayHasKey( self::V1_ID, $result, 'filter 回 true 時換卡頁應保留 V1' );
		$this->assertArrayHasKey( self::V3_ID, $result, 'filter 回 true 時 V3 仍應保留' );
	}

	/**
	 * T-F5 補充：filter 只影響換卡頁，order-pay 非換卡時 V1 仍被移除
	 *
	 * @testdox T-F5 補充 filter 回 true 但 order-pay 非換卡時仍應移除 V1 和 V3
	 * @group edge
	 */
	public function test_T_F5_filter不影響非換卡的order_pay(): void {
		add_filter( 'woomp_payuni_allow_v1_change_payment', '__return_true' );
		$this->enter_order_pay_context( false );

		$result = \J7\Payuni\Domains\Subscription\SubscriptionBootstrap::conditional_payment_gateways( $this->make_gateways() );

		$this->assertArrayNotHasKey( self::V1_ID, $result, 'filter 只放行換卡頁，非換卡的 order-pay 不受影響' );
		$this->assertArrayNotHasKey( self::V3_ID, $result, '非換卡的 order-pay 仍依購物車移除 V3' );
	}

	// ──────────────────────────────────────────────
	// 輔助方法
	// ──────────────────────────────────────────────

	/**
	 * 建立一般信用卡與 V1／V3 定期定額閘道
	 *
	 * @return array<string, \WC_Payment_Gateway>
	 */
	private function make_gateways(): array {
		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$gateways = [
			'payuni-credit-v3' => new \PAYUNI\Gateways\CreditV3(),
			self::V1_ID        => new \PAYUNI\Gateways\CreditSubscription(),
			self::V3_ID        => new \PAYUNI\Gateways\CreditSubscriptionV3(),
		];
		error_reporting( $previous );

		return $gateways;
	}

	/**
	 * 進入 order-pay 情境：is_checkout() 為真 + order-pay endpoint，可選擇是否為換卡請求
	 *
	 * @param bool   $is_change 是否為換卡請求
	 * @param string $order_id  order-pay 的訂單 ID
	 *
	 * @return void
	 */
	private function enter_order_pay_context( bool $is_change, string $order_id = '123' ): void {
		$this->in_order_pay_context = true;
		$this->saved_get            = $_GET;
		$this->saved_order_pay_var  = $GLOBALS['wp']->query_vars['order-pay'] ?? null;
		$this->saved_change_flag    = class_exists( 'WC_Subscriptions_Change_Payment_Gateway' )
			? (bool) \WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment
			: false;

		add_filter( 'woocommerce_is_checkout', '__return_true' );
		$GLOBALS['wp']->query_vars['order-pay'] = $order_id;

		unset( $_GET['change_payment_method'] );
		if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) ) {
			\WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment = $is_change;
		}
		if ( $is_change ) {
			$_GET['change_payment_method'] = $order_id;
		}
	}

	/**
	 * 還原 enter_order_pay_context() 改動的全域狀態
	 *
	 * @return void
	 */
	private function leave_order_pay_context(): void {
		if ( ! $this->in_order_pay_context ) {
			return;
		}

		remove_filter( 'woocommerce_is_checkout', '__return_true' );
		if ( null === $this->saved_order_pay_var ) {
			unset( $GLOBALS['wp']->query_vars['order-pay'] );
		} else {
			$GLOBALS['wp']->query_vars['order-pay'] = $this->saved_order_pay_var;
		}
		$_GET = $this->saved_get;
		if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) ) {
			\WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment = $this->saved_change_flag;
		}

		$this->in_order_pay_context = false;
	}

	/**
	 * 斷言目前情境確實是「換卡頁」，避免情境模擬失敗造成假紅燈
	 *
	 * @return void
	 */
	private function assert_context_is_change_page(): void {
		$this->assertTrue( is_checkout(), '測試前提：目前是結帳頁' );
		$this->assertTrue( is_wc_endpoint_url( 'order-pay' ), '測試前提：目前是 order-pay endpoint' );
		$this->assertTrue( \J7\Payuni\Domains\Subscription\PaymentMethodChange::is_change_request(), '測試前提：目前是換卡請求' );
	}

	/**
	 * 重置 WCS 的 one_gateway_supports 靜態快取
	 *
	 * @return void
	 */
	private function reset_one_gateway_supports_cache(): void {
		if ( ! class_exists( 'WC_Subscriptions_Core_Payment_Gateways' ) ) {
			return;
		}

		$prop = new \ReflectionProperty( 'WC_Subscriptions_Core_Payment_Gateways', 'one_gateway_supports' );
		$prop->setAccessible( true );
		$prop->setValue( null, [] );
	}
}

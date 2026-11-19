<?php
/**
 * PayUni V3 定期定額付款流程整合測試
 *
 * 驗證 process_payment 的三條路徑、Token 搜尋邏輯、
 * 以及零元取 Token 排程機制。
 *
 * @package Woomp\Tests\Integration
 */

require_once dirname( __DIR__ ) . '/support/WcsFixture.php';

/**
 * V3 定期定額付款流程測試類別
 *
 * @covers \PAYUNI\Gateways\CreditSubscriptionV3
 * @covers \J7\Payuni\Domains\Subscription\SubscriptionHandler
 * @group subscription
 * @group payuni
 */
final class SubscriptionV3PaymentTest extends WP_UnitTestCase {

	/**
	 * Gateway 實例
	 *
	 * @var \PAYUNI\Gateways\CreditSubscriptionV3|null
	 */
	private $gateway;

	/**
	 * 測試用客戶 ID
	 *
	 * @var int
	 */
	private int $customer_id;

	/**
	 * 攔截到的 HTTP 請求
	 *
	 * @var array<int, array{url: string, args: array}>
	 */
	private array $http_requests = [];

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'wc_woomp_enabled_payuni_gateway', 'yes' );

		// 設定 PayUni 測試憑證，避免 SettingDTO 的 strict type 錯誤
		update_option( 'payuni_payment_testmode', 'yes' );
		update_option( 'payuni_payment_merchant_no_test', 'TEST_MERCHANT' );
		update_option( 'payuni_payment_hash_key_test', 'TEST_HASH_KEY_1234567890123456' );
		update_option( 'payuni_payment_hash_iv_test', 'TEST_HASH_IV_123456' );

		// 重置 SettingDTO 單例
		$ref = new \ReflectionClass( \J7\Payuni\Contracts\DTOs\SettingDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$this->gateway = new \PAYUNI\Gateways\CreditSubscriptionV3();
		error_reporting( $previous );

		$this->customer_id = $this->factory()->user->create( [
			'role' => 'customer',
		] );
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		$this->gateway = null;

		// 清理 payment tokens
		$tokens = \WC_Payment_Tokens::get_customer_tokens( $this->customer_id );
		foreach ( $tokens as $token ) {
			\WC_Payment_Tokens::delete( $token->get_id() );
		}

		// 重置 SettingDTO 單例
		$ref = new \ReflectionClass( \J7\Payuni\Contracts\DTOs\SettingDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		parent::tearDown();
	}

	// ──────────────────────────────────────────────
	// 1. Token 搜尋邏輯 (get_card_hash)
	// ──────────────────────────────────────────────

	/**
	 * @testdox get_card_hash V3 Token 設為預設時應回傳 V3 Token
	 */
	public function test_get_card_hash_returns_v3_token_when_default(): void {
		// 建立 V1 Token（非預設）
		$this->create_payment_token( $this->customer_id, 'v1_hash_abc', 'payuni-credit-subscription' );
		// 建立 V3 Token（設為預設）
		$this->create_payment_token( $this->customer_id, 'v3_hash_xyz', 'payuni-credit-subscription-v3', true );

		$order = $this->create_test_order( $this->customer_id );

		$hash = \J7\Payuni\Domains\Subscription\SubscriptionHandler::get_card_hash( $order );

		$this->assertSame(
			'v3_hash_xyz',
			$hash,
			'V3 Token 設為預設時應回傳 V3 Token'
		);
	}

	/**
	 * @testdox get_card_hash 只有 V3 Token 時應回傳 V3 Token
	 */
	public function test_get_card_hash_returns_v3_only_token(): void {
		// 只建立 V3 Token
		$this->create_payment_token( $this->customer_id, 'v3_only_hash', 'payuni-credit-subscription-v3' );

		$order = $this->create_test_order( $this->customer_id );

		$hash = \J7\Payuni\Domains\Subscription\SubscriptionHandler::get_card_hash( $order );

		$this->assertSame(
			'v3_only_hash',
			$hash,
			'只有 V3 Token 時應回傳 V3 Token'
		);
	}

	/**
	 * @testdox get_card_hash 找不到 V3 Token 時 fallback 到 V1
	 */
	public function test_get_card_hash_falls_back_to_v1(): void {
		// 只建立 V1 Token
		$this->create_payment_token( $this->customer_id, 'v1_hash_only', 'payuni-credit-subscription' );

		$order = $this->create_test_order( $this->customer_id );

		$hash = \J7\Payuni\Domains\Subscription\SubscriptionHandler::get_card_hash( $order );

		$this->assertSame(
			'v1_hash_only',
			$hash,
			'找不到 V3 Token 時應 fallback 到 V1'
		);
	}

	/**
	 * @testdox get_card_hash 找不到任何 Token 時回傳空字串
	 */
	public function test_get_card_hash_returns_empty_when_no_tokens(): void {
		$order = $this->create_test_order( $this->customer_id );

		$hash = \J7\Payuni\Domains\Subscription\SubscriptionHandler::get_card_hash( $order );

		$this->assertSame(
			'',
			$hash,
			'找不到任何 Token 時應回傳空字串'
		);
	}

	/**
	 * @testdox get_card_hash 優先使用預設 Token（若為訂閱閘道）
	 */
	public function test_get_card_hash_prefers_default_token(): void {
		// 建立兩張 V3 Token，第一張設為預設
		$token1 = $this->create_payment_token( $this->customer_id, 'default_hash', 'payuni-credit-subscription-v3', true );
		$this->create_payment_token( $this->customer_id, 'newer_hash', 'payuni-credit-subscription-v3' );

		$order = $this->create_test_order( $this->customer_id );

		$hash = \J7\Payuni\Domains\Subscription\SubscriptionHandler::get_card_hash( $order );

		$this->assertSame(
			'default_hash',
			$hash,
			'應優先使用預設 Token'
		);
	}

	/**
	 * @testdox get_card_hash 可 fallback 到 payuni-credit-v3 閘道的 Token
	 */
	public function test_get_card_hash_falls_back_to_credit_v3(): void {
		// 只建立 payuni-credit-v3 Token
		$this->create_payment_token( $this->customer_id, 'credit_v3_hash', 'payuni-credit-v3' );

		$order = $this->create_test_order( $this->customer_id );

		$hash = \J7\Payuni\Domains\Subscription\SubscriptionHandler::get_card_hash( $order );

		$this->assertSame(
			'credit_v3_hash',
			$hash,
			'應可 fallback 到 payuni-credit-v3 閘道的 Token'
		);
	}

	/**
	 * @testdox get_card_hash 訪客（customer_id = 0）應回傳空字串
	 */
	public function test_get_card_hash_returns_empty_for_guest(): void {
		$order = $this->create_test_order( 0 );

		$hash = \J7\Payuni\Domains\Subscription\SubscriptionHandler::get_card_hash( $order );

		$this->assertSame(
			'',
			$hash,
			'訪客應回傳空字串'
		);
	}

	// ──────────────────────────────────────────────
	// 2. process_payment 路徑判斷
	// ──────────────────────────────────────────────

	/**
	 * @testdox process_payment 金額 = 0 + 有 Token 應直接完成付款
	 */
	public function test_process_payment_zero_amount_with_token(): void {
		// 建立 Token
		$token = $this->create_payment_token( $this->customer_id, 'test_hash_123', 'payuni-credit-subscription-v3' );

		// 建立零元訂單
		$order = $this->create_test_order( $this->customer_id, 0 );
		$order->set_payment_method( 'payuni-credit-subscription-v3' );

		// 模擬前端傳入 saved token
		$order->update_meta_data( 'payuni_use_saved_token', 'yes' );
		$order->update_meta_data( 'payuni_saved_token_id', (string) $token->get_id() );
		$order->save();

		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$result = $this->gateway->process_payment( $order->get_id() );
		error_reporting( $previous );

		$this->assertSame( 'success', $result['result'], '金額 = 0 + 有 Token 應回傳 success' );

		// 重新讀取訂單
		$order = \wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid(), '訂單應已付款完成' );
		$this->assertSame( (string) $token->get_id(), $order->get_meta( '_payuni_token_id', true ), '應儲存 token_id meta' );
	}

	/**
	 * @testdox process_payment 金額 = 0 + 無 Token 應走零元取 Token 流程（但不真的發 API）
	 */
	public function test_process_payment_zero_amount_without_token_returns_failure_without_api(): void {
		// 建立零元訂單（無 Token）
		$order = $this->create_test_order( $this->customer_id, 0 );
		$order->set_payment_method( 'payuni-credit-subscription-v3' );
		$order->save();

		// 因為沒有真正的 API 連線，process_payment 零元取 Token 路徑會失敗
		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$result = $this->gateway->process_payment( $order->get_id() );
		error_reporting( $previous );

		// 零元取 Token 會呼叫 API，在測試環境中會失敗，這是預期行為
		$this->assertSame( 'failure', $result['result'], '沒有 API 連線時零元取 Token 應回傳 failure' );
	}

	/**
	 * @testdox process_payment 金額 > 0 應走正常交易流程（但不真的發 API）
	 */
	public function test_process_payment_positive_amount_returns_failure_without_api(): void {
		$order = $this->create_test_order( $this->customer_id, 1000 );
		$order->set_payment_method( 'payuni-credit-subscription-v3' );
		$order->update_meta_data( 'sdk_token_tmp', 'test_sdk_token' );
		$order->save();

		// 因為沒有真正的 API 連線，會失敗
		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$result = $this->gateway->process_payment( $order->get_id() );
		error_reporting( $previous );

		$this->assertSame( 'failure', $result['result'], '沒有 API 連線時正常交易應回傳 failure' );
	}

	// ──────────────────────────────────────────────
	// 3. 排程取消授權
	// ──────────────────────────────────────────────

	/**
	 * @testdox schedule_cancel_authorization 應排程 payuni_cancel_zero_authorization 事件
	 */
	public function test_schedule_cancel_authorization(): void {
		$handler = new \J7\Payuni\Domains\Subscription\SubscriptionHandler();
		$handler->schedule_cancel_authorization( 'TEST_TRADE_NO_123' );

		// 排程優先走 Action Scheduler（WP-cron 事件於高流量/頁面快取站點會遺失），無 AS 時 fallback WP-cron。
		if ( \function_exists( 'as_next_scheduled_action' ) ) {
			$scheduled = \as_next_scheduled_action( 'payuni_cancel_zero_authorization', [ 'TEST_TRADE_NO_123' ] );

			$this->assertNotFalse( $scheduled, '應以 Action Scheduler 排程 payuni_cancel_zero_authorization' );
			$this->assertGreaterThanOrEqual( time() + 110, $scheduled, '排程時間應約在 2 分鐘後' );
			$this->assertLessThanOrEqual( time() + 130, $scheduled, '排程時間應約在 2 分鐘後' );

			// 清理
			\as_unschedule_all_actions( 'payuni_cancel_zero_authorization', [ 'TEST_TRADE_NO_123' ] );
			return;
		}

		$scheduled = \wp_next_scheduled( 'payuni_cancel_zero_authorization', [ 'TEST_TRADE_NO_123' ] );

		$this->assertNotFalse( $scheduled, '應排程 payuni_cancel_zero_authorization 事件' );
		$this->assertGreaterThanOrEqual( time() + 110, $scheduled, '排程時間應約在 2 分鐘後' );
		$this->assertLessThanOrEqual( time() + 130, $scheduled, '排程時間應約在 2 分鐘後' );

		// 清理
		\wp_clear_scheduled_hook( 'payuni_cancel_zero_authorization', [ 'TEST_TRADE_NO_123' ] );
	}

	// ──────────────────────────────────────────────
	// 4. 續扣失敗時無 Token 的處理
	// ──────────────────────────────────────────────

	/**
	 * @testdox process_renewal_payment 無 Token 時應將訂單設為 failed
	 */
	public function test_renewal_without_token_sets_order_failed(): void {
		$order = $this->create_test_order( $this->customer_id, 500 );
		$order->set_payment_method( 'payuni-credit-subscription-v3' );
		$order->save();

		$handler = new \J7\Payuni\Domains\Subscription\SubscriptionHandler();

		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$handler->process_renewal_payment( 500.0, $order );
		error_reporting( $previous );

		$order = \wc_get_order( $order->get_id() );
		$this->assertSame( 'failed', $order->get_status(), '無 Token 時應設為 failed' );
	}

	// ──────────────────────────────────────────────
	// 5. 續扣失敗處理 (subscription_fail_handler)
	// ──────────────────────────────────────────────

	/**
	 * @testdox subscription_fail_handler 對 V3 閘道 failed 訂單應改為 pending
	 */
	public function test_fail_handler_changes_v3_failed_to_pending(): void {
		$order = $this->create_test_order( $this->customer_id, 500 );
		$order->set_payment_method( 'payuni-credit-subscription-v3' );
		$order->update_status( 'failed' );
		$order->save();

		\J7\Payuni\Domains\Subscription\SubscriptionBootstrap::subscription_fail_handler( null, $order );

		$order = \wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $order->get_status(), 'V3 閘道 failed 訂單應改為 pending' );
	}

	/**
	 * @testdox subscription_fail_handler 對 V3 閘道但狀態非 failed 的訂單不應修改狀態
	 */
	public function test_fail_handler_does_not_change_non_failed_order(): void {
		$order = $this->create_test_order( $this->customer_id, 500 );
		$order->set_payment_method( 'payuni-credit-subscription-v3' );
		$order->update_status( 'processing' );
		$order->save();

		\J7\Payuni\Domains\Subscription\SubscriptionBootstrap::subscription_fail_handler( null, $order );

		$order = \wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $order->get_status(), '狀態非 failed 時不應修改' );
	}

	/**
	 * @testdox subscription_fail_handler 對 V1 閘道的 failed 訂單不應修改
	 */
	public function test_fail_handler_ignores_v1_gateway(): void {
		$order = $this->create_test_order( $this->customer_id, 500 );
		$order->set_payment_method( 'payuni-credit-subscription' );
		$order->update_status( 'failed' );
		$order->save();

		\J7\Payuni\Domains\Subscription\SubscriptionBootstrap::subscription_fail_handler( null, $order );

		$order = \wc_get_order( $order->get_id() );
		$this->assertSame( 'failed', $order->get_status(), 'V1 閘道不應被 V3 fail_handler 處理' );
	}

	/**
	 * @testdox subscription_fail_handler 對 PayUni 回應 SUCCESS 的 failed 訂單不應修改
	 */
	public function test_fail_handler_ignores_success_response(): void {
		$order = $this->create_test_order( $this->customer_id, 500 );
		$order->set_payment_method( 'payuni-credit-subscription-v3' );
		$order->update_status( 'failed' );
		$order->update_meta_data( '_payuni_resp_status', 'SUCCESS' );
		$order->save();

		\J7\Payuni\Domains\Subscription\SubscriptionBootstrap::subscription_fail_handler( null, $order );

		$order = \wc_get_order( $order->get_id() );
		$this->assertSame( 'failed', $order->get_status(), 'PayUni 回應 SUCCESS 時不應改為 pending' );
	}

	// ──────────────────────────────────────────────
	// 6. 續扣排程呼叫
	// ──────────────────────────────────────────────

	/**
	 * @testdox process_scheduled_payment 應委派給 SubscriptionHandler 處理
	 */
	public function test_process_scheduled_payment_delegates_to_handler(): void {
		$order = $this->create_test_order( $this->customer_id, 500 );
		$order->set_payment_method( 'payuni-credit-subscription-v3' );
		$order->save();

		// 無 Token 時呼叫 process_scheduled_payment，應導致 failed
		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		\J7\Payuni\Domains\Subscription\SubscriptionBootstrap::process_scheduled_payment( 500.0, $order );
		error_reporting( $previous );

		$order = \wc_get_order( $order->get_id() );
		$this->assertSame(
			'failed',
			$order->get_status(),
			'process_scheduled_payment 應委派處理（無 Token 應 failed）'
		);
	}

	// ──────────────────────────────────────────────
	// 7. save_payment_token 參數化 gateway_id
	// ──────────────────────────────────────────────

	/**
	 * @testdox save_payment_token 預設使用 CreditV3 閘道 ID
	 */
	public function test_save_payment_token_default_gateway_id(): void {
		$handler = new \J7\Payuni\Infrastructure\Http\TradeHandler();
		$handler->save_payment_token(
			$this->customer_id,
			'test_hash_default',
			'411111',
			'1234',
			'1230'
		);

		$tokens = \WC_Payment_Tokens::get_customer_tokens( $this->customer_id, 'payuni-credit-v3' );

		$this->assertCount( 1, $tokens, '應儲存到 payuni-credit-v3 閘道' );

		$token = \reset( $tokens );
		$this->assertSame( 'test_hash_default', $token->get_token() );
		$this->assertSame( 'payuni-credit-v3', $token->get_gateway_id() );
	}

	/**
	 * @testdox save_payment_token 可指定 subscription V3 閘道 ID
	 */
	public function test_save_payment_token_subscription_gateway_id(): void {
		$handler = new \J7\Payuni\Infrastructure\Http\TradeHandler();
		$handler->save_payment_token(
			$this->customer_id,
			'test_hash_sub',
			'522222',
			'5678',
			'0631',
			'payuni-credit-subscription-v3'
		);

		$tokens = \WC_Payment_Tokens::get_customer_tokens( $this->customer_id, 'payuni-credit-subscription-v3' );

		$this->assertCount( 1, $tokens, '應儲存到 payuni-credit-subscription-v3 閘道' );

		$token = \reset( $tokens );
		$this->assertSame( 'test_hash_sub', $token->get_token() );
		$this->assertSame( 'payuni-credit-subscription-v3', $token->get_gateway_id() );
		$this->assertSame( 'mastercard', $token->get_card_type(), '5 開頭應為 mastercard' );
	}

	/**
	 * @testdox save_payment_token 不會重複儲存相同 Token
	 */
	public function test_save_payment_token_no_duplicate(): void {
		$handler = new \J7\Payuni\Infrastructure\Http\TradeHandler();

		// 第一次儲存
		$handler->save_payment_token(
			$this->customer_id,
			'dup_hash',
			'411111',
			'9999',
			'1230',
			'payuni-credit-subscription-v3'
		);

		// 第二次儲存相同 hash
		$handler->save_payment_token(
			$this->customer_id,
			'dup_hash',
			'411111',
			'9999',
			'1230',
			'payuni-credit-subscription-v3'
		);

		$tokens = \WC_Payment_Tokens::get_customer_tokens( $this->customer_id, 'payuni-credit-subscription-v3' );

		$this->assertCount( 1, $tokens, '不應重複儲存相同 Token' );
	}

	/**
	 * @testdox save_payment_token 正確解析有效期限
	 */
	public function test_save_payment_token_parses_expiry(): void {
		$handler = new \J7\Payuni\Infrastructure\Http\TradeHandler();
		$handler->save_payment_token(
			$this->customer_id,
			'expiry_test_hash',
			'411111',
			'4242',
			'0628', // MMYY 格式
			'payuni-credit-subscription-v3'
		);

		$tokens = \WC_Payment_Tokens::get_customer_tokens( $this->customer_id, 'payuni-credit-subscription-v3' );
		$token = \reset( $tokens );

		$this->assertSame( '06', $token->get_expiry_month(), '月份應為 06' );
		$this->assertSame( '2028', $token->get_expiry_year(), '年份應為 2028' );
	}

	// ──────────────────────────────────────────────
	// 8. 換卡流程（process_payment 用新卡重新走交易）
	// ──────────────────────────────────────────────

	/**
	 * @testdox process_payment 金額 = 0 + 不同 Gateway 的 Token 也能找到並完成
	 */
	public function test_process_payment_zero_amount_with_credit_v3_token(): void {
		// 建立 payuni-credit-v3 Token（模擬跨閘道 Token）
		$token = $this->create_payment_token( $this->customer_id, 'cross_gw_hash', 'payuni-credit-v3' );

		$order = $this->create_test_order( $this->customer_id, 0 );
		$order->set_payment_method( 'payuni-credit-subscription-v3' );
		$order->save();

		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$result = $this->gateway->process_payment( $order->get_id() );
		error_reporting( $previous );

		$this->assertSame( 'success', $result['result'], '應能用 credit-v3 Token 完成零元付款' );

		$order = \wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid(), '訂單應已完成付款' );
	}

	// ──────────────────────────────────────────────
	// 9. issue #147 第一階段：Token 優先序（V1 預設 token 不可搶先）
	//
	// 執行指令：
	// docker exec <woomp-tests-cli> sh -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --filter "T_H|T_Z1" tests/phpunit/integration/SubscriptionV3PaymentTest.php'
	// ──────────────────────────────────────────────

	/**
	 * T-H1：舊 V1 預設 token 不會搶先
	 *
	 * @group edge
	 */
	public function test_T_H1_v1預設token不搶先_回傳非預設的v3_hash(): void {
		$this->create_payment_token( $this->customer_id, 'v1_default_hash', 'payuni-credit-subscription', true );
		$this->create_payment_token( $this->customer_id, 'v3_non_default_hash', 'payuni-credit-subscription-v3' );

		$order = $this->create_test_order( $this->customer_id );

		$this->assertSame(
			'v3_non_default_hash',
			\J7\Payuni\Domains\Subscription\SubscriptionHandler::get_card_hash( $order ),
			'V1 是客戶預設 token、另有一張 V3 token 時，續扣應使用 V3，不可被舊 V1 預設 token 搶先'
		);
	}

	/**
	 * T-H2：續訂單所屬訂閱的 _payuni_token_id 優先於客戶預設 token
	 *
	 * @group happy
	 */
	public function test_T_H2_訂閱綁定的token優先於客戶預設token(): void {
		\Woomp_Test_Wcs_Fixture::install();

		$default_token = $this->create_payment_token( $this->customer_id, 'default_v3_hash', 'payuni-credit-subscription-v3', true );
		$bound_token   = $this->create_payment_token( $this->customer_id, 'bound_v3_hash', 'payuni-credit-subscription-v3' );
		$this->assertNotSame( $default_token->get_id(), $bound_token->get_id() );

		$renewal = $this->create_subscription_renewal( (string) $bound_token->get_id() );

		$this->assertSame(
			'bound_v3_hash',
			\J7\Payuni\Domains\Subscription\SubscriptionHandler::get_card_hash( $renewal ),
			'續訂單所屬訂閱綁定的 V3 token 應優先於客戶預設 token'
		);
	}

	/**
	 * T-H3：訂閱綁定的 token 不合法（V1／別人的／已刪除）時跳過，往下一層找
	 *
	 * @dataProvider invalid_bound_token_provider
	 * @group edge
	 * @group security
	 *
	 * @param string $scenario 情境
	 */
	public function test_T_H3_訂閱綁定的token不合法時跳過( string $scenario ): void {
		\Woomp_Test_Wcs_Fixture::install();

		// 客戶預設是 V1、另有一張 V3：不合法時應落到「最新的 V3 訂閱 token」，而不是 V1 預設
		$this->create_payment_token( $this->customer_id, 'v1_default_hash', 'payuni-credit-subscription', true );
		$this->create_payment_token( $this->customer_id, 'v3_fallback_hash', 'payuni-credit-subscription-v3' );

		switch ( $scenario ) {
			case 'v1_token':
				$bound_id = (string) $this->create_payment_token( $this->customer_id, 'v1_bound_hash', 'payuni-credit-subscription' )->get_id();
				break;
			case 'other_customers_token':
				$other    = $this->factory()->user->create( [ 'role' => 'customer' ] );
				$bound_id = (string) $this->create_payment_token( $other, 'other_customer_hash', 'payuni-credit-subscription-v3' )->get_id();
				break;
			default: // deleted_token
				$token    = $this->create_payment_token( $this->customer_id, 'deleted_hash', 'payuni-credit-subscription-v3' );
				$bound_id = (string) $token->get_id();
				\WC_Payment_Tokens::delete( $token->get_id() );
		}

		$renewal = $this->create_subscription_renewal( $bound_id );

		$hash = \J7\Payuni\Domains\Subscription\SubscriptionHandler::get_card_hash( $renewal );

		$this->assertNotSame( 'other_customer_hash', $hash, '絕不可使用別人的 token' );
		$this->assertNotSame( 'v1_bound_hash', $hash, '訂閱綁定的 V1 token 不合法，應跳過' );
		$this->assertSame( 'v3_fallback_hash', $hash, "[{$scenario}] 應跳過不合法的綁定，往下找最新的 V3 訂閱 token（而非 V1 預設）" );
	}

	/**
	 * T-H3 情境
	 *
	 * @return array<string, array{0: string}>
	 */
	public function invalid_bound_token_provider(): array {
		return [
			'綁定的是 V1 token'   => [ 'v1_token' ],
			'綁定的是別人的 token' => [ 'other_customers_token' ],
			'綁定的 token 已刪除' => [ 'deleted_token' ],
		];
	}

	/**
	 * T-H4：只有 V1 token 時仍回傳 V1 hash，並寫一則 warning
	 *
	 * @group edge
	 */
	public function test_T_H4_只有v1_token_回傳v1_hash且寫warning(): void {
		$this->create_payment_token( $this->customer_id, 'v1_only_hash', 'payuni-credit-subscription', true );

		$logs = [];
		\add_action(
			'woomp_payuni_log',
			static function ( $level, $message ) use ( &$logs ) {
				$logs[] = [ (string) $level, (string) $message ];
			},
			10,
			2
		);

		$order = $this->create_test_order( $this->customer_id );
		$hash  = \J7\Payuni\Domains\Subscription\SubscriptionHandler::get_card_hash( $order );

		$this->assertSame( 'v1_only_hash', $hash, '只有 V1 token 時仍應回傳 V1 hash（最後手段）' );

		$warnings = \array_filter(
			$logs,
			static fn( array $log ): bool => 'warning' === $log[0]
		);
		$this->assertNotEmpty( $warnings, '使用舊 V1 token 時應寫一則 warning，提醒商家可能需重新綁卡' );
	}

	/**
	 * T-Z1：0 元首購、客戶只有 V1 token → 不可直接拿 V1 token 完成，要打 PayUni 綁 5 元
	 *
	 * @group edge
	 */
	public function test_T_Z1_零元首購只有v1_token_應打payuni綁卡而非直接完成(): void {
		$this->create_payment_token( $this->customer_id, 'v1_default_hash', 'payuni-credit-subscription', true );

		$order = $this->create_test_order( $this->customer_id, 0 );
		$order->update_meta_data( 'sdk_token_tmp', 'test_sdk_token' );
		$order->save();

		$this->mock_merchant_trade_success( 'NEW_V3_HASH' );

		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$this->gateway->process_payment( $order->get_id() );
		error_reporting( $previous );

		$trade_request = null;
		foreach ( $this->http_requests as $request ) {
			if ( false !== \strpos( $request['url'], 'merchant_trade' ) ) {
				$trade_request = $request;
				break;
			}
		}

		$this->assertNotNull( $trade_request, '客戶只有 V1 token 的 0 元首購應打 PayUni 取新 V3 token，不可直接用 V1 token 完成' );

		$params = \J7\Payuni\Shared\Utils\EncryptUtils::decrypt( $trade_request['args']['body']['EncryptInfo'] ?? '' );
		$this->assertSame( 5, (int) ( $params['TradeAmt'] ?? 0 ), '應授權 5 元取得 CreditHash' );
	}

	/**
	 * T-Z1：0 元首購走路徑 2（有 V3 token）成功後，token 要綁到該訂單的訂閱
	 *
	 * @group happy
	 */
	public function test_T_Z1_零元首購路徑2成功後_token綁到訂閱(): void {
		\Woomp_Test_Wcs_Fixture::install();

		$token = $this->create_payment_token( $this->customer_id, 'v3_saved_hash', 'payuni-credit-subscription-v3', true );

		$order = $this->create_test_order( $this->customer_id, 0 );
		$order->set_payment_method( 'payuni-credit-subscription-v3' );
		$order->save();

		$subscription = \Woomp_Test_Wcs_Fixture::create_subscription( $order, $this->customer_id );

		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$result   = $this->gateway->process_payment( $order->get_id() );
		error_reporting( $previous );

		$this->assertSame( 'success', $result['result'] );

		$subscription = \wc_get_order( $subscription->get_id() );
		$this->assertSame(
			(string) $token->get_id(),
			(string) $subscription->get_meta( '_payuni_token_id', true ),
			'路徑 2 完成付款後，應把 token ID 寫入該訂單所屬訂閱的 _payuni_token_id'
		);
	}

	// ──────────────────────────────────────────────
	// Helper 方法
	// ──────────────────────────────────────────────

	/**
	 * 建立「訂閱 → 續訂單」，訂閱綁定指定的 _payuni_token_id
	 *
	 * @param string $bound_token_id 要寫進訂閱的 _payuni_token_id
	 *
	 * @return \WC_Order 續訂單
	 */
	private function create_subscription_renewal( string $bound_token_id ): \WC_Order {
		$parent       = $this->create_test_order( $this->customer_id );
		$subscription = \Woomp_Test_Wcs_Fixture::create_subscription( $parent, $this->customer_id );
		$subscription->update_meta_data( '_payuni_token_id', $bound_token_id );
		$subscription->save();

		return \Woomp_Test_Wcs_Fixture::create_renewal_order( $subscription, $this->customer_id );
	}

	/**
	 * 攔截 merchant_trade 並回傳加密過的授權成功結果
	 *
	 * @param string $credit_hash 回傳的 CreditHash
	 *
	 * @return void 攔截到的請求累積在 $this->http_requests
	 */
	private function mock_merchant_trade_success( string $credit_hash ): void {
		\add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $credit_hash ) {
				$this->http_requests[] = [
					'url'  => $url,
					'args' => $args,
				];

				$payload      = [
					'Status'     => 'SUCCESS',
					'Message'    => '授權成功',
					'MerID'      => 'TEST_MERCHANT',
					'MerTradeNo' => 'T-Z1',
					'TradeNo'    => 'TRADE_Z1_0001',
					'TradeAmt'   => '5',
					'Card6No'    => '411111',
					'Card4No'    => '4242',
					'CreditHash' => $credit_hash,
					'CreditLife' => '1230',
				];
				$encrypt_info = \J7\Payuni\Shared\Utils\EncryptUtils::encrypt( $payload );

				return [
					'headers'  => [],
					'body'     => \wp_json_encode(
						[
							'Status'      => 'SUCCESS',
							'MerID'       => 'TEST_MERCHANT',
							'Version'     => '1.0',
							'EncryptInfo' => $encrypt_info,
							'HashInfo'    => \J7\Payuni\Shared\Utils\EncryptUtils::hash_info( $encrypt_info ),
						]
					),
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'cookies'  => [],
					'filename' => null,
				];
			},
			10,
			3
		);
	}

	/**
	 * 建立測試用 Payment Token
	 *
	 * @param int    $customer_id 客戶 ID
	 * @param string $token_value Token 值（CreditHash）
	 * @param string $gateway_id  閘道 ID
	 * @param bool   $is_default  是否為預設
	 *
	 * @return \WC_Payment_Token_CC Token 物件
	 */
	private function create_payment_token( int $customer_id, string $token_value, string $gateway_id, bool $is_default = false ): \WC_Payment_Token_CC {
		$token = new \WC_Payment_Token_CC();
		$token->set_token( $token_value );
		$token->set_gateway_id( $gateway_id );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( $customer_id );

		if ( $is_default ) {
			$token->set_default( true );
		}

		$token->save();

		return $token;
	}

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

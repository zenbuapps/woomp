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
 * 範圍（第二階段，後端換卡流程，紅燈）：T-C1～T-C9、T-N1～T-N5、T-M1。
 * - 換卡請求（WCS order-pay 換付款方式）進 CreditSubscriptionV3::process_payment($subscription_id)
 *   一律交給 PaymentMethodChange::start()：只授權 5 元（UseTokenType=3）、絕不扣整期。
 * - WCS 預設在呼叫 process_payment 之前就改付款方式；V3 必須延後到綁卡成功才改。
 * - 3D 路徑靠背景通知（Bootstrap::handle_notify）完成：先以 MerTradeNo `{訂閱ID}-C{n}` 分流。
 * - 這些測試需要真正的 WooCommerce Subscriptions（WC_Subscription），PayUni 請求用 pre_http_request 攔截，
 *   以 EncryptUtils::decrypt 檢查送出內容，回應用真正的 AES-256-GCM 加密（不手刻假字串）。
 * - T-F*、T-W1 在別的測試檔。
 *
 * 執行指令：
 * docker exec <woomp-tests-cli> sh -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --filter SubscriptionV3PaymentMethodChange'
 *
 * @package Woomp\Tests\Integration
 */

require_once dirname( __DIR__ ) . '/support/WcsFixture.php';

/**
 * 中止 wp_die()／wp_send_json() 用的例外，把控制權交回測試。
 */
final class PaymentMethodChangeHalt extends \RuntimeException {}

/**
 * V3 定期定額付款 meta 與換卡流程測試類別
 *
 * @group subscription
 * @group payuni
 */
final class SubscriptionV3PaymentMethodChangeTest extends WP_UnitTestCase {

	private const GATEWAY_ID = 'payuni-credit-subscription-v3';
	private const V1_GATEWAY_ID = 'payuni-credit-subscription';
	private const VALIDATE_HOOK = 'woocommerce_subscription_validate_payment_meta_payuni-credit-subscription-v3';
	private const META_FILTER   = 'woocommerce_subscription_payment_meta';
	private const SDK_TOKEN     = 'SDK_TOKEN_T147';
	private const THREE_D_URL   = 'https://sandbox-api.payuni.com.tw/api/credit_3d/T147ABC';
	private const CANCEL_HOOK   = 'payuni_cancel_zero_authorization';
	private const CHANGE_CLASS  = \J7\Payuni\Domains\Subscription\PaymentMethodChange::class;

	/**
	 * 測試用客戶 ID
	 *
	 * @var int
	 */
	private int $customer_id;

	/**
	 * 攔截到的 PayUni 請求（含解密後參數）
	 *
	 * @var array<int, array{url: string, args: array, params: array}>
	 */
	private array $requests = [];

	/**
	 * 產生 PayUni 回應內層 payload 的 callable：fn( array $params, string $url ): array|WP_Error
	 *
	 * @var callable
	 */
	private $payuni_handler;

	/**
	 * Woomp_Payment_Callback_Guard::reject() 記下的 [原因, context JSON]
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $rejections = [];

	/**
	 * woomp_payuni_log 的 error 訊息
	 *
	 * @var string[]
	 */
	private array $error_logs = [];

	/**
	 * 測試前的換卡旗標
	 *
	 * @var bool
	 */
	private bool $saved_change_flag = false;

	/**
	 * 是否動過 HPOS 設定（tearDown 還原）
	 *
	 * @var bool
	 */
	private bool $hpos_toggled = false;

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
		update_option( 'payuni_3d_auth', 'no' );
		$this->reset_setting_dto();

		if ( null === WC()->session ) {
			WC()->session = new \WC_Session_Handler();
			WC()->session->init();
		}
		wc_clear_notices();

		$this->customer_id       = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$this->requests          = [];
		$this->rejections        = [];
		$this->error_logs        = [];
		$this->saved_change_flag = class_exists( 'WC_Subscriptions_Change_Payment_Gateway' )
			? (bool) \WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment
			: false;
		$this->payuni_handler    = fn( array $p ) => $this->three_d_payload( $p );

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
		add_action( 'woomp_payment_callback_rejected', [ $this, 'record_rejection' ], 10, 3 );
		add_action( 'woomp_payuni_log', [ $this, 'record_log' ], 10, 3 );
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_doing_ajax' );
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_action( 'woomp_payment_callback_rejected', [ $this, 'record_rejection' ], 10 );
		remove_action( 'woomp_payuni_log', [ $this, 'record_log' ], 10 );

		if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) ) {
			\WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment = $this->saved_change_flag;
		}
		$_GET     = [];
		$_POST    = [];
		$_REQUEST = [];
		wp_set_current_user( 0 );
		wc_clear_notices();

		foreach ( \WC_Payment_Tokens::get_customer_tokens( $this->customer_id ) as $token ) {
			\WC_Payment_Tokens::delete( $token->get_id() );
		}

		if ( $this->hpos_toggled ) {
			$this->set_hpos( false );
			$this->hpos_toggled = false;
		}

		update_option( 'payuni_payment_testmode', 'yes' );
		update_option( 'payuni_3d_auth', 'no' );
		$this->reset_setting_dto();

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
	// 第二階段：換卡（同步路徑）T-C*
	// ──────────────────────────────────────────────

	/**
	 * T-C1 補充：is_change_request() 依 WCS 旗標或 $_GET['change_payment_method'] 判斷
	 *
	 * @group smoke
	 */
	public function test_T_C1_is_change_request偵測換卡請求(): void {
		$this->require_change_api();
		$this->require_wcs();

		\WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment = false;
		unset( $_GET['change_payment_method'] );
		$this->assertFalse( \J7\Payuni\Domains\Subscription\PaymentMethodChange::is_change_request(), '沒有旗標也沒有 GET 參數不是換卡' );

		\WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment = true;
		$this->assertTrue( \J7\Payuni\Domains\Subscription\PaymentMethodChange::is_change_request(), 'WCS 旗標為 true 是換卡' );

		\WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment = false;
		$_GET['change_payment_method'] = '123';
		$this->assertTrue( \J7\Payuni\Domains\Subscription\PaymentMethodChange::is_change_request(), '帶 change_payment_method 是換卡' );
	}

	/**
	 * T-C1：換卡不扣整期。訂閱 total 9,450，送出的只能是 5 元、強制約定、MerTradeNo 為 {ID}-C{n}
	 *
	 * @group happy
	 */
	public function test_T_C1_換卡不扣整期_只授權5元且強制約定(): void {
		$subscription         = $this->create_v1_subscription();
		$this->payuni_handler = fn( array $p ) => $this->success_payload( $p );

		$this->submit_change( $subscription );

		$trades = $this->trade_requests();
		$this->assertCount( 1, $trades, '換卡只能送一筆 merchant_trade' );
		$params = $trades[0]['params'];
		$this->assertSame( 5, (int) ( $params['TradeAmt'] ?? 0 ), '換卡只授權 5 元，不可扣訂閱每期金額 9450' );
		$this->assertSame( 3, (int) ( $params['UseTokenType'] ?? 0 ), '換卡必須 UseTokenType=3（強制約定），才拿得到 CreditHash' );
		$this->assertSame( self::SDK_TOKEN, $params['Token'] ?? '', '要帶前端 SDK Token' );
		$this->assertMatchesRegularExpression(
			'/^' . $subscription->get_id() . '-C\d+$/',
			(string) ( $params['MerTradeNo'] ?? '' ),
			'MerTradeNo 格式 {訂閱ID}-C{n}'
		);
	}

	/**
	 * T-C2：客戶有舊 V1 預設 token，仍必須呼叫 PayUni 重新綁卡，不可直接拿 V1 token 當成功
	 *
	 * @group edge
	 */
	public function test_T_C2_有舊v1預設token仍會重新綁卡(): void {
		$v1_token = $this->create_token( $this->customer_id, 'OLD_V1_HASH', self::V1_GATEWAY_ID );
		$v1_token->set_default( true );
		$v1_token->save();
		$subscription         = $this->create_v1_subscription();
		$this->payuni_handler = fn( array $p ) => $this->success_payload( $p, 'NEW_CREDIT_HASH' );

		$this->submit_change( $subscription );

		$this->assertCount( 1, $this->trade_requests(), '有舊 token 也必須打 API 重新綁卡' );
		$fresh = wcs_get_subscription( $subscription->get_id() );
		$this->assertNotSame( (string) $v1_token->get_id(), (string) $fresh->get_meta( '_payuni_token_id' ), '不可綁到舊 V1 token' );
		$new = $this->find_token_by_hash( 'NEW_CREDIT_HASH', self::GATEWAY_ID );
		$this->assertNotNull( $new, '應建立新的 V3 token' );
		$this->assertSame( (string) $new->get_id(), (string) $fresh->get_meta( '_payuni_token_id' ) );
	}

	/**
	 * T-C3：同步成功 → 付款方式 V3、綁新 token、設為預設、排程取消 5 元、訂閱仍 active
	 *
	 * @group happy
	 */
	public function test_T_C3_同步成功後訂閱改為v3並綁新token(): void {
		$this->assert_sync_success_binds_v3();
	}

	/**
	 * T-C3（HPOS 關閉）
	 *
	 * @group hpos
	 */
	public function test_T_C3_同步成功_HPOS關閉(): void {
		$this->set_hpos( false );
		$this->assertFalse( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), '前提：HPOS 關閉' );
		$this->assert_sync_success_binds_v3();
	}

	/**
	 * T-C3（HPOS 開啟）
	 *
	 * @group hpos
	 */
	public function test_T_C3_同步成功_HPOS開啟(): void {
		$this->set_hpos( true );
		$this->assertTrue( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), '前提：HPOS 開啟' );
		$this->assert_sync_success_binds_v3();
	}

	/**
	 * T-C4：同步成功時，訂閱有 pending 續訂單 → 續訂單仍 pending（不可被 5 元標成已付款）
	 *
	 * @group edge
	 */
	public function test_T_C4_同步成功時pending續訂單維持pending(): void {
		$subscription         = $this->create_v1_subscription();
		$renewal              = \Woomp_Test_Wcs_Fixture::create_renewal_order( $subscription, $this->customer_id, 'pending' );
		$this->payuni_handler = fn( array $p ) => $this->success_payload( $p );

		$result = $this->submit_change( $subscription );

		$this->assertSame( 'success', $result['result'] ?? '', '前提：換卡成功' );
		$fresh = wc_get_order( $renewal->get_id() );
		$this->assertSame( 'pending', $fresh->get_status(), '續訂單必須維持 pending' );
		$this->assertFalse( $fresh->is_paid() );
	}

	/**
	 * T-C5：3D 路徑 → 回傳 PayUni URL、付款方式仍 V1、有 pending、本次請求放行 payuni 網域
	 *
	 * @group happy
	 */
	public function test_T_C5_3d路徑導向payuni且付款方式不變(): void {
		update_option( 'payuni_3d_auth', 'yes' );
		$this->reset_setting_dto();
		$subscription = $this->create_v1_subscription();

		$result = $this->submit_change( $subscription );

		$this->assertSame( 'success', $result['result'] ?? '' );
		$this->assertSame( self::THREE_D_URL, $result['redirect'] ?? '', '要導向 PayUni 的 3D 網址' );

		$params = $this->trade_requests()[0]['params'];
		$this->assertSame( 1, (int) ( $params['API3D'] ?? 0 ), '3D 開啟時要帶 API3D=1' );
		$this->assertSame( $subscription->get_view_order_url(), $params['ReturnURL'] ?? '', 'ReturnURL 回訂閱檢視頁' );

		$fresh = wcs_get_subscription( $subscription->get_id() );
		$this->assertSame( self::V1_GATEWAY_ID, $fresh->get_payment_method(), '3D 完成前付款方式不可改' );
		$pending = $fresh->get_meta( '_payuni_change_pending' );
		$this->assertNotEmpty( $pending, '要留下 pending 供 webhook 對應' );
		$mer = (string) $params['MerTradeNo'];
		$this->assertStringContainsString( $mer, (string) wp_json_encode( $pending ), 'pending 要記本次 MerTradeNo' );
		$this->assertStringContainsString( $mer, (string) wp_json_encode( $fresh->get_meta( '_payuni_change_attempts' ) ), 'attempts 要記本次 MerTradeNo' );

		$this->assertSame( self::THREE_D_URL, wp_validate_redirect( self::THREE_D_URL, 'https://fallback.invalid/' ), 'payuni 網域要被 wp_safe_redirect 放行' );
		$this->assertSame( 'https://fallback.invalid/', wp_validate_redirect( 'https://evil.example.com/x', 'https://fallback.invalid/' ), '其他外部網域不可放行' );
	}

	/**
	 * T-C5：V3 閘道 supports 延後換卡，WCS 才會顯示「同時更新所有訂閱」
	 *
	 * @group smoke
	 */
	public function test_T_C5_閘道支援延後換付款方式(): void {
		$gateway = $this->get_v3_gateway();
		$this->assertTrue( $gateway->supports( 'subscription_payment_method_delayed_change' ) );
	}

	/**
	 * T-C5：WCS 的 update_payment_via_pay_shortcode filter 對 V3 回 false（延後更新），其他閘道不受影響
	 *
	 * @group edge
	 */
	public function test_T_C5_filter對v3回false延後更新(): void {
		$this->require_change_api();
		$subscription = $this->create_v1_subscription();

		$this->assertFalse(
			apply_filters( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', true, self::GATEWAY_ID, $subscription ),
			'V3 必須延後更新，否則 3D 失敗時訂閱會停在 V3 卻沒有 token'
		);
		$this->assertTrue(
			apply_filters( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', true, 'bacs', $subscription ),
			'其他閘道維持 WCS 預設'
		);
	}

	/**
	 * T-C6：沒有 sdk_token → failure + notice，沒有發出 HTTP
	 *
	 * @group error
	 */
	public function test_T_C6_沒有sdk_token失敗且不發請求(): void {
		$subscription = $this->create_v1_subscription();

		$result = $this->submit_change( $subscription, '' );

		$this->assertSame( 'failure', $result['result'] ?? '' );
		$this->assertGreaterThan( 0, wc_notice_count( 'error' ), '要提示客戶重新輸入卡片' );
		$this->assertCount( 0, $this->trade_requests(), '沒有 SDK token 不可呼叫 PayUni' );
		$this->assertSame( self::V1_GATEWAY_ID, wcs_get_subscription( $subscription->get_id() )->get_payment_method() );
	}

	/**
	 * T-C6：PayUni HTTP 逾時 → failure + notice，付款方式不變，attempt 標 failed
	 *
	 * @group error
	 */
	public function test_T_C6_http錯誤失敗且付款方式不變(): void {
		$subscription         = $this->create_v1_subscription();
		$this->payuni_handler = fn() => new \WP_Error( 'http_request_failed', 'cURL error 28: timeout' );

		$result = $this->submit_change( $subscription );

		$this->assertSame( 'failure', $result['result'] ?? '' );
		$this->assertGreaterThan( 0, wc_notice_count( 'error' ) );
		$fresh = wcs_get_subscription( $subscription->get_id() );
		$this->assertSame( self::V1_GATEWAY_ID, $fresh->get_payment_method() );
		$this->assertStringContainsString( 'failed', (string) wp_json_encode( $fresh->get_meta( '_payuni_change_attempts' ) ), 'attempt 要標記 failed' );
	}

	/**
	 * T-C6：PayUni 回拒絕（無 TradeNo 也無 URL）→ failure，notice 含狀態碼
	 *
	 * @group error
	 */
	public function test_T_C6_授權被拒顯示狀態碼(): void {
		$subscription         = $this->create_v1_subscription();
		$this->payuni_handler = fn( array $p ) => $this->decline_payload( $p );

		$result = $this->submit_change( $subscription );

		$this->assertSame( 'failure', $result['result'] ?? '' );
		$messages = wp_json_encode( wc_get_notices( 'error' ), JSON_UNESCAPED_UNICODE );
		$this->assertStringContainsString( 'CREDIT04001', (string) $messages, 'notice 要含狀態碼' );
	}

	/**
	 * T-C7：授權成功但沒有 CreditHash（PROD）→ 付款方式不變、有「綁卡未完成」備註、沒有 token
	 *
	 * @group error
	 */
	public function test_T_C7_無credit_hash時不改付款方式(): void {
		update_option( 'payuni_payment_testmode', 'no' );
		update_option( 'payuni_payment_merchant_no', 'PROD_MERCHANT' );
		update_option( 'payuni_payment_hash_key', 'PROD_HASH_KEY_1234567890123456' );
		update_option( 'payuni_payment_hash_iv', 'PROD_HASH_IV_1234567' );
		$this->reset_setting_dto();
		$subscription         = $this->create_v1_subscription();
		$this->payuni_handler = fn( array $p ) => $this->success_payload( $p, '' );

		$result = $this->submit_change( $subscription );

		$this->assertSame( 'failure', $result['result'] ?? '', '沒有 CreditHash 不算綁卡成功' );
		$this->assertGreaterThan( 0, wc_notice_count( 'error' ), '同步路徑要顯示 notice' );
		$fresh = wcs_get_subscription( $subscription->get_id() );
		$this->assertSame( self::V1_GATEWAY_ID, $fresh->get_payment_method(), '付款方式不可改' );
		$this->assertCount( 0, \WC_Payment_Tokens::get_customer_tokens( $this->customer_id ), '不可存假 token' );
		$this->assertStringContainsString( '綁卡未完成', implode( "\n", $this->note_contents( $fresh ) ), '要有「綁卡未完成」備註' );
	}

	/**
	 * T-C8：連續送出（含失敗那次）每次 MerTradeNo 都不同，同編號重送 PayUni 會回 IFTRADE01006
	 *
	 * @group edge
	 */
	public function test_T_C8_連續送出MerTradeNo各不相同(): void {
		$subscription         = $this->create_v1_subscription();
		$call                 = 0;
		$this->payuni_handler = function ( array $p ) use ( &$call ) {
			++$call;
			return 2 === $call ? $this->decline_payload( $p ) : $this->three_d_payload( $p );
		};

		$this->submit_change( $subscription );
		$this->submit_change( $subscription );
		$this->submit_change( $subscription );

		$numbers = array_map( static fn( $r ) => (string) ( $r['params']['MerTradeNo'] ?? '' ), $this->trade_requests() );
		$this->assertCount( 3, $numbers );
		$this->assertCount( 3, array_unique( $numbers ), '每次嘗試（含失敗）都要用新的 MerTradeNo：' . implode( ',', $numbers ) );
		foreach ( $numbers as $number ) {
			$this->assertMatchesRegularExpression( '/^' . $subscription->get_id() . '-C\d+$/', $number );
		}
	}

	/**
	 * T-C9：訪客或 customer_id 0 → failure，不發請求
	 *
	 * @group security
	 */
	public function test_T_C9_訪客或customer_id為0時失敗(): void {
		$subscription = $this->create_v1_subscription();
		$subscription->set_customer_id( 0 );
		$subscription->save();

		$result = $this->submit_change( $subscription );

		$this->assertSame( 'failure', $result['result'] ?? '' );
		$this->assertCount( 0, $this->trade_requests(), '沒有客戶不可綁卡' );
		$this->assertSame( self::V1_GATEWAY_ID, wcs_get_subscription( $subscription->get_id() )->get_payment_method() );
	}

	// ──────────────────────────────────────────────
	// 第二階段：換卡背景通知 T-N*
	// ──────────────────────────────────────────────

	/**
	 * T-N1：3D 之後 webhook 回 SUCCESS → 完成綁定；有 _delayed_update_payment_method_all 時同客戶另一筆 active 訂閱也被切換
	 *
	 * @group happy
	 */
	public function test_T_N1_webhook成功完成綁定並更新全部訂閱(): void {
		$this->assert_notify_updates_all_subscriptions();
	}

	/**
	 * T-N1（HPOS 關閉）
	 *
	 * @group hpos
	 */
	public function test_T_N1_webhook成功_HPOS關閉(): void {
		$this->set_hpos( false );
		$this->assertFalse( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), '前提：HPOS 關閉' );
		$this->assert_notify_updates_all_subscriptions();
	}

	/**
	 * T-N1（HPOS 開啟）
	 *
	 * @group hpos
	 */
	public function test_T_N1_webhook成功_HPOS開啟(): void {
		$this->set_hpos( true );
		$this->assertTrue( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), '前提：HPOS 開啟' );
		$this->assert_notify_updates_all_subscriptions();
	}

	/**
	 * T-N2：webhook 回失敗 → 付款方式不變、pending 清掉、attempt failed、訂閱有備註
	 *
	 * @group error
	 */
	public function test_T_N2_webhook失敗不改付款方式並清pending(): void {
		$subscription = $this->create_v1_subscription();
		$mer          = $this->start_3d_attempt( $subscription );
		$notes_before = count( $this->note_contents( wcs_get_subscription( $subscription->get_id() ) ) );

		$this->deliver_notify(
			$mer,
			[
				'Status'     => 'CREDIT04001',
				'Message'    => '授權失敗',
				'CreditHash' => '',
			]
		);

		$fresh = wcs_get_subscription( $subscription->get_id() );
		$this->assertSame( self::V1_GATEWAY_ID, $fresh->get_payment_method(), '失敗不可改付款方式' );
		$this->assertEmpty( $fresh->get_meta( '_payuni_change_pending' ), 'pending 要清掉' );
		$this->assertStringContainsString( 'failed', (string) wp_json_encode( $fresh->get_meta( '_payuni_change_attempts' ) ), 'attempt 要標 failed' );
		$this->assertCount( 0, \WC_Payment_Tokens::get_customer_tokens( $this->customer_id ), '失敗不可建 token' );
		$this->assertGreaterThan( $notes_before, count( $this->note_contents( $fresh ) ), '訂閱要留下失敗備註' );
	}

	/**
	 * T-N3：偽造 — MerTradeNo 不在 attempts → 拒絕且沒有任何變更
	 *
	 * @group security
	 */
	public function test_T_N3_偽造_MerTradeNo不在attempts(): void {
		$this->assert_forgery_rejected( static fn( string $real ) => preg_replace( '/-C\d+$/', '-C99', $real ), [], [] );
	}

	/**
	 * T-N3：偽造 — TradeAmt 不是 5 → 拒絕且沒有任何變更
	 *
	 * @group security
	 */
	public function test_T_N3_偽造_金額不是5(): void {
		$this->assert_forgery_rejected( static fn( string $real ) => $real, [ 'TradeAmt' => '9450' ], [] );
	}

	/**
	 * T-N3：偽造 — 內層 MerID 不符 → 拒絕且沒有任何變更
	 *
	 * @group security
	 */
	public function test_T_N3_偽造_MerID不符(): void {
		$this->assert_forgery_rejected( static fn( string $real ) => $real, [ 'MerID' => 'EVIL_MERCHANT' ], [] );
	}

	/**
	 * T-N3：偽造 — {訂單ID}-C1 指到一般訂單（不是訂閱）→ 拒絕，訂單不被碰
	 *
	 * @group security
	 */
	public function test_T_N3_偽造_指到非訂閱訂單(): void {
		$order = wc_create_order();
		$order->set_customer_id( $this->customer_id );
		$order->set_payment_method( self::GATEWAY_ID );
		$order->set_total( 5 );
		$order->set_status( 'pending' );
		$order->save();

		$this->assert_forgery_rejected( static fn( string $real ) => $order->get_id() . '-C1', [], [] );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $fresh->get_status(), '一般訂單不可被換卡通知改狀態' );
		$this->assertFalse( $fresh->is_paid() );
	}

	/**
	 * T-N4：webhook 收到訂閱物件時絕不呼叫 payment_complete，pending 的續訂單維持 pending
	 *
	 * @group security
	 */
	public function test_T_N4_webhook不會把續訂單標成已付款(): void {
		$subscription = $this->create_v1_subscription();
		$renewal      = \Woomp_Test_Wcs_Fixture::create_renewal_order( $subscription, $this->customer_id, 'pending' );
		$mer          = $this->start_3d_attempt( $subscription );

		$completions = 0;
		$counter     = static function () use ( &$completions ) {
			++$completions;
		};
		add_action( 'woocommerce_payment_complete', $counter );
		add_action( 'woocommerce_subscription_payment_complete', $counter );

		$response = $this->deliver_notify( $mer );

		$this->assertTrue( (bool) ( $response['success'] ?? false ), '前提：換卡通知處理成功' );
		$fresh = wcs_get_subscription( $subscription->get_id() );
		$this->assertSame( self::GATEWAY_ID, $fresh->get_payment_method(), '前提：換卡流程確實跑完' );
		$this->assertSame( 0, $completions, '訂閱物件絕不可呼叫 payment_complete' );
		$this->assertSame( 'active', $fresh->get_status(), '訂閱維持 active' );
		$renewal_fresh = wc_get_order( $renewal->get_id() );
		$this->assertSame( 'pending', $renewal_fresh->get_status(), '續訂單必須維持 pending' );
		$this->assertFalse( $renewal_fresh->is_paid() );
	}

	/**
	 * T-N5：舊 attempt 回 SUCCESS → 只排程取消 5 元，不覆蓋最新一次的綁定
	 *
	 * @group edge
	 */
	public function test_T_N5_舊attempt成功只取消5元不綁定(): void {
		$subscription = $this->create_v1_subscription();
		$old          = $this->start_3d_attempt( $subscription );
		$new          = $this->start_3d_attempt( $subscription );
		$this->assertNotSame( $old, $new, '前提：兩次嘗試編號不同' );

		$response = $this->deliver_notify(
			$old,
			[
				'TradeNo'    => 'TN_OLD_ATTEMPT',
				'CreditHash' => 'OLD_HASH',
			]
		);

		$this->assertTrue( (bool) ( $response['success'] ?? false ), '舊 attempt 的合法成功通知要回 success 讓 PayUni 停止重送' );
		$this->assertSame( 1, $this->count_cancel_actions( 'TN_OLD_ATTEMPT' ), '舊 attempt 的 5 元仍要排程取消' );
		$fresh = wcs_get_subscription( $subscription->get_id() );
		$this->assertSame( self::V1_GATEWAY_ID, $fresh->get_payment_method(), '不可被舊 attempt 綁定' );
		$this->assertNull( $this->find_token_by_hash( 'OLD_HASH', self::GATEWAY_ID ), '不可存舊 attempt 的 token' );
		$this->assertStringContainsString( $new, (string) wp_json_encode( $fresh->get_meta( '_payuni_change_pending' ) ), '最新一次的 pending 要保留' );
	}

	/**
	 * T-N5：重複通知 → 只有一張 token、只排一次取消
	 *
	 * @group edge
	 */
	public function test_T_N5_重複通知只有一張token且只排一次取消(): void {
		$subscription = $this->create_v1_subscription();
		$mer          = $this->start_3d_attempt( $subscription );
		$inner        = [
			'TradeNo'    => 'TN_DUP_NOTIFY',
			'CreditHash' => 'DUP_HASH',
		];

		$first  = $this->deliver_notify( $mer, $inner );
		$second = $this->deliver_notify( $mer, $inner );

		$this->assertTrue( (bool) ( $first['success'] ?? false ) );
		$this->assertTrue( (bool) ( $second['success'] ?? false ), '重複通知回 success' );
		$this->assertCount( 1, \WC_Payment_Tokens::get_customer_tokens( $this->customer_id, self::GATEWAY_ID ), '只能有一張 token' );
		$this->assertSame( 1, $this->count_cancel_actions( 'TN_DUP_NOTIFY' ), '取消只排一次' );
		$this->assertSame( self::GATEWAY_ID, wcs_get_subscription( $subscription->get_id() )->get_payment_method() );
	}

	// ──────────────────────────────────────────────
	// 第二階段：續訂單複製 meta T-M1
	// ──────────────────────────────────────────────

	/**
	 * T-M1：建立續訂單時，換卡暫存 meta 不可被複製，_payuni_token_id 要保留
	 *
	 * @group edge
	 */
	public function test_T_M1_續訂單不含換卡暫存meta但保留token_id(): void {
		$subscription = $this->create_v1_subscription();
		$token        = $this->create_token( $this->customer_id, 'M1_HASH', self::GATEWAY_ID );
		$subscription->update_meta_data( '_payuni_token_id', (string) $token->get_id() );
		$subscription->update_meta_data( '_payuni_change_seq', '3' );
		$subscription->update_meta_data( '_payuni_change_attempts', [ $subscription->get_id() . '-C1' => 'failed' ] );
		$subscription->update_meta_data( '_payuni_change_pending', [ 'mer_trade_no' => $subscription->get_id() . '-C3' ] );
		$subscription->update_meta_data( '_payuni_mer_trade_no', 'OLD_MER_TRADE_NO' );
		$subscription->save();

		$renewal = wcs_create_renewal_order( wcs_get_subscription( $subscription->get_id() ) );
		$this->assertNotWPError( $renewal );
		$renewal = wc_get_order( $renewal->get_id() );

		$leaked = [];
		foreach ( $renewal->get_meta_data() as $meta ) {
			$key = $meta->key;
			if ( 0 === strpos( $key, '_payuni_change_' ) || '_payuni_mer_trade_no' === $key ) {
				$leaked[] = $key;
			}
		}
		$this->assertSame( [], array_values( array_unique( $leaked ) ), '續訂單不可帶到換卡暫存 meta' );
		$this->assertSame( (string) $token->get_id(), (string) $renewal->get_meta( '_payuni_token_id' ), '_payuni_token_id 要保留（續扣取卡用）' );
	}

	// ──────────────────────────────────────────────
	// Helper：換卡情境
	// ──────────────────────────────────────────────

	/**
	 * 攔截所有外送 HTTP；PayUni 請求解密後交給 $this->payuni_handler 產生加密回應
	 *
	 * @param mixed  $pre  預設回應
	 * @param array  $args 請求參數
	 * @param string $url  網址
	 *
	 * @return mixed
	 */
	public function intercept_http( $pre, $args, $url ) {
		$params = [];
		$enc    = is_array( $args['body'] ?? null ) ? ( $args['body']['EncryptInfo'] ?? '' ) : '';
		if ( is_string( $enc ) && '' !== $enc ) {
			$params = \J7\Payuni\Shared\Utils\EncryptUtils::decrypt( $enc );
		}

		$this->requests[] = [
			'url'    => (string) $url,
			'args'   => $args,
			'params' => $params,
		];

		if ( [] === $params ) {
			return [
				'headers'  => [],
				'body'     => '{}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
				'filename' => null,
			];
		}

		$payload = ( $this->payuni_handler )( $params, (string) $url );
		if ( $payload instanceof \WP_Error ) {
			return $payload;
		}

		return $this->encrypted_response( $payload );
	}

	/**
	 * 把內層 payload 包成 PayUni 回應（真正的 AES-256-GCM + HashInfo）
	 *
	 * @param array $payload 內層欄位
	 *
	 * @return array wp_remote_post 回應
	 */
	private function encrypted_response( array $payload ): array {
		$encrypt_info = \J7\Payuni\Shared\Utils\EncryptUtils::encrypt( $payload );

		return [
			'headers'  => [],
			'body'     => wp_json_encode(
				[
					'Status'      => 'SUCCESS',
					'MerID'       => $this->merchant_id(),
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
	}

	/**
	 * 目前設定的商店代號
	 *
	 * @return string
	 */
	private function merchant_id(): string {
		return \J7\Payuni\Contracts\DTOs\SettingDTO::instance()->merchant_id;
	}

	/**
	 * PayUni 要求建立 3D 驗證的回應（沒有 TradeNo、只有 URL）
	 *
	 * @param array $params 請求參數
	 *
	 * @return array
	 */
	private function three_d_payload( array $params ): array {
		return [
			'Status'     => 'SUCCESS',
			'Message'    => '建立幕後3D成功',
			'MerID'      => $this->merchant_id(),
			'MerTradeNo' => (string) ( $params['MerTradeNo'] ?? '' ),
			'URL'        => self::THREE_D_URL,
		];
	}

	/**
	 * PayUni 授權成功的回應（無 3D）
	 *
	 * @param array  $params      請求參數
	 * @param string $credit_hash CreditHash；空字串表示不回傳
	 *
	 * @return array
	 */
	private function success_payload( array $params, string $credit_hash = 'NEW_CREDIT_HASH' ): array {
		$mer     = (string) ( $params['MerTradeNo'] ?? '' );
		$payload = [
			'Status'     => 'SUCCESS',
			'Message'    => '授權成功',
			'MerID'      => $this->merchant_id(),
			'MerTradeNo' => $mer,
			'TradeNo'    => 'TN_' . $mer,
			'TradeAmt'   => '5',
			'Card6No'    => '411111',
			'Card4No'    => '4242',
			'CreditLife' => '1230',
		];
		if ( '' !== $credit_hash ) {
			$payload['CreditHash'] = $credit_hash;
		}

		return $payload;
	}

	/**
	 * PayUni 拒絕授權的回應（沒有 TradeNo 也沒有 URL）
	 *
	 * @param array $params 請求參數
	 *
	 * @return array
	 */
	private function decline_payload( array $params ): array {
		return [
			'Status'     => 'CREDIT04001',
			'Message'    => '授權失敗',
			'MerID'      => $this->merchant_id(),
			'MerTradeNo' => (string) ( $params['MerTradeNo'] ?? '' ),
		];
	}

	/**
	 * 攔截到的 merchant_trade 請求
	 *
	 * @return array<int, array{url: string, args: array, params: array}>
	 */
	private function trade_requests(): array {
		return array_values(
			array_filter(
				$this->requests,
				static fn( $r ) => false !== strpos( $r['url'], 'merchant_trade' )
			)
		);
	}

	/**
	 * 記錄被 Woomp_Payment_Callback_Guard::reject() 拒絕的原因
	 *
	 * @param string $provider 金流代號
	 * @param string $reason   原因
	 * @param array  $context  context
	 *
	 * @return void
	 */
	public function record_rejection( $provider, $reason, $context = [] ): void {
		$this->rejections[] = [ (string) $reason, $provider . ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) ];
	}

	/**
	 * 記錄 woomp_payuni_log 的 error
	 *
	 * @param string $level   等級
	 * @param string $message 訊息
	 * @param mixed  $context context
	 *
	 * @return void
	 */
	public function record_log( $level, $message, $context = [] ): void {
		if ( 'error' === $level ) {
			$this->error_logs[] = (string) $message;
		}
	}

	/**
	 * 是否有「來自換卡分支」的拒絕紀錄（rejection／error log 含 payment_method_change 或「換卡」）
	 *
	 * 現行 handle_notify 對 `{ID}-C{n}` 只會丟「找不到訂單或 MerTradeNo 不符」，不算換卡分支。
	 *
	 * @return bool
	 */
	private function rejected_by_change_branch(): bool {
		$haystacks = $this->error_logs;
		foreach ( $this->rejections as $rejection ) {
			$haystacks[] = $rejection[0] . ' ' . $rejection[1];
		}
		foreach ( $haystacks as $text ) {
			if ( false !== strpos( $text, 'payment_method_change' ) || false !== strpos( $text, '換卡' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 重置 SettingDTO 單例（改 option 後必須重讀）
	 *
	 * @return void
	 */
	private function reset_setting_dto(): void {
		$ref  = new \ReflectionClass( \J7\Payuni\Contracts\DTOs\SettingDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * 換卡 API 尚未實作就 fail，避免誤走舊的扣款路徑
	 *
	 * @return void
	 */
	private function require_change_api(): void {
		if ( ! class_exists( self::CHANGE_CLASS ) ) {
			$this->fail( '尚未實作: ' . self::CHANGE_CLASS );
		}
		foreach ( [ 'is_change_request', 'start', 'complete', 'fail', 'match_notify', 'handle_notify' ] as $method ) {
			if ( ! method_exists( self::CHANGE_CLASS, $method ) ) {
				$this->fail( '尚未實作: ' . self::CHANGE_CLASS . '::' . $method . '()' );
			}
		}
	}

	/**
	 * WCS 未載入就跳過
	 *
	 * @return void
	 */
	private function require_wcs(): void {
		if ( ! class_exists( 'WC_Subscription' ) || ! function_exists( 'wcs_create_subscription' ) ) {
			$this->markTestSkipped( 'WooCommerce Subscriptions 未載入' );
		}
	}

	/**
	 * 建立付款方式為 V1 的 active 訂閱（total 9450、有下次扣款日）
	 *
	 * @return \WC_Subscription
	 */
	private function create_v1_subscription(): \WC_Subscription {
		$this->require_wcs();

		$subscription = wcs_create_subscription(
			[
				'customer_id'      => $this->customer_id,
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'status'           => 'active',
			]
		);
		$this->assertNotWPError( $subscription );
		$subscription->set_payment_method( self::V1_GATEWAY_ID );
		$subscription->set_total( 9450 );
		$subscription->set_billing_email( 'test@example.com' );
		$subscription->save();
		$subscription->update_dates( [ 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ] );

		return wcs_get_subscription( $subscription->get_id() );
	}

	/**
	 * 取得 V3 定期定額閘道（走 WC 的閘道註冊，而非 new）
	 *
	 * @return \WC_Payment_Gateway
	 */
	private function get_v3_gateway() {
		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$gateways = WC()->payment_gateways()->payment_gateways();
		error_reporting( $previous );

		if ( ! isset( $gateways[ self::GATEWAY_ID ] ) ) {
			$this->fail( 'V3 定期定額閘道沒有註冊到 WC' );
		}

		return $gateways[ self::GATEWAY_ID ];
	}

	/**
	 * 模擬 WCS 換卡頁的 POST（nonce 已由 WCS 驗過，這裡不重做）
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 * @param string           $sdk_token    前端 SDK token；空字串表示沒送
	 *
	 * @return void
	 */
	private function prepare_change_request( \WC_Subscription $subscription, string $sdk_token ): void {
		wp_set_current_user( (int) $subscription->get_customer_id() );
		\WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment = true;

		$_GET['change_payment_method']     = (string) $subscription->get_id();
		$_GET['key']                       = $subscription->get_order_key();
		$_POST['payment_method']           = self::GATEWAY_ID;
		$_POST['woocommerce_change_payment'] = (string) $subscription->get_id();
		if ( '' !== $sdk_token ) {
			$_POST['sdk_token_tmp'] = $sdk_token;
		} else {
			unset( $_POST['sdk_token_tmp'] );
		}
		$_REQUEST = array_merge( $_GET, $_POST );
	}

	/**
	 * 走真實入口 CreditSubscriptionV3::process_payment( 訂閱ID )
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 * @param string           $sdk_token    SDK token
	 *
	 * @return array process_payment 回傳
	 */
	private function submit_change( \WC_Subscription $subscription, string $sdk_token = self::SDK_TOKEN ): array {
		$this->require_change_api();
		$this->prepare_change_request( $subscription, $sdk_token );

		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		try {
			return (array) $this->get_v3_gateway()->process_payment( $subscription->get_id() );
		} finally {
			error_reporting( $previous );
		}
	}

	/**
	 * 啟動一次 3D 換卡嘗試，回傳這次的 MerTradeNo
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 *
	 * @return string
	 */
	private function start_3d_attempt( \WC_Subscription $subscription ): string {
		$this->payuni_handler = fn( array $p ) => $this->three_d_payload( $p );
		$result               = $this->submit_change( $subscription );
		$this->assertSame( 'success', $result['result'] ?? '', '前提：3D 換卡啟動成功' );

		$trades = $this->trade_requests();
		$last   = end( $trades );

		return (string) ( $last['params']['MerTradeNo'] ?? '' );
	}

	/**
	 * 組加密的背景通知送進 Bootstrap::handle_notify()，回傳 JSON 回應
	 *
	 * @param string $mer_trade_no MerTradeNo
	 * @param array  $inner        覆蓋內層欄位
	 * @param array  $outer        覆蓋外層欄位
	 *
	 * @return array{success?: bool}
	 */
	private function deliver_notify( string $mer_trade_no, array $inner = [], array $outer = [] ): array {
		$payload      = array_merge(
			[
				'Status'      => 'SUCCESS',
				'Message'     => '授權成功',
				'MerID'       => $this->merchant_id(),
				'MerTradeNo'  => $mer_trade_no,
				'Gateway'     => '1',
				'TradeNo'     => 'TN_' . $mer_trade_no,
				'TradeAmt'    => '5',
				'TradeStatus' => '1',
				'PaymentType' => '1',
				'Card6No'     => '411111',
				'Card4No'     => '4242',
				'CreditHash'  => 'NOTIFY_HASH',
				'CreditLife'  => '1230',
				'AuthType'    => '1',
			],
			$inner
		);
		$encrypt_info = \J7\Payuni\Shared\Utils\EncryptUtils::encrypt( $payload );
		$_POST        = array_merge(
			[
				'Status'      => 'SUCCESS',
				'MerID'       => $this->merchant_id(),
				'Version'     => '1.0',
				'EncryptInfo' => $encrypt_info,
				'HashInfo'    => \J7\Payuni\Shared\Utils\EncryptUtils::hash_info( $encrypt_info ),
			],
			$outer
		);
		$_REQUEST     = $_POST;

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function () {
					throw new PaymentMethodChangeHalt( 'wp_die' );
				};
			}
		);

		ob_start();
		try {
			\J7\Payuni\Bootstrap::handle_notify();
		} catch ( PaymentMethodChangeHalt $halt ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// 預期中止。
		} finally {
			$output = (string) ob_get_clean();
		}

		$decoded = json_decode( $output, true );

		return is_array( $decoded ) ? $decoded : [ 'raw' => $output ];
	}

	/**
	 * 依 hash 與閘道找客戶的 token
	 *
	 * @param string $hash       token 值
	 * @param string $gateway_id 閘道
	 *
	 * @return \WC_Payment_Token|null
	 */
	private function find_token_by_hash( string $hash, string $gateway_id ): ?\WC_Payment_Token {
		foreach ( \WC_Payment_Tokens::get_customer_tokens( $this->customer_id, $gateway_id ) as $token ) {
			if ( $hash === $token->get_token() ) {
				return $token;
			}
		}

		return null;
	}

	/**
	 * 訂閱備註內容
	 *
	 * @param \WC_Order $order 訂閱
	 *
	 * @return string[]
	 */
	private function note_contents( \WC_Order $order ): array {
		return array_map(
			static fn( $note ) => (string) $note->content,
			(array) wc_get_order_notes( [ 'order_id' => $order->get_id() ] )
		);
	}

	/**
	 * 等待中的「取消 5 元授權」排程數量
	 *
	 * @param string $trade_no PayUni 交易編號
	 *
	 * @return int
	 */
	private function count_cancel_actions( string $trade_no ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		return count(
			as_get_scheduled_actions(
				[
					'hook'     => self::CANCEL_HOOK,
					'args'     => [ $trade_no ],
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				],
				'ids'
			)
		);
	}

	/**
	 * 切換 HPOS（訂閱存在 wc_orders 或 posts）
	 *
	 * @param bool $enabled 是否啟用
	 *
	 * @return void
	 */
	private function set_hpos( bool $enabled ): void {
		// 狀態相同就不要動：WC 在 option 更新前會檢查「有沒有未同步的訂單」，已有訂單時改值會丟例外
		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() === $enabled ) {
			return;
		}

		$option             = \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION;
		$this->hpos_toggled = true;
		if ( $enabled ) {
			// 開啟必須在建立任何訂單之前呼叫（此時沒有未同步的訂單）
			update_option( $option, 'yes' );
			// WCS 的「客戶訂閱 ID 快取」在開機時依 HPOS 狀態選定監聽的資料表，測試中途切 HPOS 不會跟著換，
			// 會一直回空陣列。正式站開機即為 HPOS 不受影響；此處改走直接查詢。
			add_filter( 'wcs_get_cached_users_subscription_ids', '__return_empty_string' );
		} else {
			// delete_option 不會觸發 WC 的「未同步訂單」檢查，tearDown 也靠它還原
			delete_option( $option );
		}
	}

	/**
	 * T-C3 的共用斷言：同步成功後的完整狀態
	 *
	 * @return void
	 */
	private function assert_sync_success_binds_v3(): void {
		$subscription         = $this->create_v1_subscription();
		$this->payuni_handler = fn( array $p ) => $this->success_payload( $p, 'SYNC_HASH' );

		$result = $this->submit_change( $subscription );

		$this->assertSame( 'success', $result['result'] ?? '' );
		$this->assertSame( $subscription->get_view_order_url(), $result['redirect'] ?? '', '成功後導回訂閱檢視頁' );

		$mer      = (string) $this->trade_requests()[0]['params']['MerTradeNo'];
		$trade_no = 'TN_' . $mer;
		$fresh    = wcs_get_subscription( $subscription->get_id() );
		$token    = $this->find_token_by_hash( 'SYNC_HASH', self::GATEWAY_ID );

		$this->assertSame( self::GATEWAY_ID, $fresh->get_payment_method(), '付款方式要改成 V3' );
		$this->assertNotNull( $token, '要建立新的 V3 token' );
		$this->assertSame( (string) $token->get_id(), (string) $fresh->get_meta( '_payuni_token_id' ), '_payuni_token_id 指向新 token' );
		$default = \WC_Payment_Tokens::get_customer_default_token( $this->customer_id );
		$this->assertNotNull( $default, '新 token 要設為預設' );
		$this->assertSame( $token->get_id(), $default->get_id() );
		$this->assertSame( 1, $this->count_cancel_actions( $trade_no ), '要排程取消 5 元授權' );
		$this->assertSame( 'active', $fresh->get_status(), '訂閱維持 active' );
	}

	/**
	 * T-N1 的共用斷言：3D 完成後 webhook 綁定，並同步更新同客戶另一筆訂閱
	 *
	 * @return void
	 */
	private function assert_notify_updates_all_subscriptions(): void {
		$subscription_a = $this->create_v1_subscription();
		$subscription_b = $this->create_v1_subscription();
		$mer            = $this->start_3d_attempt( $subscription_a );

		// WCS 在客戶勾「同時更新所有訂閱」且閘道延後更新時，會寫下這個旗標
		$a = wcs_get_subscription( $subscription_a->get_id() );
		$a->update_meta_data( '_delayed_update_payment_method_all', self::GATEWAY_ID );
		$a->save();

		$response = $this->deliver_notify( $mer, [ 'CreditHash' => 'N1_HASH' ] );

		$this->assertTrue( (bool) ( $response['success'] ?? false ), 'webhook 應回 success：' . wp_json_encode( $response ) );
		$token = $this->find_token_by_hash( 'N1_HASH', self::GATEWAY_ID );
		$this->assertNotNull( $token, '要建立新的 V3 token' );

		$a = wcs_get_subscription( $subscription_a->get_id() );
		$b = wcs_get_subscription( $subscription_b->get_id() );
		$this->assertSame( self::GATEWAY_ID, $a->get_payment_method(), '換卡的訂閱要改成 V3' );
		$this->assertSame( (string) $token->get_id(), (string) $a->get_meta( '_payuni_token_id' ) );
		$this->assertEmpty( $a->get_meta( '_payuni_change_pending' ), 'pending 要清掉' );
		$this->assertEmpty( $a->get_meta( '_delayed_update_payment_method_all' ), '延後旗標要被 WCS 清掉' );
		$this->assertSame( self::GATEWAY_ID, $b->get_payment_method(), '同客戶另一筆 active 訂閱也要切到 V3' );
		$this->assertSame( (string) $token->get_id(), (string) $b->get_meta( '_payuni_token_id' ), '另一筆訂閱要帶上同一張 token' );
		$this->assertSame( 1, $this->count_cancel_actions( 'TN_' . $mer ), '要排程取消 5 元授權' );
	}

	/**
	 * T-N3 的共用斷言：偽造通知被換卡分支拒絕，且沒有任何變更
	 *
	 * @param callable $pick  fn( string $real_mer_trade_no ): string，決定實際送出的 MerTradeNo
	 * @param array    $inner 覆蓋內層欄位
	 * @param array    $outer 覆蓋外層欄位
	 *
	 * @return void
	 */
	private function assert_forgery_rejected( callable $pick, array $inner, array $outer ): void {
		$subscription = $this->create_v1_subscription();
		$real         = $this->start_3d_attempt( $subscription );
		$before       = wcs_get_subscription( $subscription->get_id() );
		$pending      = $before->get_meta( '_payuni_change_pending' );
		$attempts     = $before->get_meta( '_payuni_change_attempts' );
		$this->assertNotEmpty( $pending, '前提：真正的 3D 嘗試留下了 pending' );
		$this->rejections = [];
		$this->error_logs = [];

		$response = $this->deliver_notify( $pick( $real ), array_merge( [ 'TradeNo' => 'TN_FORGED' ], $inner ), $outer );

		$this->assertNotTrue( $response['success'] ?? null, '偽造通知不可回 success' );
		$fresh = wcs_get_subscription( $subscription->get_id() );
		$this->assertSame( self::V1_GATEWAY_ID, $fresh->get_payment_method(), '付款方式不可變' );
		$this->assertCount( 0, \WC_Payment_Tokens::get_customer_tokens( $this->customer_id ), '不可建立 token' );
		$this->assertSame( 0, $this->count_cancel_actions( 'TN_FORGED' ), '不可排任何取消' );
		$this->assertEquals( $pending, $fresh->get_meta( '_payuni_change_pending' ), 'pending 不可動' );
		$this->assertEquals( $attempts, $fresh->get_meta( '_payuni_change_attempts' ), 'attempts 不可動' );
		$this->assertTrue(
			$this->rejected_by_change_branch(),
			'拒絕必須來自換卡分支（rejection／error log 要含 payment_method_change 或「換卡」）；目前紀錄：'
			. wp_json_encode( [ $this->rejections, $this->error_logs ], JSON_UNESCAPED_UNICODE )
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

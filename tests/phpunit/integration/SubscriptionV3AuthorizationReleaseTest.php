<?php
/**
 * PayUni V3 綁卡 5 元授權的釋放（取消授權／退款）整合測試（issue #147 後續）
 *
 * 背景（sandbox 實測）：商店授權後約 3 秒就自動請款（trade/query `CloseStatus=2`），
 * 2 分鐘後排程呼叫 `/api/trade/cancel` 回 `CANCEL03001`「取消授權失敗，已存在請款成功紀錄」，
 * 5 元留在客戶帳上；log 只寫「未知錯誤」，看不到解密後的訊息。
 *
 * 規則：
 * - R1 先查（trade/query）再決定：未請款 → 取消授權；已請款 → 退款（trade/close CloseType=2，5 元）。
 * - R2 取消失敗且原因是「已請款」（查詢後才被請款的競態，或查詢失敗）→ 改走退款。
 * - R3 冪等：同一 TradeNo 不可退兩次；已取消／已退款的交易不再呼叫 API；同時執行只有一個會動作。
 * - R4 安全：查到的金額不是 5 元就不自動處理（排程參數只有 TradeNo，不可退錯交易）。
 * - R5 結果寫訂閱／訂單備註與 log；log 要有解密後的 PayUni 訊息。
 * - R6 請款處理中、結果不明 → 重排程，不盲目重送退款。
 *
 * 執行指令：
 * docker exec <woomp-tests-cli> sh -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --filter SubscriptionV3AuthorizationRelease'
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 綁卡授權釋放測試
 *
 * @group subscription
 * @group payuni
 */
final class SubscriptionV3AuthorizationReleaseTest extends WP_UnitTestCase {

	private const RELEASE_CLASS = \J7\Payuni\Domains\Subscription\AuthorizationRelease::class;
	private const HOOK          = 'payuni_cancel_zero_authorization';
	private const TRADE_NO      = 'TN_RELEASE_0001';

	/**
	 * 攔截到的 PayUni 請求
	 *
	 * @var array<int, array{url: string, params: array}>
	 */
	private array $requests = [];

	/**
	 * 各端點的回應：endpoint => callable( array $params ): array{outer: string, inner: array}|WP_Error
	 *
	 * @var array<string, callable>
	 */
	private array $responders = [];

	/**
	 * woomp_payuni_log 紀錄 [level, message, context JSON]
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	private array $logs = [];

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
		$this->reset_setting_dto();

		$this->requests   = [];
		$this->logs       = [];
		$this->responders = [
			'trade/query'  => fn() => $this->query_result( [ 'CloseStatus' => '9' ] ),
			'trade/cancel' => fn() => $this->ok( [ 'Message' => '取消授權成功' ] ),
			'trade/close'  => fn() => $this->ok( [ 'Message' => '退款成功', 'CloseType' => '2' ] ),
		];

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
		add_action( 'woomp_payuni_log', [ $this, 'record_log' ], 10, 3 );
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_action( 'woomp_payuni_log', [ $this, 'record_log' ], 10 );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK );
		}
		wp_clear_scheduled_hook( self::HOOK, [ self::TRADE_NO ] );
		$this->reset_setting_dto();

		parent::tearDown();
	}

	// ──────────────────────────────────────────────
	// R1：先查再決定
	// ──────────────────────────────────────────────

	/**
	 * R1：尚未請款 → 取消授權，不退款
	 *
	 * @group happy
	 */
	public function test_R1_未請款時取消授權且不退款(): void {
		$order = $this->create_order();
		$this->schedule( $order->get_id() );

		$outcome = $this->run_release();

		$this->assertSame( 'cancelled', $outcome );
		$this->assertSame( 1, $this->count_calls( 'trade/cancel' ), '要呼叫取消授權' );
		$this->assertSame( 0, $this->count_calls( 'trade/close' ), '未請款不可退款' );
		$this->assertSame( self::TRADE_NO, $this->calls( 'trade/cancel' )[0]['params']['TradeNo'] ?? '' );
		$this->assertNoteContains( $order, '已取消' );
	}

	/**
	 * R1：已請款（sandbox 約 3 秒自動請款）→ 不呼叫取消，直接退 5 元
	 *
	 * @group happy
	 */
	public function test_R1_已請款時改走退款5元(): void {
		$order                           = $this->create_order();
		$this->responders['trade/query'] = fn() => $this->query_result( [ 'CloseStatus' => '2', 'CloseAmt' => '5' ] );
		$this->schedule( $order->get_id() );

		$outcome = $this->run_release();

		$this->assertSame( 'refunded', $outcome );
		$this->assertSame( 0, $this->count_calls( 'trade/cancel' ), '已請款不可再呼叫取消授權（必定 CANCEL03001）' );
		$this->assertSame( 1, $this->count_calls( 'trade/close' ), '要呼叫退款' );
		$params = $this->calls( 'trade/close' )[0]['params'];
		$this->assertSame( self::TRADE_NO, $params['TradeNo'] ?? '' );
		$this->assertSame( 2, (int) ( $params['CloseType'] ?? 0 ), '退款 CloseType=2' );
		$this->assertSame( 5, (int) ( $params['TradeAmt'] ?? 0 ), '只退 5 元' );
		$this->assertNoteContains( $order, '退款' );
	}

	/**
	 * R1：PayUni 已取消／已退款 → 不再呼叫任何異動 API
	 *
	 * @group edge
	 * @dataProvider provide_already_released
	 *
	 * @param array $fields 查詢結果欄位
	 */
	public function test_R1_已取消或已退款不再呼叫api( array $fields ): void {
		$this->responders['trade/query'] = fn() => $this->query_result( $fields );
		$this->schedule( 0 );

		$outcome = $this->run_release();

		$this->assertSame( 'released', $outcome );
		$this->assertSame( 0, $this->count_calls( 'trade/cancel' ) );
		$this->assertSame( 0, $this->count_calls( 'trade/close' ) );
	}

	/**
	 * 已釋放的查詢結果
	 *
	 * @return array<string, array{0: array}>
	 */
	public function provide_already_released(): array {
		return [
			'交易已取消'  => [ [ 'TradeStatus' => '3', 'CloseStatus' => '9' ] ],
			'已退款成功' => [ [ 'CloseStatus' => '2', 'RefundStatus' => '2', 'RefundAmt' => '5' ] ],
			'退款處理中' => [ [ 'CloseStatus' => '2', 'RefundStatus' => '8' ] ],
		];
	}

	// ──────────────────────────────────────────────
	// R2：取消失敗（已請款）→ 退款
	// ──────────────────────────────────────────────

	/**
	 * R2：查詢時未請款、取消時已被請款（CANCEL03001）→ 改走退款，log 要有解密後的訊息
	 *
	 * @group error
	 */
	public function test_R2_取消回CANCEL03001時改走退款且log有解密訊息(): void {
		$order                            = $this->create_order();
		$this->responders['trade/cancel'] = fn() => $this->captured_error();
		$this->schedule( $order->get_id() );

		$outcome = $this->run_release();

		$this->assertSame( 'refunded', $outcome );
		$this->assertSame( 1, $this->count_calls( 'trade/cancel' ) );
		$this->assertSame( 1, $this->count_calls( 'trade/close' ), '取消失敗（已請款）要改走退款' );
		$this->assertLogContains( '已存在請款成功紀錄', '要記下解密後的 PayUni 訊息' );
		$this->assertLogNotContains( '未知錯誤' );
	}

	/**
	 * R2：查詢失敗時仍要能釋放：取消 → 已請款 → 退款
	 *
	 * @group error
	 */
	public function test_R2_查詢失敗時取消失敗仍會退款(): void {
		$this->responders['trade/query']  = fn() => new \WP_Error( 'http_request_failed', 'timeout' );
		$this->responders['trade/cancel'] = fn() => $this->captured_error();
		$this->schedule( 0 );

		$outcome = $this->run_release();

		$this->assertSame( 'refunded', $outcome );
		$this->assertSame( 1, $this->count_calls( 'trade/close' ) );
	}

	/**
	 * R2：HttpClient 對非 SUCCESS 的外層狀態要解密內層，例外訊息帶出 PayUni 的原因
	 *
	 * @group error
	 */
	public function test_R2_HttpClient例外訊息包含解密後的內層訊息(): void {
		$this->responders['trade/cancel'] = fn() => $this->captured_error();

		try {
			( new \J7\Payuni\Infrastructure\Http\HttpClient() )->cancel_trade( self::TRADE_NO );
			$this->fail( '取消失敗要丟例外' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'CANCEL03001', $e->getMessage() );
			$this->assertStringContainsString( '已存在請款成功紀錄', $e->getMessage() );
			$this->assertStringNotContainsString( '未知錯誤', $e->getMessage() );
		}
	}

	/**
	 * R2：外層 SUCCESS、內層不是 SUCCESS 也算失敗（不可當成取消成功）
	 *
	 * @group error
	 */
	public function test_R2_內層狀態失敗不算取消成功(): void {
		$this->responders['trade/cancel'] = fn() => [
			'outer' => 'SUCCESS',
			'inner' => [
				'Status'  => 'CANCEL03001',
				'Message' => '取消授權失敗，已存在請款成功紀錄',
			],
		];
		$this->schedule( 0 );

		$outcome = $this->run_release();

		$this->assertSame( 'refunded', $outcome, '內層失敗（已請款）要改走退款' );
	}

	// ──────────────────────────────────────────────
	// R3：冪等
	// ──────────────────────────────────────────────

	/**
	 * R3：同一 TradeNo 執行兩次只退一次；第二次不打任何 API
	 *
	 * @group edge
	 */
	public function test_R3_同一TradeNo只退一次(): void {
		$this->responders['trade/query'] = fn() => $this->query_result( [ 'CloseStatus' => '2' ] );
		$this->schedule( 0 );

		$this->assertSame( 'refunded', $this->run_release() );
		$before = count( $this->requests );

		$this->assertSame( 'refunded', $this->run_release(), '第二次要回報既有結果' );
		$this->assertSame( $before, count( $this->requests ), '已完成的交易不可再打 PayUni' );
		$this->assertSame( 1, $this->count_calls( 'trade/close' ) );
	}

	/**
	 * R3：另一個程序正在處理（未逾時）→ 本次不動作
	 *
	 * @group edge
	 */
	public function test_R3_處理中的鎖未逾時時不動作(): void {
		$this->schedule( 0 );
		$this->force_state(
			[
				'status' => 'processing',
				'time'   => time(),
			]
		);

		$this->assertSame( 'busy', $this->run_release() );
		$this->assertCount( 0, $this->requests, '別的程序處理中不可打 PayUni' );
	}

	/**
	 * R3：處理中的鎖已逾時（上一個程序中途死掉）→ 可以接手
	 *
	 * @group edge
	 */
	public function test_R3_處理中的鎖逾時後可接手(): void {
		$this->schedule( 0 );
		$this->force_state(
			[
				'status' => 'processing',
				'time'   => time() - HOUR_IN_SECONDS,
			]
		);

		$this->assertSame( 'cancelled', $this->run_release() );
	}

	/**
	 * R3：退款請求結果不明（逾時）→ 重排程；之後查詢失敗也不可盲目再退一次
	 *
	 * @group error
	 */
	public function test_R3_退款結果不明時不重送退款(): void {
		$this->responders['trade/query'] = fn() => $this->query_result( [ 'CloseStatus' => '2' ] );
		$this->responders['trade/close'] = fn() => new \WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
		$this->schedule( 0 );

		$this->assertSame( 'retry', $this->run_release() );
		$this->assertSame( 1, $this->count_calls( 'trade/close' ) );
		$this->assertTrue( $this->has_scheduled_retry(), '結果不明要重排程' );

		// 第二次：查詢失敗 → 只能等，不可再送退款
		$this->responders['trade/query']  = fn() => new \WP_Error( 'http_request_failed', 'timeout' );
		$this->responders['trade/cancel'] = fn() => $this->captured_error();
		$this->assertSame( 'retry', $this->run_release() );
		$this->assertSame( 1, $this->count_calls( 'trade/close' ), '退款結果不明時不可再送一次退款' );

		// 第三次：查到已退款 → 結束
		$this->responders['trade/query'] = fn() => $this->query_result( [ 'CloseStatus' => '2', 'RefundStatus' => '2' ] );
		$this->assertSame( 'released', $this->run_release() );
		$this->assertSame( 1, $this->count_calls( 'trade/close' ) );
	}

	/**
	 * R3：PayUni 明確拒絕退款 → 可重試（沒有退成）
	 *
	 * @group error
	 */
	public function test_R3_退款被明確拒絕時重排程(): void {
		$this->responders['trade/query'] = fn() => $this->query_result( [ 'CloseStatus' => '2' ] );
		$this->responders['trade/close'] = fn() => [
			'outer' => 'CLOSE03001',
			'inner' => [
				'Status'  => 'CLOSE03001',
				'Message' => '退款失敗',
			],
		];
		$this->schedule( 0 );

		$this->assertSame( 'retry', $this->run_release() );

		$this->responders['trade/close'] = fn() => $this->ok( [ 'Message' => '退款成功' ] );
		$this->assertSame( 'refunded', $this->run_release(), '明確失敗不算「結果不明」，下次可以再退' );
		$this->assertSame( 2, $this->count_calls( 'trade/close' ) );
	}

	// ──────────────────────────────────────────────
	// R4：安全
	// ──────────────────────────────────────────────

	/**
	 * R4：查到的金額不是 5 元 → 不取消、不退款，寫 error 與備註
	 *
	 * @group security
	 */
	public function test_R4_金額不是5元不自動處理(): void {
		$order                           = $this->create_order();
		$this->responders['trade/query'] = fn() => $this->query_result( [ 'TradeAmt' => '9450', 'CloseStatus' => '2' ] );
		$this->schedule( $order->get_id() );

		$outcome = $this->run_release();

		$this->assertSame( 'manual', $outcome );
		$this->assertSame( 0, $this->count_calls( 'trade/cancel' ) );
		$this->assertSame( 0, $this->count_calls( 'trade/close' ) );
		$this->assertTrue( $this->has_log( 'error' ), '要寫 error log' );
		$this->assertNoteContains( $order, 'PayUni 後台' );
	}

	// ──────────────────────────────────────────────
	// R6：請款處理中
	// ──────────────────────────────────────────────

	/**
	 * R6：請款申請中／處理中 → 取消與退款都不可行，重排程
	 *
	 * @group edge
	 */
	public function test_R6_請款處理中時重排程(): void {
		$this->responders['trade/query'] = fn() => $this->query_result( [ 'CloseStatus' => '7' ] );
		$this->schedule( 0 );

		$this->assertSame( 'retry', $this->run_release() );
		$this->assertSame( 0, $this->count_calls( 'trade/cancel' ) );
		$this->assertSame( 0, $this->count_calls( 'trade/close' ) );
		$this->assertTrue( $this->has_scheduled_retry() );
	}

	/**
	 * R6：重試達上限 → 停止並要求人工處理
	 *
	 * @group edge
	 */
	public function test_R6_重試達上限後轉人工(): void {
		$order                           = $this->create_order();
		$this->responders['trade/query'] = fn() => $this->query_result( [ 'CloseStatus' => '7' ] );
		$this->schedule( $order->get_id() );

		$outcome = '';
		for ( $i = 0; $i < 10 && 'manual' !== $outcome; $i++ ) {
			$outcome = $this->run_release();
		}

		$this->assertSame( 'manual', $outcome, '重試有上限' );
		$this->assertNoteContains( $order, 'PayUni 後台' );
	}

	// ──────────────────────────────────────────────
	// 排程
	// ──────────────────────────────────────────────

	/**
	 * 排程參數維持只有 TradeNo（相容已排入的舊排程），對象 ID 另存
	 *
	 * @group happy
	 */
	public function test_排程參數只有TradeNo且記下對象(): void {
		$order = $this->create_order();
		( new \J7\Payuni\Domains\Subscription\SubscriptionHandler() )->schedule_cancel_authorization( self::TRADE_NO, $order->get_id() );

		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$this->assertNotFalse( as_next_scheduled_action( self::HOOK, [ self::TRADE_NO ] ) );
		} else {
			$this->assertNotFalse( wp_next_scheduled( self::HOOK, [ self::TRADE_NO ] ) );
		}

		\J7\Payuni\Domains\Subscription\SubscriptionHandler::cancel_zero_authorization( self::TRADE_NO );
		$this->assertNoteContains( $order, '已取消' );
	}

	/**
	 * 舊排程（升級前排入、沒有對象 ID）仍能釋放
	 *
	 * @group edge
	 */
	public function test_舊排程沒有對象ID仍能釋放(): void {
		\J7\Payuni\Domains\Subscription\SubscriptionHandler::cancel_zero_authorization( self::TRADE_NO );

		$this->assertSame( 1, $this->count_calls( 'trade/cancel' ) );
	}

	// ──────────────────────────────────────────────
	// Helper
	// ──────────────────────────────────────────────

	/**
	 * 攔截 PayUni 請求
	 *
	 * @param mixed  $pre  預設回應
	 * @param array  $args 請求參數
	 * @param string $url  網址
	 *
	 * @return mixed
	 */
	public function intercept_http( $pre, $args, $url ) {
		$enc    = is_array( $args['body'] ?? null ) ? (string) ( $args['body']['EncryptInfo'] ?? '' ) : '';
		$params = '' !== $enc ? \J7\Payuni\Shared\Utils\EncryptUtils::decrypt( $enc ) : [];

		$this->requests[] = [
			'url'    => (string) $url,
			'params' => $params,
		];

		foreach ( $this->responders as $endpoint => $responder ) {
			if ( str_ends_with( (string) $url, '/' . $endpoint ) ) {
				$response = $responder( $params );
				if ( $response instanceof \WP_Error ) {
					return $response;
				}
				return $this->encrypted_response( $response['outer'], $response['inner'] );
			}
		}

		return new \WP_Error( 'unexpected', 'Unexpected request: ' . $url );
	}

	/**
	 * 記錄 woomp_payuni_log
	 *
	 * @param string $level   等級
	 * @param string $message 訊息
	 * @param mixed  $context context
	 *
	 * @return void
	 */
	public function record_log( $level, $message, $context = [] ): void {
		$this->logs[] = [ (string) $level, (string) $message, (string) wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) ];
	}

	/**
	 * 成功回應
	 *
	 * @param array $inner 內層欄位
	 *
	 * @return array{outer: string, inner: array}
	 */
	private function ok( array $inner ): array {
		return [
			'outer' => 'SUCCESS',
			'inner' => array_merge(
				[
					'Status'  => 'SUCCESS',
					'MerID'   => 'TEST_MERCHANT',
					'TradeNo' => self::TRADE_NO,
				],
				$inner
			),
		];
	}

	/**
	 * Sandbox 實測的「已請款，不可取消」回應：外層是錯誤碼、內層有訊息
	 *
	 * @return array{outer: string, inner: array}
	 */
	private function captured_error(): array {
		return [
			'outer' => 'CANCEL03001',
			'inner' => [
				'Status'  => 'CANCEL03001',
				'Message' => '取消授權失敗，已存在請款成功紀錄',
				'MerID'   => 'TEST_MERCHANT',
				'TradeNo' => self::TRADE_NO,
			],
		];
	}

	/**
	 * trade/query 回應（Result 陣列）
	 *
	 * @param array $fields 覆蓋欄位
	 *
	 * @return array{outer: string, inner: array}
	 */
	private function query_result( array $fields ): array {
		return $this->ok(
			[
				'Message' => '查詢成功',
				'Result'  => [
					array_merge(
						[
							'MerTradeNo'  => '123-C1',
							'TradeNo'     => self::TRADE_NO,
							'TradeAmt'    => '5',
							'TradeStatus' => '1',
							'PaymentType' => '1',
							'DataSource'  => 'A',
						],
						$fields
					),
				],
			]
		);
	}

	/**
	 * 包成 PayUni 回應（真正的 AES-256-GCM + HashInfo）
	 *
	 * @param string $outer_status 外層 Status
	 * @param array  $inner        內層欄位
	 *
	 * @return array
	 */
	private function encrypted_response( string $outer_status, array $inner ): array {
		$encrypt_info = \J7\Payuni\Shared\Utils\EncryptUtils::encrypt( $inner );

		return [
			'headers'  => [],
			'body'     => wp_json_encode(
				[
					'Status'      => $outer_status,
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
	}

	/**
	 * 排程釋放（記下對象 ID）
	 *
	 * @param int $object_id 訂單／訂閱 ID
	 *
	 * @return void
	 */
	private function schedule( int $object_id ): void {
		$this->require_release_api();
		\J7\Payuni\Domains\Subscription\AuthorizationRelease::schedule( self::TRADE_NO, $object_id );
	}

	/**
	 * 執行一次釋放
	 *
	 * @return string 結果
	 */
	private function run_release(): string {
		$this->require_release_api();

		return \J7\Payuni\Domains\Subscription\AuthorizationRelease::run( self::TRADE_NO );
	}

	/**
	 * 直接改狀態（模擬另一個程序）
	 *
	 * @param array $state 狀態
	 *
	 * @return void
	 */
	private function force_state( array $state ): void {
		$name = \J7\Payuni\Domains\Subscription\AuthorizationRelease::OPTION_PREFIX . self::TRADE_NO;
		$old  = json_decode( (string) get_option( $name, '{}' ), true );
		update_option( $name, (string) wp_json_encode( array_merge( is_array( $old ) ? $old : [], $state ) ), false );
	}

	/**
	 * 尚未實作就 fail
	 *
	 * @return void
	 */
	private function require_release_api(): void {
		if ( ! class_exists( self::RELEASE_CLASS ) ) {
			$this->fail( '尚未實作: ' . self::RELEASE_CLASS );
		}
	}

	/**
	 * 某端點的請求
	 *
	 * @param string $endpoint 端點
	 *
	 * @return array<int, array{url: string, params: array}>
	 */
	private function calls( string $endpoint ): array {
		return array_values( array_filter( $this->requests, static fn( $r ) => str_ends_with( $r['url'], '/' . $endpoint ) ) );
	}

	/**
	 * 某端點的請求數
	 *
	 * @param string $endpoint 端點
	 *
	 * @return int
	 */
	private function count_calls( string $endpoint ): int {
		return count( $this->calls( $endpoint ) );
	}

	/**
	 * 是否有等待中的重試排程
	 *
	 * @return bool
	 */
	private function has_scheduled_retry(): bool {
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$ids = as_get_scheduled_actions(
				[
					'hook'     => self::HOOK,
					'args'     => [ self::TRADE_NO ],
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				],
				'ids'
			);
			return count( $ids ) >= 1;
		}

		return false !== wp_next_scheduled( self::HOOK, [ self::TRADE_NO ] );
	}

	/**
	 * 是否有某等級的 log
	 *
	 * @param string $level 等級
	 *
	 * @return bool
	 */
	private function has_log( string $level ): bool {
		foreach ( $this->logs as $log ) {
			if ( $level === $log[0] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * log（訊息 + context）含某字串
	 *
	 * @param string $needle  字串
	 * @param string $message 失敗訊息
	 *
	 * @return void
	 */
	private function assertLogContains( string $needle, string $message = '' ): void {
		foreach ( $this->logs as $log ) {
			if ( false !== strpos( $log[1] . ' ' . $log[2], $needle ) ) {
				$this->assertTrue( true );
				return;
			}
		}
		$this->fail( $message . '；log：' . wp_json_encode( $this->logs, JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * log 不含某字串
	 *
	 * @param string $needle 字串
	 *
	 * @return void
	 */
	private function assertLogNotContains( string $needle ): void {
		foreach ( $this->logs as $log ) {
			$this->assertStringNotContainsString( $needle, $log[1] . ' ' . $log[2] );
		}
	}

	/**
	 * 訂單備註含某字串
	 *
	 * @param \WC_Order $order  訂單
	 * @param string    $needle 字串
	 *
	 * @return void
	 */
	private function assertNoteContains( \WC_Order $order, string $needle ): void {
		$notes = array_map( static fn( $n ) => (string) $n->content, (array) wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
		foreach ( $notes as $note ) {
			if ( false !== strpos( $note, $needle ) ) {
				$this->assertTrue( true );
				return;
			}
		}
		$this->fail( "備註要含「{$needle}」；目前：" . wp_json_encode( $notes, JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * 建立零元首購訂單
	 *
	 * @return \WC_Order
	 */
	private function create_order(): \WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( 'payuni-credit-subscription-v3' );
		$order->set_total( 0 );
		$order->save();

		return $order;
	}

	/**
	 * 重置 SettingDTO 單例
	 *
	 * @return void
	 */
	private function reset_setting_dto(): void {
		$ref  = new \ReflectionClass( \J7\Payuni\Contracts\DTOs\SettingDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}
}

<?php
/**
 * PayUni V3 定期定額「信用卡 Token 綁定與續扣」回歸測試
 *
 * 對應 GitHub issue #130：正式站訂閱續扣全數失敗。
 * 根因有三層，本測試逐一鎖住，避免再次回歸：
 *
 * 1. UseTokenType 用了 2（記憶卡號），PayUni 授權成功卻不壓 CreditHash；
 *    續扣需要的是可幕後扣款的「約定信用卡」，必須送 3。
 * 2. 綁卡沒拿到 CreditHash 時，以卡號組合（Card6No****Card4No）當替身存進
 *    WC_Payment_Tokens，把綁卡失敗偽裝成成功，直到扣款日才爆。
 * 3. 續扣打了 UNi Embed 的 /iframe/merchant_trade，該端點必須帶前端 SDK_TOKEN，
 *    背景排程沒有前端可帶，PayUni 必然回 IFTRADE02004「未有交易 Token」。
 *
 * @package Woomp\Tests\Integration
 */

require_once dirname( __DIR__ ) . '/support/WcsFixture.php';

/**
 * V3 定期定額 Token 綁定與續扣測試類別
 *
 * @covers \J7\Payuni\Domains\Subscription\SubscriptionHandler
 * @covers \J7\Payuni\Infrastructure\Http\TradeHandler
 * @group subscription
 * @group payuni
 */
final class SubscriptionV3TokenBindingTest extends WP_UnitTestCase {

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
	private array $captured_requests = [];

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

		$this->customer_id       = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$this->captured_requests = [];
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		$this->leave_checkout_context();

		foreach ( \WC_Payment_Tokens::get_customer_tokens( $this->customer_id ) as $token ) {
			\WC_Payment_Tokens::delete( $token->get_id() );
		}

		update_option( 'payuni_payment_testmode', 'yes' );
		$this->reset_setting_dto();

		parent::tearDown();
	}

	// ──────────────────────────────────────────────
	// 1. UseTokenType 必須是「強制約定信用卡」(3)
	// ──────────────────────────────────────────────

	/**
	 * 零元取 Token 必須送 UseTokenType=3（強制約定信用卡）
	 *
	 * 送 2（記憶卡號）時 PayUni 授權會成功但不回傳 CreditHash，
	 * 導致訂閱綁卡看似成功、續扣時才發現沒有可用的 Token。
	 */
	public function test_零元取_token_必須送強制約定信用卡(): void {
		$order = $this->create_test_order( 0 );
		$order->update_meta_data( 'sdk_token_tmp', 'DUMMY_SDK_TOKEN' );
		$order->save();

		$this->capture_http_requests();

		$handler = new \J7\Payuni\Domains\Subscription\SubscriptionHandler();
		$handler->process_zero_amount_token( $order );

		$request = $this->find_request_containing( 'merchant_trade' );
		$this->assertNotNull( $request, '零元取 Token 應呼叫 merchant_trade 端點' );

		$params = $this->decrypt_request_params( $request );
		$this->assertSame(
			\J7\Payuni\Shared\Enums\EUseTokenType::FORCE_BIND->value,
			(int) ( $params['UseTokenType'] ?? 0 ),
			'訂閱綁卡必須用強制約定信用卡（3），否則 PayUni 不會壓 CreditHash'
		);
	}

	/**
	 * EUseTokenType 的數值必須與 PayUni 官方文件一致
	 *
	 * 前端 constants.module.js 的 TOKEN_TYPE 與此 enum 共用同一組數值，
	 * 任一邊改動都會讓 token_get 與 getTradeResult 不一致，PayUni 即不執行綁定。
	 *
	 * @see https://docs.payuni.com.tw/web/#/7/512
	 */
	public function test_use_token_type_列舉值符合官方定義(): void {
		$this->assertSame( 1, \J7\Payuni\Shared\Enums\EUseTokenType::OPTIONAL_BIND->value, '1=約定信用卡' );
		$this->assertSame( 2, \J7\Payuni\Shared\Enums\EUseTokenType::REMEMBER_CARD->value, '2=記憶卡號' );
		$this->assertSame( 3, \J7\Payuni\Shared\Enums\EUseTokenType::FORCE_BIND->value, '3=強制約定信用卡' );
	}

	/**
	 * 前端 TOKEN_TYPE 常數必須與後端 enum 同值
	 *
	 * 兩者不一致時 PayUni 不會執行綁定，症狀是「授權成功但沒有 CreditHash」，
	 * 極難從日誌看出，因此以測試鎖住。
	 */
	public function test_前端_token_type_常數與後端_enum_同值(): void {
		$js_path = dirname( __DIR__, 3 ) . '/includes/payuni/v3/Applications/assets/js/constants.module.js';
		$this->assertFileExists( $js_path );

		$js = file_get_contents( $js_path );

		$this->assertMatchesRegularExpression( '/AGREED_CARD:\s*1\b/', $js, '前端 1 應為約定信用卡' );
		$this->assertMatchesRegularExpression( '/REMEMBER_CARD:\s*2\b/', $js, '前端 2 應為記憶卡號' );
		$this->assertMatchesRegularExpression( '/FORCE_AGREED_CARD:\s*3\b/', $js, '前端 3 應為強制約定信用卡' );
	}

	// ──────────────────────────────────────────────
	// 2. 無 CreditHash 時不得存入假 Token
	// ──────────────────────────────────────────────

	/**
	 * 正式環境沒有 CreditHash 時不得儲存任何 Token
	 *
	 * 過去以 Card6No****Card4No 當替身，會讓續扣送出無效值、
	 * PayUni 回「約定信用卡不存在」，且綁卡失敗完全無聲。
	 */
	public function test_正式環境無_credit_hash_不儲存_token(): void {
		update_option( 'payuni_payment_testmode', 'no' );
		update_option( 'payuni_payment_merchant_no', 'PROD_MERCHANT' );
		update_option( 'payuni_payment_hash_key', 'PROD_HASH_KEY_1234567890123456' );
		update_option( 'payuni_payment_hash_iv', 'PROD_HASH_IV_1234567' );
		$this->reset_setting_dto();

		( new \J7\Payuni\Infrastructure\Http\TradeHandler() )->save_payment_token(
			$this->customer_id,
			'',
			'428430',
			'5217',
			'1230',
			'payuni-credit-subscription-v3'
		);

		$tokens = \WC_Payment_Tokens::get_customer_tokens( $this->customer_id );
		$this->assertCount( 0, $tokens, '正式環境沒有 CreditHash 就不該建立 Token' );
	}

	/**
	 * 有 CreditHash 時正常儲存
	 */
	public function test_有_credit_hash_時正常儲存_token(): void {
		( new \J7\Payuni\Infrastructure\Http\TradeHandler() )->save_payment_token(
			$this->customer_id,
			'REAL_CREDIT_HASH_VALUE',
			'428430',
			'5217',
			'1230',
			'payuni-credit-subscription-v3'
		);

		$tokens = \WC_Payment_Tokens::get_customer_tokens( $this->customer_id );
		$this->assertCount( 1, $tokens );
		$this->assertSame( 'REAL_CREDIT_HASH_VALUE', array_values( $tokens )[0]->get_token() );
	}

	/**
	 * Sandbox 模擬授權不回傳 CreditHash，仍保留卡號替身以便測試
	 */
	public function test_sandbox_無_credit_hash_仍以卡號替身儲存(): void {
		( new \J7\Payuni\Infrastructure\Http\TradeHandler() )->save_payment_token(
			$this->customer_id,
			'',
			'428430',
			'5217',
			'1230',
			'payuni-credit-subscription-v3'
		);

		$tokens = \WC_Payment_Tokens::get_customer_tokens( $this->customer_id );
		$this->assertCount( 1, $tokens, 'Sandbox 應保留替身 Token 以維持可測試性' );
		$this->assertSame( '428430****5217', array_values( $tokens )[0]->get_token() );
	}

	// ──────────────────────────────────────────────
	// 3. 續扣必須打 /api/credit，不可打 /iframe/merchant_trade
	// ──────────────────────────────────────────────

	/**
	 * 續扣必須打信用卡幕後端點，而非 UNi Embed 的 merchant_trade
	 *
	 * merchant_trade 需要前端 SDK_TOKEN（10 分鐘有效），背景排程無從取得，
	 * PayUni 必然回 IFTRADE02004「未有交易 Token」。
	 */
	public function test_續扣打信用卡幕後端點而非_merchant_trade(): void {
		$this->create_payment_token( 'RENEWAL_CREDIT_HASH' );

		$order = $this->create_test_order( 198 );
		$this->capture_http_requests();

		( new \J7\Payuni\Domains\Subscription\SubscriptionHandler() )
			->process_renewal_payment( 198.0, $order );

		$this->assertNotNull(
			$this->find_request_containing( '/api/credit' ),
			'續扣應呼叫 /api/credit 幕後授權端點'
		);
		$this->assertNull(
			$this->find_request_containing( 'iframe/merchant_trade' ),
			'續扣不得呼叫 UNi Embed 的 merchant_trade（缺 SDK_TOKEN 必回 IFTRADE02004）'
		);
	}

	/**
	 * 續扣送出的參數必須帶 CreditHash，且不得帶前端專用的 Token
	 */
	public function test_續扣參數帶_credit_hash_且不帶_sdk_token(): void {
		$this->create_payment_token( 'RENEWAL_CREDIT_HASH' );

		$order = $this->create_test_order( 198 );
		$this->capture_http_requests();

		( new \J7\Payuni\Domains\Subscription\SubscriptionHandler() )
			->process_renewal_payment( 198.0, $order );

		$request = $this->find_request_containing( '/api/credit' );
		$this->assertNotNull( $request );

		$params = $this->decrypt_request_params( $request );
		$this->assertSame( 'RENEWAL_CREDIT_HASH', $params['CreditHash'] ?? '', '續扣必須帶已綁定的 CreditHash' );
		$this->assertArrayNotHasKey( 'Token', $params, '續扣不應帶前端 SDK_TOKEN' );
	}

	// ──────────────────────────────────────────────
	// 4. issue #147 第一階段：token ID 回傳與訂閱綁定
	//
	// 執行指令：
	// docker exec <woomp-tests-cli> sh -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --filter "T_B" tests/phpunit/integration/SubscriptionV3TokenBindingTest.php'
	// ──────────────────────────────────────────────

	/**
	 * T-B1：新存一張卡時 save_payment_token 回傳 int token ID
	 *
	 * @group happy
	 */
	public function test_T_B1_新存卡片回傳token_id(): void {
		$id = ( new \J7\Payuni\Infrastructure\Http\TradeHandler() )->save_payment_token(
			$this->customer_id,
			'B1_NEW_HASH',
			'428430',
			'5217',
			'1230',
			'payuni-credit-subscription-v3'
		);

		$this->assertIsInt( $id, '新存卡片應回傳 int 型別的 token ID' );

		$tokens = \WC_Payment_Tokens::get_customer_tokens( $this->customer_id, 'payuni-credit-subscription-v3' );
		$this->assertCount( 1, $tokens );
		$this->assertSame( \array_values( $tokens )[0]->get_id(), $id, '回傳的 ID 必須是剛建立的 token' );
	}

	/**
	 * T-B1：卡片已存在時回傳既有 token ID，不重複建立
	 *
	 * @group edge
	 */
	public function test_T_B1_卡片已存在回傳既有token_id(): void {
		$handler = new \J7\Payuni\Infrastructure\Http\TradeHandler();
		$args    = [ $this->customer_id, 'B1_DUP_HASH', '428430', '5217', '1230', 'payuni-credit-subscription-v3' ];

		$first  = $handler->save_payment_token( ...$args );
		$second = $handler->save_payment_token( ...$args );

		$this->assertIsInt( $first, '第一次儲存應回傳 token ID' );
		$this->assertSame( $first, $second, '卡片已存在時應回傳既有 token ID' );
		$this->assertCount( 1, \WC_Payment_Tokens::get_customer_tokens( $this->customer_id, 'payuni-credit-subscription-v3' ) );
	}

	/**
	 * T-B1：拒存（正式環境沒有 CreditHash）回傳 null
	 *
	 * 注意：此案例在實作前就會通過（目前回傳 void 即 null），
	 * 用途是鎖住「拒存回傳 null」的契約，避免實作時誤回傳 0 或 false。
	 *
	 * @group edge
	 */
	public function test_T_B1_拒存時回傳null(): void {
		update_option( 'payuni_payment_testmode', 'no' );
		update_option( 'payuni_payment_merchant_no', 'PROD_MERCHANT' );
		update_option( 'payuni_payment_hash_key', 'PROD_HASH_KEY_1234567890123456' );
		update_option( 'payuni_payment_hash_iv', 'PROD_HASH_IV_1234567' );
		$this->reset_setting_dto();

		$id = ( new \J7\Payuni\Infrastructure\Http\TradeHandler() )->save_payment_token(
			$this->customer_id,
			'',
			'428430',
			'5217',
			'1230',
			'payuni-credit-subscription-v3'
		);

		$this->assertNull( $id, '拒存時必須回傳 null' );
		$this->assertCount( 0, \WC_Payment_Tokens::get_customer_tokens( $this->customer_id ) );
	}

	/**
	 * T-B2：V3 訂閱閘道首購成功後，token 要綁到母單的訂閱
	 *
	 * @group happy
	 */
	public function test_T_B2_首購成功後token綁到訂閱(): void {
		\Woomp_Test_Wcs_Fixture::install();

		$order = $this->create_test_order( 9450 );
		$order->set_status( 'pending' );
		$order->save();

		$subscription = \Woomp_Test_Wcs_Fixture::create_subscription( $order, $this->customer_id );

		( new \J7\Payuni\Infrastructure\Http\TradeHandler() )->update_order_status( $order, $this->success_trade_result( 'B2_HASH' ) );

		$tokens = \WC_Payment_Tokens::get_customer_tokens( $this->customer_id, 'payuni-credit-subscription-v3' );
		$this->assertCount( 1, $tokens, '首購成功應儲存一張 V3 訂閱 token' );

		$subscription = \wc_get_order( $subscription->get_id() );
		$this->assertSame(
			(string) \array_values( $tokens )[0]->get_id(),
			(string) $subscription->get_meta( '_payuni_token_id', true ),
			'首購成功後，母單的訂閱應被寫入 _payuni_token_id'
		);
	}

	/**
	 * T-B2：對訂閱物件呼叫 update_order_status 不可 payment_complete，訂閱與其 pending 續訂單不變
	 *
	 * 防線：換卡 webhook 的 5 元授權若被當成訂單處理，會把續訂單用 5 元標成已付款。
	 *
	 * @group security
	 */
	public function test_T_B2_對訂閱物件不會payment_complete(): void {
		\Woomp_Test_Wcs_Fixture::install();

		$parent       = $this->create_test_order( 9450 );
		$subscription = \Woomp_Test_Wcs_Fixture::create_subscription( $parent, $this->customer_id );
		$renewal      = \Woomp_Test_Wcs_Fixture::create_renewal_order( $subscription, $this->customer_id, 'pending' );

		// 真 WCS 的訂閱是 active，會先被 can_mark_paid() 擋掉，驗不到縱深防禦；
		// 改成 on-hold（可被標成已付款的狀態），確保擋下來的是「訂閱物件」這一層。
		$subscription->update_status( 'on-hold' );
		$this->assertTrue( \Woomp_Payment_Callback_Guard::can_mark_paid( $subscription ), '前置：訂閱狀態必須是狀態防護會放行的狀態' );

		$status_before = $subscription->get_status();

		( new \J7\Payuni\Infrastructure\Http\TradeHandler() )->update_order_status( $subscription, $this->success_trade_result( 'B2_SUB_HASH' ) );

		$subscription = \wc_get_order( $subscription->get_id() );
		$renewal      = \wc_get_order( $renewal->get_id() );

		$this->assertSame( $status_before, $subscription->get_status(), '訂閱狀態不可被 payment_complete 改動' );
		$this->assertFalse( $subscription->is_paid(), '訂閱不可被標成已付款' );
		$this->assertSame( 'pending', $renewal->get_status(), '訂閱的 pending 續訂單必須維持 pending' );
	}

	// ──────────────────────────────────────────────
	// Helper 方法
	// ──────────────────────────────────────────────

	/**
	 * 授權成功的解密交易結果
	 *
	 * @param string $credit_hash CreditHash
	 *
	 * @return array
	 */
	private function success_trade_result( string $credit_hash ): array {
		return [
			'Status'     => 'SUCCESS',
			'Message'    => '授權成功',
			'MerID'      => 'TEST_MERCHANT',
			'MerTradeNo' => 'TB2',
			'TradeNo'    => 'TRADE_TB2_0001',
			'TradeAmt'   => '9450',
			'Card6No'    => '428430',
			'Card4No'    => '5217',
			'CreditHash' => $credit_hash,
			'CreditLife' => '1230',
		];
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

	/**
	 * 攔截所有外送 HTTP 請求，不真的打 PayUni
	 *
	 * @return void
	 */
	private function capture_http_requests(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->captured_requests[] = [
					'url'  => $url,
					'args' => $args,
				];

				return [
					'headers'  => [],
					'body'     => wp_json_encode( [ 'Status' => 'TEST_INTERCEPTED' ] ),
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
	 * 找出 URL 含指定字串的請求
	 *
	 * @param string $needle 要比對的片段
	 *
	 * @return array|null 找到的請求，找不到回傳 null
	 */
	private function find_request_containing( string $needle ): ?array {
		foreach ( $this->captured_requests as $request ) {
			if ( false !== strpos( $request['url'], $needle ) ) {
				return $request;
			}
		}

		return null;
	}

	/**
	 * 解密攔截到的請求參數
	 *
	 * @param array $request 攔截到的請求
	 *
	 * @return array 解密後的參數
	 */
	private function decrypt_request_params( array $request ): array {
		$encrypt_info = $request['args']['body']['EncryptInfo'] ?? '';

		return $encrypt_info
			? \J7\Payuni\Shared\Utils\EncryptUtils::decrypt( $encrypt_info )
			: [];
	}

	// ──────────────────────────────────────────────
	// 換卡頁前端參數（issue #147 第三階段）
	// ──────────────────────────────────────────────

	/**
	 * T-W1：換卡情境下 localize 給前端的 USE_TOKEN_TYPE 必須是 3、PAGE_CONTEXT 必須是 order-pay
	 *
	 * 換卡頁購物車是空的，現行實作依購物車判斷會得到 2（記憶卡號），PayUni 不回 CreditHash，
	 * 換卡綁卡看似成功卻無法續扣（同 #130）。前端靠 PAGE_CONTEXT 切換 order-pay 流程。
	 *
	 * @testdox T-W1 換卡情境應 localize USE_TOKEN_TYPE=3 與 PAGE_CONTEXT=order-pay
	 */
	public function test_T_W1_換卡情境前端參數(): void {
		$this->enter_checkout_context( true );

		$params = $this->enqueue_and_read_checkout_params();

		$this->assertSame(
			\J7\Payuni\Shared\Enums\EUseTokenType::FORCE_BIND->value,
			(int) ( $params['USE_TOKEN_TYPE'] ?? 0 ),
			'換卡必須用強制約定信用卡（3），SDK token 與前端 getTradeResult 一致'
		);
		$this->assertSame( 'order-pay', $params['PAGE_CONTEXT'] ?? null, '換卡情境前端必須拿到 PAGE_CONTEXT=order-pay' );
	}

	/**
	 * T-W1 對照：一般結帳（空購物車、非 order-pay）維持現行行為
	 *
	 * 現行：USE_TOKEN_TYPE=2（記憶卡號）、沒有 PAGE_CONTEXT 欄位。
	 * 契約：PAGE_CONTEXT 在一般結帳可以不存在或為 'checkout'，但不可是 'order-pay'。
	 *
	 * @testdox T-W1 對照 一般結帳 USE_TOKEN_TYPE 仍為 2 且 PAGE_CONTEXT 不是 order-pay
	 * @group edge
	 */
	public function test_T_W1_一般結帳前端參數不變(): void {
		$this->enter_checkout_context( null );

		$params = $this->enqueue_and_read_checkout_params();

		$this->assertSame(
			\J7\Payuni\Shared\Enums\EUseTokenType::REMEMBER_CARD->value,
			(int) ( $params['USE_TOKEN_TYPE'] ?? 0 ),
			'一般結帳（無訂閱商品）維持記憶卡號（2）'
		);
		$this->assertContains( $params['PAGE_CONTEXT'] ?? 'checkout', [ 'checkout' ], '一般結帳 PAGE_CONTEXT 只能是不存在或 checkout' );
	}

	/**
	 * T-W1 對照：order-pay 但不是換卡，不可套用換卡參數
	 *
	 * @testdox T-W1 對照 order-pay 非換卡時 USE_TOKEN_TYPE 仍為 2 且 PAGE_CONTEXT 不是 order-pay
	 * @group edge
	 */
	public function test_T_W1_order_pay_非換卡不套用換卡參數(): void {
		$this->enter_checkout_context( false );

		$params = $this->enqueue_and_read_checkout_params();

		$this->assertSame(
			\J7\Payuni\Shared\Enums\EUseTokenType::REMEMBER_CARD->value,
			(int) ( $params['USE_TOKEN_TYPE'] ?? 0 ),
			'order-pay 非換卡不可強制綁卡'
		);
		$this->assertNotSame( 'order-pay', $params['PAGE_CONTEXT'] ?? null, '非換卡的 order-pay 不可套用換卡前端流程' );
	}

	/**
	 * 是否進入過結帳情境
	 *
	 * @var bool
	 */
	private bool $in_checkout_context = false;

	/**
	 * 進入情境前的狀態
	 *
	 * @var array{get: array, order_pay: mixed, flag: bool}
	 */
	private array $saved_context = [];

	/**
	 * 進入結帳情境
	 *
	 * @param bool|null $order_pay null=一般結帳；false=order-pay 非換卡；true=order-pay 換卡
	 *
	 * @return void
	 */
	private function enter_checkout_context( ?bool $order_pay ): void {
		$has_wcs               = class_exists( 'WC_Subscriptions_Change_Payment_Gateway' );
		$this->in_checkout_context = true;
		$this->saved_context   = [
			'get'       => $_GET,
			'order_pay' => $GLOBALS['wp']->query_vars['order-pay'] ?? null,
			'flag'      => $has_wcs ? (bool) \WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment : false,
		];

		add_filter( 'woocommerce_is_checkout', '__return_true' );
		unset( $_GET['change_payment_method'] );
		unset( $GLOBALS['wp']->query_vars['order-pay'] );
		if ( $has_wcs ) {
			\WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment = false;
		}

		if ( null !== $order_pay ) {
			$GLOBALS['wp']->query_vars['order-pay'] = '123';
		}
		if ( true === $order_pay ) {
			$_GET['change_payment_method'] = '123';
			if ( $has_wcs ) {
				\WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment = true;
			}
		}
	}

	/**
	 * 還原結帳情境與 script 註冊
	 *
	 * @return void
	 */
	private function leave_checkout_context(): void {
		if ( ! $this->in_checkout_context ) {
			return;
		}

		remove_filter( 'woocommerce_is_checkout', '__return_true' );
		if ( null === $this->saved_context['order_pay'] ) {
			unset( $GLOBALS['wp']->query_vars['order-pay'] );
		} else {
			$GLOBALS['wp']->query_vars['order-pay'] = $this->saved_context['order_pay'];
		}
		$_GET = $this->saved_context['get'];
		if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) ) {
			\WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment = $this->saved_context['flag'];
		}
		foreach ( [ 'uni-payment-checkout', 'uni-payment' ] as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
		wp_dequeue_style( 'payuni-checkout-v3' );
		wp_deregister_style( 'payuni-checkout-v3' );

		$this->in_checkout_context = false;
	}

	/**
	 * 呼叫 enqueue_checkout_scripts() 並解析 localize 給前端的參數
	 *
	 * PayUni 的 token_get 請求被擋下（回 WP_Error），USE_TOKEN_TYPE 仍在請求之前決定。
	 * wp_localize_script 會把純量轉成字串，所以數值要自行轉 int 比較。
	 *
	 * @return array<string, mixed>
	 */
	private function enqueue_and_read_checkout_params(): array {
		foreach ( [ 'uni-payment-checkout', 'uni-payment' ] as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'blocked', '測試不打外部 API' ) );

		\J7\Payuni\Bootstrap::enqueue_checkout_scripts();

		$data = (string) wp_scripts()->get_data( 'uni-payment-checkout', 'data' );
		$this->assertNotSame( '', $data, '前提：結帳頁應 localize payuni_payment_v3_checkout_params' );
		$this->assertSame( 1, preg_match( '/payuni_payment_v3_checkout_params\s*=\s*(\{.*\});/s', $data, $m ), '前提：localize 資料格式可解析' );

		$params = json_decode( $m[1], true );
		$this->assertIsArray( $params );

		return $params;
	}

	/**
	 * 建立測試用訂單
	 *
	 * @param int $total 訂單金額
	 *
	 * @return \WC_Order 訂單物件
	 */
	private function create_test_order( int $total ): \WC_Order {
		$order = \wc_create_order();
		$order->set_customer_id( $this->customer_id );
		$order->set_billing_email( 'test@example.com' );
		$order->set_total( $total );
		$order->set_payment_method( 'payuni-credit-subscription-v3' );
		$order->save();

		return $order;
	}

	/**
	 * 建立測試用 Payment Token
	 *
	 * @param string $token_value Token 值（CreditHash）
	 *
	 * @return void
	 */
	private function create_payment_token( string $token_value ): void {
		$token = new \WC_Payment_Token_CC();
		$token->set_token( $token_value );
		$token->set_gateway_id( 'payuni-credit-subscription-v3' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '5217' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( $this->customer_id );
		$token->set_default( true );
		$token->save();
	}
}

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
	// Helper 方法
	// ──────────────────────────────────────────────

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

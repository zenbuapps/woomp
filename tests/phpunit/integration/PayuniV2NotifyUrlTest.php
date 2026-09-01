<?php
/**
 * PayUni V2 信用卡 3D 驗證 NotifyURL 整合測試（issue #127 PR C）
 *
 * 背景：V2 信用卡開 3D 驗證時，送往 PayUni 的參數只帶 ReturnURL、沒帶 NotifyURL
 * （程式碼被註解掉）。訂單能否完成 100% 綁在「PayUni 用顧客瀏覽器把人導回」這一步，
 * 顧客付完款關閉視窗即掉單——錢已收、訂單停在 pending、數日後被逾期取消清掉。
 *
 * 本檔驗證送往 PayUni 的加密參數：
 * - 3D 開啟時同時含 ReturnURL 與 NotifyURL，且兩者指向不同端點
 * - NotifyURL 指向全小寫的 wc-api/payuni_notify_card_bg（wc-api 分派器會 strtolower）
 * - 3D 關閉時兩者都不出現
 * - 緊急關閉 filter 可停送 NotifyURL
 * - 綁卡 5 元的 build_hash_request() 同樣帶 NotifyURL
 *
 * 執行指令：
 * docker exec <woomp-tests-cli> sh -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --filter PayuniV2NotifyUrl'
 *
 * @package Woomp\Tests\Integration
 */

use PAYUNI\Gateways\Credit;
use PAYUNI\Gateways\Request;
use Payuni\APIs\Payment;

/**
 * PayUni V2 NotifyURL 測試類別
 *
 * @covers \PAYUNI\Gateways\Request::get_transaction_args
 * @covers \PAYUNI\Gateways\Request::build_hash_request
 * @group gateway
 * @group payuni
 * @group regression
 */
final class PayuniV2NotifyUrlTest extends WP_UnitTestCase {

	/**
	 * 測試過程中建立的訂單 ID，供 tearDown 清理。
	 *
	 * @var int[]
	 */
	private array $order_ids = [];

	/**
	 * 攔截到送往 PayUni 的請求 body（build_hash_request 用）。
	 *
	 * @var array
	 */
	private array $sent_bodies = [];

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( Request::class ) || ! class_exists( Credit::class ) ) {
			$this->markTestSkipped( 'PayUni V2 模組未載入，跳過 NotifyURL 測試' );
		}

		update_option( 'payuni_payment_testmode', 'yes' );
		update_option( 'payuni_payment_merchant_no_test', 'TEST_MERCHANT' );
		update_option( 'payuni_payment_hash_key_test', 'TEST_HASH_KEY_1234567890123456' );
		update_option( 'payuni_payment_hash_iv_test', 'TEST_HASH_IV_123456' );
		update_option( 'payuni_3d_auth', 'yes' );

		$this->order_ids   = [];
		$this->sent_bodies = [];
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		remove_all_filters( 'woomp_payuni_v2_enable_notify_url' );
		remove_filter( 'pre_http_request', [ $this, 'intercept_payuni_request' ], 10 );

		foreach ( $this->order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$order->delete( true );
			}
		}
		$this->order_ids = [];

		parent::tearDown();
	}

	// ========================================================================
	// Fixtures
	// ========================================================================

	/**
	 * 建立一張 pending 的 V2 信用卡訂單。
	 *
	 * @return \WC_Order
	 */
	private function create_order(): \WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( 'payuni-credit' );
		$order->set_total( 1000 );
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_status( 'pending' );
		$order->save();

		$this->order_ids[] = $order->get_id();

		return $order;
	}

	/**
	 * 典型的新卡結帳資料。
	 *
	 * @return array
	 */
	private function new_card_data(): array {
		return [
			'number'   => '4111111111111111',
			'expiry'   => '1230',
			'cvc'      => '123',
			'token_id' => 'new',
			'new'      => 'yes',
		];
	}

	/**
	 * 呼叫 get_transaction_args() 並解密 EncryptInfo，回傳實際送給 PayUni 的參數。
	 *
	 * @param \WC_Order $order 訂單物件。
	 *
	 * @return array
	 */
	private function get_decrypted_args( \WC_Order $order ): array {
		$request   = new Request( new Credit() );
		$parameter = $request->get_transaction_args( $order, $this->new_card_data() );

		$this->assertArrayHasKey( 'EncryptInfo', $parameter );

		return Payment::decrypt( $parameter['EncryptInfo'] );
	}

	/**
	 * pre_http_request 攔截器：記錄送往 PayUni 的 body，回一個「3D 建立成功」的假回應。
	 *
	 * @param mixed  $preempt 短路值。
	 * @param array  $args    請求參數。
	 * @param string $url     請求網址。
	 *
	 * @return mixed
	 */
	public function intercept_payuni_request( $preempt, $args, $url ) {
		if ( false === strpos( (string) $url, 'payuni.com.tw' ) ) {
			return $preempt;
		}

		$this->sent_bodies[] = $args['body'] ?? [];

		$encrypt_info = Payment::encrypt(
			[
				'Status'  => 'SUCCESS',
				'Message' => '建立幕後3D成功',
				'URL'     => 'https://sandbox-api.payuni.com.tw/api/credit/api_3d/TEST',
			]
		);

		return [
			'headers'  => [],
			'body'     => wp_json_encode(
				[
					'Status'      => 'SUCCESS',
					'EncryptInfo' => $encrypt_info,
					'HashInfo'    => Payment::hash_info( $encrypt_info ),
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

	// ========================================================================
	// Rule: 3D 開啟時同時送 ReturnURL 與 NotifyURL
	// ========================================================================

	/**
	 * @testdox 3D 開啟時，送出參數應同時含 ReturnURL 與 NotifyURL，且兩者指向不同端點
	 */
	public function test_3d_enabled_sends_both_return_and_notify_url(): void {
		$args = $this->get_decrypted_args( $this->create_order() );

		$this->assertSame( '1', (string) ( $args['API3D'] ?? '' ), '應開啟 API3D' );
		$this->assertArrayHasKey( 'ReturnURL', $args, '應送出 ReturnURL（瀏覽器導回）' );
		$this->assertArrayHasKey( 'NotifyURL', $args, '應送出 NotifyURL（幕後通知）——沒有它顧客關分頁就掉單' );
		$this->assertNotSame( $args['ReturnURL'], $args['NotifyURL'], '兩者必須指向不同端點，收尾方式不同' );
	}

	/**
	 * @testdox NotifyURL 應指向全小寫的 wc-api/payuni_notify_card_bg，ReturnURL 維持既有端點
	 */
	public function test_notify_url_points_to_background_endpoint(): void {
		$args = $this->get_decrypted_args( $this->create_order() );

		$this->assertStringEndsWith( 'wc-api/payuni_notify_card_bg', $args['NotifyURL'] );
		$this->assertSame( strtolower( $args['NotifyURL'] ), $args['NotifyURL'], 'wc-api 分派器會 strtolower，端點名必須全小寫' );
		$this->assertStringEndsWith( 'wc-api/payuni_notify_card', $args['ReturnURL'], 'ReturnURL 端點不應變動' );
	}

	/**
	 * @testdox 兩個 URL 對應的 wc-api hook 都必須已註冊（沒註冊 = 回 400，通知永遠收不到）
	 */
	public function test_both_endpoints_have_handlers(): void {
		$this->assertNotFalse( has_action( 'woocommerce_api_payuni_notify_card' ), 'ReturnURL 端點無 handler' );
		$this->assertNotFalse( has_action( 'woocommerce_api_payuni_notify_card_bg' ), 'NotifyURL 端點無 handler' );
	}

	// ========================================================================
	// Rule: 3D 關閉時不送
	// ========================================================================

	/**
	 * @testdox 3D 關閉時，ReturnURL / NotifyURL / API3D 都不應出現
	 */
	public function test_3d_disabled_sends_neither_url(): void {
		update_option( 'payuni_3d_auth', 'no' );

		$args = $this->get_decrypted_args( $this->create_order() );

		$this->assertArrayNotHasKey( 'API3D', $args );
		$this->assertArrayNotHasKey( 'ReturnURL', $args );
		$this->assertArrayNotHasKey( 'NotifyURL', $args );
	}

	// ========================================================================
	// Rule: 緊急關閉開關
	// ========================================================================

	/**
	 * @testdox filter woomp_payuni_v2_enable_notify_url 回 false 時應停送 NotifyURL，ReturnURL 不受影響
	 */
	public function test_kill_switch_filter_disables_notify_url(): void {
		add_filter( 'woomp_payuni_v2_enable_notify_url', '__return_false' );

		$args = $this->get_decrypted_args( $this->create_order() );

		$this->assertArrayHasKey( 'ReturnURL', $args, '關閉開關只影響 NotifyURL' );
		$this->assertArrayNotHasKey( 'NotifyURL', $args, '開關關閉時不應送 NotifyURL' );
	}

	// ========================================================================
	// Rule: MerTradeNo 落地（PR A）
	// ========================================================================

	/**
	 * @testdox 送出的 MerTradeNo 應落地成 _payuni_mer_trade_no，值與 EncryptInfo 內一致
	 */
	public function test_mer_trade_no_is_persisted(): void {
		$order = $this->create_order();
		$args  = $this->get_decrypted_args( $order );

		$this->assertSame(
			$args['MerTradeNo'],
			wc_get_order( $order->get_id() )->get_meta( '_payuni_mer_trade_no' ),
			'落地的 MerTradeNo 應與實際送出的完全一致'
		);
	}

	// ========================================================================
	// Rule: 綁卡 5 元同樣送 NotifyURL
	// ========================================================================

	/**
	 * build_hash_request() 不經 payuni_transaction_args_* filter，站方無法從外部補，
	 * 只能改原始碼。沒有 NotifyURL 時顧客關分頁不只不會存卡，5 元退刷也不會被排程。
	 *
	 * @testdox 綁卡 5 元的 build_hash_request() 同樣送出 NotifyURL
	 */
	public function test_hash_request_sends_notify_url(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'cardholder@example.com' ] );
		wp_set_current_user( $user_id );

		$order = $this->create_order();

		add_filter( 'pre_http_request', [ $this, 'intercept_payuni_request' ], 10, 3 );

		$request = new Request( new Credit() );
		$request->build_hash_request( $order, $this->new_card_data() );

		$this->assertNotEmpty( $this->sent_bodies, '應有送往 PayUni 的請求' );

		$body = $this->sent_bodies[0];
		$this->assertArrayHasKey( 'EncryptInfo', $body );

		$args = Payment::decrypt( $body['EncryptInfo'] );

		$this->assertSame( '5', (string) ( $args['TradeAmt'] ?? '' ), '前提：這是 5 元綁卡請求' );
		$this->assertArrayHasKey( 'ReturnURL', $args );
		$this->assertArrayHasKey( 'NotifyURL', $args, '綁卡請求也必須帶 NotifyURL' );
		$this->assertStringEndsWith( 'wc-api/payuni_notify_card_bg', $args['NotifyURL'] );
	}
}

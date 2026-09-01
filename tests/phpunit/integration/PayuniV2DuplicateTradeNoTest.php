<?php
/**
 * PayUni V2 CREDIT04001「已存在相同商店訂單編號」先反查再決定（issue #127 PR E）整合測試
 *
 * 背景：PayUni 對 MerTradeNo 的限制是「10 分鐘內不可重複」，CREDIT04001 只代表這個編號
 * 10 分鐘內用過，不必然代表前一筆已授權成功。過去程式直接 woomp_copy_order() 開新單
 * 重刷；若前一次其實已授權（網站只是沒收到通知），顧客會被扣兩次款。
 *
 * 本檔驗證 AbstractGateway::recover_or_copy_on_duplicate()：
 * - 反查得已付款 → 補完原訂單、不開新單、不再打 api/credit
 * - 反查得未付款 / 金額不符 / 查詢失敗 → 降級為既有的複製重刷路徑，結帳不中斷
 *
 * 執行指令：
 * docker exec <woomp-tests-cli> sh -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --filter PayuniV2DuplicateTradeNo'
 *
 * @package Woomp\Tests\Integration
 */

use J7\Payuni\Shared\Utils\EncryptUtils;
use PAYUNI\Gateways\Credit;
use Payuni\APIs\Payment;

/**
 * CREDIT04001 反查測試類別
 *
 * @covers \PAYUNI\Gateways\AbstractGateway::recover_or_copy_on_duplicate
 * @covers \PAYUNI\Gateways\Credit::process_payment
 * @group gateway
 * @group payuni
 * @group regression
 */
final class PayuniV2DuplicateTradeNoTest extends WP_UnitTestCase {

	/**
	 * api/credit 回應佇列（解密後的 payload，FIFO）。
	 *
	 * @var array
	 */
	private array $credit_responses = [];

	/**
	 * trade/query 回應（解密後的 payload）。
	 *
	 * @var array|null
	 */
	private ?array $query_response = null;

	/**
	 * 是否讓 trade/query 回 WP_Error。
	 *
	 * @var bool
	 */
	private bool $query_should_fail = false;

	/**
	 * 攔截到的請求 URL 清單。
	 *
	 * @var string[]
	 */
	private array $sent_urls = [];

	/**
	 * 測試中建立的訂單 ID。
	 *
	 * @var int[]
	 */
	private array $order_ids = [];

	/**
	 * 透過 woocommerce_new_order 計數的新訂單數。
	 *
	 * @var int
	 */
	private int $new_orders_created = 0;

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( Credit::class ) ) {
			$this->markTestSkipped( 'PayUni V2 模組未載入' );
		}

		update_option( 'payuni_payment_testmode', 'yes' );
		update_option( 'payuni_payment_merchant_no_test', 'TEST_MERCHANT' );
		update_option( 'payuni_payment_hash_key_test', 'TEST_HASH_KEY_1234567890123456' );
		update_option( 'payuni_payment_hash_iv_test', 'TEST_HASH_IV_123456' );
		update_option( 'payuni_3d_auth', 'no' ); // 非 3D 讓流程單純：成功會同步走 card_response。

		$this->reset_setting_dto();

		$this->credit_responses   = [];
		$this->query_response     = null;
		$this->query_should_fail  = false;
		$this->sent_urls          = [];
		$this->order_ids          = [];
		$this->new_orders_created = 0;

		add_filter( 'pre_http_request', [ $this, 'intercept' ], 10, 3 );
		add_action( 'woocommerce_new_order', [ $this, 'count_new_order' ] );

		// 模擬結帳頁 POST 的卡號欄位（AbstractGateway::get_card_data() 讀這些 key）。
		$_POST['payuni-credit-card-number']        = '4111 1111 1111 1111';
		$_POST['payuni-credit-card-expiry']        = '12/30';
		$_POST['payuni-credit-card-cvc']           = '123';
		$_POST['wc-payuni-credit-payment-token']   = 'new';
		$_POST['wc-payuni-credit-new-payment-method'] = 'no';
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept' ], 10 );
		remove_action( 'woocommerce_new_order', [ $this, 'count_new_order' ] );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		foreach ( [ 'payuni-credit-card-number', 'payuni-credit-card-expiry', 'payuni-credit-card-cvc', 'wc-payuni-credit-payment-token', 'wc-payuni-credit-new-payment-method' ] as $key ) {
			unset( $_POST[ $key ] );
		}

		foreach ( $this->order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$order->delete( true );
			}
		}
		// 複製出的新訂單不在 $order_ids 內，用 payment_method 撈掉。
		foreach ( wc_get_orders( [ 'payment_method' => 'payuni-credit', 'limit' => -1 ] ) as $order ) {
			$order->delete( true );
		}
		$this->order_ids = [];

		$this->reset_setting_dto();

		parent::tearDown();
	}

	// ========================================================================
	// Fixtures / mocks
	// ========================================================================

	/**
	 * 建立 pending 訂單。
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

		// fixture 自己的 wc_create_order() 也會觸發 woocommerce_new_order，歸零後才開始計「重刷複製出的新訂單」。
		$this->new_orders_created = 0;

		return wc_get_order( $order->get_id() );
	}

	/**
	 * 計數新訂單。
	 *
	 * @return void
	 */
	public function count_new_order(): void {
		++$this->new_orders_created;
	}

	/**
	 * 排一筆 api/credit 回應。
	 *
	 * @param string $status  PayUni 狀態碼。
	 * @param array  $extra   額外欄位。
	 *
	 * @return void
	 */
	private function queue_credit( string $status, array $extra = [] ): void {
		$this->credit_responses[] = array_merge(
			[
				'Status'  => $status,
				'Message' => 'CREDIT04001' === $status ? '已存在相同商店訂單編號' : ( 'SUCCESS' === $status ? '交易成功' : '授權失敗' ),
			],
			$extra
		);
	}

	/**
	 * 設定 trade/query 回應。
	 *
	 * @param \WC_Order $order  訂單。
	 * @param string    $status TradeStatus。
	 * @param array     $extra  覆蓋欄位。
	 *
	 * @return void
	 */
	private function set_query( \WC_Order $order, string $status, array $extra = [] ): void {
		$this->query_response = [
			'Result' => [
				array_merge(
					[
						'MerTradeNo'  => (string) $order->get_meta( '_payuni_mer_trade_no' ) ?: (string) $order->get_id(),
						'TradeNo'     => 'DUP_TN_' . $order->get_id(),
						'TradeStatus' => $status,
						'TradeAmt'    => (string) (int) $order->get_total(),
						'Card4No'     => '1111',
						'DataSource'  => 'A',
					],
					$extra
				),
			],
		];
	}

	/**
	 * pre_http_request 攔截器。
	 *
	 * @param mixed  $pre  短路值。
	 * @param array  $args 請求參數。
	 * @param string $url  網址。
	 *
	 * @return mixed
	 */
	public function intercept( $pre, $args, $url ) {
		$url = (string) $url;

		if ( false === strpos( $url, 'payuni.com.tw' ) ) {
			return $pre;
		}

		$this->sent_urls[] = $url;

		if ( false !== strpos( $url, 'trade/query' ) ) {
			if ( $this->query_should_fail ) {
				return new WP_Error( 'http_request_failed', '模擬連線失敗' );
			}
			$encrypt_info = EncryptUtils::encrypt( $this->query_response ?? [ 'Result' => [] ] );
			return $this->json_response( [ 'Status' => 'SUCCESS', 'EncryptInfo' => $encrypt_info, 'HashInfo' => EncryptUtils::hash_info( $encrypt_info ) ] );
		}

		// api/credit
		$payload = $this->credit_responses ? array_shift( $this->credit_responses ) : [ 'Status' => 'FAIL', 'Message' => '佇列已空' ];

		// 從送出的 EncryptInfo 取回 MerTradeNo，讓回應能對回同一張訂單。
		$sent = isset( $args['body']['EncryptInfo'] ) ? Payment::decrypt( $args['body']['EncryptInfo'] ) : [];
		$payload['MerTradeNo'] = $sent['MerTradeNo'] ?? '';
		$payload['TradeAmt']   = $sent['TradeAmt'] ?? '';
		$payload['TradeNo']    = $payload['TradeNo'] ?? 'NEW_TN_' . wp_rand( 1000, 9999 );
		$payload['AuthType']   = '1';

		$encrypt_info = Payment::encrypt( $payload );
		return $this->json_response( [ 'Status' => $payload['Status'], 'EncryptInfo' => $encrypt_info, 'HashInfo' => Payment::hash_info( $encrypt_info ) ] );
	}

	/**
	 * 組 WP_Http 回應。
	 *
	 * @param array $body JSON body。
	 *
	 * @return array
	 */
	private function json_response( array $body ): array {
		return [
			'headers'  => [],
			'body'     => wp_json_encode( $body ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * 送往 api/credit 的次數。
	 *
	 * @return int
	 */
	private function credit_calls(): int {
		return count( array_filter( $this->sent_urls, static fn( $u ) => false !== strpos( $u, 'api/credit' ) ) );
	}

	/**
	 * 重置 V3 SettingDTO 單例。
	 *
	 * @return void
	 */
	private function reset_setting_dto(): void {
		if ( ! class_exists( \J7\Payuni\Contracts\DTOs\SettingDTO::class ) ) {
			return;
		}
		$ref = new \ReflectionProperty( \J7\Payuni\Contracts\DTOs\SettingDTO::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	// ========================================================================
	// Rule: 反查已付款 → 補完原訂單，不開新單
	// ========================================================================

	/**
	 * @testdox CREDIT04001 + 反查 TradeStatus=1 → 補完原訂單、不產生新訂單、api/credit 只打 1 次
	 */
	public function test_credit04001_with_paid_trade_completes_original_order(): void {
		$order = $this->create_order();
		$this->queue_credit( 'CREDIT04001' );

		// 先讓 _payuni_mer_trade_no 落地（第一次 build_request 會寫），再設定反查回應。
		$this->set_query( $order, '1' );

		$result = ( new Credit() )->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 0, $this->new_orders_created, '反查得已付款不應開新訂單' );
		$this->assertSame( 1, $this->credit_calls(), '不應再打 api/credit 重刷' );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertTrue( $reloaded->is_paid(), '原訂單應被補完' );
		$this->assertSame( 'DUP_TN_' . $order->get_id(), $reloaded->get_meta( '_payuni_resp_trade_no' ) );

		$notes = array_map( static fn( $n ) => $n->content, wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
		$this->assertNotEmpty( array_filter( $notes, static fn( $t ) => false !== strpos( $t, '未重複刷卡' ) ) );
	}

	/**
	 * @testdox 反查補完路徑不應掛 wp_doing_ajax filter（那是複製重刷路徑才需要的 hack）
	 */
	public function test_recover_path_does_not_add_wp_doing_ajax_filter(): void {
		$order = $this->create_order();
		$this->queue_credit( 'CREDIT04001' );
		$this->set_query( $order, '1' );

		( new Credit() )->process_payment( $order->get_id() );

		$this->assertFalse( has_filter( 'wp_doing_ajax', '__return_true' ) );
	}

	// ========================================================================
	// Rule: 反查未成功 → 降級為複製重刷
	// ========================================================================

	/**
	 * @testdox CREDIT04001 + 反查 TradeStatus=9（未付款）→ 複製新訂單並重刷，api/credit 打 2 次
	 */
	public function test_credit04001_with_unpaid_trade_copies_order(): void {
		$order = $this->create_order();
		$this->queue_credit( 'CREDIT04001' );
		$this->queue_credit( 'FAIL' ); // 新訂單重刷的結果，內容不重要。
		$this->set_query( $order, '9' );

		( new Credit() )->process_payment( $order->get_id() );

		$this->assertSame( 1, $this->new_orders_created, '未付款應照舊複製新訂單重刷' );
		$this->assertSame( 2, $this->credit_calls(), '應對新訂單再打一次 api/credit' );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid(), '原訂單不應被完成' );
	}

	/**
	 * @testdox CREDIT04001 + 反查金額不符 → 不補完原訂單，改複製重刷
	 */
	public function test_credit04001_with_amount_mismatch_copies_order(): void {
		$order = $this->create_order();
		$this->queue_credit( 'CREDIT04001' );
		$this->queue_credit( 'FAIL' );
		$this->set_query( $order, '1', [ 'TradeAmt' => '999' ] );

		( new Credit() )->process_payment( $order->get_id() );

		$this->assertSame( 1, $this->new_orders_created );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid(), '金額不符不得把舊交易當成本次付款' );
	}

	/**
	 * @testdox CREDIT04001 + 反查連線失敗 → 安全降級為複製重刷，結帳不中斷
	 */
	public function test_credit04001_with_query_failure_falls_back_to_copy(): void {
		$order = $this->create_order();
		$this->queue_credit( 'CREDIT04001' );
		$this->queue_credit( 'FAIL' );
		$this->query_should_fail = true;

		$result = ( new Credit() )->process_payment( $order->get_id() );

		$this->assertIsArray( $result, '對帳查詢失敗絕不能讓結帳炸掉' );
		$this->assertSame( 1, $this->new_orders_created, '查詢失敗應降級為既有行為' );
	}

	/**
	 * @testdox 非 CREDIT04001 的失敗不應觸發反查，也不應複製訂單
	 */
	public function test_other_failure_does_not_trigger_recovery(): void {
		$order = $this->create_order();
		$this->queue_credit( 'CREDIT05002' );
		$this->set_query( $order, '1' );

		$result = ( new Credit() )->process_payment( $order->get_id() );

		$this->assertSame( 'failed', $result['result'] );
		$this->assertSame( 0, $this->new_orders_created );
		$this->assertEmpty( array_filter( $this->sent_urls, static fn( $u ) => false !== strpos( $u, 'trade/query' ) ), '不應反查' );
	}
}

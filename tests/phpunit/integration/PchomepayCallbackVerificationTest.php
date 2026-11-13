<?php
/**
 * 支付連（PChomePay）背景通知來源驗證整合測試
 *
 * 通知網址是公開的 wc-api，任何人都能 POST。驗證：
 * - 通知只當觸發訊號，訂單狀態一律以 getPayment() 伺服器回查結果為準
 * - 訂單綁定（付款方式、_pchomepay_orderid）、金額、狀態轉換
 * - 回查失敗回 503、驗證不通過回 403
 * - access token 改存 option，不再寫入外掛目錄
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 支付連背景通知驗證測試
 *
 * @covers includes/PChomePay-Cart-for-WooCommerce/
 * @group gateway
 * @group pchomepay
 * @group security
 */
class PchomepayCallbackVerificationTest extends WP_UnitTestCase {

	/**
	 * 測試訂單
	 *
	 * @var WC_Order
	 */
	private $order;

	/**
	 * 訂單上的支付連訂單編號
	 *
	 * @var string
	 */
	private $pchomepay_order_id;

	/**
	 * getPayment() 模擬回應：array 為 JSON 內容、WP_Error 為連線失敗
	 *
	 * @var array|WP_Error
	 */
	private $payment_response;

	/**
	 * 記錄送出的 HTTP 請求網址
	 *
	 * @var string[]
	 */
	private $requested_urls = [];

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce 未載入，跳過支付連回呼測試' );
		}

		$dir = WOOMP_PLUGIN_DIR_TEST . 'includes/PChomePay-Cart-for-WooCommerce/includes/';
		require_once $dir . 'PChomePayClient.php';
		require_once $dir . 'PChomePayGateway.php';
		require_once $dir . 'PChomePayPIGateway.php';

		update_option( 'woomp_pchomepay_app_id', 'TEST_APP_ID' );
		update_option( 'woomp_pchomepay_secret', 'TEST_SECRET' );
		update_option( 'woomp_pchomepay_sandbox_secret', 'TEST_SANDBOX_SECRET' );
		update_option( 'woomp_pchomepay_test_mode', 'no' );
		delete_option( PChomePayTokenStorage::OPTION_NAME );
		delete_option( PChomePayTokenStorage::SANDBOX_OPTION_NAME );

		$this->order = wc_create_order();
		$this->order->set_payment_method( 'pchomepay' );
		$this->order->set_total( 1000 );
		$this->order->set_status( 'pending' );
		$this->order->save();

		$this->pchomepay_order_id = 'AW20261113' . $this->order->get_id();
		$this->order->update_meta_data( '_pchomepay_orderid', $this->pchomepay_order_id );
		$this->order->save();

		$this->payment_response = $this->paid_payment();
		$this->requested_urls   = [];

		add_filter( 'pre_http_request', [ $this, 'mock_pchomepay_api' ], 10, 3 );
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'mock_pchomepay_api' ], 10 );
		remove_filter( 'wc_order_statuses', [ $this, 'add_audit_statuses' ] );
		if ( $this->order instanceof WC_Order ) {
			$this->order->delete( true );
		}
		foreach ( [ 'woomp_pchomepay_app_id', 'woomp_pchomepay_secret', 'woomp_pchomepay_sandbox_secret', 'woomp_pchomepay_test_mode', PChomePayTokenStorage::OPTION_NAME, PChomePayTokenStorage::SANDBOX_OPTION_NAME ] as $option ) {
			delete_option( $option );
		}
		parent::tearDown();
	}

	/**
	 * 模擬支付連 API（token 與 getPayment）
	 *
	 * @param false|array|WP_Error $preempt 預設回應.
	 * @param array                $args    請求參數.
	 * @param string               $url     請求網址.
	 * @return false|array|WP_Error
	 */
	public function mock_pchomepay_api( $preempt, $args, $url ) {
		if ( false === strpos( $url, 'pchomepay.com.tw' ) ) {
			return $preempt;
		}
		$this->requested_urls[] = $url;

		if ( false !== strpos( $url, '/v1/token' ) ) {
			return $this->http_response(
				200,
				[
					'token'             => 'MOCK_TOKEN',
					'expired_in'        => 28800,
					'expired_timestamp' => time() + 28800,
				]
			);
		}

		if ( false !== strpos( $url, '/v1/payment/' ) ) {
			if ( is_wp_error( $this->payment_response ) ) {
				return $this->payment_response;
			}
			return $this->http_response( 200, $this->payment_response );
		}

		return $this->http_response( 404, [] );
	}

	/**
	 * 加入審單中訂單狀態
	 *
	 * @param array $statuses 訂單狀態.
	 */
	public function add_audit_statuses( $statuses ) {
		$statuses['wc-awaiting']         = '等待審單';
		$statuses['wc-awaitingforpcpay'] = '等待支付連審單';
		return $statuses;
	}

	/**
	 * 組 HTTP 回應
	 *
	 * @param int   $code HTTP 狀態碼.
	 * @param array $body JSON 內容.
	 */
	private function http_response( int $code, array $body ): array {
		return [
			'headers'  => [],
			'body'     => wp_json_encode( $body ),
			'response' => [
				'code'    => $code,
				'message' => '',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * 回查結果：已付款
	 *
	 * @param array $overrides 覆寫欄位.
	 */
	private function paid_payment( array $overrides = [] ): array {
		return array_merge(
			[
				WC_Gateway_PChomePay::QUERY_ORDER_ID_FIELD => $this->pchomepay_order_id,
				WC_Gateway_PChomePay::QUERY_PAY_TYPE_FIELD => 'CARD',
				WC_Gateway_PChomePay::QUERY_AMOUNT_FIELD   => 1000,
				WC_Gateway_PChomePay::QUERY_STATUS_FIELD   => WC_Gateway_PChomePay::QUERY_STATUS_SUCCESS,
				'payment_info'                             => [
					'installment'      => 1,
					'card_last_number' => '1234',
				],
			],
			$overrides
		);
	}

	/**
	 * 組通知請求
	 *
	 * @param string $notify_type 通知類型.
	 * @param array  $message     notify_message 內容.
	 */
	private function notify( string $notify_type, array $message = [] ): array {
		return [
			'notify_type'    => $notify_type,
			'notify_message' => wp_json_encode(
				array_merge(
					[
						'order_id' => $this->pchomepay_order_id,
						'pay_type' => 'CARD',
						'amount'   => 1000,
					],
					$message
				)
			),
		];
	}

	/**
	 * 處理通知並回傳結果
	 *
	 * @param array  $request 請求.
	 * @param string $class   閘道類別.
	 */
	private function handle( array $request, string $class = 'WC_Gateway_PChomePay' ): array {
		$gateway = new $class();
		return $gateway->handle_notify( $request );
	}

	/**
	 * 重新讀取訂單
	 */
	private function reload(): WC_Order {
		return wc_get_order( $this->order->get_id() );
	}

	/**
	 * 是否打過 getPayment
	 */
	private function queried_payment(): bool {
		foreach ( $this->requested_urls as $url ) {
			if ( false !== strpos( $url, '/v1/payment/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @testdox 回查為已付款且金額相符時才標成已付款
	 */
	public function test_confirm_with_paid_query_completes_order() {
		$result = $this->handle( $this->notify( 'order_confirm' ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'success', $result['body'] );
		$order = $this->reload();
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( $this->pchomepay_order_id, $order->get_transaction_id() );
		$this->assertSame( 'CARD', $order->get_meta( '_pchomepay_paytype' ) );
		$this->assertStringContainsString( '/v1/payment/' . $this->pchomepay_order_id, implode( ' ', $this->requested_urls ) );
	}

	/**
	 * @testdox 偽造的 order_confirm（回查未付款）不會標成已付款
	 */
	public function test_forged_confirm_does_not_pay_when_query_not_paid() {
		$this->payment_response = $this->paid_payment(
			[
				WC_Gateway_PChomePay::QUERY_STATUS_FIELD      => 'W',
				WC_Gateway_PChomePay::QUERY_STATUS_CODE_FIELD => OrderStatusCodeEnum::ORDER_ATM_PAYING,
			]
		);

		$result = $this->handle( $this->notify( 'order_confirm' ) );

		$this->assertSame( 403, $result['status'] );
		$order = $this->reload();
		$this->assertFalse( $order->is_paid() );
		$this->assertTrue( $order->has_status( 'pending' ) );
	}

	/**
	 * @testdox 回查找不到交易（ORDER_NOT_EXIST）回 403 且不付款
	 */
	public function test_confirm_rejected_when_query_says_order_not_exist() {
		$this->payment_response = [
			'error_type' => 'invalid_request_error',
			'code'       => ApiException::ORDER_NOT_EXIST,
			'message'    => 'order not exist',
		];

		$result = $this->handle( $this->notify( 'order_confirm' ) );

		$this->assertSame( 403, $result['status'] );
		$this->assertFalse( $this->reload()->is_paid() );
	}

	/**
	 * @testdox 回查狀態值無法辨識時一律不付款（fail-closed）
	 */
	public function test_confirm_rejected_when_query_status_missing() {
		$payment = $this->paid_payment();
		unset( $payment[ WC_Gateway_PChomePay::QUERY_STATUS_FIELD ] );
		$this->payment_response = $payment;

		$result = $this->handle( $this->notify( 'order_confirm' ) );

		$this->assertSame( 403, $result['status'] );
		$this->assertFalse( $this->reload()->is_paid() );
	}

	/**
	 * @testdox 通知 order_id 與訂單 _pchomepay_orderid 不符時拒絕且不回查
	 */
	public function test_order_id_not_matching_meta_is_rejected() {
		$this->order->update_meta_data( '_pchomepay_orderid', 'AW20260101' . $this->order->get_id() );
		$this->order->save();

		$result = $this->handle( $this->notify( 'order_confirm' ) );

		$this->assertSame( 403, $result['status'] );
		$this->assertFalse( $this->reload()->is_paid() );
		$this->assertFalse( $this->queried_payment() );
	}

	/**
	 * @testdox 訂單沒有 _pchomepay_orderid 時拒絕
	 */
	public function test_order_without_meta_is_rejected() {
		$this->order->delete_meta_data( '_pchomepay_orderid' );
		$this->order->save();

		$result = $this->handle( $this->notify( 'order_confirm' ) );

		$this->assertSame( 403, $result['status'] );
		$this->assertFalse( $this->reload()->is_paid() );
	}

	/**
	 * @testdox 訂單付款方式不是支付連時拒絕
	 */
	public function test_non_pchomepay_order_is_rejected() {
		$this->order->set_payment_method( 'bacs' );
		$this->order->save();

		$result = $this->handle( $this->notify( 'order_confirm' ) );

		$this->assertSame( 403, $result['status'] );
		$this->assertFalse( $this->reload()->is_paid() );
		$this->assertFalse( $this->queried_payment() );
	}

	/**
	 * @testdox 回查金額與訂單不符時拒絕
	 */
	public function test_amount_mismatch_is_rejected() {
		$this->payment_response = $this->paid_payment( [ WC_Gateway_PChomePay::QUERY_AMOUNT_FIELD => 1 ] );

		$result = $this->handle( $this->notify( 'order_confirm', [ 'amount' => 1000 ] ) );

		$this->assertSame( 403, $result['status'] );
		$this->assertFalse( $this->reload()->is_paid() );
	}

	/**
	 * @testdox 回查結果的 order_id 與通知不同時拒絕
	 */
	public function test_query_order_id_mismatch_is_rejected() {
		$this->payment_response = $this->paid_payment( [ WC_Gateway_PChomePay::QUERY_ORDER_ID_FIELD => 'AW20261113999999' ] );

		$result = $this->handle( $this->notify( 'order_confirm' ) );

		$this->assertSame( 403, $result['status'] );
		$this->assertFalse( $this->reload()->is_paid() );
	}

	/**
	 * @testdox 已取消的訂單即使回查為已付款也不會被改回
	 */
	public function test_cancelled_order_is_not_reopened() {
		$this->order->set_status( 'cancelled' );
		$this->order->save();

		$result = $this->handle( $this->notify( 'order_confirm' ) );

		$this->assertSame( 200, $result['status'] );
		$order = $this->reload();
		$this->assertTrue( $order->has_status( 'cancelled' ) );
		$this->assertFalse( $order->is_paid() );
	}

	/**
	 * @testdox order_expired 不會把已付款訂單降級
	 */
	public function test_expired_does_not_downgrade_paid_order() {
		$this->order->set_status( 'processing' );
		$this->order->save();
		$this->payment_response = $this->paid_payment(
			[
				WC_Gateway_PChomePay::QUERY_STATUS_FIELD      => WC_Gateway_PChomePay::QUERY_STATUS_FAILED,
				WC_Gateway_PChomePay::QUERY_STATUS_CODE_FIELD => OrderStatusCodeEnum::ORDER_EXPIRED,
			]
		);

		$result = $this->handle( $this->notify( 'order_expired', [ 'status_code' => 'FE' ] ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertTrue( $this->reload()->has_status( 'processing' ) );
	}

	/**
	 * @testdox 偽造 order_expired（回查實際已付款）不會把訂單改成失敗
	 */
	public function test_forged_expired_uses_query_result() {
		$this->order->set_status( 'processing' );
		$this->order->save();

		$result = $this->handle( $this->notify( 'order_expired', [ 'status_code' => 'FE' ] ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertTrue( $this->reload()->has_status( 'processing' ) );
	}

	/**
	 * @testdox 回查為失敗時，未付款訂單改為 failed
	 */
	public function test_expired_marks_unpaid_order_failed_when_query_failed() {
		$this->payment_response = $this->paid_payment(
			[
				WC_Gateway_PChomePay::QUERY_STATUS_FIELD      => WC_Gateway_PChomePay::QUERY_STATUS_FAILED,
				WC_Gateway_PChomePay::QUERY_STATUS_CODE_FIELD => OrderStatusCodeEnum::ORDER_EXPIRED,
			]
		);

		$result = $this->handle( $this->notify( 'order_expired' ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertTrue( $this->reload()->has_status( 'failed' ) );
	}

	/**
	 * @testdox 偽造 order_audit 不會把已付款訂單改成審單中
	 */
	public function test_audit_does_not_downgrade_paid_order() {
		$this->order->set_status( 'processing' );
		$this->order->save();
		$this->payment_response = $this->paid_payment(
			[
				WC_Gateway_PChomePay::QUERY_STATUS_FIELD      => 'W',
				WC_Gateway_PChomePay::QUERY_STATUS_CODE_FIELD => OrderStatusCodeEnum::ORDER_PENDING_CLIENT,
			]
		);

		$result = $this->handle( $this->notify( 'order_audit', [ 'status_code' => 'WAC' ] ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertTrue( $this->reload()->has_status( 'processing' ) );
	}

	/**
	 * @testdox 審單中的訂單在回查為已付款時可以完成付款
	 */
	public function test_awaiting_order_can_be_completed() {
		// 審單狀態只在支付連啟用時才由 PChomePay.php 註冊，測試中自行加入。
		add_filter( 'wc_order_statuses', [ $this, 'add_audit_statuses' ] );
		$this->order->set_status( 'awaiting' );
		$this->order->save();
		$this->assertTrue( $this->reload()->has_status( 'awaiting' ) );

		$result = $this->handle( $this->notify( 'order_confirm' ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertTrue( $this->reload()->is_paid() );
	}

	/**
	 * @testdox 回查 API 連線失敗回 503 且不改訂單
	 */
	public function test_api_failure_returns_503() {
		$this->payment_response = new WP_Error( 'http_request_failed', 'timeout' );

		$result = $this->handle( $this->notify( 'order_confirm' ) );

		$this->assertSame( 503, $result['status'] );
		$order = $this->reload();
		$this->assertTrue( $order->has_status( 'pending' ) );
		$this->assertFalse( $order->is_paid() );
	}

	/**
	 * @testdox 未設定 API 金鑰時拒絕且不改訂單
	 */
	public function test_missing_credentials_is_rejected() {
		update_option( 'woomp_pchomepay_secret', '' );

		$result = $this->handle( $this->notify( 'order_confirm' ) );

		$this->assertSame( 403, $result['status'] );
		$this->assertFalse( $this->reload()->is_paid() );
	}

	/**
	 * @testdox 通知內容不會以顧客備註寫入，且攻擊者字串不會進入備註
	 */
	public function test_notify_content_not_written_to_customer_notes() {
		$this->handle(
			$this->notify(
				'order_confirm',
				[
					'payment_info' => [
						'bank_code'       => '<script>alert(1)</script>',
						'virtual_account' => 'EVIL',
					],
				]
			)
		);

		$notes = wc_get_order_notes( [ 'order_id' => $this->order->get_id() ] );
		foreach ( $notes as $note ) {
			$this->assertFalse( (bool) $note->customer_note, '支付連通知不可寫成顧客備註：' . $note->content );
			$this->assertStringNotContainsString( '<script>', $note->content );
			$this->assertStringNotContainsString( 'EVIL', $note->content );
		}
	}

	/**
	 * @testdox PI 拍錢包閘道使用相同驗證
	 */
	public function test_pi_gateway_uses_same_verification() {
		$this->order->set_payment_method( 'pchomepay_pi' );
		$this->order->save();
		$this->payment_response = $this->paid_payment( [ WC_Gateway_PChomePay::QUERY_STATUS_FIELD => 'W' ] );

		$forged = $this->handle( $this->notify( 'order_confirm' ), 'WC_PI_Gateway_PChomePay' );
		$this->assertSame( 403, $forged['status'] );
		$this->assertFalse( $this->reload()->is_paid() );

		$this->payment_response = $this->paid_payment( [ WC_Gateway_PChomePay::QUERY_PAY_TYPE_FIELD => 'PI' ] );
		$valid                  = $this->handle( $this->notify( 'order_confirm' ), 'WC_PI_Gateway_PChomePay' );
		$this->assertSame( 200, $valid['status'] );
		$this->assertTrue( $this->reload()->is_paid() );
	}

	/**
	 * @testdox 退款通知的退款編號不是本站送出過的時拒絕且不寫備註
	 */
	public function test_unknown_refund_notify_is_rejected() {
		$before = count( wc_get_order_notes( [ 'order_id' => $this->order->get_id() ] ) );

		$result = $this->handle(
			[
				'notify_type'    => 'refund_success',
				'notify_message' => wp_json_encode( [ 'refund_id' => 'RF' . $this->pchomepay_order_id ] ),
			]
		);

		$this->assertSame( 403, $result['status'] );
		$this->assertCount( $before, wc_get_order_notes( [ 'order_id' => $this->order->get_id() ] ) );
	}

	/**
	 * @testdox 已知退款編號的退款通知只寫私人備註
	 */
	public function test_known_refund_notify_writes_private_note() {
		$refund_id = 'RF' . $this->pchomepay_order_id;
		$this->order->update_meta_data( '_pchomepay_refundid', $refund_id );
		$this->order->save();

		$result = $this->handle(
			[
				'notify_type'    => 'refund_success',
				'notify_message' => wp_json_encode(
					[
						'refund_id' => $refund_id,
						'extra'     => '<img src=x onerror=alert(1)>',
					]
				),
			]
		);

		$this->assertSame( 200, $result['status'] );
		$notes = wc_get_order_notes( [ 'order_id' => $this->order->get_id() ] );
		$this->assertNotEmpty( $notes );
		$this->assertFalse( (bool) $notes[0]->customer_note );
		$this->assertStringContainsString( $refund_id, $notes[0]->content );
		$this->assertStringNotContainsString( 'onerror', $notes[0]->content );
	}

	/**
	 * @testdox access token 存在 option（不 autoload），不寫入外掛目錄
	 */
	public function test_token_is_stored_in_option_not_file() {
		$this->handle( $this->notify( 'order_confirm' ) );

		$stored = get_option( PChomePayTokenStorage::OPTION_NAME );
		$this->assertIsString( $stored );
		$this->assertSame( 'MOCK_TOKEN', json_decode( $stored )->token );
		$this->assertFileDoesNotExist( WOOMP_PLUGIN_DIR_TEST . 'includes/PChomePay-Cart-for-WooCommerce/pchomepay_api_token.json' );

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", PChomePayTokenStorage::OPTION_NAME ) );
		$this->assertContains( $autoload, [ 'no', 'off' ] );
	}
}

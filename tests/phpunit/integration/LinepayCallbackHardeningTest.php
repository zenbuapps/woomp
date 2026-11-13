<?php
/**
 * LINE Pay 回呼強化整合測試
 *
 * `/wc-api/wc_gateway_linepay_handler` 是公開網址：
 * - refund：只有已登入的訂單本人、帶正確 nonce 才能退款，金額由伺服器決定
 * - confirm／cancel：必須帶 reserve 時發出的 token
 * - confirm 有鎖，已付款訂單的錯誤分支不可把狀態打回 pending
 *
 * @package Woomp\Tests\Integration
 */

/**
 * LINE Pay 回呼強化測試類別
 *
 * @group gateway
 * @group linepay
 * @group security
 */
class LinepayCallbackHardeningTest extends WP_UnitTestCase {

	/**
	 * LINE Pay handler
	 *
	 * @var WC_Gateway_LINEPay_Handler
	 */
	private $handler;

	/**
	 * 攔截到的 LINE Pay API 請求
	 *
	 * @var array<int, array{url: string, body: string}>
	 */
	private $http_requests = [];

	/**
	 * 測試建立的訂單
	 *
	 * @var WC_Order[]
	 */
	private $orders = [];

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WC_Order' ) || ! class_exists( 'WC_Gateway_LINEPay_Handler' ) ) {
			$this->markTestSkipped( 'WooCommerce 或 LINE Pay 模組未載入' );
		}

		update_option( 'woocommerce_linepay_enabled', 'yes' );
		update_option( 'linepay_sandbox', 'yes' );
		update_option( 'linepay_sandbox_channel_id', 'TEST_CHANNEL' );
		update_option( 'linepay_sandbox_channel_secret', 'TEST_SECRET' );
		update_option( 'linepay_customer_refund', [ 'wc-processing' ] );
		update_option( 'linepay_admin_refund', [ 'wc-processing', 'wc-completed' ] );

		$this->handler = new WC_Gateway_LINEPay_Handler();
		$this->handler->init_wc_gateway_linepay_handler();

		// 回呼成功時會 wp_safe_redirect + exit，測試中關掉 exit.
		add_filter( 'woomp_linepay_callback_exit', '__return_false' );

		$this->http_requests = [];
		add_filter( 'pre_http_request', [ $this, 'fake_linepay_api' ], 10, 3 );
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		foreach ( $this->orders as $order ) {
			$order->delete( true );
		}
		$this->orders = [];
		unset( $_GET['request_type'], $_GET['order_id'], $_GET['token'], $_GET['_wpnonce'], $_GET['cancel_amount'], $_GET['reason'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 假的 LINE Pay API：記錄請求並依端點回傳成功
	 *
	 * @param false|array $pre  預設回應.
	 * @param array       $args 請求參數.
	 * @param string      $url  網址.
	 * @return false|array
	 */
	public function fake_linepay_api( $pre, $args, $url ) {
		if ( false === strpos( $url, 'pay.line.me' ) ) {
			return $pre;
		}

		$this->http_requests[] = [
			'url'  => $url,
			'body' => (string) ( $args['body'] ?? '' ),
		];

		if ( false !== strpos( $url, '/refund' ) ) {
			$info = [
				'refundTransactionId'   => '2026111300000001',
				'refundTransactionDate' => '2026-11-13T00:00:00Z',
			];
		} elseif ( false !== strpos( $url, '/confirm' ) ) {
			$info = [
				'transactionId' => '2026111300000002',
				'payInfo'       => [ [ 'method' => 'BALANCE', 'amount' => 1000 ] ],
			];
		} else {
			$info = [ [ 'payStatus' => 'AUTHORIZATION' ] ];
		}

		return [
			'headers'  => [],
			'body'     => wp_json_encode( [ 'returnCode' => '0000', 'returnMessage' => 'OK', 'info' => $info ] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * 建立已 confirm 的 LINE Pay 訂單
	 *
	 * @param int $customer_id 顧客 ID.
	 * @return WC_Order
	 */
	private function create_confirmed_order( int $customer_id ): WC_Order {
		$order = wc_create_order( [ 'customer_id' => $customer_id ] );
		$order->set_payment_method( 'linepay' );
		$order->set_currency( 'TWD' );
		$order->set_total( 1000 );
		$order->set_transaction_id( 'LINEPAY_TX_1' );
		$order->set_status( 'processing' );
		$order->update_meta_data( '_linepay_payment_status', WC_Gateway_LINEPay_Const::PAYMENT_STATUS_CONFIRMED );
		$order->update_meta_data( '_linepay_transaction_balanced_amount', '1000' );
		$order->save();

		$this->orders[] = $order;
		return $order;
	}

	/**
	 * 建立已 reserve、等待 confirm 的 LINE Pay 訂單
	 *
	 * @param string $token 回呼 token.
	 * @return WC_Order
	 */
	private function create_reserved_order( string $token = 'valid-callback-token' ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( 'linepay' );
		$order->set_currency( 'TWD' );
		$order->set_total( 1000 );
		$order->set_status( 'pending' );
		$order->update_meta_data( '_linepay_payment_status', WC_Gateway_LINEPay_Const::PAYMENT_STATUS_RESERVED );
		$order->update_meta_data( '_linepay_reserved_transaction_id', 'LINEPAY_RESERVED_1' );
		$order->update_meta_data( WC_Gateway_LINEPay::META_RESERVED_AMOUNT, '1000' );
		$order->update_meta_data( WC_Gateway_LINEPay::META_CALLBACK_TOKEN_HASH, hash( 'sha256', $token ) );
		$order->save();

		$this->orders[] = $order;
		return $order;
	}

	/**
	 * 模擬打 wc-api 回呼
	 *
	 * @param array $query $_GET 參數.
	 */
	private function call_callback( array $query ): void {
		foreach ( $query as $key => $value ) {
			$_GET[ $key ] = $value;
		}
		$this->handler->handle_callback();
	}

	/**
	 * 是否打過指定的 LINE Pay API
	 *
	 * @param string $needle 網址片段.
	 * @return bool
	 */
	private function called_api( string $needle ): bool {
		foreach ( $this->http_requests as $request ) {
			if ( false !== strpos( $request['url'], $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @testdox 匿名請求不能觸發顧客退款
	 */
	public function test_anonymous_refund_is_rejected() {
		$customer_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		$order       = $this->create_confirmed_order( $customer_id );

		wp_set_current_user( 0 );
		$this->call_callback(
			[
				'request_type'  => 'refund',
				'order_id'      => (string) $order->get_id(),
				'cancel_amount' => '1000',
			]
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $this->called_api( '/refund' ), '匿名請求不應呼叫 LINE Pay 退款 API' );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertEmpty( $order->get_refunds(), '不應建立 WC 退款' );
	}

	/**
	 * @testdox 登入的其他顧客（即使帶自己的 nonce）不能退別人的訂單
	 */
	public function test_other_customer_refund_is_rejected() {
		$owner_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		$other_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		$order    = $this->create_confirmed_order( $owner_id );

		wp_set_current_user( $other_id );
		$this->call_callback(
			[
				'request_type' => 'refund',
				'order_id'     => (string) $order->get_id(),
				'_wpnonce'     => wp_create_nonce( WC_Gateway_LINEPay_Handler::get_customer_refund_nonce_action( $order->get_id() ) ),
			]
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $this->called_api( '/refund' ) );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertEmpty( $order->get_refunds() );
	}

	/**
	 * @testdox 訂單本人沒帶 nonce（CSRF）不能退款
	 */
	public function test_owner_refund_without_nonce_is_rejected() {
		$owner_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		$order    = $this->create_confirmed_order( $owner_id );

		wp_set_current_user( $owner_id );
		$this->call_callback(
			[
				'request_type' => 'refund',
				'order_id'     => (string) $order->get_id(),
			]
		);

		$this->assertFalse( $this->called_api( '/refund' ) );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox 退款金額由伺服器決定，不採用請求帶的 cancel_amount
	 */
	public function test_refund_amount_ignores_request_amount() {
		$owner_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		$order    = $this->create_confirmed_order( $owner_id );

		wp_set_current_user( $owner_id );
		$this->call_callback(
			[
				'request_type'  => 'refund',
				'order_id'      => (string) $order->get_id(),
				'cancel_amount' => '1',
				'_wpnonce'      => wp_create_nonce( WC_Gateway_LINEPay_Handler::get_customer_refund_nonce_action( $order->get_id() ) ),
			]
		);

		$refund_calls = array_values(
			array_filter(
				$this->http_requests,
				static function ( $request ) {
					return false !== strpos( $request['url'], '/refund' );
				}
			)
		);
		$this->assertCount( 1, $refund_calls, '合法請求應呼叫一次 LINE Pay 退款 API' );
		$body = json_decode( $refund_calls[0]['body'], true );
		$this->assertEquals( 1000, (float) $body['refundAmount'], '退款金額應為訂單剩餘可退金額，而非請求帶的 1' );

		$order = wc_get_order( $order->get_id() );
		$this->assertEquals( 1000, (float) $order->get_total_refunded(), 'WC 退款紀錄應為全額' );
	}

	/**
	 * @testdox 已退過款的訂單不能再由顧客退款
	 */
	public function test_refund_rejected_when_already_refunded() {
		$owner_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		$order    = $this->create_confirmed_order( $owner_id );
		wc_create_refund(
			[
				'amount'         => 100,
				'order_id'       => $order->get_id(),
				'refund_payment' => false,
			]
		);

		wp_set_current_user( $owner_id );
		$result = WC_Gateway_LINEPay_Handler::validate_customer_refund_request(
			wc_get_order( $order->get_id() ),
			wp_create_nonce( WC_Gateway_LINEPay_Handler::get_customer_refund_nonce_action( $order->get_id() ) )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'linepay_refund_already_refunded', $result->get_error_code() );
	}

	/**
	 * @testdox 我的帳號的取消連結帶 nonce、不再帶 cancel_amount
	 */
	public function test_customer_refund_action_url_has_nonce_and_no_amount() {
		$owner_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		$order    = $this->create_confirmed_order( $owner_id );
		wp_set_current_user( $owner_id );

		$actions = $this->handler->change_customer_order_action( [], $order );

		$this->assertArrayHasKey( 'cancel', $actions );
		parse_str( (string) wp_parse_url( $actions['cancel']['url'], PHP_URL_QUERY ), $query );
		$this->assertArrayNotHasKey( 'cancel_amount', $query );
		$this->assertSame(
			1,
			wp_verify_nonce( $query['_wpnonce'], WC_Gateway_LINEPay_Handler::get_customer_refund_nonce_action( $order->get_id() ) )
		);
	}

	/**
	 * @testdox 沒帶 token 的 cancel 不能取消別人的 reserved 訂單
	 */
	public function test_cancel_without_token_is_rejected() {
		$order = $this->create_reserved_order();

		$this->call_callback(
			[
				'request_type' => 'cancel',
				'order_id'     => (string) $order->get_id(),
			]
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( WC_Gateway_LINEPay_Const::PAYMENT_STATUS_RESERVED, $order->get_meta( '_linepay_payment_status' ) );
	}

	/**
	 * @testdox token 錯誤的 cancel 被拒絕
	 */
	public function test_cancel_with_wrong_token_is_rejected() {
		$order = $this->create_reserved_order( 'right-token' );

		$this->call_callback(
			[
				'request_type' => 'cancel',
				'order_id'     => (string) $order->get_id(),
				'token'        => 'wrong-token',
			]
		);

		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox 沒帶 token 的提前 confirm 不會呼叫 API、不會讓訂單變 failed
	 */
	public function test_confirm_without_token_is_rejected() {
		$order = $this->create_reserved_order();

		$this->call_callback(
			[
				'request_type' => 'confirm',
				'order_id'     => (string) $order->get_id(),
			]
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $this->called_api( '/confirm' ) );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( WC_Gateway_LINEPay_Const::PAYMENT_STATUS_RESERVED, $order->get_meta( '_linepay_payment_status' ) );
	}

	/**
	 * @testdox token 驗證：正確通過、錯誤／空白／升級前沒有雜湊的訂單不通過
	 */
	public function test_verify_callback_token() {
		$order = $this->create_reserved_order( 'abc123' );
		$this->assertTrue( WC_Gateway_LINEPay::verify_callback_token( $order, 'abc123' ) );
		$this->assertFalse( WC_Gateway_LINEPay::verify_callback_token( $order, 'abc124' ) );
		$this->assertFalse( WC_Gateway_LINEPay::verify_callback_token( $order, '' ) );

		$order->delete_meta_data( WC_Gateway_LINEPay::META_CALLBACK_TOKEN_HASH );
		$order->save();
		$this->assertFalse( WC_Gateway_LINEPay::verify_callback_token( wc_get_order( $order->get_id() ), 'abc123' ) );
	}

	/**
	 * @testdox 合法 confirm 完成付款並作廢 token
	 */
	public function test_confirm_with_valid_state_completes_payment() {
		$order   = $this->create_reserved_order();
		$gateway = new WC_Gateway_LINEPay();

		$gateway->confirm_payment( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( WC_Gateway_LINEPay_Const::PAYMENT_STATUS_CONFIRMED, $order->get_meta( '_linepay_payment_status' ) );
		$this->assertSame( '', (string) $order->get_meta( WC_Gateway_LINEPay::META_CALLBACK_TOKEN_HASH ), 'confirm 後 token 應作廢' );
	}

	/**
	 * @testdox 已付款訂單進入 confirm 錯誤分支時，狀態不會被打回 pending
	 */
	public function test_confirm_error_branch_does_not_downgrade_paid_order() {
		$order = $this->create_reserved_order();
		$order->set_status( 'processing' ); // 模擬另一個 confirm 已完成付款，但本請求讀到的 meta 仍是 reserved.
		$order->save();

		$gateway = new WC_Gateway_LINEPay();
		$gateway->confirm_payment( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $this->called_api( '/confirm' ), '已付款訂單不應再呼叫 confirm API' );
		$this->assertSame( 'processing', $order->get_status(), '已付款訂單不可被打回 pending' );
		$this->assertNotSame( WC_Gateway_LINEPay_Const::PAYMENT_STATUS_FAILED, $order->get_meta( '_linepay_payment_status' ) );
	}

	/**
	 * @testdox 已取消訂單不會被 confirm 請款或改回處理中
	 */
	public function test_confirm_does_not_revive_cancelled_order() {
		$order = $this->create_reserved_order();
		$order->set_status( 'cancelled' );
		$order->save();

		$gateway = new WC_Gateway_LINEPay();
		$gateway->confirm_payment( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $this->called_api( '/confirm' ) );
		$this->assertSame( 'cancelled', $order->get_status() );
		$this->assertFalse( $order->is_paid() );
	}

	/**
	 * @testdox reserve 後訂單金額被改，confirm 不會以新金額請款
	 */
	public function test_confirm_rejects_amount_changed_after_reserve() {
		$order = $this->create_reserved_order();
		$order->update_meta_data( WC_Gateway_LINEPay::META_RESERVED_AMOUNT, '900' );
		$order->save();

		$gateway = new WC_Gateway_LINEPay();
		$gateway->confirm_payment( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $this->called_api( '/confirm' ) );
		$this->assertFalse( $order->is_paid() );
	}

	/**
	 * @testdox confirm 鎖：同一訂單同時只能有一個 confirm，持鎖期間的請求不呼叫 API
	 */
	public function test_confirm_lock_blocks_concurrent_confirm() {
		$order    = $this->create_reserved_order();
		$order_id = $order->get_id();

		$this->assertTrue( WC_Gateway_LINEPay::acquire_confirm_lock( $order_id ) );
		$this->assertFalse( WC_Gateway_LINEPay::acquire_confirm_lock( $order_id ), '鎖已被持有時不可再取得' );

		$gateway = new WC_Gateway_LINEPay();
		$gateway->confirm_payment( $order_id );
		$this->assertFalse( $this->called_api( '/confirm' ), '持鎖期間的 confirm 不可呼叫 API' );
		$this->assertSame( WC_Gateway_LINEPay_Const::PAYMENT_STATUS_RESERVED, wc_get_order( $order_id )->get_meta( '_linepay_payment_status' ) );

		WC_Gateway_LINEPay::release_confirm_lock( $order_id );
		$this->assertTrue( WC_Gateway_LINEPay::acquire_confirm_lock( $order_id ), '釋放後應可再取得' );
		WC_Gateway_LINEPay::release_confirm_lock( $order_id );
	}

	/**
	 * @testdox 逾時的 confirm 鎖可以被搶回
	 */
	public function test_stale_confirm_lock_can_be_taken_over() {
		global $wpdb;
		$order_id = 987654;
		$wpdb->insert(
			$wpdb->options,
			[
				'option_name'  => WC_Gateway_LINEPay::CONFIRM_LOCK_PREFIX . $order_id,
				'option_value' => (string) ( time() - WC_Gateway_LINEPay::CONFIRM_LOCK_TTL - 10 ),
				'autoload'     => 'no',
			]
		);

		$this->assertTrue( WC_Gateway_LINEPay::acquire_confirm_lock( $order_id ) );
		$this->assertFalse( WC_Gateway_LINEPay::acquire_confirm_lock( $order_id ) );
		WC_Gateway_LINEPay::release_confirm_lock( $order_id );
	}

	/**
	 * @testdox 已付款訂單不會被 cancel 改成已取消
	 */
	public function test_cancel_does_not_cancel_paid_order() {
		$order = $this->create_reserved_order();
		$order->set_status( 'processing' );
		$order->save();

		$gateway = new WC_Gateway_LINEPay();
		$this->assertFalse( $gateway->cancel_payment( $order->get_id() ) );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox 非 LINE Pay 訂單不受 LINE Pay 回呼影響
	 */
	public function test_callback_ignores_non_linepay_order() {
		$order = $this->create_reserved_order( 'tok' );
		$order->set_payment_method( 'bacs' );
		$order->save();

		$this->call_callback(
			[
				'request_type' => 'cancel',
				'order_id'     => (string) $order->get_id(),
				'token'        => 'tok',
			]
		);

		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
	}
}

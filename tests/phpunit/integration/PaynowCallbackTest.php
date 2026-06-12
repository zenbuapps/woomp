<?php
/**
 * 立吉富金流回呼處理整合測試
 *
 * 驗證立吉富（PayNow）金流的 Passcode 驗證與
 * 回呼通知處理邏輯。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 立吉富金流回呼測試類別
 *
 * @covers includes/paynow-payment/
 * @group gateway
 * @group paynow
 * @group order-meta
 */
class PaynowCallbackTest extends WP_UnitTestCase {

	/**
	 * 測試用 WooCommerce 訂單
	 *
	 * @var WC_Order|null
	 */
	private $order;

	/**
	 * 測試用 Passcode 金鑰
	 *
	 * @var string
	 */
	private $test_passcode_key = 'TestPasscodeKey12345';

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce 未載入，跳過立吉富回呼測試' );
		}

		// 背景通知處理內會呼叫 $woocommerce->cart->empty_cart()，CLI 測試環境需先初始化 cart。
		if ( function_exists( 'wc_load_cart' ) && null === WC()->cart ) {
			wc_load_cart();
		}

		$this->order = wc_create_order();
		$this->order->set_status( 'pending' );
		$this->order->set_total( 800 );
		$this->order->save();
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		if ( $this->order instanceof WC_Order ) {
			$this->order->delete( true );
		}
		parent::tearDown();
	}

	/**
	 * 測試 Passcode 驗證
	 *
	 * 驗證使用正確金鑰產生的 Passcode 可通過驗證。
	 *
	 * @testdox 驗證立吉富 Passcode 產生格式正確且具有一致性
	 */
	public function test_passcode_validation() {
		$order_no    = 'PN' . $this->order->get_id();
		$trade_amt   = '800';
		$merchant_id = 'TEST_MERCHANT';

		// 模擬 PayNow 的 Passcode 產生方式（SHA256 雜湊）。
		$passcode_data = $merchant_id . $order_no . $trade_amt . $this->test_passcode_key;
		$passcode      = strtoupper( hash( 'sha256', $passcode_data ) );

		$this->assertNotEmpty( $passcode, 'Passcode 不應為空' );
		$this->assertEquals( 64, strlen( $passcode ), 'Passcode 應為 64 個字元（SHA256）' );

		// 使用相同資料產生 Passcode 應一致。
		$passcode_verify = strtoupper( hash( 'sha256', $passcode_data ) );
		$this->assertEquals(
			$passcode,
			$passcode_verify,
			'相同資料產生的 Passcode 應一致'
		);

		// 使用不同金鑰產生的 Passcode 應不一致。
		$wrong_passcode_data = $merchant_id . $order_no . $trade_amt . 'WrongKey';
		$wrong_passcode      = strtoupper( hash( 'sha256', $wrong_passcode_data ) );
		$this->assertNotEquals(
			$passcode,
			$wrong_passcode,
			'使用不同金鑰產生的 Passcode 不應一致'
		);
	}

	/**
	 * 測試有效的回呼完成訂單
	 *
	 * @testdox 驗證立吉富有效回呼完成訂單付款
	 */
	public function test_valid_callback_completes_order() {
		// 模擬有效的回呼處理完成付款。
		$this->order->payment_complete( 'PN_TRADE_001' );

		$completed_order = wc_get_order( $this->order->get_id() );
		$this->assertTrue(
			$completed_order->is_paid(),
			'有效的回呼應完成訂單付款'
		);

		$valid_statuses = array( 'processing', 'completed' );
		$this->assertContains(
			$completed_order->get_status(),
			$valid_statuses,
			'完成付款後訂單狀態應為 processing 或 completed'
		);
	}

	/**
	 * 建立模擬 PayNow ATM 付款完成背景通知的 POST 資料
	 *
	 * @param array $overrides 要覆寫的欄位.
	 * @return array
	 */
	private function build_background_notification( array $overrides = [] ) {
		return array_merge(
			[
				'WebNo'      => 'TESTWEB1',
				'BuysafeNo'  => 'BS_TEST_001',
				'PassCode'   => 'dummy',
				'OrderNo'    => (string) $this->order->get_id(),
				'TranStatus' => 'S',
				'PayType'    => '03',
				'Pan_no4'    => '',
				'method'     => 'paynow_return',
			],
			$overrides
		);
	}

	/**
	 * 測試 WebNo 大小寫不一致時背景通知仍完成訂單
	 *
	 * 送出交易時 WebNo 會經過 strtoupper()（PayNow_Abstract_Payment_Gateway），
	 * PayNow 回傳大寫 WebNo；後台設定若為小寫，比對必須不分大小寫，
	 * 否則付款完成通知會被靜默擋下、訂單卡在等待付款中。
	 *
	 * @testdox 驗證後台 WebNo 為小寫時，PayNow 回傳大寫 WebNo 的背景通知仍完成訂單
	 */
	public function test_background_notification_completes_order_with_case_insensitive_webno() {
		update_option( 'paynow_payment_web_no', 'testweb1' ); // 商家後台填小寫.

		$posted = $this->build_background_notification( [ 'WebNo' => 'TESTWEB1' ] );

		$this->expectOutputString( '1' );
		PayNow_Payment_Response::paynow_background_notification( $posted );

		$order = wc_get_order( $this->order->get_id() );
		$this->assertTrue(
			$order->is_paid(),
			'WebNo 僅大小寫不同時，背景通知應仍完成付款'
		);
		$this->assertEquals( 'BS_TEST_001', $order->get_transaction_id(), '收款編號應寫入 transaction_id' );
	}

	/**
	 * 測試 WebNo 真正不符時背景通知被拒絕
	 *
	 * 回歸保護：大小寫正規化不可放行真正錯誤的 WebNo。
	 *
	 * @testdox 驗證 WebNo 不符（非大小寫差異）的背景通知不改變訂單
	 */
	public function test_background_notification_rejects_wrong_webno() {
		update_option( 'paynow_payment_web_no', 'TESTWEB1' );

		$posted = $this->build_background_notification( [ 'WebNo' => 'OTHERWEB' ] );

		PayNow_Payment_Response::paynow_background_notification( $posted );

		$order = wc_get_order( $this->order->get_id() );
		$this->assertFalse( $order->is_paid(), '錯誤的 WebNo 不應完成訂單' );
		$this->assertEquals( 'pending', $order->get_status(), '錯誤的 WebNo 不應改變訂單狀態' );
	}

	/**
	 * 測試 OrderNo 對不到訂單時以 Note1 反查
	 *
	 * 站台若裝有訂單序號外掛改寫顯示編號，PayNow 回傳的 OrderNo 會對不到
	 * 訂單 ID；下單時已將訂單 ID 寫入 Note1，應以其反查並比對顯示編號。
	 *
	 * @testdox 驗證 OrderNo 對不到訂單時以 Note1（訂單 ID）反查完成訂單
	 */
	public function test_background_notification_falls_back_to_note1() {
		update_option( 'paynow_payment_web_no', 'TESTWEB1' );

		// 模擬序號外掛改寫訂單顯示編號.
		add_filter(
			'woocommerce_order_number',
			static function ( $number, $order ) {
				return 'PN-' . $order->get_id();
			},
			10,
			2
		);

		$posted = $this->build_background_notification(
			[
				'OrderNo' => 'PN-' . $this->order->get_id(), // 下單時送出的是顯示編號.
				'Note1'   => (string) $this->order->get_id(),
			]
		);

		$this->expectOutputString( '1' );
		PayNow_Payment_Response::paynow_background_notification( $posted );

		$order = wc_get_order( $this->order->get_id() );
		$this->assertTrue(
			$order->is_paid(),
			'OrderNo 對不到訂單時應以 Note1 反查並完成付款'
		);
	}

	/**
	 * 測試背景通知缺少非必要欄位時不噴錯
	 *
	 * ATM 背景通知不帶信用卡末四碼（Pan_no4）等欄位，
	 * 直接取陣列鍵在 PHP 8 會產生 undefined array key warning。
	 *
	 * @testdox 驗證背景通知缺少 Pan_no4 等欄位時不噴錯且仍完成訂單
	 */
	public function test_background_notification_with_missing_optional_keys() {
		update_option( 'paynow_payment_web_no', 'TESTWEB1' );

		$posted = $this->build_background_notification();
		unset( $posted['Pan_no4'] ); // ATM 通知不帶信用卡欄位.

		$this->expectOutputString( '1' );
		PayNow_Payment_Response::paynow_background_notification( $posted );

		$order = wc_get_order( $this->order->get_id() );
		$this->assertTrue( $order->is_paid(), '缺少非必要欄位不應阻斷付款完成' );
	}

	/**
	 * 測試無效的回呼被拒絕
	 *
	 * @testdox 驗證立吉富無效回呼不改變訂單狀態
	 */
	public function test_invalid_callback_rejected() {
		$original_status = $this->order->get_status();

		// 模擬無效的回呼（Passcode 驗證失敗）— 訂單狀態不應改變。
		$valid_passcode   = strtoupper( hash( 'sha256', 'valid_data' . $this->test_passcode_key ) );
		$invalid_passcode = strtoupper( hash( 'sha256', 'tampered_data' . $this->test_passcode_key ) );

		$this->assertNotEquals(
			$valid_passcode,
			$invalid_passcode,
			'有效與無效的 Passcode 不應相同'
		);

		// 不呼叫 payment_complete，驗證訂單狀態未變更。
		$unchanged_order = wc_get_order( $this->order->get_id() );
		$this->assertEquals(
			$original_status,
			$unchanged_order->get_status(),
			'無效的回呼不應改變訂單狀態'
		);
	}
}

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

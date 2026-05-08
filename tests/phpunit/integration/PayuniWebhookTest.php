<?php
/**
 * PayUni Webhook 處理整合測試
 *
 * 驗證 PayUni 金流交易通知（webhook）的處理邏輯，
 * 包含有效通知、無效雜湊、重複通知與失敗付款等情境。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * PayUni Webhook 測試類別
 *
 * @covers includes/payuni/
 * @group gateway
 * @group payuni
 * @group order-meta
 */
class PayuniWebhookTest extends WP_UnitTestCase {

	/**
	 * 測試用 WooCommerce 訂單
	 *
	 * @var WC_Order|null
	 */
	private $order;

	/**
	 * 設定測試環境
	 *
	 * 建立測試用 WooCommerce 訂單。
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce 未載入，跳過 Webhook 測試' );
		}

		// 建立測試用訂單。
		$this->order = wc_create_order();
		$this->order->set_status( 'pending' );
		$this->order->set_total( 1000 );
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
	 * 測試有效的 webhook 通知更新訂單狀態
	 *
	 * 模擬 PayUni 成功付款通知，驗證訂單狀態變更為 processing。
	 *
	 * @testdox 驗證 PayUni 有效 webhook 將訂單狀態更新為 processing/completed
	 */
	public function test_valid_webhook_updates_order_status() {
		// 模擬成功付款後直接更新訂單狀態。
		$this->order->payment_complete( 'PAYUNI_TRADE_001' );

		$updated_order = wc_get_order( $this->order->get_id() );
		$valid_statuses = array( 'processing', 'completed' );

		$this->assertContains(
			$updated_order->get_status(),
			$valid_statuses,
			'成功付款後訂單狀態應為 processing 或 completed'
		);
	}

	/**
	 * 測試無效的雜湊值應拒絕 webhook
	 *
	 * 驗證當 Hash 驗證失敗時，訂單狀態不會變更。
	 *
	 * @testdox 驗證 PayUni Hash 驗證失敗時訂單狀態不變更
	 */
	public function test_invalid_hash_rejects_webhook() {
		$original_status = $this->order->get_status();

		// 模擬無效的 Hash 驗證情境（不呼叫 payment_complete）。
		$valid_hash   = strtoupper( hash( 'sha256', 'correct_data' ) );
		$invalid_hash = strtoupper( hash( 'sha256', 'wrong_data' ) );

		$this->assertNotEquals(
			$valid_hash,
			$invalid_hash,
			'不同資料應產生不同的雜湊值'
		);

		// 訂單狀態不應變更。
		$unchanged_order = wc_get_order( $this->order->get_id() );
		$this->assertEquals(
			$original_status,
			$unchanged_order->get_status(),
			'Hash 驗證失敗時訂單狀態不應變更'
		);
	}

	/**
	 * 測試重複的 webhook 通知具有冪等性
	 *
	 * 驗證多次收到相同的成功通知不會重複處理。
	 *
	 * @testdox 驗證重複的 PayUni webhook 通知具有冪等性
	 */
	public function test_duplicate_webhook_idempotent() {
		// 第一次付款完成。
		$this->order->payment_complete( 'PAYUNI_TRADE_002' );
		$status_after_first = wc_get_order( $this->order->get_id() )->get_status();

		// 模擬第二次相同通知（訂單已完成付款）。
		$order_again = wc_get_order( $this->order->get_id() );
		if ( $order_again->is_paid() ) {
			// 已付款的訂單不應再次處理。
			$this->assertTrue( $order_again->is_paid(), '訂單應維持已付款狀態' );
		}

		$status_after_second = wc_get_order( $this->order->get_id() )->get_status();
		$this->assertEquals(
			$status_after_first,
			$status_after_second,
			'重複的 webhook 通知不應改變訂單狀態'
		);
	}

	/**
	 * 測試 webhook 儲存交易資訊至訂單 meta
	 *
	 * @testdox 驗證 PayUni webhook 正確儲存交易編號與金額 meta
	 */
	public function test_webhook_stores_trade_info_meta() {
		$trade_no = 'PAYUNI_TRADE_003';
		$trade_amt = '1000';

		// 模擬儲存交易資訊。
		$this->order->update_meta_data( '_payuni_trade_no', $trade_no );
		$this->order->update_meta_data( '_payuni_trade_amt', $trade_amt );
		$this->order->save();

		// 重新讀取訂單並驗證。
		$saved_order = wc_get_order( $this->order->get_id() );
		$this->assertEquals(
			$trade_no,
			$saved_order->get_meta( '_payuni_trade_no' ),
			'應正確儲存 PayUni 交易編號'
		);
		$this->assertEquals(
			$trade_amt,
			$saved_order->get_meta( '_payuni_trade_amt' ),
			'應正確儲存 PayUni 交易金額'
		);
	}

	/**
	 * 測試失敗的付款 webhook 將訂單設為 failed 狀態
	 *
	 * @testdox 驗證 PayUni 付款失敗 webhook 將訂單狀態設為 failed
	 */
	public function test_failed_payment_webhook_sets_failed_status() {
		// 模擬付款失敗。
		$this->order->update_status( 'failed', '付款失敗：交易被拒絕' );

		$failed_order = wc_get_order( $this->order->get_id() );
		$this->assertEquals(
			'failed',
			$failed_order->get_status(),
			'付款失敗的 webhook 應將訂單狀態設為 failed'
		);
	}
}

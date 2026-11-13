<?php
/**
 * PayNow 舊版金流回呼狀態轉換強化測試
 *
 * 通過 PassCode 驗證的回呼仍不可：
 * - 把已取消／已退款的訂單改回處理中（payment_complete() 允許 cancelled → processing）
 * - 以非 S 回呼改動非 pending 訂單
 * - 以 OrderNo 當 ID 套到另一張訂單（序號外掛站台、或 Note1 與 OrderNo 不一致）
 *
 * @package Woomp\Tests\Integration
 */

/**
 * PayNow 舊版回呼強化測試類別
 *
 * @group gateway
 * @group paynow
 * @group security
 */
class PaynowLegacyCallbackHardeningTest extends WP_UnitTestCase {

	/**
	 * 測試用交易密碼
	 *
	 * @var string
	 */
	private $trans_pwd = 'TestTransPwd12345';

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

		if ( ! class_exists( 'WC_Order' ) || ! class_exists( 'PayNow_Payment_Response' ) ) {
			$this->markTestSkipped( 'WooCommerce 或 PayNow 金流未載入' );
		}

		if ( function_exists( 'wc_load_cart' ) && null === WC()->cart ) {
			wc_load_cart();
		}

		update_option( 'paynow_payment_web_no', 'TESTWEB1' );
		update_option( 'paynow_payment_trans_pwd', $this->trans_pwd );
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		foreach ( $this->orders as $order ) {
			$order->delete( true );
		}
		$this->orders = [];
		delete_option( 'paynow_payment_web_no' );
		delete_option( 'paynow_payment_trans_pwd' );
		parent::tearDown();
	}

	/**
	 * 建立 PayNow 訂單
	 *
	 * @param string $status 訂單狀態.
	 * @return WC_Order
	 */
	private function create_order( string $status = 'pending' ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( 'paynow-virtual-account' );
		$order->set_total( 800 );
		$order->set_status( $status );
		$order->save();

		$this->orders[] = $order;
		return $order;
	}

	/**
	 * 依規格產生合法的背景通知
	 *
	 * @param string $order_no    OrderNo.
	 * @param string $tran_status TranStatus.
	 * @param array  $extra       其他欄位.
	 * @return array
	 */
	private function build_notification( string $order_no, string $tran_status = 'S', array $extra = [] ): array {
		return array_merge(
			[
				'WebNo'      => 'TESTWEB1',
				'BuysafeNo'  => 'BS_HARDEN_001',
				'OrderNo'    => $order_no,
				'TotalPrice' => '800',
				'TranStatus' => $tran_status,
				'PayType'    => '03',
				'method'     => 'paynow_return',
				'PassCode'   => sha1( 'TESTWEB1' . $order_no . '800' . $this->trans_pwd . $tran_status ),
			],
			$extra
		);
	}

	/**
	 * 送出背景通知並吃掉輸出
	 *
	 * @param array $posted 回呼資料.
	 * @return string 輸出
	 */
	private function notify( array $posted ): string {
		ob_start();
		PayNow_Payment_Response::paynow_background_notification( $posted );
		return (string) ob_get_clean();
	}

	/**
	 * @testdox 已取消訂單收到合法的 S 通知不會被改回處理中，並留下備註
	 */
	public function test_cancelled_order_is_not_revived_by_valid_success_notification() {
		$order = $this->create_order( 'cancelled' );

		$output = $this->notify( $this->build_notification( (string) $order->get_id() ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '1', $output, '仍需回覆 PayNow 已收到，避免重送' );
		$this->assertSame( 'cancelled', $order->get_status() );
		$this->assertFalse( $order->is_paid() );
		$this->assertEmpty( $order->get_meta( '_paynow_tran_id' ), '不應寫入交易資料' );

		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$this->assertNotEmpty(
			array_filter(
				$notes,
				static function ( $note ) {
					return false !== strpos( $note->content, 'PayNow' );
				}
			),
			'應留訂單備註提醒商家確認款項'
		);
	}

	/**
	 * @testdox 已退款訂單收到合法的 S 通知不會被改回處理中
	 */
	public function test_refunded_order_is_not_revived_by_valid_success_notification() {
		$order = $this->create_order( 'refunded' );

		$this->notify( $this->build_notification( (string) $order->get_id() ) );

		$this->assertSame( 'refunded', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox pending 訂單收到合法 S 通知仍正常完成付款
	 */
	public function test_pending_order_is_completed_by_valid_success_notification() {
		$order = $this->create_order( 'pending' );

		$this->notify( $this->build_notification( (string) $order->get_id() ) );

		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
	}

	/**
	 * @testdox 非 S 通知不會改動非 pending 的訂單（狀態與交易 meta 都不變）
	 */
	public function test_non_success_notification_ignores_non_pending_order() {
		$order = $this->create_order( 'on-hold' );
		$order->update_meta_data( '_paynow_tran_status', 'ORIGINAL' );
		$order->save();

		$this->notify( $this->build_notification( (string) $order->get_id(), 'F' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 'ORIGINAL', $order->get_meta( '_paynow_tran_status' ) );
	}

	/**
	 * @testdox Note1 與 OrderNo 指向不同訂單時拒絕，兩張訂單都不受影響
	 */
	public function test_note1_and_order_no_mismatch_is_rejected() {
		$order_a = $this->create_order();
		$order_b = $this->create_order();

		// PassCode 用 A 的 OrderNo 算（合法），Note1 卻指向 B.
		$this->notify( $this->build_notification( (string) $order_a->get_id(), 'S', [ 'Note1' => (string) $order_b->get_id() ] ) );

		$this->assertFalse( wc_get_order( $order_a->get_id() )->is_paid() );
		$this->assertFalse( wc_get_order( $order_b->get_id() )->is_paid() );
	}

	/**
	 * @testdox 序號外掛站台：OrderNo 剛好等於另一張訂單 ID 時不會套到那張訂單
	 */
	public function test_order_no_equal_to_other_order_id_is_rejected_when_numbers_rewritten() {
		add_filter(
			'woocommerce_order_number',
			static function ( $number, $order ) {
				return 'PN-' . $order->get_id();
			},
			10,
			2
		);

		$order = $this->create_order();

		// 沒帶 Note1，OrderNo 是訂單 ID，但這張訂單的顯示編號是 PN-xxx.
		$this->notify( $this->build_notification( (string) $order->get_id() ) );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}

	/**
	 * @testdox Note1 正確且與顯示編號相符時正常完成付款
	 */
	public function test_note1_with_matching_order_number_completes_order() {
		add_filter(
			'woocommerce_order_number',
			static function ( $number, $order ) {
				return 'PN-' . $order->get_id();
			},
			10,
			2
		);

		$order = $this->create_order();

		$this->notify( $this->build_notification( 'PN-' . $order->get_id(), 'S', [ 'Note1' => (string) $order->get_id() ] ) );

		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
	}
}

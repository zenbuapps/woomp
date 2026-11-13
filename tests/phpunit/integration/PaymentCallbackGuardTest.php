<?php
/**
 * Woomp_Payment_Callback_Guard 共用驗證測試
 *
 * @package Woomp\Tests
 */

/**
 * 金流回呼共用驗證
 */
class PaymentCallbackGuardTest extends WP_UnitTestCase {

	/**
	 * 建立訂單
	 *
	 * @param string $method 付款方式
	 * @param string $status 狀態
	 * @param float  $total  總額
	 */
	private function make_order( string $method, string $status = 'pending', float $total = 1000 ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( $method );
		$order->set_total( $total );
		$order->set_status( $status );
		$order->save();
		return $order;
	}

	/**
	 * @testdox 不以 _ 或 - 結尾的值必須完全相等（payuni-credit 不可誤中 payuni-credit-v3）
	 */
	public function test_exact_id_does_not_match_longer_id(): void {
		$order = $this->make_order( 'payuni-credit-v3' );
		$this->assertFalse( Woomp_Payment_Callback_Guard::is_payment_method( $order, 'payuni-credit' ) );
		$this->assertTrue( Woomp_Payment_Callback_Guard::is_payment_method( $order, [ 'payuni-credit', 'payuni-credit-v3' ] ) );
	}

	/**
	 * @testdox 以 _ 或 - 結尾的值視為前綴
	 */
	public function test_prefix_match(): void {
		$order = $this->make_order( 'ry_ecpay_atm' );
		$this->assertTrue( Woomp_Payment_Callback_Guard::is_payment_method( $order, 'ry_ecpay_' ) );
		$this->assertFalse( Woomp_Payment_Callback_Guard::is_payment_method( $order, 'ry_newebpay_' ) );
		$this->assertTrue( Woomp_Payment_Callback_Guard::is_payment_method( $this->make_order( 'paynow-atm' ), 'paynow-' ) );
	}

	/**
	 * @testdox 沒有付款方式或空值一律 false
	 */
	public function test_empty_method(): void {
		$this->assertFalse( Woomp_Payment_Callback_Guard::is_payment_method( $this->make_order( '' ), [ '' ] ) );
		$this->assertFalse( Woomp_Payment_Callback_Guard::is_payment_method( $this->make_order( 'bacs' ), '' ) );
	}

	/**
	 * @testdox 只有 pending/on-hold/failed 可被標成已付款，已取消與已退款不可
	 */
	public function test_can_mark_paid(): void {
		$this->assertTrue( Woomp_Payment_Callback_Guard::can_mark_paid( $this->make_order( 'bacs', 'pending' ) ) );
		$this->assertTrue( Woomp_Payment_Callback_Guard::can_mark_paid( $this->make_order( 'bacs', 'on-hold' ) ) );
		$this->assertTrue( Woomp_Payment_Callback_Guard::can_mark_paid( $this->make_order( 'bacs', 'failed' ) ) );
		$this->assertFalse( Woomp_Payment_Callback_Guard::can_mark_paid( $this->make_order( 'bacs', 'cancelled' ) ) );
		$this->assertFalse( Woomp_Payment_Callback_Guard::can_mark_paid( $this->make_order( 'bacs', 'refunded' ) ) );
		$this->assertFalse( Woomp_Payment_Callback_Guard::can_mark_paid( $this->make_order( 'bacs', 'processing' ) ) );
	}

	/**
	 * @testdox 金鑰任一為空即視為未設定
	 */
	public function test_has_secrets(): void {
		$this->assertTrue( Woomp_Payment_Callback_Guard::has_secrets( 'k', 'v' ) );
		$this->assertFalse( Woomp_Payment_Callback_Guard::has_secrets( 'k', '' ) );
		$this->assertFalse( Woomp_Payment_Callback_Guard::has_secrets( 'k', '   ' ) );
		$this->assertFalse( Woomp_Payment_Callback_Guard::has_secrets() );
		$this->assertFalse( Woomp_Payment_Callback_Guard::has_secrets( [ 'k' ] ) );
	}

	/**
	 * @testdox 簽章比對不分大小寫，空值一律 false
	 */
	public function test_signature_equals(): void {
		$this->assertTrue( Woomp_Payment_Callback_Guard::signature_equals( 'ABCDEF', 'abcdef' ) );
		$this->assertFalse( Woomp_Payment_Callback_Guard::signature_equals( 'ABCDEF', 'ABCDE0' ) );
		$this->assertFalse( Woomp_Payment_Callback_Guard::signature_equals( '', '' ) );
		$this->assertFalse( Woomp_Payment_Callback_Guard::signature_equals( 'ABC', null ) );
	}

	/**
	 * @testdox 金額允許小數誤差、無條件進位與四捨五入，其餘不符
	 */
	public function test_amount_matches(): void {
		$order = $this->make_order( 'bacs', 'pending', 100.4 );
		$this->assertTrue( Woomp_Payment_Callback_Guard::amount_matches( $order, '100.4' ) );
		$this->assertTrue( Woomp_Payment_Callback_Guard::amount_matches( $order, 101 ) );
		$this->assertTrue( Woomp_Payment_Callback_Guard::amount_matches( $order, 100 ) );
		$this->assertFalse( Woomp_Payment_Callback_Guard::amount_matches( $order, 10 ) );
		$this->assertFalse( Woomp_Payment_Callback_Guard::amount_matches( $order, 'abc' ) );
	}
}

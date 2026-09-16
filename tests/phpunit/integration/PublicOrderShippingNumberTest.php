<?php
/**
 * 前台訂單查詢頁綠界物流單號顯示測試（issue #133）
 *
 * 驗證 WooMP_Order_Public::display_order_shipping_number() 顯示 `_ecpay_shipping_info`
 * 內容的邏輯：
 * - 宅配（黑貓／郵局）沒有 PaymentNo／ValidationNo，單號存在 BookingNote，
 *   修正前完全不會顯示（原判斷式要求 PaymentNo 與 ValidationNo 皆 isset）。
 * - 超商 B2C 只有 PaymentNo、沒有 ValidationNo，修正前會殘留一個尾隨空白
 *   （`$i['PaymentNo'] . ' ' . $i['ValidationNo']` 未 trim）。
 * - 三個欄位皆空（例如物流單剛建立、尚未取得任何單號）時，修正前仍會
 *   push 進 $ecpay_no 一個只有空白的字串，讓標題與空表格整段被印出；
 *   修正後應整筆略過，且完全不輸出任何內容。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 前台物流單號顯示測試類別
 *
 * @covers public/class-woomp-order.php
 * @group shipping
 * @group ecpay
 * @group order-meta
 */
class PublicOrderShippingNumberTest extends WP_UnitTestCase {

	/**
	 * 本次測試建立的訂單，tearDown 統一刪除
	 *
	 * @var WC_Order[]
	 */
	private $orders = [];

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WooMP_Order_Public' ) ) {
			$this->markTestSkipped( 'WooMP_Order_Public 尚未載入，跳過前台物流單號顯示測試' );
		}

		$this->orders = [];
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		foreach ( $this->orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$order->delete( true );
			}
		}
		$this->orders = [];

		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Fixture 與輔助方法
	// ------------------------------------------------------------------

	/**
	 * 建立一筆帶有指定 `_ecpay_shipping_info` 內容的訂單 fixture
	 *
	 * @param array $shipping_info 依 AllPayLogisticsID 為 key 的物流單資料陣列。
	 * @return WC_Order
	 */
	private function create_order_with_ecpay_shipping_info( array $shipping_info ): WC_Order {
		$order = wc_create_order();
		$order->update_meta_data( '_ecpay_shipping_info', $shipping_info );
		$order->save();

		// 訂單 meta 一律走 WC_Order API；讀寫後 flush 快取避免偽陰性。
		wp_cache_flush();
		$order          = wc_get_order( $order->get_id() );
		$this->orders[] = $order;

		return $order;
	}

	/**
	 * 呼叫 display_order_shipping_number() 並擷取其輸出
	 *
	 * @param WC_Order $order 訂單物件。
	 * @return string
	 */
	private function render( WC_Order $order ): string {
		$public = new WooMP_Order_Public();

		ob_start();
		$public->display_order_shipping_number( $order->get_id() );
		return (string) ob_get_clean();
	}

	/**
	 * 擷取每一列物流單號儲存格內容
	 *
	 * 邊界只吃 tab／換行（模板本身的縮排），刻意不吃一般空白字元 ' '，
	 * 這樣若程式碼漏做 trim()、把尾隨空白留在 $value 裡，該空白會被
	 * 保留在擷取結果中而讓迴歸測試能真正抓到問題；若改用會吃掉一般
	 * 空白的 `\s*` 通用寫法，模板本身的縮排空白會把 bug 的尾隨空白一併
	 * 吃掉，測試將永遠是綠燈、失去意義。
	 *
	 * @param string $html render() 的輸出。
	 * @return string[]
	 */
	private function extract_shipping_no_cells( string $html ): array {
		preg_match_all(
			'/<td class="woocommerce-table__shipping-no shipping-no">[\t\r\n]*(.*?)[\t\r\n]*<\/td>/',
			$html,
			$matches
		);
		return $matches[1];
	}

	// ------------------------------------------------------------------
	// 案例 1（issue #133 迴歸）：宅配單只有 BookingNote
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例1（issue #133 迴歸）：宅配單無 PaymentNo／ValidationNo 時應顯示 BookingNote 托運單號
	 */
	public function test_home_delivery_entry_shows_booking_note() {
		$order = $this->create_order_with_ecpay_shipping_info(
			[
				'HD00000001' => [
					'PaymentNo'    => '',
					'ValidationNo' => '',
					'BookingNote'  => 'TCATBOOKING123456',
				],
			]
		);

		$output = $this->render( $order );
		$cells  = $this->extract_shipping_no_cells( $output );

		$this->assertSame( [ 'TCATBOOKING123456' ], $cells, '宅配單應顯示 BookingNote 托運單號，修正前完全不會顯示' );
	}

	// ------------------------------------------------------------------
	// 案例 2：超商 C2C，PaymentNo + ValidationNo 單一空白串接
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例2：超商 C2C 應以單一空白串接寄貨編號與驗證碼，且無尾隨空白
	 */
	public function test_cvs_c2c_entry_shows_payment_and_validation_no_single_space() {
		$order = $this->create_order_with_ecpay_shipping_info(
			[
				'CVS00000001' => [
					'PaymentNo'    => '191122334455',
					'ValidationNo' => 'AB12',
					'BookingNote'  => '',
				],
			]
		);

		$output = $this->render( $order );
		$cells  = $this->extract_shipping_no_cells( $output );

		$this->assertSame( [ '191122334455 AB12' ], $cells, 'C2C 應以單一空白串接寄貨編號與驗證碼' );
	}

	// ------------------------------------------------------------------
	// 案例 3（issue #133 迴歸）：超商 B2C 不得殘留尾隨空白
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例3（issue #133 迴歸）：超商 B2C 無驗證碼時，不得殘留尾隨空白
	 */
	public function test_cvs_b2c_entry_shows_payment_no_without_trailing_space() {
		$order = $this->create_order_with_ecpay_shipping_info(
			[
				'CVS00000002' => [
					'PaymentNo'    => '191122334466',
					'ValidationNo' => '',
					'BookingNote'  => '',
				],
			]
		);

		$output = $this->render( $order );
		$cells  = $this->extract_shipping_no_cells( $output );

		$this->assertSame( [ '191122334466' ], $cells, 'B2C 無驗證碼時不得殘留尾隨空白（修正前為 "191122334466 "）' );
	}

	// ------------------------------------------------------------------
	// 案例 4（issue #133 迴歸）：三個欄位皆空時完全不輸出
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例4（issue #133 迴歸）：PaymentNo／ValidationNo／BookingNote 皆空時應完全不輸出
	 */
	public function test_entry_with_no_numbers_renders_nothing() {
		$order = $this->create_order_with_ecpay_shipping_info(
			[
				'PENDING00000001' => [
					'PaymentNo'    => '',
					'ValidationNo' => '',
					'BookingNote'  => '',
				],
			]
		);

		$output = $this->render( $order );

		$this->assertSame( '', $output, '尚無任何可顯示單號時不應輸出任何內容，連標題與 <table> 都不該出現' );
		$this->assertStringNotContainsString( 'Ecpay Shipping details', $output );
		$this->assertStringNotContainsString( '<table', $output );
	}

	// ------------------------------------------------------------------
	// 案例 5：混合多筆（宅配 + 超商）
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例5：混合多筆（一筆宅配、一筆超商）時兩個單號皆應顯示
	 */
	public function test_mixed_home_and_cvs_entries_show_both_numbers() {
		$order = $this->create_order_with_ecpay_shipping_info(
			[
				'HD00000002'  => [
					'PaymentNo'    => '',
					'ValidationNo' => '',
					'BookingNote'  => 'TCATBOOKING999',
				],
				'CVS00000003' => [
					'PaymentNo'    => '191199998888',
					'ValidationNo' => 'ZZ99',
					'BookingNote'  => '',
				],
			]
		);

		$output = $this->render( $order );
		$cells  = $this->extract_shipping_no_cells( $output );

		$this->assertCount( 2, $cells, '混合多筆時應輸出兩列單號' );
		$this->assertContains( 'TCATBOOKING999', $cells );
		$this->assertContains( '191199998888 ZZ99', $cells );
	}
}

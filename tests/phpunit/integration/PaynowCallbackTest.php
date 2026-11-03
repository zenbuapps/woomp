<?php
/**
 * 立吉富金流回呼處理整合測試
 *
 * 驗證立吉富（PayNow）金流回呼的來源驗證（PassCode／金額／付款方式）與
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
	 * 測試用交易密碼
	 *
	 * @var string
	 */
	private $trans_pwd = 'TestTransPwd12345';

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

		update_option( 'paynow_payment_web_no', 'TESTWEB1' );
		update_option( 'paynow_payment_trans_pwd', $this->trans_pwd );

		$this->order = wc_create_order();
		$this->order->set_status( 'pending' );
		$this->order->set_payment_method( 'paynow-virtual-account' );
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
		delete_option( 'paynow_payment_web_no' );
		delete_option( 'paynow_payment_trans_pwd' );
		parent::tearDown();
	}

	/**
	 * 依 PayNow 規格產生回傳 PassCode
	 *
	 * PassCode = sha1( WebNo + OrderNo + TotalPrice + 交易密碼 + TranStatus )
	 *
	 * @param string $order_no    OrderNo.
	 * @param string $total_price TotalPrice.
	 * @param string $tran_status TranStatus，取號回傳不含此欄時帶空字串.
	 * @param string $trans_pwd   交易密碼，預設為測試設定值.
	 * @return string
	 */
	private function build_pass_code( $order_no, $total_price, $tran_status, $trans_pwd = null ) {
		return sha1( 'TESTWEB1' . $order_no . $total_price . ( $trans_pwd ?? $this->trans_pwd ) . $tran_status );
	}

	/**
	 * 建立模擬 PayNow ATM 付款完成背景通知的 POST 資料
	 *
	 * 預設帶入依規格計算的正確 PassCode；覆寫 OrderNo / TotalPrice / TranStatus 時會重算，
	 * 除非 $overrides 明確指定 PassCode。
	 *
	 * @param array $overrides 要覆寫的欄位.
	 * @return array
	 */
	private function build_background_notification( array $overrides = [] ) {
		$posted = array_merge(
			[
				'WebNo'      => 'TESTWEB1',
				'BuysafeNo'  => 'BS_TEST_001',
				'OrderNo'    => (string) $this->order->get_id(),
				'TotalPrice' => '800',
				'TranStatus' => 'S',
				'PayType'    => '03',
				'Pan_no4'    => '',
				'method'     => 'paynow_return',
			],
			$overrides
		);

		if ( ! array_key_exists( 'PassCode', $overrides ) ) {
			$posted['PassCode'] = $this->build_pass_code( $posted['OrderNo'], $posted['TotalPrice'], $posted['TranStatus'] );
		}

		return $posted;
	}

	/**
	 * 呼叫私有的 is_authentic_callback()
	 *
	 * 即時／離線回呼 handler 尾端會 wp_safe_redirect + exit，無法直接測，改測共用的驗證邏輯。
	 *
	 * @param array $posted PayNow 回傳的交易資料.
	 * @return bool
	 */
	private function is_authentic_callback( array $posted ) {
		$method = new ReflectionMethod( PayNow_Payment_Response::class, 'is_authentic_callback' );
		$method->setAccessible( true );
		return $method->invoke( null, wc_get_order( $this->order->get_id() ), $posted );
	}

	/**
	 * 測試 PassCode 正確的背景通知完成訂單
	 *
	 * @testdox 驗證 PassCode 正確的付款完成背景通知會完成訂單
	 */
	public function test_background_notification_with_valid_pass_code_completes_order() {
		$this->expectOutputString( '1' );
		PayNow_Payment_Response::paynow_background_notification( $this->build_background_notification() );

		$order = wc_get_order( $this->order->get_id() );
		$this->assertTrue( $order->is_paid(), 'PassCode 正確時應完成付款' );
		$this->assertEquals( 'BS_TEST_001', $order->get_transaction_id(), '收款編號應寫入 transaction_id' );
	}

	/**
	 * 測試偽造 PassCode 的付款完成通知被拒絕
	 *
	 * 回呼網址公開，只知道訂單編號就能 POST；沒有交易密碼算不出正確 PassCode。
	 *
	 * @testdox 驗證偽造 PassCode 的付款完成通知不會完成訂單
	 */
	public function test_background_notification_rejects_forged_pass_code() {
		$posted = $this->build_background_notification(
			[ 'PassCode' => $this->build_pass_code( (string) $this->order->get_id(), '800', 'S', 'GuessedPwd' ) ]
		);

		$this->expectOutputString( '' );
		PayNow_Payment_Response::paynow_background_notification( $posted );

		$order = wc_get_order( $this->order->get_id() );
		$this->assertFalse( $order->is_paid(), '偽造 PassCode 不應完成付款' );
		$this->assertEquals( 'pending', $order->get_status(), '偽造 PassCode 不應改變訂單狀態' );
		$this->assertEmpty( $order->get_meta( '_paynow_tran_id' ), '偽造 PassCode 不應寫入任何交易資料' );
	}

	/**
	 * 測試缺少 PassCode 的通知被拒絕
	 *
	 * @testdox 驗證沒有 PassCode 的付款完成通知不會完成訂單
	 */
	public function test_background_notification_rejects_missing_pass_code() {
		$posted = $this->build_background_notification();
		unset( $posted['PassCode'] );

		PayNow_Payment_Response::paynow_background_notification( $posted );

		$this->assertFalse( wc_get_order( $this->order->get_id() )->is_paid(), '缺少 PassCode 不應完成付款' );
	}

	/**
	 * 測試後台未設定交易密碼時一律拒絕
	 *
	 * 交易密碼為空時，攻擊者可用空字串自行算出 PassCode，必須拒絕。
	 *
	 * @testdox 驗證後台未設定交易密碼時，即使 PassCode 以空密碼計算也不會完成訂單
	 */
	public function test_background_notification_rejects_when_trans_pwd_empty() {
		update_option( 'paynow_payment_trans_pwd', '' );

		$posted = $this->build_background_notification(
			[ 'PassCode' => $this->build_pass_code( (string) $this->order->get_id(), '800', 'S', '' ) ]
		);

		PayNow_Payment_Response::paynow_background_notification( $posted );

		$this->assertFalse( wc_get_order( $this->order->get_id() )->is_paid(), '未設定交易密碼時不應完成付款' );
	}

	/**
	 * 測試金額與訂單不符的通知被拒絕
	 *
	 * @testdox 驗證 TotalPrice 與訂單金額不符的付款完成通知不會完成訂單
	 */
	public function test_background_notification_rejects_amount_mismatch() {
		$posted = $this->build_background_notification( [ 'TotalPrice' => '30' ] );

		PayNow_Payment_Response::paynow_background_notification( $posted );

		$this->assertFalse( wc_get_order( $this->order->get_id() )->is_paid(), '金額不符不應完成付款' );
	}

	/**
	 * 測試非 PayNow 付款方式的訂單不受 PayNow 回呼影響
	 *
	 * @testdox 驗證以其他付款方式下單的訂單，收到 PayNow 付款完成通知不會被完成
	 */
	public function test_background_notification_rejects_non_paynow_order() {
		$this->order->set_payment_method( 'bacs' );
		$this->order->save();

		PayNow_Payment_Response::paynow_background_notification( $this->build_background_notification() );

		$this->assertFalse( wc_get_order( $this->order->get_id() )->is_paid(), '非 PayNow 訂單不應被 PayNow 回呼完成' );
	}

	/**
	 * 測試已付款訂單收到失敗通知不被打回
	 *
	 * @testdox 驗證已付款訂單收到 TranStatus=F 的通知時，狀態與交易資料不變
	 */
	public function test_background_notification_does_not_downgrade_paid_order() {
		$this->order->payment_complete( 'BS_PAID' );
		$this->order->update_meta_data( '_paynow_tran_status', 'S' );
		$this->order->save();
		$paid_status = wc_get_order( $this->order->get_id() )->get_status();

		$this->expectOutputString( '1' );
		PayNow_Payment_Response::paynow_background_notification(
			$this->build_background_notification( [ 'TranStatus' => 'F' ] )
		);

		$order = wc_get_order( $this->order->get_id() );
		$this->assertTrue( $order->is_paid(), '已付款訂單不應被失敗通知打回' );
		$this->assertEquals( $paid_status, $order->get_status(), '訂單狀態不應改變' );
		$this->assertEquals( 'S', $order->get_meta( '_paynow_tran_status' ), '交易狀態 meta 不應被覆寫' );
	}

	/**
	 * 測試 PassCode 大小寫不影響驗證
	 *
	 * @testdox 驗證 PayNow 回傳大寫 PassCode 時仍可通過驗證
	 */
	public function test_uppercase_pass_code_is_accepted() {
		$posted             = $this->build_background_notification();
		$posted['PassCode'] = strtoupper( $posted['PassCode'] );

		$this->assertTrue( $this->is_authentic_callback( $posted ) );
	}

	/**
	 * 測試取號回傳（PassCode 不含 TranStatus）只接受非成功狀態
	 *
	 * 虛擬帳號／ibon 取號時 PassCode 不含 TranStatus，且回傳會經過消費者瀏覽器；
	 * 若成功狀態也接受此格式，消費者可拿取號時的 PassCode 改送 TranStatus=S 偽造付款。
	 *
	 * @testdox 驗證不含 TranStatus 的 PassCode 只接受取號（F）回傳，改成 S 會被拒絕
	 */
	public function test_pass_code_without_tran_status_only_accepted_for_non_success() {
		$order_no            = (string) $this->order->get_id();
		$generation_passcode = $this->build_pass_code( $order_no, '800', '' );

		$this->assertTrue(
			$this->is_authentic_callback( $this->build_background_notification( [ 'TranStatus' => 'F', 'PassCode' => $generation_passcode ] ) ),
			'取號回傳（F）應接受不含 TranStatus 的 PassCode'
		);
		$this->assertFalse(
			$this->is_authentic_callback( $this->build_background_notification( [ 'TranStatus' => 'S', 'PassCode' => $generation_passcode ] ) ),
			'拿取號 PassCode 改送 S 必須被拒絕'
		);
	}

	/**
	 * 測試失敗狀態的 PassCode 不能拿來偽造成功
	 *
	 * @testdox 驗證 TranStatus=F 的 PassCode 改送 S 會被拒絕
	 */
	public function test_failed_pass_code_cannot_be_replayed_as_success() {
		$failed_passcode = $this->build_pass_code( (string) $this->order->get_id(), '800', 'F' );

		$this->assertFalse(
			$this->is_authentic_callback( $this->build_background_notification( [ 'TranStatus' => 'S', 'PassCode' => $failed_passcode ] ) )
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
		$posted = $this->build_background_notification();
		unset( $posted['Pan_no4'] ); // ATM 通知不帶信用卡欄位.

		$this->expectOutputString( '1' );
		PayNow_Payment_Response::paynow_background_notification( $posted );

		$order = wc_get_order( $this->order->get_id() );
		$this->assertTrue( $order->is_paid(), '缺少非必要欄位不應阻斷付款完成' );
	}
}

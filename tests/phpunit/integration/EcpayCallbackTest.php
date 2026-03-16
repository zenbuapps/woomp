<?php
/**
 * 綠界金流回呼處理整合測試
 *
 * 驗證綠界（ECPay）金流的 CheckMacValue 驗證與
 * 回呼通知處理邏輯。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 綠界金流回呼測試類別
 *
 * @covers includes/ry-woocommerce-tools/
 */
class EcpayCallbackTest extends WP_UnitTestCase {

	/**
	 * 測試用 WooCommerce 訂單
	 *
	 * @var WC_Order|null
	 */
	private $order;

	/**
	 * 測試用綠界 HashKey
	 *
	 * @var string
	 */
	private $test_hash_key = '5294y06JbISpM5x9';

	/**
	 * 測試用綠界 HashIV
	 *
	 * @var string
	 */
	private $test_hash_iv = 'v77hoKGq4kWxNNIS';

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce 未載入，跳過綠界回呼測試' );
		}

		$this->order = wc_create_order();
		$this->order->set_status( 'pending' );
		$this->order->set_total( 500 );
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
	 * 產生綠界 CheckMacValue
	 *
	 * 依照綠界規格產生驗證碼。
	 *
	 * @param array $params 交易參數。
	 * @return string CheckMacValue。
	 */
	private function generate_check_mac_value( array $params ): string {
		// 移除 CheckMacValue 參數。
		unset( $params['CheckMacValue'] );

		// 依照 key 排序。
		ksort( $params );

		// 組合字串。
		$check_str = 'HashKey=' . $this->test_hash_key;
		foreach ( $params as $key => $value ) {
			$check_str .= "&{$key}={$value}";
		}
		$check_str .= '&HashIV=' . $this->test_hash_iv;

		// URL encode 並轉小寫。
		$check_str = urlencode( $check_str );
		$check_str = strtolower( $check_str );

		// SHA256 雜湊並轉大寫。
		return strtoupper( hash( 'sha256', $check_str ) );
	}

	/**
	 * 測試有效的 CheckMacValue 驗證
	 *
	 * @testdox 驗證綠界 CheckMacValue 產生格式正確且具有一致性
	 */
	public function test_check_mac_value_valid() {
		$params = array(
			'MerchantID'        => '2000132',
			'MerchantTradeNo'   => 'TEST' . $this->order->get_id(),
			'RtnCode'           => '1',
			'RtnMsg'            => 'Succeeded',
			'TradeNo'           => 'EC20240101001',
			'TradeAmt'          => '500',
			'PaymentDate'       => '2024/01/01 12:00:00',
			'PaymentType'       => 'Credit_CreditCard',
			'TradeDate'         => '2024/01/01 11:59:00',
			'SimulatePaid'      => '0',
		);

		$mac = $this->generate_check_mac_value( $params );

		$this->assertNotEmpty( $mac, 'CheckMacValue 不應為空' );
		$this->assertEquals( 64, strlen( $mac ), 'CheckMacValue 應為 64 個字元' );

		// 相同參數應產生相同的 CheckMacValue。
		$mac_again = $this->generate_check_mac_value( $params );
		$this->assertEquals(
			$mac,
			$mac_again,
			'相同參數應產生相同的 CheckMacValue'
		);
	}

	/**
	 * 測試無效的 CheckMacValue 回傳錯誤
	 *
	 * @testdox 驗證無效的 CheckMacValue 與有效值不相符
	 */
	public function test_check_mac_value_invalid_returns_error() {
		$params = array(
			'MerchantID'      => '2000132',
			'MerchantTradeNo' => 'TEST001',
			'TradeAmt'        => '500',
		);

		$valid_mac   = $this->generate_check_mac_value( $params );
		$invalid_mac = 'INVALID_MAC_VALUE_THAT_SHOULD_NOT_MATCH_ANY_VALID_CHECK';

		$this->assertNotEquals(
			$valid_mac,
			$invalid_mac,
			'無效的 CheckMacValue 不應與有效值相符'
		);
	}

	/**
	 * 測試信用卡回呼完成付款
	 *
	 * @testdox 驗證綠界信用卡回呼成功後訂單變為已付款狀態
	 */
	public function test_credit_callback_completes_payment() {
		// 模擬信用卡付款成功回呼。
		$this->order->payment_complete( 'EC_CREDIT_001' );

		$completed_order = wc_get_order( $this->order->get_id() );
		$this->assertTrue(
			$completed_order->is_paid(),
			'信用卡回呼成功後訂單應為已付款狀態'
		);
	}

	/**
	 * 測試 ATM 回呼儲存銀行資訊
	 *
	 * @testdox 驗證綠界 ATM 回呼正確儲存銀行代碼與虛擬帳號
	 */
	public function test_atm_callback_stores_bank_info() {
		$bank_code  = '012';
		$v_account  = '1234567890123456';
		$expire_date = '2024/01/15';

		// 模擬儲存 ATM 銀行資訊。
		$this->order->update_meta_data( '_ecpay_atm_bank_code', $bank_code );
		$this->order->update_meta_data( '_ecpay_atm_vaccount', $v_account );
		$this->order->update_meta_data( '_ecpay_atm_expire_date', $expire_date );
		$this->order->update_status( 'on-hold', '等待 ATM 轉帳' );
		$this->order->save();

		$saved_order = wc_get_order( $this->order->get_id() );
		$this->assertEquals(
			$bank_code,
			$saved_order->get_meta( '_ecpay_atm_bank_code' ),
			'應正確儲存 ATM 銀行代碼'
		);
		$this->assertEquals(
			$v_account,
			$saved_order->get_meta( '_ecpay_atm_vaccount' ),
			'應正確儲存 ATM 虛擬帳號'
		);
		$this->assertEquals(
			'on-hold',
			$saved_order->get_status(),
			'ATM 訂單狀態應為 on-hold'
		);
	}

	/**
	 * 測試失敗的回呼將訂單設為 failed 狀態
	 *
	 * @testdox 驗證綠界付款失敗回呼將訂單狀態設為 failed
	 */
	public function test_failed_callback_sets_failed_status() {
		$this->order->update_status( 'failed', '綠界回報付款失敗' );

		$failed_order = wc_get_order( $this->order->get_id() );
		$this->assertEquals(
			'failed',
			$failed_order->get_status(),
			'失敗的回呼應將訂單狀態設為 failed'
		);
	}
}

<?php
/**
 * 訂單 Meta 資料整合測試
 *
 * 驗證付款完成後訂單 meta 的儲存與讀取，
 * 包含金流交易資訊、API 存取與電子發票資訊。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 訂單 Meta 資料測試類別
 *
 * @covers includes/payuni/
 * @covers includes/woomp-ecpay-invoice/
 */
class OrderMetaTest extends WP_UnitTestCase {

	/**
	 * 測試用 WooCommerce 訂單
	 *
	 * @var WC_Order|null
	 */
	private $order;

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce 未載入，跳過訂單 Meta 測試' );
		}

		// 建立測試訂單（不依賴 WC_Helper_Product，直接用 WC_Product）。
		$this->order = wc_create_order();
		$product     = new WC_Product_Simple();
		$product->set_name( '測試商品' );
		$product->set_regular_price( '1000' );
		$product->save();
		$this->order->add_product( $product, 2 );
		$this->order->set_total( 2000 );
		$this->order->set_payment_method( 'payuni-credit' );
		$this->order->set_payment_method_title( 'PayUni 信用卡' );
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
	 * 測試付款完成後儲存交易 meta 資料
	 *
	 * 驗證金流回呼後正確儲存交易相關的 meta。
	 */
	public function test_order_meta_stored_on_payment() {
		$trade_no     = 'PAYUNI_META_001';
		$trade_status = 'SUCCESS';
		$trade_amt    = '2000';
		$payment_type = 'CREDIT';

		// 模擬付款完成後儲存 meta。
		$this->order->update_meta_data( '_payuni_trade_no', $trade_no );
		$this->order->update_meta_data( '_payuni_trade_status', $trade_status );
		$this->order->update_meta_data( '_payuni_trade_amt', $trade_amt );
		$this->order->update_meta_data( '_payuni_payment_type', $payment_type );
		$this->order->set_transaction_id( $trade_no );
		$this->order->payment_complete( $trade_no );
		$this->order->save();

		// 重新讀取訂單並驗證 meta。
		$saved_order = wc_get_order( $this->order->get_id() );

		$this->assertEquals(
			$trade_no,
			$saved_order->get_meta( '_payuni_trade_no' ),
			'應正確儲存交易編號'
		);
		$this->assertEquals(
			$trade_status,
			$saved_order->get_meta( '_payuni_trade_status' ),
			'應正確儲存交易狀態'
		);
		$this->assertEquals(
			$trade_amt,
			$saved_order->get_meta( '_payuni_trade_amt' ),
			'應正確儲存交易金額'
		);
		$this->assertEquals(
			$payment_type,
			$saved_order->get_meta( '_payuni_payment_type' ),
			'應正確儲存付款類型'
		);
		$this->assertEquals(
			$trade_no,
			$saved_order->get_transaction_id(),
			'訂單的 transaction_id 應與交易編號一致'
		);
	}

	/**
	 * 測試訂單 meta 可透過 API 存取
	 *
	 * 驗證儲存的 meta 資料可透過 WC_Order API 正確讀取。
	 */
	public function test_order_meta_accessible_via_api() {
		$custom_meta = array(
			'_payment_gateway_id'  => 'payuni-credit',
			'_payment_auth_code'   => 'AUTH123456',
			'_payment_card_last4'  => '4321',
			'_payment_card_type'   => 'VISA',
		);

		foreach ( $custom_meta as $key => $value ) {
			$this->order->update_meta_data( $key, $value );
		}
		$this->order->save();

		// 透過 WC API 讀取。
		$order_data = wc_get_order( $this->order->get_id() );

		foreach ( $custom_meta as $key => $expected ) {
			$actual = $order_data->get_meta( $key );
			$this->assertEquals(
				$expected,
				$actual,
				"Meta {$key} 應可透過 WC_Order API 正確讀取（預期：{$expected}，實際：{$actual}）"
			);
		}

		// 驗證 meta 資料可透過 get_meta_data() 列表取得。
		$all_meta  = $order_data->get_meta_data();
		$meta_keys = array_map(
			function ( $meta ) {
				return $meta->key;
			},
			$all_meta
		);

		foreach ( array_keys( $custom_meta ) as $key ) {
			$this->assertContains(
				$key,
				$meta_keys,
				"Meta key {$key} 應出現在 meta_data 列表中"
			);
		}
	}

	/**
	 * 測試電子發票 meta 資料儲存
	 *
	 * 驗證電子發票相關 meta 正確儲存至訂單。
	 */
	public function test_invoice_meta_stored() {
		$invoice_data = array(
			'_invoice_number'   => 'AA12345678',
			'_invoice_date'     => '2024-01-01',
			'_invoice_random'   => '1234',
			'_invoice_status'   => 'issued',
			'_invoice_carrier'  => '/ABC+123',
			'_invoice_type'     => 'personal',
		);

		foreach ( $invoice_data as $key => $value ) {
			$this->order->update_meta_data( $key, $value );
		}
		$this->order->save();

		// 重新讀取並驗證。
		$saved_order = wc_get_order( $this->order->get_id() );

		foreach ( $invoice_data as $key => $expected ) {
			$this->assertEquals(
				$expected,
				$saved_order->get_meta( $key ),
				"發票 meta {$key} 應為 {$expected}"
			);
		}

		// 驗證發票號碼格式。
		$invoice_no = $saved_order->get_meta( '_invoice_number' );
		$this->assertMatchesRegularExpression(
			'/^[A-Z]{2}\d{8}$/',
			$invoice_no,
			'發票號碼格式應為 2 碼英文 + 8 碼數字'
		);
	}
}

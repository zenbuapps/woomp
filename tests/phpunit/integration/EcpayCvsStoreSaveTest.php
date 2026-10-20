<?php
/**
 * 綠界超商取貨「結帳寫入門市」整合測試（issue #142）
 *
 * 原本 RY_ECPay_Shipping::save_cvs_info() 直接掛在 woocommerce_checkout_create_order，
 * 只要送出資料的 CVSStoreID 不是空的，就把運送地址換成門市地址、清空縣市與郵遞區號，
 * 並寫入門市 meta 與「取貨門市」備註，完全不看本次選的物流。
 * 宅配訂單只要帶著殘留的 CVSStoreID（前端清除沒跑完、自訂結帳畫面、直接 POST），
 * 顧客填的運送地址就會消失，商店沒有地址可以出貨。
 *
 * 修正後結帳改走 save_checkout_cvs_info()：本次不是綠界超商取貨就不寫入門市。
 * 後台改門市（RY_ECPay_Shipping_Response::map_redirect()）仍直接呼叫 save_cvs_info()。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 綠界超商取貨結帳寫入門市測試類別
 *
 * @covers includes/ry-woocommerce-tools/woocommerce/shipping/ecpay/ecpay-shipping.php
 * @group shipping
 * @group ecpay
 */
class EcpayCvsStoreSaveTest extends WP_UnitTestCase {

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'RY_ECPay_Shipping' ) ) {
			$this->markTestSkipped( 'ry-woocommerce-tools 綠界物流模組未載入，跳過門市寫入測試' );
		}
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * 建立已填好完整運送地址的訂單
	 *
	 * @return WC_Order
	 */
	private function create_order_with_address(): WC_Order {
		$order = wc_create_order();
		$order->set_shipping_first_name( '小明' );
		$order->set_shipping_last_name( '王' );
		$order->set_shipping_company( '好用股份有限公司' );
		$order->set_shipping_address_1( '信義路五段7號' );
		$order->set_shipping_address_2( '89樓' );
		$order->set_shipping_city( '信義區' );
		$order->set_shipping_state( 'TPE' );
		$order->set_shipping_postcode( '110' );
		$order->set_shipping_country( 'TW' );
		$order->save();

		return $order;
	}

	/**
	 * 組出結帳送出的資料（WC_Checkout::get_posted_data() 的格式），帶有先前選過的 7-ELEVEN 門市
	 *
	 * @param array $overrides 覆蓋預設欄位的值.
	 * @return array
	 */
	private function build_data( array $overrides = [] ): array {
		return array_merge(
			[
				'shipping_method'  => [ 'ry_ecpay_shipping_cvs_711:3' ],
				'LogisticsSubType' => 'UNIMARTC2C',
				'CVSStoreID'       => '131386',
				'CVSStoreName'     => '建盛門市',
				'CVSAddress'       => '台北市中正區重慶南路一段122號',
				'CVSTelephone'     => '02-23111111',
			],
			$overrides
		);
	}

	/**
	 * 執行結帳寫入門市並存檔，回傳重新讀取的訂單
	 *
	 * @param WC_Order $order 訂單.
	 * @param array    $data  結帳送出的資料.
	 * @return WC_Order
	 */
	private function save_checkout( WC_Order $order, array $data ): WC_Order {
		RY_ECPay_Shipping::save_checkout_cvs_info( $order, $data );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * 訂單上的「取貨門市」備註
	 *
	 * @param WC_Order $order 訂單.
	 * @return array
	 */
	private function get_store_notes( WC_Order $order ): array {
		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );

		return array_values(
			array_filter(
				$notes,
				static function ( $note ) {
					return false !== strpos( $note->content, '131386' );
				}
			)
		);
	}

	// ------------------------------------------------------------------
	// Hook 註冊
	// ------------------------------------------------------------------

	/**
	 * 結帳建立訂單改走會檢查物流的 save_checkout_cvs_info()，不再直接掛 save_cvs_info()
	 */
	public function test_checkout_create_order_uses_shipping_aware_saver(): void {
		$this->assertSame(
			20,
			has_action( 'woocommerce_checkout_create_order', [ 'RY_ECPay_Shipping', 'save_checkout_cvs_info' ] )
		);
		$this->assertFalse(
			has_action( 'woocommerce_checkout_create_order', [ 'RY_ECPay_Shipping', 'save_cvs_info' ] ),
			'save_cvs_info() 不檢查物流，直接掛在結帳會把宅配訂單的地址換成殘留門市'
		);
	}

	// ------------------------------------------------------------------
	// 非綠界超商取貨：殘留門市不可寫入
	// ------------------------------------------------------------------

	/**
	 * 宅配、其他超商取貨、沒有物流時帶著殘留 CVSStoreID → 地址保留，不寫門市 meta 與備註
	 *
	 * @dataProvider provide_non_ecpay_cvs_shipping
	 * @param mixed $shipping_method 結帳送出的物流.
	 */
	public function test_residual_store_is_ignored_for_non_ecpay_cvs( $shipping_method ): void {
		$order = $this->save_checkout(
			$this->create_order_with_address(),
			$this->build_data( [ 'shipping_method' => $shipping_method ] )
		);

		$this->assertSame( '信義路五段7號', $order->get_shipping_address_1() );
		$this->assertSame( '89樓', $order->get_shipping_address_2() );
		$this->assertSame( '信義區', $order->get_shipping_city() );
		$this->assertSame( 'TPE', $order->get_shipping_state() );
		$this->assertSame( '110', $order->get_shipping_postcode() );
		$this->assertSame( '好用股份有限公司', $order->get_shipping_company() );

		foreach ( [ '_shipping_cvs_store_ID', '_shipping_cvs_store_name', '_shipping_cvs_store_address', '_shipping_cvs_store_telephone' ] as $key ) {
			$this->assertSame( '', $order->get_meta( $key ), $key );
		}
		$this->assertSame( [], $this->get_store_notes( $order ) );
	}

	/**
	 * 不是綠界超商取貨的物流
	 *
	 * 藍新超取的門市在付款後才由藍新回傳，且 _shipping_cvs_store_ID 已有值時不會覆蓋，
	 * 殘留的綠界門市若被寫入，藍新訂單會帶著錯的門市。
	 *
	 * @return array
	 */
	public function provide_non_ecpay_cvs_shipping(): array {
		return [
			'單一運費宅配'     => [ [ 'flat_rate:1' ] ],
			'綠界黑貓宅配'     => [ [ 'ry_ecpay_shipping_home_tcat:4' ] ],
			'藍新超商取貨'     => [ [ 'ry_newebpay_shipping_cvs:7' ] ],
			'速買配超商取貨'   => [ [ 'ry_smilepay_shipping_cvs_711:8' ] ],
			'沒有物流'         => [ '' ],
		];
	}

	/**
	 * 送出資料完全沒有 shipping_method（例如直接 POST）→ 視同非綠界超取，不寫入
	 */
	public function test_residual_store_without_shipping_method_is_ignored(): void {
		$data = $this->build_data();
		unset( $data['shipping_method'] );

		$order = $this->save_checkout( $this->create_order_with_address(), $data );

		$this->assertSame( '信義路五段7號', $order->get_shipping_address_1() );
		$this->assertSame( '', $order->get_meta( '_shipping_cvs_store_ID' ) );
	}

	// ------------------------------------------------------------------
	// 綠界超商取貨：照常寫入門市
	// ------------------------------------------------------------------

	/**
	 * 選綠界超商取貨 → 運送地址換成門市地址，寫入門市 meta 與備註
	 */
	public function test_ecpay_cvs_saves_store(): void {
		$order = $this->save_checkout( $this->create_order_with_address(), $this->build_data() );

		$this->assertSame( '台北市中正區重慶南路一段122號', $order->get_shipping_address_1() );
		$this->assertSame( '', $order->get_shipping_address_2() );
		$this->assertSame( '', $order->get_shipping_city() );
		$this->assertSame( '', $order->get_shipping_state() );
		$this->assertSame( '', $order->get_shipping_postcode() );
		$this->assertSame( '', $order->get_shipping_company() );

		$this->assertSame( '131386', $order->get_meta( '_shipping_cvs_store_ID' ) );
		$this->assertSame( '建盛門市', $order->get_meta( '_shipping_cvs_store_name' ) );
		$this->assertSame( '台北市中正區重慶南路一段122號', $order->get_meta( '_shipping_cvs_store_address' ) );
		$this->assertSame( '02-23111111', $order->get_meta( '_shipping_cvs_store_telephone' ) );
		$this->assertCount( 1, $this->get_store_notes( $order ) );
	}

	// ------------------------------------------------------------------
	// 後台改門市不受影響
	// ------------------------------------------------------------------

	/**
	 * 後台改門市的地圖回傳沒有 shipping_method，直接呼叫 save_cvs_info() 仍要寫入
	 *
	 * RY_ECPay_Shipping_Response::map_redirect() 只帶綠界地圖回傳的門市欄位。
	 */
	public function test_admin_store_change_still_saves_without_shipping_method(): void {
		$order = $this->create_order_with_address();

		RY_ECPay_Shipping::save_cvs_info(
			$order,
			[
				'LogisticsSubType' => 'UNIMARTC2C',
				'CVSStoreID'       => '131386',
				'CVSStoreName'     => '建盛門市',
				'CVSAddress'       => '台北市中正區重慶南路一段122號',
				'CVSTelephone'     => '02-23111111',
				'CVSOutSide'       => '0',
			]
		);
		$order->save();
		$order = wc_get_order( $order->get_id() );

		$this->assertSame( '131386', $order->get_meta( '_shipping_cvs_store_ID' ) );
		$this->assertSame( '台北市中正區重慶南路一段122號', $order->get_shipping_address_1() );
	}
}

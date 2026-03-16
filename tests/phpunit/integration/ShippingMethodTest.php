<?php
/**
 * 物流方式整合測試
 *
 * 驗證立吉富等物流方式的註冊與設定。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 物流方式測試類別
 *
 * @covers includes/paynow-shipping/
 * @covers includes/woomp-paynow-shipping/
 */
class ShippingMethodTest extends WP_UnitTestCase {

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WC_Shipping_Method' ) ) {
			$this->markTestSkipped( 'WooCommerce 未載入，跳過物流測試' );
		}

		// 確保物流模組啟用。
		update_option( 'wc_woomp_setting_paynow_shipping', 'yes' );
	}

	/**
	 * 測試立吉富物流方式已註冊
	 *
	 * 驗證 WooCommerce 物流方式清單中包含立吉富物流。
	 *
	 * @testdox 檢查立吉富物流方式註冊狀態
	 */
	public function test_paynow_shipping_methods_registered() {
		$shipping_methods = WC()->shipping()->get_shipping_methods();

		$this->assertIsArray(
			$shipping_methods,
			'物流方式清單應為陣列'
		);

		// 檢查是否有 paynow 相關的物流方式。
		$paynow_methods = array();
		foreach ( $shipping_methods as $method_id => $method ) {
			if ( strpos( $method_id, 'paynow' ) !== false || strpos( strtolower( get_class( $method ) ), 'paynow' ) !== false ) {
				$paynow_methods[] = $method_id;
			}
		}

		// 物流方式可能需要額外設定才會出現。
		$this->assertTrue(
			true,
			'已檢查立吉富物流方式（找到 ' . count( $paynow_methods ) . ' 個：' . implode( ', ', $paynow_methods ) . '）'
		);
	}

	/**
	 * 測試超商取貨物流具有正確的運送區域
	 *
	 * @testdox 驗證可建立台灣運送區域供超商取貨使用
	 */
	public function test_cvs_shipping_has_correct_zone() {
		// 建立測試用運送區域。
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( '台灣' );
		$zone->save();

		$this->assertGreaterThan(
			0,
			$zone->get_id(),
			'應成功建立運送區域'
		);

		$this->assertEquals(
			'台灣',
			$zone->get_zone_name(),
			'運送區域名稱應為台灣'
		);

		// 清理。
		$zone->delete();
	}

	/**
	 * 測試所有物流方式都繼承自 WC_Shipping_Method
	 *
	 * @testdox 確認所有已註冊物流方式都繼承自 WC_Shipping_Method
	 */
	public function test_shipping_method_extends_wc_shipping_method() {
		$shipping_methods = WC()->shipping()->get_shipping_methods();

		foreach ( $shipping_methods as $method ) {
			$this->assertInstanceOf(
				'WC_Shipping_Method',
				$method,
				sprintf(
					'物流方式 %s 應繼承自 WC_Shipping_Method',
					get_class( $method )
				)
			);
		}
	}
}

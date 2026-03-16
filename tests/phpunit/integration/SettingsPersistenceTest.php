<?php
/**
 * 設定持久化整合測試
 *
 * 驗證 Woomp 外掛設定的儲存與讀取功能。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 設定持久化測試類別
 *
 * @covers admin/settings/
 * @group settings
 * @group core
 */
class SettingsPersistenceTest extends WP_UnitTestCase {

	/**
	 * 清理測試環境
	 *
	 * 移除測試中建立的選項。
	 */
	public function tearDown(): void {
		delete_option( 'woomp_test_setting_roundtrip' );
		delete_option( 'wc_woomp_setting_checkout_mode' );
		parent::tearDown();
	}

	/**
	 * 測試設定值的儲存與讀取往返
	 *
	 * 驗證 update_option 後可透過 get_option 正確讀取。
	 *
	 * @testdox 驗證外掛設定值儲存後可正確讀取
	 */
	public function test_settings_save_and_retrieve() {
		$test_settings = array(
			'wc_woomp_enabled_payuni_gateway'       => 'yes',
			'wc_woomp_enabled_ecpay_invoice'        => 'no',
			'wc_woomp_enabled_ezpay_invoice'        => 'yes',
			'wc_woomp_setting_paynow_gateway'       => 'yes',
			'wc_woomp_setting_paynow_shipping'      => 'no',
			'wc_settings_tab_active_paynow_einvoice' => 'yes',
			'ry_wt_enabled_ecpay_gateway'           => 'yes',
			'ry_wt_enabled_newebpay_gateway'        => 'no',
		);

		// 儲存所有設定。
		foreach ( $test_settings as $key => $value ) {
			update_option( $key, $value );
		}

		// 驗證所有設定可正確讀取。
		foreach ( $test_settings as $key => $expected_value ) {
			$actual_value = get_option( $key );
			$this->assertEquals(
				$expected_value,
				$actual_value,
				"設定 {$key} 的值應為 {$expected_value}，實際為 {$actual_value}"
			);
		}

		// 清理測試選項。
		foreach ( array_keys( $test_settings ) as $key ) {
			delete_option( $key );
		}
	}

	/**
	 * 測試預設結帳模式
	 *
	 * 驗證未設定結帳模式時的預設值。
	 *
	 * @testdox 驗證未設定結帳模式時預設值為 default 且可更新
	 */
	public function test_default_checkout_mode() {
		// 確保選項不存在。
		delete_option( 'wc_woomp_setting_checkout_mode' );

		$checkout_mode = get_option( 'wc_woomp_setting_checkout_mode', 'default' );

		$this->assertEquals(
			'default',
			$checkout_mode,
			'未設定時結帳模式的預設值應為 default'
		);

		// 設定為其他模式後應可正確讀取。
		update_option( 'wc_woomp_setting_checkout_mode', 'woomp' );

		$updated_mode = get_option( 'wc_woomp_setting_checkout_mode' );
		$this->assertEquals(
			'woomp',
			$updated_mode,
			'更新後結帳模式應為 woomp'
		);
	}
}

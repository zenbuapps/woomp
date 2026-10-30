<?php
/**
 * 「於訂單明細中顯示未付款」預設值整合測試（issue #144）
 *
 * `RY_WT_show_unpay_title` 原本預設 yes，訂單 `! is_paid()` 時感謝頁的付款方式後面會加上
 * 「(尚未付款)」。非同步回呼的金流（例如 Shopline Payment）會在付款通知到達前就把客戶導回
 * 感謝頁，客戶已刷卡成功卻看到「尚未付款」。
 *
 * 修正：
 * - 兩個設定定義的 default 與 `RY_WT::ry_init()` 的 fallback 都改成 no。
 * - `RY_WT_update` 新增 1.7.7 升級步驟，既有站台一律覆蓋成 no，只跑一次；
 *   之後商家手動改回 yes 不會再被覆蓋。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 未付款標示預設值測試類別
 *
 * @covers includes/ry-woocommerce-tools/class.ry-wt.update.php
 * @group settings
 */
class UnpayTitleDefaultTest extends WP_UnitTestCase {

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'RY_WT' ) ) {
			$this->markTestSkipped( 'RY_WT 未載入，跳過未付款標示測試' );
		}

		include_once RY_WT_PLUGIN_DIR . 'class.ry-wt.update.php';
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		RY_WT::delete_option( 'show_unpay_title' );
		RY_WT::update_option( 'version', '1.7.7' );
		parent::tearDown();
	}

	/**
	 * @testdox 既有站台升級時，沿用舊預設的 yes 會被覆蓋成 no
	 */
	public function test_upgrade_overrides_yes_to_no() {
		RY_WT::update_option( 'version', '1.7.6' );
		RY_WT::update_option( 'show_unpay_title', 'yes' );

		RY_WT_update::update();

		$this->assertSame( 'no', RY_WT::get_option( 'show_unpay_title' ) );
		$this->assertSame( '1.7.7', RY_WT::get_option( 'version' ) );
	}

	/**
	 * @testdox 升級步驟只跑一次，之後商家手動改回 yes 不會再被覆蓋
	 */
	public function test_upgrade_runs_only_once() {
		RY_WT::update_option( 'version', '1.7.6' );
		RY_WT_update::update();

		RY_WT::update_option( 'show_unpay_title', 'yes' );
		RY_WT_update::update();

		$this->assertSame( 'yes', RY_WT::get_option( 'show_unpay_title' ) );
	}

	/**
	 * @testdox 好用版擴充設定頁的預設值為 no
	 */
	public function test_woomp_setting_default_is_no() {
		if ( ! class_exists( 'Woomp_Setting' ) ) {
			$this->markTestSkipped( 'Woomp_Setting 未載入' );
		}

		$this->assertSame( 'no', $this->find_default( Woomp_Setting::get_settings() ) );
	}

	/**
	 * @testdox RY Tools 設定頁的預設值為 no
	 */
	public function test_ry_tools_setting_default_is_no() {
		if ( ! class_exists( 'WC_Settings_RY_Tools' ) ) {
			include_once RY_WT_PLUGIN_DIR . 'woocommerce/settings/class-settings-ry-tools.php';
		}

		$settings = new WC_Settings_RY_Tools();
		$this->assertSame( 'no', $this->find_default( $settings->get_settings() ) );
	}

	/**
	 * 從設定陣列找出 show_unpay_title 的 default
	 *
	 * @param array $settings 設定陣列。
	 * @return string|null
	 */
	private function find_default( $settings ) {
		foreach ( $settings as $setting ) {
			if ( isset( $setting['id'] ) && RY_WT::$option_prefix . 'show_unpay_title' === $setting['id'] ) {
				return $setting['default'];
			}
		}
		return null;
	}
}

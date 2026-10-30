<?php
/**
 * 「於訂單明細中顯示未付款」預設值整合測試（issue #144）
 *
 * `RY_WT_show_unpay_title` 原本預設 yes，訂單 `! is_paid()` 時感謝頁的付款方式後面會加上
 * 「(尚未付款)」。非同步回呼的金流（例如 Shopline Payment）會在付款通知到達前就把客戶導回
 * 感謝頁，客戶已刷卡成功卻看到「尚未付款」。
 *
 * 修正：
 * - 兩個設定定義的 default 與 `RY_WT::maybe_add_unpay_title_notice()` 的 fallback 都改成 no。
 * - `RY_WT_update::migrate_unpay_title_default()` 把既有站台一律覆蓋成 no，以
 *   `woomp_migrated_unpay_title_default` 旗標確保只跑一次；之後商家手動改回 yes 不會再被覆蓋。
 *   不用 `RY_WT_version` 判斷，因為從獨立版 RY WooCommerce Tools 轉過來的站台殘留的版號不可靠。
 *
 * 各測試寫入的 option 由 WP_UnitTestCase 的 transaction rollback 還原，hook 由 hook 備份機制還原。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 未付款標示預設值測試類別
 *
 * @covers includes/ry-woocommerce-tools/class.ry-wt.update.php
 * @covers includes/ry-woocommerce-tools/class.ry-wt.main.php
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

		// 模擬尚未升級的站台。
		delete_option( 'woomp_migrated_unpay_title_default' );
		RY_WT::remove_unpay_title_notice( true );
	}

	/**
	 * 資料提供者：升級前站台資料庫裡可能的 RY_WT_version
	 *
	 * @return array
	 */
	public static function provide_ry_versions() {
		return [
			'woomp 既有站台'                  => [ '1.7.6' ],
			'從獨立版 RY Tools 轉過來（版號較新）' => [ '2.0.0' ],
			'版號等於 RY_WT_VERSION'            => [ RY_WT_VERSION ],
			'全新安裝'                        => [ '' ],
		];
	}

	/**
	 * @dataProvider provide_ry_versions
	 * @testdox 升級時沿用舊預設的 yes 會被覆蓋成 no（RY_WT_version = $ry_version）
	 *
	 * @param string $ry_version 升級前的 RY_WT_version。
	 */
	public function test_upgrade_overrides_yes_to_no( $ry_version ) {
		RY_WT::update_option( 'version', $ry_version );
		RY_WT::update_option( 'show_unpay_title', 'yes' );

		RY_WT_update::update();
		RY_WT_update::migrate_unpay_title_default();

		$this->assertSame( 'no', RY_WT::get_option( 'show_unpay_title' ) );
	}

	/**
	 * @testdox 覆蓋只跑一次，之後商家手動改回 yes 不會再被覆蓋
	 */
	public function test_upgrade_runs_only_once() {
		RY_WT_update::migrate_unpay_title_default();

		RY_WT::update_option( 'show_unpay_title', 'yes' );
		RY_WT_update::migrate_unpay_title_default();

		$this->assertSame( 'yes', RY_WT::get_option( 'show_unpay_title' ) );
	}

	/**
	 * @testdox 沒有存過設定時，前台不會加上「(尚未付款)」
	 */
	public function test_no_notice_when_option_missing() {
		RY_WT::delete_option( 'show_unpay_title' );

		RY_WT::maybe_add_unpay_title_notice();

		$this->assertFalse( has_filter( 'woocommerce_order_get_payment_method_title', [ 'RY_WT', 'unpay_title_notice' ] ) );
	}

	/**
	 * @testdox 商家手動開啟時，前台仍會加上「(尚未付款)」
	 */
	public function test_notice_when_option_yes() {
		RY_WT::update_option( 'show_unpay_title', 'yes' );

		RY_WT::maybe_add_unpay_title_notice();

		$this->assertSame( 10, has_filter( 'woocommerce_order_get_payment_method_title', [ 'RY_WT', 'unpay_title_notice' ] ) );
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

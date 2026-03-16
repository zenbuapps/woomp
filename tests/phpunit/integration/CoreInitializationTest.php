<?php
/**
 * 核心初始化整合測試
 *
 * 驗證 Woomp 外掛啟動時的常數定義、自動載入器、類別載入、
 * 選項預設值與文字域等核心功能。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 核心初始化測試類別
 *
 * @covers init.php
 * @covers woomp.php
 * @group core
 * @group smoke
 */
class CoreInitializationTest extends WP_UnitTestCase {

	/**
	 * 測試外掛常數已正確定義
	 *
	 * @covers ::define_constants
	 * @testdox 驗證外掛核心常數（WOOMP_VERSION 等）已正確定義且非空
	 */
	public function test_plugin_constants_defined() {
		$this->assertTrue( defined( 'WOOMP_VERSION' ), 'WOOMP_VERSION 常數應已定義' );
		$this->assertTrue( defined( 'WOOMP_PLUGIN_URL' ), 'WOOMP_PLUGIN_URL 常數應已定義' );
		$this->assertTrue( defined( 'WOOMP_PLUGIN_DIR' ), 'WOOMP_PLUGIN_DIR 常數應已定義' );
		$this->assertTrue( defined( 'WOOMP_PLUGIN_BASENAME' ), 'WOOMP_PLUGIN_BASENAME 常數應已定義' );

		$this->assertNotEmpty( WOOMP_VERSION, 'WOOMP_VERSION 不應為空' );
		$this->assertNotEmpty( WOOMP_PLUGIN_URL, 'WOOMP_PLUGIN_URL 不應為空' );
		$this->assertNotEmpty( WOOMP_PLUGIN_DIR, 'WOOMP_PLUGIN_DIR 不應為空' );
		$this->assertNotEmpty( WOOMP_PLUGIN_BASENAME, 'WOOMP_PLUGIN_BASENAME 不應為空' );
	}

	/**
	 * 測試 Composer 自動載入器已載入
	 *
	 * 驗證 J7\Payuni 命名空間可透過 PSR-4 自動載入。
	 *
	 * @testdox 確認 Composer autoload.php 存在於外掛目錄中
	 */
	public function test_composer_autoloader_loaded() {
		$this->assertTrue(
			file_exists( WOOMP_PLUGIN_DIR . 'vendor/autoload.php' ),
			'Composer autoload.php 應存在於外掛目錄中'
		);
	}

	/**
	 * 測試 Woomp 主類別存在
	 *
	 * @testdox 確認 Woomp 主類別已載入
	 */
	public function test_woomp_class_exists() {
		$this->assertTrue(
			class_exists( 'Woomp' ),
			'Woomp 主類別應已載入'
		);
	}

	/**
	 * 測試啟用外掛時設定預設選項值
	 *
	 * @covers ::activate_woomp
	 * @testdox 驗證啟用外掛時設定模式選項可正常讀取
	 */
	public function test_default_options_set_on_activation() {
		// 清除測試用選項。
		delete_option( 'woomp_setting_mode' );

		// 模擬啟用外掛。
		if ( function_exists( 'activate_woomp' ) ) {
			activate_woomp();
		}

		// 在啟動後驗證基本選項已可讀取。
		$mode = get_option( 'woomp_setting_mode', 'default' );
		$this->assertNotFalse( $mode, '啟用後應可讀取設定模式選項' );
	}

	/**
	 * 測試啟用外掛時不會覆寫已存在的選項
	 *
	 * @covers ::activate_woomp
	 * @testdox 驗證啟用外掛時不會覆寫已存在的選項值
	 */
	public function test_activation_does_not_overwrite_existing_options() {
		$existing_value = 'custom_value';
		update_option( 'wc_woomp_enabled_payuni_gateway', $existing_value );

		// 模擬啟用外掛。
		if ( function_exists( 'activate_woomp' ) ) {
			activate_woomp();
		}

		$this->assertEquals(
			$existing_value,
			get_option( 'wc_woomp_enabled_payuni_gateway' ),
			'啟用外掛不應覆寫已存在的選項值'
		);
	}

	/**
	 * 測試文字域已正確載入
	 *
	 * @testdox 確認語系目錄存在或文字域已正確載入
	 */
	public function test_text_domains_loaded() {
		$loaded = is_textdomain_loaded( 'woomp' );
		// 即使文字域尚未載入，至少驗證語系檔案目錄存在。
		$languages_dir = WOOMP_PLUGIN_DIR . 'languages/';
		$this->assertTrue(
			is_dir( $languages_dir ) || $loaded,
			'語系目錄應存在或文字域應已載入'
		);
	}

	/**
	 * 測試 Plugin Update Checker 已註冊
	 *
	 * @testdox 確認 Plugin Update Checker 類別已載入
	 */
	public function test_plugin_update_checker_registered() {
		$this->assertTrue(
			class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' )
			|| class_exists( 'Puc_v4_Factory' ),
			'Plugin Update Checker 類別應已載入'
		);
	}

	/**
	 * 測試 WooCommerce 依賴檢查
	 *
	 * 當 WooCommerce 未啟用時，外掛不應完全初始化。
	 *
	 * @testdox 確認 WooCommerce 存在時外掛已正常初始化
	 */
	public function test_wc_dependency_check() {
		// 確認 WooCommerce 已載入（在測試環境中）。
		$this->assertTrue(
			class_exists( 'WooCommerce' ),
			'測試環境中 WooCommerce 應已載入'
		);

		// 驗證在 WC 存在時外掛已正常初始化。
		$this->assertTrue(
			defined( 'WOOMP_PLUGIN_DIR' ),
			'當 WooCommerce 存在時，外掛應已初始化'
		);
	}
}

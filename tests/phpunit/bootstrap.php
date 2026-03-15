<?php
/**
 * Woomp 整合測試啟動檔案
 *
 * 載入 WordPress 測試套件、WooCommerce 與 Woomp 外掛，
 * 並設定測試所需的預設選項。
 *
 * @package Woomp\Tests
 */

// 定義外掛根目錄路徑。
define( 'WOOMP_PLUGIN_DIR_TEST', dirname( dirname( __DIR__ ) ) . '/' );

// 嘗試取得 WordPress 測試套件路徑（wp-env 預設路徑）。
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	// wp-env 預設將測試套件放在 /tmp/wordpress-tests-lib。
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	// 嘗試 Local by Flywheel / 其他環境的備用路徑。
	$_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}

if ( ! $_tests_dir || ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "無法找到 WordPress 測試套件。請設定 WP_TESTS_DIR 環境變數。\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// 載入 WordPress 測試套件的 functions.php，以便在載入 WordPress 前註冊外掛。
require_once $_tests_dir . '/includes/functions.php';

/**
 * 在 WordPress 測試初始化時手動載入 WooCommerce 與 Woomp。
 */
function _manually_load_plugins() {
	// 設定模組載入所需的預設選項。
	$default_options = array(
		'wc_woomp_enabled_payuni_gateway'       => 'yes',
		'wc_woomp_enabled_ecpay_invoice'        => 'yes',
		'wc_woomp_enabled_ezpay_invoice'        => 'yes',
		'wc_woomp_setting_paynow_gateway'       => 'yes',
		'wc_woomp_setting_paynow_shipping'      => 'yes',
		'wc_settings_tab_active_paynow_einvoice' => 'yes',
		'ry_wt_enabled_ecpay_gateway'           => 'yes',
		'ry_wt_enabled_ecpay_shipping'          => 'yes',
		'ry_wt_enabled_newebpay_gateway'        => 'yes',
		'ry_wt_enabled_newebpay_shipping'       => 'yes',
		'ry_wt_enabled_smilepay_gateway'        => 'yes',
		'ry_wt_enabled_smilepay_shipping'       => 'yes',
	);

	foreach ( $default_options as $key => $value ) {
		update_option( $key, $value );
	}

	// 載入 WooCommerce。
	$wc_dir = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	if ( file_exists( $wc_dir ) ) {
		require $wc_dir;
	}

	// 載入 Woomp 主外掛檔案。
	require WOOMP_PLUGIN_DIR_TEST . 'woomp.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugins' );

// 載入 WordPress 測試套件。
require $_tests_dir . '/includes/bootstrap.php';

// 確保 WooCommerce 已安裝測試用資料表等。
if ( class_exists( 'WC_Install' ) ) {
	WC_Install::install();
}

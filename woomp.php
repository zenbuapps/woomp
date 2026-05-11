<?php
/**
 * @wordpress-plugin
 * Plugin Name:       好用版擴充 MorePower Addon for WooCommerce
 * Plugin URI:        https://morepower.club/morepower-addon/
 * Description:       WooCommerce 好用版擴充，改善結帳流程與可變商品等區塊，並整合多項金流，讓 WooCommerce 更符合亞洲人使用習慣。
 * Version:           3.5.5
 * Author:            MorePower
 * Author URI:        https://morepower.club
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       woomp
 * Domain Path:       /languages
 * WC requires at least: 7.1
 * WC tested up to: 6.4.1
 */

/**
 * 宣告 HPOS（Custom Order Tables）相容性
 *
 * @see https://developer.woocommerce.com/docs/hpos-extension-recipe-book/
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__ );
	}
} );

require_once 'init.php';
require_once 'debug.php';
require_once 'Compatibility.php';

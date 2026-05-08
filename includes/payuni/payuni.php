<?php

use J7\Payuni\Bootstrap;

defined( 'ABSPATH' ) || exit;
define( 'PAYUNI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PAYUNI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PAYUNI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoload
 */
add_action(
	'plugins_loaded',
	function () {
		if ( wc_string_to_bool( get_option( 'wc_woomp_enabled_payuni_gateway' ) ) || wc_string_to_bool( get_option( 'wc_woomp_enabled_payuni_shipping' ) ) ) {
			\A7\autoload( WOOMP_PLUGIN_DIR . 'includes/payuni/src' );

			if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
				wp_die( 'WC_Payment_Gateway not found' );
			}
			\PAYUNI\APIs\Payment::init();
		}
        
        // 註冊統一金流 v3 入口
        Bootstrap::register_hooks();
	}
);

// TODO 應該要案須載入
add_action(
	'wp_enqueue_scripts',
	function () {
		wp_enqueue_script( 'card-mask', 'https://cdnjs.cloudflare.com/ajax/libs/imask/3.4.0/imask.min.js', [], '1.0.0', true );
		wp_enqueue_style( 'card', PAYUNI_PLUGIN_URL . 'assets/card.css', [], '1.0.7' );
		wp_register_script( 'card', PAYUNI_PLUGIN_URL . 'assets/card.js', [ 'jquery' ], '1.5.6', true );
		wp_localize_script(
			'card',
			'card_params',
			[
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'ajax_nonce' => wp_create_nonce( 'payuni_card_change' ),
				'user_id'    => get_current_user_id(),
			]
		);
		wp_enqueue_script( 'card' );
	}
);

// 如果是訂閱付款方式，不顯示儲存付款方式
\add_filter(
	'woocommerce_payment_gateway_save_new_payment_method_option_html',
	function ( $html, $payment_gateway ) {

		$payment_gateway_id = $payment_gateway->id;
		if ( 'payuni-credit-subscription' === $payment_gateway_id ) {
			return '';
		}

		return $html;
	},
	90,
	2
);

<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @link       #
 * @since      1.0.0
 *
 * @package    Woomp
 * @subpackage Woomp/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Woomp
 * @subpackage Woomp/public
 * @author     More Power <a@a.a>
 */
class Woomp_Public {



	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string $plugin_name       The name of the plugin.
	 * @param      string $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Woomp_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Woomp_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/woomp-public.css', [], $this->version, 'all' );
		\wp_enqueue_style( 'woomp-main', WOOMP_PLUGIN_URL . 'admin/css/main.min.css', [], \WOOMP_VERSION );
	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Woomp_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Woomp_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script( 'twzipcode', plugin_dir_url( __FILE__ ) . 'js/twzipcode.js', [ 'jquery' ], $this->version, true );

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/woomp-public.js', [ 'jquery' ], $this->version, true );

		if ( is_checkout() ) {

			wp_register_script( 'woomp_checkout', plugin_dir_url( __FILE__ ) . 'js/woomp-checkout.js', [ 'jquery' ], $this->version, true );
			wp_localize_script(
				'woomp_checkout',
				'woomp_checkout_params',
				[
					'enableWoomp'                 => ( get_option( 'wc_woomp_setting_mode', 1 ) === 'onepage' || get_option( 'wc_woomp_setting_mode', 1 ) === 'twopage' ) ? 'yes' : '',
					'enableTwoPage'               => ( get_option( 'wc_woomp_setting_mode', 1 ) === 'twopage' ) ? 'yes' : '',
					'enableTwAddress'             => get_option( 'wc_woomp_setting_tw_address' ),
					'enableCountryToTop'          => get_option( 'wc_woomp_setting_billing_country_pos' ),
					'enableCheckoutLoginReminder' => get_option( 'woocommerce_enable_checkout_login_reminder', true ),
					'enableCoupons'               => get_option( 'woocommerce_enable_coupons', true ),
					'wcGetCheckoutUrl'            => wc_get_checkout_url(),
					'isUserLoggedIn'              => is_user_logged_in(),
					'textReturnCustomer'          => esc_html__( 'Returning customer?', 'woocommerce' ),
					'textClickLogin'              => esc_html__( 'Click here to login', 'woocommerce' ),
					'textHaveCoupon'              => esc_html__( 'Have a coupon?', 'woocommerce' ),
					'textClickCoupon'             => esc_html__( 'Click here to enter your code', 'woocommerce' ),
					'isFreeCart'                  => $this->is_free_cart(),
				]
			);
			wp_enqueue_script( 'woomp_checkout' );
		}
	}

	/**
	 * 檢查購物車總金額為 0
	 */
	public function is_free_cart() {
		global $woocommerce;
		$total = $woocommerce->cart->cart_contents_total;
		if ( '0' === $total ) {
			return 'yes';
		}
		return 'no';
	}

	/**
	 * 虛擬商品自動完成訂單
	 */
	public function auto_complete_virtual( $order_id, $status_from, $status_to ) {
		$enable_autocomplete = \wc_string_to_bool( \get_option( 'wc_woomp_setting_virtual_product_order_auto_complete' ) );

		if (!$enable_autocomplete || 'processing' !== $status_to || ! $order_id) {
			return;
		}

		$order = \wc_get_order( $order_id );
		if (!$order instanceof \WC_Order) {
			return;
		}

		$virtual_order = true;
		/** @var WC_Order_Item_Product[] $line_items */
		$line_items = $order->get_items();
		if (!$line_items) {
			return;
		}

		foreach ( $line_items as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product = $item->get_product();
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			if ( ! $product->is_virtual() ) {
				$virtual_order = false;
				break;
			}
		}

		if ( $virtual_order ) {
			$order->update_status( 'completed' );
		}
	}
}

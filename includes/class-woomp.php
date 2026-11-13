<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       #
 * @since      1.0.0
 *
 * @package    Woomp
 * @subpackage Woomp/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Woomp
 * @subpackage Woomp/includes
 * @author     More Power <a@a.a>
 */
final class Woomp {


	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Woomp_Loader $loader Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string $plugin_name The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string $version The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'WOOMP_VERSION' ) ) {
			$this->version = WOOMP_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'woomp';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Woomp_Loader. Orchestrates the hooks of the plugin.
	 * - Woomp_i18n. Defines internationalization functionality.
	 * - Woomp_Admin. Defines all hooks for the admin area.
	 * - Woomp_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-woomp-loader.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-woomp-logger.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-woomp-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'admin/resources/shop_subscription/index.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/resources/class-checkout.php';

		require_once plugin_dir_path( __DIR__ ) . 'admin/class-woomp-admin.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-woomp-product.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-woomp-order.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-woomp-email.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-woomp-payment-cod-clone.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-woomp-shipping-flat-rate.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/settings/class-woomp-setting.php';
		// require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-woomp-payment-cvs.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'public/class-woomp-public.php';
		require_once plugin_dir_path( __DIR__ ) . 'public/class-woomp-checkout.php';
		require_once plugin_dir_path( __DIR__ ) . 'public/class-woomp-order.php';
		require_once plugin_dir_path( __DIR__ ) . 'public/class-woomp-product.php';

		$this->loader = new Woomp_Loader();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Woomp_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {
		$plugin_i18n = new Woomp_i18n();

		$this->loader->add_action( 'init', $plugin_i18n, 'load_plugin_textdomain', 20 );
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {
		$plugin_admin = new Woomp_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts', 99 );
		$this->loader->add_filter( 'woocommerce_admin_billing_fields', $plugin_admin, 'custom_order_meta', 10, 1 );
		$this->loader->add_action(
			'woocommerce_admin_order_data_after_billing_address',
			$plugin_admin,
			'add_address_meta',
			10,
			1
		);
		$this->loader->add_filter(
			'woocommerce_admin_shipping_fields',
			$plugin_admin,
			'custom_order_meta_shipping',
			10,
			1
		);
		$this->loader->add_action(
			'woocommerce_admin_order_data_after_shipping_address',
			$plugin_admin,
			'add_address_meta_shipping',
			10,
			1
		);
		$this->loader->add_filter( 'plugin_action_links_woomp/woomp.php', $plugin_admin, 'add_settings_link' );
		$this->loader->add_filter(
			'woocommerce_available_payment_gateways',
			$plugin_admin,
			'only_newebpay_gateway',
			10
		);
		$this->loader->add_filter(
			'woocommerce_available_payment_gateways',
			$plugin_admin,
			'only_newebpay_gateway',
			10
		);
		$this->loader->add_filter( 'plugin_row_meta', $plugin_admin, 'plugin_row_meta', 10, 2 );
		$this->loader->add_action( 'admin_menu', $plugin_admin, 'add_woomp_submenu', 60 );
		$this->loader->add_filter( 'post_class', $plugin_admin, 'disable_order_table_link', 10, 1 );
		$this->loader->add_filter(
			'wp_ajax_update_shipping_number',
			$plugin_admin,
			'update_order_shipping_number',
			10,
			1
		);
		$this->loader->add_filter( 'woocommerce_email_actions', $plugin_admin, 'add_email_action', 10, 1 );

		// Cron Job
		$this->loader->add_action( 'init', $plugin_admin, 'register_cron_hook', 10 );
		$this->loader->add_action( 'wmp_cron_every_morning', $plugin_admin, 'set_ecpay_cvs_get_remind', 10 );
		$this->loader->add_action( 'wmp_cron_every_morning', $plugin_admin, 'set_ecpay_cvs_get_expired', 10 );

		$this->loader->add_filter( 'woocommerce_shipping_methods', $plugin_admin, 'set_flat_rate_class', 10 );

		$this->loader->add_action( 'woocommerce_new_order', $plugin_admin, 'set_unpaid_atm_order_cron', 10 );
		$this->loader->add_action( 'wmp_cron_atm_deadline', $plugin_admin, 'cancel_unpaid_order', 10, 1 );
		$this->loader->add_action(
			'wmp_cron_atm_deadline_remind',
			$plugin_admin,
			'set_ecpay_atm_transfer_remind',
			10,
			1
		);
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @return    string    The name of the plugin.
	 * @since     1.0.0
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @return    string    The version number of the plugin.
	 * @since     1.0.0
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {
		$plugin_public = new Woomp_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );
		$this->loader->add_action( 'woocommerce_order_status_changed', $plugin_public, 'auto_complete_virtual', 10, 3 );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @return    Woomp_Loader    Orchestrates the hooks of the plugin.
	 * @since     1.0.0
	 */
	public function get_loader() {
		return $this->loader;
	}
}

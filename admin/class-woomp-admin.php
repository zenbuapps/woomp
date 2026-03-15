<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       #
 * @since      1.0.0
 *
 * @package    Woomp
 * @subpackage Woomp/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Woomp
 * @subpackage Woomp/admin
 * @author     More Power <a@a.a>
 */
class Woomp_Admin {

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

	public static $support_methods = [
		'ry_newebpay_shipping_cvs' => 'RY_NewebPay_Shipping_CVS',
	];

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string $plugin_name       The name of this plugin.
	 * @param      string $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register the stylesheets for the admin area.
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

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/woomp-admin.css', [], $this->version, 'all' );
	}

	/**
	 * Register the JavaScript for the admin area.
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

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/woomp-admin.js', [ 'jquery' ], '2.2.5', true );
	}

	/**
	 * 訂單頁面新增地址編輯欄位
	 */
	public function custom_order_meta( $fields ) {
		$fields['full-address'] = [
			'label'         => __( '完整地址', 'woomp' ),
			'show'          => true,
			'wrapper_class' => 'form-field-wide full-address',
		];
		return $fields;
	}

	public function custom_order_meta_shipping( $fields ) {
		global $theorder;

		$shipping_method = false;
		if ( ! empty( $theorder ) && class_exists( 'RY_ECPay_Shipping' ) ) {
			$items_shipping = $theorder->get_items( 'shipping' );
			$items_shipping = array_shift( $items_shipping );
			if ( $items_shipping ) {
				$shipping_method = RY_ECPay_Shipping::get_order_support_shipping( $items_shipping );
			}
			if ( $shipping_method !== false ) {
				if ( strpos( $shipping_method, 'cvs' ) < -1 ) {
					$fields['full-addressShipping'] = [
						'label'         => __( '運送地址', 'woomp' ),
						'show'          => true,
						'wrapper_class' => 'form-field-wide full-addressShipping',
					];
				}
			}
		}
		return $fields;
	}

	/**
	 * 訂單頁面新增地址&姓名欄位
	 */
	public function add_address_meta( $order ) {
		if ( get_option( 'wc_woomp_setting_one_line_address', 1 ) === 'yes' ) {
			echo '<style>
			.order_data_column:nth-child(2) .address:not(.ivoice) p:first-child,
			.order_data_column:nth-child(2) .address.ivoice #billingName,
			.order_data_column:nth-child(2) .address.ivoice #fullAddress {
				display: none;
			}</style>';
			if ( $order->get_meta( '_billing_full-address' ) ) {
				echo '<style>.order_data_column:nth-child(2) .address:not(.ivoice) p:last-child{display:none;}</style>';
			}
			echo '<p style="font-size: 14px;" id="billingName"><strong>帳單姓名:<br/></strong>' . $order->get_billing_last_name() . $order->get_billing_first_name() . '</p>';
			echo '<p style="font-size: 14px;" id="fullAddress"><strong>帳單地址:<br/></strong><span>' . $order->get_meta( '_billing_full-address' ) . '</span></p>';
		}
	}

	public function add_address_meta_shipping( $order ) {
		echo '<style>
		.order_data_column:nth-child(3) .address p:first-child {
			display: none;
		}</style>';
		echo '<p style="font-size:14px;clear:both;padding-top:10px!important" id="shippingName"><strong>收件人姓名:<br/></strong>' . $order->get_shipping_last_name() . $order->get_shipping_first_name() . '</p>';
		if ( get_option( 'wc_woomp_setting_one_line_address', 1 ) === 'yes' && strpos( $order->get_shipping_method(), '超商' ) < -1 ) {
			echo '<p style="font-size: 14px;" id="fullAddressShipping"><strong>運送地址:<br/></strong><span>' . $order->get_meta( '_shipping_full-addressShipping' ) . '</span></p>';
		} elseif ( get_option( 'wc_woomp_setting_one_line_address', 1 ) === 'yes' && strpos( $order->get_shipping_method(), '超商' ) > -1 ) {
			echo '<p style="font-size: 14px; color: #222" id="cvsStore"><strong>' . $order->get_shipping_method() . '</strong></p>';
			echo '<p style="font-size: 14px;" id="cvsNumber">門市編號:<br/><span>' . $order->get_meta( '_shipping_cvs_store_ID' ) . $order->get_meta( '_shipping_paynow_storeid' ) . '</span></p>';
			echo '<p style="font-size: 14px;" id="cvsName">門市名稱:<br/><span>' . $order->get_meta( '_shipping_cvs_store_name' ) . $order->get_meta( '_shipping_paynow_storename' ) . '</span></p>';
			echo '<p style="font-size: 14px;" id="cvsAddress">門市地址:<br/><span>' . $order->get_meta( '_shipping_cvs_store_address' ) . $order->get_meta( '_shipping_paynow_storeaddress' ) . '</span></p>';
		}
	}

	/**
	 * 在外掛列表頁加入「設定」按鈕
	 */
	public function add_settings_link( $links ) {
		return array_merge(
			[
				'settings' => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=woomp_setting' ) . '">' . __( 'Settings' ) . '</a>',
				'gateway'  => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=woomp_setting_gateway' ) . '">' . __( 'payment', 'woomp' ) . '</a>',
				'shipping' => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=woomp_setting_shipping' ) . '">' . __( 'shipping', 'woomp' ) . '</a>',
				'invoice'  => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=woomp_setting_invoice' ) . '">' . __( 'invoice', 'woomp' ) . '</a>',
			],
			$links
		);
	}


	/**
	 * 讓 RY 藍新物流也能支援 woomp_cvs_payment
	 */
	public function only_newebpay_gateway( $_available_gateways ) {
		if ( WC()->cart && WC()->cart->needs_shipping() ) {
			$chosen_shipping = wc_get_chosen_shipping_method_ids();
			$chosen_shipping = array_intersect( $chosen_shipping, array_keys( self::$support_methods ) );
			if ( count( $chosen_shipping ) ) {
				foreach ( $_available_gateways as $key => $gateway ) {
					if ( strpos( $key, 'ry_newebpay_' ) === 0 ) {
						continue;
					}
					if ( $key == 'cod' || $key == 'woomp_cvs_gateway' ) {
						continue;
					}
					unset( $_available_gateways[ $key ] );
				}
			}
		}
		return $_available_gateways;
	}

	/**
	 * 新增教學文件連結
	 */
	public function plugin_row_meta( $links, $file ) {
		// if ( 'woomp/woomp.php' === $file ) {
		// return array_merge(
		// $links,
		// array(
		// 'doc' => '<a target="_blank" href="https://morepower.club/know_cate/addon/">教學文件</a>',
		// ),
		// );
		// }

		return $links;
	}



	/**
	 * 增加好用版選單
	 */
	public function add_woomp_submenu() {
		add_submenu_page( 'woocommerce', 'woomp-main', '好用版擴充設定', 'manage_options', admin_url() . 'admin.php?page=wc-settings&tab=woomp_setting', '', 10 );
		add_submenu_page( 'woocommerce', 'woomp-main', '- 金流設定', 'manage_options', admin_url() . 'admin.php?page=wc-settings&tab=woomp_setting_gateway&section=payuni', '', 11 );
		add_submenu_page( 'woocommerce', 'woomp-main', '- 物流設定', 'manage_options', admin_url() . 'admin.php?page=wc-settings&tab=woomp_setting_shipping&section=ecpay', '', 12 );
		add_submenu_page( 'woocommerce', 'woomp-main', '- 電子發票設定', 'manage_options', admin_url() . 'admin.php?page=wc-settings&tab=woomp_setting_invoice&section=ecpay', '', 13 );
	}

	/**
	 * 停用訂單列表點擊事件
	 */
	public function disable_order_table_link( $classes ) {
		if ( is_admin() ) {
			$current_screen = get_current_screen();
			// HPOS 相容：同時判斷傳統訂單列表和 HPOS 訂單列表
			$is_order_list = ( $current_screen->base == 'edit' && $current_screen->post_type == 'shop_order' )
				|| in_array( $current_screen->id, Woomp_HPOS_Helper::get_order_screen_ids(), true );
			if ( $is_order_list ) {
				$classes[] = 'no-link';
			}
		}
		return $classes;
	}

	/**
	 * Ajax 更新訂單物流單號
	 */
	public function update_order_shipping_number() {
		if ( isset( $_POST['shippingNo'] ) && isset( $_POST['orderId'] ) ) {
			$shipping_no = sanitize_text_field( wp_unslash( $_POST['shippingNo'] ) );
			$order_id    = absint( $_POST['orderId'] );
			$order       = wc_get_order( $order_id );
			if ( $order ) {
				$order->update_meta_data( 'wmp_shipping_no', $shipping_no );
				$order->save();
				echo wp_json_encode( '變更完成' );
			} else {
				echo wp_json_encode( '找不到訂單' );
			}
		} else {
			echo wp_json_encode( '發生錯誤' );
		}
		die();
	}

	/**
	 * 新增訂單觸發電子郵件
	 */
	public function add_email_action( $actions ) {
		$actions[] = 'ry_ecpay_shipping_cvs_to_store';
		$actions[] = 'ry_ecpay_shipping_cvs_to_transporting';
		$actions[] = 'ry_ecpay_shipping_cvs_get_remind';
		$actions[] = 'ry_ecpay_shipping_cvs_get_expired';
		$actions[] = 'ry_ecpay_shipping_atm_transfer_remind';
		return $actions;
	}

	/**
	 * 排程 Hook 註冊
	 */
	public function register_cron_hook() {
		$cron = [
			[
				'type'      => 'recurring',
				'hook_name' => 'wmp_cron_every_morning',
				'start'     => strtotime( '10:00:00' ) - get_option( 'gmt_offset' ) * 3600,
				'interval'  => DAY_IN_SECONDS,
			],
		];

		foreach ( $cron as $arg ) {
			if ( ! as_next_scheduled_action( $arg['hook_name'] ) ) {
				as_schedule_recurring_action( $arg['start'], $arg['interval'], $arg['hook_name'] );
			}
		}
	}

	/**
	 * 綠界超商訂單取貨到期前一天通知
	 */
	public function set_ecpay_cvs_get_remind() {
		$args   = [
			'status'     => [ 'wc-ry-at-cvs' ],
			'meta_key'   => 'ecpay_cvs_at_store_expired',
			'meta_value' => date( 'Y-m-d', strtotime( '+1 days' ) ),
		];
		$orders = wc_get_orders( $args );

		if ( $orders ) {
			$wc_emails = WC_Emails::instance();
			$emails    = $wc_emails->get_emails();
			$email     = $emails['RY_ECPay_Shipping_Email_Customer_CVS_Get_Remind'];

			if ( $email ) {
				if ( $email->is_enabled() ) {
					foreach ( $orders as $order ) {
						$email->object    = $order;
						$email->recipient = $email->object->get_billing_email();
						$email->send( $email->get_recipient(), $email->get_subject(), $email->get_content(), $email->get_headers(), $email->get_attachments() );
					}
				}
			}
		}
	}

	/**
	 * 綠界超商訂單取貨到期當天通知
	 */
	public function set_ecpay_cvs_get_expired() {
		$args   = [
			'status'     => [ 'wc-ry-at-cvs' ],
			'meta_key'   => 'ecpay_cvs_at_store_expired',
			'meta_value' => date( 'Y-m-d' ),
		];
		$orders = wc_get_orders( $args );

		$wc_emails = WC_Emails::instance();
		$emails    = $wc_emails->get_emails();
		$email     = $emails['RY_ECPay_Shipping_Email_Customer_CVS_Get_Expired'] ?? '';

		if ( $email ) {
			if ( $email->is_enabled() ) {
				foreach ( $orders as $order ) {
					$email->object    = $order;
					$email->recipient = $email->object->get_billing_email();
					$email->send( $email->get_recipient(), $email->get_subject(), $email->get_content(), $email->get_headers(), $email->get_attachments() );
				}
			}
		}
	}

	/**
	 * 將單一費率類別改成好用版的
	 */
	public function set_flat_rate_class( $shipping_methods ) {
		$shipping_methods['flat_rate'] = 'WooMP_Shipping_Flat_Rate';
		return $shipping_methods;
	}

	/**
	 * ATM 排程 Hook 註冊
	 */
	public function set_unpaid_atm_order_cron( $order_id ) {

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->get_payment_method() === 'ry_ecpay_atm' ) {
			$atm        = WC()->payment_gateways()->get_available_payment_gateways()['ry_ecpay_atm'];
			$expire_sec = $atm->expire_date * 86400;

			// 註冊取消訂單排程
			as_schedule_single_action( strtotime( $order->get_date_created()->date( 'Y-m-d H:i:s' ) . ' -8 hour' ) + $expire_sec, 'wmp_cron_atm_deadline', [ $order_id ] );

			// 註冊發送轉帳提醒通知信排程
			as_schedule_single_action( strtotime( $order->get_date_created()->date( 'Y-m-d H:i:s' ) . ' -1 day -8 hour' ) + $expire_sec, 'wmp_cron_atm_deadline_remind', [ $order_id ] );
		}
	}

	/**
	 * Cancel unpaid order
	 */
	public function cancel_unpaid_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( 'pending' === $order->get_status() || 'on-hold' === $order->get_status() ) {
			$order->update_status( 'cancelled' );
		}
	}

	/**
	 * ATM 逾期前一日通知
	 */
	public function set_ecpay_atm_transfer_remind( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( $order ) {
			if ( 'pending' === $order->get_status() || 'on-hold' === $order->get_status() ) {
				$wc_emails = WC_Emails::instance();
				$emails    = $wc_emails->get_emails();
				$email     = $emails['RY_ECPay_Shipping_Email_Customer_ATM_Transfer_Remind'];

				if ( $email ) {
					if ( $email->is_enabled() ) {
						$email->object    = $order;
						$email->recipient = $email->object->get_billing_email();
						$email->send( $email->get_recipient(), $email->get_subject(), $email->get_content(), $email->get_headers(), $email->get_attachments() );
					}
				}
			}
		}
	}
}

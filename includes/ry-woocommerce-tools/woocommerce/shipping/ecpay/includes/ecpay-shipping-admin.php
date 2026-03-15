<?php
/**
 * ECPay 物流管理
 *
 * 處理綠界科技物流方法的管理功能
 *
 * @package RyWoocommerceTools
 * @since 1.0.0
 */

/**
 * RY_ECPay_Shipping_admin 類別
 *
 * 管理 WooCommerce 中與物流相關的管理任務
 */
final class RY_ECPay_Shipping_admin {

	/**
	 * 初始化管理掛鉤和設定
	 *
	 * 載入必要的外掛程式、註冊動作和篩選器
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		// 載入必要的外掛程式
		include_once RY_WT_PLUGIN_DIR . 'woocommerce/admin/meta-boxes/ecpay-shipping-meta-box.php';

		// 管理選單和介面相關 hooks
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ], 15 );
		add_action( 'add_meta_boxes', [ 'RY_ECPay_Shipping_Meta_Box', 'add_meta_box' ], 40, 2 );

		// 運送欄位和設定相關 hooks
		add_filter( 'woocommerce_admin_shipping_fields', [ __CLASS__, 'set_cvs_shipping_fields' ], 99 );
		add_action( 'woocommerce_shipping_zone_method_status_toggled', [ __CLASS__, 'check_can_enable' ], 10, 4 );
		add_action( 'woocommerce_update_options_shipping_options', [ __CLASS__, 'check_ship_destination' ] );

		// 訂單動作相關 hooks
		add_filter( 'woocommerce_order_actions', [ __CLASS__, 'add_order_actions' ] );
		add_action( 'woocommerce_order_action_get_new_ecpay_no', [ 'RY_ECPay_Shipping_Api', 'get_code' ] );
		add_action( 'woocommerce_order_action_get_new_ecpay_no_cod', [ 'RY_ECPay_Shipping_Api', 'get_code_cod' ] );
		add_action( 'woocommerce_order_action_send_at_cvs_email', [ 'RY_ECPay_Shipping', 'send_at_cvs_email' ] );

		// 訂單批量處理（傳統 + HPOS 雙重註冊）
		add_filter( 'bulk_actions-edit-shop_order', [ __CLASS__, 'bulk_action' ], 99, 1 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'bulk_action' ], 99, 1 );
		add_filter( 'handle_bulk_actions-edit-shop_order', [ __CLASS__, 'handle_bulk_order_status_update' ], 100, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'handle_bulk_order_status_update' ], 100, 3 );
	}

	/**
	 * 新增管理子選單頁面以列印物流單
	 *
	 * 在 WordPress 管理介面中新增一個隱藏的子選單項目，用於列印物流單
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function admin_menu() {
		add_submenu_page( null, 'RY ECPay shipping print', null, 'edit_shop_orders', 'ry_print_ecpay_shipping', [ __CLASS__, 'print_shipping' ] );
	}

	/**
	 * 設定超商（CVS）物流欄位
	 *
	 * 根據訂單的物流方法動態新增超商相關欄位
	 *
	 * @since 1.0.0
	 * @param array $shipping_fields 現有的運送欄位
	 * @return array 更新後的運送欄位
	 */
	public static function set_cvs_shipping_fields( $shipping_fields ) {
		global $theorder;

		$shipping_method = false;
		if ( ! empty( $theorder ) ) {
			$items_shipping = $theorder->get_items( 'shipping' );
			$items_shipping = array_shift( $items_shipping );
			if ( $items_shipping ) {
				$shipping_method = RY_ECPay_Shipping::get_order_support_shipping( $items_shipping );
			}
			if ( $shipping_method !== false ) {
				if ( strpos( $shipping_method, 'cvs' ) !== false ) {
					$shipping_fields['cvs_store_ID']        = [
						'label' => __( 'Store ID', 'ry-woocommerce-tools' ),
						'show'  => false,
					];
					$shipping_fields['cvs_store_name']      = [
						'label' => __( 'Store Name', 'ry-woocommerce-tools' ),
						'show'  => false,
					];
					$shipping_fields['cvs_store_address']   = [
						'label' => __( 'Store Address', 'ry-woocommerce-tools' ),
						'show'  => false,
					];
					$shipping_fields['cvs_store_telephone'] = [
						'label' => __( 'Store Telephone', 'ry-woocommerce-tools' ),
						'show'  => false,
					];
				}

				$shipping_fields['phone'] = [
					'label' => __( 'Phone', 'ry-woocommerce-tools' ),
				];
			} elseif ( 'yes' == RY_WT::get_option( 'keep_shipping_phone', 'no' ) ) {
				$shipping_fields['phone'] = [
					'label' => __( 'Phone', 'ry-woocommerce-tools' ),
				];
			}
		}
		return $shipping_fields;
	}

	/**
	 * 檢查物流方法是否可以啟用
	 *
	 * 當運送目的地設定為「僅限帳單地址」時，停用特定物流方法
	 *
	 * @since 1.0.0
	 * @param int    $instance_id 物流方法實例 ID
	 * @param string $method_id   物流方法 ID
	 * @param int    $zone_id     運送區域 ID
	 * @param int    $is_enabled  是否啟用
	 * @return void
	 */
	public static function check_can_enable( $instance_id, $method_id, $zone_id, $is_enabled ) {
		if ( $is_enabled != 1 ) {
			return;
		}
		if ( array_key_exists( $method_id, RY_ECPay_Shipping::$support_methods ) ) {
			if ( 'billing_only' === get_option( 'woocommerce_ship_to_destination' ) ) {
				global $wpdb;

				$wpdb->update(
					$wpdb->prefix . 'woocommerce_shipping_zone_methods',
					[
						'is_enabled' => 0,
					],
					[
						'instance_id' => absint( $instance_id ),
					]
				);
			}
		}
	}

	/**
	 * 檢查運送目的地設定
	 *
	 * 根據 WooCommerce 的運送目的地設定，調整超商物流方法的狀態
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function check_ship_destination() {
		global $wpdb;
		if ( 'billing_only' === get_option( 'woocommerce_ship_to_destination' ) ) {
			RY_WT::update_option( 'ecpay_shipping_cvs_type', 'disable' );
			foreach ( [ 'ry_ecpay_shipping_cvs_711', 'ry_ecpay_shipping_cvs_hilife', 'ry_ecpay_shipping_cvs_family' ] as $method_id ) {
				$wpdb->update(
					$wpdb->prefix . 'woocommerce_shipping_zone_methods',
					[
						'is_enabled' => 0,
					],
					[
						'method_id' => $method_id,
					]
				);
			}

			WC_Admin_Settings::add_error( __( 'All cvs shipping methods set to disable.', 'ry-woocommerce-tools' ) );
		} elseif ( RY_WT::get_option( 'ecpay_shipping_cvs_type' ) == 'disable' ) {
			RY_WT::update_option( 'ecpay_shipping_cvs_type', 'C2C' );
		}
	}

	/**
	 * 新增訂單動作
	 *
	 * 根據訂單的物流方法和付款方式，動態新增可用的訂單動作
	 *
	 * @since 1.0.0
	 * @param array $order_actions 現有的訂單動作
	 * @return array 更新後的訂單動作
	 */
	public static function add_order_actions( $order_actions ) {
		global $theorder, $post;
		if ( ! is_object( $theorder ) ) {
			$theorder = wc_get_order( $post->ID );
		}

		foreach ( $theorder->get_items( 'shipping' ) as $item_id => $item ) {
			if ( RY_ECPay_Shipping::get_order_support_shipping( $item ) !== false ) {
				$order_actions['get_new_ecpay_no'] = __( 'Get new Ecpay shipping no', 'ry-woocommerce-tools' );
				if ( $theorder->get_payment_method() == 'cod' ) {
					$order_actions['get_new_ecpay_no_cod'] = __( 'Get new Ecpay shipping no with cod', 'ry-woocommerce-tools' );
				}
				if ( $theorder->has_status( [ 'ry-at-cvs' ] ) ) {
					$order_actions['send_at_cvs_email'] = __( 'Resend at cvs notification', 'ry-woocommerce-tools' );
				}
			}
		}
		return $order_actions;
	}

	/**
	 * 列印物流單
	 *
	 * 根據訂單和物流資訊，產生並列印物流單
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function print_shipping() {
		$order_ID     = wp_unslash( $_GET['orderid'] );
		$logistics_ID = ( ! empty( $_GET['id'] ) ) ? (int) $_GET['id'] : 0;
		$print_list   = [];

		if ( $logistics_ID > 0 ) {
			$order = wc_get_order( (int) $order_ID );
			if ( empty( $order ) ) {
				wp_redirect( Woomp_HPOS_Helper::get_order_list_url() );
				exit();
			}

			$shipping_list = $order->get_meta( '_ecpay_shipping_info', true );
			if ( is_array( $shipping_list ) ) {
				foreach ( $shipping_list as $info ) {
					if ( $info['ID'] == $logistics_ID ) {
						$print_list[] = $info;
					}
				}
			}
		} else {
			$print_type = wp_unslash( $_GET['type'] );
			$order_IDs  = explode( ',', $order_ID );
			foreach ( $order_IDs as $order_ID ) {
				$order = wc_get_order( (int) $order_ID );
				if ( empty( $order ) ) {
					continue;
				}
				$shipping_list = $order->get_meta( '_ecpay_shipping_info', true );
				if ( is_array( $shipping_list ) ) {
					foreach ( $shipping_list as $info ) {
						switch ( $info['LogisticsSubType'] ) {
							case 'UNIMART':
							case 'UNIMARTC2C':
								if ( $print_type == 'cvs_711' || $print_type == 'cvs_711_freeze' ) {
									$print_list[] = $info;
								}
								break;
							case 'FAMI':
							case 'FAMIC2C':
								if ( $print_type == 'cvs_family' ) {
									$print_list[] = $info;
								}
								break;
							case 'HILIFE':
							case 'HILIFEC2C':
								if ( $print_type == 'cvs_hilife' ) {
									$print_list[] = $info;
								}
								break;
							case 'OKMARTC2C':
								if ( $print_type == 'cvs_okmart' ) {
									$print_list[] = $info;
								}
								break;
							case 'POST':
								if ( $print_type == 'home_post' ) {
									$print_list[] = $info;
								}
								break;
							case 'TCAT':
								if ( $print_type == 'home_tcat' || $print_type == 'home_tcat_freeze' || $print_type == 'home_tcat_frozen' ) {
									$print_list[] = $info;
								}
								break;
							case 'ECAN':
								if ( $print_type == 'home_ecan' ) {
									$print_list[] = $info;
								}
								break;
						}
					}
				}
			}
		}

		if ( ! empty( $print_list ) ) {
			RY_ECPay_Shipping_Api::get_print_form( $print_list );
			exit();
		}

		// wp_redirect(admin_url(''));
		exit();

		/*
		foreach ($order->get_items('shipping') as $item_id => $item) {
		$shipping_list = $order->get_meta('_ecpay_shipping_info', true);
		if (!is_array($shipping_list)) {
		continue;
		}
		foreach ($shipping_list as $info) {
		if ($info['ID'] != $logistics_id) {
		continue;
		}
		if ($only) {
		$print_info = RY_ECPay_Shipping_Api::get_print_info($logistics_id, $info);
		switch ($info['LogisticsSubType']) {
		case 'FAMIC2C':
		$sub_info = substr($print_info, strpos($print_info, '<img'));
		preg_match('/(<img[^>]*>)/', $sub_info, $match);
		if (count($match) == 2) {
		$print_info = '<!DOCTYPE html><html><head><meta charset="' . get_bloginfo('charset', 'display') . '"></head><body style="margin:0;padding:0;overflow:hidden">'
		. $match[1]
		. '</body></html>';
		}
		break;
		case 'HILIFEC2C':
		$sub_info = substr($print_info, strpos($print_info, 'location.href'));
		preg_match("/'([^']*)'/", $sub_info, $match);
		if (count($match) == 2) {
		$print_info = '<!DOCTYPE html><html><head><meta charset="' . get_bloginfo('charset', 'display') . '"></head><body style="margin:0;padding:0;overflow:hidden">'
		. '<iframe src="' . $match[1] . '" style="border:0;width:990px;height:315px"></iframe>'
		. '</body></html>';
		}
		break;
		case 'UNIMARTC2C':
		break;
		}
		} else {
		$print_info = RY_ECPay_Shipping_Api::get_print_info_form($logistics_id, $info);
		}

		echo $print_info;
		wp_die();
		}
		}*/
	}

	/**
	 * 新增批次處理選項
	 *
	 * @param array $actions 現有批次處理選項.
	 * @return array 更新後的批次處理選項
	 */
	public static function bulk_action( $actions ) {
		$actions['get_new_ecpay_no']                = __( 'Get new Ecpay shipping no', 'ry-woocommerce-tools' );
		$actions['get_new_ecpay_no_cod']            = __( 'Get new Ecpay shipping no with cod', 'ry-woocommerce-tools' );
		$actions['print_ecpay_post_shipping_label'] = '列印中華郵政宅配單(綠界)';
		return $actions;
	}


	/**
	 * 處理批量更改訂單狀態
	 *
	 * @param string $redirect_to 重定向 URL.
	 * @param string $action      執行的動作.
	 * @param array  $post_ids    訂單 ID 列表.
	 * @return string
	 */
	public static function handle_bulk_order_status_update( $redirect_to, $action, $post_ids ) {
		if ( $action === 'print_ecpay_post_shipping_label' ) {
			foreach ( $post_ids as $post_id ) {
				$order = wc_get_order( $post_id );
				if ( $order && ! $order->get_meta( '_ecpay_shipping_info', true ) ) {
					RY_ECPay_Shipping_Api::get_code( $post_id );
				}
			}

			$order_ids_string = implode( ',', $post_ids );
			$print_url        = \add_query_arg(
				[
					'page'    => 'ry_print_ecpay_shipping',
					'orderid' => $order_ids_string,
					'type'    => 'home_post',
				],
				\admin_url( 'admin.php' )
			);
			\wp_redirect( $print_url );
			exit;
		}

		if ( ! in_array( $action, [ 'get_new_ecpay_no', 'get_new_ecpay_no_cod' ] ) ) {
			return $redirect_to;
		}

		foreach ( $post_ids as $post_id ) {
			RY_ECPay_Shipping_Api::get_code( $post_id, 'get_new_ecpay_no_cod' === $action );
		}

		// HPOS 相容：使用 Helper 取得訂單列表 URL
		$order_list_url = Woomp_HPOS_Helper::get_order_list_url();

		return $redirect_to ? $redirect_to : $order_list_url;
	}
}

RY_ECPay_Shipping_admin::init();

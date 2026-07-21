<?php
/**
 * WooMP 訂單管理類別
 *
 * 此類別處理所有與 WooCommerce 訂單相關的功能，包含：
 * 1. 訂單狀態管理
 * 2. 訂單列表顯示優化
 * 3. 物流資訊處理
 * 4. 批次處理功能
 *
 * @package WooMP
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 離開，防止直接訪問
}

if ( ! class_exists( 'WooMP_Order' ) ) {
	/**
	 * WooMP 訂單管理類別
	 */
	class WooMP_Order { //phpcs:ignore
		/**
		 * 初始化類別
		 *
		 * 註冊所有需要的 hooks 和 filters
		 *
		 * @return void
		 */
		public static function init() {
			$class = new self();

			// 訂單狀態相關
			add_action( 'init', [ $class, 'add_order_status' ] );
			add_filter( 'wc_order_statuses', [ $class, 'add_order_statuses' ] );
			add_filter( 'woocommerce_reports_order_statuses', [ $class, 'add_order_statuses' ] );
			add_filter( 'woocommerce_order_is_paid_statuses', [ $class, 'add_report_paid_statuses' ] );

			// 物流相關
			add_filter( 'wp_ajax_delete_shipping_ecpay_cvs', [ $class, 'delete_shipping_ecpay_cvs' ] );
			add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $class, 'add_choose_cvs_btn' ] );
			add_action( 'admin_enqueue_scripts', [ $class, 'enqueue_choose_cvs_script' ] );

			// 訂單品項 meta 顯示中文化
			add_filter( 'woocommerce_order_item_display_meta_key', [ $class, 'display_shipping_meta_key' ], 10, 3 );
			add_filter( 'woocommerce_order_item_display_meta_value', [ $class, 'display_shipping_meta_value' ], 10, 3 );

			// 訂單列表優化（傳統 + HPOS 雙重註冊）
			add_filter( 'manage_shop_order_posts_columns', [ $class, 'shop_order_columns' ], 11, 1 );
			add_filter( 'manage_woocommerce_page_wc-orders_columns', [ $class, 'shop_order_columns' ], 11, 1 );
			add_action( 'manage_shop_order_posts_custom_column', [ $class, 'shop_order_column' ], 11, 2 );
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $class, 'shop_order_column' ], 11, 2 );

			// 批次處理（傳統 + HPOS 雙重註冊）
			add_filter( 'bulk_actions-edit-shop_order', [ $class, 'bulk_action' ], 99, 1 );
			add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ $class, 'bulk_action' ], 99, 1 );
			add_filter( 'handle_bulk_actions-edit-shop_order', [ $class, 'print_shipping_note' ], 10, 3 );
			add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ $class, 'print_shipping_note' ], 10, 3 );
			add_filter( 'handle_bulk_actions-edit-shop_order', [ $class, 'handle_bulk_order_status_update' ], 10, 3 );
			add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ $class, 'handle_bulk_order_status_update' ], 10, 3 );
			add_action( 'admin_notices', [ $class, 'bulk_admin_notices' ] );
		}

		/**
		 * 註冊自定義訂單狀態
		 *
		 * @return void
		 */
		public function add_order_status() {
			$custom_statuses = [
				'wc-wmp-in-transit' => [
					'label'                     => '配送中',
					'public'                    => true,
					'show_in_admin_status_list' => true,
					'show_in_admin_all_list'    => true,
					'exclude_from_search'       => false,
				],
				'wc-wmp-shipped'    => [
					'label'                     => '已出貨',
					'public'                    => true,
					'show_in_admin_status_list' => true,
					'show_in_admin_all_list'    => true,
					'exclude_from_search'       => false,
				],
			];

			foreach ( $custom_statuses as $status_key => $status_args ) {
				register_post_status( $status_key, $status_args );
			}
		}

		/**
		 * 新增訂單狀態到 WooCommerce 狀態列表
		 *
		 * @param array $order_statuses 現有訂單狀態.
		 * @return array 更新後的訂單狀態
		 */
		public function add_order_statuses( $order_statuses ) {
			$new_order_statuses = [];
			foreach ( $order_statuses as $key => $status ) {
				$new_order_statuses[ $key ] = $status;
				if ( 'wc-processing' === $key ) {
					$new_order_statuses['wc-wmp-in-transit'] = '配送中';
					$new_order_statuses['wc-wmp-shipped']    = '已出貨';
				}
			}
			return $new_order_statuses;
		}

		/**
		 * 物流品項 meta 名稱中文化
		 *
		 * 運費計算時寫入 WC_Shipping_Rate 的內部欄位 no_count（包裹件數）與
		 * diff（距免運門檻差額），會被 WooCommerce 原樣複製成訂單品項 meta。
		 * 直接顯示英文鍵值商家無從理解，故改為中文標籤。
		 *
		 * @param string        $display_key 顯示用名稱.
		 * @param object        $meta        Meta 物件.
		 * @param WC_Order_Item $item        訂單品項.
		 * @return string
		 */
		public function display_shipping_meta_key( $display_key, $meta, $item ) {
			if ( ! $item instanceof WC_Order_Item_Shipping ) {
				return $display_key;
			}

			$labels = [
				'no_count' => __( '包裹件數', 'woomp' ),
				'diff'     => __( '免運門檻差額', 'woomp' ),
			];

			return $labels[ $meta->key ] ?? $display_key;
		}

		/**
		 * 物流品項 meta 數值語意化
		 *
		 * 原始值為裸數字（diff 為負數代表已達門檻），加上單位與說明避免誤讀。
		 *
		 * @param string        $display_value 顯示用數值.
		 * @param object        $meta          Meta 物件.
		 * @param WC_Order_Item $item          訂單品項.
		 * @return string
		 */
		public function display_shipping_meta_value( $display_value, $meta, $item ) {
			if ( ! $item instanceof WC_Order_Item_Shipping ) {
				return $display_value;
			}

			switch ( $meta->key ) {
				case 'no_count':
					return sprintf(
						/* translators: %s: 包裹件數 */
						__( '%s 件', 'woomp' ),
						(int) $meta->value
					);

				case 'diff':
					$diff = (float) $meta->value;
					if ( $diff > 0 ) {
						return sprintf(
							/* translators: %s: 尚差金額 */
							__( '尚差 %s 達免運門檻', 'woomp' ),
							wc_price( $diff )
						);
					}
					return __( '已達免運門檻', 'woomp' );
			}

			return $display_value;
		}

		/**
		 * 新增已付款狀態到報表
		 *
		 * @param array $statuses 現有狀態.
		 * @return array 更新後的狀態
		 */
		public function add_report_paid_statuses( $statuses ) {
			$statuses[] = 'wmp-in-transit';
			$statuses[] = 'wmp-shipped';
			return array_unique( $statuses );
		}

		/**
		 * Ajax 處理刪除綠界物流資訊
		 *
		 * @return void
		 */
		public function delete_shipping_ecpay_cvs() {
			if ( !current_user_can( 'edit_shop_orders' ) ) {
				wp_send_json_error( '權限不足' );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$order_id = isset( $_POST['orderId'] ) ? absint( $_POST['orderId'] ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$ecpay_shipping_id = isset( $_POST['ecpayShippingId'] ) ? sanitize_text_field( wp_unslash( $_POST['ecpayShippingId'] ) ) : '';

			if ( ! $order_id || empty( $ecpay_shipping_id ) ) {
				wp_send_json_error( '參數錯誤' );
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				wp_send_json_error( '找不到訂單' );
			}

			$ecpay_shipping_info = $order->get_meta( '_ecpay_shipping_info', true );
			if ( ! is_array( $ecpay_shipping_info ) ) {
				wp_send_json_error( '找不到物流資訊' );
			}

			unset( $ecpay_shipping_info[ $ecpay_shipping_id ] );
			$order->update_meta_data( '_ecpay_shipping_info', $ecpay_shipping_info );
			$order->save();

			wp_send_json_success( '已成功刪除物流資訊' );
		}

		/**
		 * 在訂單列表增加自定義欄位
		 *
		 * @param array $columns 現有欄位.
		 * @return array 更新後的欄位
		 */
		public function shop_order_columns( $columns ) {
			$add_index = array_search( 'shipping_address', array_keys( $columns ), true ) + 1;
			$pre_array = array_splice( $columns, 0, $add_index );
			$array     = [
				'wmp_payment_no'  => __( '金流單號', 'ry-woocommerce-tools' ),
				'wmp_shipping_no' => __( '物流單號', 'ry-woocommerce-tools' ),
			];
			return array_merge( $pre_array, $array, $columns );
		}

		/**
		 * 顯示訂單列表自定義欄位內容
		 *
		 * @param string $column  欄位名稱.
		 * @param int    $post_id 訂單 ID.
		 * @return void
		 */
		public function shop_order_column( $column, $post_id ) {
			$order = wc_get_order( $post_id );
			if ( ! $order ) {
				return;
			}

			switch ( $column ) {
				case 'wmp_payment_no':
					echo esc_html( $order->get_transaction_id() );
					break;

				case 'wmp_shipping_no':
					$this->display_shipping_number( $order );
					break;

				case 'billing_address':
				case 'shipping_address':
					$this->display_address_phone( $column, $order );
					break;
			}
		}

		/**
		 * 顯示物流單號
		 *
		 * @param WC_Order $order WooCommerce 訂單物件.
		 * @return void
		 */
		private function display_shipping_number( $order ) {
			$shipping_list   = $order->get_meta( '_ecpay_shipping_info', true );
			$paynow_shipping = $order->get_meta( '_paynow_shipping_paymentno', true );

			if ( is_array( $shipping_list ) ) {
				foreach ( $shipping_list as $item ) {
					if ( isset( $item['LogisticsType'] ) ) {
						switch ( $item['LogisticsType'] ) {
							case 'CVS':
							case 'HOME':
								echo esc_html(
									! empty( $item['PaymentNo'] )
									? $item['PaymentNo'] . ' ' . $item['ValidationNo']
									: $item['ID']
								);
								break;
							case 'POST':
								echo esc_html( $item['BookingNote'] ?? '' );
								break;
						}
					}
				}
			} elseif ( $paynow_shipping ) {
				echo esc_html( $paynow_shipping );
			} else {
				$this->display_shipping_input( $order->get_id(), $order );
			}
		}

		/**
		 * 顯示物流單號輸入框
		 *
		 * @param int $order_id 訂單 ID.
		 * @return void
		 */
		private function display_shipping_input( $order_id, $order = null ) {
			if ( $order ) {
				$current_no = $order->get_meta( 'wmp_shipping_no', true );
			} else {
				$order      = wc_get_order( $order_id );
				$current_no = $order ? $order->get_meta( 'wmp_shipping_no', true ) : '';
			}
			?>
			<div class="shippingNoWrap">
				<input type="text"
					name="shippingNo"
					placeholder="請輸入物流單號"
					value="<?php echo esc_attr( $current_no ); ?>"
					style="width: 100%;"
					maxlength="100">
				<input type="hidden" class="orderId" value="<?php echo esc_attr( $order_id ); ?>">
				<div class="shipping-no-loading">
					<div class="lds-spinner">
			<?php for ( $i = 0; $i < 12; $i++ ) : ?>
							<div></div>
						<?php endfor; ?>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * 顯示地址和電話資訊
		 *
		 * @param string   $column 欄位名稱.
		 * @param WC_Order $order  WooCommerce 訂單物件.
		 * @return void
		 */
		private function display_address_phone( $column, $order ) {
			if ( get_option( 'wc_woomp_setting_show_phone', 1 ) !== 'yes' ) {
				return;
			}

			if ( 'billing_address' === $column ) {
				printf(
					'<span class="billing-phone" style="display: block;">電話 %s</span>',
					esc_html( $order->get_billing_phone() )
				);
			} elseif ( 'shipping_address' === $column && $order->get_shipping_phone() ) {
				printf(
					'<span class="shipping-phone" style="display: block;">電話 %s</span>',
					esc_html( $order->get_shipping_phone() )
				);
			}
		}

		/**
		 * 處理列印物流單的批次操作
		 *
		 * @param string $redirect_to 重定向 URL.
		 * @param string $action      執行的動作.
		 * @param array  $ids         訂單 ID 列表.
		 * @return string|void
		 */
		public function print_shipping_note( $redirect_to, $action, $ids ) {
			if ( false !== strpos( $action, 'ry_print_ecpay_' ) ) {
				$redirect_to = add_query_arg(
					[
						'orderid'  => implode( ',', $ids ),
						'type'     => substr( $action, 15 ),
						'noheader' => 1,
					],
					admin_url( 'admin.php?page=ry_print_ecpay_shipping' )
				);
				wp_redirect( $redirect_to );
				exit();
			} elseif ( 'wmp_print_hct' === $action ) {
				set_time_limit( 0 );
				$csv_arr   = [];
				$csv_arr[] = [ '序號', '訂單號', '收件人姓名', '收件人地址', '收件人電話', '託運備註', '商品別編號', '商品數量', '才積重量', '代收貨款', '指定配送日期', '指定配送時間' ];

				$filename = current_time( 'Y-m-d' ) . '-hct-export.csv';

				header( 'Pragma: no-cache' );
				header( 'Expires: 0' );
				header( 'Content-Disposition: attachment;filename="' . $filename . '";' );
				header( 'Content-Type: application/csv; charset=UTF-8' );

				$i = 1;

				foreach ( $ids as $id ) {
					$order     = wc_get_order( $id );
					$csv_arr[] = [
						'serial'           => $i,
						'order_id'         => $id,
						'shipping_name'    => ( $order->get_shipping_last_name() ) ? $order->get_shipping_last_name() . $order->get_shipping_first_name() : $order->get_billing_last_name() . $order->get_billing_first_name(),
						'shipping_address' => ( $order->get_shipping_address_1() ) ? $order->get_shipping_postcode() . $order->get_shipping_state() . $order->get_shipping_city() . $order->get_shipping_address_1() : $order->get_billing_postcode() . $order->get_billing_state() . $order->get_billing_city() . $order->get_billing_address_1(),
						'phone'            => $order->get_billing_phone(),
						'note'             => '',
						'product_num'      => '',
						'qty'              => count( $order->get_items() ),
						'weight'           => '',
						'amount'           => ( 'woomp_cod_gateway' === $order->get_payment_method() ) ? $order->get_total() : '',
						'deliver_date'     => '',
						'deliver_time'     => '',
					];
					++$i;
				}

				$csv_arr_length = count( $csv_arr );
				for ( $j = 0; $j < $csv_arr_length; $j++ ) {
					echo $this->csvstr( $csv_arr[ $j ] ) . "\n";
				}
			}
		}

		/**
		 * 處理批量更改訂單狀態
		 *
		 * @param string $redirect_to 重定向 URL.
		 * @param string $action      執行的動作.
		 * @param array  $post_ids    訂單 ID 列表.
		 * @return string
		 */
		public function handle_bulk_order_status_update( $redirect_to, $action, $post_ids ) {
			if ( ! in_array( $action, [ 'mark_wmp-shipped', 'mark_wmp-in-transit' ] ) ) {
				return $redirect_to;
			}

			$processed_orders = 0;
			$status           = str_replace( 'mark_', '', $action );

			foreach ( $post_ids as $post_id ) {
				$order = wc_get_order( $post_id );
				if ( $order ) {
					$order->update_status( $status );
					++$processed_orders;
				}
			}

			$redirect_to = add_query_arg(
				[
					'processed_count' => $processed_orders,
					'processed_type'  => $status,
				],
				$redirect_to
			);

			return $redirect_to;
		}

		/**
		 * 顯示批量更新訂單狀態的通知訊息
		 *
		 * @return void
		 */
		public function bulk_admin_notices() {
			if ( empty( $_REQUEST['processed_count'] ) || empty( $_REQUEST['processed_type'] ) ) {
				return;
			}

			$count = intval( $_REQUEST['processed_count'] );
			$type  = sanitize_text_field( wp_unslash( $_REQUEST['processed_type'] ) );

			$status_labels = [
				'wmp-shipped'    => '已出貨',
				'wmp-in-transit' => '配送中',
			];

			$message = sprintf(
				/* translators: %1$d: 處理數量, %2$s: 狀態名稱 */
				_n(
					'%1$d 筆訂單已更新為 %2$s',
					'%1$d 筆訂單已更新為 %2$s',
					$count,
					'woomp'
				),
				$count,
				$status_labels[ $type ] ?? $type
			);

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		/**
		 * 確保輸出內容符合 CSV 格式
		 *
		 * @param array $fields CSV 欄位資料.
		 * @return string|false
		 */
		private function csvstr( array $fields ) {
			$f = fopen( 'php://memory', 'r+' );
			if ( fputcsv( $f, $fields ) === false ) {
				return false;
			}
			rewind( $f );
			$csv_line = stream_get_contents( $f );
			return rtrim( $csv_line );
		}

		/**
		 * 新增批次處理選項
		 *
		 * @param array $actions 現有批次處理選項.
		 * @return array 更新後的批次處理選項
		 */
		public function bulk_action( $actions ) {
			$actions['mark_wmp-shipped']    = __( '變更為已出貨', 'woomp' );
			$actions['mark_wmp-in-transit'] = __( '變更為配送中', 'woomp' );

			if ( ! wc_string_to_bool( get_option( 'RY_WT_enabled_ecpay_shipping' ) ) ) {
				return $actions;
			}

			$cvs_type = RY_WT::get_option( 'ecpay_shipping_cvs_type' );

			if ( 'B2C' === $cvs_type ) {
				$actions['ry_print_ecpay_cvs_711']        = __( 'Print ECPay shipping booking note (711)', 'woomp' );
				$actions['ry_print_ecpay_cvs_711_freeze'] = __( 'Print ECPay shipping booking note (711 Freeze)', 'woomp' );
				$actions['ry_print_ecpay_cvs_family']     = __( 'Print ECPay shipping booking note (family)', 'woomp' );
				$actions['ry_print_ecpay_cvs_hilife']     = __( 'Print ECPay shipping booking note (hilife)', 'woomp' );
			} elseif ( 'C2C' === $cvs_type ) {
				$actions['ry_print_ecpay_cvs_711']    = __( 'Print ECPay shipping booking note (711)', 'woomp' );
				$actions['ry_print_ecpay_cvs_family'] = __( 'Print ECPay shipping booking note (family)', 'woomp' );
				$actions['ry_print_ecpay_cvs_hilife'] = __( 'Print ECPay shipping booking note (hilife)', 'woomp' );
				$actions['ry_print_ecpay_cvs_okmart'] = __( 'Print ECPay shipping booking note (okmart)', 'woomp' );
			}

			$actions['ry_print_ecpay_home_tcat'] = __( 'Print ECPay shipping booking note (tcat)', 'woomp' );

			return $actions;
		}

		/**
		 * 增加訂單頁面重新選擇超商按鈕
		 *
		 * @param WC_Order $order WooCommerce 訂單物件.
		 * @return void
		 */
		public function add_choose_cvs_btn( $order ) {
			if ( get_option( RY_WT::$option_prefix . 'enabled_ecpay_shipping', 1 ) === 'yes' ) {
				foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
					$method_class = RY_ECPay_Shipping::get_order_support_shipping( $item );
					if ( $method_class !== false && strpos( $method_class, 'cvs' ) !== false ) {
						echo '<div class="edit_address">
							<button type="button" class="button choose-cvs" style="margin-top: 10px;">' .
						esc_html__( 'Update convenience store', 'woomp' ) .
						'</button><p style="margin-top: 10px;">' .
						esc_html__( 'After choosing cvs, you need update the order to save changing.', 'woomp' ) .
						'</p></div>';
					}
				}
			}
		}

		/**
		 * 註冊重新選擇超商 JS
		 *
		 * @return void
		 */
		public function enqueue_choose_cvs_script() {
			global $pagenow;

			// HPOS 相容：判斷是否在訂單編輯頁面
			$is_order_edit = false;
			$order         = null;

			if ( 'post.php' === $pagenow && isset( $_GET['post'] ) && 'shop_order' === get_post_type( absint( $_GET['post'] ) ) ) {
				$is_order_edit = true;
				$order         = wc_get_order( absint( $_GET['post'] ) );
			} elseif ( isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] && isset( $_GET['action'] ) && 'edit' === $_GET['action'] && isset( $_GET['id'] ) ) {
				$is_order_edit = true;
				$order         = wc_get_order( absint( $_GET['id'] ) );
			}

			if ( $is_order_edit && $order && get_option( RY_WT::$option_prefix . 'enabled_ecpay_shipping', 1 ) === 'yes' ) {
				foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
					$method_class = RY_ECPay_Shipping::get_order_support_shipping( $item );
					if ( $method_class !== false && strpos( $method_class, 'cvs' ) !== false ) {
						list($MerchantID, $HashKey, $HashIV, $CVS_type) = RY_ECPay_Shipping::get_ecpay_api_info();

						$choosed_cvs = '';

						if ( isset( $_POST['MerchantID'] ) && $_POST['MerchantID'] == $MerchantID ) {
							$choosed_cvs = [
								'CVSStoreID'   => wc_clean( wp_unslash( $_POST['CVSStoreID'] ) ),
								'CVSStoreName' => wc_clean( wp_unslash( $_POST['CVSStoreName'] ) ),
								'CVSAddress'   => wc_clean( wp_unslash( $_POST['CVSAddress'] ) ),
								'CVSTelephone' => wc_clean( wp_unslash( $_POST['CVSTelephone'] ) ),
							];
						}

						wp_register_script( 'wmp-admin-shipping', WOOMP_PLUGIN_URL . 'admin/js/choose-cvs.js', [ 'jquery' ], null, false );

						wp_localize_script(
							'wmp-admin-shipping',
							'ECPayInfo',
							[
								'postUrl'  => RY_ECPay_Shipping_Api::get_map_post_url(),
								'postData' => [
									'MerchantID'       => $MerchantID,
									'LogisticsType'    => $method_class::$LogisticsType,
									'LogisticsSubType' => $method_class::$LogisticsSubType . ( ( 'C2C' == $CVS_type ) ? 'C2C' : '' ),
									'IsCollection'     => 'Y',
									'ServerReplyURL'   => esc_url( WC()->api_request_url( 'ry_ecpay_map_callback' ) ),
									'ExtraData'        => 'ry' . $order->get_id(),
								],
								'newStore' => $choosed_cvs,
							]
						);

						wp_enqueue_script( 'wmp-admin-shipping' );

					}
				}
			}
		}
	}

	// 初始化類別
	WooMP_Order::init();
}

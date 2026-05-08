<?php

namespace WOOMPECPAYINVOICE\ShopOrder;

use PostTypes\PostType;
use WOOMPECPAYINVOICE\APIs\EcpayInvoiceHandler;

defined( 'ABSPATH' ) || exit;

class Admin {

	public static function init() {
		$class = new self();
		add_action( 'admin_enqueue_scripts', [ $class, 'enqueue_script' ] );

		// 訂單列表欄位（傳統 + HPOS 雙重掛載）
		add_filter( 'manage_shop_order_posts_columns', [ $class, 'shop_order_columns' ], 11, 1 );
		add_action( 'manage_shop_order_posts_custom_column', [ $class, 'shop_order_column' ], 11, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ $class, 'shop_order_columns' ], 11, 1 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $class, 'shop_order_column' ], 11, 2 );

		// 訂單儲存（HPOS 相容）
		add_action( 'woocommerce_process_shop_order_meta', [ $class, 'update_invoice_data_hpos' ], 10, 2 );
		add_action( 'admin_init', [ $class, 'update_invoice_exist_data' ], 20 );

		if ( 'auto' === get_option( 'wc_woomp_ecpay_invoice_issue_mode' ) ) {
			$invoice_issue_at = str_replace( 'wc-', '', get_option( 'wc_woomp_ecpay_invoice_issue_at' ) );
			add_action( 'woocommerce_order_status_' . $invoice_issue_at, [ $class, 'issue_invoice' ], 10, 1 );
		}

		if ( 'auto' === get_option( 'wc_woomp_ecpay_invoice_invalid_mode' ) ) {
			$invoice_invalid_at = str_replace( 'wc-', '', get_option( 'wc_woomp_ecpay_invoice_invalid_at' ) );
			add_action( 'woocommerce_order_status_' . $invoice_invalid_at, [ $class, 'invalid_invoice' ], 10, 1 );
		}
	}

	public function enqueue_script() {
		wp_register_script( 'woomp_ecpay_invoice', ECPAYINVOICE_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], '3.4.29', true );
		wp_localize_script(
			'woomp_ecpay_invoice',
			'woomp_ecpay_invoice_params',
			[
				'ajax_nonce' => wp_create_nonce( 'invoice_handler' ),
				'post_id'    => get_the_ID(),
			]
		);
		wp_enqueue_script( 'woomp_ecpay_invoice' );
	}

	/**
	 * 後台訂單列表增加單號欄位
	 *
	 * @param array<string> $columns 欄位
	 * @return array<string>
	 */
	public function shop_order_columns( $columns ): array {
		$add_index = array_search( 'shipping_address', array_keys( $columns ) ) + 1;
		$pre_array = array_splice( $columns, 0, $add_index );
		$array     = [
			'wmp_invoice_no' => __( 'Invoice number', 'woomp' ),
		];
		return array_merge( $pre_array, $array, $columns );
	}

	/**
	 * 後台訂單列表增加開立發票按鈕
	 *
	 * @param string $column 欄位名稱
	 * @param int    $post_id POST ID
	 * @return void
	 */
	public function shop_order_column( $column, $post_id ): void {
		$order = \wc_get_order( $post_id );
		if ( 'wmp_invoice_no' !== $column || '0' === $order->get_total() ) {
			return;
		}
		$ecpay_invoice_data   = $order->get_meta( '_ecpay_invoice_data' );
		$invoice_type         = $ecpay_invoice_data['_invoice_type'] ?? 'individual';
		$invoice_company_name = $ecpay_invoice_data['_invoice_company_name'] ?? '';
		$invoice_tax_id       = $ecpay_invoice_data['_invoice_tax_id'] ?? '';

		if ( ! empty( $order->get_meta( '_ecpay_invoice_number' ) ) ) {
			echo \esc_html( $order->get_meta( '_ecpay_invoice_number' ) );
			printf(
				/*html*/'<br><button type="button" class="button btnInvalidInvoice" value="%1$s">%2$s</button>',
				\esc_attr( $post_id ),
				\__( 'Invalid invoice', 'woomp' )
			);
		} else {
			printf(
				/*html*/'<button type="button" class="button btnGenerateInvoice" value="%1$s" data-invoice_type="%2$s" data-invoice_tax_id="%3$s" data-invoice_company_name="%4$s">%5$s</button>',
				\esc_attr( $post_id ),
				\esc_attr( $invoice_type ),
				\esc_attr( $invoice_tax_id ),
				\esc_attr( $invoice_company_name ),
				\__( 'Generate invoice', 'woomp' )
			);
		}
	}

	/**
	 * 更新電子發票資訊
	 */
	public function update_invoice_data( $post_id, $post, $update ) {

		if ( get_option( 'wc_woomp_enabled_ecpay_invoice' ) ) {

			$order        = \wc_get_order( $post_id );
			$invoice_data = [];

			if ( $order && $update ) {
				if ( isset( $_POST['_invoice_type'] ) ) {
					$invoice_data['_invoice_type'] = wp_unslash( $_POST['_invoice_type'] );
				}

				if ( isset( $_POST['_invoice_individual'] ) ) {
					$invoice_data['_invoice_individual'] = wp_unslash( $_POST['_invoice_individual'] );
				} else {
					$invoice_data['_invoice_individual'] = false;
				}

				if ( isset( $_POST['_invoice_carrier'] ) && ! empty( $_POST['_invoice_carrier'] ) ) {
					$invoice_data['_invoice_carrier'] = wp_unslash( $_POST['_invoice_carrier'] );
				}

				if ( isset( $_POST['_invoice_company_name'] ) && ! empty( $_POST['_invoice_company_name'] ) ) {
					$invoice_data['_invoice_company_name'] = wp_unslash( $_POST['_invoice_company_name'] );
				}

				if ( isset( $_POST['_invoice_tax_id'] ) && ! empty( $_POST['_invoice_tax_id'] ) ) {
					$invoice_data['_invoice_tax_id'] = wp_unslash( $_POST['_invoice_tax_id'] );
				}

				if ( isset( $_POST['_invoice_donate'] ) && ! empty( $_POST['_invoice_donate'] ) ) {
					$invoice_data['_invoice_donate'] = wp_unslash( $_POST['_invoice_donate'] );
				}

				if ( isset( $_POST['_invoice_type'] ) && $invoice_data && count( $invoice_data ) > 0 ) {
					$order->update_meta_data( '_ecpay_invoice_data', $invoice_data );
					$order->save();
				}
			}
		}
	}

	/**
	 * HPOS 相容：透過 woocommerce_process_shop_order_meta 更新電子發票資訊
	 *
	 * @param int             $order_id 訂單 ID
	 * @param \WC_Order|null  $order    訂單物件（WC 傳入）
	 */
	public function update_invoice_data_hpos( $order_id, $order = null ) {
		$this->update_invoice_data( $order_id, null, true );
	}

	/**
	 * 讀取既有的訂單電子發票資訊
	 */
	public function update_invoice_exist_data() {
		if ( ! isset( $_GET['post'] ) && ! ( isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] && isset( $_GET['id'] ) ) ) {
			return;
		}

		global $pagenow;

		// HPOS 相容：傳統模式 post.php + shop_order，HPOS 模式 admin.php + wc-orders
		$is_order_edit = false;
		$order         = null;

		if ( 'post.php' === $pagenow && isset( $_GET['post'] ) && 'shop_order' === get_post_type( absint( $_GET['post'] ) ) ) {
			$is_order_edit = true;
			$order         = \wc_get_order( absint( $_GET['post'] ) );
		} elseif ( 'admin.php' === $pagenow && isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] && isset( $_GET['id'] ) ) {
			$is_order_edit = true;
			$order         = \wc_get_order( absint( $_GET['id'] ) );
		}

		if ( get_option( 'wc_woomp_enabled_ecpay_invoice' ) && $is_order_edit && $order ) {
			$invoice_data = [];

			// 電子發票類型.
			if ( $order->get_meta( '_invoice_type' ) && ! empty( $order->get_meta( '_invoice_type' ) ) ) {

				if ( 'personal' === $order->get_meta( '_invoice_type' ) ) {
					$invoice_data['_invoice_type'] = 'individual';
				} else {
					$invoice_data['_invoice_type'] = $order->get_meta( '_invoice_type' );
				}

				// 個人發票類型.
				if ( $order->get_meta( '_invoice_carruer_type' ) && ! empty( $order->get_meta( '_invoice_carruer_type' ) ) ) {
					switch ( $order->get_meta( '_invoice_carruer_type' ) ) {
						case 'ecpay_host':
							$invoice_data['_invoice_individual'] = '雲端發票';
							break;
						case 'MOICA':
							$invoice_data['_invoice_individual'] = '自然人憑證';
							break;
						case 'phone_barcode':
							$invoice_data['_invoice_individual'] = '手機條碼';
							break;
						default:
							// code...
							break;
					}
				}

				// 載具.
				if ( $order->get_meta( '_invoice_carruer_no' ) && ! empty( $order->get_meta( '_invoice_carruer_no' ) ) ) {
					$invoice_data['_invoice_carrier'] = $order->get_meta( '_invoice_carruer_no' );
				}

				// 統一編號.
				if ( $order->get_meta( '_invoice_no' ) && ! empty( $order->get_meta( '_invoice_no' ) ) ) {
					$invoice_data['_invoice_tax_id'] = $order->get_meta( '_invoice_no' );
				}

				// 公司名稱.
				if ( $order->get_meta( '_invoice_no' ) && empty( $invoice_data['_invoice_company_name'] ) && ! empty( $order->get_meta( '_invoice_no' ) ) ) {
					$options = [
						'method'  => 'GET',
						'timeout' => 60,
						'headers' => [
							'Content-Type' => 'application/json',
						],
					];

					$response = wp_remote_request( 'https://company.g0v.ronny.tw/api/show/' . $order->get_meta( '_invoice_no' ), $options );
					$resp     = json_decode( wp_remote_retrieve_body( $response ) );

					$invoice_data['_invoice_company_name'] = $resp->data->公司名稱;
				}

				if ( $order->get_meta( '_invoice_donate_no' ) && ! empty( $order->get_meta( '_invoice_donate_no' ) ) ) {
					$invoice_data['_invoice_donate'] = $order->get_meta( '_invoice_donate_no' );
				}

				if ( count( $invoice_data ) > 0 ) {
					$order->update_meta_data( '_ecpay_invoice_data', $invoice_data );
					$order->delete_meta_data( '_invoice_type' );
					$order->save();
				}
			}
		}
	}

	/**
	 * 開立發票
	 */
	public function issue_invoice( $order_id ) {
		$order = \wc_get_order( $order_id );

		if ( $order->get_meta( '_ecpay_invoice_status' ) === '0' || $order->get_meta( '_ecpay_invoice_number' ) === '' ) {
			$invoice = new EcpayInvoiceHandler();
			$invoice->generate_invoice( $order_id );
		}
	}

	/**
	 * 作廢發票
	 */
	public function invalid_invoice( $order_id ) {
		$order = \wc_get_order( $order_id );
		if ( $order->get_meta( '_ecpay_invoice_status' ) === '1' ) {
			$invoice = new EcpayInvoiceHandler();
			$invoice->invalid_invoice( $order_id );
		}
	}
}

Admin::init();

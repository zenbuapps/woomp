<?php

namespace WOOMPEZPAYINVOICE\ShopOrder;

use WOOMPEZPAYINVOICE\APIs\ezPayInvoiceHandler;

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

		if ( 'auto' === get_option( 'wc_woomp_ezpay_invoice_issue_mode' ) ) {
			$invoice_issue_at = str_replace( 'wc-', '', get_option( 'wc_woomp_ezpay_invoice_issue_at' ) );
			add_action( 'woocommerce_order_status_' . $invoice_issue_at, [ $class, 'issue_invoice' ], 10, 1 );
		}

		if ( 'auto' === get_option( 'wc_woomp_ezpay_invoice_invalid_mode' ) ) {
			$invoice_invalid_at = str_replace( 'wc-', '', get_option( 'wc_woomp_ezpay_invoice_invalid_at' ) );
			add_action( 'woocommerce_order_status_' . $invoice_invalid_at, [ $class, 'invalid_invoice' ], 10, 1 );
		}
	}

	public function enqueue_script() {
		wp_register_script( 'woomp_ezpay_invoice', EZPAYINVOICE_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], '3.4.29', true );
		wp_localize_script(
			'woomp_ezpay_invoice',
			'woomp_ezpay_invoice_params',
			[
				'ajax_nonce' => wp_create_nonce( 'invoice_handler' ),
				'post_id'    => get_the_ID(),
			]
		);
		wp_enqueue_script( 'woomp_ezpay_invoice' );
	}

	/**
	 * 後台訂單列表增加單號欄位
	 */
	public function shop_order_columns( $columns ) {
		$add_index = array_search( 'shipping_address', array_keys( $columns ) ) + 1;
		$pre_array = array_splice( $columns, 0, $add_index );
		$array     = [
			'wmp_invoice_no' => __( 'Invoice number', 'woomp' ),
		];
		return array_merge( $pre_array, $array, $columns );
	}

	public function shop_order_column( $column, $post_id ) {
		$order = wc_get_order( $post_id );
		if ( 'wmp_invoice_no' === $column && '0' !== $order->get_total() ) {

			if ( ! empty( $order->get_meta( '_ezpay_invoice_number' ) ) ) {
				echo esc_html( $order->get_meta( '_ezpay_invoice_number' ) );
				echo '<br><button type="button" class="button btnInvalidInvoiceEzPay" value="' . esc_attr( $post_id ) . '">' . __( 'Invalid invoice', 'woomp' ) . '</button>';
			} else {
				echo '<button type="button" class="button btnGenerateInvoiceEzPay" value="' . esc_attr( $post_id ) . '">' . __( 'Generate invoice', 'woomp' ) . '</button>';
			}
		}
	}

	/**
	 * HPOS 相容：透過 woocommerce_process_shop_order_meta 更新電子發票資訊
	 *
	 * @param int             $order_id 訂單 ID
	 * @param \WC_Order|null  $order    訂單物件
	 */
	public function update_invoice_data_hpos( $order_id, $order = null ) {
		$this->update_invoice_data( $order_id, null, true );
	}

	/**
	 * 更新電子發票資訊
	 */
	public function update_invoice_data( $post_id, $post, $update ) {

		if ( get_option( 'wc_woomp_enabled_ezpay_invoice' ) ) {

			$order        = \wc_get_order( $post_id );
			$invoice_data = [];

			if ( $order && $update ) {
				if ( isset( $_POST['_ezpay_invoice_type'] ) ) {
					$invoice_data['_ezpay_invoice_type'] = wp_unslash( $_POST['_ezpay_invoice_type'] );
				}

				if ( isset( $_POST['_ezpay_invoice_individual'] ) ) {
					$invoice_data['_ezpay_invoice_individual'] = wp_unslash( $_POST['_ezpay_invoice_individual'] );
				} else {
					$invoice_data['_ezpay_invoice_individual'] = false;
				}

				if ( isset( $_POST['_ezpay_invoice_carrier'] ) && ! empty( $_POST['_ezpay_invoice_carrier'] ) ) {
					$invoice_data['_ezpay_invoice_carrier'] = wp_unslash( $_POST['_ezpay_invoice_carrier'] );
				}

				if ( isset( $_POST['_ezpay_invoice_company_name'] ) && ! empty( $_POST['_ezpay_invoice_company_name'] ) ) {
					$invoice_data['_ezpay_invoice_company_name'] = wp_unslash( $_POST['_ezpay_invoice_company_name'] );
				}

				if ( isset( $_POST['_ezpay_invoice_tax_id'] ) && ! empty( $_POST['_ezpay_invoice_tax_id'] ) ) {
					$invoice_data['_ezpay_invoice_tax_id'] = wp_unslash( $_POST['_ezpay_invoice_tax_id'] );
				}

				if ( isset( $_POST['_ezpay_invoice_donate'] ) && ! empty( $_POST['_ezpay_invoice_donate'] ) ) {
					$invoice_data['_ezpay_invoice_donate'] = wp_unslash( $_POST['_ezpay_invoice_donate'] );
				}

				if ( isset( $_POST['_ezpay_invoice_type'] ) && $invoice_data && count( $invoice_data ) > 0 ) {
					$order->update_meta_data( '_ezpay_invoice_data', $invoice_data );
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

		if ( $order->get_meta( '_ezpay_invoice_status' ) === '0' || $order->get_meta( '_ezpay_invoice_number' ) === '' ) {
			$invoice = new EzpayInvoiceHandler();
			$invoice->generate_invoice( $order_id );
		}
	}

	/**
	 * 作廢發票
	 */
	public function invalid_invoice( $order_id ) {
		$order = \wc_get_order( $order_id );
		if ( $order->get_meta( '_ezpay_invoice_status' ) === '1' ) {
			$invoice = new EzpayInvoiceHandler();
			$invoice->invalid_invoice( $order_id );
		}
	}
}

Admin::init();

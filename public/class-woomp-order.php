<?php

/**
 * 前台訂單相關功能
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WooMP_Order_Public' ) ) {
	class WooMP_Order_Public {
		/**
		 * 初始化
		 */
		public static function init() {
			$class = new self();
			add_action( 'woocommerce_view_order', [ $class, 'display_order_shipping_number' ] );
		}

		/**
		 * 前台訂單查詢顯示物流單號
		 */
		public function display_order_shipping_number( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}

			// 綠界物流單號（HPOS 相容：使用 $order->get_meta()）
			$ecpay_shipping_info = $order->get_meta( '_ecpay_shipping_info', false );
			if ( $ecpay_shipping_info ) {
				$ecpay_no = [];
				foreach ( $ecpay_shipping_info as $meta ) {
					$data = $meta->value ?? $meta;
					if ( is_array( $data ) ) {
						foreach ( $data as $i ) {
							if ( isset( $i['PaymentNo'], $i['ValidationNo'] ) ) {
								$shipping_no = $i['PaymentNo'] . ' ' . $i['ValidationNo'];
								$ecpay_no[]  = $shipping_no;
							}
						}
					}
				}
				if ( count( $ecpay_no ) > 0 ) { ?>
				<h2 class="woocommerce-order-details__title">
					<?php echo __( 'Ecpay Shipping details', 'woomp' ); ?>
				</h2>

				<table class="woocommerce-table woocommerce-table--shipping-details shop_table shipping_details">
					<thead>
						<tr>
							<th class="woocommerce-table__shipping-no shipping-no">
					<?php echo __( 'Shipping payment no', 'woomp' ); ?>
							</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $ecpay_no as $value ) : ?>
						<tr>

							<td class="woocommerce-table__shipping-no shipping-no">
						<?php echo esc_html( $value ); ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
					<?php
				}
			}

			// 自行輸入物流單號（HPOS 相容：使用 $order->get_meta()）
			$wmp_shipping_no = $order->get_meta( 'wmp_shipping_no', true );
			if ( $wmp_shipping_no ) {
				?>
				<h2 class="woocommerce-order-details__title">
				<?php echo __( 'Shipping details', 'woomp' ); ?>
				</h2>

				<table class="woocommerce-table woocommerce-table--shipping-details shop_table shipping_details">
					<thead>
						<tr>
							<th class="woocommerce-table__shipping-no shipping-no">
				<?php echo __( 'Shipping payment no', 'woomp' ); ?>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td class="woocommerce-table__shipping-no shipping-no">
				<?php
				echo esc_html( $wmp_shipping_no );
				?>
							</td>
						</tr>
					</tbody>
				</table>
				<?php
			}
		}
	}
	WooMP_Order_Public::init();
}

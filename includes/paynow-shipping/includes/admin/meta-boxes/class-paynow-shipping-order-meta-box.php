<?php
/**
 * PayNow_Shipping_Order_Meta_Box class file.
 *
 * @package paynow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Display shipping info for PayNow shipping order.
 */
class PayNow_Shipping_Order_Meta_Box {

	/**
	 * 在訂單編輯頁面註冊 meta box（HPOS 相容）
	 *
	 * @param string                $post_type Post type 或 screen ID
	 * @param \WP_Post|\WC_Order $post_or_order Post 或 Order 物件
	 * @return void
	 */
	public static function add_meta_box( $post_type, $post_or_order ) {
		if ( ! in_array( $post_type, Woomp_HPOS_Helper::get_order_screen_ids(), true ) ) {
			return;
		}

		global $theorder;
		if ( ! is_object( $theorder ) ) {
			$theorder = Woomp_HPOS_Helper::get_order( $post_or_order );
		}
		if ( ! $theorder ) {
			return;
		}

		foreach ( $theorder->get_items( 'shipping' ) as $item_id => $item ) {
			if ( PayNow_Shipping::is_paynow_shipping( $item->get_method_id() ) !== false ) {
				foreach ( Woomp_HPOS_Helper::get_order_screen_ids() as $screen ) {
					add_meta_box( 'paynow-shipping-info', __( 'PayNow Shipping Info', 'paynow-shipping' ), [ __CLASS__, 'output' ], $screen, 'side', 'high' );
				}
				break;
			}
		}
	}

	/**
	 * 輸出 meta box 內容（HPOS 相容）
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Post 或 Order 物件
	 * @return void
	 */
	public static function output( $post_or_order ) {
		global $theorder;

		if ( ! is_object( $theorder ) ) {
			$theorder = Woomp_HPOS_Helper::get_order( $post_or_order );
		}
		if ( ! $theorder ) {
			return;
		}

		// paynow 物流單號.
		echo '<table>';
		$order_id = $theorder->get_id();
		echo '<tr><th><div id="order-id" data-order-id="' . esc_html( $order_id ) . '">' . esc_html__( 'PayNow Logistic Number', 'paynow-shipping' ) . '</div></th><td>' . esc_html( $theorder->get_meta( PayNow_Shipping_Order_Meta::LogisticNumber ) ) . '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Logistic Service', 'paynow-shipping' ) . '</th><td>' . esc_html( $theorder->get_meta( PayNow_Shipping_Order_Meta::LogisticService ) ) . '</td></tr>';

		$service_id    = $theorder->get_meta( PayNow_Shipping_Order_Meta::LogisticServiceId );
		$payment_no    = $theorder->get_meta( PayNow_Shipping_Order_Meta::PaymentNo );
		$validation_no = $theorder->get_meta( PayNow_Shipping_Order_Meta::ValidationNo );

		$status = $theorder->get_meta( PayNow_Shipping_Order_Meta::Status );
		if ( '0' === $status ) {
			$status_txt = '訂單成立中';
		} elseif ( '1' === $status ) {
			$status_txt = '無效訂單';
		} else {
			$status_txt = 'N/A';
		}
		$delivery_status    = ( empty( $theorder->get_meta( PayNow_Shipping_Order_Meta::DeliveryStatus ) ) ) ? 'N/A' : $theorder->get_meta( PayNow_Shipping_Order_Meta::DeliveryStatus );
		$logistic_code      = ( empty( $theorder->get_meta( PayNow_Shipping_Order_Meta::LogisticCode ) ) ) ? 'N/A' : $theorder->get_meta( PayNow_Shipping_Order_Meta::LogisticCode );
		$logistic_code_desc = ( empty( $theorder->get_meta( PayNow_Shipping_Order_Meta::DetailStatusDesc ) ) ) ? 'N/A' : $theorder->get_meta( PayNow_Shipping_Order_Meta::DetailStatusDesc );

		$update_at = $theorder->get_meta( PayNow_Shipping_Order_Meta::StatusUpdateAt );

		// 物流商貨運編號.
		if ( PayNow_Shipping_Logistic_Service::SEVEN === $service_id || PayNow_Shipping_Logistic_Service::SEVENBULK === $service_id || PayNow_Shipping_Logistic_Service::SEVENFROZEN_C2C === $service_id ) {
			$shipping_no = $payment_no . $validation_no;
		} else {
			$shipping_no = $payment_no;
		}

		echo '<tr><th>' . esc_html__( 'Payment NO', 'paynow-shipping' ) . '</th><td>' . esc_html( $shipping_no ) . '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Status', 'paynow-shipping' ) . '</th><td>' . esc_html( $status_txt ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Delivery Status', 'paynow-shipping' ) . '</th><td>' . esc_html( $delivery_status ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Logistic Code', 'paynow-shipping' ) . '</th><td>' . esc_html( $logistic_code ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Logistic Code Description', 'paynow-shipping' ) . '</th><td>' . esc_html( $logistic_code_desc ) . '</td></tr>';

		do_action( 'paynow_shipping_admin_meta_before_last_query', $theorder );

		foreach ( $theorder->get_items( 'shipping' ) as $item_id => $item ) {
			if ( PayNow_Shipping::is_paynow_shipping( $item->get_method_id() ) !== false ) {
				if ( ! empty( $theorder->get_meta( PayNow_Shipping_Order_Meta::LogisticNumber ) && $theorder->get_meta( PayNow_Shipping_Order_Meta::Status ) !== '1' ) ) {
					$order_btn = '<button class="button renew-order" data-id="' . $theorder->get_id() . '">重新取號</button>';
				} else {
					$order_btn = '<button class="button create-order" data-id="' . $theorder->get_id() . '">取號</button>';
				}
			}
		}

		echo '<tr><th>' . esc_html__( 'Logistic Status Last Query', 'paynow-shipping' ) . '</th><td>' . esc_html( $update_at ) . '</td></tr>';

		echo '<tr id="paynow-action"><th>物流單動作</th><td>' . $order_btn . '<button class="button print-label" data-id=' . esc_html( $order_id ) . ' data-service="' . esc_html( $service_id ) . '">列印</button><button class="button update-delivery-status" data-id="' . esc_html( $order_id ) . '">更新</button><button class="button cancel-shipping" data-id="' . esc_html( $order_id ) . '">取消</button></td></tr>';
		echo '</table>';
		?>


		<?php
		wc_enqueue_js(
			'jQuery(function($) {
$(".print-label").click(function(){
    window.open(ajaxurl + "?" + $.param({
        action: "paynow_shipping_print_label",
        orderids: $(this).data("id"),
		service: $(this).data("service"),
    }), "_blank", "toolbar=yes,scrollbars=yes,resizable=yes");
});
});'
		);
	}
}

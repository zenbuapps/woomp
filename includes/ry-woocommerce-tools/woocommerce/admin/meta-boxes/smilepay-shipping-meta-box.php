<?php
class RY_SmilePay_Shipping_Meta_Box {

	public static function add_meta_box( $post_type, $post ) {
		// HPOS 相容：同時支援 shop_order 和 HPOS screen ID
		if ( in_array( $post_type, Woomp_HPOS_Helper::get_order_screen_ids(), true ) ) {
			global $theorder;
			if ( ! is_object( $theorder ) ) {
				$order = Woomp_HPOS_Helper::get_order( $post );
				if ( $order ) {
					$theorder = $order;
				}
			}

			if ( ! is_object( $theorder ) ) {
				return;
			}

			foreach ( $theorder->get_items( 'shipping' ) as $item_id => $item ) {
				if ( RY_SmilePay_Shipping::get_order_support_shipping( $item ) !== false ) {
					// HPOS 相容：為所有 screen ID 註冊 meta box
					foreach ( Woomp_HPOS_Helper::get_order_screen_ids() as $screen ) {
						add_meta_box( 'ry-smilepay-shipping-info', __( 'SmilePay shipping info', 'ry-woocommerce-tools' ), [ __CLASS__, 'output' ], $screen, 'normal', 'high' );
					}
					break;
				}
			}
		}
	}

	public static function output( $post ) {
		global $theorder;

		// HPOS 相容：使用 Helper 取得訂單物件
		if ( ! is_object( $theorder ) ) {
			$theorder = Woomp_HPOS_Helper::get_order( $post );
		}

		if ( ! $theorder ) {
			return;
		}

		$order_id = $theorder->get_id();

		$shipping_list = $theorder->get_meta( '_smilepay_shipping_info', true );
		if ( ! is_array( $shipping_list ) ) {
			$shipping_list = [];
		} ?>
<table cellpadding="0" cellspacing="0" class="widefat">
	<thead>
		<tr>
			<th>
		<?php echo __( 'SmilePay shipping ID', 'ry-woocommerce-tools' ); ?>
			</th>
			<th>
		<?php echo __( 'Shipping no', 'ry-woocommerce-tools' ); ?>
			</th>
			<th>
		<?php echo __( 'Store ID', 'ry-woocommerce-tools' ); ?>
			</th>
			<th>
		<?php echo __( 'Shipping status', 'ry-woocommerce-tools' ); ?>
			</th>
			<th>
		<?php echo __( 'declare amount', 'ry-woocommerce-tools' ); ?>
			</th>
			<th>
		<?php echo __( 'Collection of money', 'ry-woocommerce-tools' ); ?>
			</th>
			<th>
		<?php echo __( 'Shipping status last change time', 'ry-woocommerce-tools' ); ?>
			</th>
			<th>
		<?php echo __( 'Shipping create time', 'ry-woocommerce-tools' ); ?>
			</th>
			<th>
		<?php echo __( 'Shipping booking note', 'ry-woocommerce-tools' ); ?>
			</th>
		</tr>
	</thead>
	<tbody>
		<?php
		foreach ( $shipping_list as $item ) {
			$item['edit']   = wc_string_to_datetime( $item['edit'] );
			$item['create'] = wc_string_to_datetime( $item['create'] );
			?>
		<tr>
			<td>
			<?php echo $item['ID']; ?>
			</td>
			<td>
			<?php echo $item['PaymentNo'] . ' ' . $item['ValidationNo']; ?>
			</td>
			<td>
			<?php echo $item['storeID']; ?>
			</td>
			<td>
			<?php echo $item['status']; ?>
			</td>
			<td>
			<?php echo $item['amount']; ?>
			</td>
			<td>
			<?php echo ( $item['IsCollection'] == '1' ) ? __( 'Yes' ) : __( 'No' ); ?>
			</td>
			<td>
			<?php /* translators: %1$s: date %2$s: time */ ?>
			<?php printf( _x( '%1$s %2$s', 'Datetime', 'ry-woocommerce-tools' ), $item['edit']->date_i18n( wc_date_format() ), $item['edit']->date_i18n( wc_time_format() ) ); ?>
			</td>
			<td>
			<?php /* translators: %1$s: date %2$s: time */ ?>
			<?php printf( _x( '%1$s %2$s', 'Datetime', 'ry-woocommerce-tools' ), $item['create']->date_i18n( wc_date_format() ), $item['create']->date_i18n( wc_time_format() ) ); ?>
			</td>
			<td>
			<?php
			if ( empty( $item['PaymentNo'] ) ) {
				?>
				<button type="button" class="button get_no" data-orderid="<?php echo $order_id; ?>" data-id="<?php echo $item['ID']; ?>"><?php echo __( 'Get no', 'ry-woocommerce-tools' ); ?></button>
				<?php
			} else {
				?>
				<button type="button" class="button print_info" data-orderid="<?php echo $order_id; ?>" data-id="<?php echo $item['ID']; ?>"><?php echo __( 'Print', 'ry-woocommerce-tools' ); ?></button>
				<?php
			}
			?>
			</td>
		</tr>
			<?php
		}
		?>
	</tbody>
</table>
		<?php
		wc_enqueue_js(
			'jQuery(function($) {
$(".get_no").click(function(){
    window.location = ajaxurl + "?" + $.param({
        action: "RY_SmilePay_Shipping_get_no",
        orderid: $(this).data("orderid"),
        id: $(this).data("id")
    });
});
$(".print_info").click(function(){
    window.open(ajaxurl + "?" + $.param({
        action: "RY_SmilePay_Shipping_print",
        orderid: $(this).data("orderid"),
        id: $(this).data("id")
    }), "_blank", "toolbar=yes,scrollbars=yes,resizable=yes");
});
});'
		);
	}
}

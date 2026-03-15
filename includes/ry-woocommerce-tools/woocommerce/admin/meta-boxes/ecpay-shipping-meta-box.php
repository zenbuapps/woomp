<?php
class RY_ECPay_Shipping_Meta_Box {

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
				if ( RY_ECPay_Shipping::get_order_support_shipping( $item ) !== false ) {
					// HPOS 相容：為所有 screen ID 註冊 meta box
					foreach ( Woomp_HPOS_Helper::get_order_screen_ids() as $screen ) {
						add_meta_box( 'ry-ecpay-shipping-info', __( 'ECPay shipping info', 'ry-woocommerce-tools' ), [ __CLASS__, 'output' ], $screen, 'normal', 'high' );
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

		$shipping_list = $theorder->get_meta( '_ecpay_shipping_info', true );
		if ( ! is_array( $shipping_list ) ) {
			$shipping_list = [];
		} ?>
		<div class="shipping-loading">
			<div class="lds-spinner"><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div></div>
		</div>
<table cellpadding="0" cellspacing="0" class="widefat">
	<thead>
		<tr>
			<th>&nbsp;</th>
			<th>
		<?php echo __( 'ECPay shipping ID', 'ry-woocommerce-tools' ); ?>
			</th>
			<th>
		<?php echo __( 'Shipping Type', 'ry-woocommerce-tools' ); ?>
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
		<?php echo __( 'Shipping status last change time', 'woomp' ); ?>
			</th>
			<th>
		<?php echo __( 'Shipping create time', 'woomp' ); ?>
			</th>
			<th>
		<?php echo __( 'Shipping booking note', 'woomp' ); ?>
			</th>
		</tr>
	</thead>
	<tbody>
		<?php
		foreach ( $shipping_list as $item ) {
			$item['edit']   = wc_string_to_datetime( $item['edit'] );
			$item['create'] = wc_string_to_datetime( $item['create'] );
			?>
		<tr class="shippingId-<?php echo $item['ID']; ?>">
			<td>
				<a class="button btnEcpayShippingCvsRemove" data-order="<?php echo $order_id; ?>" data-item="<?php echo $item['ID']; ?>"  href="#"><?php echo __( '刪除', 'ry-woocommerce-tools' ); ?></a>
			</td>
			<td>
			<?php echo $item['ID']; ?>
			</td>
			<?php if ( $item['LogisticsType'] == 'CVS' ) { ?>
			<td>
				<?php echo _x( 'CVS', 'shipping type', 'ry-woocommerce-tools' ); ?>
			</td>
			<td>
				<?php echo $item['PaymentNo'] . $item['ValidationNo']; ?>
			</td>
			<td>
				<?php echo $item['store_ID']; ?>
			</td>
			<?php } else { ?>
			<td>
				<?php echo _x( 'Home', 'shipping type', 'ry-woocommerce-tools' ); ?>
			</td>
			<td>
				<?php echo $item['BookingNote']; ?>
			</td>
			<td></td>
			<?php } ?>
			<td>
			<?php echo $item['status_msg']; ?>
			</td>
			<td>
			<?php echo $item['amount']; ?>
			</td>
			<td>
			<?php echo ( $item['IsCollection'] == 'Y' ) ? __( 'Yes' ) : __( 'No' ); ?>
			</td>
			<td>
			<?php /* translators: %1$s: date %2$s: time */ ?>
			<?php printf( _x( '%1$s %2$s', 'Datetime', 'ry-woocommerce-tools' ), $item['edit']->date_i18n( wc_date_format() ), $item['edit']->date_i18n( wc_time_format() ) ); ?>
			</td>
			<td>
			<?php printf( _x( '%1$s %2$s', 'Datetime', 'ry-woocommerce-tools' ), $item['create']->date_i18n( wc_date_format() ), $item['create']->date_i18n( wc_time_format() ) ); ?>
			</td>
			<td>
				<a class="button" target="_blank" rel="noopener noreffer" href="
			<?php
			echo esc_url(
				add_query_arg(
					[
						'orderid'  => $order_id,
						'id'       => $item['ID'],
						'noheader' => 1,
					],
					admin_url( 'admin.php?page=ry_print_ecpay_shipping' )
				)
			);
			?>
																				"><?php echo __( 'Print', 'ry-woocommerce-tools' ); ?></a>
			</td>
		</tr>
			<?php
		}
		?>
	</tbody>
</table>
		<?php
	}
}

<?php
/**
 * 訂閱發票管理 Metabox
 */

declare (strict_types = 1);

namespace J7\Woomp\Admin\InoviceManagement;

// 如果沒有啟用訂閱，就不初始化訂閱發票管理
if (!class_exists('WC_Subscriptions')) {
	return;
}

/**
 * Class InoviceManagement
 */
final class InoviceManagement {

	const METABOX_ID = 'woomp_invoice_management';

	/**
	 * 是否啟用立吉富電子發票
	 *
	 * @var bool
	 */
	private $is_paynow_einvoice_active;

	/**
	 * Constructor
	 */
	public function __construct() {

		$this->is_paynow_einvoice_active = \get_option( 'wc_settings_tab_active_paynow_einvoice' ) === 'yes';

		// 目前只支援立吉富電子發票 其他的未來再慢慢支援
		if (!$this->is_paynow_einvoice_active) {
			return;
		}

		\add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		\add_action( 'save_post', [ $this, 'save_meta_box' ], 10, 3 );

		\add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	/**
	 * Adds the meta box container.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	public function add_meta_box( string $post_type ): void {
		// Limit meta box to certain post types.
		$post_types = [ 'shop_subscription' ];

		if ( in_array( $post_type, $post_types, true ) ) {
			\add_meta_box( self::METABOX_ID, __( '訂閱發票資訊(下期開始以下方資訊開立發票)', 'woomp' ), [ $this, 'render_meta_box_content' ], $post_types, 'normal', 'default' );
		}
	}

	/**
	 * Render Meta Box content.
	 *
	 * @param \WP_Post $post    The current post.
	 * @return void
	 */
	public function render_meta_box_content( $post ): void {
		// HPOS 相容：從 $post 取得訂單或訂閱物件
		$order_or_subscription = Woomp_HPOS_Helper::get_order( $post );
		if ( ! $order_or_subscription ) {
			// 若為訂閱，嘗試以 wcs_get_subscription 取得
			$post_id = $post instanceof \WP_Post ? $post->ID : $post->get_id();
			$order_or_subscription = function_exists( 'wcs_get_subscription' ) ? \wcs_get_subscription( $post_id ) : null;
		}

		echo '<div class="woomp">';

		echo '<div class="grid grid-cols-4 gap-4 [&_label]:block">';
		\woocommerce_wp_select(
			[
				'id'      => 'woomp_invoice_management_select',
				'label'   => '請選擇電子發票廠商',
				'class'   => ' w-full ',
				'options' => [
					'paynow' => '立吉富 PAYNOW',
				],
			]
			);
		echo '</div>';

		$fields = \Paynow_Einvoice::get_einvoice_fields();
		echo '<div class="grid grid-cols-4 gap-4 [&_label]:block">';
		foreach ($fields as $field => $args) {
			$input_type = match ($args['type']) {
				'text' => 'woocommerce_wp_text_input',
				'select' => 'woocommerce_wp_select',
				default => 'woocommerce_wp_text_input',
			};

			// HPOS 相容：使用物件方法取代 get_post_meta
			$value = $order_or_subscription ? $order_or_subscription->get_meta( "_{$field}", true ) : '';

			$input_type(
				[
					'id'            => $field,
					'label'         => $args['label'] ?? '',
					'placeholder'   => $args['placeholder'] ?? '',
					'value'         => $value,
					'class'         => ' w-full ',
					'wrapper_class' => ( !$value && !\in_array($field, [ 'paynow_ei_carrier_type', 'paynow_ei_issue_type' ], true) ? ' tw-hidden' : '' ),
					'options'       => $args['options'] ?? [],
				]
			);
		}
		echo '</div>';

		echo '</div>';
	}


	/**
	 * Enqueue scripts
	 */
	public function enqueue_scripts(): void {
		\wp_enqueue_script( 'paynow-einvoice', WOOMP_PLUGIN_URL . 'includes/paynow-einvoice/public/js/paynow-einvoice-public.js', [ 'jquery' ], '1.0.0', false );
	}

	/**
	 * 把發票資訊存入訂閱
	 *
	 * @param int      $post_id Post ID
	 * @param \WP_Post $post Post object
	 * @param bool     $update Whether this is an existing post being updated or not.
	 * @return void
	 */
	public function save_meta_box( $post_id, $post, $update ) {
		$post_type = $post->post_type;

		if (!$update) {
			return;
		}

		if (!in_array($post_type, [ 'shop_order' ,'shop_subscription' ], true)) {
			return;
		}

		// HPOS 相容：使用物件方法操作 meta
		$object = null;
		if ( 'shop_subscription' === $post_type && function_exists( 'wcs_get_subscription' ) ) {
			$object = \wcs_get_subscription( $post_id );
		} else {
			$object = \wc_get_order( $post_id );
		}

		if ( ! $object ) {
			return;
		}

		$fields = \Paynow_Einvoice::get_einvoice_fields();
		foreach ($fields as $field => $args) {
			$value = \sanitize_text_field( $_POST[ $field ] ?? '' ); // phpcs:ignore
			$object->update_meta_data( "_{$field}", $value );
		}
		$object->save();
	}
}

new InoviceManagement();

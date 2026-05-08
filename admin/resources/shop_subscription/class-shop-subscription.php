<?php
/**
 * ShopSubscription
 */

declare (strict_types = 1);

namespace J7\Woomp\Admin\Resources\ShopSubscription;

// 如果沒有啟用訂閱，就不初始化卡片管理
if (!class_exists('WC_Subscriptions')) {
	return;
}

/**
 * Class ShopSubscription
 */
final class ShopSubscription {


	/**
	 * Constructor
	 */
	public function __construct() {
		\add_filter( 'woocommerce_can_subscription_be_updated_to_active', [ __CLASS__, 'allowed_canceled_wcs_reactive' ], 10, 2 );
		\add_action( 'woocommerce_subscription_status_updated', [ __CLASS__, 'remove_cancelled_timestamp_when_reactive' ], 10, 3 );

		\add_action( 'plugins_loaded', [ __CLASS__, 'run_after_plugins_loaded' ] );
	}

	/**
	 * 讓已取消的訂閱，可以重新啟用
	 *
	 * @param bool             $can_be_updated 訂閱是否可更新.
	 * @param \WC_Subscription $subscription 訂閱物件.
	 * @return bool 如果訂閱已取消，返回 true，否則返回 $can_be_updated.
	 */
	public static function allowed_canceled_wcs_reactive( $can_be_updated, $subscription ) {

		$is_cancelled = $subscription->has_status( 'cancelled' );

		if ( $is_cancelled ) {
			return true;
		}

		return $can_be_updated;
	}

	/**
	 * 取消訂閱時，移除訂閱的取消時間戳記
	 *
	 * @param \WC_Subscription $subscription 訂閱物件.
	 * @param string           $new_status 新狀態.
	 * @param string           $old_status 舊狀態.
	 */
	public static function remove_cancelled_timestamp_when_reactive( \WC_Subscription $subscription, string $new_status, string $old_status ): void {

		if ('cancelled' !== $old_status || 'active' !== $new_status) {
			return;
		}
		// HPOS 相容：使用物件方法取代 delete_post_meta
		$subscription->delete_meta_data( '_schedule_cancelled' );
		$subscription->save();
	}


	/**
	 * 整合 WooCommerce Subscriptions
	 */
	public static function run_after_plugins_loaded() {
		\add_filter( 'wc_subscriptions_object_data', [ __CLASS__, 'sync_invoice_data_at_renew_subscription' ], 100, 4 );
	}



	/**
	 * 產生新訂閱時，複製訂單時同步發票資訊
	 * 1. 每次訂閱訂單續訂時，複製訂單時觸發，將發票資訊同步至新訂單 #35
	 * 2. 避免有人更換預設的付款方式，結果還是用原始(第一筆)訂單的付款資訊扣款
	 *
	 * @param array                                                          $data order data
	 * @param \WC_Order|\WC_Subscription                                     $to_object (續訂)|(新訂)
	 * @param \WC_Subscription|\Automattic\WooCommerce\Admin\Overrides\Order $from_object (續訂)|(新訂)
	 * @param string                                                         $copy_type  "renewal_order"(續訂)|"subscription"(新訂)
	 *
	 * @return array
	 */
public static function sync_invoice_data_at_renew_subscription( $data, $to_object, $from_object, $copy_type ) { // phpcs:ignore
		if ( ! method_exists( $from_object, 'get_meta' ) || ! method_exists( $to_object, 'update_meta_data' ) ) {
			return $data;
		}

		$fields = [
			// 處理 綠界 ECPAY 發票
			'_ecpay_invoice_data',
			// 處理藍新 EZPAY 發票
			'_ezpay_invoice_data',
			// 處理 立吉富 PAYNOW 發票
			'_paynow_ei_issue_type',
			'_paynow_ei_carrier_type',
			'_paynow_ei_buyer_name',
			'_paynow_ei_ubn',
			'_paynow_ei_donate_org',
			'_paynow_ei_carrier_num',
			// '_paynow_ei_issued', // 不需要同步此欄位
			// '_paynow_ei_result_invoice_number', // 不需要同步此欄位
			// '_paynow_invoice_url', // 不需要同步此欄位
		];
		foreach ( $fields as $field ) {
			// HPOS 相容：使用物件方法判斷 meta 是否存在（語義與原始 metadata_exists 一致）。
			if ( ! $from_object->meta_exists( $field ) ) {
				continue;
			}
			$to_object->update_meta_data( $field, $from_object->get_meta( $field, true ) );
		}

		return $data;
	}
}

new ShopSubscription();

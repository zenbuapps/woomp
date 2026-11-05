<?php
/**
 * PayNow_Shipping_Response class file.
 *
 * @package paynow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handling PayNow shipping response callback
 */
class PayNow_Shipping_Response {

	/**
	 * 後台改門市 returnUrl 帶的 token 參數名稱。
	 */
	const CVS_TOKEN_QUERY_ARG = 'cvs_token';

	/**
	 * 改門市 token 的 transient 前綴，後面接 token 的雜湊（資料庫不存 token 原文）。
	 */
	const CVS_TOKEN_TRANSIENT_PREFIX = 'paynow_cvs_token_';

	/**
	 * 改門市 token 的有效秒數。
	 *
	 * Token 在載入訂單頁時產生，管理員可能開著分頁很久才按，所以不設太短。
	 */
	const CVS_TOKEN_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * 貨態回呼網址帶的 token 參數名稱。
	 */
	const STATUS_TOKEN_QUERY_ARG = 'token';

	/**
	 * 存放每站獨立貨態回呼 token 的 option。
	 */
	const STATUS_TOKEN_OPTION = 'paynow_shipping_status_callback_token';

	/**
	 * 是否已強制要求貨態回呼帶 token 的 option（收到第一筆 token 正確的回呼後設為 yes）。
	 */
	const STATUS_TOKEN_REQUIRED_OPTION = 'paynow_shipping_status_callback_token_required';

	/**
	 * 最後一筆已處理貨態的訂單 meta（物流單號:貨態），用來擋重送。
	 */
	const LAST_STATUS_META = '_paynow_shipping_last_status_callback';

	/**
	 * 回呼內向 PayNow 查詢貨態的逾時秒數。
	 *
	 * 查詢可由匿名請求觸發，不沿用預設的 45 秒，避免大量偽造請求占住 PHP worker。
	 */
	const STATUS_QUERY_TIMEOUT = 10;

	/**
	 * Class instance.
	 *
	 * @var PayNow_Shipping_Response
	 */
	private static $instance;

	/**
	 * Initialize class and add hooks.
	 *
	 * @return void
	 */
	public static function init() {
		self::get_instance();

		// 選擇超商後，接收超商資訊.
		add_action( 'woocommerce_api_paynow_choose_cvs_callback', [ self::get_instance(), 'paynow_choose_cvs_callback' ] );

		// 物流貨態回傳.
		add_action( 'woocommerce_api_paynow_shipping_order_callback', [ self::get_instance(), 'paynow_receive_order_status_update' ] );

		// 根據貨態更新訂單狀態.
		add_action( 'paynow_update_shipping_order_status', [ self::get_instance(), 'paynow_update_order_status_after_received_update' ], 10, 2 );

		// 在物流設定頁顯示含 token 的貨態回呼網址.
		add_filter( 'paynow_shipping_settings', [ self::get_instance(), 'add_status_callback_settings' ] );
	}

	/**
	 * Choose CVS callback when use return from CVS map.
	 *
	 * @return void
	 */
	public static function paynow_choose_cvs_callback() {
		// phpcs:disable WordPress.Security.NonceVerification
		$posted   = wc_clean( wp_unslash( $_POST ) );
		$cvs_info = [];

		if ( ! empty( $posted ) ) {

			PayNow_Shipping::log( 'recieve cvs callback from PayNow:' . wc_print_r( $posted, true ) );

			foreach ( [ 'service', 'storename', 'storeid', 'storeaddress' ] as $key ) {
				if ( isset( $posted[ $key ] ) && is_string( $posted[ $key ] ) ) {
					$cvs_info[ 'paynow_' . $key ] = $posted[ $key ];
				}
			}

			// May received additional data for CVS Family Frozen shipping.
			$cvs_info = apply_filters( 'paynow_shipping_cvs_callback', $cvs_info, $posted );
		}

		// 帶 cvs_token 或舊版的 order_id，代表這次要改既有訂單，一律走 token 驗證，不可落回結帳流程.
		if ( isset( $_REQUEST['order_id'] ) || isset( $_REQUEST[ self::CVS_TOKEN_QUERY_ARG ] ) ) {
			$query = wc_clean( wp_unslash( $_GET ) );
			$order = self::change_order_cvs_store( self::get_string_value( $query, self::CVS_TOKEN_QUERY_ARG ), $cvs_info );

			if ( is_wp_error( $order ) ) {
				PayNow_Shipping::log( 'Reject CVS store change: ' . $order->get_error_code(), 'error' );
				wp_die( esc_html( $order->get_error_message() ), esc_html__( '門市沒有變更', 'paynow-shipping' ), [ 'response' => 403 ] );
			}

			wp_safe_redirect( $order->get_edit_order_url() );
			exit;
		}

		// post to checkout page, so the cvs field can be saved.
		echo self::build_checkout_redirect_form( $cvs_info ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		die();
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * 產生把門市資料帶回結帳頁的自動送出表單
	 *
	 * @param array $cvs_info 門市資料.
	 * @return string
	 */
	public static function build_checkout_redirect_form( $cvs_info ) {
		$html  = '<!doctype html><html ' . get_language_attributes( 'html' ) . '><head><meta charset="' . get_bloginfo( 'charset', 'display' ) . '"><title>AutoSubmitForm</title></head><body>';
		$html .= '<form method="post" id="paynow-map-redirect" action="' . esc_url( wc_get_page_permalink( 'checkout' ) ) . '" style="display:none;">';
		foreach ( $cvs_info as $key => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		}
		$html .= '</form>';
		$html .= '<script type="text/javascript">document.getElementById("paynow-map-redirect").submit();</script>';
		$html .= '</body></html>';

		return $html;
	}

	/**
	 * 產生後台改門市用的 returnUrl
	 *
	 * 門市地圖導回是跨站 POST，瀏覽器可能不帶 WordPress 登入 cookie，
	 * 所以不能靠 current_user_can() 或 nonce，改用綁定訂單的一次性 token。
	 * Token 由 transient 對應到訂單，網址只需要一個參數，也不用經過 esc_url() 把 & 轉成 &#038;。
	 *
	 * @param int $order_id 訂單 ID.
	 * @return string
	 */
	public static function get_cvs_change_return_url( $order_id ) {
		$token = wp_generate_password( 32, false );
		set_transient( self::get_cvs_token_transient_key( $token ), absint( $order_id ), self::CVS_TOKEN_TTL );

		return esc_url_raw( add_query_arg( self::CVS_TOKEN_QUERY_ARG, $token, WC()->api_request_url( 'paynow_choose_cvs_callback' ) ) );
	}

	/**
	 * 驗證 token 後變更訂單門市，並觸發取消、重建物流單
	 *
	 * @param string $token    改門市 token.
	 * @param array  $cvs_info 門市資料.
	 * @return WC_Order|WP_Error
	 */
	public static function change_order_cvs_store( $token, $cvs_info ) {
		$invalid_token = new WP_Error( 'invalid_cvs_token', __( '門市變更連結無效或已過期，訂單沒有變更。請回到訂單頁重新整理後，再選一次門市。', 'paynow-shipping' ) );

		if ( '' === $token ) {
			return $invalid_token;
		}

		$transient_key = self::get_cvs_token_transient_key( $token );
		$order         = wc_get_order( absint( get_transient( $transient_key ) ) );

		if ( ! $order instanceof WC_Order ) {
			return $invalid_token;
		}

		// 欄位不齊時保留 token：可能是在地圖上取消，管理員還能用同一個連結重選.
		foreach ( [ 'paynow_service', 'paynow_storeid', 'paynow_storename', 'paynow_storeaddress' ] as $key ) {
			if ( empty( $cvs_info[ $key ] ) ) {
				return new WP_Error( 'missing_cvs_store', __( '沒有收到完整的門市資料，訂單沒有變更。請回到訂單頁再選一次門市。', 'paynow-shipping' ) );
			}
		}

		// delete_transient() 只有實際刪到才回傳 true，同時送達的重複請求只有一筆會通過.
		if ( ! delete_transient( $transient_key ) ) {
			return $invalid_token;
		}

		PayNow_Shipping::paynow_save_order_shipping_meta( $order, $cvs_info );
		/* translators: %1$s: CVS store name. */
		$order->add_order_note( sprintf( __( 'CVS store has changed to %1$s. The PayNow shipping order will be revoked and recreated.', 'paynow-shipping' ), $cvs_info['paynow_storename'] ) );
		do_action( 'paynow_after_admin_changed_cvs_store', $order );

		return $order;
	}

	/**
	 * Handling order status update from PayNow.
	 *
	 * @return void
	 */
	public static function paynow_receive_order_status_update() {
		// phpcs:disable WordPress.Security.NonceVerification
		$posted = wc_clean( wp_unslash( $_POST ) );
		$query  = wc_clean( wp_unslash( $_GET ) );
		PayNow_Shipping::log( 'receive_order_status_update: ' . wc_print_r( $posted, true ) );

		$result = self::process_status_update( $posted, self::get_string_value( $query, self::STATUS_TOKEN_QUERY_ARG ) );

		if ( is_wp_error( $result ) ) {
			PayNow_Shipping::log( 'Reject shipping status callback: ' . $result->get_error_message(), 'error' );
			// 查詢 PayNow 失敗是暫時性問題，回 503 讓對方有機會重送.
			status_header( 'status_query_failed' === $result->get_error_code() ? 503 : 403 );
		}

		exit;
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * 驗證並套用貨態更新
	 *
	 * @param array  $posted 回呼內容.
	 * @param string $token  回呼網址帶的 token.
	 * @return WC_Order|WP_Error
	 */
	public static function process_status_update( $posted, $token ) {
		$token_check = self::verify_status_token( $token );
		if ( is_wp_error( $token_check ) ) {
			return $token_check;
		}

		$orderno              = self::get_string_value( $posted, 'orderno' );
		$paynow_logistic_code = self::get_string_value( $posted, 'PayNowLogisticCode' );

		if ( '' === $orderno || '' === $paynow_logistic_code ) {
			return new WP_Error( 'missing_status_fields', 'orderno or PayNowLogisticCode is missing' );
		}

		// FIXME: orderno may be prefixed.
		$order = wc_get_order( $orderno );

		// 物流單號只會在向 PayNow 建立物流單後寫入，用來確認這張訂單真的使用 PayNow 物流.
		if ( ! $order instanceof WC_Order || '' === (string) $order->get_meta( PayNow_Shipping_Order_Meta::LogisticNumber ) ) {
			return new WP_Error( 'not_paynow_shipping_order', 'Order ' . $orderno . ' has no PayNow shipping data' );
		}

		$status_key = $order->get_meta( PayNow_Shipping_Order_Meta::LogisticNumber ) . ':' . $paynow_logistic_code;

		// 沒帶 token 的回呼來源不明，貨態以 PayNow 查詢結果為準.
		if ( '' === $token ) {
			// 貨態是真的也可能被重放，不可蓋掉商家之後手動改的訂單狀態.
			if ( $status_key === $order->get_meta( self::LAST_STATUS_META ) ) {
				return $order;
			}

			$confirmed = self::confirm_status_with_paynow( $order, $paynow_logistic_code );
			if ( is_wp_error( $confirmed ) ) {
				return $confirmed;
			}

			$posted = array_merge( $posted, $confirmed );
		}

		$order->update_meta_data( self::LAST_STATUS_META, $status_key );
		$order->update_meta_data( PayNow_Shipping_Order_Meta::LogisticCode, $paynow_logistic_code );

		// 沒送的欄位不覆寫，避免清掉既有資料.
		$optional_fields = [
			'OriginOrderno'             => '_paynow_shipping_original_order',
			'Detail_Status_Description' => PayNow_Shipping_Order_Meta::DetailStatusDesc,
			'paymentno'                 => PayNow_Shipping_Order_Meta::PaymentNo,
			'StoreDate'                 => PayNow_Shipping_Order_Meta::StoreDate,
			'StoreTime'                 => PayNow_Shipping_Order_Meta::StoreTime,
		];
		foreach ( $optional_fields as $field => $meta_key ) {
			if ( isset( $posted[ $field ] ) ) {
				$order->update_meta_data( $meta_key, self::get_string_value( $posted, $field ) );
			}
		}

		// 商家沒設定對應訂單狀態時，後面不會呼叫 update_status()，這裡要自己存.
		$order->save();

		$order->add_order_note( 'Shipping status update from PayNow: logistic_code:' . $paynow_logistic_code . ', detailed_status:' . self::get_string_value( $posted, 'Detail_Status_Description' ) . ', paymentno:' . self::get_string_value( $posted, 'paymentno' ) . ', store_date: ' . self::get_string_value( $posted, 'StoreDate' ) . ', store_time:' . self::get_string_value( $posted, 'StoreTime' ) );

		// change order status based on logistic code.
		do_action( 'paynow_update_shipping_order_status', $order, $paynow_logistic_code );

		return $order;
	}

	/**
	 * 向 PayNow 查詢物流單，確認回呼的貨態是真的
	 *
	 * PayNow 物流回呼沒有可驗證的簽章，所以用伺服器對伺服器的查詢當依據。
	 * 查詢結果和回呼不同就拒絕：偽造的請求不會更動訂單，只有 PayNow 也認定的貨態才會生效。
	 *
	 * @param WC_Order $order         訂單.
	 * @param string   $logistic_code 回呼送來的貨態.
	 * @return array|WP_Error 查詢 API 有提供的欄位，這些欄位以查詢結果為準。
	 */
	private static function confirm_status_with_paynow( $order, $logistic_code ) {
		$response = PayNow_Shipping_Request::query_order( $order, self::STATUS_QUERY_TIMEOUT );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'status_query_failed', 'Order ' . $order->get_id() . ' status query failed: ' . $response->get_error_message() );
		}

		$result = json_decode( wp_remote_retrieve_body( $response ), true );
		$result = is_array( $result ) ? wc_clean( $result ) : [];

		$queried_code = self::get_string_value( $result, 'PayNowLogisticCode' );

		// 查無物流單時 PayNow 仍回 200，貨態是空字串.
		if ( '' === $queried_code || self::get_string_value( $result, 'LogisticNumber' ) !== (string) $order->get_meta( PayNow_Shipping_Order_Meta::LogisticNumber ) ) {
			return new WP_Error( 'status_query_failed', 'Order ' . $order->get_id() . ' status query returned no usable result' );
		}

		if ( $queried_code !== $logistic_code ) {
			return new WP_Error( 'status_not_confirmed', 'Order ' . $order->get_id() . ' status not confirmed by PayNow. Callback: ' . $logistic_code . ', PayNow: ' . $queried_code );
		}

		PayNow_Shipping::log( 'Shipping status callback without token was confirmed by querying PayNow. Please update the callback URL in PayNow backend.', 'warning' );

		return array_intersect_key(
			$result,
			array_flip( [ 'Detail_Status_Description', 'paymentno' ] )
		);
	}

	/**
	 * 驗證貨態回呼的 token
	 *
	 * 既有站台的 PayNow 後台設定的是沒有 token 的舊網址，一升級就全部拒絕會收不到貨態。
	 * 所以沒帶 token 的回呼仍會處理，但必須先向 PayNow 查詢確認（confirm_status_with_paynow）。
	 * 收到第一筆 token 正確的回呼，就代表商家已經改好網址，從此不再接受沒帶 token 的回呼。
	 * 帶錯 token 則一律拒絕。
	 *
	 * @param string $token 回呼網址帶的 token.
	 * @return true|WP_Error
	 */
	private static function verify_status_token( $token ) {
		if ( '' === $token ) {
			if ( 'yes' === get_option( self::STATUS_TOKEN_REQUIRED_OPTION ) ) {
				return new WP_Error( 'missing_status_token', 'Callback token is required but missing' );
			}

			return true;
		}

		// 這裡只讀不建立：token 只在管理員開設定頁時產生，匿名請求不會寫入資料庫.
		if ( ! hash_equals( (string) get_option( self::STATUS_TOKEN_OPTION, '' ), $token ) ) {
			return new WP_Error( 'invalid_status_token', 'Callback token does not match' );
		}

		update_option( self::STATUS_TOKEN_REQUIRED_OPTION, 'yes', false );

		return true;
	}

	/**
	 * 取得每站獨立的貨態回呼 token，沒有就建立
	 *
	 * 只有管理員開設定頁會走到這裡，不需要處理同時建立的競態。
	 *
	 * @return string
	 */
	private static function get_status_callback_token() {
		$token = (string) get_option( self::STATUS_TOKEN_OPTION, '' );

		if ( '' === $token ) {
			$token = wp_generate_password( 32, false );
			update_option( self::STATUS_TOKEN_OPTION, $token, false );
		}

		return $token;
	}

	/**
	 * 取得含 token 的貨態回呼網址
	 *
	 * @return string
	 */
	public static function get_status_callback_url() {
		return add_query_arg( self::STATUS_TOKEN_QUERY_ARG, self::get_status_callback_token(), WC()->api_request_url( 'paynow_shipping_order_callback' ) );
	}

	/**
	 * 在物流設定頁加上貨態回呼網址說明
	 *
	 * @param array $settings 物流設定欄位.
	 * @return array
	 */
	public function add_status_callback_settings( $settings ) {
		if ( 'yes' === get_option( self::STATUS_TOKEN_REQUIRED_OPTION ) ) {
			$status = __( '已啟用驗證：沒帶 token 或 token 不符的貨態回呼都會被拒絕。', 'paynow-shipping' );
		} else {
			$status = __( '尚未改用新網址：舊網址（沒帶 token）的回呼會先向 PayNow 查詢確認才更新訂單，查詢失敗時該筆貨態不會生效。改好網址後，收到第一筆帶 token 的貨態就不再接受舊網址。', 'paynow-shipping' );
		}

		$settings[] = [
			'title' => __( '貨態回傳網址', 'paynow-shipping' ),
			'type'  => 'title',
			'desc'  => __( '請到 PayNow 物流後台，把貨態回傳網址改成：', 'paynow-shipping' ) . '<br><code>' . esc_html( self::get_status_callback_url() ) . '</code><br>' . esc_html( $status ),
			'id'    => 'paynow_shipping_status_callback',
		];
		$settings[] = [
			'type' => 'sectionend',
			'id'   => 'paynow_shipping_status_callback',
		];

		return $settings;
	}

	/**
	 * 取得改門市 token 對應的 transient key
	 *
	 * @param string $token 改門市 token.
	 * @return string
	 */
	private static function get_cvs_token_transient_key( $token ) {
		return self::CVS_TOKEN_TRANSIENT_PREFIX . hash( 'sha256', $token );
	}

	/**
	 * 取出字串欄位。缺少或不是字串（例如 token[]=x）都回傳空字串
	 *
	 * @param array  $data 資料.
	 * @param string $key  欄位名稱.
	 * @return string
	 */
	private static function get_string_value( $data, $key ) {
		return isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? $data[ $key ] : '';
	}

	/**
	 * Update order status when received update from paynow
	 *
	 * @param WC_Order $order The order object.
	 * @param string   $logistic_code PayNow logistic code.
	 * @return void
	 */
	public function paynow_update_order_status_after_received_update( $order, $logistic_code ) {

		PayNow_Shipping::log( 'Update order stauts. Order id:' . $order->get_id() . ', logistic code:' . $logistic_code );

		if ( PayNow_Shipping_Status::AT_SENDER_CVS === $logistic_code ) {
			if ( ! empty( PayNow_Shipping::$order_status_at_sender_cvs ) ) {
				$order->update_status( PayNow_Shipping::$order_status_at_sender_cvs );
			}
		} elseif ( PayNow_Shipping_Status::AT_RECEIVER_CVS === $logistic_code ) {
			if ( ! empty( PayNow_Shipping::$order_status_at_receiver_cvs ) ) {
				$order->update_status( PayNow_Shipping::$order_status_at_receiver_cvs );
			}
		} elseif ( PayNow_Shipping_Status::CUSTOMER_PICKUP === $logistic_code ) {
			if ( ! empty( PayNow_Shipping::$order_status_pickuped ) ) {
				$order->update_status( PayNow_Shipping::$order_status_pickuped );
			}
		} elseif ( PayNow_Shipping_Status::EC_RETURN === $logistic_code ) {
			if ( ! empty( PayNow_Shipping::$order_status_returned ) ) {
				$order->update_status( PayNow_Shipping::$order_status_returned );
			}
		}
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		// do nothing.
	}

	/**
	 * Initialize the class and return instance.
	 *
	 * @return PayNow_Shipping_Response
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

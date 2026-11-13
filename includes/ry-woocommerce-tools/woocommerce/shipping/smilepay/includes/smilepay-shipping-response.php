<?php
/**
 * 速買配物流回呼
 *
 * 三個回呼網址都是公開的：
 *   - 門市地圖（結帳／後台）：Data_id 必須是本站送出過的；只有 pending 訂單能在結帳流程選門市；
 *     Classif=T（取貨付款）只對付款方式為 cod 的訂單成立。
 *   - 貨態更新：Smseid 必須是訂單上已存在的物流單，有存 Data_id 時也必須相符；
 *     貨態只能推進「已付款」的訂單，不能把未付款訂單改成已付款或完成。
 *
 * 速買配物流回呼是否帶簽章欄位尚無公開規格，取得規格後應補驗章或改為伺服器回查。
 */
class RY_SmilePay_Shipping_Response extends RY_SmilePay {

	/**
	 * 金流代號（log 用）
	 */
	const PROVIDER = 'smilepay-shipping';

	public static function init() {
		add_action( 'woocommerce_api_ry_smilepay_shipping_map_callback', [ __CLASS__, 'check_map_callback' ] );
		add_action( 'woocommerce_api_ry_smilepay_shipping_admin_map_callback', [ __CLASS__, 'check_admin_map_callback' ] );
		add_action( 'woocommerce_api_ry_smilepay_shipping_callback', [ __CLASS__, 'shipping_callback' ] );

		add_action( 'valid_smilepay_shipping_map_request', [ __CLASS__, 'doing_map_callback' ] );
		add_action( 'valid_smilepay_shipping_admin_map_request', [ __CLASS__, 'doing_admin_map_callback' ] );
		add_action( 'valid_smilepay_shipping_request', [ __CLASS__, 'doing_callback' ] );

		add_action( 'ry_smilepay_shipping_response_status_2', [ __CLASS__, 'shipping_at_cvs' ], 10, 2 );
		add_action( 'ry_smilepay_shipping_response_status_4', [ __CLASS__, 'shipping_out_cvs' ], 10, 2 );
		if ( 'yes' == RY_WT::get_option( 'ecpay_shipping_auto_completed', 'yes' ) ) {
			add_action( 'ry_smilepay_shipping_response_status_3', [ __CLASS__, 'shipping_completed' ], 10, 2 );
		}
	}

	/**
	 * 驗證門市地圖回呼，通過時回傳訂單
	 *
	 * @param array $ipn_info 回呼資料
	 * @return WC_Order|null
	 */
	public static function validate_map_callback( $ipn_info ) {
		if ( ! is_array( $ipn_info ) || '1' !== (string) self::get_status( $ipn_info ) ) {
			return null;
		}

		$data_id  = isset( $ipn_info['Data_id'] ) ? (string) $ipn_info['Data_id'] : '';
		$order_id = self::get_order_id( $ipn_info, RY_WT::get_option( 'smilepay_gateway_order_prefix' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order ) {
			Woomp_Payment_Callback_Guard::reject( self::PROVIDER, 'map: order not found', [ 'Data_id' => $data_id ] );
			return null;
		}

		$context = [
			'order_id' => $order->get_id(),
			'Data_id'  => $data_id,
		];

		if ( ! self::is_known_data_id( $order, '_smilepay_shipping_Data_id_list', $data_id ) ) {
			Woomp_Payment_Callback_Guard::reject( self::PROVIDER, 'map: Data_id mismatch', $context );
			return null;
		}

		$smse_id = self::get_transaction_id( $ipn_info );
		if ( ! is_string( $smse_id ) || '' === $smse_id ) {
			Woomp_Payment_Callback_Guard::reject( self::PROVIDER, 'map: Smseid missing', $context );
			return null;
		}

		return $order;
	}

	public static function check_map_callback() {
		if ( ! empty( $_POST ) ) {
			$ipn_info = self::clean_post_data();
			RY_SmilePay_Shipping::log( 'IPN request: ' . var_export( $ipn_info, true ) );
			if ( self::validate_map_callback( $ipn_info ) ) {
				do_action( 'valid_smilepay_shipping_map_request', $ipn_info );
				return;
			}
		}
		self::die_error();
	}

	/**
	 * 套用結帳流程的門市地圖回呼，回傳要導向的網址
	 *
	 * @param array $ipn_info 回呼資料
	 * @return string
	 */
	public static function apply_map_callback( $ipn_info ) {
		$url   = wc_get_checkout_url();
		$order = self::validate_map_callback( $ipn_info );
		if ( ! $order ) {
			return $url;
		}
		RY_SmilePay_Shipping::log( 'Found order #' . $order->get_id() );

		// 結帳流程選門市只發生在付款前；其他狀態的訂單只導回訂單頁，不改任何資料。
		if ( ! $order->has_status( 'pending' ) ) {
			Woomp_Payment_Callback_Guard::reject(
				self::PROVIDER,
				'map: order not pending',
				[
					'order_id' => $order->get_id(),
					'status'   => $order->get_status(),
				]
			);
			return $order->get_checkout_order_received_url();
		}

		$is_cod = 'T' === ( $ipn_info['Classif'] ?? '' );
		if ( $is_cod && 'cod' !== $order->get_payment_method() ) {
			Woomp_Payment_Callback_Guard::reject(
				self::PROVIDER,
				'map: Classif T on non-cod order',
				[
					'order_id'       => $order->get_id(),
					'payment_method' => $order->get_payment_method(),
				]
			);
			return $url;
		}
		if ( ! $is_cod && ! Woomp_Payment_Callback_Guard::is_payment_method( $order, 'ry_smilepay_' ) ) {
			Woomp_Payment_Callback_Guard::reject(
				self::PROVIDER,
				'map: payment method mismatch',
				[
					'order_id'       => $order->get_id(),
					'payment_method' => $order->get_payment_method(),
				]
			);
			return $url;
		}

		$smse_id       = self::get_transaction_id( $ipn_info );
		$shipping_list = $order->get_meta( '_smilepay_shipping_info', true );
		if ( ! is_array( $shipping_list ) ) {
			$shipping_list = [];
		}

		$shipping_list[ $smse_id ] = [
			'ID'           => $smse_id,
			'Data_id'      => (string) $ipn_info['Data_id'],
			'amount'       => (int) ( $ipn_info['Amount'] ?? 0 ),
			'storeID'      => $ipn_info['Storeid'] ?? '',
			'PaymentNo'    => '',
			'ValidationNo' => '',
			'IsCollection' => $is_cod ? 1 : 0,
			'type'         => $ipn_info['Classif_sub'] ?? '',
			'status'       => 0,
			'create'       => (string) new WC_DateTime(),
			'edit'         => (string) new WC_DateTime(),
		];

		$order->set_shipping_company( '' );
		$order->set_shipping_address_2( '' );
		$order->set_shipping_city( '' );
		$order->set_shipping_state( '' );
		$order->set_shipping_postcode( '' );
		$order->set_shipping_address_1( $ipn_info['Storeaddress'] ?? '' );
		$order->update_meta_data( '_shipping_cvs_store_ID', $ipn_info['Storeid'] ?? '' );
		$order->update_meta_data( '_shipping_cvs_store_name', $ipn_info['Storename'] ?? '' );
		$order->update_meta_data( '_shipping_cvs_store_address', $ipn_info['Storeaddress'] ?? '' );
		$order->update_meta_data( '_smilepay_shipping_info', $shipping_list );
		$order->save();

		if ( $is_cod ) {
			// 貨到付款：WC 的 cod 閘道本來在結帳時就會把訂單改成 processing，
			// 本外掛為了先選門市而延到這裡（RY_SmilePay_Shipping::change_cod_order_status）。不代表收到款項。
			$order->update_status( $order->has_downloadable_item() ? 'on-hold' : 'processing' );
			return $order->get_checkout_order_received_url();
		}

		$code_url = RY_SmilePay_Gateway_Api::get_code( $order );
		return $code_url ? $code_url : $order->get_checkout_order_received_url();
	}

	public static function doing_map_callback( $ipn_info ) {
		$url = self::apply_map_callback( $ipn_info );
		RY_SmilePay_Shipping::log( 'Redirect: ' . $url );
		wp_redirect( $url );
	}

	public static function check_admin_map_callback() {
		if ( ! empty( $_POST ) ) {
			$ipn_info = self::clean_post_data();
			RY_SmilePay_Shipping::log( 'IPN request: ' . var_export( $ipn_info, true ) );
			if ( self::validate_map_callback( $ipn_info ) ) {
				do_action( 'valid_smilepay_shipping_admin_map_request', $ipn_info );
				return;
			}
		}
		// HPOS 相容：使用 Helper 取得訂單列表 URL
		wp_redirect( Woomp_HPOS_Helper::get_order_list_url() );
	}

	/**
	 * 套用後台門市地圖回呼，回傳要導向的網址
	 *
	 * 只新增物流單資料，不改訂單狀態。
	 *
	 * @param array $ipn_info 回呼資料
	 * @return string
	 */
	public static function apply_admin_map_callback( $ipn_info ) {
		// HPOS 相容：使用 Helper 取得訂單列表 URL
		$url   = Woomp_HPOS_Helper::get_order_list_url();
		$order = self::validate_map_callback( $ipn_info );
		if ( ! $order ) {
			return $url;
		}
		RY_SmilePay_Shipping::log( 'Found order #' . $order->get_id() );

		$smse_id       = self::get_transaction_id( $ipn_info );
		$shipping_list = $order->get_meta( '_smilepay_shipping_info', true );
		if ( ! is_array( $shipping_list ) ) {
			$shipping_list = [];
		}

		$shipping_list[ $smse_id ] = [
			'ID'           => $smse_id,
			'Data_id'      => (string) $ipn_info['Data_id'],
			'amount'       => (int) ( $ipn_info['Amount'] ?? 0 ),
			'storeID'      => $ipn_info['Storeid'] ?? '',
			'PaymentNo'    => '',
			'ValidationNo' => '',
			'IsCollection' => 'T' === ( $ipn_info['Classif'] ?? '' ) ? 1 : 0,
			'type'         => $ipn_info['Classif_sub'] ?? '',
			'status'       => $ipn_info['Status'],
			'create'       => (string) new WC_DateTime(),
			'edit'         => (string) new WC_DateTime(),
		];

		$order->update_meta_data( '_smilepay_shipping_info', $shipping_list );
		$order->save();

		// HPOS 相容：使用 Helper 取得訂單編輯 URL
		$url = Woomp_HPOS_Helper::get_order_edit_url( $order->get_id() );

		if ( 'yes' === RY_WT::get_option( 'smilepay_shipping_auto_get_no', 'yes' ) ) {
			RY_SmilePay_Shipping_Api::get_code_no( $order->get_id(), $smse_id );
		}

		return $url;
	}

	public static function doing_admin_map_callback( $ipn_info ) {
		$url = self::apply_admin_map_callback( $ipn_info );
		RY_SmilePay_Shipping::log( 'Redirect: ' . $url );
		wp_redirect( $url );
	}

	public static function shipping_callback() {
		if ( ! empty( $_POST ) ) {
			$ipn_info = self::clean_post_data( true );
			RY_SmilePay_Shipping::log( 'IPN request: ' . var_export( $ipn_info, true ) );
			if ( self::validate_status_callback( $ipn_info ) ) {
				do_action( 'valid_smilepay_shipping_request', $ipn_info );
				return;
			}
		}
		self::die_error();
	}

	/**
	 * 驗證貨態回呼，通過時回傳訂單
	 *
	 * @param array $ipn_info 回呼資料
	 * @return WC_Order|null
	 */
	public static function validate_status_callback( $ipn_info ) {
		if ( ! is_array( $ipn_info ) ) {
			return null;
		}

		$data_id  = isset( $ipn_info['Data_id'] ) ? (string) $ipn_info['Data_id'] : '';
		$order_id = self::get_order_id( $ipn_info, RY_WT::get_option( 'smilepay_gateway_order_prefix' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order ) {
			Woomp_Payment_Callback_Guard::reject( self::PROVIDER, 'status: order not found', [ 'Data_id' => $data_id ] );
			return null;
		}

		$context = [
			'order_id' => $order->get_id(),
			'Data_id'  => $data_id,
		];

		$status = self::get_status( $ipn_info );
		// 貨態會被組進 action 名稱，限制字元。
		if ( ! is_string( $status ) || ! preg_match( '/^[A-Za-z0-9_-]{1,20}$/', $status ) ) {
			Woomp_Payment_Callback_Guard::reject( self::PROVIDER, 'status: invalid status', $context );
			return null;
		}

		// 只接受訂單上已存在的物流單，不由回呼新增。
		$smse_id       = self::get_transaction_id( $ipn_info );
		$shipping_list = $order->get_meta( '_smilepay_shipping_info', true );
		if ( ! is_string( $smse_id ) || '' === $smse_id || ! is_array( $shipping_list ) || ! isset( $shipping_list[ $smse_id ] ) ) {
			Woomp_Payment_Callback_Guard::reject( self::PROVIDER, 'status: unknown Smseid', $context );
			return null;
		}

		// 選門市時有記下 Data_id 的物流單（本版之後建立），Data_id 必須相符。
		$known_data_id = (string) ( $shipping_list[ $smse_id ]['Data_id'] ?? '' );
		if ( '' !== $known_data_id && ! hash_equals( $known_data_id, $data_id ) ) {
			Woomp_Payment_Callback_Guard::reject( self::PROVIDER, 'status: Data_id mismatch', $context );
			return null;
		}

		return $order;
	}

	/**
	 * 套用已驗證的貨態回呼
	 *
	 * @param array $ipn_info 回呼資料
	 * @return WC_Order|null
	 */
	public static function apply_status_callback( $ipn_info ) {
		$order = self::validate_status_callback( $ipn_info );
		if ( ! $order ) {
			return null;
		}
		RY_SmilePay_Shipping::log( 'Found order #' . $order->get_id() );

		$shipping_list = $order->get_meta( '_smilepay_shipping_info', true );
		$list_id       = self::get_transaction_id( $ipn_info );
		$old_info      = $shipping_list[ $list_id ];

		$shipping_list[ $list_id ]['status'] = self::get_status( $ipn_info );
		$shipping_list[ $list_id ]['edit']   = (string) new WC_DateTime();

		if ( 'yes' === RY_WT::get_option( 'smilepay_shipping_log_status_change', 'no' ) ) {
			if ( isset( $old_info['status'] ) ) {
				if ( $old_info['status'] != $shipping_list[ $list_id ]['status'] ) {
					$order->add_order_note(
						sprintf(
						/* translators: 1: EcPay ID 2: Old status no 3: New status no */
							__( '%1$s shipping status from %2$s to %3$s', 'ry-woocommerce-tools' ),
							$list_id,
							$old_info['status'],
							$shipping_list[ $list_id ]['status']
						)
					);
				}
			}
		}

		$order->update_meta_data( '_smilepay_shipping_info', $shipping_list );
		$order->save_meta_data();

		do_action( 'ry_smilepay_shipping_response_status_' . $shipping_list[ $list_id ]['status'], $ipn_info, $order );
		do_action( 'ry_smilepay_shipping_response', $ipn_info, $order );

		return $order;
	}

	public static function doing_callback( $ipn_info ) {
		if ( self::apply_status_callback( $ipn_info ) ) {
			self::die_success();
			return;
		}
		RY_SmilePay_Shipping::log( 'Order not found', 'error' );
		self::die_error();
	}

	/**
	 * 貨態可否把訂單改成指定狀態
	 *
	 * 物流回呼沒有簽章，只能推進已付款（含貨到付款的 processing）的訂單，
	 * 不能把 pending／on-hold／cancelled 等未付款訂單改成已付款狀態或完成。
	 *
	 * @param WC_Order $order 訂單
	 * @param string[] $from  允許的原狀態
	 * @param string   $to    目標狀態
	 */
	protected static function can_transition( $order, $from, $to ) {
		if ( $order->has_status( $from ) ) {
			return true;
		}
		if ( ! $order->has_status( $to ) ) {
			Woomp_Payment_Callback_Guard::reject(
				self::PROVIDER,
				'status: transition not allowed',
				[
					'order_id' => $order->get_id(),
					'from'     => $order->get_status(),
					'to'       => $to,
				]
			);
		}
		return false;
	}

	public static function shipping_at_cvs( $ipn_info, $order ) {
		if ( self::can_transition( $order, [ 'processing' ], 'ry-at-cvs' ) ) {
			$order->update_status( 'ry-at-cvs' );
		}
	}

	public static function shipping_out_cvs( $ipn_info, $order ) {
		if ( self::can_transition( $order, [ 'processing', 'ry-at-cvs' ], 'ry-out-cvs' ) ) {
			$order->update_status( 'ry-out-cvs' );
		}
	}

	public static function shipping_completed( $ipn_info, $order ) {
		if ( self::can_transition( $order, [ 'processing', 'ry-at-cvs' ], 'completed' ) ) {
			$order->update_status( 'completed' );
		}
	}
}

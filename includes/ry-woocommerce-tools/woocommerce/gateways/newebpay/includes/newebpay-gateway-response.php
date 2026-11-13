<?php
class RY_NewebPay_Gateway_Response extends RY_NewebPay_Gateway_Api {

	public static function init() {
		add_action( 'woocommerce_api_request', [ __CLASS__, 'set_do_die' ] );
		add_action( 'woocommerce_api_ry_newebpay_callback', [ __CLASS__, 'check_callback' ] );
		add_action( 'woocommerce_thankyou', [ __CLASS__, 'check_callback' ], 8 );
		add_action( 'valid_newebpay_gateway_request', [ __CLASS__, 'doing_callback' ] );
	}

	/**
	 * 付款方式前綴（ry_newebpay_*：一般閘道；wmp_newebpay_*：woomp 分期閘道）
	 */
	const PAYMENT_METHOD_PREFIXES = [ 'ry_newebpay_', 'wmp_newebpay_' ];

	/**
	 * Guard log 用的金流代號
	 */
	const CALLBACK_PROVIDER = 'newebpay';

	/**
	 * 回呼入口
	 *
	 * 兩個來源都走這裡：wc-api/ry_newebpay_callback（NotifyURL，藍新伺服器），
	 * 以及 woocommerce_thankyou 優先度 8（ReturnURL／CustomerURL，經消費者瀏覽器 POST）。
	 */
	public static function check_callback() {
		if ( empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$ipn_info = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		// 謝謝頁也會收到其他金流（例如綠界 OrderResultURL）的 POST，沒有 TradeInfo 就不是給藍新的
		if ( ! is_array( $ipn_info ) || ! isset( $ipn_info['TradeInfo'] ) ) {
			return;
		}

		if ( self::ipn_request_is_valid( $ipn_info ) ) {
			do_action( 'valid_newebpay_gateway_request', $ipn_info );
		} else {
			self::die_error();
		}
	}

	/**
	 * 驗證 TradeSha
	 *
	 * 金鑰為空時任何人都能算出 TradeSha 並自行加密 TradeInfo，一律拒絕。
	 *
	 * @param array $ipn_info 回呼資料
	 */
	protected static function ipn_request_is_valid( $ipn_info ) {
		RY_NewebPay_Gateway::log( 'IPN request: ' . var_export( $ipn_info, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export

		$info_value     = self::get_tradeInfo_value( $ipn_info );
		$info_sha_value = self::get_tradeSha_value( $ipn_info );
		if ( ! is_string( $info_value ) || '' === $info_value || ! is_string( $info_sha_value ) ) {
			Woomp_Payment_Callback_Guard::reject( self::CALLBACK_PROVIDER, 'missing TradeInfo or TradeSha' );
			return false;
		}

		list($MerchantID, $HashKey, $HashIV) = RY_NewebPay_Gateway::get_newebpay_api_info();
		if ( ! Woomp_Payment_Callback_Guard::has_secrets( $MerchantID, $HashKey, $HashIV ) ) {
			Woomp_Payment_Callback_Guard::reject( self::CALLBACK_PROVIDER, 'api secrets not configured' );
			return false;
		}

		if ( Woomp_Payment_Callback_Guard::signature_equals( self::generate_hash_value( $info_value, $HashKey, $HashIV ), $info_sha_value ) ) {
			return true;
		}

		Woomp_Payment_Callback_Guard::reject( self::CALLBACK_PROVIDER, 'TradeSha mismatch' );
		return false;
	}

	/**
	 * 回呼的 MerchantOrderNo 是否屬於這筆訂單
	 *
	 * 有存過編號（_newebpay_MerchantOrderNo 或歷史清單）就必須相符；
	 * 舊訂單沒存過才回退比對產生規則「前綴 + 訂單 ID + TS」。
	 *
	 * @param WC_Order $order    訂單
	 * @param mixed    $order_no 回呼的 MerchantOrderNo
	 */
	protected static function is_order_merchant_order_no( WC_Order $order, $order_no ): bool {
		if ( ! is_string( $order_no ) || '' === $order_no ) {
			return false;
		}

		$known = array_filter(
			array_merge( [ $order->get_meta( '_newebpay_MerchantOrderNo' ) ], (array) $order->get_meta( '_newebpay_MerchantOrderNo_history' ) ),
			static fn( $no ) => is_string( $no ) && '' !== $no
		);
		if ( $known ) {
			return in_array( $order_no, $known, true );
		}

		$expected_prefix = (string) RY_WT::get_option( 'newebpay_gateway_order_prefix' ) . $order->get_id() . 'TS';
		return 0 === strpos( $order_no, $expected_prefix );
	}

	/**
	 * 回呼與訂單的綁定檢查，回傳拒絕原因（空字串代表通過）
	 *
	 * @param object   $result 解密後的 Result
	 * @param WC_Order $order  訂單
	 */
	protected static function get_binding_error( $result, WC_Order $order ): string {
		if ( ! Woomp_Payment_Callback_Guard::is_payment_method( $order, self::PAYMENT_METHOD_PREFIXES ) ) {
			return 'payment method mismatch';
		}

		list($MerchantID) = RY_NewebPay_Gateway::get_newebpay_api_info();
		if ( ! isset( $result->MerchantID ) || (string) $result->MerchantID !== (string) $MerchantID ) {
			return 'MerchantID mismatch';
		}

		if ( ! self::is_order_merchant_order_no( $order, $result->MerchantOrderNo ?? '' ) ) {
			return 'MerchantOrderNo mismatch';
		}

		if ( ! Woomp_Payment_Callback_Guard::amount_matches( $order, $result->Amt ?? null ) ) {
			return 'Amt mismatch';
		}

		return '';
	}

	public static function doing_callback( $ipn_info ) {
		list($MerchantID, $HashKey, $HashIV) = RY_NewebPay_Gateway::get_newebpay_api_info();
		if ( ! Woomp_Payment_Callback_Guard::has_secrets( $MerchantID, $HashKey, $HashIV ) ) {
			Woomp_Payment_Callback_Guard::reject( self::CALLBACK_PROVIDER, 'api secrets not configured' );
			self::die_error();
			return;
		}

		$decrypted = self::args_decrypt( self::get_tradeInfo_value( $ipn_info ), $HashKey, $HashIV );
		$ipn_info  = is_string( $decrypted ) ? json_decode( $decrypted ) : null;
		RY_NewebPay_Gateway::log( 'IPN decrypt request: ' . var_export( $ipn_info, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		if ( ! is_object( $ipn_info ) || ! isset( $ipn_info->Result ) || ! is_object( $ipn_info->Result ) ) {
			Woomp_Payment_Callback_Guard::reject( self::CALLBACK_PROVIDER, 'TradeInfo decrypt failed' );
			self::die_error();
			return;
		}

		$order_id = self::get_order_id( $ipn_info, RY_WT::get_option( 'newebpay_gateway_order_prefix' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			RY_NewebPay_Gateway::log( 'Order not found', 'error' );
			Woomp_Payment_Callback_Guard::reject( self::CALLBACK_PROVIDER, 'order not found', [ 'MerchantOrderNo' => (string) ( $ipn_info->Result->MerchantOrderNo ?? '' ) ] );
			self::die_error();
			return;
		}

		$binding_error = self::get_binding_error( $ipn_info->Result, $order );
		if ( '' !== $binding_error ) {
			Woomp_Payment_Callback_Guard::reject(
				self::CALLBACK_PROVIDER,
				$binding_error,
				[
					'order_id'        => $order->get_id(),
					'MerchantOrderNo' => (string) ( $ipn_info->Result->MerchantOrderNo ?? '' ),
					'Amt'             => (string) ( $ipn_info->Result->Amt ?? '' ),
				]
			);
			self::die_error();
			return;
		}

		$payment_status = (string) self::get_status( $ipn_info );
		RY_NewebPay_Gateway::log( 'Found order #' . $order->get_id() . ' Payment status: ' . $payment_status );

		// 已付款／已取消／已退款：不可被重放的舊通知改回或降級
		if ( ! Woomp_Payment_Callback_Guard::can_mark_paid( $order ) ) {
			if ( ! $order->is_paid() || 'SUCCESS' !== $payment_status ) {
				$order->add_order_note(
					sprintf(
						'收到藍新通知（%1$s，藍新交易序號 %2$s），但訂單狀態為「%3$s」，未變更訂單狀態。',
						$payment_status,
						(string) self::get_transaction_id( $ipn_info ),
						wc_get_order_status_name( $order->get_status() )
					)
				);
			}
			RY_NewebPay_Gateway::log( 'Order #' . $order->get_id() . ' not payable (' . $order->get_status() . '), callback ignored' );
			self::die_success();
			return;
		}

		$transaction_id = (string) $order->get_transaction_id();
		if ( $transaction_id == '' || $transaction_id != self::get_transaction_id( $ipn_info ) ) {
			$payment_type = self::get_payment_type( $ipn_info );
			$order->set_transaction_id( self::get_transaction_id( $ipn_info ) );
			$order->update_meta_data( '_newebpay_payment_type', $payment_type );
			$order->save();
			$order = wc_get_order( $order->get_id() );
		}

		if ( 'SUCCESS' === $payment_status ) {
			self::payment_status_SUCCESS( $order, $ipn_info->Result );
		} else {
			self::payment_status_unknow( $order, $ipn_info, $payment_status );
		}

		do_action( 'ry_newebpay_gateway_response_status_' . $payment_status, $ipn_info->Result, $order );
		do_action( 'ry_newebpay_gateway_response', $ipn_info->Result, $order );

		self::die_success();
	}

	protected static function payment_status_SUCCESS( $order, $ipn_info ) {
		if ( Woomp_Payment_Callback_Guard::can_mark_paid( $order ) ) {
			if ( isset( $ipn_info->PayTime ) ) {
				$order->add_order_note( __( 'Payment completed', 'ry-woocommerce-tools' ) );
				// payment_complete() 會依商品決定 processing／completed，不可再強制改 processing
				$order->payment_complete();
			} elseif ( isset( $ipn_info->BankCode ) ) {
				$expireDate = new DateTime( $ipn_info->ExpireDate . ' ' . $ipn_info->ExpireTime, new DateTimeZone( 'Asia/Taipei' ) );

				$order->update_meta_data( '_newebpay_atm_BankCode', $ipn_info->BankCode );
				$order->update_meta_data( '_newebpay_atm_vAccount', $ipn_info->CodeNo );
				$order->update_meta_data( '_newebpay_atm_ExpireDate', $expireDate->format( DATE_ATOM ) );
				$order->save_meta_data();

				$order->update_status( 'on-hold' );
			} elseif ( isset( $ipn_info->CodeNo ) ) {
				$expireDate = new DateTime( $ipn_info->ExpireDate . ' ' . $ipn_info->ExpireTime, new DateTimeZone( 'Asia/Taipei' ) );

				$order->update_meta_data( '_newebpay_cvs_PaymentNo', $ipn_info->CodeNo );
				$order->update_meta_data( '_newebpay_cvs_ExpireDate', $expireDate->format( DATE_ATOM ) );
				$order->save_meta_data();

				$order->update_status( 'on-hold' );
			} elseif ( isset( $ipn_info->Barcode_1 ) ) {
				$expireDate = new DateTime( $ipn_info->ExpireDate . ' ' . $ipn_info->ExpireTime, new DateTimeZone( 'Asia/Taipei' ) );

				$order->update_meta_data( '_newebpay_barcode_Barcode1', $ipn_info->Barcode_1 );
				$order->update_meta_data( '_newebpay_barcode_Barcode2', $ipn_info->Barcode_2 );
				$order->update_meta_data( '_newebpay_barcode_Barcode3', $ipn_info->Barcode_3 );
				$order->update_meta_data( '_newebpay_barcode_ExpireDate', $expireDate->format( DATE_ATOM ) );
				$order->save_meta_data();

				$order->update_status( 'on-hold' );
			}
		}

		if ( isset( $ipn_info->StoreCode ) ) {
			$order = wc_get_order( $order );
			if ( $order->get_meta( '_shipping_cvs_store_ID' ) == '' ) {
				$order->set_shipping_company( '' );
				$order->set_shipping_address_2( '' );
				$order->set_shipping_city( '' );
				$order->set_shipping_state( '' );
				$order->set_shipping_postcode( '' );

				$order->set_shipping_last_name( '' );
				$order->set_shipping_first_name( $ipn_info->CVSCOMName );
				$order->add_order_note(
					sprintf(
					/* translators: 1: Store name 2: Store ID */
						__( 'CVS store %1$s (%2$s)', 'ry-woocommerce-tools' ),
						$ipn_info->StoreName,
						$ipn_info->StoreCode
					)
				);
				$order->update_meta_data( '_shipping_cvs_store_ID', $ipn_info->StoreCode );
				$order->update_meta_data( '_shipping_cvs_store_name', $ipn_info->StoreName );
				$order->update_meta_data( '_shipping_cvs_store_address', $ipn_info->StoreAddr );
				$order->update_meta_data( '_shipping_cvs_store_type', $ipn_info->StoreType );
				if ( version_compare( WC_VERSION, '5.6.0', '>=' ) ) {
					$order->set_shipping_phone( $ipn_info->CVSCOMPhone );
				} else {
					$order->update_meta_data( '_shipping_phone', $ipn_info->CVSCOMPhone );
				}
				$order->set_shipping_address_1( $ipn_info->StoreAddr );
				$order->save();

				$shipping_list = $order->get_meta( '_newebpay_shipping_info', true );
				if ( ! is_array( $shipping_list ) ) {
					$shipping_list = [];
				}
				if ( ! isset( $shipping_list[ $ipn_info->TradeNo ] ) ) {
					$shipping_list[ $ipn_info->TradeNo ] = [];
				}
				$shipping_list[ $ipn_info->TradeNo ]['ID']           = $ipn_info->TradeNo;
				$shipping_list[ $ipn_info->TradeNo ]['Type']         = $ipn_info->StoreType;
				$shipping_list[ $ipn_info->TradeNo ]['PaymentNo']    = $ipn_info->TradeNo;
				$shipping_list[ $ipn_info->TradeNo ]['store_ID']     = $ipn_info->StoreCode;
				$shipping_list[ $ipn_info->TradeNo ]['create']       = (string) new WC_DateTime();
				$shipping_list[ $ipn_info->TradeNo ]['edit']         = (string) new WC_DateTime();
				$shipping_list[ $ipn_info->TradeNo ]['amount']       = $ipn_info->Amt;
				$shipping_list[ $ipn_info->TradeNo ]['IsCollection'] = $ipn_info->TradeType;

				$order->update_meta_data( '_newebpay_shipping_info', $shipping_list );
				$order->save_meta_data();

				if ( $ipn_info->TradeType == '1' ) {
					if ( $order->get_status() == 'pending' ) {
						$order->update_status( 'processing' );
					}
				}
			}
		}
	}

	/**
	 * 非 SUCCESS 的通知
	 *
	 * @param WC_Order $order          訂單
	 * @param object   $ipn_info       解密後的完整通知（含 Status／Message）
	 * @param string   $payment_status 狀態
	 */
	protected static function payment_status_unknow( $order, $ipn_info, $payment_status ) {
		RY_NewebPay_Gateway::log( 'Unknow status: ' . self::get_status( $ipn_info ) . '(' . self::get_status_msg( $ipn_info ) . ')' );
		// 已付款訂單不可被失敗通知降級（例如消費者重試付款失敗的通知晚到）
		if ( ! Woomp_Payment_Callback_Guard::can_mark_paid( $order ) ) {
			$order->add_order_note(
				sprintf(
					'收到藍新付款失敗通知：%1$s (%2$s)，但訂單狀態為「%3$s」，未變更訂單狀態。',
					self::get_status( $ipn_info ),
					self::get_status_msg( $ipn_info ),
					wc_get_order_status_name( $order->get_status() )
				)
			);
			return;
		}
		$order->update_status(
			'failed',
			sprintf(
			/* translators: 1: Error status code 2: Error status message */
				__( 'Payment failed: %1$s (%2$s)', 'ry-woocommerce-tools' ),
				self::get_status( $ipn_info ),
				self::get_status_msg( $ipn_info )
			)
		);
	}
}

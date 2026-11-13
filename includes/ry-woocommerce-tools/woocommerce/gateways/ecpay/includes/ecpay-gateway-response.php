<?php
class RY_ECPay_Gateway_Response extends RY_ECPay_Gateway_Api {

	public static function init() {
		add_action( 'woocommerce_api_request', [ __CLASS__, 'set_do_die' ] );
		add_action('woocommerce_api_ry_ecpay_gateway_return', [ __CLASS__, 'gateway_return' ]);
		add_action( 'woocommerce_api_ry_ecpay_callback', [ __CLASS__, 'check_callback' ] );
		add_action( 'valid_ecpay_gateway_request', [ __CLASS__, 'doing_callback' ] );

		add_action( 'ry_ecpay_gateway_response_status_1', [ __CLASS__, 'payment_complete' ], 10, 2 );
		add_action( 'ry_ecpay_gateway_response_status_2', [ __CLASS__, 'payment_wait_atm' ], 10, 2 );
		add_action( 'ry_ecpay_gateway_response_status_10100073', [ __CLASS__, 'payment_wait_cvs' ], 10, 2 );
		add_action( 'ry_ecpay_gateway_response_status_10100058', [ __CLASS__, 'payment_failed' ], 10, 2 );
	}

	/**
	 * 付款方式前綴（ry_ecpay_*：一般閘道；wmp_ecpay_*：woomp 分期閘道）
	 */
	const PAYMENT_METHOD_PREFIXES = [ 'ry_ecpay_', 'wmp_ecpay_' ];

	/**
	 * Guard log 用的金流代號
	 */
	const CALLBACK_PROVIDER = 'ecpay';

	/**
	 * 回呼入口
	 *
	 * 兩個來源都走這裡：wc-api/ry_ecpay_callback（ReturnURL／PaymentInfoURL，綠界伺服器），
	 * 以及 order-received 頁面的瀏覽器 POST（OrderResultURL／ClientRedirectURL，見 RY_ECPay_Gateway::init()）。
	 */
	public static function check_callback() {
		if ( empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			RY_ECPay_Gateway::log( 'check_callback $_POST is empty' );
			return;
		}

		$ipn_info = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		RY_ECPay_Gateway::log( 'check_callback IPN info: ' . var_export( $ipn_info, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		if ( self::ipn_request_is_valid( $ipn_info ) ) {
			do_action( 'valid_ecpay_gateway_request', $ipn_info );
		} else {
			self::die_error();
		}
	}

	/**
	 * 驗證 CheckMacValue
	 *
	 * 金鑰為空時任何人都能算出簽章，一律拒絕（正式模式未填金鑰時 check_option() 不會關掉回呼端點）。
	 *
	 * @param mixed $ipn_info 回呼資料
	 */
	protected static function ipn_request_is_valid( $ipn_info ) {
		if ( ! is_array( $ipn_info ) ) {
			return false;
		}
		foreach ( $ipn_info as $value ) {
			if ( ! is_scalar( $value ) ) {
				Woomp_Payment_Callback_Guard::reject( self::CALLBACK_PROVIDER, 'non-scalar field' );
				return false;
			}
		}

		$check_value = self::get_check_value( $ipn_info );
		if ( ! is_string( $check_value ) || '' === $check_value ) {
			Woomp_Payment_Callback_Guard::reject( self::CALLBACK_PROVIDER, 'missing CheckMacValue' );
			return false;
		}

		list($MerchantID, $HashKey, $HashIV) = RY_ECPay_Gateway::get_ecpay_api_info();
		if ( ! Woomp_Payment_Callback_Guard::has_secrets( $MerchantID, $HashKey, $HashIV ) ) {
			Woomp_Payment_Callback_Guard::reject( self::CALLBACK_PROVIDER, 'api secrets not configured' );
			return false;
		}

		$ipn_info_check_value = self::generate_check_value( $ipn_info, $HashKey, $HashIV, 'sha256' );
		if ( Woomp_Payment_Callback_Guard::signature_equals( $ipn_info_check_value, $check_value ) ) {
			return true;
		}

		Woomp_Payment_Callback_Guard::reject(
			self::CALLBACK_PROVIDER,
			'CheckMacValue mismatch',
			[ 'MerchantTradeNo' => (string) ( $ipn_info['MerchantTradeNo'] ?? '' ) ]
		);
		return false;
	}

	/**
	 * 目前是否為測試模式
	 */
	protected static function is_testmode(): bool {
		return 'yes' === RY_WT::get_option( 'ecpay_gateway_testmode', 'yes' );
	}

	/**
	 * 回呼的 MerchantTradeNo 是否屬於這筆訂單
	 *
	 * 有存過編號（_ecpay_MerchantTradeNo 或歷史清單）就必須相符；
	 * 舊訂單沒存過才回退比對產生規則「前綴 + 訂單 ID + TS」。
	 *
	 * @param WC_Order $order    訂單
	 * @param mixed    $trade_no 回呼的 MerchantTradeNo
	 */
	protected static function is_order_trade_no( WC_Order $order, $trade_no ): bool {
		if ( ! is_string( $trade_no ) || '' === $trade_no ) {
			return false;
		}

		$known = array_filter(
			array_merge( [ $order->get_meta( '_ecpay_MerchantTradeNo' ) ], (array) $order->get_meta( '_ecpay_MerchantTradeNo_history' ) ),
			static fn( $no ) => is_string( $no ) && '' !== $no
		);
		if ( $known ) {
			return in_array( $trade_no, $known, true );
		}

		$expected_prefix = (string) RY_WT::get_option( 'ecpay_gateway_order_prefix' ) . $order->get_id() . 'TS';
		return 0 === strpos( $trade_no, $expected_prefix );
	}

	/**
	 * 回呼與訂單的綁定檢查，回傳拒絕原因（空字串代表通過）
	 *
	 * @param array    $ipn_info 回呼資料（已驗章）
	 * @param WC_Order $order    訂單
	 */
	protected static function get_binding_error( array $ipn_info, WC_Order $order ): string {
		// 擋住「用 A 金流的金鑰偽造回呼，把 B 金流（例如貨到付款）的訂單標成已付款」
		if ( ! Woomp_Payment_Callback_Guard::is_payment_method( $order, self::PAYMENT_METHOD_PREFIXES ) ) {
			return 'payment method mismatch';
		}

		list($MerchantID) = RY_ECPay_Gateway::get_ecpay_api_info();
		if ( (string) ( $ipn_info['MerchantID'] ?? '' ) !== (string) $MerchantID ) {
			return 'MerchantID mismatch';
		}

		if ( ! self::is_order_trade_no( $order, $ipn_info['MerchantTradeNo'] ?? '' ) ) {
			return 'MerchantTradeNo mismatch';
		}

		if ( ! Woomp_Payment_Callback_Guard::amount_matches( $order, $ipn_info['TradeAmt'] ?? null ) ) {
			return 'TradeAmt mismatch';
		}

		// 測試模式的金鑰是公開的：只接受在測試模式下送出付款的訂單
		if ( self::is_testmode() && 'yes' !== $order->get_meta( '_ry_ecpay_testmode' ) ) {
			return 'test mode callback for order not paid in test mode';
		}

		return '';
	}

	public static function doing_callback( $ipn_info ) {
		$order_id = self::get_order_id( $ipn_info, RY_WT::get_option( 'ecpay_gateway_order_prefix' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			RY_ECPay_Gateway::log( 'Order not found', 'error' );
			Woomp_Payment_Callback_Guard::reject( self::CALLBACK_PROVIDER, 'order not found', [ 'MerchantTradeNo' => (string) ( $ipn_info['MerchantTradeNo'] ?? '' ) ] );
			self::die_error();
			return;
		}

		$binding_error = self::get_binding_error( $ipn_info, $order );
		if ( '' !== $binding_error ) {
			Woomp_Payment_Callback_Guard::reject(
				self::CALLBACK_PROVIDER,
				$binding_error,
				[
					'order_id'        => $order->get_id(),
					'MerchantTradeNo' => (string) ( $ipn_info['MerchantTradeNo'] ?? '' ),
					'TradeAmt'        => (string) ( $ipn_info['TradeAmt'] ?? '' ),
				]
			);
			self::die_error();
			return;
		}

		$payment_status = self::get_status( $ipn_info );
		RY_ECPay_Gateway::log( 'Found order #' . $order->get_id() . ' Payment status: ' . $payment_status );

		// 模擬付款（綠界後台「模擬付款」）不是真實付款，不可出貨
		if ( '1' === (string) ( $ipn_info['SimulatePaid'] ?? '0' ) ) {
			$order->add_order_note( sprintf( '收到綠界模擬付款通知（SimulatePaid=1，RtnCode %s），非真實付款，未變更訂單狀態。', $payment_status ) );
			self::die_success();
			return;
		}

		// 已付款／已取消／已退款：不可被重放的舊通知改回
		if ( ! Woomp_Payment_Callback_Guard::can_mark_paid( $order ) ) {
			if ( ! $order->is_paid() ) {
				$order->add_order_note(
					sprintf(
						'收到綠界通知（RtnCode %1$s，綠界交易編號 %2$s），但訂單狀態為「%3$s」，未變更訂單狀態。',
						$payment_status,
						(string) self::get_transaction_id( $ipn_info ),
						wc_get_order_status_name( $order->get_status() )
					)
				);
			}
			RY_ECPay_Gateway::log( 'Order #' . $order->get_id() . ' not payable (' . $order->get_status() . '), callback ignored' );
			self::die_success();
			return;
		}

		// 付款可能來自較早的付款頁（歷史編號）；退款查詢要用實際付款的編號
		if ( $order->get_meta( '_ecpay_MerchantTradeNo' ) !== $ipn_info['MerchantTradeNo'] ) {
			$order->update_meta_data( '_ecpay_MerchantTradeNo', $ipn_info['MerchantTradeNo'] );
			$order->save_meta_data();
		}

		$order = self::set_transaction_info( $order, $ipn_info );

		do_action( 'ry_ecpay_gateway_response_status_' . $payment_status, $ipn_info, $order );
		do_action( 'ry_ecpay_gateway_response', $ipn_info, $order );

		self::die_success();
	}

	protected static function set_transaction_info( $order, $ipn_info ) {
		// 已付款訂單的交易編號不可被後來的回呼覆寫（退款依賴它）
		if ( $order->is_paid() ) {
			return $order;
		}
		$transaction_id = (string) $order->get_transaction_id();
		if ( $transaction_id == '' || ! $order->is_paid() || $transaction_id != self::get_transaction_id( $ipn_info ) ) {
			list($payment_type, $payment_subtype) = self::get_payment_info( $ipn_info );
			$order->set_transaction_id( self::get_transaction_id( $ipn_info ) );
			$order->update_meta_data( '_ecpay_payment_type', $payment_type );
			$order->update_meta_data( '_ecpay_payment_subtype', $payment_subtype );
			$account = $order->get_meta('_ecpay_atm_vAccount');

			if ('ATM' === $payment_type) {
				if ($account) {
					$expireDate = $order->get_meta('_ecpay_atm_ExpireDate');
					// 如果 $expireDate 字串滿足 ISO 8601 格式，用 regex 驗證
					if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+\d{2}:\d{2}$/', $expireDate)) {
						$timestamp = strtotime($expireDate);
					$expireDate = date('Y-m-d H:i:s', $timestamp); // phpcs:ignore
					}

					$order_note = sprintf(
					/*html*/'
			<strong>綠界金流交易紀錄</strong><br>
			狀態碼：%1$s<br>
			交易訊息：%2$s<br>
			交易編號：%3$s<br>
			轉帳銀行：%4$s<br>
			轉帳帳號：%5$s<br>
			轉帳期限：%6$s
			',
					$ipn_info['RtnCode'] === '1' ? '1 (成功)' : $ipn_info['RtnCode'] . ' (尚未付款)',
					$ipn_info['RtnMsg'],
					$ipn_info['TradeNo'],
					$order->get_meta('_ecpay_atm_BankCode'),
					$account,
					$expireDate,
					);
					$order->add_order_note($order_note, true );
				}
			} else {
				$order_note = sprintf(
				/*html*/'綠界付款%1$s: <br/>
				_ecpay_payment_type: %2$s <br/>
				_ecpay_payment_subtype: %3$s <br/>
				',
				$ipn_info['RtnCode'] === '1' ? '成功' : '',
				$payment_type,
				$payment_subtype
				);
				// $order->add_order_note( $order_note );
			}

			$order->save();
			$order = wc_get_order( $order->get_id() );

			// 紀錄 log
			$log = new \WC_Logger();
			ob_start();
			var_dump($ipn_info);
			$msg = ob_get_clean();
			$log->info(
				'set_transaction_info $ipn_info: ' . $msg,
				[
					'source' => 'woomp-ecpay',
				]
				);

		}
		return $order;
	}

	protected static function get_payment_info( $ipn_info ) {
		if ( isset( $ipn_info['PaymentType'] ) ) {
			$payment_type = $ipn_info['PaymentType'];
			$payment_type = explode( '_', $payment_type );
			if ( count( $payment_type ) == 1 ) {
				$payment_type[] = '';
			}
			return $payment_type;
		}
		return false;
	}

	// 以下狀態處理器也可能被第三方直接觸發，各自再檢查一次訂單可否變更

	public static function payment_complete( $ipn_info, $order ) {
		if ( Woomp_Payment_Callback_Guard::can_mark_paid( $order ) ) {
			// $order = self::set_transaction_info( $order, $ipn_info );
			$order->add_order_note( __( 'Payment completed', 'ry-woocommerce-tools' ) );
			$order->payment_complete();
		}
	}

	public static function payment_wait_atm( $ipn_info, $order ) {
		if ( Woomp_Payment_Callback_Guard::can_mark_paid( $order ) ) {
			$expireDate = new DateTime( $ipn_info['ExpireDate'], new DateTimeZone( 'Asia/Taipei' ) );

			$order->update_meta_data( '_ecpay_atm_BankCode', $ipn_info['BankCode'] );
			$order->update_meta_data( '_ecpay_atm_vAccount', $ipn_info['vAccount'] );
			$order->update_meta_data( '_ecpay_atm_ExpireDate', $expireDate->format( DATE_ATOM ) );
			$order->save_meta_data();

			$order->update_status( 'on-hold' );
		}
	}

	public static function payment_wait_cvs( $ipn_info, $order ) {
		if ( Woomp_Payment_Callback_Guard::can_mark_paid( $order ) ) {
			list($payment_type, $payment_subtype) = self::get_payment_info( $ipn_info );
			$expireDate                           = new DateTime( $ipn_info['ExpireDate'], new DateTimeZone( 'Asia/Taipei' ) );

			if ( $payment_type == 'CVS' ) {
				$order->update_meta_data( '_ecpay_cvs_PaymentNo', $ipn_info['PaymentNo'] );
				$order->update_meta_data( '_ecpay_cvs_ExpireDate', $expireDate->format( DATE_ATOM ) );
			} else {
				$order->update_meta_data( '_ecpay_barcode_Barcode1', $ipn_info['Barcode1'] );
				$order->update_meta_data( '_ecpay_barcode_Barcode2', $ipn_info['Barcode2'] );
				$order->update_meta_data( '_ecpay_barcode_Barcode3', $ipn_info['Barcode3'] );
				$order->update_meta_data( '_ecpay_barcode_ExpireDate', $expireDate->format( DATE_ATOM ) );
			}
			$order->save_meta_data();

			$order->update_status( 'on-hold' );
		}
	}

	public static function payment_failed( $ipn_info, $order ) {
		if ( ! Woomp_Payment_Callback_Guard::can_mark_paid( $order ) ) {
			$order->add_order_note( __( 'Payment failed within paid order', 'ry-woocommerce-tools' ) );
			$order->save();
			return;
		}

		$order->update_status(
			'failed',
			sprintf(
			/* translators: Error status message */
				__( 'Payment failed (%s)', 'ry-woocommerce-tools' ),
				self::get_status_msg( $ipn_info )
			)
		);
	}

	public static function gateway_return() {
		$order_key = wp_unslash($_GET['key'] ?? '');
		$order_ID  = (int) wp_unslash($_GET['id'] ?? 0);
		$order     = wc_get_order($order_ID);
		if ($order && hash_equals($order->get_order_key(), $order_key)) {
			$return_url = $order->get_checkout_order_received_url();
		} else {
			$return_url = wc_get_endpoint_url('order-received', '', wc_get_checkout_url());
		}

		$return_url = apply_filters('woocommerce_get_return_url', $return_url, $order);
		wp_redirect($return_url);

		exit();
	}
}

<?php
/**
 * 速買配付款回呼
 *
 * `/wc-api/ry_smilepay_callback` 是公開網址。速買配的驗證碼 Mid_smilepay 是「位數加權和」，
 * 值域只有 0～864，且商家參數碼只貢獻一個固定常數，可被暴力猜出，也可從自己的合法回呼反推。
 * 因此改付款狀態前，除了驗證碼還必須通過：
 *   - Data_id 必須是本站取號時送出過的（存在訂單 meta）
 *   - 付款方式為 ry_smilepay_*
 *   - 取號型（ATM／超商代碼／超商條碼）訂單：Smseid 必須等於取號時存下的交易編號
 *   - 金額等於訂單金額
 *   - 回呼環境（測試／正式）與訂單取號時相同
 *   - 每張訂單驗證失敗 5 次就鎖 24 小時
 *   - 只有 pending／on-hold／failed 的訂單能被標成已付款
 *
 * 速買配是否有交易查詢 API 尚無公開規格，取得規格後應改為伺服器主動回查。
 */
class RY_SmilePay_Gateway_Response extends RY_SmilePay_Gateway_Api {

	/**
	 * 金流代號（log 用）
	 */
	const PROVIDER = 'smilepay';

	/**
	 * 每張訂單允許的驗證失敗次數
	 */
	const MAX_FAILURES = 5;

	/**
	 * 鎖定時間（秒）
	 */
	const LOCK_TTL = DAY_IN_SECONDS;

	public static function init() {
		add_action( 'woocommerce_api_ry_smilepay_callback', [ __CLASS__, 'check_callback' ] );

		add_action( 'valid_smilepay_gateway_request', [ __CLASS__, 'doing_callback' ] );
	}

	public static function check_callback() {
		if ( ! empty( $_POST ) ) {
			$ipn_info = self::clean_post_data( true );
			if ( self::ipn_request_is_valid( $ipn_info ) ) {
				do_action( 'valid_smilepay_gateway_request', $ipn_info );
				return;
			}
		}
		self::die_error();
	}

	protected static function ipn_request_is_valid( $ipn_info ) {
		return null !== self::validate_callback( $ipn_info );
	}

	/**
	 * 驗證付款回呼，通過時回傳訂單
	 *
	 * @param array $ipn_info 回呼資料
	 * @return WC_Order|null
	 */
	public static function validate_callback( $ipn_info ) {
		if ( ! is_array( $ipn_info ) ) {
			return null;
		}
		RY_SmilePay_Gateway::log( 'IPN request: ' . var_export( $ipn_info, true ) );

		$data_id  = isset( $ipn_info['Data_id'] ) ? (string) $ipn_info['Data_id'] : '';
		$order_id = self::get_order_id( $ipn_info, RY_WT::get_option( 'smilepay_gateway_order_prefix' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order ) {
			Woomp_Payment_Callback_Guard::reject( self::PROVIDER, 'order not found', [ 'Data_id' => $data_id ] );
			return null;
		}

		$context = [
			'order_id' => $order->get_id(),
			'Data_id'  => $data_id,
		];

		if ( self::is_locked( $order ) ) {
			Woomp_Payment_Callback_Guard::reject( self::PROVIDER, 'order locked after repeated failures', $context );
			return null;
		}

		if ( ! Woomp_Payment_Callback_Guard::is_payment_method( $order, 'ry_smilepay_' ) ) {
			Woomp_Payment_Callback_Guard::reject( self::PROVIDER, 'payment method mismatch', $context + [ 'payment_method' => $order->get_payment_method() ] );
			return null;
		}

		if ( ! self::is_same_mode_as_order( $order ) ) {
			Woomp_Payment_Callback_Guard::reject( self::PROVIDER, 'test mode mismatch', $context );
			return null;
		}

		list($Dcvc, $Rvg2c, $Verify_key, $Rot_check) = RY_SmilePay_Gateway::get_smilepay_api_info();
		if ( ! Woomp_Payment_Callback_Guard::has_secrets( $Rot_check ) ) {
			Woomp_Payment_Callback_Guard::reject( self::PROVIDER, 'Rot_check not configured', $context );
			return null;
		}

		// Data_id 不符不計入失敗次數：否則任何人只要知道訂單 ID 就能把別人的訂單鎖住（阻斷合法付款通知）。
		// Data_id 含時間戳與亂數，不知道的人猜不到；計數只針對已知 Data_id 後的驗證碼／Smseid 猜測。
		if ( ! self::is_known_data_id( $order, '_smilepay_Data_id_list', $data_id, '_smilepay_Data_id' ) ) {
			Woomp_Payment_Callback_Guard::reject( self::PROVIDER, 'Data_id mismatch', $context );
			return null;
		}

		$smse_id = self::get_transaction_id( $ipn_info );
		if ( ! is_string( $smse_id ) || '' === $smse_id ) {
			self::record_failure( $order, 'Smseid missing', $context );
			return null;
		}
		// 取號型付款在取號時已存下速買配交易編號（SmilePayNO），回呼必須是同一筆。
		$known_smse_id = (string) $order->get_transaction_id();
		if ( '' !== $known_smse_id && ! hash_equals( $known_smse_id, $smse_id ) ) {
			self::record_failure( $order, 'Smseid mismatch', $context + [ 'Smseid' => $smse_id ] );
			return null;
		}

		$check_value = self::get_check_value( $ipn_info );
		$expected    = self::calculate_check_value( $Rot_check, $order, $smse_id );
		if ( ! Woomp_Payment_Callback_Guard::signature_equals( (string) $expected, is_scalar( $check_value ) ? trim( (string) $check_value ) : '' ) ) {
			self::record_failure( $order, 'Mid_smilepay mismatch', $context );
			return null;
		}

		if ( ! isset( $ipn_info['Amount'] ) || ! Woomp_Payment_Callback_Guard::amount_matches( $order, $ipn_info['Amount'] ) ) {
			Woomp_Payment_Callback_Guard::reject( self::PROVIDER, 'amount mismatch', $context + [ 'Amount' => $ipn_info['Amount'] ?? '' ] );
			return null;
		}

		return $order;
	}

	/**
	 * 計算速買配驗證碼 Mid_smilepay
	 *
	 * 商家參數碼末 4 碼 + 訂單金額（8 碼）+ Smseid 末 4 碼（非數字換成 9），偶數位和 ×9 + 奇數位和 ×3。
	 *
	 * @param string   $rot_check 商家參數碼
	 * @param WC_Order $order     訂單
	 * @param string   $smse_id   速買配交易編號
	 */
	public static function calculate_check_value( $rot_check, $order, $smse_id ) {
		$parts    = [];
		$parts[0] = str_pad( substr( (string) $rot_check, -4 ), 4, '0', STR_PAD_LEFT );
		$parts[1] = str_pad( (string) (int) ceil( $order->get_total() ), 8, '0', STR_PAD_LEFT );
		$parts[2] = str_pad( substr( (string) $smse_id, -4 ), 4, '9', STR_PAD_LEFT );
		$parts[2] = preg_replace( '/[^\d]/s', '9', $parts[2] );

		$digits = preg_replace( '/[^\d]/', '0', implode( '', $parts ) );
		$odd    = 0;
		$even   = 0;
		$strlen = strlen( $digits );
		for ( $i = 0; $i < $strlen; ++$i ) {
			if ( 0 === $i % 2 ) {
				$even += (int) $digits[ $i ];
			} else {
				$odd += (int) $digits[ $i ];
			}
		}
		return $even * 9 + $odd * 3;
	}

	/**
	 * 失敗次數 transient key
	 *
	 * @param WC_Order $order 訂單
	 */
	protected static function failure_key( $order ) {
		return 'woomp_smilepay_cb_fail_' . $order->get_id();
	}

	/**
	 * 鎖定 transient key
	 *
	 * @param WC_Order $order 訂單
	 */
	protected static function lock_key( $order ) {
		return 'woomp_smilepay_cb_lock_' . $order->get_id();
	}

	/**
	 * 訂單的 SmilePay 回呼是否被鎖定
	 *
	 * @param WC_Order $order 訂單
	 */
	public static function is_locked( $order ) {
		return (bool) get_transient( self::lock_key( $order ) );
	}

	/**
	 * 記錄一次驗證失敗；達上限時鎖定並通知商家
	 *
	 * transient 計數不是原子操作，同時大量請求可能多算幾次才鎖；仍足以把暴力猜測限制在個位數次。
	 *
	 * @param WC_Order $order   訂單
	 * @param string   $reason  原因
	 * @param array    $context 其他資訊
	 */
	protected static function record_failure( $order, $reason, $context ) {
		$count = (int) get_transient( self::failure_key( $order ) ) + 1;
		set_transient( self::failure_key( $order ), $count, self::LOCK_TTL );

		Woomp_Payment_Callback_Guard::reject( self::PROVIDER, $reason, $context + [ 'failures' => $count ] );

		if ( $count >= self::MAX_FAILURES && ! self::is_locked( $order ) ) {
			set_transient( self::lock_key( $order ), 1, self::LOCK_TTL );
			Woomp_Payment_Callback_Guard::reject( self::PROVIDER, 'order locked', $context + [ 'failures' => $count ] );
			$order->add_order_note(
				sprintf(
					/* translators: %d: 失敗次數 */
					__( '速買配付款回呼驗證連續失敗 %d 次，可能有人在嘗試偽造付款通知。此訂單的速買配回呼已暫停 24 小時，請登入速買配後台人工核對付款狀態。', 'woomp' ),
					$count
				)
			);
		}
	}

	/**
	 * 套用已驗證的付款回呼
	 *
	 * @param WC_Order $order    訂單
	 * @param array    $ipn_info 回呼資料
	 * @return WC_Order
	 */
	public static function apply_payment( $order, $ipn_info ) {
		$payment_type = '';
		switch ( $ipn_info['Classif'] ?? '' ) {
			case 'A':
				$payment_type = 1;
				break;
			case 'B':
				$payment_type = 21;
				break;
			case 'C':
				$payment_type = 3;
				break;
			case 'E':
				$payment_type = 4;
				break;
			case 'F':
				$payment_type = 6;
				break;
		}

		// 信用卡授權失敗（Response_id = 0）不可標成已付款。
		$credit_failed = 1 === $payment_type && isset( $ipn_info['Response_id'] ) && '0' === trim( (string) $ipn_info['Response_id'] );

		if ( ! $credit_failed && Woomp_Payment_Callback_Guard::can_mark_paid( $order ) ) {
			$order = self::set_transaction_info( $order, $ipn_info, $payment_type );
			$order->add_order_note( __( 'Payment completed', 'ry-woocommerce-tools' ) );
			$order->payment_complete();
		} elseif ( ! $credit_failed && ! $order->is_paid() ) {
			Woomp_Payment_Callback_Guard::reject(
				self::PROVIDER,
				'order status not payable',
				[
					'order_id' => $order->get_id(),
					'status'   => $order->get_status(),
				]
			);
		}

		if ( $credit_failed ) {
			if ( $order->is_paid() ) {
				$order->add_order_note( __( 'Payment failed within paid order', 'ry-woocommerce-tools' ) );
				$order->save();
			} elseif ( $order->has_status( [ 'pending', 'on-hold' ] ) ) {
				$order->update_status(
					'failed',
					sprintf(
						/* translators: Error status message */
						__( 'Payment failed (%s)', 'ry-woocommerce-tools' ),
						$ipn_info['Errdesc'] ?? ''
					)
				);
			}
		}

		return $order;
	}

	public static function doing_callback( $ipn_info ) {
		$order = self::validate_callback( $ipn_info );
		if ( ! $order ) {
			RY_SmilePay_Gateway::log( 'Order not found', 'error' );
			self::die_error();
			return;
		}
		RY_SmilePay_Gateway::log( 'Found order #' . $order->get_id() );

		$order = self::apply_payment( $order, $ipn_info );

		do_action( 'ry_smilepay_gateway_response', $ipn_info, $order );

		$gateway = wc_get_payment_gateway_by_order( $order );
		if ( $gateway && ! $gateway->get_code_mode ) {
			wp_redirect( $order->get_checkout_order_received_url() );
			return;
		}
		self::die_success();
	}
}

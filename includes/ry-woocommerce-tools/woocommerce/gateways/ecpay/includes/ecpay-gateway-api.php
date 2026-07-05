<?php
class RY_ECPay_Gateway_Api extends RY_ECPay {

	public static $api_test_url = [
		'checkout' => 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5',
		'query'    => 'https://payment-stage.ecpay.com.tw/Cashier/QueryTradeInfo/V5',
		'sptoken'  => 'https://payment-stage.ecpay.com.tw/SP/CreateTrade',
		// 注意：綠界 Stage 測試環境不支援 DoAction（無法真實授權），僅正式環境可實際退刷。
		'refund'   => 'https://payment-stage.ecpay.com.tw/CreditDetail/DoAction',
	];
	public static $api_url      = [
		'checkout' => 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5',
		'query'    => 'https://payment.ecpay.com.tw/Cashier/QueryTradeInfo/V5',
		'sptoken'  => 'https://payment.ecpay.com.tw/SP/CreateTrade',
		'refund'   => 'https://payment.ecpay.com.tw/CreditDetail/DoAction',
	];

	public static function checkout_form( $order, $gateway ) {
		RY_ECPay_Gateway::log( 'Generating payment form by ' . $gateway->id . ' for #' . $order->get_order_number() );

		$notify_url = WC()->api_request_url( 'ry_ecpay_callback', true );
		// 取得訂單的付款方式
		$payment_type = $order->get_payment_method();

		if ('ry_ecpay_barcode' === $payment_type) {
			$return_url = self::get_3rd_return_url($order);
		} else {
			$return_url = $gateway->get_return_url( $order );
		}

		list($MerchantID, $HashKey, $HashIV) = RY_ECPay_Gateway::get_ecpay_api_info();

		$args                      = [
			'MerchantID'        => $MerchantID,
			'MerchantTradeNo'   => self::generate_trade_no( $order->get_id(), RY_WT::get_option( 'ecpay_gateway_order_prefix' ) ),
			'MerchantTradeDate' => new DateTime( '', new DateTimeZone( 'Asia/Taipei' ) ),
			'PaymentType'       => 'aio',
			'TotalAmount'       => (int) ceil( $order->get_total() ),
			'TradeDesc'         => get_bloginfo( 'name' ),
			'ItemName'          => self::get_item_name( $order ),
			'ReturnURL'         => $notify_url,
			'ChoosePayment'     => $gateway->payment_type,
			'ClientBackURL'     => $return_url,
			'OrderResultURL'    => $return_url,
			'NeedExtraPaidInfo' => 'Y',
			'IgnorePayment'     => '',
			'EncryptType'       => 1,
			'PaymentInfoURL'    => $notify_url,
			'ClientRedirectURL' => $return_url,
		];
		$args['TradeDesc']         = preg_replace( '/[\x{21}-\x{2f}\x{3a}-\x{40}\x{5b}-\x{60}\x{7b}-\x{7e}]/', ' ', $args['TradeDesc'] );
		$args['TradeDesc']         = mb_substr( $args['TradeDesc'], 0, 100 );
		$args['MerchantTradeDate'] = $args['MerchantTradeDate']->format( 'Y/m/d H:i:s' );

		switch ( get_locale() ) {
			case 'zh_HK':
			case 'zh_TW':
				break;
			case 'ko_KR':
				$args['Language'] = 'KOR';
				break;
			case 'ja':
				$args['Language'] = 'JPN';
				break;
			case 'zh_CN':
				$args['Language'] = 'CHI';
				break;
			case 'en_US':
			case 'en_AU':
			case 'en_CA':
			case 'en_GB':
			default:
				$args['Language'] = 'ENG';
				break;
		}

		$args = self::add_type_info( $args, $order, $gateway );
		$args = self::add_check_value( $args, $HashKey, $HashIV, 'sha256' );
		RY_ECPay_Gateway::log( 'Checkout POST: ' . var_export( $args, true ) );

		$order->update_meta_data( '_ecpay_MerchantTradeNo', $args['MerchantTradeNo'] );
		$order->save_meta_data();

		if ( 'yes' === RY_WT::get_option( 'ecpay_gateway_testmode', 'yes' ) ) {
			$url = self::$api_test_url['checkout'];
		} else {
			$url = self::$api_url['checkout'];
		}
		echo '<form method="post" id="ry-ecpay-form" action="' . esc_url( $url ) . '" style="display:none;">';
		foreach ( $args as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		}
		echo '</form>';

		wc_enqueue_js(
			'$.blockUI({
    message: "' . __( 'Please wait.<br>Getting checkout info.', 'ry-woocommerce-tools' ) . '",
    baseZ: 99999,
    overlayCSS: {
        background: "#000",
        opacity: 0.4
    },
    css: {
        "font-size": "1.5em",
        padding: "1.5em",
        textAlign: "center",
        border: "3px solid #aaa",
        backgroundColor: "#fff",
    }
});
$("#ry-ecpay-form").submit();'
		);

		do_action( 'ry_ecpay_gateway_checkout', $args, $order, $gateway );
	}

	protected static function add_type_info( $args, $order, $gateway ) {
		switch ( $gateway->payment_type ) {
			case 'Credit':
				if ( isset( $gateway->number_of_periods ) && ! empty( $gateway->number_of_periods ) ) {
					if ( is_array( $gateway->number_of_periods ) ) {
						$number_of_periods = (int) $order->get_meta( '_ecpay_payment_number_of_periods', true );
						if ( ! in_array( $number_of_periods, $gateway->number_of_periods ) ) {
							$number_of_periods = 0;
						}
					} else {
						$number_of_periods = (int) $gateway->number_of_periods;
					}
					if ( in_array( $number_of_periods, [ 3, 6, 12, 18, 24 ] ) ) {
						$args['CreditInstallment'] = $number_of_periods;
						$order->add_order_note(
							sprintf(
							/* translators: %d number of periods */
								__( 'Credit installment to %d', 'ry-woocommerce-tools' ),
								$number_of_periods
							)
						);
						$order->save();
					}
				}
				break;
			case 'ATM':
				$args['ExpireDate'] = $gateway->expire_date;
				break;
			case 'BARCODE':
			case 'CVS':
				$args['StoreExpireDate'] = $gateway->expire_date;
				break;
		}
		return $args;
	}

	protected static function get_item_name( $order ) {
		$item_name = '';
		if ( count( $order->get_items() ) ) {
			foreach ( $order->get_items() as $item ) {
				$item_name .= str_replace( '#', '', trim( $item->get_name() ) ) . '#';
				if ( mb_strlen( $item_name ) > 200 ) {
					break;
				}
			}
		}
		$item_name = rtrim( $item_name, '#' );
		return $item_name;
	}

	/**
	 * 取得指定 API 端點網址（依測試／正式環境切換）
	 *
	 * @param string $key 端點鍵名（checkout／query／sptoken／refund）。
	 * @return string 端點網址。
	 */
	protected static function get_api_url( $key ) {
		if ( 'yes' === RY_WT::get_option( 'ecpay_gateway_testmode', 'yes' ) ) {
			return self::$api_test_url[ $key ];
		}
		return self::$api_url[ $key ];
	}

	/**
	 * 綠界後台信用卡退款編排（方案 B′）
	 *
	 * 流程：
	 * 1. 前置檢查綠界交易編號（TradeNo）存在、分期強制全額退款。
	 * 2. QueryTradeInfo 前置驗證訂單付款狀態，失敗即中止（不送出 DoAction）。
	 * 3. 送出 DoAction Action=R 退刷；RtnCode=1 即成功。
	 * 4. 若綠界回覆「尚未關帳」且為全額退款，降級送出 Action=N 取消授權。
	 * 5. 其他失敗一律回 WP_Error（fail-closed，不標記已退款）。
	 *
	 * @param WC_Order           $order   訂單物件。
	 * @param float|null         $amount  退款金額（null 表示全額）。
	 * @param string             $reason  退款原因。
	 * @param WC_Payment_Gateway $gateway 觸發退款的金流閘道。
	 * @return bool|WP_Error 成功回傳 true；失敗回傳 WP_Error。
	 */
	public static function process_credit_refund( $order, $amount, $reason, $gateway ) {
		$trade_no = $order->get_transaction_id();
		if ( empty( $trade_no ) ) {
			return new WP_Error(
				'ry_ecpay_refund_no_trade_no',
				__( '查無綠界交易編號，無法透過綠界退款。請確認訂單付款狀態，或登入綠界廠商後台處理。', 'ry-woocommerce-tools' )
			);
		}

		// $amount 為 null 時（WooCommerce process_refund 慣例）視為全額退款。
		if ( null === $amount ) {
			$amount = $order->get_total();
		}

		// 金額雙邊採一致取整（對齊結帳送綠界的 (int) ceil 交易金額），避免含小數訂單把全額退款誤判為部分退款。
		$refund_amount = (int) ceil( (float) $amount );
		$order_total   = (int) ceil( (float) $order->get_total() );

		// 縱深防護：退款金額須大於 0 且不超過訂單金額（WooCommerce 核心已驗可退餘額，此處為本層再驗，防止超額退款）。
		if ( $refund_amount <= 0 || $refund_amount > $order_total ) {
			return new WP_Error(
				'ry_ecpay_refund_amount_invalid',
				__( '退款金額不正確，必須大於 0 且不超過訂單金額。', 'ry-woocommerce-tools' )
			);
		}

		$is_full = ( $refund_amount >= $order_total );

		// 信用卡分期／紅利僅支援全額退款，於呼叫綠界 API 前攔截部分退款。
		if ( false !== strpos( $gateway->id, 'installment' ) && ! $is_full ) {
			return new WP_Error(
				'ry_ecpay_refund_installment_full_only',
				__( '信用卡分期付款僅支援全額退款，無法部分退款。請改為全額退款。', 'ry-woocommerce-tools' )
			);
		}

		// 前置驗證：查詢綠界交易狀態，失敗即中止。
		$query = self::query_trade_info( $order );
		if ( is_wp_error( $query ) ) {
			return $query;
		}

		// 主路徑：Action=R 退刷。
		$result = self::do_credit_action( $order, 'R', $refund_amount );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( '1' === (string) ( $result['RtnCode'] ?? '' ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: 退款金額 2: 綠界回應訊息 */
					__( '綠界退款成功（退刷 R，金額 %1$s）：%2$s', 'ry-woocommerce-tools' ),
					$refund_amount,
					$result['RtnMsg'] ?? ''
				)
			);
			RY_ECPay_Gateway::log( 'Refund(R) success for #' . $order->get_order_number() . ' amount=' . $refund_amount );
			return true;
		}

		// 綠界回覆尚未關帳且為全額退款 → 降級 Action=N 取消授權。
		$rtn_msg = (string) ( $result['RtnMsg'] ?? '' );
		if ( false !== strpos( $rtn_msg, '未關帳' ) && $is_full ) {
			$result_n = self::do_credit_action( $order, 'N', $refund_amount );
			if ( is_wp_error( $result_n ) ) {
				return $result_n;
			}

			if ( '1' === (string) ( $result_n['RtnCode'] ?? '' ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: 退款金額 2: 綠界回應訊息 */
						__( '綠界退款成功（尚未關帳，改以放棄授權 N，金額 %1$s）：%2$s', 'ry-woocommerce-tools' ),
						$refund_amount,
						$result_n['RtnMsg'] ?? ''
					)
				);
				RY_ECPay_Gateway::log( 'Refund(N) success for #' . $order->get_order_number() . ' amount=' . $refund_amount );
				return true;
			}

			return new WP_Error(
				'ry_ecpay_refund_action_n_failed',
				sprintf(
					/* translators: %s 綠界回應訊息 */
					__( '綠界退款失敗（放棄授權 N）：%s', 'ry-woocommerce-tools' ),
					$result_n['RtnMsg'] ?? ''
				)
			);
		}

		return new WP_Error(
			'ry_ecpay_refund_doaction_rejected',
			sprintf(
				/* translators: %s 綠界回應訊息 */
				__( '綠界退款失敗：%s', 'ry-woocommerce-tools' ),
				$rtn_msg
			)
		);
	}

	/**
	 * 呼叫綠界 QueryTradeInfo 查詢並驗證交易狀態（退款前置驗證）
	 *
	 * @param WC_Order $order 訂單物件。
	 * @return array|WP_Error 成功回傳解析後的回應陣列；失敗回傳 WP_Error。
	 */
	protected static function query_trade_info( $order ) {
		list( $merchant_id, $hash_key, $hash_iv ) = RY_ECPay_Gateway::get_ecpay_api_info();

		$args = [
			'MerchantID'      => $merchant_id,
			'MerchantTradeNo' => $order->get_meta( '_ecpay_MerchantTradeNo' ),
			'TimeStamp'       => time(),
		];
		$args = self::add_check_value( $args, $hash_key, $hash_iv, 'sha256' );

		$response = self::link_server( self::get_api_url( 'query' ), $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'ry_ecpay_refund_query_failed',
				__( '無法連線綠界查詢交易狀態，退款已中止。', 'ry-woocommerce-tools' ) . ' ' . $response->get_error_message()
			);
		}

		parse_str( wp_remote_retrieve_body( $response ), $result );

		if ( ! self::verify_response_check_value( $result, $hash_key, $hash_iv ) ) {
			return new WP_Error( 'ry_ecpay_refund_query_checkmac_failed', __( '綠界查詢回應驗證失敗（CheckMacValue 不符），退款已中止。', 'ry-woocommerce-tools' ) );
		}

		if ( '1' !== (string) ( $result['TradeStatus'] ?? '' ) ) {
			return new WP_Error( 'ry_ecpay_refund_trade_not_paid', __( '訂單尚未完成付款，無法退款。', 'ry-woocommerce-tools' ) );
		}

		return $result;
	}

	/**
	 * 呼叫綠界 CreditDetail/DoAction 執行信用卡請退款動作
	 *
	 * @param WC_Order $order  訂單物件。
	 * @param string   $action 動作代碼（C 關帳／R 退刷／E 取消關帳／N 放棄）。
	 * @param int      $amount 金額。
	 * @return array|WP_Error 成功回傳解析後的回應陣列；失敗回傳 WP_Error。
	 */
	protected static function do_credit_action( $order, $action, $amount ) {
		list( $merchant_id, $hash_key, $hash_iv ) = RY_ECPay_Gateway::get_ecpay_api_info();

		$merchant_trade_no = $order->get_meta( '_ecpay_MerchantTradeNo' );
		$trade_no          = $order->get_transaction_id();

		$args = [
			'MerchantID'      => $merchant_id,
			'MerchantTradeNo' => $merchant_trade_no,
			'TradeNo'         => $trade_no,
			'Action'          => $action,
			'TotalAmount'     => (int) $amount,
		];
		$args = self::add_check_value( $args, $hash_key, $hash_iv, 'sha256' );

		$response = self::link_server( self::get_api_url( 'refund' ), $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'ry_ecpay_refund_doaction_failed',
				__( '無法連線綠界退款服務，退款已中止。', 'ry-woocommerce-tools' ) . ' ' . $response->get_error_message()
			);
		}

		parse_str( wp_remote_retrieve_body( $response ), $result );

		// 綠界 CreditDetail/DoAction 回應僅含 MerchantID／MerchantTradeNo／TradeNo／RtnCode／RtnMsg，
		// 不含 CheckMacValue（與 QueryTradeInfo 不同，故此路徑「不」驗章）。改以「回應綁定原請求」防偽：
		// 回應真實性由 TLS 通道保護（link_server 未關 sslverify），並比對回應的 MerchantTradeNo／TradeNo
		// 與本訂單一致，避免回應被替換或錯配到其他訂單。
		if ( ! isset( $result['RtnCode'] ) ) {
			return new WP_Error( 'ry_ecpay_refund_doaction_bad_response', __( '綠界退款回應格式異常，退款已中止。', 'ry-woocommerce-tools' ) );
		}

		if ( ( $result['MerchantTradeNo'] ?? '' ) !== (string) $merchant_trade_no
			|| ( $result['TradeNo'] ?? '' ) !== (string) $trade_no ) {
			return new WP_Error( 'ry_ecpay_refund_doaction_mismatch', __( '綠界退款回應與本訂單不符，退款已中止。', 'ry-woocommerce-tools' ) );
		}

		return $result;
	}

	/**
	 * 驗證綠界回應的 CheckMacValue 是否與依官方演算法重算的結果一致
	 *
	 * @param array  $result   解析後的回應陣列。
	 * @param string $hash_key 綠界 HashKey。
	 * @param string $hash_iv  綠界 HashIV。
	 * @return bool 驗證通過回傳 true。
	 */
	protected static function verify_response_check_value( $result, $hash_key, $hash_iv ) {
		if ( ! isset( $result['CheckMacValue'] ) ) {
			return false;
		}
		$expected = self::generate_check_value( $result, $hash_key, $hash_iv, 'sha256' );
		return hash_equals( $expected, (string) $result['CheckMacValue'] );
	}
}

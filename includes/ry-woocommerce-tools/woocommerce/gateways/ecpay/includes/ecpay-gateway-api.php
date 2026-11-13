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
		// 消費者可能重新整理付款頁產生新編號，卻在舊分頁完成付款；保留曾送出的編號供回呼比對
		$history   = (array) $order->get_meta( '_ecpay_MerchantTradeNo_history' );
		$history[] = $args['MerchantTradeNo'];
		$order->update_meta_data( '_ecpay_MerchantTradeNo_history', array_slice( array_values( array_unique( array_filter( $history, static fn( $no ) => is_string( $no ) && '' !== $no ) ) ), -20 ) );
		// 測試模式使用公開金鑰，只接受在測試模式下送出付款的訂單收到測試回呼
		$order->update_meta_data( '_ry_ecpay_testmode', 'yes' === RY_WT::get_option( 'ecpay_gateway_testmode', 'yes' ) ? 'yes' : 'no' );
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
	 * 3. 全額退款依序盲試 Action：R（已關帳退刷）→ N（已授權未關帳放棄）→ E→N（已請款待關帳）。
	 * 4. 部分退款只有 R 可用（N／E 皆為全額語意），R 失敗即中止。
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

		$rtn_msg_r = (string) ( $result['RtnMsg'] ?? '' );

		// 部分退款只有 R 可用（N／E 皆為全額語意），R 失敗即中止。
		if ( ! $is_full ) {
			return new WP_Error(
				'ry_ecpay_refund_doaction_rejected',
				sprintf(
					/* translators: %s 綠界回應訊息 */
					__( '綠界退款失敗：%s', 'ry-woocommerce-tools' ),
					$rtn_msg_r
				)
			);
		}

		// 降級 1：Action=N 放棄授權（已授權、尚未關帳的交易走這條）。
		//
		// 此處刻意不以 RtnMsg 字串判斷交易階段：綠界未公開 DoAction 的錯誤碼／錯誤訊息
		// 對照表，舊實作的 `strpos( $rtn_msg, '未關帳' )` 在正式站實測回的是
		// 「更新失敗.(error_amount_R)」，比對不到就不降級、直接 fail。而官方（p=2885）
		// 已載明各交易狀態對應的 Action，且這些 Action 互斥——狀態不符時只會回失敗，
		// 不會產生副作用，故改為「依序盲試」：比「先查詢再決策」可靠，且對存量訂單立即生效。
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

		// 降級 2：Action=E 取消關帳 → 再送 N 放棄授權（已請款待關帳的交易走這條）。
		$result_e = self::do_credit_action( $order, 'E', $refund_amount );
		if ( is_wp_error( $result_e ) ) {
			return $result_e;
		}

		if ( '1' === (string) ( $result_e['RtnCode'] ?? '' ) ) {
			$result_en = self::do_credit_action( $order, 'N', $refund_amount );

			if ( ! is_wp_error( $result_en ) && '1' === (string) ( $result_en['RtnCode'] ?? '' ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: 退款金額 2: 綠界回應訊息 */
						__( '綠界退款成功（已取消關帳 E 後放棄授權 N，金額 %1$s）：%2$s', 'ry-woocommerce-tools' ),
						$refund_amount,
						$result_en['RtnMsg'] ?? ''
					)
				);
				RY_ECPay_Gateway::log( 'Refund(E->N) success for #' . $order->get_order_number() . ' amount=' . $refund_amount );
				return true;
			}

			// E 已成功但 N 失敗：交易停在「已授權未關帳」的中間狀態，必須讓商家知道並人工收尾。
			$en_msg = is_wp_error( $result_en ) ? $result_en->get_error_message() : (string) ( $result_en['RtnMsg'] ?? '' );
			$order->add_order_note(
				sprintf(
					/* translators: %s 綠界回應訊息 */
					__( '綠界退款未完成：已取消關帳（E）但放棄授權（N）失敗，交易目前停在「已授權未關帳」狀態，請登入綠界廠商後台人工完成。綠界回應：%s', 'ry-woocommerce-tools' ),
					$en_msg
				)
			);
			RY_ECPay_Gateway::log( 'Refund(E ok, N failed) for #' . $order->get_order_number() . ': ' . $en_msg, 'error' );

			return new WP_Error(
				'ry_ecpay_refund_action_en_incomplete',
				sprintf(
					/* translators: %s 綠界回應訊息 */
					__( '綠界退款未完成：已取消關帳但放棄授權失敗（%s），交易停在「已授權未關帳」，請至綠界廠商後台人工完成。', 'ry-woocommerce-tools' ),
					$en_msg
				)
			);
		}

		// R、N、E 三條都被綠界拒絕：一併回報三次的 RtnMsg，便於判斷交易實際狀態。
		return new WP_Error(
			'ry_ecpay_refund_doaction_rejected',
			sprintf(
				/* translators: 1: 退刷 R 訊息 2: 放棄授權 N 訊息 3: 取消關帳 E 訊息 */
				__( '綠界退款失敗（退刷 R：%1$s；放棄授權 N：%2$s；取消關帳 E：%3$s）。請登入綠界廠商後台確認交易狀態。', 'ry-woocommerce-tools' ),
				$rtn_msg_r,
				$result_n['RtnMsg'] ?? '',
				$result_e['RtnMsg'] ?? ''
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

		$merchant_trade_no = $order->get_meta( '_ecpay_MerchantTradeNo' );
		if ( empty( $merchant_trade_no ) ) {
			return new WP_Error(
				'ry_ecpay_refund_no_merchant_trade_no',
				__( '訂單缺少綠界特店交易編號（MerchantTradeNo），無法查詢交易狀態。請登入綠界廠商後台處理退款。', 'ry-woocommerce-tools' )
			);
		}

		$args = [
			'MerchantID'      => $merchant_id,
			'MerchantTradeNo' => $merchant_trade_no,
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

		$body = trim( (string) wp_remote_retrieve_body( $response ) );
		RY_ECPay_Gateway::log( 'QueryTradeInfo response for #' . $order->get_order_number() . ': ' . $body );

		$raw = self::parse_response_body( $body );

		// 綠界 QueryTradeInfo 回應「不」對值做 URL encode（中文、空白、字面 + 全是原文輸出），
		// 而 CheckMacValue 是用這些原始值簽的。若改用 parse_str() 解析，它會依
		// x-www-form-urlencoded 語意把字面 + 解成空白、把 %xx 解碼，值被改掉後重算的
		// 章永遠對不上（例：品名「AI 繪圖夢工廠 + 社群玩家特典」的訂單退款 100% 失敗）。
		// 故優先以未解碼的原始字串驗章；為相容綠界日後改為編碼輸出，再以 parse_str() 退而驗一次。
		if ( self::verify_response_check_value( $raw, $hash_key, $hash_iv ) ) {
			$result = $raw;
		} else {
			parse_str( $body, $decoded );
			if ( self::verify_response_check_value( $decoded, $hash_key, $hash_iv ) ) {
				$result = $decoded;
			} elseif ( ! isset( $raw['CheckMacValue'] ) ) {
				return new WP_Error(
					'ry_ecpay_refund_query_bad_response',
					sprintf(
						/* translators: %s 綠界原始回應內容 */
						__( '綠界查詢交易狀態失敗，綠界回應：%s', 'ry-woocommerce-tools' ),
						mb_substr( wp_strip_all_tags( $body ), 0, 200 )
					)
				);
			} else {
				return new WP_Error( 'ry_ecpay_refund_query_checkmac_failed', __( '綠界查詢回應驗證失敗（CheckMacValue 不符），退款已中止。', 'ry-woocommerce-tools' ) );
			}
		}

		// TradeStatus 非 1 時可能是綠界錯誤碼（例：10200047 交易不存在），一律視為未完成付款。

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

		$body = trim( (string) wp_remote_retrieve_body( $response ) );
		RY_ECPay_Gateway::log( 'DoAction(' . $action . ') response for #' . $order->get_order_number() . ': ' . $body );

		// 同樣不能用 parse_str()：綠界回應未編碼，RtnMsg 含中文或 + 時會被改掉。
		$result = self::parse_response_body( $body );

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
	 * 解析綠界回應的 form-urlencoded body（不做 URL 解碼）
	 *
	 * 綠界回應是以原文輸出的（中文不編碼、空白不轉 +、字面 + 也不轉 %2B），
	 * 且 CheckMacValue 是用這些原始值簽的。若用 PHP `parse_str()` 會把字面 + 解成空白、
	 * 把 %xx 解碼、並把 key 中的 `.` 與空白換成 `_`，造成驗章必定失敗。
	 *
	 * @param string $body 回應原始內容。
	 * @return array 解析結果（key/value 皆保留原始字串）。
	 */
	protected static function parse_response_body( $body ) {
		$result = [];
		foreach ( explode( '&', (string) $body ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$kv               = explode( '=', $pair, 2 );
			$result[ $kv[0] ] = $kv[1] ?? '';
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

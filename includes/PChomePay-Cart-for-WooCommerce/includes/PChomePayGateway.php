<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 2018/1/19
 * Time: 上午10:26
 */

defined( 'ABSPATH' ) || exit;

class WC_Gateway_PChomePay extends WC_Payment_Gateway {

	/** @var bool Whether or not logging is enabled */
	public static $log_enabled = false;

	/** @var WC_Logger Logger instance */
	public static $log = false;
	public static $customize_order_received_text;

	public $app_id;
	public $secret;
	public $sandbox_secret;
	public $atm_expiredate;
	public $test_mode;
	public $debug;
	public $notify_url;
	public $payment_methods;
	public $card_installment;
	public $card_last_number;
	public $client;

	public function __construct() {
		// Validate ATM ExpireDate
		if ( isset( $_POST['woocommerce_pchomepay_atm_expiredate'] ) && ( ! preg_match( '/^\d*$/', $_POST['woocommerce_pchomepay_atm_expiredate'] ) || $_POST['woocommerce_pchomepay_atm_expiredate'] < 1 || $_POST['woocommerce_pchomepay_atm_expiredate'] > 5 ) ) {
			$_POST['woocommerce_pchomepay_atm_expiredate'] = 5;
		}

		$this->id                 = 'pchomepay';
		$this->icon               = apply_filters( 'woocommerce_pchomepay_icon', plugins_url( 'images/pchomepay_logo.png', __DIR__ ) );
		$this->has_fields         = false;
		$this->method_title       = __( 'PChomePay支付連', 'woocommerce' );
		$this->method_description = '透過 PChomePay支付連 付款，會連結到 PChomePay支付連 付款頁面。';
		$this->supports           = [ 'products', 'refunds' ];

		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		$this->enabled        = $this->get_option( 'enabled' );
		$this->title          = $this->get_option( 'title' );
		$this->description    = $this->get_option( 'description' );
		$this->app_id         = trim( get_option( 'woomp_pchomepay_app_id' ) );
		$this->secret         = trim( get_option( 'woomp_pchomepay_secret' ) );
		$this->sandbox_secret = trim( get_option( 'woomp_pchomepay_sandbox_secret' ) );
		$this->atm_expiredate = get_option( 'woomp_pchomepay_atm_expiredate' );
		// Test Mode
		$this->test_mode        = ( get_option( 'woomp_pchomepay_test_mode' ) === 'yes' ) ? true : false;
		$this->debug            = ( get_option( 'woomp_pchomepay_debug' ) === 'yes' ) ? true : false;
		$this->notify_url       = WC()->api_request_url( get_class( $this ) );
		$this->payment_methods  = get_option( 'woomp_pchomepay_payment_methods' );
		$this->card_installment = get_option( 'woomp_pchomepay_card_installment' );
		$this->card_last_number = ( get_option( 'woomp_pchomepay_card_last_number' ) === 'yes' ) ? true : false;

		self::$customize_order_received_text = get_option( 'woomp_pchomepay_customize_order_received_text' );
		self::$log_enabled                   = $this->debug;

		if ( empty( $this->app_id ) || empty( $this->secret ) ) {
			$this->enabled = false;
		} else {
			$this->client = new PChomePayClient( $this->app_id, $this->secret, $this->sandbox_secret, $this->test_mode, self::$log_enabled );
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), [ $this, 'receive_response' ] );
		add_filter( 'https_ssl_verify', '__return_false' );
	}

	public function is_available() {
		return wc_string_to_bool( $this->get_option( 'enabled' ) );
	}

	public function init_form_fields() {
		$this->form_fields = [
			'enabled'     => [
				'title'   => __( 'Enable/Disable', 'woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable', 'woocommerce' ),
				'default' => 'no',
			],
			'title'       => [
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
				'default'     => __( 'PChomePay', 'woocommerce' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
				'default'     => __( '透過 PChomePay支付連 付款，會連結到 PChomePay支付連 付款頁面。', 'woocommerce' ),
			],
		];
	}

	public function admin_options() {
		parent::admin_options();
	}

	// **
	// * 前端付款方式顯示
	// * Display the form when chooses O'Pay payment
	// */
	// public function payment_fields()
	// {
	// if (!empty($this->description)) {
	// echo $this->description . '<br /><br />';
	// }
	// echo __('Payment Method', 'woocommerce') . ' : ';
	// echo '<select name="'. $this->id .'_choose_payment">';
	// foreach ($this->payment_methods as $method) {
	// echo '  <option value="' . $method . '">';
	// echo '    ' . $method;
	// echo '  </option>';
	// }
	// echo '</select>';
	// }

	private function get_pchomepay_payment_data( $order ) {
		global $woocommerce;

		$order_id    = 'AW' . date( 'Ymd' ) . $order->get_order_number();
		$pay_type    = $this->payment_methods;
		$amount      = ceil( $order->get_total() );
		$returnUrl   = $this->get_return_url( $order );
		$notifyUrl   = $this->notify_url;
		$buyer_email = $order->get_billing_email();

		if ( isset( $this->atm_expiredate ) && ( ! preg_match( '/^\d*$/', $this->atm_expiredate ) || $this->atm_expiredate < 1 || $this->atm_expiredate > 5 ) ) {
			$this->atm_expiredate = 5;
		}

		$atm_info = (object) [ 'expire_days' => (int) $this->atm_expiredate ];

		$card_info = [];

		foreach ( $this->card_installment as $items ) {
			switch ( $items ) {
				case 'CRD_3':
					$card_installment['installment'] = 3;
					break;
				case 'CRD_6':
					$card_installment['installment'] = 6;
					break;
				case 'CRD_12':
					$card_installment['installment'] = 12;
					break;
				default:
					unset( $card_installment );
					break;
			}
			if ( isset( $card_installment ) ) {
				$card_info[] = (object) $card_installment;
			}
		}

		$items = [];

		$order_items = $order->get_items();
		foreach ( $order_items as $item ) {
			$product         = [];
			$order_item      = new WC_Order_Item_Product( $item );
			$product_id      = ( $order_item->get_product_id() );
			$product['name'] = $order_item->get_name();
			$product['url']  = get_permalink( $product_id );

			$items[] = (object) $product;
		}

		$pchomepay_args = [
			'order_id'    => $order_id,
			'pay_type'    => $pay_type,
			'amount'      => $amount,
			'return_url'  => $returnUrl,
			'notify_url'  => $notifyUrl,
			'items'       => $items,
			'buyer_email' => $buyer_email,
			'atm_info'    => $atm_info,
		];

		if ( $card_info ) {
			$pchomepay_args['card_info'] = $card_info;
		}

		return apply_filters( 'woocommerce_pchomepay_args', $pchomepay_args );
	}

	public function process_payment( $order_id ) {
		try {
			global $woocommerce;

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				throw new Exception( '無法取得訂單資料。' );
			}

			$pchomepay_args = json_encode( $this->get_pchomepay_payment_data( $order ) );

			if ( ! class_exists( 'PChomePayClient' ) ) {
				if ( ! require __DIR__ . '/PChomePayClient.php' ) {
					throw new Exception( __( 'PChomePayClient Class missed.', 'woocommerce' ) );
				}
			}

			// 建立訂單
			$result = $this->client->postPayment( $pchomepay_args );

			if ( ! $result ) {
				self::log( '交易失敗：伺服器端未知錯誤，請聯絡 PChomePay支付連。' );
				throw new Exception( '嘗試使用付款閘道 API 建立訂單時發生錯誤，請聯絡網站管理員。' );
			}

			// 減少庫存
			wc_reduce_stock_levels( $order_id );
			// 清空購物車
			$woocommerce->cart->empty_cart();

			// 更新訂單狀態為等待中 (等待第三方支付網站返回)
			$order->update_meta_data( '_pchomepay_orderid', $result->order_id );
			$order->save();
			$order->update_status( 'pending', __( 'Awaiting PChomePay payment', 'woocommerce' ) );
			$order->add_order_note( '訂單編號：' . $result->order_id, true );
			// 返回感謝購物頁面跳轉
			return [
				'result'       => 'success',
				// 'redirect' => $order->get_checkout_payment_url(true)
					'redirect' => $result->payment_url,
			];

		} catch ( Exception $e ) {
			wc_add_notice( __( $e->getMessage(), 'woocommerce' ), 'error' );
		}
	}

	public function receive_response() {
		usleep( 500000 );

		$notify_type    = $_REQUEST['notify_type'];
		$notify_message = $_REQUEST['notify_message'];

		$refund_array = [ 'refund_pending', 'refund_success', 'refund_fail' ];

		if ( in_array( $notify_type, $refund_array ) ) {
			$order_data = json_decode( str_replace( '\"', '"', $notify_message ) );

			$order = wc_get_order( substr( $order_data->refund_id, 13 ) );

			if ( $order ) {
				$order->add_order_note( 'Notify_Type:' . $notify_type . '<br>Notify_Message: ' . $notify_message, true );
			}
			echo 'success';
			exit();
		}

		if ( ! $notify_type || ! $notify_message ) {
			http_response_code( 404 );
			exit;
		}

		$order_data  = json_decode( str_replace( '\"', '"', $notify_message ) );
		$wc_order_id = substr( $order_data->order_id, 10 );
		$order       = wc_get_order( $wc_order_id );

		if ( ! $order ) {
			http_response_code( 404 );
			exit;
		}

		// 紀錄訂單付款方式
		switch ( $order_data->pay_type ) {
			case 'ATM':
				$pay_type_note  = 'ATM 付款';
				$pay_type_note .= '<br>ATM虛擬帳號: ' . $order_data->payment_info->bank_code . ' - ' . $order_data->payment_info->virtual_account;
				break;
			case 'CARD':
				if ( $order_data->payment_info ) {
					if ( $order_data->payment_info->installment == 1 ) {
						$pay_type_note = '信用卡 付款 (一次付清)';
					} else {
						$pay_type_note = '信用卡 分期付款 (' . $order_data->payment_info->installment . '期)';
					}

					if ( $this->card_last_number ) {
						$pay_type_note .= '<br>末四碼: ' . $order_data->payment_info->card_last_number;
					}
				} else {
					$pay_type_note = '信用卡 付款';
				}

				break;
			case 'ACCT':
				$pay_type_note = '支付連餘額 付款';
				break;
			case 'EACH':
				$pay_type_note = '銀行支付 付款';
				break;
			case 'IPL7':
				$pay_type_note = '7-11超商 付款';
				break;
			case 'PI':
				$pay_type_note = 'PI拍錢包 付款';
				break;
			default:
				$pay_type_note = '未選擇付款方式';
		}

		if ( ! $order->get_meta( '_pchomepay_paytype' ) ) {
			$order->update_meta_data( '_pchomepay_paytype', $order_data->pay_type );
			$order->save();
		}

		if ( $notify_type == 'order_audit' ) {
			if ( $order_data->status_code === OrderStatusCodeEnum::ORDER_PENDING_CLIENT ) {
				$order->update_status( 'awaiting' );
			} elseif ( $order_data->status_code === OrderStatusCodeEnum::ORDER_PENDING_PCHOMEPAY ) {
				$order->update_status( 'awaitingforpcpay' );
			}

			$order->add_order_note( sprintf( __( '訂單交易等待中。<br>status code: %1$s<br>message: %2$s', 'woocommerce' ), $order_data->status_code, OrderStatusCodeEnum::getErrMsg( $order_data->status_code ) ), true );
		} elseif ( $notify_type == 'order_expired' ) {
			$order->add_order_note( $pay_type_note, true );
			if ( $order_data->status_code ) {
				$order->update_status( 'failed' );
				$order->add_order_note( sprintf( __( '訂單已失敗。<br>error code: %1$s<br>message: %2$s', 'woocommerce' ), $order_data->status_code, OrderStatusCodeEnum::getErrMsg( $order_data->status_code ) ), true );
			} else {
				$order->update_status( 'failed' );
				$order->add_order_note( '訂單已失敗。', true );
			}
		} elseif ( $notify_type == 'order_confirm' ) {
			$order->add_order_note( $pay_type_note, true );
			$order->update_status( 'processing' );
			$order->payment_complete();
		}

		echo 'success';
		exit();
	}

	private function get_pchomepay_refund_data( $orderID, $amount, $refundID = null ) {
		try {
			global $woocommerce;

			$order = $this->client->getPayment( $orderID );

			if ( ! $order ) {
				self::log( '查無此筆訂單：' . $orderID );
			}

			$order_id = $order->order_id;

			if ( $amount === $order->amount ) {
				$refund_id = 'RF' . $order_id;
			} elseif ( $refundID ) {
				$number    = (int) substr( $refundID, strpos( $refundID, '-' ) + 1 ) + 1;
				$refund_id = 'RF' . $order_id . '-' . $number;
			} else {
				$refund_id = 'RF' . $order_id . '-1';
			}

			$trade_amount   = (int) $amount;
			$pchomepay_args = [
				'order_id'     => $order_id,
				'refund_id'    => $refund_id,
				'trade_amount' => $trade_amount,
			];

			return apply_filters( 'woocommerce_pchomepay_args', $pchomepay_args );
		} catch ( Exception $e ) {
			self::log( $e->getMessage() );
			throw $e;
		}
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		try {
			$wcOrder = wc_get_order( $order_id );

			if ( ! $wcOrder ) {
				throw new Exception( '無法取得訂單資料。' );
			}

			$orderID   = $wcOrder->get_meta( '_pchomepay_orderid' );
			$refundIDs = $wcOrder->get_meta( '_pchomepay_refundid' );

			if ( $refundIDs ) {
				$refundID = trim( strrchr( $refundIDs, ',' ), ', ' ) ? trim( strrchr( $refundIDs, ',' ), ', ' ) : $refundIDs;
			} else {
				$refundID = $refundIDs;
			}

			$pchomepay_args = json_encode( $this->get_pchomepay_refund_data( $orderID, $amount, $refundID ) );

			if ( ! class_exists( 'PChomePayClient' ) && ! require __DIR__ . '/PChomePayClient.php' ) {
				throw new Exception( __( 'PChomePayClient Class missed.', 'woocommerce' ) );
			}

			$payType = $wcOrder->get_meta( '_pchomepay_paytype' );

			$version = ( in_array( $payType, [ 'IPL7', 'IPPI' ] ) ) ? 'v1' : 'v2';

			// 退款
			$response_data = $this->client->postRefund( $pchomepay_args, $version );

			if ( ! $response_data ) {
				self::log( '退款失敗：伺服器端未知錯誤，請聯絡 PChomePay支付連。' );
				return false;
			}

			if ( isset( $response_data->refund_id ) ) {
				// 更新 meta
				if ( $refundID ) {
					$wcOrder->update_meta_data( '_pchomepay_refundid', $refundIDs . ', ' . $response_data->refund_id );
				} else {
					$wcOrder->update_meta_data( '_pchomepay_refundid', $response_data->refund_id );
				}

				if ( isset( $response_data->redirect_url ) ) {
					$wcOrder->update_meta_data( '_pchomepay_refund_url', $response_data->refund_id . ' : ' . $response_data->redirect_url );
				}
			}
			$wcOrder->save();
			$wcOrder->add_order_note( '退款編號：' . json_decode( $pchomepay_args )->refund_id, true );

			return true;
		} catch ( Exception $e ) {
			self::log( $e->getMessage() );
			throw $e;
		}
	}

	private function get_pchomepay_audit_data( $wcOrder, $status ) {
		$order_id = 'AW' . date_format( $wcOrder->get_date_created(), 'Ymd' ) . $wcOrder->get_id();
		$order    = $this->client->getPayment( $order_id );

		$pchomepay_args = [
			'order_id' => $order->order_id,
			'status'   => $status,
		];

		$pchomepay_args = apply_filters( 'woocommerce_pchomepay_args', $pchomepay_args );

		return $pchomepay_args;
	}

	public function process_audit( $wc_orderid, $status ) {
		try {
			$wcOrder = wc_get_order( $wc_orderid );

			if ( ! $wcOrder ) {
				throw new Exception( '無法取得訂單資料。' );
			}
			$pchomepay_args = json_encode( $this->get_pchomepay_audit_data( $wcOrder, $status ) );

			if ( ! class_exists( 'PChomePayClient' ) ) {
				if ( ! require __DIR__ . '/PChomePayClient.php' ) {
					throw new Exception( __( 'PChomePayClient Class missed.', 'woocommerce' ) );
				}
			}

			$response_data = $this->client->postPaymentAudit( $pchomepay_args );

			if ( ! $response_data ) {
				throw new Exception( __( '審單失敗，未知的錯誤原因', 'woocommerce' ) );
			}

			if ( $response_data->status === 'SUCC' ) {

				switch ( $status ) {
					case 'PASS':
						$wcOrder->add_order_note( '訂單編號：' . $response_data->order_id . '已過單', true );
						break;
					case 'DENY':
						$wcOrder->add_order_note( '訂單編號：' . $response_data->order_id . '已拒絕', true );
						break;
					default:
						throw new Exception( __( '審單狀態錯誤', 'woocommerce' ) );
				}
			}

			return true;
		} catch ( Exception $e ) {
			if ( $e->getCode() ) {
				self::log( '審單失敗，錯誤代碼：' . $e->getCode() );
				$wcOrder->add_order_note( '審單失敗，錯誤代碼：' . $e->getCode() );
			} else {
				self::log( $e->getMessage() );
				$wcOrder->add_order_note( $e->getMessage() );
			}
			return false;
		}
	}

	public function process_query711_history_page( $wc_orderid ) {
		try {
			$wcOrder = wc_get_order( $wc_orderid );

			if ( ! $wcOrder ) {
				throw new Exception( '無法取得訂單資料。' );
			}

			if ( ! class_exists( 'PChomePayClient' ) ) {
				if ( ! require __DIR__ . '/PChomePayClient.php' ) {
					throw new Exception( __( 'PChomePayClient Class missed.', 'woocommerce' ) );
				}
			}

			$response_data = $this->client->get711HistoryPage( $wcOrder->get_meta( '_pchomepay_orderid' ) );

			if ( ! $response_data ) {
				throw new Exception( __( '查詢失敗，未知的錯誤原因', 'woocommerce' ) );
			}

			if ( ! $wcOrder->get_meta( '_pchomepay_logisticid' ) ) {
				$wcOrder->update_meta_data( '_pchomepay_logisticid', $response_data->logistic_id );
				$wcOrder->save();
			}

			return $response_data->history_url;
		} catch ( Exception $e ) {
			if ( $e->getCode() ) {
				self::log( '審單失敗，錯誤代碼：' . $e->getCode() );
				$wcOrder->add_order_note( '審單失敗，錯誤代碼：' . $e->getCode() );
			} else {
				self::log( $e->getMessage() );
				$wcOrder->add_order_note( $e->getMessage() );
			}
			return false;
		}
	}

	/**
	 * Logging method.
	 *
	 * @param string $message Log message.
	 * @param string $level Optional. Default 'info'.
	 *     emergency|alert|critical|error|warning|notice|info|debug
	 */
	public static function log( $message, $level = 'info' ) {
		if ( self::$log_enabled ) {
			if ( empty( self::$log ) ) {
				self::$log = wc_get_logger();
			}
			self::$log->log( $level, $message, [ 'source' => 'pchomepay' ] );
		}
	}

	/**
	 * @param $order_id
	 * @return array
	 */
	function get_order_notes( $order_id ) {
		global $wpdb;

		$table_perfixed = $wpdb->prefix . 'comments';
		$results        = $wpdb->get_results(
			"
        SELECT *
        FROM $table_perfixed
        WHERE  `comment_post_ID` = $order_id
        AND  `comment_type` LIKE  'order_note'
    "
		);

		foreach ( $results as $note ) {
			$order_note[] = [
				'note_id'      => $note->comment_ID,
				'note_date'    => $note->comment_date,
				'note_author'  => $note->comment_author,
				'note_content' => $note->comment_content,
			];
		}
		return $order_note;
	}
}

<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 2018/1/19
 * Time: 上午10:26
 */

defined( 'ABSPATH' ) || exit;

class WC_Gateway_PChomePay extends WC_Payment_Gateway {

	/** 支付連付款方式 ID（一般 + PI 拍錢包） */
	const PAYMENT_METHOD_IDS = [ 'pchomepay', 'pchomepay_pi' ];

	/** 審單中狀態（本外掛自訂） */
	const AUDIT_STATUSES = [ 'awaiting', 'awaitingforpcpay' ];

	/** 訂單類通知 */
	const ORDER_NOTIFY_TYPES = [ 'order_confirm', 'order_expired', 'order_audit' ];

	/** 退款類通知 */
	const REFUND_NOTIFY_TYPES = [ 'refund_pending', 'refund_success', 'refund_fail' ];

	/**
	 * getPayment()（GET /v1/payment/{order_id}）回應欄位
	 *
	 * order_id、amount 由既有退款／審單程式使用而確認；status_code 由 PChomePayClient::handleResult()
	 * 與通知內容確認。status 欄位名稱與成功值 'S'／失敗值 'F' 尚未取得支付連文件確認，
	 * 若與實際不符，回查永遠判斷不了已付款（fail-closed，不會誤標已付款），請依文件修正此處。
	 */
	const QUERY_ORDER_ID_FIELD    = 'order_id';
	const QUERY_AMOUNT_FIELD      = 'amount';
	const QUERY_PAY_TYPE_FIELD    = 'pay_type';
	const QUERY_STATUS_FIELD      = 'status';
	const QUERY_STATUS_CODE_FIELD = 'status_code';
	const QUERY_STATUS_SUCCESS    = 'S';
	const QUERY_STATUS_FAILED     = 'F';

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

	/**
	 * 接收支付連背景通知（wc-api 公開網址）
	 *
	 * 通知內容完全不可信：任何人都能 POST 到這個網址。這裡只把通知當成「觸發訊號」，
	 * 訂單綁定後一律以 getPayment() 伺服器主動回查的結果決定訂單狀態。
	 */
	public function receive_response() {
		usleep( 500000 );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 金流背景通知，改以伺服器回查驗證。
		$result = $this->handle_notify( wp_unslash( $_REQUEST ) );

		status_header( $result['status'] );
		if ( 503 === $result['status'] ) {
			header( 'Retry-After: 60' );
		}
		echo esc_html( $result['body'] );
		exit();
	}

	/**
	 * 處理支付連背景通知
	 *
	 * 回傳值：
	 * - 200 success：已處理（或無需處理），支付連不需重送
	 * - 403：驗證不通過（偽造、訂單不符、金額不符…），不改訂單
	 * - 503：回查失敗（網路／API 錯誤），不改訂單，讓支付連重送
	 *
	 * @param array $request 已 unslash 的請求參數（notify_type、notify_message）.
	 * @return array{status:int, body:string}
	 */
	public function handle_notify( array $request ): array {
		$notify_type    = isset( $request['notify_type'] ) && is_string( $request['notify_type'] ) ? $request['notify_type'] : '';
		$notify_message = isset( $request['notify_message'] ) && is_string( $request['notify_message'] ) ? $request['notify_message'] : '';

		if ( '' === $notify_type || '' === $notify_message ) {
			return self::notify_result( 404, 'not found' );
		}

		$notify_data = json_decode( $notify_message );
		if ( ! is_object( $notify_data ) ) {
			self::reject_notify( 'invalid notify_message', [ 'notify_type' => $notify_type ] );
			return self::notify_result( 403, 'forbidden' );
		}

		if ( in_array( $notify_type, self::REFUND_NOTIFY_TYPES, true ) ) {
			return $this->handle_refund_notify( $notify_type, $notify_data );
		}

		if ( ! in_array( $notify_type, self::ORDER_NOTIFY_TYPES, true ) ) {
			self::reject_notify( 'unknown notify_type', [ 'notify_type' => $notify_type ] );
			return self::notify_result( 403, 'forbidden' );
		}

		// 1. 訂單綁定：通知的 order_id 必須與建立交易時存下的 _pchomepay_orderid 完全一致。
		$pchomepay_order_id = isset( $notify_data->order_id ) && is_string( $notify_data->order_id ) ? $notify_data->order_id : '';
		$order              = $this->find_order_by_pchomepay_order_id( $pchomepay_order_id );
		if ( ! $order ) {
			self::reject_notify( 'order not bound', [ 'notify_type' => $notify_type ] );
			return self::notify_result( 403, 'forbidden' );
		}

		$context = [
			'order_id'    => $order->get_id(),
			'notify_type' => $notify_type,
		];

		// 2. 伺服器主動回查，之後只信任查詢結果。
		if ( ! $this->client instanceof PChomePayClient || ! $this->has_api_credentials() ) {
			self::reject_notify( 'api credentials missing', $context );
			return self::notify_result( 403, 'forbidden' );
		}

		try {
			$payment = $this->client->getPayment( $pchomepay_order_id );
		} catch ( Throwable $e ) {
			if ( (int) $e->getCode() === ApiException::ORDER_NOT_EXIST ) {
				self::reject_notify( 'payment not found on query', $context );
				return self::notify_result( 403, 'forbidden' );
			}
			self::reject_notify( 'payment query failed', $context + [ 'error' => $e->getMessage() ] );
			return self::notify_result( 503, 'service unavailable' );
		}

		if ( ! is_object( $payment ) ) {
			// 空回應（逾時、連線失敗）時 client 會回傳 true／false。
			self::reject_notify( 'payment query returned no data', $context );
			return self::notify_result( 503, 'service unavailable' );
		}

		$query_order_id = self::query_field( $payment, self::QUERY_ORDER_ID_FIELD );
		if ( ! is_string( $query_order_id ) || ! hash_equals( $pchomepay_order_id, $query_order_id ) ) {
			self::reject_notify( 'query order_id mismatch', $context );
			return self::notify_result( 403, 'forbidden' );
		}

		// 付款類別只在第一次、且格式合法時記錄（退款需用來判斷 API 版本）。
		$pay_type = self::query_field( $payment, self::QUERY_PAY_TYPE_FIELD );
		if ( ! $order->get_meta( '_pchomepay_paytype' ) && is_string( $pay_type ) && preg_match( '/^[A-Z0-9_]{1,20}$/', $pay_type ) ) {
			$order->update_meta_data( '_pchomepay_paytype', $pay_type );
			$order->save();
		}

		// 3. 依查詢結果（不是 notify_type）決定訂單狀態。
		switch ( self::get_query_state( $payment ) ) {
			case 'paid':
				return $this->apply_paid_state( $order, $payment, $pchomepay_order_id, $context );

			case 'failed':
				if ( $this->can_change_unpaid_status( $order ) && ! $order->has_status( 'failed' ) ) {
					$order->update_status(
						'failed',
						sprintf(
							'支付連回查：訂單已失敗。<br>error code: %1$s<br>message: %2$s',
							esc_html( (string) self::query_field( $payment, self::QUERY_STATUS_CODE_FIELD ) ),
							esc_html( self::status_code_message( self::query_field( $payment, self::QUERY_STATUS_CODE_FIELD ) ) )
						)
					);
				}
				break;

			case 'awaiting':
			case 'awaitingforpcpay':
				$new_status = self::get_query_state( $payment );
				if ( $this->can_change_unpaid_status( $order ) && ! $order->has_status( $new_status ) ) {
					$order->update_status(
						$new_status,
						sprintf(
							'支付連回查：訂單交易等待中。<br>status code: %1$s<br>message: %2$s',
							esc_html( (string) self::query_field( $payment, self::QUERY_STATUS_CODE_FIELD ) ),
							esc_html( self::status_code_message( self::query_field( $payment, self::QUERY_STATUS_CODE_FIELD ) ) )
						)
					);
				}
				break;

			default:
				// 回查無法確認已付款：order_confirm 視為驗證不通過；其他通知不動訂單。
				if ( 'order_confirm' === $notify_type ) {
					self::reject_notify(
						'payment not confirmed by query',
						$context + [
							'status'      => self::scalar_for_log( self::query_field( $payment, self::QUERY_STATUS_FIELD ) ),
							'status_code' => self::scalar_for_log( self::query_field( $payment, self::QUERY_STATUS_CODE_FIELD ) ),
						]
					);
					return self::notify_result( 403, 'forbidden' );
				}
		}

		return self::notify_result( 200, 'success' );
	}

	/**
	 * 回查結果為已付款時更新訂單
	 *
	 * @param WC_Order $order              訂單.
	 * @param object   $payment            getPayment() 查詢結果.
	 * @param string   $pchomepay_order_id 支付連訂單編號.
	 * @param array    $context            log context.
	 * @return array{status:int, body:string}
	 */
	private function apply_paid_state( WC_Order $order, $payment, string $pchomepay_order_id, array $context ): array {
		if ( $order->is_paid() ) {
			// 重送的合法通知：冪等，不重複處理。
			return self::notify_result( 200, 'success' );
		}

		if ( ! Woomp_Payment_Callback_Guard::amount_matches( $order, self::query_field( $payment, self::QUERY_AMOUNT_FIELD ) ) ) {
			self::reject_notify(
				'amount mismatch',
				$context + [
					'query_amount' => self::scalar_for_log( self::query_field( $payment, self::QUERY_AMOUNT_FIELD ) ),
					'order_total'  => $order->get_total(),
				]
			);
			return self::notify_result( 403, 'forbidden' );
		}

		$is_audit_status = $order->has_status( self::AUDIT_STATUSES ) && ! $order->is_paid();
		if ( ! Woomp_Payment_Callback_Guard::can_mark_paid( $order ) && ! $is_audit_status ) {
			// 已取消／已退款等訂單不可被重放改回已付款，交由商家人工確認。
			self::reject_notify( 'order status not payable', $context + [ 'status' => $order->get_status() ] );
			$order->add_order_note(
				sprintf(
					'支付連回查此交易為已付款，但訂單狀態為「%s」，未自動變更，請至支付連後台確認。',
					esc_html( wc_get_order_status_name( $order->get_status() ) )
				)
			);
			return self::notify_result( 200, 'success' );
		}

		$order->add_order_note( $this->build_pay_type_note( $payment ) );

		// 審單中（awaiting／awaitingforpcpay）不在 WC 預設可完成付款的狀態，暫時放行。
		$allow_audit_statuses = static function ( $statuses ) {
			return array_merge( (array) $statuses, self::AUDIT_STATUSES );
		};
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $allow_audit_statuses );
		$order->payment_complete( $pchomepay_order_id );
		remove_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $allow_audit_statuses );

		return self::notify_result( 200, 'success' );
	}

	/**
	 * 處理退款類通知
	 *
	 * 退款通知只記錄備註、不改訂單狀態；仍須綁定訂單與付款方式，且退款編號必須是本站送出過的。
	 *
	 * @param string $notify_type 通知類型.
	 * @param object $notify_data 通知內容.
	 * @return array{status:int, body:string}
	 */
	private function handle_refund_notify( string $notify_type, $notify_data ): array {
		$refund_id = isset( $notify_data->refund_id ) && is_string( $notify_data->refund_id ) ? $notify_data->refund_id : '';

		// 退款編號格式：RF + 支付連訂單編號（AW + Ymd + 訂單編號）[-序號]。
		if ( ! preg_match( '/^RF(AW\d{8}\d+?)(?:-\d+)?$/', $refund_id, $matches ) ) {
			self::reject_notify( 'invalid refund_id', [ 'notify_type' => $notify_type ] );
			return self::notify_result( 403, 'forbidden' );
		}

		$order = $this->find_order_by_pchomepay_order_id( $matches[1] );
		if ( ! $order ) {
			self::reject_notify( 'refund order not bound', [ 'notify_type' => $notify_type ] );
			return self::notify_result( 403, 'forbidden' );
		}

		$known_refund_ids = array_filter( array_map( 'trim', explode( ',', (string) $order->get_meta( '_pchomepay_refundid' ) ) ) );
		$is_known         = false;
		foreach ( $known_refund_ids as $known_refund_id ) {
			if ( hash_equals( $known_refund_id, $refund_id ) ) {
				$is_known = true;
				break;
			}
		}
		if ( ! $is_known ) {
			self::reject_notify(
				'unknown refund_id',
				[
					'order_id'    => $order->get_id(),
					'notify_type' => $notify_type,
				]
			);
			return self::notify_result( 403, 'forbidden' );
		}

		$labels = [
			'refund_pending' => '退款處理中',
			'refund_success' => '退款成功',
			'refund_fail'    => '退款失敗',
		];

		// 私人備註，不寄給顧客；只寫入固定文字與已驗證格式的退款編號。
		$order->add_order_note( sprintf( '支付連退款通知：%1$s（退款編號：%2$s）', $labels[ $notify_type ], esc_html( $refund_id ) ) );

		return self::notify_result( 200, 'success' );
	}

	/**
	 * 以支付連訂單編號找出已綁定的 WC 訂單
	 *
	 * 支付連訂單編號格式為 AW + Ymd + 訂單編號；訂單的付款方式必須是支付連，
	 * 且 _pchomepay_orderid（建立交易成功時寫入）必須與之完全一致。
	 *
	 * @param string $pchomepay_order_id 支付連訂單編號.
	 * @return WC_Order|null
	 */
	private function find_order_by_pchomepay_order_id( string $pchomepay_order_id ) {
		if ( ! preg_match( '/^AW\d{8}(\d+)$/', $pchomepay_order_id, $matches ) ) {
			return null;
		}

		$order = wc_get_order( (int) $matches[1] );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		if ( ! Woomp_Payment_Callback_Guard::is_payment_method( $order, self::PAYMENT_METHOD_IDS ) ) {
			return null;
		}

		$stored = (string) $order->get_meta( '_pchomepay_orderid' );
		if ( '' === $stored || ! hash_equals( $stored, $pchomepay_order_id ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * 依查詢結果判斷交易狀態
	 *
	 * 回傳 paid／failed／awaiting／awaitingforpcpay／unknown。
	 * 判斷不了一律 unknown（不改訂單、不標已付款）。
	 *
	 * @param object $payment getPayment() 查詢結果.
	 */
	private static function get_query_state( $payment ): string {
		$status      = self::query_field( $payment, self::QUERY_STATUS_FIELD );
		$status_code = self::query_field( $payment, self::QUERY_STATUS_CODE_FIELD );
		$status_code = is_string( $status_code ) ? $status_code : '';

		if ( OrderStatusCodeEnum::ORDER_PENDING_CLIENT === $status_code ) {
			return 'awaiting';
		}
		if ( OrderStatusCodeEnum::ORDER_PENDING_PCHOMEPAY === $status_code ) {
			return 'awaitingforpcpay';
		}
		// OrderStatusCodeEnum 中失敗類代碼皆為 F 開頭（FE、FB、FX…），等待類為 W 開頭。
		if ( ( '' !== $status_code && 'F' === $status_code[0] ) || self::QUERY_STATUS_FAILED === $status ) {
			return 'failed';
		}
		if ( self::QUERY_STATUS_SUCCESS === $status && '' === $status_code ) {
			return 'paid';
		}
		return 'unknown';
	}

	/**
	 * 訂單是否仍是未付款、可依回查結果改成失敗／審單中
	 *
	 * 已付款、已取消、已退款的訂單一律不動。
	 *
	 * @param WC_Order $order 訂單.
	 */
	private function can_change_unpaid_status( WC_Order $order ): bool {
		if ( $order->is_paid() ) {
			return false;
		}
		return $order->has_status( array_merge( Woomp_Payment_Callback_Guard::get_payable_statuses(), self::AUDIT_STATUSES ) );
	}

	/**
	 * 是否已設定 API 金鑰（測試模式使用 sandbox secret）
	 */
	private function has_api_credentials(): bool {
		return Woomp_Payment_Callback_Guard::has_secrets( $this->app_id, $this->test_mode ? $this->sandbox_secret : $this->secret );
	}

	/**
	 * 由查詢結果組出付款方式備註（所有外部字串皆跳脫）
	 *
	 * @param object $payment getPayment() 查詢結果.
	 */
	private function build_pay_type_note( $payment ): string {
		$pay_type     = self::query_field( $payment, self::QUERY_PAY_TYPE_FIELD );
		$payment_info = self::query_field( $payment, 'payment_info' );
		$payment_info = is_object( $payment_info ) ? $payment_info : null;

		switch ( $pay_type ) {
			case 'ATM':
				$note = 'ATM 付款';
				break;
			case 'CARD':
				$note        = '信用卡 付款';
				$installment = $payment_info && isset( $payment_info->installment ) ? $payment_info->installment : null;
				if ( is_numeric( $installment ) ) {
					$note = 1 === (int) $installment ? '信用卡 付款 (一次付清)' : sprintf( '信用卡 分期付款 (%d期)', (int) $installment );
				}
				$last_number = $payment_info && isset( $payment_info->card_last_number ) ? (string) $payment_info->card_last_number : '';
				if ( $this->card_last_number && preg_match( '/^\d{4}$/', $last_number ) ) {
					$note .= '<br>末四碼: ' . esc_html( $last_number );
				}
				break;
			case 'ACCT':
				$note = '支付連餘額 付款';
				break;
			case 'EACH':
				$note = '銀行支付 付款';
				break;
			case 'IPL7':
				$note = '7-11超商 付款';
				break;
			case 'PI':
				$note = 'PI拍錢包 付款';
				break;
			default:
				$note = '付款方式：' . esc_html( is_scalar( $pay_type ) ? (string) $pay_type : '未知' );
		}

		return '支付連回查確認付款完成。' . $note;
	}

	/**
	 * 讀取查詢結果欄位
	 *
	 * @param object $payment 查詢結果.
	 * @param string $field   欄位名稱.
	 * @return mixed|null
	 */
	private static function query_field( $payment, string $field ) {
		return is_object( $payment ) && isset( $payment->{$field} ) ? $payment->{$field} : null;
	}

	/**
	 * 狀態碼說明（未知代碼不報錯）
	 *
	 * @param mixed $status_code 狀態碼.
	 */
	private static function status_code_message( $status_code ): string {
		if ( ! is_string( $status_code ) || '' === $status_code ) {
			return '';
		}
		$reflection = new ReflectionClass( OrderStatusCodeEnum::class );
		if ( ! in_array( $status_code, $reflection->getConstants(), true ) ) {
			return '未知狀態';
		}
		return (string) OrderStatusCodeEnum::getErrMsg( $status_code );
	}

	/**
	 * 只把純量寫進 log（截斷避免攻擊者塞大量內容）
	 *
	 * @param mixed $value 值.
	 */
	private static function scalar_for_log( $value ): string {
		return is_scalar( $value ) ? mb_substr( (string) $value, 0, 64 ) : gettype( $value );
	}

	/**
	 * 記錄被拒絕的通知
	 *
	 * @param string $reason  原因.
	 * @param array  $context 其他資訊（不可含 token、金鑰）.
	 */
	private static function reject_notify( string $reason, array $context = [] ): void {
		Woomp_Payment_Callback_Guard::reject( 'pchomepay', $reason, $context );
	}

	/**
	 * 通知處理結果
	 *
	 * @param int    $status HTTP 狀態碼.
	 * @param string $body   回應內容.
	 * @return array{status:int, body:string}
	 */
	private static function notify_result( int $status, string $body ): array {
		return [
			'status' => $status,
			'body'   => $body,
		];
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

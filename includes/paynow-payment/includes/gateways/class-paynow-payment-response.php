<?php
/**
 * PayNow_Payment_Response class file
 *
 * @package paynow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Receive response from PayNow.
 */
class PayNow_Payment_Response {

	/**
	 * Class instance
	 *
	 * @var PayNow_Payment_Response
	 */
	private static $instance;

	/**
	 * Constructor
	 */
	public function __construct() {
		// do nothing.
	}

	/**
	 * Get the single instance or new one if not exists.
	 *
	 * @return PayNow_Payment_Response
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize and add hooks
	 *
	 * @return void
	 */
	public static function init() {
		self::get_instance();

		// Payment listener/API hook, 此網址請設定在 PayNow 後台.
		add_action( 'woocommerce_api_paynow_payment', [ self::get_instance(), 'paynow_receive_response' ] );
		add_action( 'paynow_payment_online_response', [ self::get_instance(), 'paynow_valid_response' ] );
		add_action( 'paynow_payment_online_background_notification', [ self::get_instance(), 'paynow_background_notification' ] );

		// offline Payment listener/API hook, 此網址請設定在 PayNow 後台.
		add_action( 'woocommerce_api_paynow_payment_offline', [ self::get_instance(), 'paynow_receive_response' ] );
		add_action( 'paynow_payment_offline_response', [ self::get_instance(), 'paynow_valid_offline_response' ] );
	}

	/**
	 * Receive response from PayNow
	 *
	 * @return void
	 */
	public static function paynow_receive_response() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		global $woocommerce;

		PayNow_Payment::log( 'paynow_receive_response. raw post data ' . wc_print_r( $_POST, true ) );

		// PassCode 等來源驗證在各 handler 取得訂單後進行（需要訂單金額與付款方式），見 is_authentic_callback().
		if ( ! empty( $_POST ) ) {

			$posted = wc_clean( wp_unslash( $_POST ) );

			if ( current_action() === 'woocommerce_api_paynow_payment' ) {
				if ( self::is_background_notification( $posted ) ) {
					do_action( 'paynow_payment_online_background_notification', $posted );
				} else {
					do_action( 'paynow_payment_online_response', $posted );
				}
			} elseif ( current_action() === 'woocommerce_api_paynow_payment_offline' ) {
				if ( self::is_background_notification( $posted ) ) {
					do_action( 'paynow_payment_online_background_notification', $posted );
				} else {
					do_action( 'paynow_payment_offline_response', $posted );
				}
			}

			exit;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	// 信用卡(01)、WebATM(02) 即時通知結果
	/**
	 * 處理線上金流即時通知，更新訂單 meta 資料
	 *
	 * @param array $posted PayNow 回傳的交易資料
	 * @return void
	 */
	public static function paynow_valid_response( $posted ) {
		global $woocommerce;

		if ( self::is_background_notification( $posted ) ) {
			return;
		}

		$web_no      = $posted['WebNo'] ?? '';
		$buysafe_no  = $posted['BuysafeNo'] ?? ''; // PayNow訂單編號.
		$pass_code   = $posted['PassCode'] ?? '';
		$order_no    = $posted['OrderNo'] ?? '';
		$tran_status = $posted['TranStatus'] ?? '';// 交易結果 S or F.
		$pay_type    = $posted['PayType'] ?? '';
		$pan_no4     = $posted['pan_no4'] ?? '';// 信用卡末四碼.

		PayNow_Payment::log( 'Order ' . $order_no . ' response received from PayNow. ' . wc_print_r( $posted, true ) );

		if ( ! self::is_valid_web_no( $web_no ) ) {
			PayNow_Payment::log( 'Received WebNo ' . $web_no . ' is not the same as the site WebNo ' . get_option( 'paynow_payment_web_no' ) . ' do nothing' );
			return;
		}

		$order = self::get_paynow_order( $posted );

		if ( $order && self::is_authentic_callback( $order, $posted ) ) {

			if ( self::should_ignore_callback( $order, $tran_status ) ) {
				wp_safe_redirect( $order->get_checkout_order_received_url() );
				exit;
			}

			$order->update_meta_data( '_paynow_tran_id', $buysafe_no );
			$order->update_meta_data( '_paynow_tran_status', $tran_status );

			// 01:信用卡, 09:銀聯, 11:分期付款.
			if ( PayNow_Pay_Type::CREDIT === $pay_type || PayNow_Pay_Type::UNION === $pay_type || PayNow_Pay_Type::CREDIT_INSTALL === $pay_type ) {
				$order->update_meta_data( '_paynow_pan_no4', $pan_no4 );
			}

			// 定期定額.
			if ( array_key_exists( 'CIFID', $posted ) ) {
				$order->update_meta_data( '_cifid', $posted['CIFID'] );
				$order->update_meta_data( '_cifpw', $posted['CIFPW'] );
				$order->update_meta_data( '_cifid_sn', $posted['CIFID_SN'] );
			}

			if ( 'S' === $tran_status ) {

				$order->save();
				$order->payment_complete( $buysafe_no );
				$woocommerce->cart->empty_cart();

			} else {

				$err_desc = $posted['ErrDesc'] ?? '';

				$order->update_meta_data( '_paynow_errdesc', urldecode( $err_desc ) );
				$order->save();

				$order->update_status( 'on-hold' );
				$woocommerce->cart->empty_cart();

			}

			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}
	}

	/**
	 * 處理離線支付（虛擬帳號、超商代碼、條碼）PayNow 回傳通知
	 *
	 * @param array $posted PayNow 回傳的交易資料
	 * @return void
	 */
	public static function paynow_valid_offline_response( $posted ) {
		global $woocommerce;

		if ( self::is_background_notification( $posted ) ) {
			return;
		}

		$web_no     = $posted['WebNo'] ?? '';
		$buysafe_no = $posted['BuysafeNo'] ?? '';// paynow訂單編號.
		$pass_code  = $posted['PassCode'] ?? '';
		$order_no   = $posted['OrderNo'] ?? '';
		$pay_type   = $posted['PayType'] ?? '';

		$new_date = urldecode( $posted['NewDate'] ?? '' );
		$due_date = urldecode( $posted['DueDate'] ?? '' );

		$tran_status = $posted['TranStatus'] ?? '';

		$order = self::get_paynow_order( $posted );
		PayNow_Payment::log( 'Order ' . $order_no . ' offline payment response received from PayNow. ' . wc_print_r( $posted, true ) );

		if ( ! self::is_valid_web_no( $web_no ) ) {
			PayNow_Payment::log( 'Received WebNo ' . $web_no . ' is not the same as the site WebNo ' . get_option( 'paynow_payment_web_no' ) . ' do nothing' );
			return;
		}

		if ( $order && self::is_authentic_callback( $order, $posted ) ) {

			if ( self::should_ignore_callback( $order, $tran_status ) ) {
				wp_safe_redirect( $order->get_checkout_order_received_url() );
				exit;
			}

			$order->update_meta_data( '_paynow_new_date', $new_date );
			$order->update_meta_data( '_paynow_due_date', $due_date );

			$order->update_meta_data( '_paynow_tran_id', $buysafe_no );
			$order->update_meta_data( '_paynow_tran_status', $tran_status );
			// HPOS 下 `_transaction_id` 是 wc_orders.transaction_id 欄位，
			// 透過 update_meta_data 寫入會被靜默丟棄，必須使用 setter。
			$order->set_transaction_id( $buysafe_no );

			// 虛擬帳號 virtual account pay type = 03.
			if ( PayNow_Pay_Type::VIRTUAL_ACCOUNT === $pay_type ) {

				$bank_code = $posted['BankCode'] ?? '';
				$atm_no    = $posted['ATMNo'] ?? '';
				$order->update_meta_data( '_paynow_bank_code', $bank_code );
				$order->update_meta_data( '_paynow_atm_no', $atm_no );

			}

			// 條碼 barcode pay type=10.
			if ( PayNow_Pay_Type::BARCODE === $pay_type ) {

				$barcode1 = $posted['BarCode1'] ?? '';
				$barcode2 = $posted['BarCode2'] ?? '';
				$barcode3 = $posted['BarCode3'] ?? '';
				$order->update_meta_data( 'barcode1', $barcode1 );
				$order->update_meta_data( 'barcode2', $barcode2 );
				$order->update_meta_data( 'barcode3', $barcode3 );

			}

			// ibon 超商代碼(ibon) pay type = 05.
			if ( PayNow_Pay_Type::IBON === $pay_type ) {

				$ibon_no   = $posted['IBONNO'] ?? '';
				$passcode2 = $posted['PassCode2'] ?? '';
				$order->update_meta_data( '_paynow_ibon_no', $ibon_no );
				// TODO: should validate passcode2.
				$order->update_meta_data( '_paynow_passcode2', $passcode2 );

			}

			if ( 'S' === $tran_status ) {

				$order->save();
				$order->add_order_note( __( 'Recieved PayNow payment completed notification', 'paynow-payment' ) );
				$order->payment_complete( $buysafe_no );
				$woocommerce->cart->empty_cart();

			} else {

				$order->save();
				$order->update_status( 'on-hold' );
				$woocommerce->cart->empty_cart();

			}

			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}
	}

	// 成功才會回傳
	/**
	 * 處理 PayNow 背景通知（成功付款時回傳）
	 *
	 * @param array $posted PayNow 回傳的交易資料
	 * @return void
	 */
	public static function paynow_background_notification( $posted ) {
		global $woocommerce;

		$web_no      = $posted['WebNo'] ?? '';
		$buysafe_no  = $posted['BuysafeNo'] ?? ''; // PayNow訂單編號.
		$pass_code   = $posted['PassCode'] ?? '';
		$order_no    = $posted['OrderNo'] ?? '';
		$tran_status = $posted['TranStatus'] ?? '';// 交易結果 S or F.
		$pay_type    = $posted['PayType'] ?? '';// 交易類別
		$pan_no4     = $posted['Pan_no4'] ?? '';// 信用卡末四碼.

		PayNow_Payment::log( 'Order ' . $order_no . ' background notification received from PayNow. ' . wc_print_r( $posted, true ) );

		if ( ! self::is_valid_web_no( $web_no ) ) {
			PayNow_Payment::log( 'Received WebNo ' . $web_no . ' is not the same as the site WebNo ' . get_option( 'paynow_payment_web_no' ) . ' do nothing' );
			return;
		}

		$order = self::get_paynow_order( $posted );
		if ( $order && self::is_authentic_callback( $order, $posted ) ) {
			if ( self::should_ignore_callback( $order, $tran_status ) ) {
				echo '1';
				return;
			}

			$order->update_meta_data( '_paynow_tran_id', $buysafe_no );
			$order->update_meta_data( '_paynow_tran_status', $tran_status );
			if ( ! empty( $pan_no4 ) ) {
				$order->update_meta_data( '_paynow_pan_no4', $pan_no4 );
			}
			$order->save();

			// should_ignore_callback() 已確認 S 時訂單可標為已付款.
			if ( 'S' === $tran_status ) {
				$order->payment_complete( $buysafe_no );
			}

			if ( $woocommerce->cart ) {
				$woocommerce->cart->empty_cart();
			}
			echo '1';
		}
	}

	private static function is_background_notification( $posted ) {
		$method = ( array_key_exists( 'method', $posted ) ) ? $posted['method'] : '';
		if ( 'paynow_return' === $method ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * 確認回呼確實來自 PayNow 且對應這張訂單
	 *
	 * 回呼網址是公開的，任何人都能 POST；未通過驗證的資料一律不得變更訂單。
	 * 依序檢查：
	 * 1. 訂單是用 PayNow 付款方式下單
	 * 2. 回傳金額 TotalPrice 與訂單金額相符
	 * 3. PassCode = sha1( WebNo + OrderNo + TotalPrice + 交易密碼 + TranStatus )
	 *    （PayNow 官方文件；ibon／FamiPort／icash 產生代碼時的回傳不含 TranStatus，虛擬帳號取號也一併容許）
	 *
	 * 不含 TranStatus 的 PassCode 只接受非 S 的回呼：取號回傳會經過消費者瀏覽器，
	 * 若對 S 也接受，消費者可拿取號時的 PassCode 改送 TranStatus=S 偽造付款完成。
	 *
	 * @param WC_Order $order  The order object.
	 * @param array    $posted PayNow 回傳的交易資料.
	 * @return bool
	 */
	private static function is_authentic_callback( $order, $posted ) {
		$order_no    = (string) ( $posted['OrderNo'] ?? '' );
		$total_price = (string) ( $posted['TotalPrice'] ?? '' );
		$tran_status = (string) ( $posted['TranStatus'] ?? '' );
		$pass_code   = strtolower( (string) ( $posted['PassCode'] ?? '' ) );

		if ( 0 !== strpos( (string) $order->get_payment_method(), 'paynow-' ) ) {
			PayNow_Payment::log( 'Order ' . $order->get_id() . ' payment method ' . $order->get_payment_method() . ' is not PayNow, reject callback', 'error' );
			return false;
		}

		if ( ! is_numeric( $total_price ) || abs( (float) $total_price - (float) $order->get_total() ) >= 0.01 ) {
			PayNow_Payment::log( 'Order ' . $order->get_id() . ' TotalPrice ' . $total_price . ' does not match order total ' . $order->get_total() . ', reject callback', 'error' );
			return false;
		}

		$trans_pwd = (string) get_option( 'paynow_payment_trans_pwd' );
		if ( '' === $trans_pwd || '' === $pass_code ) {
			PayNow_Payment::log( 'Order ' . $order->get_id() . ' missing PassCode or transaction password, reject callback', 'error' );
			return false;
		}

		// 送出時 WebNo 經過 strtoupper()；回傳的 WebNo 已由 is_valid_web_no() 確認只差大小寫，兩者都試.
		$web_nos = array_unique( array_filter( [ strtoupper( (string) get_option( 'paynow_payment_web_no' ) ), (string) ( $posted['WebNo'] ?? '' ) ] ) );

		foreach ( $web_nos as $web_no ) {
			$base       = $web_no . $order_no . $total_price . $trans_pwd;
			$candidates = [ $base . $tran_status ];
			if ( 'S' !== $tran_status ) {
				$candidates[] = $base;
			}

			foreach ( $candidates as $candidate ) {
				if ( hash_equals( sha1( $candidate ), $pass_code ) ) {
					return true;
				}
			}
		}

		PayNow_Payment::log( 'Order ' . $order->get_id() . ' PassCode verification failed, reject callback', 'error' );
		return false;
	}

	/**
	 * 通過來源驗證的回呼，是否仍不該改動這張訂單
	 *
	 * - S（付款成功）：只有 Woomp_Payment_Callback_Guard::can_mark_paid() 的訂單才處理。
	 *   WC 的 payment_complete() 允許 cancelled → processing，消費者可在商家取消／退款後重送自己拿到的合法成功通知；
	 *   已付款訂單的重複通知也不再重寫交易資料。未付款但不可標為已付款（已取消／已退款）時留訂單備註給商家。
	 * - 非 S（取號、失敗）：只處理 pending 訂單。已付款、已取消、已保留中的訂單收到晚到或重送的通知，
	 *   不可被改成保留中，也不可改寫 ATM／條碼等繳費資訊。
	 *
	 * @param WC_Order $order       The order object.
	 * @param string   $tran_status 交易結果 S or F.
	 * @return bool
	 */
	private static function should_ignore_callback( $order, $tran_status ) {
		if ( 'S' === $tran_status ) {
			if ( Woomp_Payment_Callback_Guard::can_mark_paid( $order ) ) {
				return false;
			}

			if ( ! $order->is_paid() ) {
				// 只有通過 PassCode 驗證才會到這裡；備註直接用繁中，避免新增翻譯字串（po／mo／l10n.php 需同步）.
				$order->add_order_note(
					sprintf(
						'收到 PayNow 付款成功通知，但訂單目前狀態為「%s」，未變更訂單狀態；請至 PayNow 商家後台確認此筆款項。',
						wc_get_order_status_name( $order->get_status() )
					)
				);
			}
			PayNow_Payment::log( 'Order ' . $order->get_id() . ' status ' . $order->get_status() . ' cannot be marked as paid, ignore TranStatus S callback', 'error' );
			return true;
		}

		if ( $order->has_status( 'pending' ) ) {
			return false;
		}

		PayNow_Payment::log( 'Order ' . $order->get_id() . ' status ' . $order->get_status() . ' is not pending, ignore TranStatus ' . $tran_status . ' callback' );
		return true;
	}

	/**
	 * 檢查 PayNow 回傳的 WebNo 是否與站台設定相符
	 *
	 * 送出交易時 WebNo 會先經過 strtoupper()（見 PayNow_Abstract_Payment_Gateway），
	 * PayNow 回傳的大小寫因此可能與後台設定值不同，必須正規化後再比對。
	 *
	 * @param string $web_no PayNow 回傳的 WebNo.
	 * @return bool
	 */
	private static function is_valid_web_no( $web_no ) {
		$site_web_no = (string) get_option( 'paynow_payment_web_no' );
		if ( '' === $site_web_no ) {
			return false;
		}

		// 官方文件：WebNo「僅用信用卡交易時回傳」。沒帶時不比對，來源由 is_authentic_callback() 以站台 WebNo 驗證.
		return '' === (string) $web_no || strtoupper( $web_no ) === strtoupper( $site_web_no );
	}

	/**
	 * Get PayNow order from posted data.
	 *
	 * @param array $posted The posted data.
	 * @return WC_Order|false
	 */
	private static function get_paynow_order( $posted ) {
		$order_no = (string) ( $posted['OrderNo'] ?? '' );
		$note1    = (string) ( $posted['Note1'] ?? '' );

		if ( '' === $order_no ) {
			PayNow_Payment::log( 'Order not found. OrderNo is empty', 'error' );
			return false;
		}

		// 下單時 OrderNo 送的是 get_order_number()（可能被序號外掛改寫），Note1 送的是訂單 ID（PayNow_Payment_Request）。
		// 優先用 Note1 找訂單；沒帶 Note1 才把 OrderNo 當 ID。兩種方式都必須再比對 get_order_number() === OrderNo：
		// PassCode 是用 OrderNo 算的，若只把 OrderNo 當 ID，序號外掛站台上「A 訂單的顯示編號」可能剛好是「B 訂單的 ID」，
		// 回呼就會套到錯的訂單上。
		if ( '' !== $note1 ) {
			$order = ctype_digit( $note1 ) ? wc_get_order( absint( $note1 ) ) : false;
		} else {
			$order = ctype_digit( $order_no ) ? wc_get_order( absint( $order_no ) ) : false;
		}

		if ( $order && (string) $order->get_order_number() !== $order_no ) {
			PayNow_Payment::log( 'Order number mismatch. OrderNo=' . $order_no . ' Note1=' . $note1 . ' order_number=' . $order->get_order_number() . ', reject callback', 'error' );
			return false;
		}

		if ( ! $order ) {
			PayNow_Payment::log( 'Order not found. OrderNo=' . $order_no . ' Note1=' . $note1, 'error' );
		}

		return $order;
	}
}

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

			if ( self::is_downgrade_of_paid_order( $order, $tran_status ) ) {
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

			if ( self::is_downgrade_of_paid_order( $order, $tran_status ) ) {
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
			if ( self::is_downgrade_of_paid_order( $order, $tran_status ) ) {
				echo '1';
				return;
			}

			$order->update_meta_data( '_paynow_tran_id', $buysafe_no );
			$order->update_meta_data( '_paynow_tran_status', $tran_status );
			if ( ! empty( $pan_no4 ) ) {
				$order->update_meta_data( '_paynow_pan_no4', $pan_no4 );
			}
			$order->save();

			if ( ! $order->is_paid() && 'S' === $tran_status ) {
				$order->payment_complete( $buysafe_no );
			}

			$woocommerce->cart->empty_cart();
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
	 *    （PayNow 規格；虛擬帳號／ibon 取號時的回傳不含 TranStatus）
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
	 * 已付款訂單收到非成功的回呼時不得改動訂單
	 *
	 * 例如 ATM 取號回呼晚到、或重送的失敗通知，不可把已完成付款的訂單打回保留中。
	 *
	 * @param WC_Order $order       The order object.
	 * @param string   $tran_status 交易結果 S or F.
	 * @return bool
	 */
	private static function is_downgrade_of_paid_order( $order, $tran_status ) {
		if ( 'S' === $tran_status || ! $order->is_paid() ) {
			return false;
		}

		PayNow_Payment::log( 'Order ' . $order->get_id() . ' is already paid, ignore TranStatus ' . $tran_status . ' callback' );
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
		return '' !== $site_web_no && strtoupper( $web_no ) === strtoupper( $site_web_no );
	}

	/**
	 * Get PayNow order from posted data.
	 *
	 * @param array $posted The posted data.
	 * @return WC_Order|false
	 */
	private static function get_paynow_order( $posted ) {
		$order_no = $posted['OrderNo'] ?? '';
		$order    = wc_get_order( $order_no );

		// 站台若以外掛改寫訂單顯示編號，OrderNo 會對不到訂單 ID，
		// 改用下單時寫入 Note1 的訂單 ID 反查，並比對顯示編號確認是同一張訂單。
		if ( ! $order && ! empty( $posted['Note1'] ) ) {
			$candidate = wc_get_order( absint( $posted['Note1'] ) );
			if ( $candidate && (string) $candidate->get_order_number() === (string) $order_no ) {
				$order = $candidate;
			}
		}

		if ( ! $order ) {
			PayNow_Payment::log( 'Order not found. OrderNo=' . $order_no . ' Note1=' . ( $posted['Note1'] ?? '' ), 'error' );
		}

		return $order;
	}
}

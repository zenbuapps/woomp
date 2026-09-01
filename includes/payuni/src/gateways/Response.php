<?php

/**
 * Payuni_Payment_Response class file
 *
 * @package Payuni
 */

namespace PAYUNI\Gateways;

use Payuni\APIs\Payment;
use function function_exists;

defined( 'ABSPATH' ) || exit;

/**
 * Receive response from Payuni.
 */
final class Response {


	/**
	 * Class instance
	 *
	 * @var Response
	 */
	private static $instance;

	/**
	 * Constructor
	 */
	public function __construct() {
		// do nothing.
	}

	/**
	 * Initialize and add hooks
	 *
	 * @return void
	 */
	public static function init() {
		$class = self::get_instance();
		add_action( 'woocommerce_api_payuni_notify_card', [ $class, 'card_response' ] );
		add_action( 'woocommerce_api_payuni_notify_atm', [ $class, 'atm_response' ] );
		add_action( 'woocommerce_api_payuni_notify_cvs', [ $class, 'cvs_response' ] );
	}

	/**
	 * Get the single instance or new one if not exists.
	 *
	 * @return Response
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Receive response from Payuni
	 * 好像只有 3D 驗證會進來
	 * 帶有 $resp 是走 3D 驗證的幕後，帶有 $_REQUEST 是結帳頁直接回傳
	 *
	 * @param null $resp payuni response.
	 *
	 * @return void
	 */
	public static function card_response( $resp = null ): void {
		global $woocommerce;

		$encrypt_info = ( $resp ) ? $resp->EncryptInfo : $_REQUEST['EncryptInfo'] ?? null;

		if(!$encrypt_info){
			throw new \Exception("接收不到 payuni 回傳的資料， encrypt_info 為 null");
		}

		$data = Payment::decrypt( $encrypt_info );

		// Payment::decrypt() 在 openssl_decrypt() 失敗時會回 false，經 parse_str() 後成為空陣列。
		// 不擋下來的話：MerTradeNo 為空 → order_id 為 0 → wc_get_order( 0 ) 回 false
		// → 後續 get_class( false ) 直接 fatal。此路徑對外公開（wc-api），必須防呆。
		if ( ! $data || empty( $data['MerTradeNo'] ) ) {
			Payment::log( 'card_response: 解密結果為空或缺少 MerTradeNo，拒絕處理' );
			return;
		}

		unset( $data['Card6No'] ); // remove card number from log.

		$formatted_decrypted_data = self::get_formatted_decrypted_data( $data );
		[
			'status'            => $status,
			'message'           => $message,
			'trade_no'          => $trade_no,
			'card_bank'         => $card_bank,
			'card_bank_name'    => $card_bank_name,
			'card_4no'          => $card_4no,
			'card_hash'         => $card_hash,
			'card_expiry_month' => $card_expiry_month,
			'card_expiry_year'  => $card_expiry_year,
			'card_inst'         => $card_inst,
			'each_amt'          => $each_amt,
			'first_amt'         => $first_amt,
			'is_3d_auth'        => $is_3d_auth,
			'user_id'           => $user_id,
			'order_id'          => $order_id,
		]                         = $formatted_decrypted_data;

		if ( \is_checkout() && function_exists( 'wc_add_notice' ) ) {
			\wc_add_notice( $message, ( 'SUCCESS' === $status ) ? 'success' : 'error' );
		}

		Payment::log( $formatted_decrypted_data );

		// 如果金額是 5 且為 一次授權，就是 hash request，就需要執行5元退刷
		$is_hash_request = '5' === ( $data['TradeAmt'] ?? '' ) && '1' === ( $data['AuthType'] ?? '' );
		if ( $is_hash_request ) {
			// Hash Refund 只執行一次 5 元退款，馬上執行會發生 "訂單處理中，請稍後再試"，所以延遲 1 分鐘再執行.

			\as_schedule_single_action(
				strtotime( '+2 minutes' ),
				'payuni_cancel_trade_by_trade_no',
				[ $data['TradeNo'], $order_id ]
			);
		}

		$order = \wc_get_order( $order_id );

		// 找不到訂單就不能再往下走：下方會呼叫 get_class( $order ) 判斷是否為訂閱，
		// 對 false 呼叫會直接 fatal。
		if ( ! $order instanceof \WC_Order ) {
			Payment::log( "card_response: 找不到訂單 #{$order_id}（MerTradeNo={$data['MerTradeNo']}），拒絕處理" );
			return;
		}

		// 清空購物車
		if ( $woocommerce->cart ) {
			$woocommerce->cart->empty_cart();
		}

		$status_success = ( 'WC_Subscription' === get_class( $order ) ) ? 'active' : 'processing';
		$status_failed  = ( 'WC_Subscription' === get_class( $order ) ) ? 'on-hold' : 'failed';

		if ( 'SUCCESS' !== $status ) {
			$note = '';

			foreach ($formatted_decrypted_data as $key => $value) {
				$note .= "<strong>{$key}</strong>: {$value}<br>";
			}

			$order->add_order_note($note);
			$order->update_status( $status_failed );
			$order->save();
			\wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		$order->payment_complete();

		$order->update_meta_data( '_payuni_order_suffix', (int) $order->get_meta( '_payuni_order_suffix' ) + 1 );
		$order->update_meta_data( '_payuni_resp_status', $status );
		$order->update_meta_data( '_payuni_resp_message', $message );
		$order->update_meta_data( '_payuni_resp_trade_no', $trade_no );
		$order->update_meta_data( '_payuni_resp_card_bank', "({$card_bank}){$card_bank_name}" );
		$order->update_meta_data( '_payuni_card_number', $card_4no );
		$order->update_meta_data( '_payuni_resp_card_inst', $card_inst );
		$order->update_meta_data( '_payuni_resp_first_amt', $first_amt );
		$order->update_meta_data( '_payuni_resp_each_amt', $each_amt );
		$order->add_order_note(
			"<strong>統一金流交易紀錄</strong><br>狀態碼：{$status}<br>交易訊息：{$message}<br>交易編號：{$trade_no}<br>卡號末四碼：{$card_4no}",
			true
		);

		$method = $order->get_payment_method();

		$_payuni_token_maybe_save = $order->get_meta( '_payuni_token_maybe_save' ) === 'yes';
		// 新增付款方式，存入帳號
		if ( ! ! $_payuni_token_maybe_save || $is_hash_request ) {
			self::save_card_to_payment_method( $card_hash, $card_4no, $card_expiry_month, $card_expiry_year, $user_id, $method );
		}
		$order->update_status( $status_success );

		/**
		 * @deprecated 2024-12-12 付款方式可以從  wp_woocommerce_payment_tokens 拿，只要有 user_id 就可以，不需要從上層訂單拿
		 * $order->update_meta_data( '_payuni_card_hash', $card_hash );
		 */

		$token_id = $order->get_meta( '_payuni_token_id' );
		if ( $token_id && is_numeric( $token_id ) ) {
			$token = \WC_Payment_Tokens::get( $token_id );
			if ( $token ) {
				$token->set_default(true);
				$token->save();
			}
		}

		$order->save();

		$no_checkout = $order->get_meta( 'no_checkout' ) === 'yes';

		if ( $is_hash_request && $no_checkout ) {
			\wp_safe_redirect( \wc_get_account_endpoint_url( 'payment-methods' ) );
			exit;
		}

		if ( $is_3d_auth ) {
			\wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}
	}

	/**
	 * Get formatted decrypted data
	 * Response 後、解密後的資料丟進來 format
	 *
	 * @param array $data decrypted data.
	 *
	 * @return array
	 * - status: string
	 * - card_4no: string
	 * - card_hash: string
	 * - card_expiry_month: string
	 * - card_expiry_year: string
	 * - is_3d_auth: bool.
	 */
	public static function get_formatted_decrypted_data( array $data ): array {
		$formatted_data = [];

		$trade_no = $data['MerTradeNo'] ?? '';
		$order_id = (int) explode( '-', $trade_no )[0];
		$order    = \wc_get_order( $order_id );

		$user_id = $order ? $order->get_customer_id() : \get_current_user_id();

		$is_3d_auth = $order ? $order->get_meta( '_payuni_is_3d_auth' ) === 'yes' : key_exists(
				'URL',
				$data
			);

		$formatted_data['status']            = (string) ( $data['Status'] ?? '' );
		$formatted_data['order_id']          = (int) $order_id;
		$formatted_data['user_id']           = (int) $user_id;
		$formatted_data['message']           = (string) ( $data['Message'] ?? '' );
		$formatted_data['trade_no']          = (string) ( $data['TradeNo'] ?? '' );
		$formatted_data['card_bank']         = (string) ( $data['CardBank'] ?? '' );
		$formatted_data['card_bank_name']    = (string) ( $data['AuthBankName'] ?? '' );
		$formatted_data['card_4no']          = (string) ( $data['Card4No'] ?? '' );
		$formatted_data['card_hash']         = (string) ( $data['CreditHash'] ?? '' );
		$formatted_data['card_expiry_month'] = (string) substr( $data['CreditLife'] ?? '', 0, 2 );
		$formatted_data['card_expiry_year']  = (string) '20' . substr( $data['CreditLife'] ?? '', 2, 2 );
		$formatted_data['card_inst']         = ( $data['CardInst'] ?? '' ); // 分期
		$formatted_data['each_amt']          = ( $data['EachAmt'] ?? '' ); // 每次多少
		$formatted_data['first_amt']         = ( $data['FirstAmt'] ?? '' ); // 首次多少
		$formatted_data['is_3d_auth']        = (bool) ( 'SUCCESS' === $formatted_data['status'] && $is_3d_auth ); // 是否 3D 驗證

		return $formatted_data;
	}

	/**
	 * Save card to payment method
	 * 將信用卡資料存入付款方式
	 *
	 * @param string  $card_hash card hash.
	 * @param string  $card_4no card last 4 number.
	 * @param string  $card_expiry_month card expiry month.
	 * @param string  $card_expiry_year card expiry year.
	 * @param int     $user_id user id.
	 * @param ?string $method payment method.
	 *
	 * @return void
	 */
	public static function save_card_to_payment_method(
		string $card_hash,
		string $card_4no,
		string $card_expiry_month,
		string $card_expiry_year,
		int $user_id,
		?string $method = 'payuni-credit-subscription'
	): void {
		if ( ! $card_hash ) {
			return;
		}

		$token = new \WC_Payment_Token_CC();
		$token->set_token( $card_hash );
		$token->set_gateway_id( $method );
		$token->set_card_type( 'visa' );
		$token->set_last4( $card_4no );
		$token->set_expiry_month( $card_expiry_month );
		$token->set_expiry_year( $card_expiry_year );
		$token->set_user_id( $user_id );
		$token->set_default(true);
		$token->save();
	}

	/**
	 * Hash response
	 * 將 5 元扣款 API 的 response 處理
	 * 如果有開 3D 就回要跳轉的 URL $['URL']
	 * 如果沒開 3D 驗證會記錄卡號
	 *
	 * @param ?object $resp payuni response.
	 * @param string  $redirect redirect url.
	 *
	 * @return array
	 */
	public static function handle_hash_response( $resp, $redirect ) {
		//@codingStandardsIgnoreStart
		$encrypt_info = ( $resp ) ? $resp->EncryptInfo : $_REQUEST['EncryptInfo'];
		//@codingStandardsIgnoreEnd

		$data = Payment::decrypt( $encrypt_info );
		/*
		有開 3D 驗證的 response
		["Status"]=> "SUCCESS"
		["Message"]=> "建立幕後3D成功"
		["URL"]=> "https://api.payuni.com.tw/api/credit/api_3d/1711111054055003344"
		*/

		[
			'status'            => $status,
			'card_4no'          => $card_4no,
			'card_hash'         => $card_hash,
			'card_expiry_month' => $card_expiry_month,
			'card_expiry_year'  => $card_expiry_year,
			'is_3d_auth'        => $is_3d_auth,
			'user_id'                => $user_id,
			'order_id' => $order_id,
		] = self::get_formatted_decrypted_data( $data );

		Payment::log( $data );

		if ( 'SUCCESS' !== $status ) {
			if ( \is_checkout() && function_exists( 'wc_add_notice' ) ) {
				\wc_add_notice( $data['Message'], 'error' );
			}

			return [
				'result'     => 'failed',
				'redirect'   => $redirect,
				'is_3d_auth' => false,
			];
		}

		// 3D 驗證走以下判斷，會 redirect 到 $data['URL'] 去做 3D 驗證
		if ( $is_3d_auth && isset( $data['URL'] ) ) {
			return [
				'result'     => 'success',
				'redirect'   => $data['URL'],
				'is_3d_auth' => true,
			];
		}

		self::save_card_to_payment_method( $card_hash, $card_4no, $card_expiry_month, $card_expiry_year, $user_id );

		// 如果金額是 5 且為 一次授權，就需要執行 5 元退刷
		if ( '5' === $data['TradeAmt'] && '1' === $data['AuthType'] ) {
			// Hash Refund 只執行一次 5 元退款，馬上執行會發生 "訂單處理中，請稍後再試"，所以延遲 1 分鐘再執行.
			$action_id = \as_schedule_single_action(
				strtotime( '+2 minutes' ),
				'payuni_cancel_trade_by_trade_no',
				[ $data['TradeNo'], $order_id ]
			);
		}

		return [
			'result'     => 'success',
			'redirect'   => $redirect,
			'is_3d_auth' => false,
		];
	}

	/**
	 * Receive response from Payuni atm payment
	 *
	 * @param object $resp payuni response.
	 *
	 * @return void
	 */
	public static function atm_response( $resp ) {
		// 背景通知付款結果.
		if ( $_REQUEST['Status'] ?? '' ) {
			if ( 'SUCCESS' === $_REQUEST['Status'] ) {
				$data         = Payment::decrypt( $_REQUEST['EncryptInfo'] );
				$time         = date( 'Y-m-d H:i:s', time() );
				$order        = wc_get_order( $data['MerTradeNo'] );
				$order_status = $order->get_status();
				if ( $order_status !== 'completed' ) {
					$order->update_status( 'processing' );
				}
				$order->add_order_note(
					"<strong>統一金流繳費紀錄</strong><br>狀態碼：{$data[ 'Status' ]}<br>繳費結果：{$data[ 'Message' ]}<br>繳費時間：{$data[ 'PayTime' ]}<br>轉帳後五碼：{$data[ 'Account5No' ]}",
					true
				);
				$order->save();
			}
		}

		// 付款完成取號.
		if ( $resp ) {
			global $woocommerce;
			$encrypt_info = $resp->EncryptInfo;
			$data         = Payment::decrypt( $encrypt_info );

			Payment::log( $data );

			$status      = $data['Status'];
			$message     = $data['Message'];
			$trade_no    = $data['TradeNo'];
			$bank        = '(' . $data['BankType'] . ')' . Payment::get_bank_name( $data['BankType'] );
			$bank_no     = $data['PayNo'];
			$bank_expire = date( 'Y-m-d H:i:s', strtotime( $data['ExpireDate'] ) );

			$order = wc_get_order( $data['MerTradeNo'] );
			$order->update_meta_data( '_payuni_resp_status', $status );
			$order->update_meta_data( '_payuni_resp_message', $message );
			$order->update_meta_data( '_payuni_resp_trade_no', $trade_no );
			$order->update_meta_data( '_payuni_resp_bank', $bank );
			$order->update_meta_data( '_payuni_resp_bank_no', $bank_no );
			$order->update_meta_data( '_payuni_resp_bank_expire', $bank_expire );

			$order->add_order_note(
				"<strong>統一金流交易紀錄</strong><br>狀態碼：{$status}<br>交易訊息：{$message}<br>交易編號：{$trade_no}<br>轉帳銀行：{$bank}<br>轉帳帳號：${bank_no}<br>轉帳期限：{$bank_expire}",
				true
			);

			if ( 'SUCCESS' === $status ) {
				$order->update_status( 'pending' );
			} else {
				$order->update_status( 'failed' );
			}

			// 超過繳費期限取消訂單.
			as_schedule_single_action(
				strtotime( $bank_expire . '-8 hour' ),
				'payuni_atm_check',
				[ $data['MerTradeNo'] ]
			);

			$woocommerce->cart->empty_cart();
			$order->save();
		}
	}

	/**
	 * Receive response from Payuni cvs payment
	 *
	 * @param object $resp payuni response.
	 *
	 * @return void
	 */
	public static function cvs_response( $resp ) {
		// 背景通知付款結果.
		if ( $_REQUEST['Status'] ) {
			if ( 'SUCCESS' === $_REQUEST['Status'] ) {
				$data  = Payment::decrypt( $_REQUEST['EncryptInfo'] );
				$time  = date( 'Y-m-d H:i:s', time() );
				$order = wc_get_order( $data['MerTradeNo'] );
				$order->update_status( 'processing' );
				$order->add_order_note(
					"<strong>統一金流繳費紀錄</strong><br>狀態碼：{$data[ 'Status' ]}<br>繳費結果：{$data[ 'Message' ]}<br>繳費時間：{$data[ 'PayTime' ]}<br>轉帳後五碼：{$data[ 'Account5No' ]}",
					true
				);
				$order->save();
			}
		}

		// 付款完成取號.
		if ( $resp ) {
			global $woocommerce;
			$encrypt_info = $resp->EncryptInfo;
			$data         = Payment::decrypt( $encrypt_info );

			Payment::log( $data );

			return;

			$status      = $data['Status'];
			$message     = $data['Message'];
			$trade_no    = $data['TradeNo'];
			$bank        = '(' . $data['BankType'] . ')' . Payment::get_bank_name( $data['BankType'] );
			$bank_no     = $data['PayNo'];
			$bank_expire = date( 'Y-m-d H:i:s', strtotime( $data['ExpireDate'] ) );

			$order = wc_get_order( $data['MerTradeNo'] );
			$order->update_meta_data( '_payuni_resp_status', $status );
			$order->update_meta_data( '_payuni_resp_message', $message );
			$order->update_meta_data( '_payuni_resp_trade_no', $trade_no );
			$order->update_meta_data( '_payuni_resp_bank', $bank );
			$order->update_meta_data( '_payuni_resp_bank_no', $bank_no );
			$order->update_meta_data( '_payuni_resp_bank_expire', $bank_expire );

			$order->add_order_note(
				"<strong>統一金流交易紀錄</strong><br>狀態碼：{$status}<br>交易訊息：{$message}<br>交易編號：{$trade_no}<br>轉帳銀行：{$bank}<br>轉帳帳號：${bank_no}<br>轉帳期限：{$bank_expire}",
				true
			);

			if ( 'SUCCESS' === $status ) {
				$order->update_status( 'on-hold' );
			} else {
				$order->update_status( 'failed' );
			}

			// 超過繳費期限取消訂單.
			as_schedule_single_action(
				strtotime( $bank_expire . '-8 hour' ),
				'payuni_cvs_check',
				[ $data['MerTradeNo'] ]
			);

			$woocommerce->cart->empty_cart();
			$order->save();
		}
	}
}

Response::init();

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
	 * 進入情境：build_request() 內同步呼叫（非 3D，同一個 PHP request）
	 *
	 * @var string
	 */
	public const CONTEXT_SYNC = 'sync';

	/**
	 * 進入情境：ReturnURL，瀏覽器 3D 完成後 form POST 導回
	 *
	 * @var string
	 */
	public const CONTEXT_RETURN = 'return';

	/**
	 * 進入情境：NotifyURL，PayUni 伺服器對伺服器的幕後通知
	 *
	 * @var string
	 */
	public const CONTEXT_NOTIFY = 'notify';

	/**
	 * 付款完成戳記 meta key
	 *
	 * 值為「本張訂單自己的 ID」。woomp_copy_order() 與 WCS 的 wcs_copy_order_meta()
	 * 都會整包複製 order meta，被複製到別張訂單時這個值對不上，天然免疫。
	 *
	 * @var string
	 */
	public const META_PAID_ORDER_ID = '_payuni_paid_order_id';

	/**
	 * 共用驗證 log 的金流代號
	 *
	 * @var string
	 */
	public const PROVIDER = 'payuni';

	/**
	 * 會進到 card_response() 的 V2 信用卡閘道
	 *
	 * @var string[]
	 */
	public const CARD_GATEWAY_IDS = [ 'payuni-credit', 'payuni-credit-installment', 'payuni-credit-subscription' ];

	/**
	 * PayUni TradeStatus：已付款
	 *
	 * 0=取號成功、1=已付款、2=付款失敗、3=付款取消、8=訂單待確認（UPP V2 規格）。
	 *
	 * @var string
	 */
	public const TRADE_STATUS_PAID = '1';

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
		// issue #127：3D 幕後通知（NotifyURL）。與前景導回分成兩個端點，
		// 讓「要不要 redirect」變成端點層級就確定的事實，不必在 handler 內猜來源。
		// 端點名必須全小寫：wc-api 分派器會對 query var 做 strtolower()。
		add_action( 'woocommerce_api_payuni_notify_card_bg', [ $class, 'card_notify' ] );
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
	 * 幕後通知進入點（NotifyURL，PayUni 伺服器對伺服器）
	 *
	 * @return void
	 */
	public static function card_notify(): void {
		self::card_response( null, self::CONTEXT_NOTIFY );
	}

	/**
	 * Receive response from Payuni
	 *
	 * 三種進入情境（見 CONTEXT_* 常數）：
	 * - sync   ：build_request() 內同步呼叫，帶 $resp，非 3D。
	 * - return ：3D 完成後瀏覽器 form POST 到 wc-api/payuni_notify_card，讀 $_REQUEST。
	 * - notify ：PayUni 幕後通知到 wc-api/payuni_notify_card_bg，讀 $_REQUEST。
	 *
	 * 3D 情境下 return 與 notify 幾乎同時抵達，兩者都會進到這裡；
	 * 付款完成的寫入由 complete_paid_order() 統一負責冪等，本方法只做解密、分派與收尾。
	 *
	 * @param null|object $resp    payuni response（同步呼叫時帶入）。
	 * @param string      $context 進入情境；留空時由 $resp 推導，維持既有呼叫相容。
	 *
	 * @return void
	 */
	public static function card_response( $resp = null, string $context = '' ): void {
		global $woocommerce;

		if ( '' === $context ) {
			$context = $resp ? self::CONTEXT_SYNC : self::CONTEXT_RETURN;
		}

		$encrypt_info = ( $resp ) ? $resp->EncryptInfo : $_REQUEST['EncryptInfo'] ?? null;

		if ( ! $encrypt_info ) {
			Payment::log( "card_response[{$context}]: 接收不到 EncryptInfo", 'error' );
			self::finish( $context, null, '' );
			return;
		}

		// 依現有協定註解，PayUni 的回傳一律帶 HashInfo；外部回傳須先驗證再解密。
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 金流商回呼沒有 nonce，以 HashInfo 簽章取代。
		if ( self::CONTEXT_SYNC !== $context ) {
			// 金鑰為空時任何人都能算出 HashInfo，必須 fail-closed。
			if ( ! Payment::has_secrets() ) {
				\Woomp_Payment_Callback_Guard::reject(
					self::PROVIDER,
					'HashKey/HashIV 未設定',
					[
						'handler' => 'card_response',
						'context' => $context,
					]
				);
				self::finish( $context, null, '' );
				return;
			}

			$hash_info = $_REQUEST['HashInfo'] ?? null;
			if ( ! \is_string( $encrypt_info ) || ! \Woomp_Payment_Callback_Guard::signature_equals( Payment::hash_info( (string) $encrypt_info ), $hash_info ) ) {
				Payment::log( "card_response[{$context}]: HashInfo 缺失或驗證失敗，拒絕處理", 'error' );
				self::finish( $context, null, '' );
				return;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$data = Payment::decrypt( (string) $encrypt_info );

		// Payment::decrypt() 在 openssl_decrypt() 失敗時會回 false，經 parse_str() 後成為空陣列。
		// 不擋下來的話：MerTradeNo 為空 → order_id 為 0 → wc_get_order( 0 ) 回 false
		// → 後續 get_class( false ) 直接 fatal。此路徑對外公開（wc-api），必須防呆。
		if ( ! $data || empty( $data['MerTradeNo'] ) ) {
			Payment::log( "card_response[{$context}]: 解密結果為空或缺少 MerTradeNo，拒絕處理", 'error' );
			self::finish( $context, null, '' );
			return;
		}

		unset( $data['Card6No'] ); // remove card number from log.

		$formatted_decrypted_data = self::get_formatted_decrypted_data( $data );
		$status                   = $formatted_decrypted_data['status'];
		$message                  = $formatted_decrypted_data['message'];
		$order_id                 = $formatted_decrypted_data['order_id'];

		// 結帳頁提示只在有顧客 session 的情境才有意義，幕後通知沒有顧客瀏覽器。
		if ( self::CONTEXT_NOTIFY !== $context && \is_checkout() && function_exists( 'wc_add_notice' ) ) {
			\wc_add_notice( $message, ( 'SUCCESS' === $status ) ? 'success' : 'error' );
		}

		Payment::log( [ 'context' => $context ] + $formatted_decrypted_data );

		// 如果金額是 5 且為 一次授權，就是 hash request（綁卡），成功後需要排程 5 元退刷。
		// 排程動作已移入 complete_paid_order()，只有真的完成付款時才排，重複通知不會排第二次。
		$is_hash_request = '5' === ( $data['TradeAmt'] ?? '' ) && '1' === ( $data['AuthType'] ?? '' );

		$order = \wc_get_order( $order_id );

		// 找不到訂單就不能再往下走：下方會呼叫 get_class( $order ) 判斷是否為訂閱，
		// 對 false 呼叫會直接 fatal。
		if ( ! $order instanceof \WC_Order ) {
			Payment::log( "card_response[{$context}]: 找不到訂單 #{$order_id}（MerTradeNo={$data['MerTradeNo']}），拒絕處理", 'error' );
			self::finish( $context, null, '' );
			return;
		}

		// 清空購物車：幕後通知沒有顧客 session，$woocommerce->cart 可能為 null。
		if ( self::CONTEXT_NOTIFY !== $context && $woocommerce->cart ) {
			$woocommerce->cart->empty_cart();
		}

		// 回呼必須屬於這張訂單：付款方式是 V2 信用卡閘道、商店代號一致（失敗通知同樣適用，
		// 否則別張訂單／別的付款方式的失敗密文也能把訂單打成 failed）。
		// sync 情境的資料是本站自己呼叫 API 拿到的回應，不經過瀏覽器、無法被重放；
		// 而且錢已經扣了，在這裡拒絕只會造成掉單，因此只對外部回呼（return / notify）檢查。
		$binding_error = self::CONTEXT_SYNC === $context ? '' : self::get_card_binding_error( $order, $data );
		if ( '' !== $binding_error ) {
			\Woomp_Payment_Callback_Guard::reject(
				self::PROVIDER,
				$binding_error,
				[
					'handler'  => 'card_response',
					'context'  => $context,
					'order_id' => $order->get_id(),
				]
			);
			self::finish( $context, null, '' );
			return;
		}

		if ( 'SUCCESS' !== $status ) {
			self::handle_failed_response( $order, $formatted_decrypted_data, $context );
			self::finish( $context, $order, $order->get_checkout_order_received_url() );
			return;
		}

		$paid_error = self::CONTEXT_SYNC === $context ? '' : self::get_card_paid_error( $order, $data, $is_hash_request );
		if ( '' !== $paid_error ) {
			\Woomp_Payment_Callback_Guard::reject(
				self::PROVIDER,
				$paid_error,
				[
					'handler'  => 'card_response',
					'context'  => $context,
					'order_id' => $order->get_id(),
					'trade_no' => (string) ( $data['TradeNo'] ?? '' ),
				]
			);
			self::finish( $context, null, '' );
			return;
		}

		self::complete_paid_order( $order, $formatted_decrypted_data, $context, $is_hash_request );

		if ( $is_hash_request && 'yes' === $order->get_meta( 'no_checkout' ) ) {
			self::finish( $context, $order, \wc_get_account_endpoint_url( 'payment-methods' ) );
			return;
		}

		self::finish( $context, $order, $order->get_checkout_order_received_url() );
	}

	/**
	 * 本訂單是否已經由 PayUni 成功回應處理過
	 *
	 * 刻意不看 _payuni_resp_status 之類的 meta：woomp_copy_order()（init.php）與 WCS 的
	 * wcs_copy_order_meta() 都會整包複製訂單 meta，被複製出來的新訂單會繼承前一張訂單的
	 * SUCCESS，用 meta 有無判斷會讓新訂單「首次付款就被當成重複通知」而整筆略過——又一次掉單。
	 *
	 * 改看三件不會被複製誤導的事實，依序：
	 * 1. 值為自己 order_id 的戳記（被複製過去時值對不上自己的 ID）。
	 *    放最前面是因為訂單被退款（refunded）或人工改狀態後 is_paid() 會變回 false，
	 *    但這筆交易確實處理過，不可以再處理第二次。
	 * 2. is_paid()（複製出來的訂單一律是 pending）。
	 * 3. WC_Subscription 的成功狀態是 active，不在 wc_get_is_paid_statuses() 內。
	 *
	 * @param \WC_Order $order 訂單物件。
	 *
	 * @return bool
	 */
	public static function is_payuni_completed( \WC_Order $order ): bool {
		if ( (int) $order->get_meta( self::META_PAID_ORDER_ID ) === $order->get_id() ) {
			return true;
		}

		if ( $order->is_paid() ) {
			return true;
		}

		if ( 'WC_Subscription' === \get_class( $order ) && $order->has_status( 'active' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * 解密後的商店代號是否與設定相符
	 *
	 * 回呼內層有帶 MerID 就必須相符；沒帶時不拒絕（同步回應與部分既有通知未必帶），
	 * 因為能解密就代表是用本站金鑰加密的，MerID 只是縱深防禦。
	 *
	 * @param array $data 解密後資料。
	 *
	 * @return bool
	 */
	public static function merchant_matches( array $data ): bool {
		$mer_id = (string) ( $data['MerID'] ?? '' );
		if ( '' === $mer_id ) {
			return true;
		}
		$expected = Payment::get_merchant_id();
		return '' !== $expected && \hash_equals( \strtoupper( \trim( $expected ) ), \strtoupper( \trim( $mer_id ) ) );
	}

	/**
	 * MerTradeNo 是否為本站送出的格式（訂單 ID 或 訂單 ID-流水號），且指向這張訂單
	 *
	 * @param \WC_Order $order        訂單。
	 * @param string    $mer_trade_no 回呼的 MerTradeNo。
	 *
	 * @return bool
	 */
	public static function mer_trade_no_matches( \WC_Order $order, string $mer_trade_no ): bool {
		if ( ! \preg_match( '/^(\d+)(?:-\d+)?$/', $mer_trade_no, $m ) ) {
			return false;
		}
		return (int) $m[1] === $order->get_id();
	}

	/**
	 * card_response() 的訂單綁定檢查（成功與失敗通知都要過）
	 *
	 * @param \WC_Order $order 訂單。
	 * @param array     $data  解密後資料。
	 *
	 * @return string 錯誤原因；空字串表示通過。
	 */
	private static function get_card_binding_error( \WC_Order $order, array $data ): string {
		if ( ! \Woomp_Payment_Callback_Guard::is_payment_method( $order, self::CARD_GATEWAY_IDS ) ) {
			return '訂單付款方式不是 PayUni V2 信用卡';
		}
		if ( ! self::mer_trade_no_matches( $order, (string) ( $data['MerTradeNo'] ?? '' ) ) ) {
			return 'MerTradeNo 與訂單不符';
		}
		if ( ! self::merchant_matches( $data ) ) {
			return 'MerID 與設定不符';
		}
		return '';
	}

	/**
	 * card_response() 成功通知的金額與狀態檢查
	 *
	 * @param \WC_Order $order           訂單。
	 * @param array     $data            解密後資料。
	 * @param bool      $is_hash_request 是否為 5 元綁卡請求。
	 *
	 * @return string 錯誤原因；空字串表示通過。
	 */
	private static function get_card_paid_error( \WC_Order $order, array $data, bool $is_hash_request ): string {
		$trade_amt = $data['TradeAmt'] ?? null;

		// 5 元綁卡：新增付款方式的訂單金額就是 5；定期定額零元訂單送出的也是 5 元。
		$amount_ok = \Woomp_Payment_Callback_Guard::amount_matches( $order, $trade_amt )
			|| ( $is_hash_request && \abs( (float) $order->get_total() ) < 0.01 );
		if ( ! $amount_ok ) {
			return '金額與訂單不符';
		}

		// 已處理過的交易交給 complete_paid_order() 的冪等邏輯（留下「重複通知」備註）。
		if ( self::is_payuni_completed( $order ) ) {
			return '';
		}

		// 新增付款方式（我的帳號）建立的暫存綁卡訂單在送出後就被移到垃圾桶，3D 通知回來時狀態是 trash。
		// 這類訂單沒有商品、金額固定 5 元，完成付款只會存卡與排 5 元退刷，故不套用狀態限制。
		if ( $is_hash_request && 'yes' === $order->get_meta( 'no_checkout' ) ) {
			return '';
		}

		if ( ! \Woomp_Payment_Callback_Guard::can_mark_paid( $order ) ) {
			return "訂單狀態 {$order->get_status()} 不可被標成已付款";
		}

		return '';
	}

	/**
	 * 依 MerTradeNo 找訂單（ID 或 ID-流水號）
	 *
	 * @param string $mer_trade_no MerTradeNo。
	 *
	 * @return \WC_Order|null
	 */
	private static function find_order_by_mer_trade_no( string $mer_trade_no ): ?\WC_Order {
		if ( ! \preg_match( '/^(\d+)(?:-\d+)?$/', $mer_trade_no, $m ) ) {
			return null;
		}
		$order = \wc_get_order( (int) $m[1] );
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * 付款成功的唯一寫入路徑
	 *
	 * card_response()（同步 / ReturnURL / NotifyURL）、補償對帳器、CREDIT04001 反查三處共用，
	 * 確保任何管道補完的訂單都寫入完全相同的 V2 meta 集合，且所有寫入都在冪等保護內。
	 *
	 * 兩道防線：
	 * 1. is_payuni_completed() 早退——大多數重複通知在這裡結束，不必碰 DB 鎖。
	 * 2. PaidLock 跨行程互斥——擋「幕後通知與瀏覽器導回幾乎同時抵達」的窄窗，
	 *    兩個 PHP worker 都通過第一道時只有一個能往下走。
	 *
	 * @param \WC_Order $order           訂單物件。
	 * @param array     $formatted       get_formatted_decrypted_data() 的輸出。
	 * @param string    $source          來源標記，寫進訂單備註與 log 供追查。
	 * @param bool      $is_hash_request 是否為 5 元綁卡請求。
	 *
	 * @return bool 真的執行了付款完成為 true；被冪等擋下為 false。
	 */
	public static function complete_paid_order( \WC_Order $order, array $formatted, string $source = 'response', bool $is_hash_request = false ): bool {
		$trade_no = (string) ( $formatted['trade_no'] ?? '' );

		if ( self::is_payuni_completed( $order ) ) {
			$order->add_order_note( "統一金流重複通知（來源：{$source}，交易編號：{$trade_no}），訂單已完成，已略過。" );
			Payment::log( "complete_paid_order[{$source}]: #{$order->get_id()} 已完成，略過重複通知" );
			return false;
		}

		$lock_key = (string) ( $order->get_meta( '_payuni_mer_trade_no' ) ?: $order->get_id() );

		if ( ! PaidLock::acquire( $lock_key ) ) {
			Payment::log( "complete_paid_order[{$source}]: #{$order->get_id()} 另一個行程正在處理，略過" );
			return false;
		}

		try {
			// 取得鎖之後重讀訂單再檢查一次：等鎖的期間對方可能已經寫完了。
			$fresh = \wc_get_order( $order->get_id() );
			if ( $fresh instanceof \WC_Order ) {
				if ( self::is_payuni_completed( $fresh ) ) {
					Payment::log( "complete_paid_order[{$source}]: #{$order->get_id()} 取鎖後發現已完成，略過" );
					return false;
				}
				$order = $fresh;
			}

			$status            = (string) ( $formatted['status'] ?? '' );
			$message           = (string) ( $formatted['message'] ?? '' );
			$card_bank         = (string) ( $formatted['card_bank'] ?? '' );
			$card_bank_name    = (string) ( $formatted['card_bank_name'] ?? '' );
			$card_4no          = (string) ( $formatted['card_4no'] ?? '' );
			$card_hash         = (string) ( $formatted['card_hash'] ?? '' );
			$card_expiry_month = (string) ( $formatted['card_expiry_month'] ?? '' );
			$card_expiry_year  = (string) ( $formatted['card_expiry_year'] ?? '' );
			$card_inst         = $formatted['card_inst'] ?? '';
			$each_amt          = $formatted['each_amt'] ?? '';
			$first_amt         = $formatted['first_amt'] ?? '';
			$user_id           = (int) ( $formatted['user_id'] ?? 0 );

			$status_success = ( 'WC_Subscription' === \get_class( $order ) ) ? 'active' : 'processing';

			$order->payment_complete( $trade_no );

			// 付款完成戳記：值放自己的 order_id，見 is_payuni_completed() 的說明。
			$order->update_meta_data( self::META_PAID_ORDER_ID, $order->get_id() );

			// _payuni_order_suffix 不在這裡遞增（issue #131）。
			// 商店訂單編號是「送出即用掉」，付款成功與否都不能再重複使用，
			// 因此遞增已移到送出當下（Request::burn_order_suffix()）。
			// 在這裡再加一次會讓序號每筆成功交易跳兩號，日後以尾碼推導 MerTradeNo 反查交易時會對不上；
			// 補償對帳器與 CREDIT04001 反查一律讀送出時落地的 _payuni_mer_trade_no，不從當下尾碼推導。

			$order->update_meta_data( '_payuni_resp_status', $status );
			$order->update_meta_data( '_payuni_resp_message', $message );
			$order->update_meta_data( '_payuni_resp_trade_no', $trade_no );
			$order->update_meta_data( '_payuni_resp_card_bank', "({$card_bank}){$card_bank_name}" );
			$order->update_meta_data( '_payuni_card_number', $card_4no );
			$order->update_meta_data( '_payuni_resp_card_inst', $card_inst );
			$order->update_meta_data( '_payuni_resp_first_amt', $first_amt );
			$order->update_meta_data( '_payuni_resp_each_amt', $each_amt );
			$order->add_order_note(
				"<strong>統一金流交易紀錄</strong><br>來源：{$source}<br>狀態碼：{$status}<br>交易訊息：{$message}<br>交易編號：{$trade_no}<br>卡號末四碼：{$card_4no}",
				true
			);

			$should_save_card = 'yes' === $order->get_meta( '_payuni_token_maybe_save' ) || $is_hash_request;

			if ( $should_save_card ) {
				if ( '' !== $card_hash ) {
					self::save_card_to_payment_method( $card_hash, $card_4no, $card_expiry_month, $card_expiry_year, $user_id, $order->get_payment_method() );
				} else {
					// 補償對帳走的 /trade/query 不回傳 CreditHash，記憶卡號無法補建。
					// 過去這種情況完全無聲，顧客以為卡存好了，下次結帳才發現沒有。
					$order->update_meta_data( '_payuni_token_bind_failed', 'yes' );
					$order->add_order_note(
						'<strong>⚠ 記憶卡號未建立</strong><br>顧客勾選了儲存付款資訊，但本次回應未帶 CreditHash'
						. "（來源：{$source}），信用卡 Token 並未建立，請提醒顧客下次結帳重新輸入卡號。"
					);
				}
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
					$token->set_default( true );
					$token->save();
				}
			}

			$order->save();

			// 5 元綁卡退刷：只有真的完成付款時才排；馬上執行會發生「訂單處理中，請稍後再試」，故延遲 2 分鐘。
			if ( $is_hash_request && '' !== $trade_no && \function_exists( 'as_schedule_single_action' ) ) {
				\as_schedule_single_action(
					strtotime( '+2 minutes' ),
					'payuni_cancel_trade_by_trade_no',
					[ $trade_no, $order->get_id() ]
				);
			}

			return true;
		} finally {
			PaidLock::release( $lock_key );
		}
	}

	/**
	 * 處理失敗回應
	 *
	 * @param \WC_Order $order     訂單物件。
	 * @param array     $formatted 格式化後的回應。
	 * @param string    $context   進入情境。
	 *
	 * @return void
	 */
	private static function handle_failed_response( \WC_Order $order, array $formatted, string $context ): void {
		// 失敗備註一律走 format_failure_note()：它會排除 card_hash（CreditHash 可直接拿來續扣，
		// 屬於憑證而非診斷資訊），且與 API 階段（Request::build_request()）的備註格式一致。
		$order->add_order_note( self::format_failure_note( $formatted ) );

		// 已完成付款的訂單絕不因為一則遲到／重送的失敗通知而被改成 failed。
		// 這是 NotifyURL 上線後才出現的新風險：PayUni 在未收到 "1" 時會重送。
		if ( self::is_payuni_completed( $order ) ) {
			$order->add_order_note( "訂單已付款完成，忽略此失敗通知（來源：{$context}）的狀態變更。" );
			$order->save();
			return;
		}

		$order->update_status( ( 'WC_Subscription' === \get_class( $order ) ) ? 'on-hold' : 'failed' );
		$order->save();
	}

	/**
	 * 依進入情境收尾
	 *
	 * - sync   ：build_request() 內同步呼叫，絕對不能 exit，否則整個結帳流程被截斷。
	 * - return ：瀏覽器 form POST 回來，導向訂單完成頁。
	 * - notify ：PayUni 伺服器對伺服器，只認 HTTP 200 / 回應 "1"。
	 *            回 302 會被判定為通知失敗而重送——這正是要避免的。
	 *
	 * @param string          $context  進入情境。
	 * @param \WC_Order|null  $order    訂單物件（解密失敗等情境為 null）。
	 * @param string          $redirect 導向網址。
	 *
	 * @return void
	 */
	private static function finish( string $context, ?\WC_Order $order, string $redirect ): void {
		/**
		 * 在真正結束前開一個口，供測試攔截與站方接自訂記錄。production 預設沒有任何 callback。
		 *
		 * @param string         $context  進入情境。
		 * @param \WC_Order|null $order    訂單物件。
		 * @param string         $redirect 導向網址。
		 */
		\do_action( 'woomp_payuni_v2_card_response_finish', $context, $order, $redirect );

		if ( self::CONTEXT_SYNC === $context ) {
			return;
		}

		if ( self::CONTEXT_NOTIFY === $context ) {
			// WooCommerce 的 wc-api 分派器把 handler 包在 ob_start() 裡，結束後 ob_end_clean() 並 die('-1')。
			// 必須自己 exit 才送得出 "1"（exit 時 PHP 會 flush output buffer）。
			// @see woocommerce/src/Internal/Utilities/LegacyRestApiStub.php::maybe_process_wc_api_query_var()
			echo '1';
			exit;
		}

		if ( '' !== $redirect ) {
			\wp_safe_redirect( $redirect );
			exit;
		}
	}

	/**
	 * 組裝交易失敗的訂單備註。
	 *
	 * 由 API 階段（Request::build_request()）與授權階段（self::card_response()）共用，
	 * 兩個階段的失敗備註格式因此一致。
	 *
	 * card_hash 會被排除：那是統一金流回傳的 CreditHash，可直接拿來續扣，
	 * 屬於憑證而非診斷資訊，不應留在後台任何人都看得到的訂單備註裡。
	 *
	 * @param array $formatted_decrypted_data self::get_formatted_decrypted_data() 的輸出。
	 *
	 * @return string
	 */
	public static function format_failure_note( array $formatted_decrypted_data ): string {
		unset( $formatted_decrypted_data['card_hash'] );

		$note = '<strong>統一金流交易失敗</strong><br>';

		foreach ( $formatted_decrypted_data as $key => $value ) {
			$note .= "<strong>{$key}</strong>: {$value}<br>";
		}

		return $note;
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
	 * 兩種進入方式：
	 * - wc-api/payuni_notify_atm：PayUni 背景通知繳費結果（公開網址，任何人都能 POST）。
	 * - Request::set_response()：本站呼叫 api/atm 取號後，以 API 回應物件同步呼叫。
	 *
	 * @param mixed $resp 取號時帶入的 API 回應物件；wc-api 進入時為空。
	 *
	 * @return void
	 */
	public static function atm_response( $resp = null ) {
		if ( \is_object( $resp ) ) {
			self::atm_issue_response( $resp );
			return;
		}

		self::handle_offline_paid_notify( 'payuni-atm', '2', 'atm_response' );
	}

	/**
	 * ATM 取號結果（本站呼叫 api/atm 的同步回應）
	 *
	 * @param object $resp API 回應物件。
	 *
	 * @return void
	 */
	private static function atm_issue_response( $resp ): void {
		global $woocommerce;

		$data = Payment::decrypt( (string) ( $resp->EncryptInfo ?? '' ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		Payment::log( $data );

		$order = $data ? self::find_order_by_mer_trade_no( (string) ( $data['MerTradeNo'] ?? '' ) ) : null;
		if ( ! $order || ! \Woomp_Payment_Callback_Guard::is_payment_method( $order, 'payuni-atm' ) ) {
			Payment::log( 'atm_response: 取號回應解密失敗、找不到訂單或付款方式不符', 'error' );
			return;
		}

		$status      = (string) ( $data['Status'] ?? '' );
		$message     = (string) ( $data['Message'] ?? '' );
		$trade_no    = (string) ( $data['TradeNo'] ?? '' );
		$bank_type   = (string) ( $data['BankType'] ?? '' );
		$bank        = '(' . $bank_type . ')' . Payment::get_bank_name( $bank_type );
		$bank_no     = (string) ( $data['PayNo'] ?? '' );
		$bank_expire = date( 'Y-m-d H:i:s', (int) strtotime( (string) ( $data['ExpireDate'] ?? '' ) ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- 沿用既有行為。

		$order->update_meta_data( '_payuni_resp_status', $status );
		$order->update_meta_data( '_payuni_resp_message', $message );
		$order->update_meta_data( '_payuni_resp_trade_no', $trade_no );
		$order->update_meta_data( '_payuni_resp_bank', $bank );
		$order->update_meta_data( '_payuni_resp_bank_no', $bank_no );
		$order->update_meta_data( '_payuni_resp_bank_expire', $bank_expire );

		$order->add_order_note(
			'<strong>統一金流交易紀錄</strong><br>狀態碼：' . \esc_html( $status )
			. '<br>交易訊息：' . \esc_html( $message )
			. '<br>交易編號：' . \esc_html( $trade_no )
			. '<br>轉帳銀行：' . \esc_html( $bank )
			. '<br>轉帳帳號：' . \esc_html( $bank_no )
			. '<br>轉帳期限：' . \esc_html( $bank_expire ),
			true
		);

		if ( 'SUCCESS' === $status ) {
			$order->update_status( 'pending' );
		} else {
			$order->update_status( 'failed' );
		}

		// 超過繳費期限取消訂單.
		if ( \function_exists( 'as_schedule_single_action' ) ) {
			\as_schedule_single_action(
				strtotime( $bank_expire . '-8 hour' ),
				'payuni_atm_check',
				[ $data['MerTradeNo'] ]
			);
		}

		if ( $woocommerce && $woocommerce->cart ) {
			$woocommerce->cart->empty_cart();
		}
		$order->save();
	}

	/**
	 * Receive response from Payuni cvs payment
	 *
	 * 取號的同步回應原本就只寫 log（後段為停用的舊程式），維持不動；
	 * 背景繳費通知改走與 ATM 相同的驗證流程。
	 *
	 * @param mixed $resp 取號時帶入的 API 回應物件；wc-api 進入時為空。
	 *
	 * @return void
	 */
	public static function cvs_response( $resp = null ) {
		if ( \is_object( $resp ) ) {
			Payment::log( Payment::decrypt( (string) ( $resp->EncryptInfo ?? '' ) ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return;
		}

		self::handle_offline_paid_notify( 'payuni-cvs', '3', 'cvs_response' );
	}

	/**
	 * ATM／超商代碼的背景繳費通知
	 *
	 * 過去只看外層未加密的 $_REQUEST['Status']，不驗 HashInfo、不看解密後狀態，
	 * 拿任何一段本站金鑰加密過的 EncryptInfo（例如 3D 失敗時瀏覽器看得到的那段）就能把訂單改成處理中。
	 * 改為依序檢查：金鑰 → HashInfo → 解密 → 內層 Status／TradeStatus → 商店代號 → 訂單綁定 → 金額 → 訂單狀態。
	 *
	 * @param string $gateway_id   訂單必須使用的付款方式。
	 * @param string $payment_type 內層 PaymentType（有帶時必須相符）：2=ATM、3=超商代碼。
	 * @param string $handler      記錄用名稱。
	 *
	 * @return bool 是否把訂單標成已付款。
	 */
	public static function handle_offline_paid_notify( string $gateway_id, string $payment_type, string $handler ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 金流商回呼沒有 nonce，以 HashInfo 簽章取代；hex 字串不可被 sanitize 改寫。
		$encrypt_info = isset( $_REQUEST['EncryptInfo'] ) && \is_string( $_REQUEST['EncryptInfo'] ) ? \trim( \wp_unslash( $_REQUEST['EncryptInfo'] ) ) : '';
		$hash_info    = isset( $_REQUEST['HashInfo'] ) && \is_string( $_REQUEST['HashInfo'] ) ? \trim( \wp_unslash( $_REQUEST['HashInfo'] ) ) : '';
		$outer_mer_id = isset( $_REQUEST['MerID'] ) && \is_string( $_REQUEST['MerID'] ) ? \sanitize_text_field( \wp_unslash( $_REQUEST['MerID'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$reject = static function ( string $reason, array $context = [] ) use ( $handler ): bool {
			\Woomp_Payment_Callback_Guard::reject( self::PROVIDER, $reason, [ 'handler' => $handler ] + $context );
			Payment::log( "{$handler}: {$reason}", 'error' );
			return false;
		};

		if ( ! Payment::has_secrets() ) {
			return $reject( 'HashKey/HashIV 未設定' );
		}

		if ( '' === $encrypt_info || ! \Woomp_Payment_Callback_Guard::signature_equals( Payment::hash_info( $encrypt_info ), $hash_info ) ) {
			return $reject( 'HashInfo 缺失或驗證失敗' );
		}

		$data = Payment::decrypt( $encrypt_info );
		if ( ! $data ) {
			return $reject( 'EncryptInfo 解密失敗' );
		}

		Payment::log( [ 'handler' => $handler ] + $data );

		$mer_trade_no = (string) ( $data['MerTradeNo'] ?? '' );
		$trade_no     = (string) ( $data['TradeNo'] ?? '' );
		$log_context  = [
			'mer_trade_no' => $mer_trade_no,
			'trade_no'     => $trade_no,
		];

		// 只信內層（有簽章保護）的狀態；外層 Status 沒有任何保護。
		if ( 'SUCCESS' !== (string) ( $data['Status'] ?? '' ) ) {
			return $reject( '內層 Status 不是 SUCCESS', $log_context + [ 'status' => (string) ( $data['Status'] ?? '' ) ] );
		}
		if ( self::TRADE_STATUS_PAID !== (string) ( $data['TradeStatus'] ?? '' ) ) {
			return $reject( 'TradeStatus 不是已付款', $log_context + [ 'trade_status' => (string) ( $data['TradeStatus'] ?? '' ) ] );
		}

		$inner_payment_type = (string) ( $data['PaymentType'] ?? '' );
		if ( '' !== $inner_payment_type && $payment_type !== $inner_payment_type ) {
			return $reject( 'PaymentType 不符', $log_context + [ 'payment_type' => $inner_payment_type ] );
		}

		if ( ! self::merchant_matches( $data ) || ( '' !== $outer_mer_id && ! self::merchant_matches( [ 'MerID' => $outer_mer_id ] ) ) ) {
			return $reject( 'MerID 與設定不符', $log_context );
		}

		$order = self::find_order_by_mer_trade_no( $mer_trade_no );
		if ( ! $order ) {
			return $reject( '找不到訂單', $log_context );
		}
		$log_context['order_id'] = $order->get_id();

		if ( ! \Woomp_Payment_Callback_Guard::is_payment_method( $order, $gateway_id ) ) {
			return $reject( "訂單付款方式不是 {$gateway_id}", $log_context );
		}

		if ( ! \Woomp_Payment_Callback_Guard::amount_matches( $order, $data['TradeAmt'] ?? null ) ) {
			return $reject( '金額與訂單不符', $log_context + [ 'trade_amt' => (string) ( $data['TradeAmt'] ?? '' ) ] );
		}

		// 冪等：戳記值為自己的訂單 ID（被 woomp_copy_order／WCS 複製到別張訂單時對不上）。
		if ( (int) $order->get_meta( self::META_PAID_ORDER_ID ) === $order->get_id() ) {
			Payment::log( "{$handler}: #{$order->get_id()} 已處理過，略過重複通知" );
			return false;
		}

		if ( ! \Woomp_Payment_Callback_Guard::can_mark_paid( $order ) ) {
			return $reject( "訂單狀態 {$order->get_status()} 不可被標成已付款", $log_context );
		}

		$order->payment_complete( $trade_no );
		$order->update_meta_data( self::META_PAID_ORDER_ID, $order->get_id() );
		$order->add_order_note(
			'<strong>統一金流繳費紀錄</strong><br>狀態碼：' . \esc_html( (string) ( $data['Status'] ?? '' ) )
			. '<br>繳費結果：' . \esc_html( (string) ( $data['Message'] ?? '' ) )
			. '<br>繳費時間：' . \esc_html( (string) ( $data['PayTime'] ?? '' ) )
			. '<br>轉帳後五碼：' . \esc_html( (string) ( $data['Account5No'] ?? '' ) ),
			true
		);
		$order->save();

		return true;
	}
}

Response::init();

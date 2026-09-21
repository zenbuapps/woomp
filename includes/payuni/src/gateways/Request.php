<?php
/**
 * Payuni_Payment_Request class file
 *
 * @package payuni
 */

namespace PAYUNI\Gateways;

use WC_Order;
use PAYUNI\APIs\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * Generates payment form and redirect to payuni
 */
final class Request {


	/**
	 * The gateway instance
	 *
	 * @var WC_Payment_Gateway
	 */
	protected $gateway;

	/**
	 * 是否為信用卡付款
	 *
	 * @var bool
	 */
	protected $is_credit;

	/**
	 * Constructor
	 *
	 * @param WC_Payment_Gateway $gateway The payment gateway instance.
	 */
	public function __construct( $gateway ) {
		$this->gateway   = $gateway;
		$gateway_id      = $gateway->id;
		$this->is_credit = in_array($gateway_id, [ 'payuni-credit', 'payuni-credit-installment', 'payuni-credit-subscription' ]);
	}

	/**
	 * 信用卡號付款請求
	 * 訂閱的第一次付款其實也是走這邊
	 *
	 * @param \WC_Order                                                                     $order The order object.
	 * @param ?array{number:string, expiry:string, cvc:string, token_id:string, new:string} $card_data 卡片資料
	 *
	 * @return array{result:string, redirect:string, status_code:string, order_id:int}
	 */
	public function build_request( WC_Order $order, $card_data = null ): array {

		$body_params = $this->get_transaction_args( $order, $card_data );
		$order_id    = $order->get_id();
		$options     = [
			'method'     => 'POST',
			'timeout'    => 60,
			'body'       => $body_params,
			'user-agent' => 'payuni',
		];

		$response = \wp_remote_request(
			$this->gateway->get_api_url() . $this->gateway->get_api_endpoint_url(),
			$options
		);

		$resp = json_decode( \wp_remote_retrieve_body( $response ) );

		//@codingStandardsIgnoreStart
		$data = \Payuni\APIs\Payment::decrypt( $resp->EncryptInfo );
		//@codingStandardsIgnoreEnd

		/*
		有開 3D 驗證的 response
		["Status"]=> "SUCCESS"
		["Message"]=> "建立幕後3D成功"
		["URL"]=> "https://api.payuni.com.tw/api/credit/api_3d/1711111054055003344"
		*/

		$formatted_decrypted_data = Response::get_formatted_decrypted_data( $data );

		[
				'status'            => $status,
				'card_4no'          => $card_4no,
				'card_hash'         => $card_hash,
				'card_expiry_month' => $card_expiry_month,
				'card_expiry_year'  => $card_expiry_year,
				'is_3d_auth'        => $is_3d_auth,
			] = $formatted_decrypted_data;

		Payment::log( $data );

		// 結帳頁顯示錯誤訊息.
		if ( 'SUCCESS' !== $status ) {
			// 統一金流在 API 階段就同步回錯誤時不會進到 Response::card_response()，這裡是唯一拿得到回應內容的地方。
			// 不留存的話後台只剩一張沒有任何線索的待付款單，錯誤碼、發卡行、卡號末四碼全部遺失，
			// 事後追查只能回頭問顧客。備註格式與 Response::card_response() 的失敗備註共用同一個組裝函式。
			$order->add_order_note( Response::format_failure_note( $formatted_decrypted_data ) );

			if ( \is_checkout() && ! \in_array( $data['Status'], [ 'CREDIT04001', 'ATM04001' ] ) ) {
				// "已存在相同商店訂單編號" 已經用新建訂單解決，不用顯示錯誤
				\wc_add_notice( $data['Message'], 'error' );
			}

			return [
				'result'      => 'failed',
				'redirect'    => $this->gateway->get_return_url( $order ),
				'status_code' => $data['Status'],
				'order_id'    => $order_id,
			];
		}

		if ($this->is_credit) {
			unset( $data['Card6No'] ); // remove card number from log.

			// 3D 驗證走以下判斷，會 redirect 到 $data['URL'] 去做 3D 驗證
			if ( $is_3d_auth ) {
				// issue #127：3D 訂單的最終狀態完全依賴 PayUni 的通知。通知遲到或漏收時，
				// 訂單會停在 pending 直到被逾期取消排程清掉，但錢已經收了。排程主動查詢作為第二層保險。
				PendingReconciler::schedule( $order );

				return [
					'result'      => 'success',
					'redirect'    => $data['URL'],
					'status_code' => $data['Status'],
					'order_id'    => $order_id,
				];
			}
		}

		$this->set_response( $order->get_payment_method(), $resp );

		return [
			'result'      => 'success',
			'redirect'    => $this->gateway->get_return_url( $order ),
			'status_code' => $data['Status'],
			'order_id'    => $order_id,
		];
	}

	/**
	 * 取用一組沒被用過的 MerTradeNo 尾碼，取完立即遞增並存檔。
	 *
	 * 統一金流的商店訂單編號「10 分鐘內不可重複」（官方 UPP v2 規格，長度上限 25、
	 * 字元限 [A-Za-z0-9_-]）。授權失敗、逾時、或顧客在 3D 驗證頁中斷，都已經把該編號用掉，
	 * 沿用同一組重送會被回 CREDIT04001「已存在相同商店訂單編號」，
	 * 顧客在結帳頁重按送出、或從「再次嘗試付款」重試同一張訂單就永遠付不了。
	 *
	 * 原本 _payuni_order_suffix 只在付款成功後才 +1（Response::card_response()），
	 * 也就是只在「不再需要它」的時候才遞增，在「需要它」的失敗情境永遠不動，等於從未生效。
	 * 改為送出即取號：不論後續成功或失敗，這組編號都不會再被用第二次。
	 *
	 * 第一次仍是裸編號（例如 34916），第二次才變 34916-1，統一金流後台既有的編號格式不受影響；
	 * 回呼端本來就以 explode('-')[0] 還原訂單 ID，故加尾碼不影響對帳。
	 *
	 * @see Response::get_formatted_decrypted_data()
	 *
	 * @param \WC_Order $order The order object.
	 *
	 * @return string 空字串或 '-{n}'。
	 */
	private static function burn_order_suffix( WC_Order $order ): string {
		$suffix_seq = (int) $order->get_meta( '_payuni_order_suffix' );

		$order->update_meta_data( '_payuni_order_suffix', $suffix_seq + 1 );
		$order->save();

		return $suffix_seq ? '-' . $suffix_seq : '';
	}

	/**
	 * Build transaction args.
	 *
	 * @see https://www.payuni.com.tw/docs/web/#/7/35
	 *
	 * @param \WC_Order                                                                     $order The order object.
	 * @param ?array{number:string, expiry:string, cvc:string, token_id:string, new:string} $card_data 卡片資料
	 *
	 * @return array{MerID:string, Version:string, EncryptInfo:string, HashInfo:string}
	 */
	public function get_transaction_args( WC_Order $order, ?array $card_data ): array {
		$order_suffix = self::burn_order_suffix( $order );

		$args = [
			'MerID'      => $this->gateway->get_mer_id(),
			'MerTradeNo' => $order->get_id() . $order_suffix,
			'TradeAmt'   => $order->get_total(),
			'Timestamp'  => time(),
			'UsrMail'    => $order->get_billing_email(),
			'ProdDesc'   => $this->get_product_name( $order ),
		];

		if ( $card_data && $this->is_credit ) {
			$token_id      = $card_data['token_id'] ?? ''; // 可能是 數字、new
			$save_new_card = $card_data['new'] ?? 'no';
			if ('payuni-credit-subscription' === $this->gateway->id && !\is_numeric($token_id)) {
				// 如果是訂閱收款且沒有帶 token_id (使用已存的卡號付款)，就一定會存卡號
				$save_new_card = 'yes';
			}

			// 不判斷 token_id 直接傳卡號
			$args['CardNo']      = $card_data['number'];
			$args['CardExpired'] = $card_data['expiry'];
			$args['CardCVC']     = $card_data['cvc'];

			// 只有要新增卡號才需要傳 CreditToken
			// TODO 這邊要再確認一下，因為在 subscription 那邊，都固定有傳，所以猜想，如果使用已存 token 付款也要傳
			if ( $save_new_card || \is_numeric($token_id) ) {
				$args['CreditToken'] = $order->get_billing_email(); // CreditToken 就是傳給金流公司，用來取得 token (CreditHash) 的憑證，這邊以客戶的 email 作為識別
			}

			// 信用卡分期數
			if ( !empty($card_data['period'])) {
				$args['CardInst'] = $card_data['period'];
			}

			// 判斷是否為使用 token (CreditHash) 付款
			if ( \is_numeric($token_id)) {
				$token              = \WC_Payment_Tokens::get( $token_id );
				$args['CreditHash'] = $token?->get_token();

				// 如果有 CreditHash 就不需要再傳卡號、有效期、末三碼
				unset( $args['CardNo'] );
				unset( $args['CardExpired'] );
				unset( $args['CardCVC'] );
			}

			// 是否開啟 3D 驗證
			if ($this->is_credit && \wc_string_to_bool( \get_option( 'payuni_3d_auth', 'yes' ) ) ) {
				$args['API3D'] = 1;

				// ReturnURL：3D 完成後由「瀏覽器」form POST 回來，顧客關掉分頁就永遠不會到。
				// NotifyURL：由 PayUni「伺服器對伺服器」送出，不受顧客行為影響，是交易結果的唯一可靠來源。
				// issue #127：過去只送 ReturnURL，顧客付完款關閉分頁 → 網站永遠收不到結果 →
				// 訂單停在 pending → 被逾期取消排程清掉，但錢已經收了。
				// 兩者刻意指向不同端點：幕後通知要回 "1"、瀏覽器導回要 redirect，
				// 在端點層級就分開，handler 不必猜來源。
				$args['ReturnURL'] = \home_url( 'wc-api/payuni_notify_card' );

				/**
				 * 緊急關閉開關：上線初期若 PayUni 對 NotifyURL 的處理有非預期行為，可用此 filter 停送。
				 * 停送後回到「只靠瀏覽器導回」的舊行為，掉單風險回升但不會有重複通知的問題。
				 *
				 * @param bool      $enabled 預設 true。
				 * @param \WC_Order $order   訂單物件。
				 */
				if ( \apply_filters( 'woomp_payuni_v2_enable_notify_url', true, $order ) ) {
					$args['NotifyURL'] = \home_url( 'wc-api/payuni_notify_card_bg' );
				}

				$order->update_meta_data( '_payuni_is_3d_auth', 'yes' );
			}

			// 儲存 meta 資料在 order 上
			$order->update_meta_data( '_payuni_token_id', $token_id );

			$order->update_meta_data( '_payuni_token_maybe_save', $save_new_card ); // □ 儲存付款資訊，下次付款更方便的 checkbox

			// 送出時實際使用的 MerTradeNo 落地保存，供日後以商店訂單編號向統一金流反查交易狀態。
			// 不在反查時重新推導：_payuni_order_suffix 是「送出即取號」（burn_order_suffix()），
			// 每送出一次就 +1，屆時推導出的編號會與當初送出的不同，查不到這筆交易。
			$order->update_meta_data( '_payuni_mer_trade_no', $args['MerTradeNo'] );

			$order->save();
		}

		$args = apply_filters(
			'payuni_transaction_args_' . $this->gateway->id,
			$args,
			$order,
			$card_data
		);

		$parameter                = [];
		$parameter['MerID']       = $this->gateway->get_mer_id();
		$parameter['Version']     = '1.0';
		$parameter['EncryptInfo'] = \Payuni\APIs\Payment::encrypt( $args );
		$parameter['HashInfo']    = \Payuni\APIs\Payment::hash_info( $parameter['EncryptInfo'] );

		return $parameter;
	}

	/**
	 * Get product name
	 *
	 * @param \WC_Order $order The order object.
	 */
	public function get_product_name( $order ) {
		$items = $order->get_items();
		if ( ! empty( $items ) ) {
			foreach ( $items as $item ) {
				return $item->get_name();
			}
		}

		return '商品名稱';
	}

	private function set_response( $payment_method, $resp ) {
		switch ( $payment_method ) {
			case 'payuni-credit':
			case 'payuni-credit-installment':
			case 'payuni-credit-subscription':
				Response::card_response( $resp );
				break;
			case 'payuni-atm':
				Response::atm_response( $resp );
				break;
			case 'payuni-cvs':
				Response::cvs_response( $resp );
				break;
			default:
				// code...
				break;
		}
	}

	/**
	 * The request for subscription.
	 * 訂閱的定期扣款走這邊
	 * 未來如果用 get_transaction_args 取得參數，記得，不需要 3D 驗證
	 *
	 * @param float|int $amount The amount.
	 * @param \WC_Order $order The subscription order object.
	 */
	public function build_subscription_request( float|int $amount, WC_Order $order ): void {
		$order_suffix = self::burn_order_suffix( $order );

		$args = [
			'MerID'       => $this->gateway->get_mer_id(),
			'MerTradeNo'  => $order->get_id() . $order_suffix,
			'TradeAmt'    => $amount,
			'Timestamp'   => time(),
			'UsrMail'     => $order->get_billing_email(),
			'ProdDesc'    => $this->get_product_name( $order ),
			'CreditToken' => $order->get_billing_email(),
			'CreditHash'  => $this->get_card_hash( $order ),
		];

		// 送出時實際使用的 MerTradeNo 落地保存，供日後反查交易狀態（理由同 get_transaction_args()）。
		$order->update_meta_data( '_payuni_mer_trade_no', $args['MerTradeNo'] );
		$order->save();

		Payment::log( $args );

		$parameter = [
			'MerID'       => $this->gateway->get_mer_id(),
			'Version'     => '1.0',
			'EncryptInfo' => \Payuni\APIs\Payment::encrypt( $args ),
			'HashInfo'    => \Payuni\APIs\Payment::hash_info( \Payuni\APIs\Payment::encrypt( $args ) ),
		];

		$options = [
			'method'     => 'POST',
			'timeout'    => 60,
			'body'       => $parameter,
			'user-agent' => 'payuni',
		];

		$response = wp_remote_request(
			$this->gateway->get_api_url() . $this->gateway->get_api_endpoint_url(),
			$options
		);
		$resp     = json_decode( wp_remote_retrieve_body( $response ) );

		$this->set_response( $order->get_payment_method(), $resp );
	}

	/**
	 * 取得 card hash
	 * 先判斷用戶的預設卡號是不是 payuni-credit-subscription，如果是，就回傳預設卡號
	 * 如果不是，就回傳 payuni-credit-subscription 的最新卡號
	 *
	 * @param \WC_Order $order The order object.
	 * @return string
	 */
	private function get_card_hash( $order ): string {
		if (!class_exists('WC_Subscriptions')) {
			return '';
		}
		$customer_id = $order->get_customer_id();

		$user_default_token = \WC_Payment_Tokens::get_customer_default_token( $order->get_customer_id() );

		if ( 'payuni-credit-subscription' === $user_default_token?->get_gateway_id()) {
			return $user_default_token?->get_token() ?? '';
		}

		$payment_tokens = \WC_Payment_Tokens::get_customer_tokens( $customer_id, 'payuni-credit-subscription' ); // 預設為升序

		if (!$payment_tokens) {
			return '';
		}

		// 取最後一個
		$payment_token = end( $payment_tokens );

		return $payment_token?->get_token() ?? '';
	}

	/**
	 * 取得 card hash 的付款請求
	 * 用戶可能只是儲存付款方式，沒有訂單，這邊會接受一個動態產生的訂單
	 *
	 * @see https://www.payuni.com.tw/docs/web/#/7/35
	 *
	 * @param \WC_Order                                                                    $order The order object.
	 * @param array{number:string, expiry:string, cvc:string, token_id:string, new:string} $card_data 卡片資料
	 *
	 * @return array
	 */
	public function build_hash_request( $order, array $card_data ): array {
		$order_suffix = ( $order->get_meta( '_payuni_order_suffix' ) ) ? '-' . $order->get_meta( '_payuni_order_suffix' ) : '';

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user_id = get_current_user_id();

		$args = [
			'MerID'       => $this->gateway->get_mer_id(),
			'MerTradeNo'  => $order?->get_id() . $order_suffix,
			'TradeAmt'    => 5,
			'Timestamp'   => time(),
			'UsrMail'     => get_userdata( $user_id )->user_email,
			'ProdDesc'    => '新增信用卡',
			'CardNo'      => $card_data['number'],
			'CardExpired' => $card_data['expiry'],
			'CardCVC'     => $card_data['cvc'],
			'CreditToken' => get_userdata( $user_id )->user_email,
		];

		// 送出時實際使用的 MerTradeNo 落地保存，供日後反查交易狀態（理由同 get_transaction_args()）。
		$order->update_meta_data( '_payuni_mer_trade_no', $args['MerTradeNo'] );

		if ( wc_string_to_bool( get_option( 'payuni_3d_auth', 'yes' ) ) ) {
			$args['API3D'] = 1;

			// 綁卡的 5 元訂單同樣需要 NotifyURL：沒有它，顧客在 3D 頁關掉分頁時 card_response() 永遠
			// 不會執行，於是 (1) 信用卡 Token 不會被存進 WC_Payment_Tokens、(2) 5 元退刷不會被排程，
			// 那 5 元真的沒退。理由同 get_transaction_args()。
			$args['ReturnURL'] = home_url( 'wc-api/payuni_notify_card' );

			/** This filter is documented in includes/payuni/src/gateways/Request.php */
			if ( apply_filters( 'woomp_payuni_v2_enable_notify_url', true, $order ) ) {
				$args['NotifyURL'] = home_url( 'wc-api/payuni_notify_card_bg' );
			}

			$order->update_meta_data( '_payuni_is_3d_auth', 'yes' );
		}

		$order->save();

		Payment::log( $args );

		$parameter = [
			'MerID'       => $this->gateway->get_mer_id(),
			'Version'     => '1.0',
			'EncryptInfo' => \Payuni\APIs\Payment::encrypt( $args ),
			'HashInfo'    => \Payuni\APIs\Payment::hash_info( \Payuni\APIs\Payment::encrypt( $args ) ),
		];

		$options = [
			'method'     => 'POST',
			'timeout'    => 60,
			'body'       => $parameter,
			'user-agent' => 'payuni',
		];

		$response = wp_remote_request(
			$this->gateway->get_api_url() . $this->gateway->get_api_endpoint_url(),
			$options
		);
		$resp     = json_decode( wp_remote_retrieve_body( $response ) );

		$redirect = $order ? $this->gateway->get_return_url( $order ) : '';
		$result   = Response::handle_hash_response( $resp, $redirect );

		// 如果是新增付款方式，就不需要再傳卡號、有效期、末三碼
		if ( 'success' === $result['result'] && ! $result['is_3d_auth'] ) {

			// 更新訂單狀態為已完成.
			$order->update_status( 'completed' );
			$order->save();

		}

		return $result;
	}
}

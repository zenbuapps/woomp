<?php
/**
 * PayUni V3 定期定額訂閱處理器
 *
 * 負責處理零元取 Token、排程續扣、取消授權等訂閱交易邏輯。
 *
 * @package J7\Payuni\Domains\Subscription
 */

declare(strict_types=1);

namespace J7\Payuni\Domains\Subscription;

use J7\Payuni\Contracts\DTOs\SettingDTO;
use J7\Payuni\Infrastructure\Http\TradeHandler;
use J7\Payuni\Shared\Enums\EUseTokenType;
use J7\Payuni\Shared\Utils\CreditTokenUtils;
use J7\Payuni\Shared\Utils\EncryptUtils;
use PAYUNI\Gateways\CreditSubscriptionV3;

\defined( 'ABSPATH' ) || exit;

/**
 * 訂閱交易處理器
 *
 * @since 1.0.0
 */
final class SubscriptionHandler {

	/**
	 * V1 定期定額閘道 ID
	 *
	 * @var string
	 */
	private const V1_GATEWAY_ID = 'payuni-credit-subscription';

	/**
	 * V3 定期定額閘道 ID
	 *
	 * @var string
	 */
	private const V3_GATEWAY_ID = 'payuni-credit-subscription-v3';

	/**
	 * V3 一般信用卡閘道 ID
	 *
	 * @var string
	 */
	private const CREDIT_V3_GATEWAY_ID = 'payuni-credit-v3';

	/**
	 * 處理零元取 Token 交易
	 *
	 * 扣 5 元取得 CreditHash，排程 2 分鐘後取消授權。
	 *
	 * @param \WC_Order $order 訂單物件
	 *
	 * @return array{result: string, redirect?: string} 交易結果
	 */
	public function process_zero_amount_token( \WC_Order $order ): array {
		$order_suffix = $order->get_meta( '_payuni_order_suffix' ) ? '-' . $order->get_meta( '_payuni_order_suffix' ) : '';

		$sdk_token    = (string) $order->get_meta( 'sdk_token_tmp', true );
		$mer_trade_no = $order->get_order_number() . $order_suffix;

		// 送出前落地本次 MerTradeNo（含流水號），3D 背景通知回來時以它核對訂單。
		TradeHandler::remember_mer_trade_no( $order, $mer_trade_no );

		try {
			$handler = new TradeHandler();
			$trade_result = $this->request_binding_trade( $order, $mer_trade_no, $sdk_token, $order->get_checkout_order_received_url() );

			$trade_no = $trade_result['TradeNo'] ?? '';
			$url_3d   = $trade_result['URL'] ?? '';

			if ( ! empty( $trade_no ) ) {
				// 直接授權成功（無 3D）
				$order->update_meta_data( 'payuni_save_card', 'yes' );
				$handler->update_order_status( $order, $trade_result );

				// 排程 2 分鐘後釋放 5 元（取消授權或退款），結果寫進訂單備註
				$this->schedule_cancel_authorization( (string) $trade_no, (int) $order->get_id() );

				return [
					'result'   => 'success',
					'redirect' => $order->get_checkout_order_received_url(),
				];
			}

			// 需 3D 驗證：PayUni 回傳 OTP 驗證頁網址（URL，Message「建立幕後3D成功」）。
			// 必須導向該頁讓顧客完成驗證（輸入簡訊 OTP），否則顧客收不到簡訊、
			// 訂單卡在「等待付款」、webhook 也不會回傳。與 process_positive_amount 同修。
			if ( ! empty( $url_3d ) ) {
				$order->update_meta_data( 'payuni_save_card', 'yes' );
				$order->update_meta_data( '_payuni_v3_resp', $trade_result );
				// 標記：3D 授權成功（webhook 回傳）後需補排程取消這筆 5 元授權（3D 路徑當下無 TradeNo）。
				$order->update_meta_data( '_payuni_zero_token_authcancel', 'pending' );
				$order->add_order_note( '3D 驗證已建立（零元取 Token），導向 OTP 驗證頁' );
				$order->save();

				return [
					'result'   => 'success',
					'redirect' => $url_3d,
				];
			}

			// 無 TradeNo 也無 URL → 交易失敗
			$status  = $trade_result['Status'] ?? '';
			$message = $trade_result['Message'] ?? '';
			\wc_add_notice( \esc_html( \sprintf( '零元取 Token 失敗（%s）：%s', $status, $message ?: '未取得交易結果' ) ), 'error' );

			return [
				'result' => 'failure',
			];
		} catch ( \Throwable $e ) {
			\do_action( 'woomp_payuni_log', 'error', "#{$order->get_id()} 零元取 Token 失敗: {$e->getMessage()}", [] );

			return [
				'result' => 'failure',
			];
		}
	}

	/**
	 * 送出 5 元綁卡交易（強制約定信用卡），回傳解密後的結果
	 *
	 * 只負責組參數、送出與解密，不碰訂單／訂閱狀態；成功、3D、失敗由呼叫端判斷。
	 * 零元首購（process_zero_amount_token）與定期定額換卡（PaymentMethodChange）共用。
	 *
	 * @param \WC_Order $target       綁卡對象（訂單或訂閱），取帳單 email 與客戶 ID
	 * @param string    $mer_trade_no 本次 MerTradeNo（每次嘗試都必須不同）
	 * @param string    $sdk_token    前端 SDK Token
	 * @param string    $return_url   3D 驗證後導回的網址（僅 3D 開啟時送出）
	 *
	 * @return array 解密後的交易結果
	 * @throws \Exception When the HTTP request, response verification or decryption fails.
	 */
	public function request_binding_trade( \WC_Order $target, string $mer_trade_no, string $sdk_token, string $return_url ): array {
		$setting     = SettingDTO::instance();
		$buyer_email = $target->get_billing_email();

		$trade_params = [
			'MerID'        => $setting->merchant_id,
			'MerTradeNo'   => $mer_trade_no,
			'Token'        => $sdk_token,
			'TradeAmt'     => 5,
			'Timestamp'    => \time(),
			'NotifyURL'    => \home_url( '/wc-api/payuni_notify' ),
			'UsrMail'      => $buyer_email,
			'ProdDesc'     => '訂閱取得信用卡 Token',
			// 續扣需要可幕後扣款的 CreditHash，只有「約定信用卡」類型才會由 PayUni 壓碼。
			// UseTokenType=2（記憶卡號）僅供下次結帳帶出卡號，授權雖成功但不回 CreditHash。
			// 此值必須與 token_get 及前端 getTradeResult 送出的 useTokenType 完全一致。
			'UseTokenType' => EUseTokenType::FORCE_BIND->value,
			'CreditToken'  => CreditTokenUtils::sanitize( $buyer_email, (int) $target->get_customer_id() ),
		];

		if ( $setting->enable_3d_auth ) {
			$trade_params['API3D']     = 1;
			$trade_params['ReturnURL'] = $return_url;
		}

		$encrypt_info = EncryptUtils::encrypt( $trade_params );
		$request_body = [
			'MerID'       => $setting->merchant_id,
			'Version'     => '1.0',
			'EncryptInfo' => $encrypt_info,
			'HashInfo'    => EncryptUtils::hash_info( $encrypt_info ),
		];

		$handler      = new TradeHandler();
		$raw_response = $handler->execute_trade( $request_body );

		return $handler->process_notify( $raw_response );
	}

	/**
	 * 排程釋放 5 元綁卡授權（2 分鐘後；取消授權或退款由 AuthorizationRelease 依請款狀態決定）
	 *
	 * @param string $trade_no  PayUni 交易編號
	 * @param int    $object_id 結果要寫備註的訂單或訂閱 ID（0 表示不寫）
	 *
	 * @return void
	 */
	public function schedule_cancel_authorization( string $trade_no, int $object_id = 0 ): void {
		AuthorizationRelease::schedule( $trade_no, $object_id );
	}

	/**
	 * 釋放 5 元綁卡授權（排程 callback）
	 *
	 * 以前只呼叫 trade/cancel：商店自動請款時必定 CANCEL03001，5 元留在客戶帳上。
	 * 改由 AuthorizationRelease 先查請款狀態，未請款取消、已請款退款，並保證同一 TradeNo 只退一次。
	 *
	 * @param string $trade_no PayUni 交易編號
	 *
	 * @return void
	 */
	public static function cancel_zero_authorization( $trade_no ): void {
		AuthorizationRelease::run( (string) $trade_no );
	}

	/**
	 * 取得訂單的 CreditHash
	 *
	 * 優先序（issue #147：客戶預設的舊 V1 token 不可搶先）：
	 * 1. 續訂單所屬訂閱綁定的 `_payuni_token_id`
	 * 2. 訂單自己的 `_payuni_token_id`
	 * 3. 客戶預設 token，且為 V3 訂閱閘道
	 * 4. 最新一筆 V3 訂閱閘道 token
	 * 5. 最新一筆 payuni-credit-v3 token
	 * 6. 最新一筆 V1 token（最後手段，寫 warning）
	 *
	 * 每一層都會驗證 token 存在、屬於訂單客戶、閘道符合該層、值非空。
	 *
	 * @param \WC_Order $order 訂單物件
	 *
	 * @return string CreditHash，找不到時回傳空字串
	 */
	public static function get_card_hash( \WC_Order $order ): string {
		$customer_id = (int) $order->get_customer_id();

		if ( ! $customer_id ) {
			return '';
		}

		$bound_gateways = [ self::V3_GATEWAY_ID, self::CREDIT_V3_GATEWAY_ID ];

		// 1. 續訂單所屬訂閱綁定的 token
		if ( \function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			foreach ( (array) \wcs_get_subscriptions_for_renewal_order( $order ) as $subscription ) {
				if ( ! $subscription instanceof \WC_Order ) {
					continue;
				}
				$token = self::resolve_valid_token( (int) $subscription->get_meta( '_payuni_token_id' ), $customer_id, $bound_gateways );
				if ( $token ) {
					return $token->get_token();
				}
			}
		}

		// 2. 訂單自己的 token
		$token = self::resolve_valid_token( (int) $order->get_meta( '_payuni_token_id' ), $customer_id, $bound_gateways );
		if ( $token ) {
			return $token->get_token();
		}

		// 3. 客戶預設 token（只收 V3 訂閱閘道）
		$default_token = \WC_Payment_Tokens::get_customer_default_token( $customer_id );
		if ( $default_token ) {
			$token = self::resolve_valid_token( (int) $default_token->get_id(), $customer_id, [ self::V3_GATEWAY_ID ] );
			if ( $token ) {
				return $token->get_token();
			}
		}

		// 4、5. 最新一筆 V3 訂閱 token → payuni-credit-v3 token
		foreach ( [ self::V3_GATEWAY_ID, self::CREDIT_V3_GATEWAY_ID ] as $gateway_id ) {
			$token = self::find_latest_token( $customer_id, $gateway_id );
			if ( $token ) {
				return $token->get_token();
			}
		}

		// 6. 舊 V1 token（最後手段）
		$token = self::find_latest_token( $customer_id, self::V1_GATEWAY_ID );
		if ( $token ) {
			\do_action(
				'woomp_payuni_log',
				'warning',
				"#{$order->get_id()} 續扣使用舊 V1 Token，可能需重新綁卡",
				[
					'order_id'    => $order->get_id(),
					'customer_id' => $customer_id,
					'token_id'    => $token->get_id(),
				]
			);
			return $token->get_token();
		}

		return '';
	}

	/**
	 * 取得客戶在指定閘道最新一筆合法 token
	 *
	 * @param int    $customer_id 客戶 ID
	 * @param string $gateway_id  閘道 ID
	 *
	 * @return \WC_Payment_Token|null
	 */
	private static function find_latest_token( int $customer_id, string $gateway_id ): ?\WC_Payment_Token {
		$tokens = \WC_Payment_Tokens::get_customer_tokens( $customer_id, $gateway_id );
		if ( empty( $tokens ) ) {
			return null;
		}

		$latest = end( $tokens );

		return self::resolve_valid_token( (int) $latest->get_id(), $customer_id, [ $gateway_id ] );
	}

	/**
	 * 驗證 token 可用於此客戶的續扣
	 *
	 * @param int      $token_id    Payment Token ID（0 表示未設定）
	 * @param int      $customer_id 訂單客戶 ID
	 * @param string[] $gateway_ids 允許的閘道 ID
	 *
	 * @return \WC_Payment_Token|null 不存在、不屬於客戶、閘道不符或值為空時回傳 null
	 */
	private static function resolve_valid_token( int $token_id, int $customer_id, array $gateway_ids ): ?\WC_Payment_Token {
		if ( $token_id <= 0 || $customer_id <= 0 ) {
			return null;
		}

		$token = \WC_Payment_Tokens::get( $token_id );
		if ( ! $token instanceof \WC_Payment_Token ) {
			\do_action( 'woomp_payuni_log', 'warning', "綁定的 Token #{$token_id} 不存在，改找下一層", [ 'customer_id' => $customer_id ] );
			return null;
		}

		if ( (int) $token->get_user_id() !== $customer_id ) {
			\do_action(
				'woomp_payuni_log',
				'error',
				"Token #{$token_id} 不屬於訂單客戶，已略過",
				[
					'customer_id'   => $customer_id,
					'token_user_id' => $token->get_user_id(),
				]
			);
			return null;
		}

		if ( ! \in_array( $token->get_gateway_id(), $gateway_ids, true ) || '' === (string) $token->get_token() ) {
			return null;
		}

		return $token;
	}

	/**
	 * 處理排程續扣
	 *
	 * @param float     $amount 續扣金額
	 * @param \WC_Order $order  續扣訂單
	 *
	 * @return void
	 */
	public function process_renewal_payment( float $amount, \WC_Order $order ): void {
		$card_hash = self::get_card_hash( $order );

		if ( empty( $card_hash ) ) {
			$order->update_status( 'failed', '續扣失敗：找不到信用卡 Token' );
			return;
		}

		$setting = SettingDTO::instance();
		$order_suffix = $order->get_meta( '_payuni_order_suffix' ) ? '-' . $order->get_meta( '_payuni_order_suffix' ) : '';

		// 續扣為幕後 Token 交易，必須打信用卡幕後端點 /api/credit。
		// 過去誤打 UNi Embed 的 /iframe/merchant_trade——該端點是給 iframe 前端流程用的，
		// 必要參數 Token（SDK_TOKEN，10 分鐘有效）只有結帳頁能取得，
		// 背景排程沒有前端可帶，PayUni 必然回 IFTRADE02004「未有交易 Token」。
		$trade_params = [
			'MerID'       => $setting->merchant_id,
			'MerTradeNo'  => $order->get_id() . $order_suffix,
			'TradeAmt'    => (int) $amount,
			'Timestamp'   => \time(),
			'UsrMail'     => $order->get_billing_email(),
			'ProdDesc'    => '訂閱續扣',
			'CreditToken' => CreditTokenUtils::sanitize( $order->get_billing_email(), $order->get_customer_id() ),
			'CreditHash'  => $card_hash,
		];

		try {
			$trade_result = $this->request_credit_api( $trade_params );

			$status = $trade_result['Status'] ?? '';

			if ( 'SUCCESS' === $status ) {
				$trade_no = $trade_result['TradeNo'] ?? '';
				$order->payment_complete( $trade_no );
				$order->add_order_note( '<strong>V3 續扣成功</strong>' );
				$this->save_renewal_meta( $order, $trade_result );
				$order->save();
				return;
			}

			// V3 失敗，嘗試 fallback
			\do_action( 'woomp_payuni_log', 'warning', "#{$order->get_id()} V3 續扣失敗，嘗試 V1 fallback", [
				'status'  => $status,
				'message' => $trade_result['Message'] ?? '',
			] );
		} catch ( \Throwable $e ) {
			\do_action( 'woomp_payuni_log', 'warning', "#{$order->get_id()} V3 續扣例外，嘗試 V1 fallback: {$e->getMessage()}", [] );
		}

		// Fallback：走 V1 endpoint（api/credit）
		try {
			$this->process_renewal_via_v1( $amount, $order, $card_hash );
		} catch ( \Throwable $e ) {
			$order->update_status( 'failed', "續扣失敗（V1 fallback）：{$e->getMessage()}" );
			\do_action( 'woomp_payuni_log', 'error', "#{$order->get_id()} V1 fallback 續扣失敗: {$e->getMessage()}", [] );
		}
	}

	/**
	 * 呼叫信用卡幕後 Token 交易 API（/api/credit）
	 *
	 * 這是 PayUni 提供給「已取得 CreditHash 後、由商店自行排程扣款」的端點，
	 * 不需要前端 SDK_TOKEN，因此適用背景續扣。
	 *
	 * @see https://docs.payuni.com.tw/web/#/7/35
	 *
	 * @param array $trade_params 未加密的交易參數
	 *
	 * @return array 解密後的交易結果
	 * @throws \Exception 請求失敗或回應格式異常時拋出。
	 */
	private function request_credit_api( array $trade_params ): array {
		$setting      = SettingDTO::instance();
		$encrypt_info = EncryptUtils::encrypt( $trade_params );

		$request_body = [
			'MerID'       => $setting->merchant_id,
			'Version'     => '1.0',
			'EncryptInfo' => $encrypt_info,
			'HashInfo'    => EncryptUtils::hash_info( $encrypt_info ),
		];

		$api_url = $setting->mode->base_api_url() . '/credit';

		$response = \wp_remote_post(
			$api_url,
			[
				'body'       => $request_body,
				'blocking'   => true,
				'timeout'    => 60,
				'user-agent' => 'payuni',
			]
		);

		if ( \is_wp_error( $response ) ) {
			throw new \Exception( $response->get_error_message() );
		}

		$response_body = \json_decode( \wp_remote_retrieve_body( $response ), true );

		\do_action( 'woomp_payuni_log', 'info', '訂閱續扣幕後授權結果', [
			'endpoint' => $api_url,
			'body'     => $request_body,
			'result'   => $response_body,
		] );

		if ( empty( $response_body['EncryptInfo'] ) ) {
			throw new \Exception( '回應格式異常：缺少 EncryptInfo' );
		}

		return EncryptUtils::decrypt( $response_body['EncryptInfo'] );
	}

	/**
	 * 透過 V1 endpoint 處理續扣（fallback）
	 *
	 * @param float     $amount    續扣金額
	 * @param \WC_Order $order     續扣訂單
	 * @param string    $card_hash CreditHash
	 *
	 * @return void
	 * @throws \Exception 續扣失敗時拋出異常
	 */
	private function process_renewal_via_v1( float $amount, \WC_Order $order, string $card_hash ): void {
		$order_suffix = $order->get_meta( '_payuni_order_suffix' ) ? '-' . $order->get_meta( '_payuni_order_suffix' ) : '';

		$args = [
			'MerID'       => ( new \PAYUNI\Gateways\CreditSubscriptionV3() )->get_mer_id(),
			'MerTradeNo'  => $order->get_id() . $order_suffix,
			'TradeAmt'    => (int) $amount,
			'Timestamp'   => \time(),
			'UsrMail'     => $order->get_billing_email(),
			'ProdDesc'    => '訂閱續扣',
			'CreditToken' => CreditTokenUtils::sanitize( $order->get_billing_email(), $order->get_customer_id() ),
			'CreditHash'  => $card_hash,
		];

		$parameter = [
			'MerID'       => $args['MerID'],
			'Version'     => '1.0',
			'EncryptInfo' => \Payuni\APIs\Payment::encrypt( $args ),
			'HashInfo'    => \Payuni\APIs\Payment::hash_info( \Payuni\APIs\Payment::encrypt( $args ) ),
		];

		$gateway = new \PAYUNI\Gateways\CreditSubscriptionV3();

		$options = [
			'method'     => 'POST',
			'timeout'    => 60,
			'body'       => $parameter,
			'user-agent' => 'payuni',
		];

		$response = \wp_remote_request(
			$gateway->get_api_url() . $gateway->get_api_endpoint_url(),
			$options
		);

		if ( \is_wp_error( $response ) ) {
			throw new \Exception( $response->get_error_message() );
		}

		$resp = \json_decode( \wp_remote_retrieve_body( $response ) );

		if ( empty( $resp->EncryptInfo ) ) {
			throw new \Exception( '回應格式異常' );
		}

		$data = \Payuni\APIs\Payment::decrypt( $resp->EncryptInfo );

		if ( 'SUCCESS' === ( $data['Status'] ?? '' ) ) {
			$trade_no = $data['TradeNo'] ?? '';
			$order->payment_complete( $trade_no );
			$order->add_order_note( '<strong>V1 fallback 續扣成功</strong>' );
			$this->save_renewal_meta( $order, $data );
			$order->save();
		} else {
			throw new \Exception( $data['Message'] ?? '未知錯誤' );
		}
	}

	/**
	 * 儲存續扣交易 meta
	 *
	 * @param \WC_Order $order        訂單物件
	 * @param array     $trade_result 交易結果
	 *
	 * @return void
	 */
	private function save_renewal_meta( \WC_Order $order, array $trade_result ): void {
		$order->update_meta_data( '_payuni_resp_status', $trade_result['Status'] ?? '' );
		$order->update_meta_data( '_payuni_resp_message', $trade_result['Message'] ?? '' );

		if ( ! empty( $trade_result['TradeNo'] ) ) {
			$order->update_meta_data( '_payuni_resp_trade_no', $trade_result['TradeNo'] );
		}

		if ( ! empty( $trade_result['Card4No'] ) ) {
			$order->update_meta_data( '_payuni_card_number', $trade_result['Card4No'] );
		}
	}
}

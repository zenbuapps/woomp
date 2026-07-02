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
use J7\Payuni\Infrastructure\Http\HttpClient;
use J7\Payuni\Infrastructure\Http\TradeHandler;
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
	 * 處理零元取 Token 交易
	 *
	 * 扣 5 元取得 CreditHash，排程 2 分鐘後取消授權。
	 *
	 * @param \WC_Order $order 訂單物件
	 *
	 * @return array{result: string, redirect?: string} 交易結果
	 */
	public function process_zero_amount_token( \WC_Order $order ): array {
		$setting = SettingDTO::instance();

		$order_suffix = $order->get_meta( '_payuni_order_suffix' ) ? '-' . $order->get_meta( '_payuni_order_suffix' ) : '';

		$sdk_token = $order->get_meta( 'sdk_token_tmp', true );
		$buyer_email = $order->get_billing_email();

		$trade_params = [
			'MerID'        => $setting->merchant_id,
			'MerTradeNo'   => $order->get_order_number() . $order_suffix,
			'Token'        => $sdk_token,
			'TradeAmt'     => 5,
			'Timestamp'    => \time(),
			'NotifyURL'    => \home_url( '/wc-api/payuni_notify' ),
			'UsrMail'      => $buyer_email,
			'ProdDesc'     => '訂閱取得信用卡 Token',
			'UseTokenType' => 2,
			'CreditToken'  => CreditTokenUtils::sanitize( $buyer_email, $order->get_customer_id() ),
		];

		if ( $setting->enable_3d_auth ) {
			$trade_params['API3D'] = 1;
			$trade_params['ReturnURL'] = $order->get_checkout_order_received_url();
		}

		$encrypt_info = EncryptUtils::encrypt( $trade_params );
		$request_body = [
			'MerID'       => $setting->merchant_id,
			'Version'     => '1.0',
			'EncryptInfo' => $encrypt_info,
			'HashInfo'    => EncryptUtils::hash_info( $encrypt_info ),
		];

		try {
			$handler = new TradeHandler();
			$raw_response = $handler->execute_trade( $request_body );
			$trade_result = $handler->process_notify( $raw_response );

			$trade_no = $trade_result['TradeNo'] ?? '';
			$url_3d   = $trade_result['URL'] ?? '';

			if ( ! empty( $trade_no ) ) {
				// 直接授權成功（無 3D）
				$order->update_meta_data( 'payuni_save_card', 'yes' );
				$handler->update_order_status( $order, $trade_result );

				// 排程 2 分鐘後取消授權
				$this->schedule_cancel_authorization( $trade_no );

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
	 * 排程取消零元授權
	 *
	 * @param string $trade_no PayUni 交易編號
	 *
	 * @return void
	 */
	public function schedule_cancel_authorization( string $trade_no ): void {
		\wp_schedule_single_event(
			\time() + 120,
			'payuni_cancel_zero_authorization',
			[ $trade_no ]
		);
	}

	/**
	 * 取消零元授權
	 *
	 * @param string $trade_no PayUni 交易編號
	 *
	 * @return void
	 */
	public static function cancel_zero_authorization( string $trade_no ): void {
		try {
			$http_client = new HttpClient();
			$http_client->cancel_trade( $trade_no );

			\do_action( 'woomp_payuni_log', 'info', "零元授權取消成功: {$trade_no}", [] );
		} catch ( \Throwable $e ) {
			\do_action( 'woomp_payuni_log', 'error', "零元授權取消失敗: {$trade_no} - {$e->getMessage()}", [] );
		}
	}

	/**
	 * 取得訂單的 CreditHash（支援 V3 和 V1 雙 gateway_id 搜尋）
	 *
	 * 先搜尋 V3 gateway 的 Token，若找不到則 fallback 到 V1。
	 *
	 * @param \WC_Order $order 訂單物件
	 *
	 * @return string CreditHash，找不到時回傳空字串
	 */
	public static function get_card_hash( \WC_Order $order ): string {
		$customer_id = $order->get_customer_id();

		if ( ! $customer_id ) {
			return '';
		}

		// 優先：使用者預設 Token 如果是 V3 或 V1 訂閱閘道
		$default_token = \WC_Payment_Tokens::get_customer_default_token( $customer_id );
		if ( $default_token ) {
			$gateway_id = $default_token->get_gateway_id();
			if ( self::V3_GATEWAY_ID === $gateway_id || self::V1_GATEWAY_ID === $gateway_id ) {
				$token_value = $default_token->get_token();
				if ( $token_value ) {
					return $token_value;
				}
			}
		}

		// 搜尋 V3 閘道 Token
		$v3_tokens = \WC_Payment_Tokens::get_customer_tokens( $customer_id, self::V3_GATEWAY_ID );
		if ( ! empty( $v3_tokens ) ) {
			$token = end( $v3_tokens );
			$token_value = $token->get_token();
			if ( $token_value ) {
				return $token_value;
			}
		}

		// Fallback：搜尋 V1 閘道 Token
		$v1_tokens = \WC_Payment_Tokens::get_customer_tokens( $customer_id, self::V1_GATEWAY_ID );
		if ( ! empty( $v1_tokens ) ) {
			$token = end( $v1_tokens );
			$token_value = $token->get_token();
			if ( $token_value ) {
				return $token_value;
			}
		}

		// 再 fallback：搜尋 V3 CreditV3 閘道 Token
		$credit_v3_tokens = \WC_Payment_Tokens::get_customer_tokens( $customer_id, 'payuni-credit-v3' );
		if ( ! empty( $credit_v3_tokens ) ) {
			$token = end( $credit_v3_tokens );
			$token_value = $token->get_token();
			if ( $token_value ) {
				return $token_value;
			}
		}

		return '';
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

		$trade_params = [
			'MerID'        => $setting->merchant_id,
			'MerTradeNo'   => $order->get_id() . $order_suffix,
			'TradeAmt'     => (int) $amount,
			'Timestamp'    => \time(),
			'UsrMail'      => $order->get_billing_email(),
			'ProdDesc'     => '訂閱續扣',
			'CreditToken'  => CreditTokenUtils::sanitize( $order->get_billing_email(), $order->get_customer_id() ),
			'CreditHash'   => $card_hash,
			'UseTokenType' => 2,
		];

		// 嘗試走 V3 endpoint（iframe/merchant_trade）
		try {
			$encrypt_info = EncryptUtils::encrypt( $trade_params );
			$request_body = [
				'MerID'       => $setting->merchant_id,
				'Version'     => '1.0',
				'EncryptInfo' => $encrypt_info,
				'HashInfo'    => EncryptUtils::hash_info( $encrypt_info ),
			];

			$handler = new TradeHandler();
			$raw_response = $handler->execute_trade( $request_body );
			$trade_result = $handler->process_notify( $raw_response );

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
				'status' => $status,
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

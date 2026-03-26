<?php
/**
 * PayUni V3 定期定額信用卡閘道
 *
 * 使用 UNi Embed iframe 處理首次付款及換卡，
 * 使用 server-side CreditHash + merchant_trade API 處理續扣。
 * 與 WooCommerce Subscriptions 整合，支援完整的訂閱生命週期管理。
 *
 * @package payuni
 */

namespace PAYUNI\Gateways;

use J7\Payuni\Contracts\DTOs\SettingDTO;
use J7\Payuni\Contracts\DTOs\TradeReqHashDTO;
use J7\Payuni\Domains\Subscription\SubscriptionHandler;
use J7\Payuni\Infrastructure\Http\TradeHandler;
use J7\Payuni\Shared\Utils\EncryptUtils;
use J7\Payuni\Shared\Utils\OrderUtils;

\defined( 'ABSPATH' ) || exit;

/**
 * V3 定期定額信用卡閘道
 *
 * @since 1.0.0
 */
class CreditSubscriptionV3 extends AbstractGateway {

	/**
	 * Gateway ID 常數
	 *
	 * @var string
	 */
	public const ID = 'payuni-credit-subscription-v3';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id = self::ID;
		parent::__construct();

		$this->has_fields         = true;
		$this->method_title       = '統一金流 PAYUNi 信用卡定期定額（免跳轉）';
		$this->method_description = '透過統一金流 PAYUNi 信用卡定期定額進行站內付款（UNi Embed 免跳轉）';

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->supports    = [
			'products',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'multiple_subscriptions',
			'tokenization',
		];

		$this->api_endpoint_url = 'api/credit';

		\add_action(
			"woocommerce_update_options_payment_gateways_{$this->id}",
			[ $this, 'process_admin_options' ]
		);
	}

	/**
	 * 設定後台 form fields
	 *
	 * 訂閱閘道不需要分期選項和記憶卡號開關（強制 token）。
	 *
	 * @return void
	 */
	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled'     => [
				'title'   => __( 'Enable/Disable', 'woocommerce' ),
				'type'    => 'checkbox',
				'label'   => \sprintf( __( 'Enable %s', 'woomp' ), $this->method_title ),
				'default' => 'no',
			],
			'title'       => [
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'default'     => $this->method_title,
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'woocommerce' ),
				'type'        => 'textarea',
				'css'         => 'width: 400px;',
				'default'     => $this->order_button_text,
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
				'desc_tip'    => true,
			],
		];
	}

	/**
	 * 處理付款
	 *
	 * 三條路徑：
	 * 1. 金額 > 0：走 UNi Embed iframe 交易（強制 UseTokenType=2、CreditToken=email、不帶 CardInst）
	 * 2. 金額 = 0 + 有已存 Token：直接 payment_complete
	 * 3. 金額 = 0 + 無 Token：扣 5 元取 CreditHash
	 *
	 * @param string $order_id 訂單 ID。
	 * @return array
	 */
	public function process_payment( $order_id ): array {
		$order = \wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return [
				'result' => 'failure',
			];
		}

		$order_total = (int) $order->get_total();

		// 路徑 2 & 3：金額 = 0
		if ( 0 === $order_total ) {
			return $this->process_zero_amount( $order );
		}

		// 路徑 1：金額 > 0，走 V3 TradeHandler 交易
		return $this->process_positive_amount( $order );
	}

	/**
	 * 處理零元付款（金額 = 0）
	 *
	 * 路徑 2：有已存 Token → 直接 payment_complete
	 * 路徑 3：無 Token → 扣 5 元取 CreditHash
	 *
	 * @param \WC_Order $order 訂單物件
	 *
	 * @return array 付款結果
	 */
	private function process_zero_amount( \WC_Order $order ): array {
		// 嘗試取得使用者選擇的 saved token
		$token_id = $this->find_saved_token_id( $order );

		if ( $token_id ) {
			// 路徑 2：有 Token，直接完成付款
			$order->payment_complete();
			$order->add_order_note( "持有 token #{$token_id} 且訂單金額為 0，直接轉為處理中" );
			$order->update_meta_data( '_payuni_token_id', (string) $token_id );
			$order->save();

			return [
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			];
		}

		// 路徑 3：無 Token，走零元取 Token 流程
		$handler = new SubscriptionHandler();
		return $handler->process_zero_amount_token( $order );
	}

	/**
	 * 處理有金額付款（金額 > 0）
	 *
	 * 複用 V3 TradeHandler 交易流程，強制 UseTokenType=2、CreditToken=email。
	 *
	 * @param \WC_Order $order 訂單物件
	 *
	 * @return array 付款結果
	 */
	private function process_positive_amount( \WC_Order $order ): array {
		try {
			$setting = SettingDTO::instance();

			// 取得暫存資料
			[
				$sdk_token,
				$save_card,
				$installment,
				$use_saved_token,
				$saved_token_id,
			] = OrderUtils::get_tmp_data( $order );

			$buyer_email = $order->get_billing_email();
			$card_hash = '';

			// 如果使用已儲存的卡片
			if ( $use_saved_token && $saved_token_id ) {
				$token = \WC_Payment_Tokens::get( (int) $saved_token_id );
				if ( $token && $token->get_user_id() === $order->get_customer_id() ) {
					$card_hash = $token->get_token();
				}

				if ( empty( $card_hash ) ) {
					throw new \Exception( '已儲存的卡片資料無效或已過期，請使用新卡片付款。' );
				}
			}

			$trade_params = [
				'MerID'        => $setting->merchant_id,
				'MerTradeNo'   => $order->get_order_number(),
				'Token'        => $sdk_token,
				'TradeAmt'     => (int) $order->get_total(),
				'Timestamp'    => \time(),
				'ReturnURL'    => $order->get_checkout_order_received_url(),
				'NotifyURL'    => \home_url( '/wc-api/payuni_notify' ),
				'UsrMail'      => $buyer_email,
				'ProdDesc'     => $this->get_product_desc( $order ),
				'UseTokenType' => 2,
				'CreditToken'  => $buyer_email,
			];

			// 訂閱強制記住卡號
			$order->update_meta_data( 'payuni_save_card', 'yes' );

			if ( $setting->enable_3d_auth ) {
				$trade_params['API3D'] = 1;
			}

			// 不帶 CardInst（訂閱不支援分期）

			$ip = $order->get_customer_ip_address();
			if ( $ip ) {
				$trade_params['UserIP'] = $ip;
			}

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

			$trade_no = $trade_result['TradeNo'] ?? '';

			if ( ! empty( $trade_no ) ) {
				// 直接授權成功
				$handler->update_order_status( $order, $trade_result );
			} else {
				// 3D 驗證流程
				$order->update_meta_data( '_payuni_v3_resp', $trade_result );
				$order->add_order_note(
					\sprintf(
						'3D 驗證已建立（訂閱首次付款），等待 webhook 回傳（狀態碼：%s）',
						$trade_result['Status'] ?? ''
					)
				);
				$order->save();
			}

			return [
				'result'   => 'success',
				'redirect' => $order->get_checkout_order_received_url(),
				'order_id' => $order->get_id(),
			];
		} catch ( \Throwable $e ) {
			\do_action( 'woomp_payuni_log', 'error', "#{$order->get_id()} V3 訂閱 process_payment 失敗: {$e->getMessage()}", [
				'order_id' => $order->get_id(),
			] );

			// 處理 CREDIT04001 重複訂單編號
			if ( \str_contains( $e->getMessage(), 'IFTRADE01006' ) || \str_contains( $e->getMessage(), 'TOKEN01006' ) ) {
				return $this->handle_duplicate_order( $order );
			}

			\wc_add_notice( \esc_html( $e->getMessage() ), 'error' );

			return [
				'result' => 'failure',
			];
		}
	}

	/**
	 * 處理重複訂單編號（CREDIT04001 / IFTRADE01006）
	 *
	 * 使用 woomp_copy_order 建立新訂單，並重新執行 process_payment。
	 *
	 * @param \WC_Order $order 原始訂單
	 *
	 * @return array 付款結果
	 */
	private function handle_duplicate_order( \WC_Order $order ): array {
		if ( ! \function_exists( 'woomp_copy_order' ) ) {
			return [ 'result' => 'failure' ];
		}

		$new_order_id = \woomp_copy_order( $order );

		// 更新訂閱的上層訂單
		if ( \function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$subscriptions = \wcs_get_subscriptions_for_order( $order );
			foreach ( $subscriptions as $subscription ) {
				$subscription->set_parent_id( $new_order_id );
				$subscription->save();
			}
		}

		return $this->process_payment( $new_order_id );
	}

	/**
	 * 尋找使用者已儲存的 Token ID
	 *
	 * 檢查使用者選擇的 saved_token_id（前端傳入），
	 * 或搜尋 V3/V1 雙 gateway_id 的 Token。
	 *
	 * @param \WC_Order $order 訂單物件
	 *
	 * @return int|null Token ID，找不到時回傳 null
	 */
	private function find_saved_token_id( \WC_Order $order ): ?int {
		// 優先：前端明確傳入的 saved_token_id
		$saved_token_id = $order->get_meta( 'payuni_saved_token_id', true );
		$use_saved_token = \wc_string_to_bool( $order->get_meta( 'payuni_use_saved_token', true ) );

		if ( $use_saved_token && $saved_token_id && \is_numeric( $saved_token_id ) ) {
			$token = \WC_Payment_Tokens::get( (int) $saved_token_id );
			if ( $token && $token->get_user_id() === $order->get_customer_id() ) {
				return (int) $saved_token_id;
			}
		}

		// Fallback：搜尋客戶的 Token
		$customer_id = $order->get_customer_id();
		if ( ! $customer_id ) {
			return null;
		}

		$gateway_ids = [
			'payuni-credit-subscription-v3',
			'payuni-credit-subscription',
			'payuni-credit-v3',
		];

		foreach ( $gateway_ids as $gateway_id ) {
			$tokens = \WC_Payment_Tokens::get_customer_tokens( $customer_id, $gateway_id );
			if ( ! empty( $tokens ) ) {
				$token = end( $tokens );
				return $token->get_id();
			}
		}

		return null;
	}

	/**
	 * 取得商品描述
	 *
	 * @param \WC_Order $order 訂單物件
	 *
	 * @return string 商品描述
	 */
	private function get_product_desc( \WC_Order $order ): string {
		/** @var \WC_Order_Item_Product[] $items */
		$items = $order->get_items();
		$item_names = \array_map( static fn( $item ) => $item->get_name(), $items );
		return \implode( ';', $item_names ) ?: '訂閱商品';
	}
}

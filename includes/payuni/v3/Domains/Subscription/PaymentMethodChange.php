<?php
/**
 * PayUni V3 定期定額「更換付款方式」
 *
 * 換卡流程的唯一入口（issue #147）：
 * - 付款 meta：`woocommerce_subscription_payment_meta` 告訴 WCS V3 需要 `_payuni_token_id`；
 *   `woocommerce_subscription_validate_payment_meta_{V3}` 確保寫入的是訂閱客戶自己的 V3 token。
 * - 延後更新：WCS 預設在呼叫 process_payment() 之前就改付款方式。V3 對
 *   `woocommerce_subscriptions_update_payment_via_pay_shortcode` 回 false，綁卡成功後才由 complete()
 *   呼叫 `WC_Subscriptions_Change_Payment_Gateway::update_payment_method()`，3D 失敗或中途離開時訂閱維持原狀。
 * - 綁卡：只授權 5 元（UseTokenType=3）取得 CreditHash，之後排程取消授權；絕不扣每期金額。
 *   MerTradeNo 為 `{訂閱ID}-C{n}`，每次嘗試都用新編號，attempts 只保留最近 10 筆。
 * - 背景通知：Bootstrap::handle_notify() 遇到 `{ID}-C{n}` 交給 route_notify()，不走訂單流程。
 *   訂閱物件絕不呼叫 payment_complete()、也不送進 woomp_copy_order()。
 * - 依 TradeNo 冪等：同一筆 TradeNo 只排一次取消、只綁定一次。
 * - 3D 導回：ReturnURL 一律指向 wc-api 端點 handle_return()（不需登入），303 導向訂閱檢視頁；
 *   POST 內容須通過與背景通知相同的驗章才處理。3D 等待中擋下 WCS 的「付款方式已變更」，
 *   改由檢視頁依 attempt 狀態提示（add_status_notice）。
 *
 * 備註與 log 不寫卡號、CreditHash 與 SDK token。
 *
 * @package J7\Payuni\Domains\Subscription
 */

declare(strict_types=1);

namespace J7\Payuni\Domains\Subscription;

use J7\Payuni\Contracts\DTOs\SettingDTO;
use J7\Payuni\Infrastructure\Http\TradeHandler;

\defined( 'ABSPATH' ) || exit;

/**
 * 定期定額換卡流程
 */
final class PaymentMethodChange {

	/**
	 * V3 定期定額閘道 ID
	 *
	 * @var string
	 */
	private const V3_GATEWAY_ID = 'payuni-credit-subscription-v3';

	/**
	 * 訂閱綁定的 token meta key
	 *
	 * @var string
	 */
	public const META_TOKEN_ID = '_payuni_token_id';

	/**
	 * 換卡流水號（每次嘗試 +1）
	 *
	 * @var string
	 */
	public const META_SEQ = '_payuni_change_seq';

	/**
	 * 換卡嘗試紀錄：MerTradeNo => [ status, time, trade_nos ]
	 *
	 * @var string
	 */
	public const META_ATTEMPTS = '_payuni_change_attempts';

	/**
	 * 等待 3D／背景通知的最新一次嘗試：[ mer_trade_no, time ]
	 *
	 * @var string
	 */
	public const META_PENDING = '_payuni_change_pending';

	/**
	 * 換卡 MerTradeNo 格式 `{訂閱ID}-C{n}`
	 *
	 * @var string
	 */
	private const MER_TRADE_NO_PATTERN = '/^(\d+)-C(\d+)$/';

	/**
	 * Attempts 最多保留筆數
	 *
	 * @var int
	 */
	private const MAX_ATTEMPTS = 10;

	/**
	 * 綁卡授權金額
	 *
	 * @var int
	 */
	private const BINDING_AMOUNT = 5;

	/**
	 * 拒絕紀錄的金流代號
	 *
	 * @var string
	 */
	private const PROVIDER = 'payuni-v3';

	/**
	 * 3D 導向只放行這個網域（含子網域）
	 *
	 * @var string
	 */
	private const REDIRECT_DOMAIN = 'payuni.com.tw';

	/**
	 * WCS 的延後「全部訂閱」旗標
	 *
	 * @var string
	 */
	private const META_DELAYED_ALL = '_delayed_update_payment_method_all';

	/**
	 * 可綁到 V3 訂閱的 token 閘道
	 *
	 * @var string[]
	 */
	private const ALLOWED_TOKEN_GATEWAYS = [ self::V3_GATEWAY_ID, 'payuni-credit-v3' ];

	/**
	 * 本次請求已同步完成換卡的訂閱 ID（WCS 第二次問 filter 時回 true，讓它處理「全部訂閱」）
	 *
	 * @var array<int, bool>
	 */
	private static array $completed = [];

	/**
	 * 本次請求要放行的 3D 導向網域
	 *
	 * @var string[]
	 */
	private static array $redirect_hosts = [];

	/**
	 * 3D 路徑回 success 後，WCS 會加一則「付款方式已變更」；設為 true 時擋下那一則（只擋一次）
	 *
	 * @var bool
	 */
	private static bool $suppress_wcs_notice = false;

	/**
	 * 3D 導回端點（wc-api）
	 *
	 * @var string
	 */
	public const RETURN_ENDPOINT = 'payuni_change_payment_return';

	/**
	 * 導回後檢視頁顯示換卡結果的旗標
	 *
	 * @var string
	 */
	public const RETURN_FLAG = 'payuni_change';

	/**
	 * 註冊 Hooks
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		\add_filter( 'woocommerce_subscription_payment_meta', [ __CLASS__, 'add_payment_meta' ], 10, 2 );
		\add_action(
			'woocommerce_subscription_validate_payment_meta_' . self::V3_GATEWAY_ID,
			[ __CLASS__, 'validate_payment_meta' ],
			10,
			2
		);
		\add_filter( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', [ __CLASS__, 'filter_update_via_pay_shortcode' ], 10, 3 );
		\add_filter( 'allowed_redirect_hosts', [ __CLASS__, 'allow_redirect_hosts' ] );
		\add_filter( 'wc_subscriptions_renewal_order_data', [ __CLASS__, 'strip_renewal_order_data' ] );
		\add_action( 'template_redirect', [ __CLASS__, 'maybe_show_pending_notice' ] );
		\add_action( 'woocommerce_api_' . self::RETURN_ENDPOINT, [ __CLASS__, 'handle_return' ] );
		\add_filter( 'woocommerce_add_success', [ __CLASS__, 'filter_wcs_success_notice' ] );
	}

	/**
	 * 註冊 V3 的付款 meta 欄位
	 *
	 * @param array $payment_meta 各閘道的付款 meta
	 * @param mixed $subscription 訂閱物件
	 *
	 * @return array
	 */
	public static function add_payment_meta( $payment_meta, $subscription ): array {
		$payment_meta = \is_array( $payment_meta ) ? $payment_meta : [];
		$value        = $subscription instanceof \WC_Order ? (string) $subscription->get_meta( self::META_TOKEN_ID ) : '';

		$payment_meta[ self::V3_GATEWAY_ID ] = [
			'post_meta' => [
				self::META_TOKEN_ID => [
					'value' => $value,
					'label' => 'PayUni V3 Token ID',
				],
			],
		];

		return $payment_meta;
	}

	/**
	 * 驗證 V3 付款 meta
	 *
	 * 空值通過（保留後台手動改成 V3、續扣走 fallback 的做法）；
	 * 非空必須是訂閱客戶自己的 V3 token。
	 *
	 * @param mixed $payment_meta 此閘道的付款 meta
	 * @param mixed $subscription 訂閱物件
	 *
	 * @return void
	 * @throws \Exception When the token is not the subscriber's own PayUni V3 token.
	 */
	public static function validate_payment_meta( $payment_meta, $subscription ): void {
		$raw = \is_array( $payment_meta ) ? ( $payment_meta['post_meta'][ self::META_TOKEN_ID ]['value'] ?? '' ) : '';
		$raw = \is_scalar( $raw ) ? \trim( (string) $raw ) : '';

		if ( '' === $raw ) {
			return;
		}

		if ( ! \ctype_digit( $raw ) || ! $subscription instanceof \WC_Order ) {
			throw new \Exception( 'PayUni V3 Token ID 格式不正確。' );
		}

		$customer_id = (int) $subscription->get_customer_id();
		$token       = \WC_Payment_Tokens::get( (int) $raw );

		if ( ! $token instanceof \WC_Payment_Token
			|| $customer_id <= 0
			|| (int) $token->get_user_id() !== $customer_id
			|| ! \in_array( $token->get_gateway_id(), self::ALLOWED_TOKEN_GATEWAYS, true ) ) {
			throw new \Exception( 'PayUni V3 Token ID 無效：必須是此訂閱客戶自己的 PayUni V3 信用卡 Token。' );
		}
	}

	// ──────────────────────────────────────────────
	// 換卡請求（WCS order-pay?change_payment_method=）
	// ──────────────────────────────────────────────

	/**
	 * 目前是否為 WCS 換付款方式請求
	 *
	 * @return bool
	 */
	public static function is_change_request(): bool {
		if ( \class_exists( 'WC_Subscriptions_Change_Payment_Gateway' )
			&& \WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只判斷情境，不處理資料；WCS 送出時會驗 _wcsnonce。
		return isset( $_GET['change_payment_method'] );
	}

	/**
	 * 目前頁面是否為訂閱換卡頁
	 *
	 * 換卡頁 = 結帳頁 + order-pay endpoint + WCS 換卡請求。
	 * 閘道過濾與前端參數（UseTokenType、PAGE_CONTEXT）共用這個判斷，避免兩邊認定不一致。
	 *
	 * @return bool
	 */
	public static function is_change_payment_page(): bool {
		return \is_checkout() && \is_wc_endpoint_url( 'order-pay' ) && self::is_change_request();
	}

	/**
	 * 啟動換卡：送出 5 元綁卡授權
	 *
	 * 同步授權成功 → complete()；需要 3D → 回傳 PayUni 驗證頁、留下 pending 等背景通知；
	 * 其他情況 → fail()。付款方式只會在 complete() 成功時才改。
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 *
	 * @return array{result: string, redirect?: string}
	 */
	public static function start( \WC_Subscription $subscription ): array {
		$customer_id = (int) $subscription->get_customer_id();
		if ( $customer_id <= 0 || \get_current_user_id() !== $customer_id ) {
			\wc_add_notice( '無法更換付款方式：請先登入此訂閱所屬的會員帳號。', 'error' );
			return [ 'result' => 'failure' ];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WCS change_payment_method_via_pay_shortcode() 已驗過 _wcsnonce 與訂單 key。
		$sdk_token = isset( $_POST['sdk_token_tmp'] ) ? \sanitize_text_field( \wp_unslash( $_POST['sdk_token_tmp'] ) ) : '';
		if ( '' === $sdk_token ) {
			\wc_add_notice( '未取得信用卡資料，請重新輸入卡片後再送出。', 'error' );
			return [ 'result' => 'failure' ];
		}

		self::$suppress_wcs_notice = false;

		// 上一次沒完成的嘗試可能留下「全部訂閱」旗標；本次若有勾選，WCS 會在 process_payment 回傳後重設。
		$subscription->delete_meta_data( self::META_DELAYED_ALL );

		$mer_trade_no = self::next_mer_trade_no( $subscription );
		self::set_attempt_status( $subscription, $mer_trade_no, 'pending' );
		$subscription->update_meta_data(
			self::META_PENDING,
			[
				'mer_trade_no' => $mer_trade_no,
				'time'         => \time(),
			]
		);
		$subscription->save();

		try {
			$result = ( new SubscriptionHandler() )->request_binding_trade(
				$subscription,
				$mer_trade_no,
				$sdk_token,
				self::get_return_url( $subscription )
			);
		} catch ( \Throwable $e ) {
			self::fail( $subscription, $mer_trade_no, '綁卡請求失敗：' . $e->getMessage(), 'sync' );
			\wc_add_notice( '更換付款方式失敗：暫時無法連線到金流服務，請稍後再試。', 'error' );
			return [ 'result' => 'failure' ];
		}

		$result['MerTradeNo'] = $mer_trade_no;
		$trade_no             = (string) ( $result['TradeNo'] ?? '' );
		$url_3d               = (string) ( $result['URL'] ?? '' );

		if ( '' !== $trade_no ) {
			if ( self::complete( $subscription, $result, 'sync' ) ) {
				self::$completed[ $subscription->get_id() ] = true;

				return [
					'result'   => 'success',
					'redirect' => $subscription->get_view_order_url(),
				];
			}

			\wc_add_notice( '信用卡綁定未完成，付款方式未變更。請稍後再試或改用其他信用卡。', 'error' );
			return [ 'result' => 'failure' ];
		}

		if ( '' !== $url_3d && self::allow_redirect_url( $url_3d ) ) {
			$subscription->add_order_note( '更換付款方式：已建立 PayUni 3D 驗證，等待持卡人完成驗證（' . \esc_html( $mer_trade_no ) . '）' );

			// WCS 收到 success 會加「付款方式已變更」，但 3D 還沒完成、付款方式也還沒改；
			// 結果改由導回後的檢視頁依 attempt 狀態提示（add_status_notice）。
			self::$suppress_wcs_notice = true;

			return [
				'result'   => 'success',
				'redirect' => $url_3d,
			];
		}

		$status  = (string) ( $result['Status'] ?? '' );
		$message = (string) ( $result['Message'] ?? '' );
		$reason  = \sprintf( 'PayUni 回應 %s：%s', $status ?: '未知狀態', $message ?: '未取得交易結果' );
		if ( '' !== $url_3d ) {
			$reason = '3D 驗證網址不是 PayUni 網域';
		}
		self::fail( $subscription, $mer_trade_no, $reason, 'sync' );
		\wc_add_notice( \esc_html( \sprintf( '更換付款方式失敗（%s）：%s', $status ?: '未知狀態', $message ?: '未取得交易結果' ) ), 'error' );

		return [ 'result' => 'failure' ];
	}

	/**
	 * 綁卡授權成功 → 存 token、改付款方式、排程取消 5 元
	 *
	 * 依 MerTradeNo／TradeNo 冪等：已完成的嘗試直接回 true。
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 * @param array            $result       解密後的交易結果（需含 MerTradeNo、TradeNo）
	 * @param string           $source       'sync'（同步授權）或 'webhook'（背景通知）
	 *
	 * @return bool 換卡是否完成
	 */
	public static function complete( \WC_Subscription $subscription, array $result, string $source ): bool {
		$mer_trade_no = (string) ( $result['MerTradeNo'] ?? '' );
		$trade_no     = (string) ( $result['TradeNo'] ?? '' );
		$attempt      = self::get_attempts( $subscription )[ $mer_trade_no ] ?? [];

		if ( 'completed' === ( $attempt['status'] ?? '' ) ) {
			self::log( 'info', "#{$subscription->get_id()} 換卡（payment_method_change）已完成，略過重複結果", $subscription, $mer_trade_no, $trade_no );
			return true;
		}

		if ( 'SUCCESS' !== ( $result['Status'] ?? '' ) || '' === $trade_no ) {
			self::fail( $subscription, $mer_trade_no, '授權結果不是成功', $source );
			return false;
		}

		// 5 元一定要取消，不論後面綁卡成不成功
		self::schedule_cancel_once( $subscription, $mer_trade_no, $trade_no );

		$customer_id = (int) $subscription->get_customer_id();
		$token_id    = null;
		if ( $customer_id > 0 ) {
			$token_id = ( new TradeHandler() )->save_payment_token(
				$customer_id,
				(string) ( $result['CreditHash'] ?? '' ),
				(string) ( $result['Card6No'] ?? '' ),
				(string) ( $result['Card4No'] ?? '' ),
				(string) ( $result['CreditLife'] ?? '' ),
				self::V3_GATEWAY_ID
			);
		}

		if ( ! $token_id ) {
			self::fail( $subscription, $mer_trade_no, '綁卡未完成：PayUni 授權成功但未回傳 CreditHash，信用卡 Token 未建立', $source );
			return false;
		}

		if ( ! \class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) ) {
			self::fail( $subscription, $mer_trade_no, 'WooCommerce Subscriptions 未載入，無法更新付款方式', $source );
			return false;
		}

		\WC_Payment_Tokens::set_users_default( $customer_id, $token_id );

		self::set_attempt_status( $subscription, $mer_trade_no, 'completed' );
		self::clear_pending( $subscription, $mer_trade_no );

		\WC_Subscriptions_Change_Payment_Gateway::update_payment_method(
			$subscription,
			self::V3_GATEWAY_ID,
			[
				'post_meta' => [
					self::META_TOKEN_ID => [ 'value' => (string) $token_id ],
				],
			]
		);

		// update_payment_method() 會自己吞掉例外並加 notice，只能看結果判斷
		if ( self::V3_GATEWAY_ID !== $subscription->get_payment_method()
			|| (string) $token_id !== (string) $subscription->get_meta( self::META_TOKEN_ID ) ) {
			self::fail( $subscription, $mer_trade_no, '更新訂閱付款方式失敗', $source );
			return false;
		}

		self::apply_gateway_title( $subscription );
		$subscription->set_requires_manual_renewal( false );
		$subscription->add_order_note(
			\sprintf( 'PayUni V3 換卡完成（%s）：已綁定新的信用卡，之後續扣將使用此卡。', self::source_label( $source ) )
		);
		$subscription->save();

		self::log( 'info', "#{$subscription->get_id()} 換卡（payment_method_change）完成", $subscription, $mer_trade_no, $trade_no );

		// 3D 路徑：WCS 在客戶勾「同時更新所有訂閱」時留下延後旗標，由我們在綁卡成功後補做
		if ( 'webhook' === $source && self::V3_GATEWAY_ID === $subscription->get_meta( self::META_DELAYED_ALL ) ) {
			\WC_Subscriptions_Change_Payment_Gateway::update_all_payment_methods_from_subscription( $subscription, self::V3_GATEWAY_ID );
		}

		return true;
	}

	/**
	 * 換卡失敗：標記 attempt、清 pending、寫訂閱備註；付款方式不動
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 * @param string           $mer_trade_no 本次 MerTradeNo
	 * @param string           $reason       原因（不可含卡號、CreditHash、token）
	 * @param string           $source       'sync' 或 'webhook'
	 *
	 * @return void
	 */
	public static function fail( \WC_Subscription $subscription, string $mer_trade_no, string $reason, string $source = 'sync' ): void {
		if ( '' !== $mer_trade_no ) {
			self::set_attempt_status( $subscription, $mer_trade_no, 'failed' );
			self::clear_pending( $subscription, $mer_trade_no );
		}

		$subscription->add_order_note(
			\esc_html( \sprintf( 'PayUni V3 換卡失敗（%s）：%s。付款方式維持不變。', self::source_label( $source ), $reason ) )
		);
		$subscription->save();

		self::log( 'error', "#{$subscription->get_id()} 換卡（payment_method_change）失敗：{$reason}", $subscription, $mer_trade_no, '' );
	}

	// ──────────────────────────────────────────────
	// 背景通知
	// ──────────────────────────────────────────────

	/**
	 * MerTradeNo 是否為換卡格式 `{ID}-C{n}`
	 *
	 * @param string $mer_trade_no MerTradeNo
	 *
	 * @return bool
	 */
	public static function is_change_mer_trade_no( string $mer_trade_no ): bool {
		return 1 === \preg_match( self::MER_TRADE_NO_PATTERN, $mer_trade_no );
	}

	/**
	 * 依 MerTradeNo 找出發起這次換卡的訂閱
	 *
	 * 必須是換卡格式、ID 是訂閱、且 MerTradeNo 在該訂閱的 attempts 裡。
	 *
	 * @param string $mer_trade_no MerTradeNo
	 *
	 * @return \WC_Subscription|null
	 */
	public static function match_notify( string $mer_trade_no ): ?\WC_Subscription {
		if ( ! \preg_match( self::MER_TRADE_NO_PATTERN, $mer_trade_no, $m ) || ! \function_exists( 'wcs_get_subscription' ) ) {
			return null;
		}

		$subscription = \wcs_get_subscription( (int) $m[1] );
		if ( ! $subscription instanceof \WC_Subscription ) {
			return null;
		}

		return \array_key_exists( $mer_trade_no, self::get_attempts( $subscription ) ) ? $subscription : null;
	}

	/**
	 * 換卡背景通知分流入口（Bootstrap::handle_notify() 呼叫，HashInfo 已驗過）
	 *
	 * @param array  $trade_result 解密後的交易結果
	 * @param string $outer_mer_id 外層 MerID
	 *
	 * @return bool 是否受理（true 回 success 讓 PayUni 停止重送）
	 */
	public static function route_notify( array $trade_result, string $outer_mer_id ): bool {
		$mer_trade_no = (string) ( $trade_result['MerTradeNo'] ?? '' );
		$subscription = self::match_notify( $mer_trade_no );

		if ( ! $subscription ) {
			self::reject( null, $mer_trade_no, '找不到對應的換卡嘗試', (string) ( $trade_result['TradeNo'] ?? '' ) );
			return false;
		}

		return self::handle_notify( $subscription, $trade_result, $outer_mer_id );
	}

	/**
	 * 處理換卡背景通知
	 *
	 * - MerID、金額（必須 5 元）不符 → 拒絕，不做任何變更。
	 * - 失敗 → fail()（已成功過的嘗試不會被降級）。
	 * - 成功但不是最新一次嘗試 → 只排程取消 5 元。
	 * - 成功且是最新一次 → complete()。
	 * - 同一筆 TradeNo 重複通知 → 略過。
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 * @param array            $trade_result 解密後的交易結果
	 * @param string           $outer_mer_id 外層 MerID
	 *
	 * @return bool 是否受理
	 */
	public static function handle_notify( \WC_Subscription $subscription, array $trade_result, string $outer_mer_id ): bool {
		$mer_trade_no = (string) ( $trade_result['MerTradeNo'] ?? '' );
		$trade_no     = (string) ( $trade_result['TradeNo'] ?? '' );
		$status       = (string) ( $trade_result['Status'] ?? '' );

		$error = self::get_notify_error( $trade_result, $outer_mer_id );
		if ( '' !== $error ) {
			self::reject( $subscription, $mer_trade_no, $error, $trade_no );
			return false;
		}

		$attempt   = self::get_attempts( $subscription )[ $mer_trade_no ] ?? [];
		$seen      = \in_array( $trade_no, (array) ( $attempt['trade_nos'] ?? [] ), true );
		$completed = 'completed' === ( $attempt['status'] ?? '' );

		if ( 'SUCCESS' !== $status ) {
			if ( $completed || ! empty( $attempt['trade_nos'] ) || 'failed' === ( $attempt['status'] ?? '' ) ) {
				self::log( 'info', "#{$subscription->get_id()} 換卡（payment_method_change）失敗通知已略過：嘗試已有結果", $subscription, $mer_trade_no, $trade_no );
				return true;
			}

			$message = (string) ( $trade_result['Message'] ?? '' );
			self::fail( $subscription, $mer_trade_no, \sprintf( 'PayUni 授權失敗（%s）：%s', $status ?: '未知狀態', $message ?: '未提供原因' ), 'webhook' );
			return true;
		}

		if ( $completed || $seen ) {
			self::log( 'info', "#{$subscription->get_id()} 換卡（payment_method_change）重複通知，已略過", $subscription, $mer_trade_no, $trade_no );
			return true;
		}

		if ( self::get_pending_mer_trade_no( $subscription ) !== $mer_trade_no ) {
			// 舊的嘗試（已被新的取代或已失敗）：不綁定，但 5 元一定要取消
			self::schedule_cancel_once( $subscription, $mer_trade_no, $trade_no );
			if ( 'pending' === ( $attempt['status'] ?? '' ) ) {
				self::set_attempt_status( $subscription, $mer_trade_no, 'superseded' );
				$subscription->save();
			}
			self::log( 'info', "#{$subscription->get_id()} 換卡（payment_method_change）舊嘗試成功通知：只取消 5 元授權，不綁定", $subscription, $mer_trade_no, $trade_no );
			return true;
		}

		self::complete( $subscription, $trade_result, 'webhook' );

		return true;
	}

	// ──────────────────────────────────────────────
	// WCS / WP filters
	// ──────────────────────────────────────────────

	/**
	 * WCS 是否在 process_payment 之前／之後直接改付款方式
	 *
	 * V3 延後到綁卡成功：同一請求內已同步完成的訂閱回 true，讓 WCS 照常處理「同時更新所有訂閱」；
	 * 其餘回 false（3D 路徑時 WCS 會改寫延後旗標，由 complete() 收尾）。
	 *
	 * @param mixed  $update             WCS 預設值
	 * @param string $new_payment_method 新付款方式
	 * @param mixed  $subscription       訂閱
	 *
	 * @return mixed
	 */
	public static function filter_update_via_pay_shortcode( $update, $new_payment_method, $subscription ) {
		if ( self::V3_GATEWAY_ID !== $new_payment_method ) {
			return $update;
		}

		$id = $subscription instanceof \WC_Order ? (int) $subscription->get_id() : 0;

		return $id > 0 && ! empty( self::$completed[ $id ] );
	}

	/**
	 * 本次請求放行 PayUni 3D 網域給 wp_safe_redirect()
	 *
	 * @param mixed $hosts 允許的網域
	 *
	 * @return array
	 */
	public static function allow_redirect_hosts( $hosts ): array {
		$hosts = \is_array( $hosts ) ? $hosts : [];

		return \array_values( \array_unique( \array_merge( $hosts, self::$redirect_hosts ) ) );
	}

	/**
	 * 續訂單不複製換卡暫存 meta（`_payuni_token_id` 保留）
	 *
	 * @param mixed $data 要複製的 meta（key => value）
	 *
	 * @return mixed
	 */
	public static function strip_renewal_order_data( $data ) {
		if ( ! \is_array( $data ) ) {
			return $data;
		}

		foreach ( \array_keys( $data ) as $key ) {
			$key = (string) $key;
			if ( \str_starts_with( $key, '_payuni_change_' ) || '_payuni_mer_trade_no' === $key ) {
				unset( $data[ $key ] );
			}
		}

		return $data;
	}

	/**
	 * 3D 路徑時擋下 WCS 接著加的「付款方式已變更」（只擋一則）
	 *
	 * @param mixed $message 成功訊息
	 *
	 * @return mixed 空字串表示不加入（wc_add_notice 會略過空訊息）
	 */
	public static function filter_wcs_success_notice( $message ) {
		if ( ! self::$suppress_wcs_notice || ! \is_string( $message ) ) {
			return $message;
		}

		// 只認 WCS change_payment_method_via_pay_shortcode() 的三種訊息，其他成功訊息不受影響
		// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- 要比對 WCS 自己翻譯後的字串。
		$wcs_messages = [
			\__( 'Payment method updated.', 'woocommerce-subscriptions' ),
			\__( 'Payment method added.', 'woocommerce-subscriptions' ),
			\__( 'Payment method updated for all your current subscriptions.', 'woocommerce-subscriptions' ),
		];
		// phpcs:enable WordPress.WP.I18n.TextDomainMismatch
		if ( ! \in_array( $message, $wcs_messages, true ) ) {
			return $message;
		}

		self::$suppress_wcs_notice = false;

		return '';
	}

	/**
	 * 訂閱檢視頁：依換卡嘗試狀態提示客戶
	 *
	 * @return void
	 */
	public static function maybe_show_pending_notice(): void {
		if ( ! \function_exists( 'is_wc_endpoint_url' ) || ! \function_exists( 'wcs_get_subscription' )
			|| ! \is_wc_endpoint_url( 'view-subscription' ) ) {
			return;
		}

		$subscription_id = \absint( \get_query_var( 'view-subscription' ) );
		$user_id         = \get_current_user_id();
		if ( ! $subscription_id || $user_id <= 0 ) {
			return;
		}

		// 只提示訂閱本人
		$subscription = \wcs_get_subscription( $subscription_id );
		if ( ! $subscription instanceof \WC_Subscription || (int) $subscription->get_customer_id() !== $user_id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只決定要不要顯示提示，不改資料。
		self::add_status_notice( $subscription, isset( $_GET[ self::RETURN_FLAG ] ) );
	}

	/**
	 * 依最新一次換卡嘗試加提示
	 *
	 * - 等待 3D／背景通知中：一律提示處理中。
	 * - 剛從 PayUni 導回（$after_return）：完成 → 成功；失敗 → 失敗且說明付款方式未變更。
	 *   一般瀏覽不重複提示完成／失敗。
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 * @param bool             $after_return 是否剛從 3D 導回
	 *
	 * @return void
	 */
	public static function add_status_notice( \WC_Subscription $subscription, bool $after_return ): void {
		if ( '' !== self::get_pending_mer_trade_no( $subscription ) ) {
			self::add_notice_once( '信用卡綁定處理中：完成 3D 驗證後，付款方式會在收到金流通知後自動更新，請稍後重新整理此頁。', 'notice' );
			return;
		}

		if ( ! $after_return ) {
			return;
		}

		$seq     = (int) $subscription->get_meta( self::META_SEQ );
		$attempt = $seq > 0 ? ( self::get_attempts( $subscription )[ $subscription->get_id() . '-C' . $seq ] ?? [] ) : [];
		$status  = (string) ( $attempt['status'] ?? '' );

		if ( 'completed' === $status ) {
			self::add_notice_once( '付款方式已變更：之後的續扣將使用新的信用卡。', 'success' );
		} elseif ( 'failed' === $status ) {
			self::add_notice_once( '信用卡驗證未完成，付款方式未變更。請重新操作或改用其他信用卡。', 'error' );
		}
	}

	/**
	 * 3D 導回端點（ReturnURL）：PayUni 以跨站 POST 導回，再 303 導向訂閱檢視頁
	 *
	 * 為什麼不直接用檢視頁當 ReturnURL：跨站 POST 不會帶 SameSite=Lax 的登入 cookie，顧客會看到登入表單；
	 * 303 之後瀏覽器改用 GET 頂層導覽，Lax cookie 會帶上。
	 *
	 * 安全：
	 * - 導向目標只由伺服器依網址上的訂閱決定，不讀任何導向參數（防 open redirect）。
	 * - 不信任 POST 內容：只有通過與背景通知相同的 HashInfo 驗章與解密、MerTradeNo 屬於該訂閱的換卡嘗試，
	 *   才交給 handle_notify()（同樣驗 MerID、5 元、attempts，且依 TradeNo 冪等）。其餘一律忽略。
	 *   這也讓背景通知漏收時，顧客導回就能完成綁卡並排程釋放 5 元。
	 *
	 * @return void
	 */
	public static function handle_return(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- 金流商跨站導回沒有 nonce，資料以 HashInfo 簽章驗證。
		$subscription_id = isset( $_GET['subscription_id'] ) ? \absint( \wp_unslash( $_GET['subscription_id'] ) ) : 0;
		$post            = \wp_unslash( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification

		$subscription = ( $subscription_id > 0 && \function_exists( 'wcs_get_subscription' ) ) ? \wcs_get_subscription( $subscription_id ) : null;
		if ( ! $subscription instanceof \WC_Subscription ) {
			self::redirect_and_exit( \wc_get_page_permalink( 'myaccount' ) );
			return;
		}

		if ( \is_array( $post ) && ! empty( $post['EncryptInfo'] ) ) {
			self::process_return_post( $subscription, $post );
		}

		self::redirect_and_exit( \add_query_arg( self::RETURN_FLAG, '1', $subscription->get_view_order_url() ) );
	}

	/**
	 * 3D 導回網址（不需登入的 wc-api 端點，帶訂閱 ID）
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 *
	 * @return string
	 */
	public static function get_return_url( \WC_Subscription $subscription ): string {
		return \add_query_arg( 'subscription_id', $subscription->get_id(), \WC()->api_request_url( self::RETURN_ENDPOINT ) );
	}

	/**
	 * 處理導回帶的交易結果（驗章通過且屬於此訂閱才處理）
	 *
	 * @param \WC_Subscription $subscription 網址上的訂閱
	 * @param array            $post         POST 內容
	 *
	 * @return void
	 */
	private static function process_return_post( \WC_Subscription $subscription, array $post ): void {
		try {
			$trade_result = ( new TradeHandler() )->process_notify( $post );
		} catch ( \Throwable $e ) {
			self::reject( $subscription, '', '導回內容驗證失敗：' . $e->getMessage(), '' );
			return;
		}

		$mer_trade_no = (string) ( $trade_result['MerTradeNo'] ?? '' );
		$matched      = self::is_change_mer_trade_no( $mer_trade_no ) ? self::match_notify( $mer_trade_no ) : null;
		if ( ! $matched || $matched->get_id() !== $subscription->get_id() ) {
			self::reject( $subscription, $mer_trade_no, '導回內容不屬於此訂閱的換卡嘗試', (string) ( $trade_result['TradeNo'] ?? '' ) );
			return;
		}

		$outer_mer_id = \is_string( $post['MerID'] ?? null ) ? (string) $post['MerID'] : '';
		self::handle_notify( $matched, $trade_result, $outer_mer_id );
	}

	/**
	 * 303 導向並結束請求（wc-api 不 exit 會被 status_header(200) 蓋掉）
	 *
	 * @param string $url 站內網址
	 *
	 * @return void
	 */
	private static function redirect_and_exit( string $url ): void {
		\wp_safe_redirect( $url, 303 );
		if ( \apply_filters( 'woomp_payuni_change_return_exit', true ) ) {
			exit;
		}
	}

	/**
	 * 同一則提示只加一次
	 *
	 * @param string $message 訊息
	 * @param string $type    notice／success／error
	 *
	 * @return void
	 */
	private static function add_notice_once( string $message, string $type ): void {
		if ( ! \wc_has_notice( $message, $type ) ) {
			\wc_add_notice( $message, $type );
		}
	}

	// ──────────────────────────────────────────────
	// 內部工具
	// ──────────────────────────────────────────────

	/**
	 * 產生本次 MerTradeNo `{ID}-C{n}` 並燒號
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 *
	 * @return string
	 */
	private static function next_mer_trade_no( \WC_Subscription $subscription ): string {
		$seq = (int) $subscription->get_meta( self::META_SEQ ) + 1;
		$subscription->update_meta_data( self::META_SEQ, (string) $seq );

		return $subscription->get_id() . '-C' . $seq;
	}

	/**
	 * 讀 attempts（舊格式的純字串狀態轉成陣列）
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 *
	 * @return array<string, array{status?: string, time?: int, trade_nos?: string[]}>
	 */
	private static function get_attempts( \WC_Subscription $subscription ): array {
		$raw = $subscription->get_meta( self::META_ATTEMPTS );
		if ( ! \is_array( $raw ) ) {
			return [];
		}

		$attempts = [];
		foreach ( $raw as $mer_trade_no => $attempt ) {
			$attempts[ (string) $mer_trade_no ] = \is_array( $attempt ) ? $attempt : [ 'status' => (string) $attempt ];
		}

		return $attempts;
	}

	/**
	 * 更新（或新增）一筆 attempt 的狀態，只保留最近 MAX_ATTEMPTS 筆；不 save
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 * @param string           $mer_trade_no MerTradeNo
	 * @param string           $status       pending／failed／completed／superseded
	 *
	 * @return void
	 */
	private static function set_attempt_status( \WC_Subscription $subscription, string $mer_trade_no, string $status ): void {
		$attempts = self::get_attempts( $subscription );
		$attempt  = $attempts[ $mer_trade_no ] ?? [ 'time' => \time() ];

		$attempt['status']         = $status;
		$attempts[ $mer_trade_no ] = $attempt;

		$subscription->update_meta_data( self::META_ATTEMPTS, \array_slice( $attempts, -self::MAX_ATTEMPTS, null, true ) );
	}

	/**
	 * 每個 TradeNo 只排一次 5 元取消；記在 attempt 的 trade_nos 並 save
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 * @param string           $mer_trade_no MerTradeNo
	 * @param string           $trade_no     PayUni 交易編號
	 *
	 * @return void
	 */
	private static function schedule_cancel_once( \WC_Subscription $subscription, string $mer_trade_no, string $trade_no ): void {
		if ( '' === $trade_no ) {
			return;
		}

		$attempts  = self::get_attempts( $subscription );
		$attempt   = $attempts[ $mer_trade_no ] ?? [
			'status' => 'pending',
			'time'   => \time(),
		];
		$trade_nos = \array_map( 'strval', (array) ( $attempt['trade_nos'] ?? [] ) );
		if ( \in_array( $trade_no, $trade_nos, true ) ) {
			return;
		}

		( new SubscriptionHandler() )->schedule_cancel_authorization( $trade_no, (int) $subscription->get_id() );

		$trade_nos[]               = $trade_no;
		$attempt['trade_nos']      = $trade_nos;
		$attempts[ $mer_trade_no ] = $attempt;
		$subscription->update_meta_data( self::META_ATTEMPTS, \array_slice( $attempts, -self::MAX_ATTEMPTS, null, true ) );
		$subscription->save();
	}

	/**
	 * 最新一次嘗試的 MerTradeNo（沒有 pending 為空字串）
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 *
	 * @return string
	 */
	private static function get_pending_mer_trade_no( \WC_Subscription $subscription ): string {
		$pending = $subscription->get_meta( self::META_PENDING );

		return \is_array( $pending ) ? (string) ( $pending['mer_trade_no'] ?? '' ) : '';
	}

	/**
	 * Pending 是這次嘗試才清掉（舊嘗試的結果不可清掉新的 pending）；不 save
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 * @param string           $mer_trade_no MerTradeNo
	 *
	 * @return void
	 */
	private static function clear_pending( \WC_Subscription $subscription, string $mer_trade_no ): void {
		if ( self::get_pending_mer_trade_no( $subscription ) === $mer_trade_no ) {
			$subscription->delete_meta_data( self::META_PENDING );
		}
	}

	/**
	 * 背景通知的商店代號與金額檢查
	 *
	 * @param array  $trade_result 解密後的交易結果
	 * @param string $outer_mer_id 外層 MerID
	 *
	 * @return string 錯誤原因；空字串表示通過
	 */
	private static function get_notify_error( array $trade_result, string $outer_mer_id ): string {
		$expected = \strtoupper( \trim( SettingDTO::instance()->merchant_id ) );
		$inner    = \strtoupper( \trim( (string) ( $trade_result['MerID'] ?? '' ) ) );
		$outer    = \strtoupper( \trim( $outer_mer_id ) );

		if ( '' === $expected || ( '' === $inner && '' === $outer ) ) {
			return 'MerID 缺失或未設定';
		}
		if ( '' !== $inner && ! \hash_equals( $expected, $inner ) ) {
			return 'MerID 與設定不符';
		}
		if ( '' !== $outer && ! \hash_equals( $expected, $outer ) ) {
			return '外層 MerID 與設定不符';
		}

		if ( 'SUCCESS' === ( $trade_result['Status'] ?? '' ) ) {
			$amount = $trade_result['TradeAmt'] ?? null;
			if ( ! \is_numeric( $amount ) || \abs( (float) $amount - self::BINDING_AMOUNT ) >= 0.01 ) {
				return '金額不是換卡綁卡的 5 元';
			}
			if ( '' === (string) ( $trade_result['TradeNo'] ?? '' ) ) {
				return '缺少 TradeNo';
			}
		}

		return '';
	}

	/**
	 * 拒絕換卡通知（寫 error log，訊息含「換卡」與 payment_method_change）
	 *
	 * @param \WC_Subscription|null $subscription 訂閱（找不到為 null）
	 * @param string                $mer_trade_no MerTradeNo
	 * @param string                $reason       原因
	 * @param string                $trade_no     PayUni 交易編號
	 *
	 * @return void
	 */
	private static function reject( ?\WC_Subscription $subscription, string $mer_trade_no, string $reason, string $trade_no ): void {
		\Woomp_Payment_Callback_Guard::reject(
			self::PROVIDER,
			'換卡通知驗證失敗：' . $reason,
			[
				'handler'         => 'payment_method_change',
				'subscription_id' => $subscription ? $subscription->get_id() : 0,
				'mer_trade_no'    => $mer_trade_no,
				'trade_no'        => $trade_no,
			]
		);
	}

	/**
	 * 只放行 https 的 PayUni 網域（含子網域），並記入本次請求的白名單
	 *
	 * @param string $url 3D 驗證頁網址
	 *
	 * @return bool
	 */
	private static function allow_redirect_url( string $url ): bool {
		$parts  = \wp_parse_url( $url );
		$scheme = \strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = \strtolower( (string) ( $parts['host'] ?? '' ) );

		if ( 'https' !== $scheme
			|| ( self::REDIRECT_DOMAIN !== $host && ! \str_ends_with( $host, '.' . self::REDIRECT_DOMAIN ) ) ) {
			return false;
		}

		if ( ! \in_array( $host, self::$redirect_hosts, true ) ) {
			self::$redirect_hosts[] = $host;
		}

		return true;
	}

	/**
	 * 付款方式標題改用閘道設定的標題
	 *
	 * 背景通知時購物車是空的，WCS 取「可用閘道」拿不到 V3，標題會變成閘道 ID。
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 *
	 * @return void
	 */
	private static function apply_gateway_title( \WC_Subscription $subscription ): void {
		$gateways = \WC()->payment_gateways() ? \WC()->payment_gateways()->payment_gateways() : [];
		if ( isset( $gateways[ self::V3_GATEWAY_ID ] ) ) {
			$subscription->set_payment_method_title( $gateways[ self::V3_GATEWAY_ID ]->get_title() );
		}
	}

	/**
	 * 來源顯示文字
	 *
	 * @param string $source 'sync' 或 'webhook'
	 *
	 * @return string
	 */
	private static function source_label( string $source ): string {
		return 'webhook' === $source ? '背景通知' : '同步授權';
	}

	/**
	 * 寫 log（context 不含卡號、CreditHash、token）
	 *
	 * @param string           $level        等級
	 * @param string           $message      訊息
	 * @param \WC_Subscription $subscription 訂閱
	 * @param string           $mer_trade_no MerTradeNo
	 * @param string           $trade_no     PayUni 交易編號
	 *
	 * @return void
	 */
	private static function log( string $level, string $message, \WC_Subscription $subscription, string $mer_trade_no, string $trade_no ): void {
		\do_action(
			'woomp_payuni_log',
			$level,
			$message,
			[
				'handler'         => 'payment_method_change',
				'subscription_id' => $subscription->get_id(),
				'mer_trade_no'    => $mer_trade_no,
				'trade_no'        => $trade_no,
			]
		);
	}
}

<?php

declare(strict_types=1);

namespace J7\Payuni;

use J7\Payuni\Contracts\DTOs\SdkDTO;
use J7\Payuni\Contracts\DTOs\SettingDTO;
use J7\Payuni\Domains\Subscription\SubscriptionBootstrap;
use J7\Payuni\Infrastructure\Http\HttpClient;
use J7\Payuni\Infrastructure\Http\TradeHandler;
use J7\Payuni\Shared\Enums\EMode;
use J7\Payuni\Shared\Utils\OrderUtils;
use PAYUNI\Gateways\CreditV3;

/**
 * PayUni V3 Bootstrap
 *
 * @description 負責初始化 PayUni UNi Embed 付款功能
 */
final class Bootstrap
{

	private const LOG_SOURCE = 'payuni_payment_v3';

	/**
	 * 註冊所有 hooks
	 *
	 * @return void
	 */
	public static function register_hooks(): void
	{
		// 日誌處理
		\add_action('woomp_payuni_log', [__CLASS__, 'log_handler'], 10, 3);

		// 前端腳本
		\add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_checkout_scripts']);
		\add_filter('script_loader_tag', [__CLASS__, 'modify_script_type'], 10, 3);

		// 結帳流程
		\add_action('woocommerce_checkout_order_processed', [__CLASS__, 'save_payuni_card_hash'], 10, 3);
		\add_filter('woocommerce_checkout_fields', [OrderUtils::class, 'extend_checkout_field']);

		// 交易回調通知
		\add_action('woocommerce_api_payuni_notify', [__CLASS__, 'handle_notify']);

		// 定期定額訂閱
		SubscriptionBootstrap::register_hooks();
	}

	/**
	 * 紀錄 log
	 *
	 * @param string $level   日誌等級
	 * @param string $message 日誌訊息
	 * @param array  $args    額外參數
	 *
	 * @return void
	 */
	public static function log_handler(string $level = 'info', string $message = '', array $args = []): void
	{
		$setting = SettingDTO::instance();
		if (!$setting->enable_log) {
			return;
		}

		$logger = new \WC_Logger();

		$context = [
			'source' => self::LOG_SOURCE,
		];

		if ($args) {
			$context['args'] = $args;
		}

		$logger->log($level, $message, $context);
	}

	/**
	 * 載入結帳頁腳本
	 *
	 * @return void
	 */
	public static function enqueue_checkout_scripts(): void
	{
		if (!\is_checkout()) {
			return;
		}

		$setting = SettingDTO::instance();

		// 載入 CSS 樣式
		\wp_enqueue_style(
			'payuni-checkout-v3',
			WOOMP_PLUGIN_URL . 'includes/payuni/v3/Applications/assets/css/checkout.css',
			[],
			WOOMP_VERSION
		);

		// 根據環境載入對應的 SDK
		$sdk_url = $setting->mode === EMode::PROD ? 'https://vendor.payuni.com.tw/sdk/uni-payment.js' : 'https://sandbox-vendor.payuni.com.tw/sdk/uni-payment.js';

		\wp_enqueue_script(
			'uni-payment',
			$sdk_url,
			[],
			'3.0.0',
			true
		);

		\wp_enqueue_script(
			'uni-payment-checkout',
			WOOMP_PLUGIN_URL . 'includes/payuni/v3/Applications/assets/js/checkout.js',
			['uni-payment', 'jquery'],
			WOOMP_VERSION,
			true
		);

		$sdk_token        = '';
		$sdk_error_detail = '';
		try {
			$token = (new HttpClient())->get_sdk_token();
			$sdk = SdkDTO::from($token);
			$sdk_token = $sdk->Token;
		} catch (\Throwable $e) {
			\do_action('woomp_payuni_log', 'error', '取得 SDK Token 失敗: ' . $e->getMessage(), []);
			// 僅對具 manage_woocommerce 權限者揭露失敗原因，供管理員於結帳頁直接定位問題；一般顧客看通用訊息
			$sdk_error_detail = \current_user_can('manage_woocommerce') ? '取得 SDK Token 失敗: ' . $e->getMessage() : '';
		}

		\wp_localize_script(
			'uni-payment-checkout',
			'payuni_payment_v3_checkout_params',
			[
				'ENV'                 => $setting->mode === EMode::PROD ? 'P' : 'S',
				'SDK_TOKEN'           => $sdk_token,
				'INIT_ERROR_DETAIL'   => $sdk_error_detail,
				'USE_INST'            => $setting->enable_installment,
				'ENABLE_3D_AUTH'      => $setting->enable_3d_auth,
				'INST_OPTIONS'        => $setting->installment_options,
				'ENABLE_TOKENIZATION' => $setting->enable_tokenization,
				'ERROR_MAPPER'        => HttpClient::$error_mapper
			]
		);
	}

	/**
	 * 將特定的 script 設定為 type="module"
	 *
	 * @param string $tag    Script 標籤
	 * @param string $handle Script handle
	 * @param string $src    Script 來源
	 *
	 * @return string 修改後的標籤
	 */
	public static function modify_script_type($tag, $handle, $src): string
	{
		if ('uni-payment-checkout' !== $handle) {
			return $tag;
		}
		return '<script type="module" src="' . \esc_url($src) . '"></script>' . "\n";
	}

	/**
	 * 儲存信用卡 Hash 暫存資料
	 *
	 * @description 因為用戶的 CardHash 是由前端獲得，先暫存在 Order 上
	 *
	 * @param int       $order_id    訂單 ID
	 * @param array     $posted_data 表單資料
	 * @param \WC_Order $order       訂單物件
	 *
	 * @return void
	 */
	public static function save_payuni_card_hash(int $order_id, array $posted_data, \WC_Order $order): void
	{
		$payuni_methods = [CreditV3::ID, \PAYUNI\Gateways\CreditSubscriptionV3::ID];

		$payment_method = $posted_data['payment_method'] ?? '';

		if (!\in_array($payment_method, $payuni_methods, true)) {
			return;
		}

		OrderUtils::update_tmp_data($order, $posted_data);
	}

	/**
	 * 處理 PayUni 交易回調通知
	 *
	 * @return void
	 */
	public static function handle_notify(): void
	{
		try {
			$handler = new TradeHandler();
			$trade_result = $handler->process_notify($_POST);

			// 取得訂單
			$mer_trade_no = $trade_result['MerTradeNo'] ?? '';
			$order = \wc_get_order($mer_trade_no);

			if (!$order) {
				throw new \Exception("找不到訂單: {$mer_trade_no}");
			}

			// Webhook 防重複：若訂單已完成付款且 TradeNo 相同，直接回傳成功
			$incoming_trade_no = $trade_result['TradeNo'] ?? '';
			$existing_trade_no = $order->get_meta('_payuni_resp_trade_no', true);
			if ($incoming_trade_no && $existing_trade_no === $incoming_trade_no && $order->is_paid()) {
				$order->add_order_note( 'PayUni Webhook：重複通知，已略過' );
				\do_action('woomp_payuni_log', 'info', "#{$order->get_id()} Webhook 重複通知，已略過", [
					'order_id' => $order->get_id(),
					'trade_no' => $incoming_trade_no,
				]);
				\wp_send_json_success();
				return;
			}

			// 更新訂單狀態
			$handler->update_order_status($order, $trade_result, true);

			// 零元取 Token（3D 流程）：webhook 授權成功後補排程取消 5 元授權。
			// 直接授權路徑已於 process_zero_amount_token 排程；3D 路徑當下無 TradeNo，故於此補上。
			if ('pending' === $order->get_meta('_payuni_zero_token_authcancel', true)
				&& 'SUCCESS' === ($trade_result['Status'] ?? '')
				&& $incoming_trade_no) {
				(new \J7\Payuni\Domains\Subscription\SubscriptionHandler())->schedule_cancel_authorization($incoming_trade_no);
				$order->update_meta_data('_payuni_zero_token_authcancel', 'scheduled');
				$order->save();
				\do_action('woomp_payuni_log', 'info', "#{$order->get_id()} 零元取 Token（3D）已補排程取消 5 元授權", [
					'order_id' => $order->get_id(),
					'trade_no' => $incoming_trade_no,
				]);
			}

			// 回應成功
			\wp_send_json_success();
		} catch (\Throwable $e) {
			\do_action('woomp_payuni_log', 'error', '處理回調通知失敗: ' . $e->getMessage(), []);
			\wp_send_json_error($e->getMessage());
		}
	}
}

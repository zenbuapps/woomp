<?php

declare(strict_types=1);

namespace J7\Payuni\Infrastructure\Http;

use J7\Payuni\Contracts\DTOs\SettingDTO;
use J7\Payuni\Shared\Utils\EncryptUtils;
use J7\Payuni\Shared\Utils\OrderUtils;

/**
 * 處理 PayUni 交易請求
 *
 * @description 負責幕後信用卡交易授權的 HTTP 請求處理
 * @see         https://docs.payuni.com.tw/web/#/29/383
 */
final class TradeHandler
{

	private const TIMEOUT    = 60;
	private const USER_AGENT = 'payuni';

	/**
	 * PayUni 交易結果欄位 → 中文 label 映射表
	 *
	 * @var array<string, string>
	 */
	private const LABEL_MAP = [
		'Status'       => '狀態碼',
		'Message'      => '交易訊息',
		'MerID'        => '商店代號',
		'MerTradeNo'   => '商店訂單編號',
		'Gateway'      => '付款方式',
		'TradeNo'      => '交易編號',
		'TradeAmt'     => '交易金額',
		'TradeStatus'  => '交易狀態',
		'PaymentType'  => '付款類型',
		'CardBank'     => '發卡銀行代碼',
		'Card6No'      => '卡號前六碼',
		'Card4No'      => '卡號末四碼',
		'CardInst'     => '分期期數',
		'FirstAmt'     => '首期金額',
		'EachAmt'      => '每期金額',
		'ResCode'      => '回應碼',
		'ResCodeMsg'   => '回應訊息',
		'AuthCode'     => '授權碼',
		'AuthBank'     => '收單銀行代碼',
		'AuthBankName' => '收單銀行名稱',
		'AuthType'     => '授權類型',
		'AuthDay'      => '授權日期',
		'AuthTime'     => '授權時間',
		'CreditHash'   => '信用卡 Hash',
		'CreditLife'   => '有效期限',
		'CoBrandCode'  => '聯名卡代碼',
	];

	/**
	 * 執行幕後信用卡交易授權
	 *
	 * @param array $request_body 交易請求參數
	 *
	 * @return array 交易回應結果
	 * @throws \Exception 當交易失敗時
	 */
	public function execute_trade(array $request_body): array
	{
		$setting = SettingDTO::instance();
		$api_url = "{$setting->mode->base_api_url()}/iframe/merchant_trade";

		try {
			$options = [
				'body'       => $request_body,
				'blocking'   => true,
				'timeout'    => self::TIMEOUT,
				'user-agent' => self::USER_AGENT,
			];

			$response = \wp_remote_post($api_url, $options);

			if (\is_wp_error($response)) {
				throw new \Exception($response->get_error_message());
			}

			/** @var array $response_body */
			$response_body = \json_decode(\wp_remote_retrieve_body($response), true);

			\do_action('woomp_payuni_log', 'info', '幕後交易授權結果', [
				'endpoint' => $api_url,
				'body'     => $request_body,
				'result'   => $response_body
			]);

			return $response_body;
		} catch (\Throwable $e) {
			\do_action('woomp_payuni_log', 'error', '幕後交易授權失敗: ' . $e->getMessage(), [
				'body' => $request_body
			]);
			throw $e;
		}
	}

	/**
	 * 處理交易回調通知
	 *
	 * @param array{
	 *     MerID:string,
	 *     Version: string,
	 *     EncryptInfo: string,
	 *     HashInfo:string
	 * } $encrypted_data 加密的回調資料
	 *
	 * @return array 解密後的交易結果
	 * @throws \Exception 當解密或驗證失敗時
	 */
	public function process_notify(array $encrypted_data): array
	{
		$encrypt_info = $encrypted_data['EncryptInfo'] ?? '';
		$hash_info = $encrypted_data['HashInfo'] ?? '';

		if (empty($encrypt_info) || empty($hash_info)) {
			throw new \Exception('缺少加密資料');
		}

		// 驗證 Hash
		$calculated_hash = EncryptUtils::hash_info($encrypt_info);
		if ($calculated_hash !== $hash_info) {
			throw new \Exception('Hash 驗證失敗');
		}

		// 解密交易結果
		$decrypted = EncryptUtils::decrypt($encrypt_info);

		\do_action('woomp_payuni_log', 'info', '交易回調通知解密結果', $decrypted);

		return $decrypted;
	}

	/**
	 * 更新訂單狀態
	 *
	 * @param \WC_Order $order        訂單物件
	 * @param array{
	 *      Status: string,
	 *      Message: string,
	 *      MerID: string,
	 *      MerTradeNo: string,
	 *      Gateway: string,
	 *      TradeNo: string,
	 *      TradeAmt: string,
	 *      TradeStatus: string,
	 *      PaymentType: string,
	 *      CardBank: string,
	 *      Card6No: string,
	 *      Card4No: string,
	 *      CardInst: string,
	 *      FirstAmt: string,
	 *      EachAmt: string,
	 *      ResCode: string,
	 *      ResCodeMsg: string,
	 *      AuthCode: string,
	 *      AuthBank: string,
	 *      AuthBankName: string,
	 *      AuthType: string,
	 *      AuthDay: string,
	 *      AuthTime: string,
	 *      CreditHash?: string,
	 *      CreditLife?: string,
	 *      CoBrandCode?:string
	 *  }               $trade_result 交易結果
	 *
	 * @return void
	 */
	public function update_order_status(\WC_Order $order, array $trade_result, bool $is_webhook = false): void
	{
		$status = $trade_result['Status'] ?? '';
		$message = $trade_result['Message'] ?? '';
		$trade_no = $trade_result['TradeNo'] ?? '';
		$card_4no = $trade_result['Card4No'] ?? '';
		$card_6no = $trade_result['Card6No'] ?? '';
		$card_hash = $trade_result['CreditHash'] ?? '';

		// 儲存交易資訊到訂單（原始解密陣列 + 顯示用獨立欄位）
		$order->update_meta_data('_payuni_v3_resp', $trade_result);
		$order->update_meta_data('_payuni_resp_status', $status);
		$order->update_meta_data('_payuni_resp_message', $message);
		if ($trade_no) {
			$order->update_meta_data('_payuni_resp_trade_no', $trade_no);
		}
		if ($card_4no) {
			$order->update_meta_data('_payuni_card_number', $card_4no);
		}

		// 冪等性保護：訂單已付款（如 process_payment 已呼叫 payment_complete）
		// 仍需保存 meta（webhook 補傳的 TradeNo/Card4No），但跳過重複付款完成動作
		if ($order->is_paid()) {
			\do_action('woomp_payuni_log', 'info', "#{$order->get_id()} 訂單已付款，更新交易細節但跳過重複付款", [
				'order_id' => $order->get_id(),
				'trade_no' => $trade_no,
			]);
			// Notify 補傳：若顧客要求記憶卡號，仍須儲存 Token
			if ('SUCCESS' === $status) {
				if ($is_webhook) {
					$order->add_order_note( $this->build_order_note_html( '統一金流 PAYUNi 交易紀錄（Webhook）', $trade_result ) );
				}
				$should_save_card = \wc_string_to_bool($order->get_meta('payuni_save_card', true));
				$user_id = $order->get_customer_id();
				\do_action('woomp_payuni_log', 'info', "[DEBUG] #{$order->get_id()} 路徑B token 儲存條件檢查", [
					'order_id'        => $order->get_id(),
					'user_id'         => $user_id,
					'should_save_card' => $should_save_card,
					'card_hash'       => $card_hash,
					'card_6no'        => $card_6no,
					'card_4no'        => $card_4no,
					'raw_meta'        => $order->get_meta('payuni_save_card', true),
				]);
				if ($user_id && $should_save_card && ($card_hash || $card_4no)) {
					$credit_life = $trade_result['CreditLife'] ?? '';
					$this->save_payment_token($user_id, $card_hash, $card_6no, $card_4no, $credit_life);
				}
			}
			$order->save();
			return;
		}

		if ('SUCCESS' === $status) {
			// 交易成功
			$order->payment_complete($trade_no);
			if ($is_webhook) {
				$order->add_order_note( $this->build_order_note_html( '統一金流 PAYUNi 交易紀錄（Webhook）', $trade_result ) );
			} else {
				$order->add_order_note( $this->build_order_note_html( '統一金流 PAYUNi 交易紀錄（前景授權）', $trade_result ) );
			}

			// 檢查是否需要儲存卡片
			$should_save_card = \wc_string_to_bool($order->get_meta('payuni_save_card', true));
			$user_id = $order->get_customer_id();
			// 儲存卡號
			if ($user_id && $should_save_card && ($card_hash || $card_4no)) {
				$credit_life = $trade_result['CreditLife'] ?? '';
				$this->save_payment_token($user_id, $card_hash, $card_6no, $card_4no, $credit_life);
			}
		} else {
			// 交易失敗：維持 pending（允許客戶重試付款），僅寫入失敗備註
			$note_html = $this->build_order_note_html( '統一金流 PAYUNi 信用卡付款失敗', $trade_result );
			$order->add_order_note( $note_html );
		}

		$order->save();
		OrderUtils::delete_tmp_data($order);
	}

	/**
	 * 建構訂單備註 HTML
	 *
	 * @param string $title        備註標題（如「統一金流 PAYUNi 交易紀錄（Webhook）」）
	 * @param array  $trade_result 解密後的交易結果陣列
	 *
	 * @return string 格式化的 HTML 備註字串
	 */
	private function build_order_note_html( string $title, array $trade_result ): string {
		$html = '<strong>' . \esc_html( $title ) . '</strong>';

		// 判斷是否為分期交易（CardInst > 1 才是真正的分期）
		$card_inst        = $trade_result['CardInst'] ?? '';
		$is_installment   = '' !== $card_inst && (int) $card_inst > 1;
		$installment_keys = [ 'CardInst', 'FirstAmt', 'EachAmt' ];

		// Phase 1: 按 LABEL_MAP 定義順序印出已知欄位
		$processed_keys = [];
		foreach ( self::LABEL_MAP as $key => $label ) {
			$processed_keys[] = $key;

			// 分期群組欄位：非分期交易時跳過
			if ( \in_array( $key, $installment_keys, true ) && ! $is_installment ) {
				continue;
			}

			$value = $trade_result[ $key ] ?? null;

			if ( ! isset( $value ) || '' === $value ) {
				continue;
			}

			$html .= '<br>' . \esc_html( $label ) . '：' . \esc_html( (string) $value );
		}

		// Phase 2: 印出不在 LABEL_MAP 中的未知 key（按 trade_result 原始順序）
		foreach ( $trade_result as $key => $value ) {
			if ( \in_array( $key, $processed_keys, true ) ) {
				continue;
			}

			if ( ! isset( $value ) || '' === $value ) {
				continue;
			}

			$html .= '<br>' . \esc_html( $key ) . '：' . \esc_html( (string) $value );
		}

		return $html;
	}

	/**
	 * 儲存 Payment Token 到 WooCommerce
	 *
	 * @param int    $customer_id 客戶 ID
	 * @param string $card_hash   信用卡 Hash
	 * @param string $card_4no    信用卡末四碼
	 * @param string $card_exp    有效期限 (MMYY)
	 *
	 * @return void
	 */
	private function save_payment_token(
		int $customer_id,
		string $card_hash,
		string $card_6no,
		string $card_4no,
		string $card_exp
	): void {
		// 若無 CreditHash（Sandbox 模擬授權不回傳），以卡號組合作為 fallback token
		$token_key = $card_hash ?: sprintf('%s****%s', $card_6no, $card_4no);
		if (! $token_key) {
			return;
		}

		// 檢查是否已存在相同的 Token
		$existing_tokens = \WC_Payment_Tokens::get_customer_tokens($customer_id, \PAYUNI\Gateways\CreditV3::ID);

		foreach ($existing_tokens as $existing_token) {
			if ($existing_token->get_token() === $token_key) {
				// 已存在相同的卡片，不需要重複儲存
				\do_action('woomp_payuni_log', 'info', '信用卡 Token 已存在，跳過儲存', [
					'customer_id' => $customer_id,
					'card_4no'    => $card_4no
				]);
				return;
			}
		}

		// 解析有效期限（PayUni CreditLife 格式為 MMYY，Sandbox 不回傳時使用遠未來 fallback）
		$expiry_month = '12';
		$expiry_year  = '2099'; // Sandbox fallback

		if (\strlen($card_exp) === 4) {
			$expiry_month = \substr($card_exp, 0, 2);
			$expiry_year  = '20' . \substr($card_exp, 2, 2);
		}

		// 建立新的 Payment Token
		$token = new \WC_Payment_Token_CC();
		$token->set_token($token_key);
		$token->set_gateway_id(\PAYUNI\Gateways\CreditV3::ID);
		// 依卡號前幾碼判斷卡片類型（4=Visa, 5=Mastercard, 3=Amex, 6=Discover）
		$card_type = match (true) {
			str_starts_with($card_6no, '4') => 'visa',
			str_starts_with($card_6no, '5') => 'mastercard',
			str_starts_with($card_6no, '3') => 'amex',
			str_starts_with($card_6no, '6') => 'discover',
			default                            => 'visa',
		};
		$token->set_card_type($card_type);
		$token->set_last4($card_4no);
		$token->set_expiry_month($expiry_month);
		$token->set_expiry_year($expiry_year);
		$token->set_user_id($customer_id);

		// 如果是第一張卡，設為預設
		if (empty($existing_tokens)) {
			$token->set_default(true);
		}

		$token->save();

		\do_action('woomp_payuni_log', 'info', '信用卡 Token 儲存成功', [
			'customer_id' => $customer_id,
			'card_4no'    => $card_4no,
			'token_id'    => $token->get_id()
		]);
	}
}

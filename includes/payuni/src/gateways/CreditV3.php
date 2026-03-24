<?php

/**
 * Payuni_Payment_Credit class file
 *
 * @package payuni
 */

namespace PAYUNI\Gateways;

use J7\Payuni\Contracts\DTOs\TradeReqDTO;
use J7\Payuni\Infrastructure\Http\HttpClient;
use J7\Payuni\Infrastructure\Http\TradeHandler;

\defined('ABSPATH') || exit;

/**
 * Payuni_Payment_Credit class for Credit Card payment
 */
class CreditV3 extends AbstractGateway
{

	public const ID = 'payuni-credit-v3';

	/** Constructor */
	public function __construct()
	{
		$this->id = self::ID;
		parent::__construct();
		$this->has_fields = true;
		// $this->order_button_text = __( '統一金流 PAYUNi 信用卡', 'woomp' );

		$this->method_title = '統一金流 PAYUNi 信用卡 v3';
		$this->method_description = '透過統一金流 PAYUNi 信用卡進行站內付款';

		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option('title');
		$this->description = $this->get_option('description');
		$this->supports = ['products', 'refunds', 'tokenization'];
		$this->api_endpoint_url = 'api/credit';

		\add_action("woocommerce_update_options_payment_gateways_{$this->id}", [$this, 'process_admin_options',]);

		//        \add_action( 'woocommerce_before_checkout_form', [ $this, 'form' ] );
	}

	/** @return void 設定後台 form fields */
	public function init_form_fields(): void
	{
		$this->form_fields = [
			'enabled'             => [
				'title'   => __('Enable/Disable', 'woocommerce'),
				'type'    => 'checkbox',
				'label'   => \sprintf(__('Enable %s', 'woomp'), $this->method_title),
				'default' => 'no',
			],
			'title'               => [
				'title'       => __('Title', 'woocommerce'),
				'type'        => 'text',
				'default'     => $this->method_title,
				'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
				'desc_tip'    => true,
			],
			'description'         => [
				'title'       => __('Description', 'woocommerce'),
				'type'        => 'textarea',
				'css'         => 'width: 400px;',
				'default'     => $this->order_button_text,
				'description' => __(
					'This controls the description which the user sees during checkout.',
					'woocommerce'
				),
				'desc_tip'    => true,
			],
			'enable_tokenization' => [
				'title'       => __('記憶卡號', 'woomp'),
				'type'        => 'checkbox',
				'label'       => __('啟用記憶卡號功能，允許客戶儲存信用卡以便下次快速結帳', 'woomp'),
				'default'     => 'no',
				'description' => __('啟用後，登入的客戶可以選擇儲存信用卡以便未來使用。', 'woomp'),
				'desc_tip'    => true,
			],
			'installment_options' => [
				'title'             => __('分期付款選項', 'woomp'),
				'type'              => 'multiselect',
				'class'             => 'wc-enhanced-select',
				'css'               => 'width: 400px;',
				'default'           => [],
				'description'       => __('選擇要啟用的分期期數。留空則不啟用分期付款。', 'woomp'),
				'desc_tip'          => true,
				'options'           => [
					3  => \sprintf(__('%d 期', 'woomp'), 3),
					6  => \sprintf(__('%d 期', 'woomp'), 6),
					9  => \sprintf(__('%d 期', 'woomp'), 9),
					12 => \sprintf(__('%d 期', 'woomp'), 12),
					18 => \sprintf(__('%d 期', 'woomp'), 18),
					24 => \sprintf(__('%d 期', 'woomp'), 24),
					30 => \sprintf(__('%d 期', 'woomp'), 30),
				],
				'custom_attributes' => [
					'data-placeholder' => __('選擇分期期數', 'woomp'),
				],
			],
			'enable_invoice_carrier' => [
				'title'       => __('統一金流電子發票（光貿）', 'woomp'),
				'type'        => 'checkbox',
				'label'       => __('啟用統一金流電子發票載具整合，允許消費者在結帳頁選擇發票載具', 'woomp'),
				'default'     => 'no',
				'description' => __('啟用後，結帳頁將顯示發票載具選項（手機條碼、自然人憑證、會員載具、捐贈碼、公司統編），載具資訊將隨交易參數一併送出至 PayUni。', 'woomp'),
				'desc_tip'    => true,
			],
		];
	}

	public function validate_fields(): bool
	{
		$enable_invoice_carrier = \wc_string_to_bool($this->get_option('enable_invoice_carrier', 'no'));
		if (!$enable_invoice_carrier) {
			return true;
		}

		$carrier_type = isset($_POST['payuni_carrier_type']) ? sanitize_text_field(wp_unslash($_POST['payuni_carrier_type'])) : '';

		if ('' === $carrier_type || 'amego' === $carrier_type) {
			return true;
		}

		$validation_map = [
			'3J0002'  => [
				'field'   => 'payuni_carrier_info_3J0002',
				'pattern' => '/^\/[0-9A-Z\+\-\.]{7}$/',
				'label'   => '手機條碼',
				'hint'    => '格式為 / 開頭加上 7 碼大寫英數字（例：/ABC1234）',
			],
			'CQ0001'  => [
				'field'   => 'payuni_carrier_info_CQ0001',
				'pattern' => '/^[A-Z]{2}[0-9]{14}$/',
				'label'   => '自然人憑證',
				'hint'    => '格式為 2 碼大寫英文加上 14 碼數字',
			],
			'Donate'  => [
				'field'   => 'payuni_carrier_info_Donate',
				'pattern' => '/^[0-9]{1,7}$/',
				'label'   => '捐贈碼',
				'hint'    => '請輸入 1~7 碼數字',
			],
			'Company' => [
				'field'   => 'payuni_carrier_info_Company',
				'pattern' => '/^[0-9]{8}$/',
				'label'   => '公司統編',
				'hint'    => '請輸入 8 碼數字',
			],
		];

		if (! isset($validation_map[$carrier_type])) {
			return true;
		}

		$rule  = $validation_map[$carrier_type];
		$value = isset($_POST[$rule['field']]) ? sanitize_text_field(wp_unslash($_POST[$rule['field']])) : '';

		if ('' === $value) {
			wc_add_notice(sprintf('請輸入%s資訊。', $rule['label']), 'error');
			return false;
		}

		if (! preg_match($rule['pattern'], $value)) {
			wc_add_notice(sprintf('%s格式不正確，%s', $rule['label'], $rule['hint']), 'error');
			return false;
		}

		return true;
	}

	/**
	 * 輸出付款表單欄位
	 *
	 * @description 在結帳頁面輸出信用卡輸入框的容器，供 PayUni SDK iframe 渲染
	 * @return void
	 */
	public function payment_fields(): void
	{
		// 輸出付款方式描述
		if ($this->description) {
			echo \wpautop(\wptexturize($this->description));
		}

		$enable_tokenization = \wc_string_to_bool($this->get_option('enable_tokenization', 'no'));
		$installment_options = $this->get_option('installment_options', []);
		$saved_tokens = [];

		// 取得已儲存的卡片
		if ($enable_tokenization && \is_user_logged_in()) {
			$saved_tokens = \WC_Payment_Tokens::get_customer_tokens(\get_current_user_id(), $this->id);
		}

		// 輸出已儲存卡片選項
		if (!empty($saved_tokens)) {
			echo '<div class="payuni-saved-tokens">';
			echo '<p class="form-row form-row-wide">';
			echo '<label>' . \esc_html__('選擇已儲存的卡片', 'woomp') . '</label>';

			foreach ($saved_tokens as $token) {
				$token_id = $token->get_id();
				$last4 = $token->get_last4();
				$expiry = $token->get_expiry_month() . '/' . $token->get_expiry_year();
				$is_default = $token->is_default() ? ' checked="checked"' : '';

				echo '<label class="payuni-saved-token-label">';
				echo '<input type="radio" name="payuni_saved_token" value="' . \esc_attr(
					$token_id
				) . '" class="payuni-saved-token-radio"' . $is_default . ' />';
				echo \sprintf(' **** **** **** %s (到期: %s)', \esc_html($last4), \esc_html($expiry));
				echo '</label><br/>';
			}

			echo '<label class="payuni-saved-token-label">';
			echo '<input type="radio" name="payuni_saved_token" value="new" class="payuni-saved-token-radio payuni-new-card-radio" />';
			echo ' ' . \esc_html__('使用新卡片', 'woomp');
			echo '</label>';
			echo '</p>';
			echo '</div>';
		}

		// 輸出信用卡輸入框容器
		$new_card_style = !empty($saved_tokens) ? ' style="display:none;"' : '';
		echo '<div class="payuni-credit-v3-form payuni-new-card-form"' . $new_card_style . '>';
		echo '<div class="payuni-form-group">';
		echo '<label for="put_card_no">' . \esc_html__('信用卡號碼', 'woomp') . '</label>';
		echo '<div id="put_card_no"></div>';
		echo '</div>';
		echo '<div class="payuni-form-group">';
		echo '<label for="put_card_exp">' . \esc_html__('有效期限', 'woomp') . '</label>';
		echo '<div id="put_card_exp"></div>';
		echo '</div>';
		echo '<div class="payuni-form-group">';
		echo '<label for="put_card_cvc">' . \esc_html__('安全碼', 'woomp') . '</label>';
		echo '<div id="put_card_cvc"></div>';
		echo '</div>';

		// 記憶卡號勾選框 - 根據 PayUni 文件格式設置
		// SDK 會根據 useTokenType 事件決定是否在 put_token_type 容器產生 checkbox
		if ($enable_tokenization && \is_user_logged_in()) {
			echo <<<HTML
                <div id="token_type_checkbox_area" style="display: flex; align-items: center; display: none;">
                <div id="put_token_type" style="display: flex; align-items: center;">
                  <!-- 按照 useTokenType 值決定是否在此容器產生 checkbox 選項-->
                </div>
                <label id="token_type_text" for="type-checkbox" style="margin-left: 8px;">
                  <!-- 此區域您可放置 token_type_text 預設文字或是其他  -->
                </label>
                </div>
            HTML;
		}

		echo '</div>'; // .payuni-credit-v3-form

		// 分期付款選項
		if (!empty($installment_options) && \is_array($installment_options)) {
			echo '<div class="payuni-installment-options">';
			echo '<p class="form-row form-row-wide">';
			echo '<label for="payuni_installment">' . \esc_html__('分期付款', 'woomp') . '</label>';
			echo '<select name="payuni_installment" id="payuni_installment" class="payuni-installment-select">';
			echo '<option value="1">' . \esc_html__('不分期', 'woomp') . '</option>';

			foreach ($installment_options as $period) {
				echo '<option value="' . \esc_attr($period) . '">';
				echo \sprintf(\esc_html__('%d 期', 'woomp'), (int) $period);
				echo '</option>';
			}

			echo '</select>';
			echo '</p>';
			echo '</div>';
		}

		// 隱藏欄位：儲存使用的 Token ID
		echo '<input type="hidden" name="payuni_used_token_id" id="payuni_used_token_id" value="" />';

		// 發票載具選擇區塊
		$enable_invoice_carrier = \wc_string_to_bool($this->get_option('enable_invoice_carrier', 'no'));

		if ($enable_invoice_carrier) {
		echo '<div class="payuni-invoice-carrier-options">';
		echo '<p class="form-row form-row-wide">';
		echo '<label for="payuni_carrier_type">' . \esc_html__('發票載具', 'woomp') . '</label>';
		echo '<select name="payuni_carrier_type" id="payuni_carrier_type" class="payuni-carrier-type-select">';
		echo '<option value="">' . \esc_html__('不使用載具（紙本發票）', 'woomp') . '</option>';
		echo '<option value="3J0002">' . \esc_html__('手機條碼', 'woomp') . '</option>';
		echo '<option value="CQ0001">' . \esc_html__('自然人憑證', 'woomp') . '</option>';
		echo '<option value="amego">' . \esc_html__('會員載具', 'woomp') . '</option>';
		echo '<option value="Donate">' . \esc_html__('捐贈發票', 'woomp') . '</option>';
		echo '<option value="Company">' . \esc_html__('公司發票（統編）', 'woomp') . '</option>';
		echo '</select>';
		echo '</p>';

		// 手機條碼輸入（3J0002）
		echo '<p class="form-row form-row-wide payuni-carrier-info-row" id="payuni_carrier_info_row_3J0002" style="display:none;">';
		echo '<label for="payuni_carrier_info_3J0002">' . \esc_html__('手機條碼（格式：/XXXXXXX）', 'woomp') . '</label>';
		echo '<input type="text" name="payuni_carrier_info_3J0002" id="payuni_carrier_info_3J0002" placeholder="/XXXXXXX" maxlength="8" />';
		echo '</p>';

		// 自然人憑證輸入（CQ0001）
		echo '<p class="form-row form-row-wide payuni-carrier-info-row" id="payuni_carrier_info_row_CQ0001" style="display:none;">';
		echo '<label for="payuni_carrier_info_CQ0001">' . \esc_html__('自然人憑證號碼', 'woomp') . '</label>';
		echo '<input type="text" name="payuni_carrier_info_CQ0001" id="payuni_carrier_info_CQ0001" placeholder="請輸入憑證號碼" maxlength="16" />';
		echo '</p>';

		// 捐贈碼輸入（Donate）
		echo '<p class="form-row form-row-wide payuni-carrier-info-row" id="payuni_carrier_info_row_Donate" style="display:none;">';
		echo '<label for="payuni_carrier_info_Donate">' . \esc_html__('捐贈碼', 'woomp') . '</label>';
		echo '<input type="text" name="payuni_carrier_info_Donate" id="payuni_carrier_info_Donate" placeholder="請輸入捐贈碼" maxlength="7" />';
		echo '</p>';

		// 統編輸入（Company）
		echo '<p class="form-row form-row-wide payuni-carrier-info-row" id="payuni_carrier_info_row_Company" style="display:none;">';
		echo '<label for="payuni_carrier_info_Company">' . \esc_html__('公司統一編號', 'woomp') . '</label>';
		echo '<input type="text" name="payuni_carrier_info_Company" id="payuni_carrier_info_Company" placeholder="請輸入統一編號" maxlength="8" />';
		echo '</p>';

		// 買方名稱（有載具時顯示，但捐贈不需填）
		echo '<p class="form-row form-row-wide" id="payuni_inv_buyer_name_row" style="display:none;">';
		echo '<label for="payuni_inv_buyer_name">' . \esc_html__('買方名稱／公司抬頭', 'woomp') . '</label>';
		echo '<input type="text" name="payuni_inv_buyer_name" id="payuni_inv_buyer_name" placeholder="請輸入買方名稱" maxlength="60" />';
		echo '</p>';

		// 隱藏欄位：載具資訊（由 JS 填入）
		echo '<input type="hidden" name="payuni_carrier_info" id="payuni_carrier_info" value="" />';

		echo '</div>'; // .payuni-invoice-carrier-options
		} // end if enable_invoice_carrier
	}


	/**
	 * 處理付款
	 *
	 * 流程：
	 * 1. 組裝加密交易參數
	 * 2. server-side 呼叫 PayUni merchant_trade API（避免瀏覽器 CORS 問題）
	 * 3. 依回應更新訂單狀態
	 *
	 * @param int $order_id 訂單 ID
	 *
	 * @return array{result:string, redirect?:string, order_id?:int} 'success'|'failure'
	 */
	public function process_payment($order_id): array
	{
		$order = \wc_get_order($order_id);
		/** @var \WC_Order $order */

		try {
			// 組裝加密請求參數（EncryptInfo, HashInfo, MerID, Version, ApiUrl）
			$request_body = TradeReqDTO::of($order)->to_array();

			// Server-side 呼叫 PayUni merchant_trade（wp_remote_post，無 CORS 問題）
			$handler      = new TradeHandler();
			$raw_response = $handler->execute_trade($request_body);

			// 解密 PayUni 回應（server-side API 一定有 EncryptInfo）
			$trade_result = $handler->process_notify($raw_response);

			// 判斷是否為 3D 驗證流程：解密後 TradeNo 為空 = 僅建立 3D 驗證，尚未實際授權
			// 真正的交易完成會有 TradeNo（由 webhook 回傳）
			if (! empty($trade_result['TradeNo'])) {
				// 直接授權成功（有 TradeNo）：更新訂單狀態
				$handler->update_order_status($order, $trade_result);
			} else {
				// 3D 驗證流程（TradeNo 為空）：訂單維持「等待付款中」，由後續 Webhook 決定最終狀態
				$status  = $trade_result['Status'] ?? '';
				$message = $trade_result['Message'] ?? '';

				$order->update_meta_data('_payuni_v3_resp', $trade_result);
				$order->add_order_note(
					\sprintf(
						'3D 驗證已建立，等待 webhook 回傳交易結果（狀態碼：%s，交易訊息：%s）',
						$status,
						$message
					)
				);
				$order->save();
			}

			return [
				'result'   => 'success',
				'redirect' => $order->get_checkout_order_received_url(),
				'order_id' => $order_id,
			];
		} catch (\Throwable $e) {
			\do_action('woomp_payuni_log', 'error', "#{$order_id} process_payment 失敗: {$e->getMessage()}", [
				'order_id' => $order_id,
			]);
			\wc_add_notice(\esc_html($e->getMessage()), 'error');

			return [
				'result' => 'failure',
			];
		}
	}

	/**
	 * Display payment detail after order table
	 *
	 * @param \WC_Order $order The order object.
	 *
	 * @return void
	 */
	public function get_detail_after_order_table(\WC_Order $order)
	{
		if ($order->get_payment_method() !== $this->id) {
			return;
		}

		// 判斷訂單是否已有最終交易結果（已付款、已失敗、已取消）
		// 3D 驗證流程中 webhook 尚未回傳時，訂單仍為 pending，不應顯示原始 3D 建立回應
		$order_status     = $order->get_status();
		$has_final_result = $order->is_paid()
			|| 'failed' === $order_status
			|| 'cancelled' === $order_status;

		if (! $has_final_result) {
			$html = <<<'HTML'
            <h2 class="woocommerce-order-details__title">交易狀態</h2>
            <div class="responsive-table">
                <table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
                    <tbody>
                        <tr>
                            <td>付款處理中，系統確認後將自動更新訂單狀態。</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            HTML;

			echo $html;
			return;
		}

		$status   = \esc_html($order->get_meta('_payuni_resp_status', true));
		$message  = \esc_html($order->get_meta('_payuni_resp_message', true));
		$trade_no = \esc_html($order->get_meta('_payuni_resp_trade_no', true));
		$card_4no = \esc_html($order->get_meta('_payuni_card_number', true));

		$html = <<<HTML
            <h2 class="woocommerce-order-details__title">交易明細</h2>
            <div class="responsive-table">
                <table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
                    <tbody>
                        <tr>
                            <th>狀態碼：</th>
                            <td>{$status}</td>
                        </tr>
                        <tr>
                            <th>交易訊息：</th>
                            <td>{$message}</td>
                        </tr>
                        <tr>
                            <th>交易編號：</th>
                            <td>{$trade_no}</td>
                        </tr>
                        <tr>
                            <th>卡號末四碼：</th>
                            <td>{$card_4no}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        HTML;

		echo $html;
	}

	/**
	 * 退款處理
	 *
	 * @param int        $order_id 訂單 ID
	 * @param float|null $amount   退款金額
	 * @param string     $reason   退款原因
	 * @return bool|\WP_Error
	 */
	public function process_refund($order_id, $amount = null, $reason = '')
	{
		$order = wc_get_order($order_id);
		if (! $order) {
			return new \WP_Error('payuni_refund_error', '找不到訂單。');
		}

		$trade_no = $order->get_meta('_payuni_resp_trade_no');
		if (empty($trade_no)) {
			return new \WP_Error('payuni_refund_error', '找不到 PayUni 交易編號，無法退款。');
		}

		if (! $amount || $amount <= 0) {
			return new \WP_Error('payuni_refund_error', '退款金額必須大於 0。');
		}

		$http_client = new HttpClient();

		try {
			$query_result = $http_client->query_trade($trade_no);
		} catch (\Exception $e) {
			return new \WP_Error('payuni_refund_error', '查詢交易狀態失敗：' . $e->getMessage());
		}

		$close_status = $query_result['Result']['0']['CloseStatus'] ?? '';

		try {
			switch ($close_status) {
				case '1': // 請款申請中 → 取消請款
				case '7': // 請款處理中 → 取消請款
					$result = $http_client->cancel_trade($trade_no);
					$order->add_order_note(
						sprintf(
							'PayUni 取消請款成功（CloseStatus=%s）。%s',
							$close_status,
							$reason ? "退款原因：{$reason}" : ''
						)
					);
					return true;

				case '3': // 已取消
				case '9': // 未申請請款
					$order->add_order_note(
						sprintf('PayUni 交易已取消或未請款（CloseStatus=%s），無需退款。', $close_status)
					);
					return true;

				case '2': // 請款成功 → 退款
				default:
					$refund_amount = intval(round($amount));
					$result        = $http_client->refund($trade_no, $refund_amount);
					$order->add_order_note(
						sprintf(
							'PayUni 退款成功，金額 NT$%s。%s',
							number_format($amount),
							$reason ? "退款原因：{$reason}" : ''
						)
					);
					return true;
			}
		} catch (\Exception $e) {
			$order->add_order_note('PayUni 退款失敗：' . $e->getMessage());
			return new \WP_Error('payuni_refund_error', '退款失敗：' . $e->getMessage());
		}
	}


	/**
	 * Checkout fields 結帳欄位
	 * Payment form on checkout page copy from WC_Payment_Gateway_CC
	 * To add the input name and get value with $_POST
	 *
	 * @return void
	 */
	public function form(): void {}
}

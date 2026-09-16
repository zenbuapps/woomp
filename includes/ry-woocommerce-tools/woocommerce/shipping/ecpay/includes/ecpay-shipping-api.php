<?php
/**
 * ECPay 物流 API 處理類別
 *
 * 此類別繼承自 RY_ECPay，用於處理綠界科技物流 API 相關操作
 *
 * @package RyWooCommerceTools
 * @subpackage ECPayShipping
 */

/**
 * ECPay 物流 API 處理類別
 */
class RY_ECPay_Shipping_Api extends RY_ECPay {

	/**
	 * 測試環境物流 API 網址
	 *
	 * @var array<string, string> 不同物流服務的測試環境 API 網址
	 */
	public static $api_test_url = [
		'map'              => 'https://logistics-stage.ecpay.com.tw/Express/map',
		'create'           => 'https://logistics-stage.ecpay.com.tw/Express/Create',
		'print_UNIMARTC2C' => 'https://logistics-stage.ecpay.com.tw/Express/PrintUniMartC2COrderInfo',
		'print_FAMIC2C'    => 'https://logistics-stage.ecpay.com.tw/Express/PrintFAMIC2COrderInfo',
		'print_HILIFEC2C'  => 'https://logistics-stage.ecpay.com.tw/Express/PrintHILIFEC2COrderInfo',
		'print_OKMARTC2C'  => 'https://logistics-stage.ecpay.com.tw/Express/PrintOKMARTC2COrderInfo',
		'print_B2C'        => 'https://logistics-stage.ecpay.com.tw/helper/printTradeDocument',
	];

	/**
	 * 正式環境物流 API 網址
	 *
	 * @var array<string, string> 不同物流服務的正式環境 API 網址
	 */
	public static $api_url = [
		'map'              => 'https://logistics.ecpay.com.tw/Express/map',
		'create'           => 'https://logistics.ecpay.com.tw/Express/Create',
		'print_UNIMARTC2C' => 'https://logistics.ecpay.com.tw/Express/PrintUniMartC2COrderInfo',
		'print_FAMIC2C'    => 'https://logistics.ecpay.com.tw/Express/PrintFAMIC2COrderInfo',
		'print_HILIFEC2C'  => 'https://logistics.ecpay.com.tw/Express/PrintHILIFEC2COrderInfo',
		'print_OKMARTC2C'  => 'https://logistics.ecpay.com.tw/Express/PrintOKMARTC2COrderInfo',
		'print_B2C'        => 'https://logistics.ecpay.com.tw/helper/printTradeDocument',
	];

	/**
	 * 取得地圖查詢 API 網址
	 *
	 * 根據是否為測試模式回傳對應的 API 網址
	 *
	 * @return string 地圖查詢 API 網址
	 */
	public static function get_map_post_url(): string {
		return RY_ECPay_Shipping::$testmode ? self::$api_test_url['map'] : self::$api_url['map'];
	}

	/**
	 * 取得代收貨款物流代碼
	 *
	 * @param int $order_id 訂單 ID
	 * @return array|false 物流代碼或 false
	 */
	public static function get_code_cod( $order_id ) {
		self::get_code($order_id, true);
	}

	/**
	 * 取得物流代碼
	 *
	 * 根據訂單 ID 和設定的前綴字取得物流代碼
	 *
	 * @param int  $order_id 訂單 ID
	 * @param bool $collection 是否為代收貨款
	 * @return array|false 物流代碼或 false
	 */
	public static function get_code( $order_id, $collection = false ) {
		$order = wc_get_order($order_id);
		if (!$order) {
			return false;
		}

		$item_names = RY_WT::get_option('shipping_item_name', '');
		if (empty($item_names)) {
			$items = $order->get_items();
			if (count($items)) {
				$item       = reset($items);
				$item_names = trim($item->get_name());
			}
		}
		$item_names = str_replace([ '^', '\'', '`', '!', '@', '＠', '#', '%', '&', '*', '+', '\\', '"', '<', '>', '|', '_', '[', ']' ], '', $item_names);
		$item_names = mb_substr($item_names, 0, 25);

		foreach ($order->get_items('shipping') as $item_id => $item) {
			$shipping_method = RY_ECPay_Shipping::get_order_support_shipping($item);
			if ($shipping_method == false) {
				continue;
			}

			$shipping_list = $order->get_meta('_ecpay_shipping_info', true);
			if (!is_array($shipping_list)) {
				$shipping_list = [];
			}

			$get_count = 1;
			if (count($shipping_list) == 0) {
				$get_count = (int) $item->get_meta('no_count');
			}
			if ($get_count < 1) {
				$get_count = 1;
			}

			$method_class                                   = RY_ECPay_Shipping::$support_methods[ $shipping_method ];
			list($MerchantID, $HashKey, $HashIV, $CVS_type) = RY_ECPay_Shipping::get_ecpay_api_info();

			$total = ceil($order->get_total());
			if ($total > 20000) {
				$total = 19999;
			}

			$notify_url = WC()->api_request_url('ry_ecpay_shipping_callback', true);

			RY_ECPay_Shipping::log('Generating shipping for order #' . $order->get_order_number() . ' with ' . $get_count . ' times');

			$shipping_phone = self::normalize_taiwan_mobile( (string) $order->get_shipping_phone());

			$args = [
				'MerchantID'           => $MerchantID,
				'LogisticsType'        => \strtoupper($method_class::$LogisticsType),
				'LogisticsSubType'     => $method_class::$LogisticsSubType,
				'GoodsAmount'          => (int) $total,
				'GoodsName'            => $item_names,
				'SenderName'           => RY_WT::get_option('ecpay_shipping_sender_name'),
				'SenderPhone'          => RY_WT::get_option('ecpay_shipping_sender_phone'),
				'SenderCellPhone'      => RY_WT::get_option('ecpay_shipping_sender_cellphone'),
				'ReceiverName'         => $order->get_shipping_last_name() . $order->get_shipping_first_name(),
				'ReceiverCellPhone'    => $shipping_phone,
				'ReceiverStoreID'      => '',
				'ServerReplyURL'       => $notify_url,
				'LogisticsC2CReplyURL' => $notify_url,
				// 預設不代收貨款。綠界 IsCollection 為選填、未帶時預設 N，
				// 明確帶值可避免後續流程漏設定時靜默送出純配送。
				'IsCollection'         => 'N',
				'CollectionAmount'     => 0,
			];

			if ('yes' === RY_WT::get_option('ecpay_shipping_cleanup_receiver_name', 'no')) {
				$args['ReceiverName'] = preg_replace('/[^a-zA-Z\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]/u', '', $args['ReceiverName']);
				if (preg_match('/^[a-zA-z]+$/', $args['ReceiverName'])) {
					$args['ReceiverName'] = mb_substr($args['ReceiverName'], 0, 10);
				} else {
					$args['ReceiverName'] = preg_replace('/[a-zA-Z]/', '', $args['ReceiverName']);
					$args['ReceiverName'] = mb_substr($args['ReceiverName'], 0, 4);
				}
			}

			if ($args['LogisticsType'] == 'CVS') {
				$args['LogisticsSubType'] .= ( 'C2C' == $CVS_type ) ? 'C2C' : '';
			}

			// 貨到付款一律代收；$collection 為後台「代收貨款」動作的額外強制。
			// 兩者皆不受「是否為第一張物流單」影響，避免改單後重建物流單時靜默變成純配送。
			if (true === $collection || 'cod' === $order->get_payment_method()) {
				$args['IsCollection']     = 'Y';
				$args['CollectionAmount'] = (int) $total;
			}

			// 已有物流單又再建一張代收單，提醒商家確認是否重複代收。
			if (count($shipping_list) > 0 && 'Y' === $args['IsCollection']) {
				$order->add_order_note(__('This order already has a shipping code. A new cash on delivery shipping code is being created, please confirm the collection amount is not duplicated.', 'ry-woocommerce-tools'));
			}

			if ($method_class::$LogisticsType == 'CVS') {
				$args['ReceiverStoreID'] = $order->get_meta('_shipping_cvs_store_ID');
			}

			if ($method_class::$LogisticsType == 'Home') {
				$country = $order->get_shipping_country();

				$state      = $order->get_shipping_state();
				$states     = WC()->countries->get_states($country);
				$full_state = ( $state && isset($states[ $state ]) ) ? $states[ $state ] : $state;

				$args['SenderZipCode']   = RY_WT::get_option('ecpay_shipping_sender_zipcode');
				$args['SenderAddress']   = RY_WT::get_option('ecpay_shipping_sender_address');
				$args['ReceiverZipCode'] = $order->get_shipping_postcode();
				$args['ReceiverAddress'] = $full_state . $order->get_shipping_city() . $order->get_shipping_address_1() . $order->get_shipping_address_2();

				// 溫層判斷
				switch (array_shift($order->get_shipping_methods())['method_id']) {
					case 'ry_ecpay_shipping_home_tcat_freeze':
						$args['Temperature'] = '0002';
						break;
					case 'ry_ecpay_shipping_home_tcat_frozen':
						$args['Temperature'] = '0003';
						break;
					default:
						$args['Temperature'] = '0001';
						break;
				}

				if ($method_class::$LogisticsSubType !== 'POST') {
					$args['Distance']              = '00';
					$args['Specification']         = '0001';
					$args['ScheduledPickupTime']   = '4';
					$args['ScheduledDeliveryTime'] = '4';
				}

				if ($method_class::$LogisticsSubType == 'POST') {
					$args['GoodsWeight'] = 1;
				}
			}

			if (RY_ECPay_Shipping::$testmode) {
				$post_url = self::$api_test_url['create'];
			} else {
				$post_url = self::$api_url['create'];
			}

			for ($i = 0; $i < $get_count; ++$i) {
				$create_datetime           = new DateTime('', new DateTimeZone('Asia/Taipei'));
				$args['MerchantTradeDate'] = $create_datetime->format('Y/m/d H:i:s');
				$args['MerchantTradeNo']   = self::generate_trade_no($order->get_id(), RY_WT::get_option('ecpay_shipping_order_prefix'));
				if ($i > 0) {
					$args['IsCollection']     = 'N';
					$args['CollectionAmount'] = 0;
				}

				$args = self::add_check_value($args, $HashKey, $HashIV, 'md5');
				RY_ECPay_Shipping::log('Shipping POST: ' . var_export($args, true));

				$response = self::link_server($post_url, $args);
				if (is_wp_error($response)) {
					RY_ECPay_Shipping::log('Shipping failed. POST error: ' . implode("\n", $response->get_error_messages()), 'error');
					continue;
				}

				if ($response['response']['code'] != '200') {
					RY_ECPay_Shipping::log('Shipping failed. Http code: ' . $response['response']['code'], 'error');
					continue;
				}

				RY_ECPay_Shipping::log('Shipping request result: ' . $response['body']);
				$body = explode('|', $response['body']);
				if (count($body) != 2) {
					RY_ECPay_Shipping::log('Shipping failed. Explode result failed.', 'error');
					continue;
				}

				if ($body[0] != '1') {
					$order->add_order_note(
						sprintf(
							/* translators: %s Error message */
							__('Get shipping code error: %s', 'ry-woocommerce-tools'),
							$body[1]
						)
					);
					continue;
				}

				parse_str($body[1], $result);
				if (!is_array($result)) {
					RY_ECPay_Shipping::log('Shipping failed. Parse result failed.', 'error');
					continue;
				}

				$shipping_list = $order->get_meta('_ecpay_shipping_info', true);
				if (!is_array($shipping_list)) {
					$shipping_list = [];
				}
				if (!isset($shipping_list[ $result['AllPayLogisticsID'] ])) {
					$shipping_list[ $result['AllPayLogisticsID'] ] = [];
				}
				$shipping_list[ $result['AllPayLogisticsID'] ]['ID']               = $result['AllPayLogisticsID'];
				$shipping_list[ $result['AllPayLogisticsID'] ]['LogisticsType']    = $result['LogisticsType'];
				$shipping_list[ $result['AllPayLogisticsID'] ]['LogisticsSubType'] = $result['LogisticsSubType'];
				$shipping_list[ $result['AllPayLogisticsID'] ]['PaymentNo']        = $result['CVSPaymentNo'];
				$shipping_list[ $result['AllPayLogisticsID'] ]['ValidationNo']     = $result['CVSValidationNo'];
				$shipping_list[ $result['AllPayLogisticsID'] ]['store_ID']         = $args['ReceiverStoreID'];
				$shipping_list[ $result['AllPayLogisticsID'] ]['BookingNote']      = $result['BookingNote'];
				$shipping_list[ $result['AllPayLogisticsID'] ]['status']           = self::get_status($result);
				$shipping_list[ $result['AllPayLogisticsID'] ]['status_msg']       = self::get_status_msg($result);
				$shipping_list[ $result['AllPayLogisticsID'] ]['create']           = $create_datetime->format(DATE_ATOM);
				$shipping_list[ $result['AllPayLogisticsID'] ]['edit']             = (string) new WC_DateTime();
				$shipping_list[ $result['AllPayLogisticsID'] ]['amount']           = $args['GoodsAmount'];
				$shipping_list[ $result['AllPayLogisticsID'] ]['IsCollection']     = $args['IsCollection'];

				$order->update_meta_data('_ecpay_shipping_info', $shipping_list);
				$order->save_meta_data();

				do_action('ry_ecpay_shipping_get_cvs_no', $result, $shipping_list[ $result['AllPayLogisticsID'] ], $order);
			}

			do_action('ry_ecpay_shipping_get_all_cvs_no', $shipping_list, $order);
		}
	}

	private static function normalize_taiwan_mobile( string $phone ): string {
		// 移除所有非數字字元
		$cleaned = preg_replace('/\D+/', '', $phone);

		// 如果是以 "886" 開頭，去掉 "886"
		if (strpos($cleaned, '886') === 0) {
			$cleaned = substr($cleaned, 3);
		}

		// 如果是以 "09" 開頭，直接回傳
		if (strpos($cleaned, '09') === 0) {
			return $cleaned;
		}

		// 如果是以 "9" 開頭，補上 "0"
		if (strpos($cleaned, '9') === 0) {
			return '0' . $cleaned;
		}

		// 其他情況回傳原始清理後的字串
		return $cleaned;
	}

	/**
	 * 取得印單表單
	 *
	 * @param array $info 印單資訊
	 */
	public static function get_print_form( $info = null ) {
		list($MerchantID, $HashKey, $HashIV, $CVS_type) = RY_ECPay_Shipping::get_ecpay_api_info();

		$print_type = $info[0]['LogisticsSubType'];
		$CVS_type   = strpos($info[0]['LogisticsSubType'], 'C2C') === false ? 'B2C' : 'C2C';

		$args = [
			'MerchantID'        => $MerchantID,
			'AllPayLogisticsID' => [],
			'CVSPaymentNo'      => [],
			'CVSValidationNo'   => [],
		];

		foreach ($info as $item) {
			if ($item['LogisticsSubType'] == $print_type) {
				$args['AllPayLogisticsID'][] = $item['ID'];
			}
			if ($CVS_type == 'C2C') {
				$args['CVSPaymentNo'][] = $item['PaymentNo'];
			}
			if ($item['LogisticsSubType'] == 'UNIMARTC2C') {
				$args['CVSValidationNo'][] = $item['ValidationNo'];
			}
		}

		foreach ($args as $key => $value) {
			if (empty($value)) {
				unset($args[ $key ]);
			} elseif (is_array($value)) {
				$args[ $key ] = implode(',', $value);
			}
		}
		$args = self::add_check_value($args, $HashKey, $HashIV, 'md5');
		RY_ECPay_Shipping::log('Print info POST: ' . var_export($args, true));

		if (RY_ECPay_Shipping::$testmode) {
			if ($CVS_type == 'C2C') {
				$post_url = self::$api_test_url[ 'print_' . $print_type ];
			} else {
				$post_url = self::$api_test_url['print_B2C'];
			}
		} elseif ($CVS_type == 'C2C') {
			$post_url = self::$api_url[ 'print_' . $print_type ];
		} else {
			$post_url = self::$api_url['print_B2C'];
		}

		echo '<!DOCTYPE html><head><meta charset="' . get_bloginfo('charset', 'display') . '"></head><body>';
		echo '<form method="post" id="ry-ecpay-form" action="' . esc_url($post_url) . '" style="display:none;">';
		foreach ($args as $key => $value) {
			echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
		}
		echo '</form>';
		echo '<script>document.getElementById("ry-ecpay-form").submit();</script>';
		echo '</body></html>';
	}
}

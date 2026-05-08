<?php
/**
 * PayNow_Shipping_Request class file.
 *
 * @package paynow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handling PayNow shipping request api calls.
 */
class PayNow_Shipping_Request {

	/**
	 * The instance.
	 *
	 * @var PayNow_Shipping_Request
	 */
	private static $instance;

	/**
	 * Initialize the class andd add hooks.
	 */
	public static function init() {
		self::get_instance();

		add_action( 'woocommerce_order_status_processing', [ self::get_instance(), 'paynow_get_logistic_no' ], 10, 1 );
		add_action( 'paynow_shipping_order_created', [ self::get_instance(), 'paynow_query_shipping_order' ], 30, 1 );

		// 後台重選超商後需取消物流單再重新建立新的物流單.
		add_action( 'paynow_after_admin_changed_cvs_store', [ self::get_instance(), 'paynow_cancel_shipping_order_when_cvs_store_changed' ] );
		add_action( 'paynow_after_cancel_shipping_order_when_cvs_store_changed', [ self::get_instance(), 'paynow_get_logistic_no' ], 10, 1 );

		// order action.
		add_action( 'wp_ajax_create_order', [ self::get_instance(), 'paynow_ajax_get_logistic_no' ], 10, 1 );
		add_action( 'wp_ajax_renew_order', [ self::get_instance(), 'paynow_ajax_get_logistic_no' ], 10, 1 );
		add_action( 'wp_ajax_update_delivery_status', [ self::get_instance(), 'paynow_ajax_query_delivery_status' ], 10, 1 );
		add_action( 'wp_ajax_cancel_shipping_order', [ self::get_instance(), 'paynow_ajax_cancel_shipping_order' ], 10, 1 );
		add_action( 'paynow_shipping_after_order_cancelled', [ self::get_instance(), 'paynow_query_shipping_order' ], 10, 1 );

		add_action( 'wp_ajax_paynow_shipping_print_label', [ self::get_instance(), 'paynow_print_label' ] );
	}

	/**
	 * Get PayNow logistic number when order status changed to processing.
	 *
	 * @param WC_Order $order The order object.
	 * @return void
	 */
	public static function paynow_get_logistic_no( $order ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( empty( $order->get_meta( PayNow_Shipping_Order_Meta::LogisticServiceId ) ) ) {
			return;
		}
		try {

			do_action( 'paynow_shipping_before_create_order', $order );

			// status = 1, 無效訂單.
			if ( ! empty( $order->get_meta( PayNow_Shipping_Order_Meta::LogisticNumber ) && (string) $order->get_meta( PayNow_Shipping_Order_Meta::Status ) !== '1' ) ) {
				$response = self::renew_order( $order );
				$action   = 'renew';
			} else {
				$response = self::create_order( $order );
				$action   = 'create';
			}

			$resp_obj = json_decode( wp_remote_retrieve_body( $response ) );
			PayNow_Shipping::log( 'PayNow shipping order response:' . wc_print_r( $resp_obj, true ) );
			if ( 'F' === $resp_obj->Status ) {
				$order->add_order_note( __( 'Create shipping order failed. ' ) . $resp_obj->ErrorMsg );
				throw new Exception( $resp_obj->ErrorMsg );
			}

			$orderno = ( 'create' === $action ) ? $resp_obj->orderno : $resp_obj->OrderNo;
			if ( self::get_prefixed_order_no( $order ) === $orderno ) {

				if ( 'create' === $action ) {
					// 建立物流單才會有這兩個回傳值.
					$order->update_meta_data( PayNow_Shipping_Order_Meta::LogisticService, $resp_obj->LogisticService ); // 物流服務名稱, ex: 7-11 交貨便.
					$order->update_meta_data( PayNow_Shipping_Order_Meta::LogisticServiceId, $resp_obj->LogisticServiceID );// 物流服務代碼.
				}

				if ( 'renew' === $action ) {
					$order->update_meta_data( PayNow_Shipping_Order_Meta::RenewOrderNo, $resp_obj->paynoworderno );// 重新取號後訂單在 Paynow 的訂單編號，列印標籤需以此為訂單編號.
				}

				$order->update_meta_data( PayNow_Shipping_Order_Meta::LogisticNumber, $resp_obj->LogisticNumber );// paynow物流單號.
				$order->update_meta_data( PayNow_Shipping_Order_Meta::PaymentNo, $resp_obj->paymentno );// 物流商貨運編號.
				$order->update_meta_data( PayNow_Shipping_Order_Meta::ValidationNo, $resp_obj->validationno );// 物流商驗證碼.
				$order->update_meta_data( PayNow_Shipping_Order_Meta::ReturnMsg, $resp_obj->ReturnMsg );
				$order->save();

				if ( 'create' === $action ) {
					$order->add_order_note( __( 'Create shipping order successed. LogisticNumber: ', 'paynow-shipping' ) . $resp_obj->LogisticNumber );
				} else {
					$order->add_order_note( __( 'Renew shipping order successed. LogisticNumber: ', 'paynow-shipping' ) . $resp_obj->LogisticNumber );
				}

				do_action( 'paynow_shipping_order_created', $order, $action );

			} else {
				PayNow_Shipping::log( 'Order NO mismatch. Received order no: ' . $orderno );
				throw new Exception( 'Order NO mismatch. Received order no: ' . $orderno );
			}

			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		} catch ( Exception $e ) {
			PayNow_Shipping::log( __( 'Create shipping order failed. ' ) . $e->getMessage(), 'error' );
			throw $e;
		}
	}

	public static function paynow_cancel_shipping_order_when_cvs_store_changed( $order ) {

		$response = self::cancel_order( $order );
		PayNow_Shipping::log( 'Order ' . $order->get_id() . ' cancel shipping order. response: ' . wc_print_r( $response, true ) );

		if ( is_wp_error( $response ) ) {
			$order->add_order_note( __( 'PayNow shipping order cancel failed. Please cancel manually and recreate the shipping order again.', 'paynow-shipping' ) );
			return;
		}

		$resp = wp_remote_retrieve_body( $response );
		if ( strpos( $resp, 'S' ) !== false ) {
			// 取消成功
			$order->update_meta_data( PayNow_Shipping_Order_Meta::Status, '1' );// 無效訂單
			$order->save();
			$order->add_order_note( $resp );
			// 重新建立物流單
			do_action( 'paynow_after_cancel_shipping_order_when_cvs_store_changed', $order );
		} else {
			// 取消失敗
			$order->add_order_note( $resp );
			$order->add_order_note( __( 'PayNow shipping order cancel failed. Please cancel manually and recreate the shipping order again.', 'paynow-shipping' ) );
		}
	}

	/**
	 * Query shipping order after shipping order created to get the logistic status
	 *
	 * @param WC_Order $order The order object.
	 * @return void
	 */
	public function paynow_query_shipping_order( $order ) {
		if ( $order ) {
			$response = self::query_order( $order );

			if ( is_wp_error( $response ) ) {
				PayNow_Shipping::log( 'Query Order ' . $order->get_id() . ' status after created failed. Response json: ' . $response->get_error_message() );
				return;
			}

			$resp_json  = wp_remote_retrieve_body( $response );
			$resp_obj   = json_decode( $resp_json );
			$query_date = wp_remote_retrieve_header( $response, 'date' );
			PayNow_Shipping::log( 'Query Order ' . $order->get_id() . ' status. Response json: ' . $resp_json );

			self::update_order_logistic_meta( $order, $resp_obj, $query_date );
		}
	}

	/**
	 * Ajax get logistic no.
	 *
	 * @return void
	 */
	public static function paynow_ajax_get_logistic_no() {

		if ( ! check_ajax_referer( 'paynow-shipping-order', 'security', false ) ) {
			$return = [
				'success' => false,
				'result'  => __( 'Invalid security token sent.', 'paynow-shipping' ),
			];
			wp_send_json( $return );
			wp_die();
		}

		if ( ! isset( $_POST['post_id'] ) ) {
			$return = [
				'success' => false,
				'result'  => __( 'Missing Ajax Parameter.', 'paynow-shipping' ),
			];
			wp_send_json_error( $return );
			wp_die();
		}

		$post_id = wc_clean( wp_unslash( $_POST['post_id'] ) );
		$order   = wc_get_order( $post_id );

		try {
			self::paynow_get_logistic_no( $order );
		} catch ( Exception $e ) {
			$return = [
				'success' => false,
				'result'  => $e->getMessage(),
			];

			wp_send_json_error( $return );
			wp_die();
		}

		$return = [
			'success' => true,
			'result'  => $resp_obj,
		];
		wp_send_json( $return );
	}

	/**
	 * Handling query status ajax call.
	 *
	 * @return void
	 */
	public static function paynow_ajax_query_delivery_status() {

		if ( ! check_ajax_referer( 'paynow-shipping-order', 'security', false ) ) {
			$return = [
				'success' => false,
				'result'  => __( 'Invalid security token sent.', 'paynow-shipping' ),
			];
			wp_send_json( $return );
			wp_die();
		}

		if ( ! isset( $_POST['post_id'] ) ) {
			$return = [
				'success' => false,
				'result'  => __( 'Missing Ajax Parameter.', 'paynow-shipping' ),
			];
			wp_send_json_error( $return );
			wp_die();
		}

		$post_id = wc_clean( wp_unslash( $_POST['post_id'] ) );
		$order   = wc_get_order( $post_id );

		$response = self::query_order( $order );

		if ( is_wp_error( $response ) ) {
			$return = [
				'success' => false,
				'result'  => $response->get_error_message(),
			];
			wp_send_json_error( $return );
			wp_die();
		}

		$resp_json  = wp_remote_retrieve_body( $response );
		$resp_obj   = json_decode( $resp_json );
		$query_date = wp_remote_retrieve_header( $response, 'date' );
		PayNow_Shipping::log( 'Order ' . $order->get_id() . ' query delivery status response. Response json: ' . $resp_json );

		self::update_order_logistic_meta( $order, $resp_obj, $query_date );

		$return = [
			'success' => true,
			'result'  => $resp_obj,
		];
		wp_send_json( $return );
	}

	/**
	 * Handling cancel shipping order ajax call.
	 */
	public static function paynow_ajax_cancel_shipping_order() {

		if ( ! check_ajax_referer( 'paynow-shipping-order', 'security', false ) ) {
			$return = [
				'success' => false,
				'result'  => __( 'Invalid security token sent.', 'paynow-shipping' ),
			];
			wp_send_json( $return );
			wp_die();
		}

		if ( ! isset( $_POST['post_id'] ) ) {
			$return = [
				'success' => false,
				'result'  => __( 'Missing Ajax Parameter.', 'paynow-shipping' ),
			];
			wp_send_json_error( $return );
			wp_die();
		}

		$post_id = wc_clean( wp_unslash( $_POST['post_id'] ) );
		$order   = wc_get_order( $post_id );

		$response = self::cancel_order( $order );
		PayNow_Shipping::log( 'Order ' . $order->get_id() . ' cancel shipping order. response: ' . wc_print_r( $response, true ) );

		if ( is_wp_error( $response ) ) {
			$return = [
				'success' => false,
				'result'  => $response->get_error_message(),
			];
			wp_send_json( $return );
			wp_die();
		}

		$resp = wp_remote_retrieve_body( $response );
		if ( strpos( $resp, 'S' ) !== false ) {
			$order->add_order_note( $resp );
			do_action( 'paynow_shipping_after_order_cancelled', $order );

			$return = [
				'success' => true,
				'result'  => $resp,
			];
			wp_send_json( $return );
		} else {
			$order->add_order_note( $resp );
			$return = [
				'success' => false,
				'result'  => $resp,
			];
			wp_send_json( $return );
		}
	}

	/**
	 * Print shipping label
	 */
	public static function paynow_print_label() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['orderids'] ) || ! isset( $_GET['service'] ) ) {
			esc_html_e( 'Missing Ajax Parameter.', 'paynow-shipping' );
			wp_die();
		}
		$order_ids = wc_clean( wp_unslash( $_GET['orderids'] ) );
		$service   = wc_clean( wp_unslash( $_GET['service'] ) );

		$order_ids_array = explode( ',', $order_ids );
		$renew_order_ids = [];
		foreach ( $order_ids_array as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$renew_order_id = $order->get_meta( PayNow_Shipping_Order_Meta::RenewOrderNo );
				if ( $renew_order_id ) {
					$renew_order_ids[] = $renew_order_id;
				} else {
					$renew_order_ids[] = $order->get_order_number();
				}
			}
		}
		$renew_order_ids_string = implode( ',', $renew_order_ids );

		$api_url = '';
		if ( PayNow_Shipping_Logistic_Service::SEVEN === $service ) {
			$api_url = PayNow_Shipping::$api_url . '/api/Order711?orderNumberStr=' . $renew_order_ids_string . '&user_account=' . PayNow_Shipping::$user_account;
		} elseif ( PayNow_Shipping_Logistic_Service::FAMI === $service ) {
			$api_url = PayNow_Shipping::$api_url . '/api/OrderFamiC2C?orderNumberStr=' . $renew_order_ids_string . '&user_account=' . PayNow_Shipping::$user_account;
		} elseif ( PayNow_Shipping_Logistic_Service::HILIFE === $service ) {
			$api_url = PayNow_Shipping::$api_url . '/api/OrderHiLife?orderNumberStr=' . $renew_order_ids_string . '&user_account=' . PayNow_Shipping::$user_account;
		} elseif ( PayNow_Shipping_Logistic_Service::TCAT === $service ) {
			$api_url = PayNow_Shipping::$api_url . '/Member/Order/PrintBlackCatLabel';
		} elseif ( PayNow_Shipping_Logistic_Service::SEVENBULK === $service ) {
			$api_url = PayNow_Shipping::$api_url . '/Member/Order/Print711bulkLabel';
		} elseif ( PayNow_Shipping_Logistic_Service::FAMIBULK === $service ) {
			$api_url = PayNow_Shipping::$api_url . '/Member/Order/PrintFamiB2CLabel';
		} elseif ( PayNow_Shipping_Logistic_Service::SEVENFROZEN === $service ) {
			$api_url = PayNow_Shipping::$api_url . '/Member/Order/Print711FreezingB2CLabel';
		}  elseif ( PayNow_Shipping_Logistic_Service::SEVENFROZEN_C2C === $service ) {
			$api_url = PayNow_Shipping::$api_url . '/Member/Order/Print711FreezingC2CLabel';
		} elseif ( PayNow_Shipping_Logistic_Service::FAMIFROZEN === $service ) {
			$api_url = PayNow_Shipping::$api_url . '/Member/Order/PrintFamiFreezingB2CLabel';
		} elseif ( PayNow_Shipping_Logistic_Service::FAMIFROZEN_C2C === $service ) {
			$api_url = PayNow_Shipping::$api_url . '/Member/Order/PrintFamiFreezingC2CLabel';
		} else {
			esc_html_e( 'Unsupported shipping service.', 'paynow-shipping' );
			wp_die();
		}

		PayNow_Shipping::log( 'Print shipping label. api_url: ' . $api_url );

		if ( PayNow_Shipping_Logistic_Service::TCAT !== $service && PayNow_Shipping_Logistic_Service::SEVENBULK !== $service && PayNow_Shipping_Logistic_Service::FAMIBULK !== $service &&
		PayNow_Shipping_Logistic_Service::FAMIFROZEN !== $service && PayNow_Shipping_Logistic_Service::SEVENFROZEN !== $service && PayNow_Shipping_Logistic_Service::SEVENFROZEN_C2C !== $service && PayNow_Shipping_Logistic_Service::FAMIFROZEN_C2C !== $service ) {
			$response = wp_remote_get( $api_url );
			PayNow_Shipping::log( 'label:' . wc_print_r( $response, true ) );
			if ( is_wp_error( $response ) ) {
				return false;
			}
			$data = wp_remote_retrieve_body( $response );

			$status = substr( str_replace( '"', '', $data ), 0, 1 );
			PayNow_Shipping::log( 'label status:' . wc_print_r( $status, true ) );
			if ( 'S' === $status ) {
				$label_url = substr( $data, 3 );
				wp_redirect( $label_url );
				die();
			} else {
				wp_die( esc_html( __( 'Fail to get print label', 'paynow-shipping' ) ) );
			}
		} else {
			// tcat、seven bulk、family bulk、family frozen and seven frozen.
			$logistic_nos = [];
			$order_ids    = explode( ',', $order_ids );
			foreach ( $order_ids as $order_id ) {
				$label_order = wc_get_order( $order_id );
				$logistic_no = $label_order ? $label_order->get_meta( PayNow_Shipping_Order_Meta::LogisticNumber ) : '';
				if ( $logistic_no ) {
					$logistic_nos[] = $logistic_no . '_1';
				}
			}

			if ( empty( $logistic_nos ) ) {
				esc_html_e( 'No logistic number', 'paynow-shipping' );
				wp_die();
			}

			$logistic_nos_request_str = implode( ',', $logistic_nos );

			$response = wp_remote_post(
				$api_url,
				[
					'timeout'     => 45,
					'httpversion' => '1.0',
					'blocking'    => true,
					'headers'     => [
						'Content-Type' => 'application/x-www-form-urlencoded',
						'User-Agent'   => 'WordPress',
					],
					'body'        => [
						'LogisticNumbers' => $logistic_nos_request_str,
					],
				]
			);
			PayNow_Shipping::log( 'label:' . wc_print_r( $response, true ) );

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$header_content = (array) wp_remote_retrieve_headers( $response );
			$header         = current( $header_content );
			$data           = wp_remote_retrieve_body( $response );
			if ( array_key_exists( 'content-type', $header ) ) {
				if ( $header['content-type'] === 'application/pdf' ) {
					header( 'Content-type: application/pdf' );
					header( 'Content-disposition: attachment;filename=paynow-tcat-' . date( 'Y-m-d' ) . '.pdf' );
					echo $data;
					wp_die();
				} else {
					echo $data;
					wp_die();

				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * PayNow add order api call.
	 *
	 * @param  WC_Order $order The order object.
	 * @return WP_Error|array
	 * @throws Exception Throws exception when create order failed.
	 */
	public static function create_order( $order ) {

		$request_args = self::build_add_order_args( $order );
		PayNow_Shipping::log( 'Create PayNow shipping order request args:' . wc_print_r( $request_args, true ), 'info' );
		$encrypt_json = self::build_encrypted_args( $request_args );
		$url          = PayNow_Shipping::$api_url . '/api/Orderapi/Add_Order';

		$response = wp_remote_post(
			$url,
			[
				'timeout'     => 45,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'User-Agent'   => 'WordPress',
				],
				'body'        => [
					'JsonOrder' => $encrypt_json,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			$order->add_order_note( __( 'Create shipping order failed. ', 'paynow-shipping' ) . $response->get_error_message() );
			PayNow_Shipping::log( 'Create PayNow shipping order:' . wc_print_r( $response, true ), 'error' );
			throw new Exception( $response->get_error_message(), $response->get_error_code() );
		}

		return $response;
	}

	/**
	 * PayNow cancel order api call.
	 *
	 * @param WC_Order $order The order object.
	 * @return WP_Error|array
	 */
	public static function cancel_order( $order ) {

		$logistic_no = $order->get_meta( PayNow_Shipping_Order_Meta::LogisticNumber );
		$passcode    = self::build_pass_code( $order );
		$url         = PayNow_Shipping::$api_url . '/api/Orderapi/CancelOrder';

		$response = wp_remote_request(
			$url,
			[
				'method' => 'DELETE',
				'body'   => [
					'LogisticNumber' => $logistic_no, // paynow 物流單號.
					'sno'            => '1',
					'PassCode'       => $passcode,
				],
			]
		);

		return $response;
	}

	/**
	 * PayNow renew order api call.
	 *
	 * @param WC_Order $order The order object.
	 * @return WP_Error|array
	 * @throws Exception Throws exception when renew failed.
	 */
	public static function renew_order( $order ) {

		$request_args = [
			'user_account'   => PayNow_Shipping::$user_account,
			'apicode'        => PayNow_Shipping::$apicode,
			'OrderNo'        => self::get_prefixed_order_no( $order ),
			'LogisticNumber' => $order->get_meta( PayNow_Shipping_Order_Meta::LogisticNumber ),
			'PassCode'       => self::build_pass_code( $order ),
			'TotalAmount'    => $order->get_total(),
			'sno'            => 1,
		];

		PayNow_Shipping::log( 'renew order args:' . wc_print_r( $request_args, true ) );

		$encrypt_json = self::build_encrypted_args( $request_args );
		$url          = PayNow_Shipping::$api_url . '/api/Orderapi/ReNewOrder';

		$response = wp_remote_post(
			$url,
			[
				'timeout'     => 45,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'User-Agent'   => 'WordPress',
				],
				'body'        => [
					'JsonOrder' => $encrypt_json,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			$order->add_order_note( __( 'Renew shipping order failed. ', 'paynow-shipping' ) . $response->get_error_message() );
			PayNow_Shipping::log( 'Renew PayNow shipping order failed:' . wc_print_r( $response, true ), 'error' );
			throw new Exception( $response->get_error_message(), $response->get_error_code() );
		}

		return $response;
	}

	/**
	 * PayNow query order api call.
	 *
	 * @param WC_Order $order The order object.
	 * @return WP_Error|array
	 */
	public static function query_order( $order ) {

		$logistic_no = $order->get_meta( PayNow_Shipping_Order_Meta::LogisticNumber );

		$url      = PayNow_Shipping::$api_url . '/api/Orderapi/Get_Order_Info?LogisticNumber=' . $logistic_no . '&sno=1';
		$response = wp_remote_get(
			$url,
			[
				'timeout'     => 45,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'User-Agent'   => 'WordPress',
				],
			]
		);
		return $response;
	}

	/**
	 * Build API call needed arguments.
	 *
	 * @param WC_Order $order The order object.
	 * @return array
	 */
	private static function build_add_order_args( $order ) {

		$reciever = self::build_receiver_info( $order );
		$args     = [];

		$args['Description']      = self::get_items_infos( $order );
		$args['DeliverMode']      = ( 'cod' === $order->get_payment_method() ) ? '01' : '02';// 01:取貨付款 02:取貨不付款.
		$args['Logistic_service'] = $order->get_meta( PayNow_Shipping_Order_Meta::LogisticServiceId );
		$args['user_account']     = PayNow_Shipping::$user_account;
		$args['apicode']          = PayNow_Shipping::$apicode;
		$args['OrderNo']          = self::get_prefixed_order_no( $order );

		$args['Receiver_address'] = $reciever['address'];// 若為交貨便請填店家地址.
		$args['Receiver_Email']   = $order->get_billing_email();
		$args['Receiver_Name']    = $order->get_shipping_last_name() . $order->get_shipping_first_name();
		$args['Receiver_Phone']   = PayNow_Shipping::paynow_get_shipping_phone( $order );

		$args['Remark'] = '';

		$args['Sender_address'] = get_option( 'paynow_shipping_sender_address' );
		$args['Sender_Name']    = get_option( 'paynow_shipping_sender_name' );
		$args['Sender_Phone']   = get_option( 'paynow_shipping_sender_phone' );
		$args['Sender_Email']   = get_option( 'paynow_shipping_sender_email' );

		$args['receiver_storeid']   = $reciever['store_id'];
		$args['receiver_storename'] = $reciever['store_name'];

		$args['return_storeid'] = '';
		$args['PassCode']       = self::build_pass_code( $order );
		$args['TotalAmount']    = $order->get_total();// 超取金額不得大於20,000，宅配宅配金額不大於100,000.
		$args['EC']             = 'EC 平台';

		// 黑貓宅配，使用 s60 規格.
		if ( $order->get_meta( PayNow_Shipping_Order_Meta::LogisticServiceId ) === PayNow_Shipping_Logistic_Service::TCAT ) {
			$args['DeliveryType'] = $order->get_meta( PayNow_Shipping_Order_Meta::DeliveryType );
			$args['Weight']       = '5';
			$args['Length']       = '5';
			$args['Width']        = '4';
			$args['Height']       = '3';
		}

		return apply_filters( 'paynow_shipping_order_request_args', $args, $order );
	}


	/**
	 * Build receiver infomation.
	 *
	 * @param WC_Order $order The order object.
	 * @return array
	 */
	private static function build_receiver_info( $order ) {
		if ( ! empty( $order->get_meta( PayNow_Shipping_Order_Meta::LogisticServiceId ) ) && $order->get_meta( PayNow_Shipping_Order_Meta::LogisticServiceId ) !== PayNow_Shipping_Logistic_Service::TCAT ) {
			$reciever['store_name'] = $order->get_meta( PayNow_Shipping_Order_Meta::StoreName );
			$reciever['store_id']   = $order->get_meta( PayNow_Shipping_Order_Meta::StoreId );
			$reciever['address']    = $order->get_meta( PayNow_Shipping_Order_Meta::StoreAddr );
		} else {
			$reciever = [
				'store_name' => '',
				'store_id'   => '',
				'address'    => self::paynow_get_api_order_address( $order ),
			];
		}

		return $reciever;
	}

	/**
	 * Update order logistic meta_box_prefs( $screen:WP_Screen )
	 *
	 * @param WC_Order $order The order object.
	 * @param object   $resp_obj The PayNow query order response object.
	 * @return void
	 */
	private static function update_order_logistic_meta( $order, $resp_obj, $query_date ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$order->update_meta_data( PayNow_Shipping_Order_Meta::LogisticNumber, $resp_obj->LogisticNumber ); // paynow 托運單號.
		$order->update_meta_data( PayNow_Shipping_Order_Meta::SNO, $resp_obj->sno ); // 物流單序號.
		$order->update_meta_data( PayNow_Shipping_Order_Meta::Status, $resp_obj->Status ); // 0:成立中訂單 1:無效訂單.
		$order->update_meta_data( PayNow_Shipping_Order_Meta::DeliveryStatus, $resp_obj->Delivery_Status ); // 流程狀態描述.
		$order->update_meta_data( PayNow_Shipping_Order_Meta::LogisticCode, $resp_obj->PayNowLogisticCode ); // PayNow 物流代碼.
		$order->update_meta_data( PayNow_Shipping_Order_Meta::DetailStatusDesc, $resp_obj->Detail_Status_Description ); // PayNow物流代碼詳細資訊.
		$order->update_meta_data( PayNow_Shipping_Order_Meta::PaymentNo, $resp_obj->paymentno ); // 物流商託運單號.
		$order->update_meta_data( PayNow_Shipping_Order_Meta::ValidationNo, $resp_obj->validationno ); // 驗證碼.
		$query_date = new DateTime( $query_date, new DateTimeZone( 'Asia/Taipei' ) );
		$order->update_meta_data( PayNow_Shipping_Order_Meta::StatusUpdateAt, $query_date->format( 'Y/m/d H:i:s' ) );
		$order->save();
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Build order address for api call.
	 *
	 * @param WC_Order $order The order object.
	 * @return string
	 */
	private static function paynow_get_api_order_address( $order ) {
		$address  = '';
		$address .= $order->get_shipping_city();
		$address .= $order->get_shipping_state();
		$address .= $order->get_shipping_address_1();
		$address .= $order->get_shipping_address_2();
		return $address;
	}

	/**
	 * Build encrypted json for api call.
	 *
	 * @param array $request_args The request arguments for building encrypted json.
	 * @return string
	 */
	public static function build_encrypted_args( $request_args ) {
		$json_string = wp_json_encode( $request_args );
		if ( strlen( $json_string ) % 8 ) {
			$json_string = str_pad( $json_string, strlen( $json_string ) + 8 - strlen( $json_string ) % 8, "\0" );
		}

		$ciphertext = self::build_ciphertext( $json_string );

		if ( ! $ciphertext ) {
			//phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			while ( $msg = openssl_error_string() ) {
				PayNow_Shipping::log( 'openssl error: ' . $msg, 'error' );
			}
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$encrypt_json = base64_encode( $ciphertext );

		return $encrypt_json;
	}

	/**
	 * Build cypher text.
	 *
	 * @param string $text The plain text to be encrypted.
	 * @return string
	 */
	public static function build_ciphertext( $text ) {
		$iv  = utf8_encode( '12345678' );
		$key = utf8_encode( '123456789070828783123456' );

		$method = 'DES-EDE3';
		$option = OPENSSL_NO_PADDING;

		return openssl_encrypt( $text, $method, $key, $option, $iv );
	}

	/**
	 * Get order number with prefix. Allow developer to use filter to change the prefix. Defaut prefix is empty.
	 *
	 * @param WC_Order $order The order object.
	 * @return string
	 */
	private static function get_prefixed_order_no( $order ) {
		$prefix = apply_filters( 'paynow_shipping_order_prefix', '' );
		return $prefix . $order->get_order_number();
	}

	/**
	 * Build pass code for api use.
	 *
	 * @param WC_Order $order The order object.
	 * @param string   $mode Build pass code mode.
	 * @return string
	 */
	private static function build_pass_code( $order, $mode = 'new' ) {
		if ( 'new' === $mode ) {
			$order_no = self::get_prefixed_order_no( $order );
		} else {
			$order_no = $order->get_meta( PayNow_Shipping_Order_Meta::LogisticNumber );
			PayNow_Shipping::log( 'renew order passcode for order no:' . $order_no );
		}

		// rule: user_account + OrderNo + TotalAmount +商家 API密碼.
		return strtoupper( hash( 'sha1', PayNow_Shipping::$user_account . $order_no . $order->get_total() . PayNow_Shipping::$apicode ) );
	}

	/**
	 * Build items as string
	 *
	 * @param WC_Order $order The order object.
	 * @return string
	 */
	private static function get_items_infos( $order ) {
		$items     = $order->get_items();
		$item_name = '';
		foreach ( $items as $item ) {
			$item_name .= $item['name'] . 'X' . $item['quantity'];
			if ( end( $items )['name'] !== $item['name'] ) {
				$item_name .= ',';
			}
		}

		// 過濾特殊字元，僅允許英文、數字和中文.
		$cleaned_item_name = preg_replace( '[^A-Za-z0-9 \p{Han}]+/u', '', $item_name );
		$cleaned_item_name = mb_substr( $cleaned_item_name, 0, 25 );
		return $cleaned_item_name;
	}

	/**
	 * Constructor
	 */
	public function __construct() {
	}

	/**
	 * Initialize the class and return instance.
	 *
	 * @return PayNow_Shipping_Request
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

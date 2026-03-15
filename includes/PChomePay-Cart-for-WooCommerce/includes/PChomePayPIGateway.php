<?php

/**
 * PI 錢包
 */

class WC_PI_Gateway_PChomePay extends WC_Gateway_PChomePay {

	public function __construct() {
		parent::__construct();

		$this->id                 = 'pchomepay_pi';
		$this->enabled            = $this->get_option( 'enabled' );
		$this->has_fields         = false;
		$this->method_title       = __( 'PChomePay PI-拍錢包', 'woocommerce' );
		$this->method_description = __( '透過 PChomePay PI-拍錢包 付款，會連結到 PChomePay支付連 付款頁面。', 'woocommerce' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		if ( empty( $this->app_id ) || empty( $this->secret ) ) {
			$this->enabled = false;
		} else {
			$this->client = new PChomePayClient( $this->app_id, $this->secret, $this->sandbox_secret, $this->test_mode, self::$log_enabled );
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), [ $this, 'receive_response' ] );
		add_filter( 'https_ssl_verify', '__return_false' );
	}

	public function is_available() {
		return wc_string_to_bool( $this->get_option( 'enabled' ) );
	}

	public function init_form_fields() {
		$this->form_fields = [
			'enabled'     => [
				'title'   => __( 'Enable/Disable', 'woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable', 'woocommerce' ),
				'default' => 'no',
			],
			'title'       => [
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
				'default'     => __( 'PChomePay PI-拍錢包', 'woocommerce' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
				'default'     => __( '透過 PChomePay PI-拍錢包 付款，會連結到 PChomePay PI-拍錢包 付款頁面。', 'woocommerce' ),
			],
		];
	}

	public function admin_options() {
		WC_Payment_Gateway::admin_options();
	}

	private function get_pchomepay_pi_payment_data( $order ) {
		global $woocommerce;

		$order_id    = 'AW' . date( 'Ymd' ) . $order->get_order_number();
		$pay_type    = [ 'PI' ];
		$amount      = ceil( $order->get_total() );
		$returnUrl   = $this->get_return_url( $order );
		$notifyUrl   = $this->notify_url;
		$buyer_email = $order->get_billing_email();

		$items = [];

		$order_items = $order->get_items();
		foreach ( $order_items as $item ) {
			$product         = [];
			$order_item      = new WC_Order_Item_Product( $item );
			$product_id      = ( $order_item->get_product_id() );
			$product['name'] = $order_item->get_name();
			$product['url']  = get_permalink( $product_id );

			$items[] = (object) $product;
		}

		$pchomepay_args = [
			'order_id'    => $order_id,
			'pay_type'    => $pay_type,
			'amount'      => $amount,
			'return_url'  => $returnUrl,
			'notify_url'  => $notifyUrl,
			'items'       => $items,
			'buyer_email' => $buyer_email,
		];

		return apply_filters( 'woocommerce_pchomepay_args', $pchomepay_args );
	}

	public function process_payment( $order_id ) {
		try {
			global $woocommerce;

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				throw new Exception( '無法取得訂單資料。' );
			}

			$pchomepay_args = json_encode( $this->get_pchomepay_pi_payment_data( $order ) );

			if ( ! class_exists( 'PChomePayClient' ) ) {
				if ( ! require __DIR__ . '/PChomePayClient.php' ) {
					throw new Exception( __( 'PChomePayClient Class missed.', 'woocommerce' ) );
				}
			}

			// 建立訂單
			$result = $this->client->postPayment( $pchomepay_args );

			if ( ! $result ) {
				self::log( '交易失敗：伺服器端未知錯誤，請聯絡 PChomePay支付連。' );
				throw new Exception( '嘗試使用付款閘道 API 建立訂單時發生錯誤，請聯絡網站管理員。' );
			}

			// 減少庫存
			wc_reduce_stock_levels( $order_id );
			// 清空購物車
			$woocommerce->cart->empty_cart();

			// 更新訂單狀態為等待中 (等待第三方支付網站返回)
			$order->update_meta_data( '_pchomepay_orderid', $result->order_id );
			$order->save();
			$order->update_status( 'pending', __( 'Awaiting PChomePay payment', 'woocommerce' ) );
			$order->add_order_note( '訂單編號：' . $result->order_id, true );
			// 返回感謝購物頁面跳轉
			return [
				'result'       => 'success',
				// 'redirect' => $order->get_checkout_payment_url(true)
					'redirect' => $result->payment_url,
			];

		} catch ( Exception $e ) {
			wc_add_notice( __( $e->getMessage(), 'woocommerce' ), 'error' );
		}
	}

	public function receive_response() {
		usleep( 500000 );

		$notify_type    = $_REQUEST['notify_type'];
		$notify_message = $_REQUEST['notify_message'];

		$refund_array = [ 'refund_pending', 'refund_success', 'refund_fail' ];

		if ( in_array( $notify_type, $refund_array ) ) {
			$order_data = json_decode( str_replace( '\"', '"', $notify_message ) );

			$order = wc_get_order( substr( $order_data->refund_id, 13 ) );

			if ( $order ) {
				$order->add_order_note( 'Notify_Type:' . $notify_type . '<br>Notify_Message: ' . $notify_message, true );
			}
			echo 'success';
			exit();
		}

		if ( ! $notify_type || ! $notify_message ) {
			http_response_code( 404 );
			exit;
		}

		$order_data  = json_decode( str_replace( '\"', '"', $notify_message ) );
		$wc_order_id = substr( $order_data->order_id, 10 );
		$order       = wc_get_order( $wc_order_id );

		if ( ! $order ) {
			http_response_code( 404 );
			exit;
		}

		// 紀錄訂單付款方式
		$pay_type_note = 'PI拍錢包 付款';

		if ( ! $order->get_meta( '_pchomepay_paytype' ) ) {
			$order->update_meta_data( '_pchomepay_paytype', $order_data->pay_type );
			$order->save();
		}

		if ( $notify_type == 'order_audit' ) {
			if ( $order_data->status_code === OrderStatusCodeEnum::ORDER_PENDING_CLIENT ) {
				$order->update_status( 'awaiting' );
			} elseif ( $order_data->status_code === OrderStatusCodeEnum::ORDER_PENDING_PCHOMEPAY ) {
				$order->update_status( 'awaitingforpcpay' );
			}

			$order->add_order_note( sprintf( __( '訂單交易等待中。<br>status code: %1$s<br>message: %2$s', 'woocommerce' ), $order_data->status_code, OrderStatusCodeEnum::getErrMsg( $order_data->status_code ) ), true );
		} elseif ( $notify_type == 'order_expired' ) {
			$order->add_order_note( $pay_type_note, true );
			if ( $order_data->status_code ) {
				$order->update_status( 'failed' );
				$order->add_order_note( sprintf( __( '訂單已失敗。<br>error code: %1$s<br>message: %2$s', 'woocommerce' ), $order_data->status_code, OrderStatusCodeEnum::getErrMsg( $order_data->status_code ) ), true );
			} else {
				$order->update_status( 'failed' );
				$order->add_order_note( '訂單已失敗。', true );
			}
		} elseif ( $notify_type == 'order_confirm' ) {
			$order->add_order_note( $pay_type_note, true );
			$order->update_status( 'processing' );
			$order->payment_complete();
		}

		echo 'success';
		exit();
	}

	private function get_pchomepay_refund_data( $orderID, $amount, $refundID = null ) {
		try {
			global $woocommerce;

			$order = $this->client->getPayment( $orderID );

			if ( ! $order ) {
				self::log( '查無此筆訂單：' . $orderID );
			}

			$order_id = $order->order_id;

			if ( $amount === $order->amount ) {
				$refund_id = 'RF' . $order_id;
			} elseif ( $refundID ) {
				$number    = (int) substr( $refundID, strpos( $refundID, '-' ) + 1 ) + 1;
				$refund_id = 'RF' . $order_id . '-' . $number;
			} else {
				$refund_id = 'RF' . $order_id . '-1';
			}

			$trade_amount   = (int) $amount;
			$pchomepay_args = [
				'order_id'     => $order_id,
				'refund_id'    => $refund_id,
				'trade_amount' => $trade_amount,
			];

			return apply_filters( 'woocommerce_pchomepay_args', $pchomepay_args );
		} catch ( Exception $e ) {
			self::log( $e->getMessage() );
			throw $e;
		}
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		try {
			$wcOrder = wc_get_order( $order_id );

			if ( ! $wcOrder ) {
				throw new Exception( '無法取得訂單資料。' );
			}

			$orderID   = $wcOrder->get_meta( '_pchomepay_orderid' );
			$refundIDs = $wcOrder->get_meta( '_pchomepay_refundid' );

			if ( $refundIDs ) {
				$refundID = trim( strrchr( $refundIDs, ',' ), ', ' ) ? trim( strrchr( $refundIDs, ',' ), ', ' ) : $refundIDs;
			} else {
				$refundID = $refundIDs;
			}

			$pchomepay_args = json_encode( $this->get_pchomepay_refund_data( $orderID, $amount, $refundID ) );

			if ( ! class_exists( 'PChomePayClient' ) && ! require __DIR__ . '/PChomePayClient.php' ) {
				throw new Exception( __( 'PChomePayClient Class missed.', 'woocommerce' ) );
			}

			$payType = $wcOrder->get_meta( '_pchomepay_paytype' );

			$version = ( in_array( $payType, [ 'IPL7', 'IPPI' ] ) ) ? 'v1' : 'v2';

			// 退款
			$response_data = $this->client->postRefund( $pchomepay_args, $version );

			if ( ! $response_data ) {
				self::log( '退款失敗：伺服器端未知錯誤，請聯絡 PChomePay支付連。' );
				return false;
			}

			if ( isset( $response_data->refund_id ) ) {
				// 更新 meta
				if ( $refundID ) {
					$wcOrder->update_meta_data( '_pchomepay_refundid', $refundIDs . ', ' . $response_data->refund_id );
				} else {
					$wcOrder->update_meta_data( '_pchomepay_refundid', $response_data->refund_id );
				}

				if ( isset( $response_data->redirect_url ) ) {
					$wcOrder->update_meta_data( '_pchomepay_refund_url', $response_data->refund_id . ' : ' . $response_data->redirect_url );
				}
			}

			$wcOrder->save();
			$wcOrder->add_order_note( '退款編號：' . json_decode( $pchomepay_args )->refund_id, true );

			return true;
		} catch ( Exception $e ) {
			self::log( $e->getMessage() );
			throw $e;
		}
	}
}

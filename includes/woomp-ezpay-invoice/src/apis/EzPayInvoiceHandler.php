<?php

namespace WOOMPEZPAYINVOICE\APIs;

use InvoicePorter\EzpayInvoice;

defined( 'ABSPATH' ) || exit;

class EzPayInvoiceHandler {


	/**
	 * EzPay invoice object
	 *
	 * @var object
	 */
	private $invoice;

	/**
	 * Construct
	 *
	 * @param object|null $invoice 測試時可注入假的 EzpayInvoice；未傳入時依設定建立真正的 API 物件。
	 */
	public function __construct( $invoice = null ) {
		if ( null !== $invoice ) {
			$this->invoice = $invoice;
			return;
		}

		if ( ( wc_string_to_bool( get_option( 'wc_woomp_ezpay_invoice_testmode_enabled' ) ) ) ) {
			$account       = [
				'merchantID' => get_option( 'wc_woomp_ezpay_invoice_merchant_id_test' ),
				'hashKey'    => get_option( 'wc_woomp_ezpay_invoice_hashkey_test' ),
				'hashIV'     => get_option( 'wc_woomp_ezpay_invoice_hashiv_test' ),
			];
			$is_production = false;
		} else {
			$account       = [
				'merchantID' => get_option( 'wc_woomp_ezpay_invoice_merchant_id' ),
				'hashKey'    => get_option( 'wc_woomp_ezpay_invoice_hashkey' ),
				'hashIV'     => get_option( 'wc_woomp_ezpay_invoice_hashiv' ),
			];
			$is_production = true;
		}
		$this->invoice = new EzpayInvoice( $account, $is_production );
	}

	/**
	 * Get issue data.
	 *
	 * @param int $order_id Order Id.
	 *
	 * @return array $issue_data Invoice issue data.
	 */
	private function get_issue_data( $order_id ) {

		$order              = \wc_get_order( $order_id );
		$ezpay_invoice_data = (array) $order->get_meta( '_ezpay_invoice_data' );

		if ( '0' === $order->get_total() || ! $ezpay_invoice_data ) {
			return;
		}

		// 取得顧客發票資訊.
		$invoice_type       = $ezpay_invoice_data['_ezpay_invoice_type'] ?? '';
		$invoice_individual = $ezpay_invoice_data['_ezpay_invoice_individual'] ?? '';

		switch ( $invoice_individual ) {
			case '手機條碼':
				$invoice_individual = 0;
				break;
			case '自然人憑證':
				$invoice_individual = 1;
				break;
			case 'ezPay 電子發票載具':
			case '雲端電子發票載具':
				$invoice_individual = 2;
				break;
			default:
				$invoice_individual = '';
				break;
		}

		$invoice_carrier      = $ezpay_invoice_data['_ezpay_invoice_carrier'] ?? '';
		$invoice_company_name = $ezpay_invoice_data['_ezpay_invoice_company_name'] ?? '';
		$invoice_tax_id       = $ezpay_invoice_data['_ezpay_invoice_tax_id'] ?? '';
		$invoice_donate       = $ezpay_invoice_data['_ezpay_invoice_donate'] ?? '';

		// 取得商品相關資訊.
		$i             = 0;
		$product_name  = '';
		$product_count = '';
		$product_unit  = '';
		$product_price = '';
		$product_amt   = '';

		$items = $order->get_items();
		foreach ( $items as $item ) {
			/**
			 * @var \WC_Order_Item_Product $item
			 */
			if (!( $item instanceof \WC_Order_Item_Product )) {
				continue;
			}

			$qty        = $item->get_quantity();
			$subtotal   = round( (float) $item->get_subtotal(), 2);
			$unit_price = $qty ? ( $subtotal / $qty ) : 0;
			$unit_price = round($unit_price, 2);

			$divide        = ( $i > 0 ) ? '|' : '';
			$product_name .= $divide . preg_replace( '/[\s|()｜（）]+/u', '-', mb_substr( $item->get_name(), 0, 30, 'UTF-8' ) );
			// $product_name .= $divide . '網路商品';
			$product_count .= $divide . str_replace( ' ', '', $qty );
			$product_unit  .= $divide . '件';

			$product_price .= $divide . $unit_price;
			$product_amt   .= $divide . $subtotal;

			++$i;
		}

		// coupons
		$coupons = $order->get_items('coupon');
		foreach ( $coupons as $coupon ) {
			/**
			 * @var \WC_Order_Item_Coupon $coupon
			 */
			if (!( $coupon instanceof \WC_Order_Item_Coupon )) {
				continue;
			}

			$qty            = $coupon->get_quantity();
			$discount       = -1 * $coupon->get_discount();
			$total_discount = ( (float) $discount )* $qty;

			$divide        = ( $i > 0 ) ? '|' : '';
			$product_name .= $divide . preg_replace( '/[\s|()｜（）]+/u', '-', mb_substr( $coupon->get_name(), 0, 30, 'UTF-8' ) );
			// $product_name .= $divide . '網路商品';
			$product_count .= $divide . str_replace( ' ', '', $qty );
			$product_unit  .= $divide . '式';
			$product_price .= $divide . $discount;
			$product_amt   .= $divide . $total_discount;

			++$i;
		}

		// 運費
		$shipping_total = number_format( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax(), wc_get_price_decimals(), '.', '' );
		if ( $shipping_total != 0 ) {
			$divide         = ( $i > 0 ) ? '|' : '';
			$product_name  .= $divide . '運費';
			$product_count .= $divide . 1;
			$product_unit  .= $divide . '式';
			$product_price .= $divide . $shipping_total;
			$product_amt   .= $divide . $shipping_total;
			++$i;
		}

		// 取得費用
		$fee_amount = 0;
		$fees       = $order->get_fees();
		foreach ( $fees as $fee ) {
			$fee_amount += (float) $fee->get_amount();
		}
		$fee_amount = round( (float) $fee_amount, 2 );
		if ( $fee_amount !== 0 ) {
			$divide         = ( $i > 0 ) ? '|' : '';
			$product_name  .= $divide . '費用';
			$product_count .= $divide . 1;
			$product_unit  .= $divide . '式';
			$product_price .= $divide . $fee_amount;
			$product_amt   .= $divide . $fee_amount;
			++$i;
		}

		/**
		 * B2C
		 */
		$issue_data['MerchantOrderNo'] = substr( get_option( 'wc_woomp_ezpay_invoice_order_prefix' ) . $order_id . '_' . time(), 0, 20 );
		$issue_data['Status']          = 1;
		$issue_data['Category']        = 'B2C';
		$issue_data['BuyerName']       = $order->get_billing_last_name() . $order->get_billing_first_name();
		$issue_data['BuyerAddress']    = $order->get_billing_postcode() . $order->get_billing_country() . $order->get_billing_state() . $order->get_billing_city() . $order->get_billing_address_1() . $order->get_billing_address_2();
		$issue_data['BuyerEmail']      = $order->get_billing_email();
		$issue_data['CarrierType']     = $invoice_individual;
		$issue_data['CarrierNum']      = ( 2 === $invoice_individual ) ? rawurlencode( $order->get_billing_email() ) : $invoice_carrier;
		$issue_data['PrintFlag']       = ( '' === $invoice_individual && '' === $invoice_donate ) ? 'Y' : 'N'; // 索取紙本發票.
		$issue_data['TaxType']         = 1; // 課稅別.
		$issue_data['TaxRate']         = 5; // 稅率5%.
		$issue_data['Amt']             = round( $order->get_total() / 1.05 ); // 未稅.
		$issue_data['TaxAmt']          = $order->get_total() - round( $order->get_total() / 1.05 ); // 稅額.
		$issue_data['TotalAmt']        = $order->get_total(); // 發票金額.
		$issue_data['ItemName']        = $product_name; // 商品名稱.
		$issue_data['ItemCount']       = $product_count; // 商品數量.
		$issue_data['ItemUnit']        = $product_unit; // 商品單位.
		$issue_data['ItemPrice']       = $product_price; // 商品單價.
		$issue_data['ItemAmt']         = $product_amt; // 商品小計.

		if ( 2 === $invoice_individual ) {
			$issue_data['KioskPrintFlag'] = 1;
		}

		/**
		 * B2B
		 */
		if ( 'company' === $invoice_type ) {
			$issue_data['Category']    = 'B2B';
			$issue_data['BuyerName']   = $invoice_company_name; // ''
			$issue_data['BuyerUBN']    = $invoice_tax_id; // '藍新好像根本不需要這個參數!?'
			$issue_data['CarrierType'] = '';
			$issue_data['PrintFlag']   = 'Y';
		}

		/**
		 * Donate
		 */
		if ( 'donate' === $invoice_type ) {
			$issue_data['CarrierType'] = '';
			$issue_data['LoveCode']    = $invoice_donate;
		}

		return $issue_data;
	}

	/**
	 * 開立發票
	 */
	public function generate_invoice( $order_id ) {
		$order = \wc_get_order( $order_id );

		$issue_data = $this->get_issue_data( $order_id );

		if ( ! $issue_data ) {
			return;
		}

		$this->invoice->create( $issue_data );

		$result_data = $this->invoice->getResponse();

		if ( $this->invoice->isOK() || $result_data->code === 'SUCCESS' ) {
			$order->update_meta_data( '_ezpay_invoice_result', $result_data );
			$order->update_meta_data( '_ezpay_invoice_number', $this->invoice->getResult( 'InvoiceNumber' ) );
			$order->update_meta_data( '_ezpay_invoice_status', '1' );
			$order_note = "ezPay電子發票開立結果<br>回傳訊息：{$result_data->message}<br>回應代碼：{$result_data->code}";
			if ( 'SUCCESS' === $result_data->code ) {
				$order_note .= "<br>發票號碼：{$result_data->result->InvoiceNumber}";
				$order_note .= "<br>開立時間：{$result_data->result->CreateTime}";
			}
			$order->add_order_note( $order_note );
		} else {
			$order->update_meta_data( '_ezpay_invoice_result', $this->invoice->getErrorMessage()['message'] );
			$order->add_order_note( 'ezPay電子發票開立結果<br>回傳訊息：' . $this->invoice->getErrorMessage()['message'] );
		}
		$order->save();
		return '開立結果：' . esc_html( $result_data->message );
	}

	/**
	 * 作廢發票
	 */
	public function invalid_invoice( $order_id ) {
		$order          = wc_get_order( $order_id );
		$invoice_number = $order->get_meta( '_ezpay_invoice_number' );
		$result_data    = $order->get_meta( '_ezpay_invoice_result' );

		if ( $invoice_number ) {

			$this->invoice = $this->invoice->info(
				[
					'SearchType'      => 0,
					'MerchantOrderNo' => $result_data->result->MerchantOrderNo,
					'InvoiceNumber'   => $result_data->result->InvoiceNumber,
					'RandomNum'       => $result_data->result->RandomNum,
				]
			);

			$this->invoice->invalid(
				[
					'InvoiceNumber' => $this->invoice->getResult( 'InvoiceNumber' ), // 發票號碼.
					'InvalidReason' => '發票作廢', // 作廢原因.
					'RandomNum'     => $this->invoice->getResult( 'RandomNum' ),
				]
			);
			$result_data = $this->invoice->getResponse();

			if ( $this->invoice->isOK() ) {
				$order_note = "ezPay電子發票作廢結果<br>回傳訊息：發票作廢成功<br>回應代碼：{$result_data->code}";
				if ( 'SUCCESS' === $result_data->code ) {
					$order_note .= "<br>發票號碼：{$result_data->result->InvoiceNumber}";
					$order_note .= "<br>作廢時間：{$result_data->result->CreateTime}";
				}
				$order->add_order_note( $order_note );
				$this->clear_invoice_meta( $order );
				return '作廢結果：' . esc_html( '發票作廢成功' );
			}

			$error = $this->invoice->getErrorMessage();
			$order->add_order_note( 'ezPay電子發票作廢結果<br>回傳訊息：' . $error['message'] . '<br>回傳代碼：' . $error['code'] );
			// LIB10005：發票已作廢過，視同作廢完成。
			if ( 'LIB10005' === $error['code'] ) {
				$this->clear_invoice_meta( $order );
			}
			return '作廢結果：' . esc_html( $error['message'] );
		}
	}

	/**
	 * 作廢完成後清空發票資料，並將狀態設為未開立
	 *
	 * @param \WC_Order $order 訂單。
	 */
	private function clear_invoice_meta( $order ) {
		$order->update_meta_data( '_ezpay_invoice_number', '' );
		$order->update_meta_data( '_ezpay_invoice_result', '' );
		$order->update_meta_data( '_ezpay_invoice_status', '0' );
		$order->save();
	}
}

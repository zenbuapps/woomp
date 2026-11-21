<?php
/**
 * PayNow 電子發票買家資料整合測試（issue #150）
 *
 * 外掛不寄紙本發票，以前卻把 BRING + 帳單完整地址送給 PayNow。驗證：
 * - 預設 buyer_add 為空
 * - `paynow_ei_send_buyer_address` 回 true 才送 BRING + 地址
 * - `paynow_ei_invoice_data` 可改欄位值，但欄位數量／順序固定（PayNow 依位置解析）
 *
 * 以 `pre_http_request` 攔截 SOAP 請求，解出 csvStr 檢查實際送出的內容。
 *
 * @package Woomp\Tests\Integration
 */

final class PaynowEinvoiceBuyerDataTest extends WP_UnitTestCase {

	/**
	 * 攔截到的 csvStr（已解碼）。
	 *
	 * @var string|null
	 */
	private $csv;

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'Paynow_Einvoice' ) ) {
			$this->markTestSkipped( 'PayNow 電子發票模組未載入' );
		}
		$this->csv = null;
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
		remove_all_filters( 'paynow_ei_send_buyer_address' );
		remove_all_filters( 'paynow_ei_invoice_data' );
		parent::tear_down();
	}

	/**
	 * 攔截 UploadInvoice_Patch，回傳開立成功。
	 */
	public function capture_request( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'PayNowEInvoice.asmx' ) ) {
			return $pre;
		}
		if ( preg_match( '#<csvStr>(.*?)</csvStr>#s', (string) $args['body'], $m ) ) {
			$this->csv = base64_decode( urldecode( html_entity_decode( $m[1], ENT_XML1 | ENT_QUOTES, 'UTF-8' ) ) );
		}
		$body = '<?xml version="1.0" encoding="utf-8"?>'
			. '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
			. '<UploadInvoice_PatchResponse xmlns="https://invoice.PayNow.com.tw/">'
			. '<UploadInvoice_PatchResult>S_,1_AB12345678</UploadInvoice_PatchResult>'
			. '</UploadInvoice_PatchResponse></soap:Body></soap:Envelope>';
		return [
			'headers'  => [],
			'body'     => $body,
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	private function create_order( string $issue_type = 'b2c' ): WC_Order {
		$product = new WC_Product_Simple();
		$product->set_name( '測試商品' );
		$product->set_regular_price( '100' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_billing_first_name( '小明' );
		$order->set_billing_last_name( '王' );
		$order->set_billing_state( '台北市' );
		$order->set_billing_city( '中正區' );
		$order->set_billing_address_1( '重慶南路一段 122 號' );
		$order->set_billing_phone( '0912345678' );
		$order->set_billing_email( 'buyer@example.com' );
		$order->update_meta_data( '_paynow_ei_issue_type', $issue_type );
		$order->calculate_totals();
		$order->save();
		return $order;
	}

	/**
	 * 開立並回傳第一列各欄位（去掉 ' 前綴）。
	 *
	 * @return array<int,string>
	 */
	private function issue_and_get_row( WC_Order $order ): array {
		$einvoice = new Paynow_Einvoice();
		$method   = new ReflectionMethod( $einvoice, 'issue_einvoice' );
		$method->setAccessible( true );
		$method->invoke( $einvoice, [ $order->get_id() ] );

		$this->assertNotNull( $this->csv, '沒有攔截到 UploadInvoice_Patch 請求' );
		$lines = explode( PHP_EOL, $this->csv );
		$cols  = explode( ',', $lines[0] );
		$this->assertCount( 17, $cols, 'PayNow 依位置解析，欄位數必須固定為 17' );
		return array_map(
			static function ( $col ) {
				return ltrim( $col, "'" );
			},
			$cols
		);
	}

	public function test_buyer_address_is_empty_by_default(): void {
		$row = $this->issue_and_get_row( $this->create_order() );

		$this->assertSame( '', $row[3], 'buyer_add 預設不可送地址' );
		$this->assertSame( 'buyer@example.com', $row[5] );
		$this->assertStringNotContainsString( '重慶南路', (string) $this->csv );
	}

	public function test_buyer_address_is_empty_for_b2b_by_default(): void {
		$order = $this->create_order( 'b2b' );
		$order->update_meta_data( '_paynow_ei_ubn', '12345678' );
		$order->update_meta_data( '_paynow_ei_buyer_name', '測試公司' );
		$order->save();

		$row = $this->issue_and_get_row( $order );

		$this->assertSame( '12345678', $row[1] );
		$this->assertSame( '', $row[3] );
	}

	public function test_filter_can_opt_in_to_send_address(): void {
		add_filter( 'paynow_ei_send_buyer_address', '__return_true' );

		$row = $this->issue_and_get_row( $this->create_order() );

		$this->assertSame( 'BRING台北市中正區重慶南路一段 122 號', $row[3] );
	}

	public function test_invoice_data_filter_can_blank_fields_without_shifting_columns(): void {
		add_filter(
			'paynow_ei_invoice_data',
			static function ( $data, $order, $item ) {
				unset( $data['buyer_phone'] );            // 移除欄位 → 送空值，不位移
				$data['buyer_email'] = 'a,b@example.com'; // 逗號會被換掉
				$data['extra']       = 'ignored';         // 多加的欄位忽略
				return $data;
			},
			10,
			3
		);

		$row = $this->issue_and_get_row( $this->create_order() );

		$this->assertSame( '', $row[4], 'buyer_phone 應為空' );
		$this->assertSame( 'a b@example.com', $row[5] );
		$this->assertSame( '測試商品', $row[10], 'Description 仍在原位置' );
		$this->assertStringNotContainsString( 'ignored', (string) $this->csv );
	}
}

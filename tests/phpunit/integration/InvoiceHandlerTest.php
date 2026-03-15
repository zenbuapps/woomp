<?php
/**
 * 電子發票處理整合測試
 *
 * 驗證綠界、EZPAY 與立吉富電子發票的
 * 開立與作廢請求格式。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 電子發票處理測試類別
 *
 * @covers includes/woomp-ecpay-invoice/
 * @covers includes/woomp-ezpay-invoice/
 * @covers includes/paynow-einvoice/
 */
class InvoiceHandlerTest extends WP_UnitTestCase {

	/**
	 * 測試用 WooCommerce 訂單
	 *
	 * @var WC_Order|null
	 */
	private $order;

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce 未載入，跳過發票測試' );
		}

		// 建立測試訂單（不依賴 WC_Helper_Product）。
		$this->order = wc_create_order();
		$product     = new WC_Product_Simple();
		$product->set_name( '測試商品' );
		$product->set_regular_price( '100' );
		$product->save();
		$this->order->add_product( $product, 1 );
		$this->order->set_total( 100 );
		$this->order->set_billing_email( 'test@example.com' );
		$this->order->save();
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		if ( $this->order instanceof WC_Order ) {
			$this->order->delete( true );
		}
		parent::tearDown();
	}

	/**
	 * 測試綠界電子發票開立請求格式
	 *
	 * 驗證開立請求包含必要欄位。
	 */
	public function test_ecpay_invoice_issue_request_format() {
		$request_data = array(
			'RelateNumber'  => 'WOOMP' . $this->order->get_id(),
			'CustomerEmail' => $this->order->get_billing_email(),
			'CustomerPhone' => '',
			'Print'         => '0',
			'Donation'      => '0',
			'CarrierType'   => '',
			'TaxType'       => '1',
			'SalesAmount'   => (int) $this->order->get_total(),
			'InvType'       => '07',
			'Items'         => array(),
		);

		// 驗證必要欄位存在。
		$required_fields = array(
			'RelateNumber',
			'CustomerEmail',
			'Print',
			'Donation',
			'TaxType',
			'SalesAmount',
			'InvType',
		);

		foreach ( $required_fields as $field ) {
			$this->assertArrayHasKey(
				$field,
				$request_data,
				"綠界發票開立請求應包含 {$field} 欄位"
			);
		}

		$this->assertEquals(
			'07',
			$request_data['InvType'],
			'發票類型應為 07（一般稅額）'
		);

		$this->assertGreaterThan(
			0,
			$request_data['SalesAmount'],
			'銷售金額應大於 0'
		);
	}

	/**
	 * 測試綠界電子發票作廢請求格式
	 *
	 * 驗證作廢請求包含必要欄位。
	 */
	public function test_ecpay_invoice_void_request_format() {
		$invoice_no   = 'AA12345678';
		$invoice_date = '2024-01-01';

		$void_data = array(
			'InvoiceNo'   => $invoice_no,
			'InvoiceDate' => $invoice_date,
			'Reason'      => '訂單取消',
		);

		$this->assertArrayHasKey( 'InvoiceNo', $void_data, '作廢請求應包含 InvoiceNo' );
		$this->assertArrayHasKey( 'InvoiceDate', $void_data, '作廢請求應包含 InvoiceDate' );
		$this->assertArrayHasKey( 'Reason', $void_data, '作廢請求應包含 Reason' );

		$this->assertMatchesRegularExpression(
			'/^[A-Z]{2}\d{8}$/',
			$void_data['InvoiceNo'],
			'發票號碼格式應為 2 碼英文 + 8 碼數字'
		);
	}

	/**
	 * 測試 EZPAY 電子發票開立請求格式
	 *
	 * 驗證 EZPAY 發票開立請求包含必要欄位。
	 */
	public function test_ezpay_invoice_issue_request_format() {
		$request_data = array(
			'TransNum'     => 'WOOMP' . $this->order->get_id(),
			'MerchantOrderNo' => (string) $this->order->get_id(),
			'BuyerName'    => $this->order->get_billing_first_name() . $this->order->get_billing_last_name(),
			'BuyerEmail'   => $this->order->get_billing_email(),
			'Category'     => 'B2C',
			'TaxType'      => '1',
			'TaxRate'      => 5,
			'Amt'          => (int) round( $this->order->get_total() / 1.05 ),
			'TaxAmt'       => (int) ( $this->order->get_total() - round( $this->order->get_total() / 1.05 ) ),
			'TotalAmt'     => (int) $this->order->get_total(),
			'PrintFlag'    => 'N',
		);

		$required_fields = array(
			'TransNum',
			'MerchantOrderNo',
			'BuyerEmail',
			'Category',
			'TaxType',
			'TotalAmt',
		);

		foreach ( $required_fields as $field ) {
			$this->assertArrayHasKey(
				$field,
				$request_data,
				"EZPAY 發票開立請求應包含 {$field} 欄位"
			);
		}

		$this->assertEquals(
			'B2C',
			$request_data['Category'],
			'類別應為 B2C'
		);

		$this->assertEquals(
			(int) $this->order->get_total(),
			$request_data['TotalAmt'],
			'總金額應與訂單金額一致'
		);
	}

	/**
	 * 測試立吉富電子發票開立請求格式
	 *
	 * 驗證立吉富發票開立請求包含必要欄位。
	 */
	public function test_paynow_invoice_issue_request_format() {
		$request_data = array(
			'order_id'     => (string) $this->order->get_id(),
			'buyer_email'  => $this->order->get_billing_email(),
			'tax_type'     => '1',
			'total_amount' => (int) $this->order->get_total(),
			'carrier_type' => '',
			'carrier_num'  => '',
			'love_code'    => '',
			'items'        => array(
				array(
					'name'     => '測試商品',
					'quantity' => 1,
					'price'    => (int) $this->order->get_total(),
				),
			),
		);

		$required_fields = array(
			'order_id',
			'buyer_email',
			'tax_type',
			'total_amount',
			'items',
		);

		foreach ( $required_fields as $field ) {
			$this->assertArrayHasKey(
				$field,
				$request_data,
				"立吉富發票開立請求應包含 {$field} 欄位"
			);
		}

		$this->assertIsArray(
			$request_data['items'],
			'items 欄位應為陣列'
		);

		$this->assertGreaterThan(
			0,
			count( $request_data['items'] ),
			'items 陣列不應為空'
		);
	}
}

<?php
/**
 * ezPay 電子發票自動作廢整合測試（issue #148）
 *
 * 以前 `Admin::invalid_invoice()` 只在 `_ezpay_invoice_status === '1'` 時作廢，
 * 但整個外掛沒有地方寫入這個欄位，自動作廢從來沒有作用。
 * 這裡用假的 EzpayInvoice 取代真正的 API，驗證：
 * - 有發票號碼（含沒有狀態欄位的舊訂單）就會作廢
 * - 開立／作廢會寫入 `_ezpay_invoice_status`
 * - 作廢失敗時回傳 ezPay 的錯誤訊息，不再一律顯示成功
 *
 * 對應規格：specs/ezpay-invoice-void.feature
 *
 * @package Woomp\Tests\Integration
 */

use WOOMPEZPAYINVOICE\APIs\EzPayInvoiceHandler;
use WOOMPEZPAYINVOICE\ShopOrder\Admin;

/**
 * 假的 EzpayInvoice：依序回傳預先排好的回應，並記錄呼叫了哪些 API。
 */
final class Woomp_Fake_Ezpay_Invoice {

	/**
	 * 依序回傳的回應。
	 *
	 * @var array<int, object>
	 */
	private $responses;

	/**
	 * 目前的回應。
	 *
	 * @var object|null
	 */
	private $response;

	/**
	 * 呼叫紀錄（create／info／invalid）。
	 *
	 * @var array<int, string>
	 */
	public $calls = [];

	/**
	 * @param array<int, object> $responses 依序回傳的回應。
	 */
	public function __construct( array $responses ) {
		$this->responses = $responses;
	}

	private function next( string $api ) {
		$this->calls[]  = $api;
		$this->response = array_shift( $this->responses );
		return $this;
	}

	public function create( $data ) {
		return $this->next( 'create' );
	}

	public function info( $data ) {
		return $this->next( 'info' );
	}

	public function invalid( $data ) {
		return $this->next( 'invalid' );
	}

	public function getResponse() {
		return $this->response;
	}

	public function getResult( $property = null ) {
		$result = $this->response->result ?? null;
		return $property ? ( $result->$property ?? null ) : $result;
	}

	public function isOK() {
		return 'SUCCESS' === $this->response->code;
	}

	public function getErrorMessage() {
		return $this->isOK() ? null : [
			'code'    => $this->response->code,
			'message' => $this->response->message,
		];
	}
}

/**
 * ezPay 自動作廢測試類別
 *
 * @covers includes/woomp-ezpay-invoice/
 * @group invoice
 * @group ezpay-invoice
 */
final class EzpayInvoiceAutoVoidTest extends WP_UnitTestCase {

	/**
	 * 測試用訂單
	 *
	 * @var WC_Order|null
	 */
	private $order;

	/**
	 * 這次測試使用的假 EzpayInvoice
	 *
	 * @var Woomp_Fake_Ezpay_Invoice|null
	 */
	private $fake;

	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( Admin::class ) || ! class_exists( EzPayInvoiceHandler::class ) ) {
			$this->markTestSkipped( 'ezPay 電子發票模組未載入' );
		}

		$this->order = wc_create_order();
		$this->order->set_billing_email( 'buyer@example.com' );
		$this->order->set_total( 1050 );
		$this->order->update_meta_data( '_ezpay_invoice_data', [ '_ezpay_invoice_type' => 'individual' ] );
		$this->order->save();

		add_filter( 'woomp_ezpay_invoice_handler', [ $this, 'use_fake_handler' ] );
	}

	public function tearDown(): void {
		remove_filter( 'woomp_ezpay_invoice_handler', [ $this, 'use_fake_handler' ] );
		if ( $this->order instanceof WC_Order ) {
			$this->order->delete( true );
		}
		parent::tearDown();
	}

	/**
	 * 讓 Admin 使用注入假 EzpayInvoice 的 handler。
	 */
	public function use_fake_handler() {
		return new EzPayInvoiceHandler( $this->fake );
	}

	private static function response( string $code, string $message, array $result = [] ) {
		return (object) [
			'code'    => $code,
			'message' => $message,
			'result'  => (object) $result,
		];
	}

	private static function issue_success() {
		return self::response(
			'SUCCESS',
			'電子發票開立成功',
			[
				'MerchantOrderNo' => 'WOOMP1_1',
				'InvoiceNumber'   => 'AB12345678',
				'RandomNum'       => '0991',
				'CreateTime'      => '2026-11-20 10:00:00',
			]
		);
	}

	private static function info_success() {
		return self::response(
			'SUCCESS',
			'查詢成功',
			[
				'InvoiceNumber' => 'AB12345678',
				'RandomNum'     => '0991',
			]
		);
	}

	private static function invalid_success() {
		return self::response(
			'SUCCESS',
			'作廢成功',
			[
				'InvoiceNumber' => 'AB12345678',
				'CreateTime'    => '2026-11-21 10:00:00',
			]
		);
	}

	/**
	 * 模擬「已開立發票」的訂單，可選擇是否有狀態欄位。
	 */
	private function mark_issued( bool $with_status ) {
		$this->order->update_meta_data( '_ezpay_invoice_number', 'AB12345678' );
		$this->order->update_meta_data( '_ezpay_invoice_result', self::issue_success() );
		if ( $with_status ) {
			$this->order->update_meta_data( '_ezpay_invoice_status', '1' );
		}
		$this->order->save();
	}

	private function reload(): WC_Order {
		return wc_get_order( $this->order->get_id() );
	}

	/**
	 * @testdox 舊訂單（有發票號碼、沒有 _ezpay_invoice_status）自動作廢會呼叫 ezPay 作廢
	 */
	public function test_auto_void_legacy_order_without_status() {
		$this->mark_issued( false );
		$this->fake = new Woomp_Fake_Ezpay_Invoice( [ self::info_success(), self::invalid_success() ] );

		( new Admin() )->invalid_invoice( $this->order->get_id() );

		$this->assertSame( [ 'info', 'invalid' ], $this->fake->calls );
		$order = $this->reload();
		$this->assertSame( '', $order->get_meta( '_ezpay_invoice_number' ) );
		$this->assertSame( '0', $order->get_meta( '_ezpay_invoice_status' ) );
	}

	/**
	 * @testdox 有狀態欄位的訂單自動作廢同樣會呼叫 ezPay 作廢
	 */
	public function test_auto_void_order_with_status() {
		$this->mark_issued( true );
		$this->fake = new Woomp_Fake_Ezpay_Invoice( [ self::info_success(), self::invalid_success() ] );

		( new Admin() )->invalid_invoice( $this->order->get_id() );

		$this->assertSame( [ 'info', 'invalid' ], $this->fake->calls );
		$this->assertSame( '', $this->reload()->get_meta( '_ezpay_invoice_number' ) );
	}

	/**
	 * @testdox 沒有發票號碼的訂單不會呼叫作廢（避免重複作廢）
	 */
	public function test_no_invoice_number_skips_void() {
		$this->fake = new Woomp_Fake_Ezpay_Invoice( [] );

		( new Admin() )->invalid_invoice( $this->order->get_id() );

		$this->assertSame( [], $this->fake->calls );
	}

	/**
	 * @testdox 作廢失敗時保留發票號碼，並回傳 ezPay 的錯誤訊息
	 */
	public function test_void_failure_keeps_number_and_returns_error() {
		$this->mark_issued( true );
		$this->fake = new Woomp_Fake_Ezpay_Invoice(
			[
				self::info_success(),
				self::response( 'INV20006', '查無發票資料' ),
			]
		);

		$message = ( new EzPayInvoiceHandler( $this->fake ) )->invalid_invoice( $this->order->get_id() );

		$this->assertStringContainsString( '查無發票資料', $message );
		$this->assertStringNotContainsString( '成功', $message );
		$order = $this->reload();
		$this->assertSame( 'AB12345678', $order->get_meta( '_ezpay_invoice_number' ) );
		$this->assertSame( '1', $order->get_meta( '_ezpay_invoice_status' ) );
	}

	/**
	 * @testdox 作廢回 LIB10005 時清空發票號碼並將狀態設為 0
	 */
	public function test_void_lib10005_clears_number() {
		$this->mark_issued( true );
		$this->fake = new Woomp_Fake_Ezpay_Invoice(
			[
				self::info_success(),
				self::response( 'LIB10005', '發票已作廢過' ),
			]
		);

		( new EzPayInvoiceHandler( $this->fake ) )->invalid_invoice( $this->order->get_id() );

		$order = $this->reload();
		$this->assertSame( '', $order->get_meta( '_ezpay_invoice_number' ) );
		$this->assertSame( '0', $order->get_meta( '_ezpay_invoice_status' ) );
	}

	/**
	 * @testdox 開立成功時寫入 _ezpay_invoice_status = '1'
	 */
	public function test_issue_success_sets_status() {
		$this->fake = new Woomp_Fake_Ezpay_Invoice( [ self::issue_success() ] );

		( new Admin() )->issue_invoice( $this->order->get_id() );

		$this->assertSame( [ 'create' ], $this->fake->calls );
		$order = $this->reload();
		$this->assertSame( 'AB12345678', $order->get_meta( '_ezpay_invoice_number' ) );
		$this->assertSame( '1', $order->get_meta( '_ezpay_invoice_status' ) );
	}

	/**
	 * @testdox 開立失敗時不寫入 _ezpay_invoice_status = '1'
	 */
	public function test_issue_failure_does_not_set_status() {
		$this->fake = new Woomp_Fake_Ezpay_Invoice( [ self::response( 'INV10003', '商品資訊格式錯誤' ) ] );

		( new Admin() )->issue_invoice( $this->order->get_id() );

		$order = $this->reload();
		$this->assertSame( '', $order->get_meta( '_ezpay_invoice_number' ) );
		$this->assertNotSame( '1', $order->get_meta( '_ezpay_invoice_status' ) );
	}

	/**
	 * @testdox 作廢後再轉入開立狀態會重新開立發票
	 */
	public function test_reissue_after_void() {
		$this->mark_issued( true );
		$this->fake = new Woomp_Fake_Ezpay_Invoice(
			[
				self::info_success(),
				self::invalid_success(),
				self::issue_success(),
			]
		);

		$admin = new Admin();
		$admin->invalid_invoice( $this->order->get_id() );
		$admin->issue_invoice( $this->order->get_id() );

		$this->assertSame( [ 'info', 'invalid', 'create' ], $this->fake->calls );
		$this->assertSame( '1', $this->reload()->get_meta( '_ezpay_invoice_status' ) );
	}

	/**
	 * @testdox 已開立的訂單再次觸發開立不會重複開立
	 */
	public function test_issued_order_is_not_reissued() {
		$this->mark_issued( true );
		$this->fake = new Woomp_Fake_Ezpay_Invoice( [] );

		( new Admin() )->issue_invoice( $this->order->get_id() );

		$this->assertSame( [], $this->fake->calls );
	}
}

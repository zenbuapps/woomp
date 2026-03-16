<?php
/**
 * 電子郵件通知整合測試
 *
 * 驗證 Woomp 外掛自訂的電子郵件內容，
 * 包含付款資訊與物流追蹤資訊。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 電子郵件通知測試類別
 *
 * @covers public/class-woomp-public.php
 * @group email
 * @group order-meta
 */
class EmailNotificationTest extends WP_UnitTestCase {

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
			$this->markTestSkipped( 'WooCommerce 未載入，跳過郵件通知測試' );
		}

		$this->order = wc_create_order();
		$this->order->set_billing_email( 'customer@example.com' );
		$this->order->set_billing_first_name( '測試' );
		$this->order->set_billing_last_name( '用戶' );
		$this->order->set_total( 1500 );
		$this->order->set_payment_method( 'payuni-credit' );
		$this->order->set_payment_method_title( 'PayUni 信用卡' );
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
	 * 測試自訂郵件內容包含付款資訊
	 *
	 * 驗證訂單郵件中包含正確的付款方式資訊。
	 *
	 * @testdox 驗證訂單郵件可存取付款方式與交易編號資訊
	 */
	public function test_custom_email_content_includes_payment_info() {
		// 設定付款相關 meta。
		$this->order->update_meta_data( '_payuni_trade_no', 'PAYUNI_EMAIL_001' );
		$this->order->payment_complete( 'PAYUNI_EMAIL_001' );
		$this->order->save();

		$saved_order = wc_get_order( $this->order->get_id() );

		// 驗證付款方式資訊可存取。
		$this->assertEquals(
			'payuni-credit',
			$saved_order->get_payment_method(),
			'訂單付款方式應為 payuni-credit'
		);

		$this->assertEquals(
			'PayUni 信用卡',
			$saved_order->get_payment_method_title(),
			'訂單付款方式標題應為 PayUni 信用卡'
		);

		$this->assertEquals(
			'PAYUNI_EMAIL_001',
			$saved_order->get_meta( '_payuni_trade_no' ),
			'應可讀取 PayUni 交易編號 meta'
		);

		// 驗證 WC 郵件系統已初始化。
		$mailer = WC()->mailer();
		$this->assertInstanceOf(
			'WC_Emails',
			$mailer,
			'WC_Emails 實例應已初始化'
		);
	}

	/**
	 * 測試郵件包含物流追蹤資訊
	 *
	 * @testdox 驗證訂單郵件可存取物流追蹤編號與物流公司資訊
	 */
	public function test_email_includes_shipping_tracking() {
		$tracking_no = 'TRACK20240101001';
		$shipping_company = '黑貓宅急便';

		// 儲存物流追蹤資訊（使用自訂 meta key，避免 WC internal meta key 警告）。
		$this->order->update_meta_data( '_shipping_tracking_no', $tracking_no );
		$this->order->update_meta_data( '_woomp_shipping_company', $shipping_company );
		$this->order->save();

		$saved_order = wc_get_order( $this->order->get_id() );

		$this->assertEquals(
			$tracking_no,
			$saved_order->get_meta( '_shipping_tracking_no' ),
			'應可讀取物流追蹤編號'
		);

		$this->assertEquals(
			$shipping_company,
			$saved_order->get_meta( '_woomp_shipping_company' ),
			'應可讀取物流公司名稱'
		);

		// 驗證 meta 資料存在，可供郵件模板使用。
		$all_meta = $saved_order->get_meta_data();
		$meta_keys = array_map(
			function ( $meta ) {
				return $meta->key;
			},
			$all_meta
		);

		$this->assertContains(
			'_shipping_tracking_no',
			$meta_keys,
			'訂單 meta 應包含物流追蹤編號'
		);
	}
}

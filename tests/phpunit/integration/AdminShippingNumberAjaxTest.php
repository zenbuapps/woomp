<?php
/**
 * 訂單列表更新物流單號 AJAX 權限測試
 *
 * `wp_ajax_update_shipping_number` 只要求登入，必須另外檢查 nonce 與 edit_shop_orders。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 物流單號 AJAX 權限測試類別
 *
 * @group admin
 * @group security
 */
class AdminShippingNumberAjaxTest extends WP_UnitTestCase {

	/**
	 * 測試訂單
	 *
	 * @var WC_Order|null
	 */
	private $order;

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WC_Order' ) || ! class_exists( 'Woomp_Admin' ) ) {
			$this->markTestSkipped( 'WooCommerce 或 Woomp_Admin 未載入' );
		}

		$this->order = wc_create_order();
		$this->order->update_meta_data( 'wmp_shipping_no', 'ORIGINAL' );
		$this->order->save();

		// wp_send_json() 在 AJAX 情境會 wp_die()，改成丟例外讓測試接住.
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function () {
					throw new WPAjaxDieStopException( 'die' );
				};
			}
		);
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		if ( $this->order instanceof WC_Order ) {
			$this->order->delete( true );
		}
		$_POST    = [];
		$_REQUEST = [];
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * 呼叫 AJAX handler
	 *
	 * @param array $post $_POST.
	 */
	private function call( array $post ): void {
		$_POST    = $post;
		$_REQUEST = $post; // check_ajax_referer() 讀 $_REQUEST.
		$admin = new Woomp_Admin( 'woomp', 'test' );
		ob_start();
		try {
			$admin->update_order_shipping_number();
		} catch ( WPAjaxDieStopException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// 預期：被拒絕時 wp_send_json() 結束請求.
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * @testdox 訂閱者即使帶正確 nonce 也不能改物流單號
	 */
	public function test_subscriber_cannot_update_shipping_number() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->call(
			[
				'action'     => 'update_shipping_number',
				'shippingNo' => 'HACKED',
				'orderId'    => (string) $this->order->get_id(),
				'nonce'      => wp_create_nonce( Woomp_Admin::SHIPPING_NO_NONCE_ACTION ),
			]
		);

		$this->assertSame( 'ORIGINAL', wc_get_order( $this->order->get_id() )->get_meta( 'wmp_shipping_no' ) );
	}

	/**
	 * @testdox 管理員沒帶 nonce（CSRF）不能改物流單號
	 */
	public function test_admin_without_nonce_cannot_update_shipping_number() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->call(
			[
				'action'     => 'update_shipping_number',
				'shippingNo' => 'HACKED',
				'orderId'    => (string) $this->order->get_id(),
			]
		);

		$this->assertSame( 'ORIGINAL', wc_get_order( $this->order->get_id() )->get_meta( 'wmp_shipping_no' ) );
	}
}

<?php
/**
 * 綠界物流「代收貨款」判斷邏輯整合測試（issue #132）
 *
 * 驗證 RY_ECPay_Shipping_Api::get_code() 送往綠界「Express/Create」的
 * IsCollection／CollectionAmount 判斷邏輯：
 * - 貨到付款（cod）訂單一律代收，不再受「是否為第一張物流單」限制
 *   （原本 `count($shipping_list) == 0` 的限制會讓已建過物流單的訂單
 *   再次建單時，代收判斷被略過而漏設定，靜默送出純配送）。
 * - 後台「代收貨款」動作（`$collection = true`）同樣不受影響。
 * - 多件包裹（`no_count` > 1）僅第一張物流單代收，其餘應為 'N'
 *   （原始碼 `if ($i > 01)` 的 `01` 是八進位字面值等於十進位 1，
 *   導致第二張物流單 `$i === 1` 時 `1 > 1` 為 false、仍誤帶全額代收，
 *   違反 specs/ecpay-shipping.feature:94「僅第一張為代收貨款」）。
 * - 已有物流單又建立代收單時，應寫入訂單備註提醒商家確認金額無重複。
 *
 * 全程以 pre_http_request 攔截送往綠界的 wp_remote_post，不觸發任何真實
 * 網路請求；假回應一律手寫字串常數組裝（不可用 http_build_query()，
 * 綠界回應的值完全不做 URL encode，詳見專案 CLAUDE.md 說明）。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 綠界物流代收判斷測試類別
 *
 * @covers includes/ry-woocommerce-tools/woocommerce/shipping/ecpay/includes/ecpay-shipping-api.php
 * @group shipping
 * @group ecpay
 */
class EcpayShippingCollectionTest extends WP_UnitTestCase {

	/**
	 * 本次測試建立的訂單，tearDown 統一刪除
	 *
	 * @var WC_Order[]
	 */
	private $orders = [];

	/**
	 * 攔截到送往綠界「Express/Create」的請求紀錄
	 *
	 * 每筆為 ['url' => string, 'body' => array]（body 已還原為 key => value）。
	 *
	 * @var array
	 */
	private $sent_requests = [];

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'RY_ECPay_Shipping_Api' ) ) {
			$this->markTestSkipped( 'ry-woocommerce-tools 綠界物流模組未載入，跳過代收判斷測試' );
		}

		$this->orders        = [];
		$this->sent_requests = [];

		add_filter( 'pre_http_request', [ $this, 'intercept_ecpay_shipping_http_request' ], 10, 3 );
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_ecpay_shipping_http_request' ], 10 );

		foreach ( $this->orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$order->delete( true );
			}
		}
		$this->orders = [];

		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// pre_http_request 攔截器
	// ------------------------------------------------------------------

	/**
	 * 攔截送往綠界的 wp_remote_post，記錄送出的請求並回傳假的建單成功回應
	 *
	 * 僅攔截網域包含 ecpay.com.tw 的請求，其餘一律放行。body 以「未 URL
	 * encode 的原文」解析（explode 而非 parse_str／http_build_query），
	 * 對齊 RY_ECPay::link_server() 實際送出的格式。
	 *
	 * @param false|array|WP_Error $preempt     短路回傳值，預設 false。
	 * @param array                $parsed_args wp_remote_post 的請求參數。
	 * @param string               $url         請求網址。
	 * @return false|array|WP_Error
	 */
	public function intercept_ecpay_shipping_http_request( $preempt, $parsed_args, $url ) {
		if ( false === strpos( $url, 'ecpay.com.tw' ) ) {
			return $preempt;
		}

		$body = [];
		if ( isset( $parsed_args['body'] ) && is_string( $parsed_args['body'] ) ) {
			foreach ( explode( '&', $parsed_args['body'] ) as $pair ) {
				if ( '' === $pair ) {
					continue;
				}
				$kv           = explode( '=', $pair, 2 );
				$body[ $kv[0] ] = $kv[1] ?? '';
			}
		}

		$this->sent_requests[] = [
			'url'  => $url,
			'body' => $body,
		];

		return [
			'headers'  => [],
			'body'     => $this->build_create_success_body(
				[
					'AllPayLogisticsID' => 'TESTLOGISTICSID' . count( $this->sent_requests ),
				]
			),
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * 組出模擬綠界「Express/Create」建單成功回應
	 *
	 * 格式為 `1|key=value&key=value...`。一律手寫字串常數組裝，不可用
	 * http_build_query()：綠界回應的值完全不做 URL encode，get_code() 也是
	 * 用 parse_str() 直接解析，若測試用已編碼的假回應會讓測試綠燈、正式站
	 * 卻因真實回應未編碼而行為不同。
	 *
	 * 必須包含 get_code() 會讀取的全部欄位：AllPayLogisticsID、
	 * LogisticsType、LogisticsSubType、CVSPaymentNo、CVSValidationNo、
	 * BookingNote，否則會觸發 undefined array key。
	 *
	 * @param array $overrides 覆蓋預設欄位的值。
	 * @return string
	 */
	private function build_create_success_body( array $overrides = [] ): string {
		$defaults = [
			'MerchantID'        => 'TESTMERCHANT001',
			'MerchantTradeNo'   => 'TESTMTN000000',
			'AllPayLogisticsID' => 'TESTLOGISTICSID',
			'LogisticsType'     => 'CVS',
			'LogisticsSubType'  => 'UNIMART',
			'CVSPaymentNo'      => 'TESTPAYNO',
			'CVSValidationNo'   => 'TESTVALID',
			'BookingNote'       => '',
		];

		$fields = array_merge( $defaults, $overrides );

		$pairs = [];
		foreach ( $fields as $key => $value ) {
			$pairs[] = $key . '=' . $value;
		}

		return '1|' . implode( '&', $pairs );
	}

	// ------------------------------------------------------------------
	// Fixture 建構
	// ------------------------------------------------------------------

	/**
	 * 建立一筆帶有綠界物流品項的訂單 fixture
	 *
	 * @param string $method_id              物流品項的 method_id（對應 RY_ECPay_Shipping::$support_methods）。
	 * @param float  $total                  訂單總金額。
	 * @param string $payment_method         付款方式（'cod' 代表貨到付款）。
	 * @param int    $no_count               物流品項的 no_count meta（多件包裹張數），預設 1。
	 * @param array  $existing_shipping_info 預先寫入的 `_ecpay_shipping_info`（模擬已建過物流單）。
	 * @return WC_Order
	 */
	private function create_order_with_shipping_item(
		string $method_id,
		float $total,
		string $payment_method = '',
		int $no_count = 1,
		array $existing_shipping_info = []
	): WC_Order {
		$order = wc_create_order();
		if ( '' !== $payment_method ) {
			$order->set_payment_method( $payment_method );
		}
		$order->set_total( $total );

		$shipping_item = new WC_Order_Item_Shipping();
		$shipping_item->set_method_title( 'ECPay Shipping' );
		$shipping_item->set_method_id( $method_id );
		$shipping_item->set_total( 0 );
		if ( $no_count > 1 ) {
			$shipping_item->add_meta_data( 'no_count', $no_count, true );
		}
		$order->add_item( $shipping_item );

		if ( ! empty( $existing_shipping_info ) ) {
			$order->update_meta_data( '_ecpay_shipping_info', $existing_shipping_info );
		}

		$order->save();

		// 避免讀到 save() 前的快取內容（本專案已知的偽陰性陷阱）。
		wp_cache_flush();
		$order          = wc_get_order( $order->get_id() );
		$this->orders[] = $order;

		return $order;
	}

	/**
	 * 組出模擬「已建過一張物流單」的 `_ecpay_shipping_info` fixture
	 *
	 * @return array
	 */
	private function build_existing_shipping_info_fixture(): array {
		return [
			'PRIORLOGISTICSID001' => [
				'ID'               => 'PRIORLOGISTICSID001',
				'LogisticsType'    => 'CVS',
				'LogisticsSubType' => 'FAMI',
				'PaymentNo'        => 'PRIORPAY001',
				'ValidationNo'     => '',
				'store_ID'         => '',
				'BookingNote'      => '',
				'status'           => 300,
				'status_msg'       => '',
				'create'           => (string) new WC_DateTime(),
				'edit'             => (string) new WC_DateTime(),
				'amount'           => 500,
				'IsCollection'     => 'N',
			],
		];
	}

	// ------------------------------------------------------------------
	// 案例 1：issue #132 迴歸 —— 已有物流單的 COD 訂單仍應代收
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例1（issue #132 迴歸）：COD 訂單已有物流單時，再次建單仍應代收貨款
	 */
	public function test_case_a1_cod_order_with_existing_shipping_info_still_forces_collection() {
		$order = $this->create_order_with_shipping_item(
			'ry_ecpay_shipping_cvs_711',
			1000,
			'cod',
			1,
			$this->build_existing_shipping_info_fixture()
		);

		RY_ECPay_Shipping_Api::get_code( $order->get_id(), false );

		$this->assertCount( 1, $this->sent_requests, 'shipping_list 非空且 no_count 未設定時，單次呼叫應只送出一次建單請求' );

		$sent = $this->sent_requests[0]['body'];
		$this->assertSame(
			'Y',
			$sent['IsCollection'],
			'issue #132 迴歸：已有物流單的 COD 訂單再次建單時仍應代收貨款，不受「是否為第一張」限制'
		);
		$this->assertSame( '1000', $sent['CollectionAmount'], 'CollectionAmount 應等於訂單總額' );
	}

	// ------------------------------------------------------------------
	// 案例 2：COD 訂單第一次建單
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例2：COD 訂單第一次建立物流單應代收貨款
	 */
	public function test_case_a2_cod_order_first_shipment_forces_collection() {
		$order = $this->create_order_with_shipping_item( 'ry_ecpay_shipping_cvs_711', 800, 'cod' );

		RY_ECPay_Shipping_Api::get_code( $order->get_id(), false );

		$this->assertCount( 1, $this->sent_requests );

		$sent = $this->sent_requests[0]['body'];
		$this->assertSame( 'Y', $sent['IsCollection'], 'COD 訂單第一次建單應代收貨款' );
		$this->assertSame( '800', $sent['CollectionAmount'] );

		$notes         = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$note_contents = wp_list_pluck( $notes, 'content' );
		$matched       = array_filter(
			$note_contents,
			static function ( $content ) {
				return false !== strpos( $content, 'already has a shipping code' );
			}
		);
		$this->assertEmpty( $matched, '第一次建單（shipping_list 原為空）不應寫入「已有物流單」提醒備註' );
	}

	// ------------------------------------------------------------------
	// 案例 3：非 COD 訂單，預設不代收
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例3：非 COD 訂單未指定代收動作時，IsCollection 應為 N、CollectionAmount 為 0
	 */
	public function test_case_a3_non_cod_order_defaults_to_no_collection() {
		$order = $this->create_order_with_shipping_item( 'ry_ecpay_shipping_cvs_711', 600, 'bacs' );

		RY_ECPay_Shipping_Api::get_code( $order->get_id(), false );

		$this->assertCount( 1, $this->sent_requests );

		$sent = $this->sent_requests[0]['body'];
		$this->assertSame( 'N', $sent['IsCollection'] );
		$this->assertSame( '0', $sent['CollectionAmount'] );
	}

	// ------------------------------------------------------------------
	// 案例 4：非 COD 訂單，後台「代收貨款」動作強制代收
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例4：非 COD 訂單透過後台「代收貨款」動作（$collection=true）時應強制代收
	 */
	public function test_case_a4_admin_manual_collection_action_forces_collection_on_non_cod_order() {
		$order = $this->create_order_with_shipping_item( 'ry_ecpay_shipping_cvs_711', 600, 'bacs' );

		RY_ECPay_Shipping_Api::get_code( $order->get_id(), true );

		$this->assertCount( 1, $this->sent_requests );

		$sent = $this->sent_requests[0]['body'];
		$this->assertSame( 'Y', $sent['IsCollection'] );
		$this->assertSame( '600', $sent['CollectionAmount'] );
	}

	// ------------------------------------------------------------------
	// 案例 5：多件包裹（八進位字面值修正迴歸）
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例5（八進位 01 bug 迴歸）：no_count=3 時僅第一張物流單代收，第二、三張不應代收
	 */
	public function test_case_a5_multi_package_only_first_label_carries_collection() {
		$order = $this->create_order_with_shipping_item( 'ry_ecpay_shipping_cvs_711', 900, 'cod', 3 );

		RY_ECPay_Shipping_Api::get_code( $order->get_id(), false );

		$this->assertCount( 3, $this->sent_requests, '應依 no_count 建立 3 張物流單' );

		$this->assertSame( 'Y', $this->sent_requests[0]['body']['IsCollection'], '第一張物流單應代收' );
		$this->assertSame( '900', $this->sent_requests[0]['body']['CollectionAmount'] );

		foreach ( [ 1, 2 ] as $index ) {
			$this->assertSame(
				'N',
				$this->sent_requests[ $index ]['body']['IsCollection'],
				sprintf( '第 %d 張物流單不應代收（原始碼 $i > 01 為八進位字面值等於 1 的 bug 迴歸）', $index + 1 )
			);
			$this->assertSame( '0', $this->sent_requests[ $index ]['body']['CollectionAmount'] );
		}
	}

	// ------------------------------------------------------------------
	// 案例 6：已有物流單又建代收單時應寫入訂單備註
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例6：已有物流單又建立代收單時，應寫入提醒商家確認金額無重複的訂單備註
	 */
	public function test_case_a6_creating_collection_label_when_shipping_exists_adds_order_note() {
		$order = $this->create_order_with_shipping_item(
			'ry_ecpay_shipping_cvs_711',
			1000,
			'cod',
			1,
			$this->build_existing_shipping_info_fixture()
		);

		RY_ECPay_Shipping_Api::get_code( $order->get_id(), false );

		$notes         = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$note_contents = wp_list_pluck( $notes, 'content' );
		$matched       = array_filter(
			$note_contents,
			static function ( $content ) {
				return false !== strpos( $content, 'already has a shipping code' );
			}
		);

		$this->assertNotEmpty( $matched, '已有物流單又建立代收單時應寫入提醒商家的訂單備註' );
	}
}

<?php
/**
 * 立吉富物流回呼來源驗證整合測試（issue #146）
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 涵蓋選門市回呼與貨態更新回呼的驗證。
 *
 * @covers PayNow_Shipping_Response
 * @group paynow
 * @group shipping
 */
class PaynowShippingCallbackTest extends WP_UnitTestCase {

	/**
	 * 測試用 PayNow 超商物流訂單
	 *
	 * @var WC_Order
	 */
	private $order;

	/**
	 * 本次測試寫入的 log（level + message）
	 *
	 * @var array
	 */
	private $logs = [];

	/**
	 * 改門市動作被觸發的訂單 ID
	 *
	 * @var array
	 */
	private $changed_order_ids = [];

	/**
	 * 本次測試對 PayNow 發出的查詢（url + timeout）
	 *
	 * @var array
	 */
	private $paynow_queries = [];

	/**
	 * 門市資料
	 *
	 * @var array
	 */
	private $cvs_info = [
		'paynow_service'      => '01',
		'paynow_storeid'      => '999999',
		'paynow_storename'    => '新門市',
		'paynow_storeaddress' => '新地址',
	];

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'PayNow_Shipping_Response' ) ) {
			$this->markTestSkipped( 'PayNow 物流模組未載入' );
		}

		$this->order = $this->create_paynow_order();

		delete_option( PayNow_Shipping_Response::STATUS_TOKEN_OPTION );
		delete_option( PayNow_Shipping_Response::STATUS_TOKEN_REQUIRED_OPTION );

		// 不讓測試真的打 PayNow 取消／重建物流單，只記錄動作有沒有被觸發。
		remove_all_actions( 'paynow_after_admin_changed_cvs_store' );
		add_action(
			'paynow_after_admin_changed_cvs_store',
			function ( $order ) {
				$this->changed_order_ids[] = $order->get_id();
			}
		);

		add_filter(
			'woocommerce_logger_log_message',
			function ( $message, $level ) {
				$this->logs[] = $level . ': ' . $message;
				return null;
			},
			10,
			2
		);
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		$_GET     = [];
		$_POST    = [];
		$_REQUEST = [];

		PayNow_Shipping::$order_status_at_receiver_cvs = null;

		parent::tearDown();
	}

	/**
	 * 建立帶有 PayNow 物流資料的訂單
	 *
	 * @return WC_Order
	 */
	private function create_paynow_order() {
		$order = wc_create_order();
		$item  = new WC_Order_Item_Shipping();
		$item->set_method_id( 'paynow_shipping_c2c_711' );
		$order->add_item( $item );
		$order->set_status( 'on-hold' );
		$order->update_meta_data( PayNow_Shipping_Order_Meta::LogisticNumber, 'LN123' );
		$order->update_meta_data( PayNow_Shipping_Order_Meta::LogisticServiceId, '01' );
		$order->update_meta_data( PayNow_Shipping_Order_Meta::StoreId, '111111' );
		$order->update_meta_data( PayNow_Shipping_Order_Meta::StoreName, '舊門市' );
		$order->save();

		return $order;
	}

	/**
	 * 從 returnUrl 取出 token
	 *
	 * @param WC_Order|null $order 訂單，預設為 $this->order.
	 * @return string
	 */
	private function mint_cvs_token( $order = null ) {
		$order = $order ?: $this->order;
		wp_parse_str( (string) wp_parse_url( PayNow_Shipping_Response::get_cvs_change_return_url( $order->get_id() ), PHP_URL_QUERY ), $query );

		return $query[ PayNow_Shipping_Response::CVS_TOKEN_QUERY_ARG ];
	}

	/**
	 * 模擬門市地圖導回，走實際的回呼入口
	 *
	 * @param array $query 網址參數.
	 * @param array $body  POST 內容.
	 * @return string|null 成功時回傳導向網址；被擋下時為 null。
	 */
	private function send_cvs_callback( $query, $body = null ) {
		$_GET     = $query;
		$_POST    = $body ?? [
			'service'      => '01',
			'storeid'      => '999999',
			'storename'    => '新門市',
			'storeaddress' => '新地址',
		];
		$_REQUEST = array_merge( $_GET, $_POST );

		$redirect_filter = static function ( $location ) {
			throw new RuntimeException( $location );
		};
		add_filter( 'wp_redirect', $redirect_filter );

		try {
			PayNow_Shipping_Response::paynow_choose_cvs_callback();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		} catch ( WPDieException $e ) {
			return null;
		} finally {
			remove_filter( 'wp_redirect', $redirect_filter );
		}

		$this->fail( '回呼應該要導向或被擋下' );
	}

	/**
	 * 斷言訂單門市沒有變動
	 */
	private function assert_store_unchanged() {
		$order = wc_get_order( $this->order->get_id() );
		$this->assertSame( '111111', $order->get_meta( PayNow_Shipping_Order_Meta::StoreId ), '門市不應變更' );
		$this->assertSame( [], $this->changed_order_ids, '不應取消或重建物流單' );
	}

	/**
	 * 貨態回呼內容
	 *
	 * @param WC_Order|null $order 訂單，預設為 $this->order.
	 * @return array
	 */
	private function status_payload( $order = null ) {
		$order = $order ?: $this->order;

		return [
			'orderno'                   => (string) $order->get_id(),
			'OriginOrderno'             => 'ORIGIN1',
			'PayNowLogisticCode'        => PayNow_Shipping_Status::AT_RECEIVER_CVS,
			'Detail_Status_Description' => '已到店',
			'paymentno'                 => 'PAY1',
			'StoreDate'                 => '2026/11/04',
			'StoreTime'                 => '10:00',
		];
	}

	/**
	 * 取得貨態回呼網址中的 token
	 *
	 * @return string
	 */
	private function status_token() {
		wp_parse_str( (string) wp_parse_url( PayNow_Shipping_Response::get_status_callback_url(), PHP_URL_QUERY ), $query );

		return $query[ PayNow_Shipping_Response::STATUS_TOKEN_QUERY_ARG ];
	}

	/**
	 * @testdox 管理員改門市：門市更新、觸發物流單重建、導回訂單編輯頁
	 */
	public function test_valid_token_changes_store_and_redirects_to_order() {
		$location = $this->send_cvs_callback( [ PayNow_Shipping_Response::CVS_TOKEN_QUERY_ARG => $this->mint_cvs_token() ] );

		$order = wc_get_order( $this->order->get_id() );
		$this->assertSame( '999999', $order->get_meta( PayNow_Shipping_Order_Meta::StoreId ) );
		$this->assertSame( '新門市', $order->get_meta( PayNow_Shipping_Order_Meta::StoreName ) );
		$this->assertSame( '新地址', $order->get_shipping_address_1() );
		$this->assertSame( [ $order->get_id() ], $this->changed_order_ids );
		$this->assertSame( $order->get_edit_order_url(), $location );
	}

	/**
	 * @testdox 沒有 token、只帶舊版 order_id：門市與物流單都不變，並寫 log
	 */
	public function test_order_id_without_token_is_rejected() {
		$location = $this->send_cvs_callback( [ 'order_id' => (string) $this->order->get_id() ] );

		$this->assertNull( $location );
		$this->assert_store_unchanged();
		$this->assertStringContainsString( 'invalid_cvs_token', implode( "\n", $this->logs ) );
	}

	/**
	 * @testdox order_id 放在 POST 也不會落回結帳流程
	 */
	public function test_order_id_in_post_body_is_rejected() {
		$location = $this->send_cvs_callback( [], [ 'order_id' => (string) $this->order->get_id() ] + [ 'storeid' => '999999' ] );

		$this->assertNull( $location );
		$this->assert_store_unchanged();
	}

	/**
	 * @testdox token 錯誤：門市不變，log 不含 token
	 */
	public function test_wrong_token_is_rejected_and_not_logged() {
		$location = $this->send_cvs_callback( [ PayNow_Shipping_Response::CVS_TOKEN_QUERY_ARG => 'wrong-secret-token' ] );

		$this->assertNull( $location );
		$this->assert_store_unchanged();
		$this->assertNotEmpty( $this->logs );
		$this->assertStringNotContainsString( 'wrong-secret-token', implode( "\n", $this->logs ) );
	}

	/**
	 * @testdox token 是陣列（cvs_token[]=x）：拒絕且不產生 PHP 錯誤
	 */
	public function test_array_token_is_rejected() {
		$location = $this->send_cvs_callback( [ PayNow_Shipping_Response::CVS_TOKEN_QUERY_ARG => [ 'x' ] ] );

		$this->assertNull( $location );
		$this->assert_store_unchanged();
	}

	/**
	 * @testdox 同一個 token 不能重複使用
	 */
	public function test_token_cannot_be_reused() {
		$token = $this->mint_cvs_token();

		$this->assertInstanceOf( WC_Order::class, PayNow_Shipping_Response::change_order_cvs_store( $token, $this->cvs_info ) );

		$second = PayNow_Shipping_Response::change_order_cvs_store( $token, array_merge( $this->cvs_info, [ 'paynow_storeid' => '222222' ] ) );

		$this->assertWPError( $second );
		$this->assertSame( 'invalid_cvs_token', $second->get_error_code() );
		$this->assertSame( '999999', wc_get_order( $this->order->get_id() )->get_meta( PayNow_Shipping_Order_Meta::StoreId ) );
		$this->assertCount( 1, $this->changed_order_ids );
	}

	/**
	 * @testdox token 過期後失效
	 */
	public function test_expired_token_is_rejected() {
		$token = $this->mint_cvs_token();
		update_option( '_transient_timeout_' . PayNow_Shipping_Response::CVS_TOKEN_TRANSIENT_PREFIX . hash( 'sha256', $token ), time() - 1 );

		$result = PayNow_Shipping_Response::change_order_cvs_store( $token, $this->cvs_info );

		$this->assertWPError( $result );
		$this->assert_store_unchanged();
	}

	/**
	 * @testdox token 綁定訂單：另一張訂單的 token 只會變更它自己的訂單
	 */
	public function test_token_only_changes_its_own_order() {
		$other = $this->create_paynow_order();

		$this->send_cvs_callback(
			[
				PayNow_Shipping_Response::CVS_TOKEN_QUERY_ARG => $this->mint_cvs_token( $other ),
				'order_id'                                    => (string) $this->order->get_id(),
			]
		);

		$this->assertSame( '111111', wc_get_order( $this->order->get_id() )->get_meta( PayNow_Shipping_Order_Meta::StoreId ) );
		$this->assertSame( '999999', wc_get_order( $other->get_id() )->get_meta( PayNow_Shipping_Order_Meta::StoreId ) );
		$this->assertSame( [ $other->get_id() ], $this->changed_order_ids );
	}

	/**
	 * @testdox 門市資料不齊：不變更、不取消物流單，token 保留可重選
	 */
	public function test_missing_store_fields_keep_token_and_order() {
		$token = $this->mint_cvs_token();

		$location = $this->send_cvs_callback( [ PayNow_Shipping_Response::CVS_TOKEN_QUERY_ARG => $token ], [ 'service' => '01' ] );

		$this->assertNull( $location );
		$this->assert_store_unchanged();
		$this->assertInstanceOf( WC_Order::class, PayNow_Shipping_Response::change_order_cvs_store( $token, $this->cvs_info ) );
	}

	/**
	 * @testdox returnUrl 導回網址正確，沒有被轉成 &#038;
	 *
	 * @dataProvider permalink_structures
	 *
	 * @param string $structure 永久連結結構.
	 */
	public function test_return_url_is_not_entity_encoded( $structure ) {
		$this->set_permalink_structure( $structure );

		$url = PayNow_Shipping_Response::get_cvs_change_return_url( $this->order->get_id() );

		$this->assertStringNotContainsString( '&#038;', $url );
		$this->assertStringContainsString( 'paynow_choose_cvs_callback', $url );
		$this->assertMatchesRegularExpression( '/[?&]cvs_token=[A-Za-z0-9]{32}$/', $url );
	}

	/**
	 * 永久連結結構
	 *
	 * @return array
	 */
	public static function permalink_structures() {
		return [
			'pretty' => [ '/%postname%/' ],
			'plain'  => [ '' ],
		];
	}

	/**
	 * @testdox 結帳選門市：表單把門市資料帶回結帳頁，不動既有訂單
	 */
	public function test_checkout_flow_builds_redirect_form() {
		$html = PayNow_Shipping_Response::build_checkout_redirect_form( $this->cvs_info );

		$this->assertStringContainsString( 'name="paynow_storeid" value="999999"', $html );
		$this->assertStringContainsString( 'id="paynow-map-redirect"', $html );
		$this->assert_store_unchanged();
	}

	/**
	 * @testdox 真實貨態：物流資料更新，訂單狀態依商家設定變更
	 */
	public function test_status_update_applies_meta_and_order_status() {
		PayNow_Shipping::$order_status_at_receiver_cvs = 'completed';

		$result = PayNow_Shipping_Response::process_status_update( $this->status_payload(), $this->status_token() );

		$this->assertInstanceOf( WC_Order::class, $result );
		$order = wc_get_order( $this->order->get_id() );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( PayNow_Shipping_Status::AT_RECEIVER_CVS, $order->get_meta( PayNow_Shipping_Order_Meta::LogisticCode ) );
		$this->assertSame( '已到店', $order->get_meta( PayNow_Shipping_Order_Meta::DetailStatusDesc ) );
		$this->assertSame( 'PAY1', $order->get_meta( PayNow_Shipping_Order_Meta::PaymentNo ) );
		$this->assertSame( '2026/11/04', $order->get_meta( PayNow_Shipping_Order_Meta::StoreDate ) );
	}

	/**
	 * @testdox 商家沒設定對應訂單狀態時，物流資料仍會存入
	 */
	public function test_status_update_saves_meta_when_no_status_mapping() {
		PayNow_Shipping::$order_status_at_receiver_cvs = '';

		PayNow_Shipping_Response::process_status_update( $this->status_payload(), $this->status_token() );

		$order = wc_get_order( $this->order->get_id() );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( '已到店', $order->get_meta( PayNow_Shipping_Order_Meta::DetailStatusDesc ) );
	}

	/**
	 * @testdox 非 PayNow 物流訂單：資料與狀態都不變
	 */
	public function test_status_update_rejects_non_paynow_order() {
		PayNow_Shipping::$order_status_at_receiver_cvs = 'completed';
		$other = wc_create_order();
		$other->set_status( 'on-hold' );
		$other->save();

		$result = PayNow_Shipping_Response::process_status_update( $this->status_payload( $other ), $this->status_token() );

		$this->assertWPError( $result );
		$this->assertSame( 'not_paynow_shipping_order', $result->get_error_code() );
		$reloaded = wc_get_order( $other->get_id() );
		$this->assertSame( 'on-hold', $reloaded->get_status() );
		$this->assertSame( '', $reloaded->get_meta( PayNow_Shipping_Order_Meta::LogisticCode ) );
	}

	/**
	 * @testdox token 不符：即使在過渡期也拒絕
	 */
	public function test_status_update_rejects_wrong_token() {
		PayNow_Shipping::$order_status_at_receiver_cvs = 'completed';

		$result = PayNow_Shipping_Response::process_status_update( $this->status_payload(), 'wrong-token' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_status_token', $result->get_error_code() );
		$this->assertSame( 'on-hold', wc_get_order( $this->order->get_id() )->get_status() );
		$this->assertNotSame( 'yes', get_option( PayNow_Shipping_Response::STATUS_TOKEN_REQUIRED_OPTION ) );
	}

	/**
	 * 模擬 PayNow 查詢物流單 API（Get_Order_Info）
	 *
	 * @param array|WP_Error|string $result 查詢結果欄位；WP_Error 代表連線失敗，字串代表原始 body。
	 */
	private function mock_paynow_query( $result ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $result ) {
				if ( false === strpos( $url, 'Get_Order_Info' ) ) {
					return $pre;
				}

				$this->paynow_queries[] = [
					'url'     => $url,
					'timeout' => $args['timeout'],
				];

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return [
					'headers'  => [],
					'cookies'  => [],
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => is_string( $result ) ? $result : wp_json_encode( $result ),
				];
			},
			10,
			3
		);
	}

	/**
	 * PayNow 查詢 API 的回應（欄位名稱依正式站實際回應）
	 *
	 * @param array $overrides 要覆寫的欄位.
	 * @return array
	 */
	private function paynow_query_result( $overrides = [] ) {
		return array_merge(
			[
				'LogisticNumber'            => 'LN123',
				'orderno'                   => (string) $this->order->get_id(),
				'Logistic_Serviece'         => '',
				'Status'                    => '0',
				'Delivery_Status'           => '',
				'PayNowLogisticCode'        => PayNow_Shipping_Status::AT_RECEIVER_CVS,
				'Detail_Status_Description' => 'PayNow 查到的狀態',
				'sno'                       => '1',
				'ErrorMsg'                  => '',
				'paymentno'                 => 'PAY-FROM-QUERY',
				'validationno'              => '',
			],
			$overrides
		);
	}

	/**
	 * 斷言這次回呼沒有更動訂單
	 *
	 * @param WC_Order|null $order 訂單，預設為 $this->order.
	 */
	private function assert_status_untouched( $order = null ) {
		$order    = $order ?: $this->order;
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( 'on-hold', $reloaded->get_status() );
		$this->assertSame( '', $reloaded->get_meta( PayNow_Shipping_Order_Meta::LogisticCode ) );
	}

	/**
	 * @testdox 舊網址（沒帶 token）：PayNow 查詢確認貨態相符才更新，資料以查詢結果為準
	 */
	public function test_tokenless_callback_applies_status_confirmed_by_paynow() {
		PayNow_Shipping::$order_status_at_receiver_cvs = 'completed';
		$this->mock_paynow_query( $this->paynow_query_result() );

		$result = PayNow_Shipping_Response::process_status_update( $this->status_payload(), '' );

		$this->assertInstanceOf( WC_Order::class, $result );
		$order = wc_get_order( $this->order->get_id() );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'PayNow 查到的狀態', $order->get_meta( PayNow_Shipping_Order_Meta::DetailStatusDesc ) );
		$this->assertSame( 'PAY-FROM-QUERY', $order->get_meta( PayNow_Shipping_Order_Meta::PaymentNo ) );
		$this->assertSame( '2026/11/04', $order->get_meta( PayNow_Shipping_Order_Meta::StoreDate ), '查詢 API 沒有到店日期，貨態確認後沿用回呼的值' );
		$this->assertStringContainsString( 'LogisticNumber=LN123', $this->paynow_queries[0]['url'] );
		$this->assertStringContainsString( 'warning: ', implode( "\n", $this->logs ), '要提醒商家改網址' );
	}

	/**
	 * @testdox 舊網址偽造貨態：PayNow 查到的貨態不同，訂單不變並寫 log
	 */
	public function test_tokenless_callback_rejected_when_paynow_disagrees() {
		PayNow_Shipping::$order_status_at_receiver_cvs = 'completed';
		$this->mock_paynow_query( $this->paynow_query_result( [ 'PayNowLogisticCode' => PayNow_Shipping_Status::AT_SENDER_CVS ] ) );

		$result = PayNow_Shipping_Response::process_status_update( $this->status_payload(), '' );

		$this->assertWPError( $result );
		$this->assertSame( 'status_not_confirmed', $result->get_error_code() );
		$this->assert_status_untouched();
	}

	/**
	 * @testdox 舊網址：查詢失敗、回應異常或物流單號不符，一律不更新訂單
	 *
	 * @dataProvider unusable_query_results
	 *
	 * @param callable $build 回傳模擬查詢結果.
	 */
	public function test_tokenless_callback_rejected_when_query_is_unusable( $build ) {
		PayNow_Shipping::$order_status_at_receiver_cvs = 'completed';
		$this->mock_paynow_query( $build( $this ) );

		$result = PayNow_Shipping_Response::process_status_update( $this->status_payload(), '' );

		$this->assertWPError( $result );
		$this->assertSame( 'status_query_failed', $result->get_error_code() );
		$this->assert_status_untouched();
	}

	/**
	 * 無法用來確認貨態的查詢結果
	 *
	 * @return array
	 */
	public static function unusable_query_results() {
		return [
			'連線失敗'     => [
				static function () {
					return new WP_Error( 'http_request_failed', 'timeout' );
				},
			],
			'不是 JSON'  => [
				static function () {
					return '<html>error</html>';
				},
			],
			'查無物流單'    => [
				static function ( $test ) {
					return $test->query_result_for_provider(
						[
							'PayNowLogisticCode' => '',
							'ErrorMsg'           => '查無該筆PayNow物流編號',
						]
					);
				},
			],
			'物流單號不符'   => [
				static function ( $test ) {
					return $test->query_result_for_provider( [ 'LogisticNumber' => 'OTHER' ] );
				},
			],
			'欄位型別不對'   => [
				static function ( $test ) {
					return $test->query_result_for_provider( [ 'PayNowLogisticCode' => [ '4000' ] ] );
				},
			],
		];
	}

	/**
	 * 給 dataProvider 使用的查詢結果（dataProvider 執行時訂單還沒建立）
	 *
	 * @param array $overrides 要覆寫的欄位.
	 * @return array
	 */
	public function query_result_for_provider( $overrides ) {
		return $this->paynow_query_result( $overrides );
	}

	/**
	 * @testdox 舊網址重送同一個貨態：不重複改訂單，也不再打 PayNow
	 */
	public function test_tokenless_replay_does_not_reset_order_status() {
		PayNow_Shipping::$order_status_at_receiver_cvs = 'completed';
		$this->mock_paynow_query( $this->paynow_query_result() );

		PayNow_Shipping_Response::process_status_update( $this->status_payload(), '' );

		// 商家之後手動把訂單改回處理中，重放舊貨態不應蓋掉.
		$order = wc_get_order( $this->order->get_id() );
		$order->update_status( 'processing' );
		$notes_before = count( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );

		PayNow_Shipping_Response::process_status_update( $this->status_payload(), '' );

		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( $notes_before, wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), '不應多寫訂單備註' );
		$this->assertCount( 1, $this->paynow_queries );
	}

	/**
	 * @testdox 物流單重建後，舊網址的相同貨態仍會處理（重放判斷綁定物流單號）
	 */
	public function test_replay_guard_is_bound_to_logistic_number() {
		PayNow_Shipping::$order_status_at_receiver_cvs = 'completed';
		$this->mock_paynow_query( $this->paynow_query_result() );
		PayNow_Shipping_Response::process_status_update( $this->status_payload(), '' );

		$order = wc_get_order( $this->order->get_id() );
		$order->update_meta_data( PayNow_Shipping_Order_Meta::LogisticNumber, 'LN456' );
		$order->update_status( 'on-hold' );

		remove_all_filters( 'pre_http_request' );
		$this->mock_paynow_query( $this->paynow_query_result( [ 'LogisticNumber' => 'LN456' ] ) );

		$result = PayNow_Shipping_Response::process_status_update( $this->status_payload(), '' );

		$this->assertInstanceOf( WC_Order::class, $result );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox 帶正確 token：直接處理，不多打一次 PayNow
	 */
	public function test_valid_token_skips_paynow_query() {
		$this->mock_paynow_query( new WP_Error( 'http_request_failed', 'should not be called' ) );

		$result = PayNow_Shipping_Response::process_status_update( $this->status_payload(), $this->status_token() );

		$this->assertInstanceOf( WC_Order::class, $result );
		$this->assertSame( [], $this->paynow_queries );
	}

	/**
	 * @testdox 回呼內的查詢不沿用 45 秒逾時，避免被大量偽造請求占住 PHP worker
	 */
	public function test_callback_query_uses_short_timeout() {
		$this->mock_paynow_query( $this->paynow_query_result() );

		PayNow_Shipping_Response::process_status_update( $this->status_payload(), '' );

		$this->assertLessThanOrEqual( 10, $this->paynow_queries[0]['timeout'] );
	}

	/**
	 * @testdox 匿名回呼帶任意 token 不會建立站台 token
	 */
	public function test_anonymous_callback_does_not_create_site_token() {
		PayNow_Shipping_Response::process_status_update( $this->status_payload(), 'guess' );

		$this->assertFalse( get_option( PayNow_Shipping_Response::STATUS_TOKEN_OPTION ) );
	}

	/**
	 * @testdox 收到第一筆正確 token 後，沒帶 token 的回呼一律拒絕
	 */
	public function test_valid_token_locks_out_tokenless_callbacks() {
		PayNow_Shipping_Response::process_status_update( $this->status_payload(), $this->status_token() );

		$this->assertSame( 'yes', get_option( PayNow_Shipping_Response::STATUS_TOKEN_REQUIRED_OPTION ) );

		$result = PayNow_Shipping_Response::process_status_update( $this->status_payload(), '' );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_status_token', $result->get_error_code() );
	}

	/**
	 * @testdox 缺少欄位或欄位是陣列：拒絕且不產生 PHP 錯誤
	 *
	 * @dataProvider malformed_payloads
	 *
	 * @param array $payload 回呼內容.
	 */
	public function test_status_update_rejects_malformed_payload( $payload ) {
		$result = PayNow_Shipping_Response::process_status_update( $payload, $this->status_token() );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_status_fields', $result->get_error_code() );
	}

	/**
	 * 格式錯誤的回呼內容
	 *
	 * @return array
	 */
	public static function malformed_payloads() {
		return [
			'empty'            => [ [] ],
			'no logistic code' => [ [ 'orderno' => '1' ] ],
			'array orderno'    => [
				[
					'orderno'            => [ '1' ],
					'PayNowLogisticCode' => '4000',
				],
			],
		];
	}

	/**
	 * @testdox 每站 token 固定且設定頁顯示完整網址
	 */
	public function test_settings_show_status_callback_url() {
		$url = PayNow_Shipping_Response::get_status_callback_url();

		$this->assertSame( $url, PayNow_Shipping_Response::get_status_callback_url(), 'token 不應每次變動' );
		$this->assertStringContainsString( 'paynow_shipping_order_callback', $url );

		$settings = apply_filters( 'paynow_shipping_settings', [] );
		$section  = wp_list_filter( $settings, [ 'id' => 'paynow_shipping_status_callback' ] );

		$this->assertStringContainsString( esc_html( $url ), reset( $section )['desc'] );
	}
}

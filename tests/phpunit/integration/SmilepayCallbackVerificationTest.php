<?php
/**
 * 速買配（SmilePay）金流／物流回呼驗證整合測試
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 涵蓋付款回呼、門市地圖回呼、貨態回呼與 AJAX 取號的來源驗證。
 *
 * @covers RY_SmilePay_Gateway_Response
 * @covers RY_SmilePay_Shipping_Response
 * @covers RY_SmilePay_Gateway
 * @group smilepay
 * @group gateway
 */
class SmilepayCallbackVerificationTest extends WP_UnitTestCase {

	/**
	 * 速買配交易編號
	 */
	const SMSE_ID = '12345678';

	/**
	 * 本次測試建立的訂單
	 *
	 * @var WC_Order[]
	 */
	private $orders = [];

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'RY_SmilePay_Gateway_Response' ) || ! class_exists( 'RY_SmilePay_Shipping_Response' ) ) {
			$this->markTestSkipped( 'SmilePay 模組未載入' );
		}

		RY_WT::update_option( 'smilepay_gateway_testmode', 'yes' );
		RY_WT::update_option( 'smilepay_gateway_order_prefix', '' );

		// 訂單改 processing 時會自動向速買配取物流編號；測試不可真的打外部 API。
		remove_action( 'woocommerce_order_status_processing', [ 'RY_SmilePay_Shipping', 'get_code' ], 10 );
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'http_blocked', 'blocked in test' );
			}
		);

		// 確保貨態 hook 存在（init 依設定註冊）。
		if ( ! has_action( 'ry_smilepay_shipping_response_status_3', [ 'RY_SmilePay_Shipping_Response', 'shipping_completed' ] ) ) {
			add_action( 'ry_smilepay_shipping_response_status_3', [ 'RY_SmilePay_Shipping_Response', 'shipping_completed' ], 10, 2 );
		}
		if ( ! has_action( 'ry_smilepay_shipping_response_status_2', [ 'RY_SmilePay_Shipping_Response', 'shipping_at_cvs' ] ) ) {
			add_action( 'ry_smilepay_shipping_response_status_2', [ 'RY_SmilePay_Shipping_Response', 'shipping_at_cvs' ], 10, 2 );
		}
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		$_GET     = [];
		$_POST    = [];
		$_REQUEST = [];
		foreach ( $this->orders as $order ) {
			$order->delete( true );
		}
		parent::tearDown();
	}

	/**
	 * 建立已取號的速買配 ATM 訂單
	 *
	 * @param array $args 覆寫設定。
	 * @return WC_Order
	 */
	private function create_order( array $args = [] ) {
		$args = array_merge(
			[
				'status'         => 'on-hold',
				'payment_method' => 'ry_smilepay_atm',
				'total'          => 500,
				'transaction_id' => self::SMSE_ID,
				'testmode'       => 'yes',
			],
			$args
		);

		$order = wc_create_order();
		$order->set_payment_method( $args['payment_method'] );
		$order->set_total( $args['total'] );
		$order->set_status( $args['status'] );
		$order->set_transaction_id( $args['transaction_id'] );
		$order->save();

		$data_id = $order->get_id() . 'TS3' . '9876543210';
		$order->update_meta_data( '_smilepay_Data_id', $data_id );
		$order->update_meta_data( '_smilepay_Data_id_list', [ $data_id ] );
		$order->update_meta_data( '_smilepay_testmode', $args['testmode'] );
		$order->save();

		$this->orders[] = $order;
		return wc_get_order( $order->get_id() );
	}

	/**
	 * 產生合法的付款回呼資料
	 *
	 * @param WC_Order $order     訂單。
	 * @param array    $overrides 覆寫欄位。
	 * @return array
	 */
	private function payment_payload( WC_Order $order, array $overrides = [] ) {
		$payload = [
			'Data_id'     => $order->get_meta( '_smilepay_Data_id' ),
			'Smseid'      => self::SMSE_ID,
			'Amount'      => '500',
			'Classif'     => 'B',
			'Response_id' => '1',
		];
		$payload                 = array_merge( $payload, $overrides );
		$payload['Mid_smilepay'] = $overrides['Mid_smilepay'] ?? (string) RY_SmilePay_Gateway_Response::calculate_check_value( '9527', $order, $payload['Smseid'] );
		return $payload;
	}

	/**
	 * 驗證並套用付款回呼，回傳最新訂單
	 *
	 * @param WC_Order $order   訂單。
	 * @param array    $payload 回呼資料。
	 * @return WC_Order
	 */
	private function send_payment( WC_Order $order, array $payload ) {
		$validated = RY_SmilePay_Gateway_Response::validate_callback( $payload );
		if ( $validated ) {
			RY_SmilePay_Gateway_Response::apply_payment( $validated, $payload );
		}
		return wc_get_order( $order->get_id() );
	}

	/**
	 * @testdox 驗證碼演算法與手算結果一致（9527 + 00000500 + 5678 → 300）
	 */
	public function test_check_value_algorithm() {
		$order = $this->create_order();
		$this->assertSame( 300, RY_SmilePay_Gateway_Response::calculate_check_value( '9527', $order, '12345678' ) );
	}

	/**
	 * @testdox 合法回呼仍能把訂單標成已付款（回歸）
	 */
	public function test_valid_callback_marks_paid() {
		$order = $this->create_order();
		$order = $this->send_payment( $order, $this->payment_payload( $order ) );
		$this->assertTrue( $order->is_paid() );
	}

	/**
	 * @testdox 信用卡訂單（取號前沒有交易編號）合法回呼仍能付款，並記下交易編號
	 */
	public function test_valid_credit_callback_marks_paid() {
		$order = $this->create_order(
			[
				'status'         => 'pending',
				'payment_method' => 'ry_smilepay_credit',
				'transaction_id' => '',
			]
		);
		$order = $this->send_payment( $order, $this->payment_payload( $order, [ 'Classif' => 'A' ] ) );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( self::SMSE_ID, $order->get_transaction_id() );
	}

	/**
	 * @testdox 信用卡授權失敗（Response_id=0）不可標成已付款
	 */
	public function test_failed_credit_callback_not_paid() {
		$order = $this->create_order(
			[
				'status'         => 'pending',
				'payment_method' => 'ry_smilepay_credit',
				'transaction_id' => '',
			]
		);
		$order = $this->send_payment(
			$order,
			$this->payment_payload(
				$order,
				[
					'Classif'     => 'A',
					'Response_id' => '0',
				]
			)
		);
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 'failed', $order->get_status() );
	}

	/**
	 * @testdox Data_id 不是本站送出過的就拒絕
	 */
	public function test_unknown_data_id_rejected() {
		$order   = $this->create_order();
		$payload = $this->payment_payload( $order, [ 'Data_id' => $order->get_id() . 'TS0000000000' ] );
		$this->assertNull( RY_SmilePay_Gateway_Response::validate_callback( $payload ) );
		$this->assertFalse( $this->send_payment( $order, $payload )->is_paid() );
	}

	/**
	 * @testdox Smseid 與取號時存下的交易編號不符就拒絕（即使驗證碼依該 Smseid 算對）
	 */
	public function test_smseid_mismatch_rejected() {
		$order   = $this->create_order();
		$payload = $this->payment_payload( $order, [ 'Smseid' => '87654321' ] );
		$this->assertNull( RY_SmilePay_Gateway_Response::validate_callback( $payload ) );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}

	/**
	 * @testdox 付款方式不是速買配就拒絕
	 */
	public function test_non_smilepay_order_rejected() {
		$order = $this->create_order( [ 'payment_method' => 'bacs' ] );
		$order = $this->send_payment( $order, $this->payment_payload( $order ) );
		$this->assertFalse( $order->is_paid() );
	}

	/**
	 * @testdox 金額與訂單不符就拒絕
	 */
	public function test_amount_mismatch_rejected() {
		$order = $this->create_order();
		$order = $this->send_payment( $order, $this->payment_payload( $order, [ 'Amount' => '1' ] ) );
		$this->assertFalse( $order->is_paid() );
	}

	/**
	 * @testdox 驗證碼錯誤就拒絕
	 */
	public function test_wrong_check_value_rejected() {
		$order = $this->create_order();
		$order = $this->send_payment( $order, $this->payment_payload( $order, [ 'Mid_smilepay' => '1' ] ) );
		$this->assertFalse( $order->is_paid() );
	}

	/**
	 * @testdox 已取消的訂單不會被合法回呼改回已付款
	 */
	public function test_cancelled_order_not_revived() {
		$order = $this->create_order( [ 'status' => 'cancelled' ] );
		$order = $this->send_payment( $order, $this->payment_payload( $order ) );
		$this->assertSame( 'cancelled', $order->get_status() );
	}

	/**
	 * @testdox 正式環境取號的訂單不接受測試模式（公開參數碼 9527）簽的回呼
	 */
	public function test_testmode_callback_rejected_for_live_order() {
		$order = $this->create_order( [ 'testmode' => 'no' ] );
		$order = $this->send_payment( $order, $this->payment_payload( $order ) );
		$this->assertFalse( $order->is_paid() );
	}

	/**
	 * @testdox 驗證失敗 5 次後鎖定訂單，之後連正確回呼也拒絕並留訂單備註
	 */
	public function test_lockout_after_repeated_failures() {
		$order = $this->create_order();
		for ( $i = 0; $i < RY_SmilePay_Gateway_Response::MAX_FAILURES; $i++ ) {
			$this->send_payment( $order, $this->payment_payload( $order, [ 'Mid_smilepay' => (string) $i ] ) );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( RY_SmilePay_Gateway_Response::is_locked( $order ) );

		$order = $this->send_payment( $order, $this->payment_payload( $order ) );
		$this->assertFalse( $order->is_paid() );

		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$found = false;
		foreach ( $notes as $note ) {
			if ( false !== strpos( $note->content, '速買配付款回呼驗證連續失敗' ) ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, '鎖定時應留訂單備註提醒商家' );
	}

	/**
	 * @testdox 不知道 Data_id 的人送再多錯誤回呼也鎖不住別人的訂單
	 */
	public function test_unknown_data_id_does_not_lock_order() {
		$order = $this->create_order();
		for ( $i = 0; $i < RY_SmilePay_Gateway_Response::MAX_FAILURES + 1; $i++ ) {
			$this->send_payment( $order, $this->payment_payload( $order, [ 'Data_id' => $order->get_id() . 'TS000000000' . $i ] ) );
		}
		$this->assertFalse( RY_SmilePay_Gateway_Response::is_locked( $order ) );

		$order = $this->send_payment( $order, $this->payment_payload( $order ) );
		$this->assertTrue( $order->is_paid() );
	}

	/**
	 * 建立有速買配物流單的訂單
	 *
	 * @param string $status 訂單狀態。
	 * @return WC_Order
	 */
	private function create_shipping_order( $status ) {
		$order   = $this->create_order( [ 'status' => $status ] );
		$data_id = $order->get_id() . 'TS5' . '1111111111';
		$order->update_meta_data(
			'_smilepay_shipping_info',
			[
				'S999' => [
					'ID'      => 'S999',
					'Data_id' => $data_id,
					'status'  => 0,
				],
			]
		);
		$order->update_meta_data( '_smilepay_shipping_Data_id_list', [ $data_id ] );
		$order->save();
		return wc_get_order( $order->get_id() );
	}

	/**
	 * 貨態回呼資料
	 *
	 * @param WC_Order $order  訂單。
	 * @param string   $status 貨態。
	 * @return array
	 */
	private function status_payload( WC_Order $order, $status ) {
		return [
			'Data_id' => $order->get_meta( '_smilepay_shipping_info' )['S999']['Data_id'],
			'Smseid'  => 'S999',
			'Status'  => $status,
		];
	}

	/**
	 * @testdox 貨態「已取貨」不能把未付款訂單改成完成
	 */
	public function test_status_callback_cannot_complete_unpaid_order() {
		$order = $this->create_shipping_order( 'on-hold' );
		RY_SmilePay_Shipping_Response::apply_status_callback( $this->status_payload( $order, '3' ) );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox 貨態「到店」不能把未付款訂單改成已付款狀態 ry-at-cvs
	 */
	public function test_status_callback_cannot_mark_unpaid_at_cvs() {
		$order = $this->create_shipping_order( 'pending' );
		RY_SmilePay_Shipping_Response::apply_status_callback( $this->status_payload( $order, '2' ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertFalse( $order->is_paid() );
	}

	/**
	 * @testdox 已付款訂單收到「已取貨」貨態仍會自動完成（回歸）
	 */
	public function test_status_callback_completes_paid_order() {
		$order = $this->create_shipping_order( 'processing' );
		RY_SmilePay_Shipping_Response::apply_status_callback( $this->status_payload( $order, '3' ) );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox 貨態回呼的 Smseid 不在訂單物流單內、或 Data_id 不符就拒絕
	 */
	public function test_status_callback_rejects_unknown_shipment() {
		$order = $this->create_shipping_order( 'processing' );

		$payload           = $this->status_payload( $order, '3' );
		$payload['Smseid'] = 'S000';
		$this->assertNull( RY_SmilePay_Shipping_Response::validate_status_callback( $payload ) );

		$payload            = $this->status_payload( $order, '3' );
		$payload['Data_id'] = $order->get_id() . 'TS0000000000';
		$this->assertNull( RY_SmilePay_Shipping_Response::validate_status_callback( $payload ) );

		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * 門市地圖回呼資料
	 *
	 * @param WC_Order $order   訂單。
	 * @param string   $classif 收款類型。
	 * @return array
	 */
	private function map_payload( WC_Order $order, $classif ) {
		return [
			'Data_id'      => $order->get_meta( '_smilepay_shipping_Data_id_list' )[0],
			'Smseid'       => 'S100',
			'Status'       => '1',
			'Classif'      => $classif,
			'Classif_sub'  => '1',
			'Amount'       => '500',
			'Storeid'      => '123456',
			'Storename'    => '門市',
			'Storeaddress' => '地址',
		];
	}

	/**
	 * @testdox 門市地圖回呼帶 Classif=T 不能把非貨到付款的訂單改成 processing
	 */
	public function test_map_callback_classif_t_cannot_mark_smilepay_order_paid() {
		$order = $this->create_shipping_order( 'pending' );
		RY_SmilePay_Shipping_Response::apply_map_callback( $this->map_payload( $order, 'T' ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertFalse( $order->is_paid() );
	}

	/**
	 * @testdox 門市地圖回呼的 Data_id 不是本站送出過的就拒絕
	 */
	public function test_map_callback_unknown_data_id_rejected() {
		$order = $this->create_shipping_order( 'pending' );
		$order->set_payment_method( 'cod' );
		$order->save();

		$payload            = $this->map_payload( $order, 'T' );
		$payload['Data_id'] = $order->get_id() . 'TS0000000000';
		RY_SmilePay_Shipping_Response::apply_map_callback( $payload );
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox 貨到付款訂單選完門市仍會改成 processing（回歸）
	 */
	public function test_map_callback_cod_order_moves_to_processing() {
		$order = $this->create_shipping_order( 'pending' );
		$order->set_payment_method( 'cod' );
		$order->save();

		RY_SmilePay_Shipping_Response::apply_map_callback( $this->map_payload( $order, 'T' ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( '123456', $order->get_meta( '_shipping_cvs_store_ID' ) );
	}

	/**
	 * 呼叫 AJAX 取號，回傳輸出內容
	 *
	 * @param string $method RY_SmilePay_Gateway 的方法名稱。
	 * @param array  $query  $_GET。
	 * @return string
	 */
	private function call_getcode( $method, array $query ) {
		$_GET     = $query;
		$_REQUEST = $query;
		ob_start();
		try {
			RY_SmilePay_Gateway::$method();
		} catch ( WPDieException $e ) {
			unset( $e );
		}
		return (string) ob_get_clean();
	}

	/**
	 * @testdox AJAX 取號沒帶 key 或 key 錯誤會被拒，且不回傳含 order key 的網址
	 */
	public function test_getcode_without_key_rejected() {
		$order = $this->create_order( [ 'status' => 'processing' ] );

		foreach ( [ 'get_code', 'shipping_get_code' ] as $method ) {
			$output = $this->call_getcode( $method, [ 'id' => $order->get_id() ] );
			$this->assertStringNotContainsString( $order->get_order_key(), $output );

			$output = $this->call_getcode(
				$method,
				[
					'id'  => $order->get_id(),
					'key' => 'wc_order_wrong',
				]
			);
			$this->assertStringNotContainsString( $order->get_order_key(), $output );
		}
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox 非速買配訂單即使帶正確 key 也不回傳訂單網址
	 */
	public function test_getcode_non_smilepay_order_rejected() {
		$order  = $this->create_order( [ 'payment_method' => 'bacs' ] );
		$output = $this->call_getcode(
			'get_code',
			[
				'id'  => $order->get_id(),
				'key' => $order->get_order_key(),
			]
		);
		$this->assertStringNotContainsString( $order->get_order_key(), $output );
	}

	/**
	 * @testdox 已付款訂單帶正確 key 呼叫取號不會重新取號降級成 on-hold
	 */
	public function test_getcode_does_not_downgrade_paid_order() {
		$order  = $this->create_order( [ 'status' => 'processing' ] );
		$output = $this->call_getcode(
			'get_code',
			[
				'id'  => $order->get_id(),
				'key' => $order->get_order_key(),
			]
		);
		$this->assertStringContainsString( 'order-received', $output );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}
}

<?php
/**
 * 綠界 ECPay 後台退款 API 同步串接整合測試（issue #123）
 *
 * 驗證 WooCommerce 後台對綠界信用卡訂單按「退款」時，process_refund()
 * 會同步呼叫綠界 CreditDetail/DoAction 進行退刷（含 QueryTradeInfo 前置驗證、
 * 未關帳降級為 Action=N、非信用卡人工指引、CheckMacValue 組裝正確性等情境）。
 * 全程以 pre_http_request 攔截 wp_remote_post，不觸發任何真實網路請求。
 *
 * TDD Red 階段說明：
 * 本檔測試預期「全數失敗」。原因：
 * - process_refund() 目前繼承 WC_Payment_Gateway 的預設實作（永遠 return false），
 *   尚未依 payment_type 分流、尚未呼叫任何綠界 API。
 * - query_trade_info() / do_credit_action() / process_credit_refund() 三個新方法
 *   尚未存在於 RY_ECPay_Gateway_Api。
 * 測試一律透過 gateway 的公開 process_refund() 介面驅動（黑箱），不直接呼叫
 * query_trade_info() / do_credit_action()。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 綠界後台退款測試類別
 *
 * 測試案例對照表（issue #123 實作計劃 10 案例）：
 * | # | 測試方法 |
 * |---|----------|
 * | 1 | test_case_01_full_refund_on_credit_gateway_returns_true |
 * | 2 | test_case_02_partial_refund_sends_matching_total_amount |
 * | 3 | test_case_03_not_settled_downgrades_to_action_n |
 * | 4 | test_case_04_non_credit_gateway_returns_wp_error_with_taiwanese_guidance |
 * | 5 | test_case_05_installment_partial_refund_rejected_without_api_call |
 * | 6 | test_case_06_query_trade_info_network_failure_aborts_before_do_action |
 * | 7 | test_case_07_query_trade_info_trade_status_mismatch_returns_wp_error |
 * | 8 | test_case_08_do_action_non_settlement_failure_returns_wp_error_with_rtn_msg |
 * | 9 | test_case_09_do_action_check_mac_value_matches_independent_recalculation |
 * | 10 | test_case_10_missing_transaction_id_returns_wp_error |
 *
 * @covers includes/ry-woocommerce-tools/woocommerce/gateways/ecpay/includes/ecpay-gateway-base.php
 * @covers includes/ry-woocommerce-tools/woocommerce/gateways/ecpay/includes/ecpay-gateway-api.php
 * @group gateway
 * @group ecpay
 * @group refund
 */
class EcpayRefundTest extends WP_UnitTestCase {

	/**
	 * 測試模式綠界商店代號（與 RY_ECPay_Gateway::get_ecpay_api_info() 測試模式回傳值一致）
	 *
	 * @var string
	 */
	private const TEST_MERCHANT_ID = '3002599';

	/**
	 * 測試模式綠界 HashKey（與 RY_ECPay_Gateway::get_ecpay_api_info() 測試模式回傳值一致）
	 *
	 * @var string
	 */
	private const TEST_HASH_KEY = 'spPjZn66i0OhqJsQ';

	/**
	 * 測試模式綠界 HashIV（與 RY_ECPay_Gateway::get_ecpay_api_info() 測試模式回傳值一致）
	 *
	 * @var string
	 */
	private const TEST_HASH_IV = 'hT5OJckN45isQTTs';

	/**
	 * 本次測試建立的訂單，tearDown 統一刪除
	 *
	 * @var WC_Order[]
	 */
	private $orders = [];

	/**
	 * 攔截到送往綠界的請求紀錄
	 *
	 * 每筆為 ['url' => string, 'body' => array]。
	 *
	 * @var array
	 */
	private $sent_requests = [];

	/**
	 * QueryTradeInfo 假回應佇列（FIFO），為空時退回預設成功回應
	 *
	 * @var array
	 */
	private $query_responses = [];

	/**
	 * DoAction 假回應佇列（FIFO），為空時退回預設成功回應
	 *
	 * @var array
	 */
	private $doaction_responses = [];

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'RY_ECPay_Gateway_Credit' ) ) {
			$this->markTestSkipped( 'ry-woocommerce-tools 綠界模組未載入，跳過退款測試' );
		}

		$this->orders             = [];
		$this->sent_requests      = [];
		$this->query_responses    = [];
		$this->doaction_responses = [];

		add_filter( 'pre_http_request', [ $this, 'intercept_ecpay_http_request' ], 10, 3 );
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_ecpay_http_request' ], 10 );

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
	 * 攔截送往綠界的 wp_remote_post，記錄送出的請求並回傳假回應
	 *
	 * 僅攔截網域包含 ecpay.com.tw 的請求（涵蓋正式站與 Stage 測試站），
	 * 其餘一律放行，避免影響 WordPress 測試框架本身的其他請求。
	 *
	 * @param false|array|WP_Error $preempt     短路回傳值，預設 false。
	 * @param array                $parsed_args wp_remote_post 的請求參數。
	 * @param string               $url         請求網址。
	 * @return false|array|WP_Error
	 */
	public function intercept_ecpay_http_request( $preempt, $parsed_args, $url ) {
		if ( false === strpos( $url, 'ecpay.com.tw' ) ) {
			return $preempt;
		}

		$body = [];
		if ( isset( $parsed_args['body'] ) ) {
			if ( is_array( $parsed_args['body'] ) ) {
				$body = $parsed_args['body'];
			} elseif ( is_string( $parsed_args['body'] ) ) {
				parse_str( $parsed_args['body'], $body );
			}
		}

		$this->sent_requests[] = [
			'url'  => $url,
			'body' => $body,
		];

		if ( false !== strpos( $url, 'QueryTradeInfo' ) ) {
			if ( ! empty( $this->query_responses ) ) {
				return array_shift( $this->query_responses );
			}
			return $this->build_ecpay_http_response(
				[
					'MerchantID'      => self::TEST_MERCHANT_ID,
					'MerchantTradeNo' => $body['MerchantTradeNo'] ?? 'TESTMTN000000',
					'TradeNo'         => 'ECTESTTN00001',
					'TradeAmt'        => '1000',
					'PaymentType'     => 'Credit_CreditCard',
					'TradeDate'       => gmdate( 'Y/m/d H:i:s' ),
					'PaymentDate'     => gmdate( 'Y/m/d H:i:s' ),
					'TradeStatus'     => '1',
				]
			);
		}

		if ( false !== strpos( $url, 'DoAction' ) ) {
			if ( ! empty( $this->doaction_responses ) ) {
				return array_shift( $this->doaction_responses );
			}
			// DoAction 回應不含 CheckMacValue（綠界官方規格 2885.md），以 $include_check_mac=false 模擬真實契約。
			return $this->build_ecpay_http_response(
				[
					'MerchantID'      => self::TEST_MERCHANT_ID,
					'MerchantTradeNo' => $body['MerchantTradeNo'] ?? 'TESTMTN000000',
					'TradeNo'         => $body['TradeNo'] ?? 'ECTESTTN00001',
					'RtnCode'         => '1',
					'RtnMsg'          => 'Succeeded',
				],
				false
			);
		}

		return $preempt;
	}

	// ------------------------------------------------------------------
	// Fixture 建構
	// ------------------------------------------------------------------

	/**
	 * 建立一筆已付款的綠界訂單 fixture
	 *
	 * @param string      $gateway_id         金流閘道 ID（決定 payment_method）。
	 * @param float       $total              訂單總金額。
	 * @param string|null $trade_no           綠界交易編號（TradeNo）。傳入 null 表示
	 *                                        不呼叫 payment_complete()，模擬查無交易編號的訂單。
	 * @param string      $merchant_trade_no  商店訂單編號（_ecpay_MerchantTradeNo meta）。
	 *                                        留空則自動產生。
	 * @return WC_Order
	 */
	private function create_ecpay_order( string $gateway_id, float $total, ?string $trade_no, string $merchant_trade_no = '' ): WC_Order {
		if ( '' === $merchant_trade_no ) {
			$merchant_trade_no = 'TESTMTN' . wp_rand( 100000, 999999 );
		}

		$order = wc_create_order();
		$order->set_payment_method( $gateway_id );
		$order->set_total( $total );
		$order->set_status( 'pending' );
		$order->update_meta_data( '_ecpay_MerchantTradeNo', $merchant_trade_no );
		$order->save();

		if ( null !== $trade_no ) {
			$order->payment_complete( $trade_no );
		}

		$order           = wc_get_order( $order->get_id() );
		$this->orders[] = $order;

		return $order;
	}

	// ------------------------------------------------------------------
	// CheckMacValue（獨立重算，供斷言比對）
	// ------------------------------------------------------------------

	/**
	 * 依綠界官方演算法獨立重算 CheckMacValue
	 *
	 * 複製 RY_ECPay::generate_check_value() 的邏輯（ksort + HashKey/HashIV
	 * 前後包夾 + 特殊 urlencode + 轉小寫 + SHA256 + 轉大寫），使用測試模式
	 * 憑證，供斷言比對「送出的 CheckMacValue」是否正確。因原方法為
	 * protected，非同一繼承鏈無法直接呼叫，故於測試端獨立重算。
	 *
	 * @param array $params 交易參數（含或不含 CheckMacValue 皆可，內部會自動排除）。
	 * @return string 64 字元大寫 SHA256 CheckMacValue。
	 */
	private function generate_ecpay_check_mac_value( array $params ): string {
		unset( $params['CheckMacValue'] );

		ksort( $params, SORT_STRING | SORT_FLAG_CASE );

		$pairs   = [ 'HashKey=' . self::TEST_HASH_KEY ];
		foreach ( $params as $key => $value ) {
			$pairs[] = $key . '=' . $value;
		}
		$pairs[] = 'HashIV=' . self::TEST_HASH_IV;

		$check_str = implode( '&', $pairs );
		$check_str = $this->ecpay_urlencode( $check_str );
		$check_str = strtolower( $check_str );

		return strtoupper( hash( 'sha256', $check_str ) );
	}

	/**
	 * 複製綠界官方（.NET 風格）URL Encode 規則
	 *
	 * 與 RY_ECPay::urlencode() 邏輯一致：PHP urlencode() 後，將
	 * -_.!*() 還原為未跳脫字元。
	 *
	 * @param string $string 原始字串。
	 * @return string 編碼後字串。
	 */
	private function ecpay_urlencode( string $string ): string {
		return str_replace(
			[ '%2D', '%2d', '%5F', '%5f', '%2E', '%2e', '%2A', '%2a', '%21', '%28', '%29' ],
			[ '-', '-', '_', '_', '.', '.', '*', '*', '!', '(', ')' ],
			urlencode( $string )
		);
	}

	/**
	 * 組出模擬綠界 API 回應（WP_Http 回應陣列格式，body 為 URL-encoded 字串）
	 *
	 * QueryTradeInfo 回應含 CheckMacValue（商家須驗章）；CreditDetail/DoAction 回應
	 * 依綠界官方規格（2885.md）「不含」CheckMacValue，故以 $include_check_mac=false
	 * 產生符合真實契約的回應，避免測試以不存在的欄位掩蓋正式站行為。
	 *
	 * @param array $params            回應欄位（不含 CheckMacValue）。
	 * @param bool  $include_check_mac 是否補上 CheckMacValue（QueryTradeInfo 為 true；DoAction 為 false）。
	 * @return array WP_Http 風格回應陣列，可直接作為 pre_http_request 短路回傳值。
	 */
	private function build_ecpay_http_response( array $params, bool $include_check_mac = true ): array {
		if ( $include_check_mac ) {
			$params['CheckMacValue'] = $this->generate_ecpay_check_mac_value( $params );
		}

		return [
			'headers'  => [],
			'body'     => http_build_query( $params, '', '&' ),
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * 將一筆 QueryTradeInfo 假回應加入佇列
	 *
	 * @param array $overrides 覆蓋預設欄位的值。
	 */
	private function queue_query_response( array $overrides = [] ): void {
		$defaults = [
			'MerchantID'      => self::TEST_MERCHANT_ID,
			'MerchantTradeNo' => 'TESTMTN000000',
			'TradeNo'         => 'ECTESTTN00001',
			'TradeAmt'        => '1000',
			'PaymentType'     => 'Credit_CreditCard',
			'TradeDate'       => gmdate( 'Y/m/d H:i:s' ),
			'PaymentDate'     => gmdate( 'Y/m/d H:i:s' ),
			'TradeStatus'     => '1',
		];

		$this->query_responses[] = $this->build_ecpay_http_response( array_merge( $defaults, $overrides ) );
	}

	/**
	 * 將一筆 DoAction 假回應加入佇列
	 *
	 * @param array $overrides 覆蓋預設欄位的值。
	 */
	private function queue_doaction_response( array $overrides = [] ): void {
		$defaults = [
			'MerchantID'      => self::TEST_MERCHANT_ID,
			'MerchantTradeNo' => 'TESTMTN000000',
			'TradeNo'         => 'ECTESTTN00001',
			'RtnCode'         => '1',
			'RtnMsg'          => 'Succeeded',
		];

		// DoAction 回應不含 CheckMacValue，故 $include_check_mac=false（對齊真實契約）。
		$this->doaction_responses[] = $this->build_ecpay_http_response( array_merge( $defaults, $overrides ), false );
	}

	/**
	 * 從已攔截的請求中，篩選出送往 CreditDetail/DoAction 的請求
	 *
	 * @return array 依送出順序排列，每筆為 ['url' => string, 'body' => array]。
	 */
	private function get_sent_doaction_requests(): array {
		return array_values(
			array_filter(
				$this->sent_requests,
				static function ( $request ) {
					return false !== strpos( $request['url'], 'DoAction' );
				}
			)
		);
	}

	// ------------------------------------------------------------------
	// 案例 1：信用卡全額退款
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例1：信用卡全額退款於 DoAction 回傳 RtnCode=1 時應回傳 true
	 * @group happy
	 */
	public function test_case_01_full_refund_on_credit_gateway_returns_true() {
		$gateway = new RY_ECPay_Gateway_Credit();
		$order   = $this->create_ecpay_order( $gateway->id, 1000, 'EC' . wp_rand( 100000, 999999 ) );

		$this->queue_query_response(
			[
				'MerchantTradeNo' => $order->get_meta( '_ecpay_MerchantTradeNo' ),
				'TradeNo'         => $order->get_transaction_id(),
				'TradeAmt'        => '1000',
			]
		);
		$this->queue_doaction_response(
			[
				'MerchantTradeNo' => $order->get_meta( '_ecpay_MerchantTradeNo' ),
				'TradeNo'         => $order->get_transaction_id(),
				'RtnCode'         => '1',
				'RtnMsg'          => 'Succeeded',
			]
		);

		$result = $gateway->process_refund( $order->get_id(), 1000, '顧客申請全額退款' );

		$this->assertTrue( $result, '信用卡全額退款於 DoAction 回傳 RtnCode=1 時應回傳 true' );

		$doaction_requests = $this->get_sent_doaction_requests();
		$this->assertNotEmpty( $doaction_requests, 'process_refund 應呼叫綠界 CreditDetail/DoAction API' );
		$this->assertSame(
			1000,
			(int) end( $doaction_requests )['body']['TotalAmount'],
			'DoAction 送出的 TotalAmount 應等於全額退款金額'
		);
	}

	// ------------------------------------------------------------------
	// 案例 2：信用卡部分退款
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例2：信用卡部分退款應於 DoAction 送出等於退款金額的 TotalAmount
	 * @group happy
	 */
	public function test_case_02_partial_refund_sends_matching_total_amount() {
		$gateway        = new RY_ECPay_Gateway_Credit();
		$order          = $this->create_ecpay_order( $gateway->id, 1000, 'EC' . wp_rand( 100000, 999999 ) );
		$partial_amount = 300;

		$this->queue_query_response(
			[
				'MerchantTradeNo' => $order->get_meta( '_ecpay_MerchantTradeNo' ),
				'TradeNo'         => $order->get_transaction_id(),
				'TradeAmt'        => '1000',
			]
		);
		$this->queue_doaction_response(
			[
				'MerchantTradeNo' => $order->get_meta( '_ecpay_MerchantTradeNo' ),
				'TradeNo'         => $order->get_transaction_id(),
				'RtnCode'         => '1',
				'RtnMsg'          => 'Succeeded',
			]
		);

		$result = $gateway->process_refund( $order->get_id(), $partial_amount, '顧客申請部分退款' );

		$this->assertTrue( $result, '信用卡部分退款於 DoAction 回傳 RtnCode=1 時應回傳 true' );

		$doaction_requests = $this->get_sent_doaction_requests();
		$this->assertNotEmpty( $doaction_requests, 'process_refund 應呼叫綠界 CreditDetail/DoAction API' );
		$this->assertSame(
			$partial_amount,
			(int) end( $doaction_requests )['body']['TotalAmount'],
			'DoAction 送出的 TotalAmount 應等於部分退款金額，而非訂單全額'
		);
	}

	// ------------------------------------------------------------------
	// 案例 3：未關帳降級為 Action=N
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例3：DoAction 回傳未關帳錯誤且為全額退款時應降級送出 Action=N
	 * @group edge
	 */
	public function test_case_03_not_settled_downgrades_to_action_n() {
		$gateway = new RY_ECPay_Gateway_Credit();
		$order   = $this->create_ecpay_order( $gateway->id, 1000, 'EC' . wp_rand( 100000, 999999 ) );

		$this->queue_query_response(
			[
				'MerchantTradeNo' => $order->get_meta( '_ecpay_MerchantTradeNo' ),
				'TradeNo'         => $order->get_transaction_id(),
				'TradeAmt'        => '1000',
			]
		);
		// 第一次 DoAction（Action=R）：綠界回覆尚未關帳，無法退款。
		$this->queue_doaction_response(
			[
				'MerchantTradeNo' => $order->get_meta( '_ecpay_MerchantTradeNo' ),
				'TradeNo'         => $order->get_transaction_id(),
				'RtnCode'         => '10100248',
				'RtnMsg'          => '此筆交易尚未關帳，無法執行退款',
			]
		);
		// 第二次 DoAction（降級 Action=N）：取消授權成功。
		$this->queue_doaction_response(
			[
				'MerchantTradeNo' => $order->get_meta( '_ecpay_MerchantTradeNo' ),
				'TradeNo'         => $order->get_transaction_id(),
				'RtnCode'         => '1',
				'RtnMsg'          => 'Succeeded',
			]
		);

		$result = $gateway->process_refund( $order->get_id(), 1000, '顧客申請全額退款' );

		$this->assertTrue( $result, '未關帳降級為 Action=N 且執行成功時應回傳 true' );

		$doaction_requests = $this->get_sent_doaction_requests();
		$this->assertCount(
			2,
			$doaction_requests,
			'未關帳降級情境應送出兩次 DoAction 請求（先 R 後降級 N）'
		);
		$this->assertSame( 'R', $doaction_requests[0]['body']['Action'], '第一次 DoAction 應送出 Action=R' );
		$this->assertSame( 'N', $doaction_requests[1]['body']['Action'], '第二次降級應送出 Action=N' );
	}

	// ------------------------------------------------------------------
	// 案例 4：非信用卡 gateway
	// ------------------------------------------------------------------

	/**
	 * @dataProvider provide_non_credit_gateways
	 * @testdox 案例4：非信用卡閘道退款應回傳 WP_Error 並指引登入綠界後台（涵蓋 ATM／CVS／BARCODE／WebATM）
	 * @group error
	 */
	public function test_case_04_non_credit_gateway_returns_wp_error_with_taiwanese_guidance( string $gateway_class, string $payment_type_label ) {
		$gateway = new $gateway_class();
		$order   = $this->create_ecpay_order( $gateway->id, 500, 'EC' . wp_rand( 100000, 999999 ) );

		$result = $gateway->process_refund( $order->get_id(), 500, '顧客申請退款' );

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			"非信用卡閘道（{$payment_type_label}）退款應回傳 WP_Error"
		);
		$this->assertStringContainsString(
			'請登入綠界廠商後台',
			$result->get_error_message(),
			'WP_Error 訊息應指引商家登入綠界廠商後台手動辦理退款'
		);
		$this->assertCount( 0, $this->sent_requests, '非信用卡閘道不應呼叫任何綠界 API' );
	}

	/**
	 * 提供案例 4 的非信用卡閘道資料（ATM／CVS／BARCODE／WebATM）
	 *
	 * @return array
	 */
	public static function provide_non_credit_gateways(): array {
		return [
			'ATM 銀行轉帳'    => [ 'RY_ECPay_Gateway_Atm', 'ATM' ],
			'CVS 超商代碼'    => [ 'RY_ECPay_Gateway_Cvc', 'CVS' ],
			'BARCODE 超商條碼' => [ 'RY_ECPay_Gateway_Barcode', 'BARCODE' ],
			'WebATM 網路 ATM' => [ 'RY_ECPay_Gateway_Webatm', 'WebATM' ],
		];
	}

	// ------------------------------------------------------------------
	// 案例 5：信用卡分期非全額退款
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例5：信用卡分期閘道非全額退款應回傳 WP_Error 且未呼叫綠界 API
	 * @group error
	 */
	public function test_case_05_installment_partial_refund_rejected_without_api_call() {
		$gateway = new WMP_ECPay_Gateway_Credit_Installment_3();
		$order   = $this->create_ecpay_order( $gateway->id, 3000, 'EC' . wp_rand( 100000, 999999 ) );

		$result = $gateway->process_refund( $order->get_id(), 1500, '顧客僅要求部分退款' );

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'信用卡分期訂單要求非全額退款時應回傳 WP_Error'
		);
		$this->assertCount(
			0,
			$this->sent_requests,
			'分期訂單非全額退款應在呼叫綠界 API 前即被拒絕'
		);
	}

	// ------------------------------------------------------------------
	// 案例 6：QueryTradeInfo 網路失敗
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例6：QueryTradeInfo 網路連線失敗時應中止並回傳 WP_Error，不送出 DoAction
	 * @group error
	 */
	public function test_case_06_query_trade_info_network_failure_aborts_before_do_action() {
		$gateway = new RY_ECPay_Gateway_Credit();
		$order   = $this->create_ecpay_order( $gateway->id, 1000, 'EC' . wp_rand( 100000, 999999 ) );

		$this->query_responses[] = new WP_Error(
			'http_request_failed',
			'模擬連線逾時，無法連線至綠界 QueryTradeInfo'
		);

		$result = $gateway->process_refund( $order->get_id(), 1000, '顧客申請全額退款' );

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'QueryTradeInfo 連線失敗時應回傳 WP_Error 並中止流程'
		);
		$this->assertCount( 1, $this->sent_requests, '應嘗試呼叫 QueryTradeInfo 一次' );
		$this->assertStringContainsString(
			'QueryTradeInfo',
			$this->sent_requests[0]['url'],
			'唯一送出的請求應為 QueryTradeInfo'
		);
		$this->assertCount(
			0,
			$this->get_sent_doaction_requests(),
			'QueryTradeInfo 失敗後不應送出 DoAction 請求'
		);
	}

	// ------------------------------------------------------------------
	// 案例 7：QueryTradeInfo TradeStatus 不符
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例7：QueryTradeInfo 回傳 TradeStatus 非 1 時應回傳 WP_Error
	 * @group error
	 */
	public function test_case_07_query_trade_info_trade_status_mismatch_returns_wp_error() {
		$gateway = new RY_ECPay_Gateway_Credit();
		$order   = $this->create_ecpay_order( $gateway->id, 1000, 'EC' . wp_rand( 100000, 999999 ) );

		$this->queue_query_response(
			[
				'MerchantTradeNo' => $order->get_meta( '_ecpay_MerchantTradeNo' ),
				'TradeNo'         => $order->get_transaction_id(),
				'TradeAmt'        => '1000',
				'TradeStatus'     => '0',
			]
		);

		$result = $gateway->process_refund( $order->get_id(), 1000, '顧客申請全額退款' );

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'TradeStatus 非 1（尚未付款）時應回傳 WP_Error'
		);
		$this->assertCount( 1, $this->sent_requests, '應只呼叫 QueryTradeInfo 一次' );
		$this->assertStringContainsString(
			'QueryTradeInfo',
			$this->sent_requests[0]['url'],
			'唯一送出的請求應為 QueryTradeInfo'
		);
		$this->assertCount(
			0,
			$this->get_sent_doaction_requests(),
			'TradeStatus 驗證失敗後不應送出 DoAction 請求'
		);
	}

	// ------------------------------------------------------------------
	// 案例 8：DoAction 失敗（非未關帳）
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例8：DoAction 回傳非未關帳的失敗 RtnCode 時應回傳含 RtnMsg 的 WP_Error
	 * @group error
	 */
	public function test_case_08_do_action_non_settlement_failure_returns_wp_error_with_rtn_msg() {
		$gateway = new RY_ECPay_Gateway_Credit();
		$order   = $this->create_ecpay_order( $gateway->id, 1000, 'EC' . wp_rand( 100000, 999999 ) );
		$rtn_msg = '信用卡授權已取消，無法執行退款';

		$this->queue_query_response(
			[
				'MerchantTradeNo' => $order->get_meta( '_ecpay_MerchantTradeNo' ),
				'TradeNo'         => $order->get_transaction_id(),
				'TradeAmt'        => '1000',
			]
		);
		$this->queue_doaction_response(
			[
				'MerchantTradeNo' => $order->get_meta( '_ecpay_MerchantTradeNo' ),
				'TradeNo'         => $order->get_transaction_id(),
				'RtnCode'         => '10100050',
				'RtnMsg'          => $rtn_msg,
			]
		);

		$result = $gateway->process_refund( $order->get_id(), 1000, '顧客申請全額退款' );

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'DoAction 回傳非未關帳的失敗代碼時應回傳 WP_Error'
		);
		$this->assertStringContainsString(
			$rtn_msg,
			$result->get_error_message(),
			'WP_Error 訊息應包含綠界回傳的 RtnMsg 內容'
		);
		$this->assertCount(
			1,
			$this->get_sent_doaction_requests(),
			'非未關帳的失敗不應觸發 Action=N 降級重試'
		);
	}

	// ------------------------------------------------------------------
	// 案例 9：CheckMacValue 組裝正確性
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例9：DoAction 送出的 CheckMacValue 應與依官方演算法獨立重算的結果一致
	 * @group security
	 */
	public function test_case_09_do_action_check_mac_value_matches_independent_recalculation() {
		$gateway = new RY_ECPay_Gateway_Credit();
		$order   = $this->create_ecpay_order( $gateway->id, 1000, 'EC' . wp_rand( 100000, 999999 ) );

		$this->queue_query_response(
			[
				'MerchantTradeNo' => $order->get_meta( '_ecpay_MerchantTradeNo' ),
				'TradeNo'         => $order->get_transaction_id(),
				'TradeAmt'        => '1000',
			]
		);
		$this->queue_doaction_response(
			[
				'MerchantTradeNo' => $order->get_meta( '_ecpay_MerchantTradeNo' ),
				'TradeNo'         => $order->get_transaction_id(),
				'RtnCode'         => '1',
				'RtnMsg'          => 'Succeeded',
			]
		);

		$gateway->process_refund( $order->get_id(), 1000, '顧客申請全額退款' );

		$doaction_requests = $this->get_sent_doaction_requests();
		$this->assertNotEmpty(
			$doaction_requests,
			'process_refund 應呼叫綠界 CreditDetail/DoAction API 才能驗證 CheckMacValue'
		);

		$sent_body = end( $doaction_requests )['body'];
		$this->assertArrayHasKey( 'CheckMacValue', $sent_body, 'DoAction 請求應包含 CheckMacValue 欄位' );

		$expected_mac = $this->generate_ecpay_check_mac_value( $sent_body );

		$this->assertSame(
			$expected_mac,
			$sent_body['CheckMacValue'],
			'DoAction 送出的 CheckMacValue 應與依官方演算法獨立重算的結果一致'
		);
	}

	// ------------------------------------------------------------------
	// 案例 10：查無交易編號
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例10：訂單查無綠界交易編號（transaction_id 為空）時應回傳 WP_Error
	 * @group edge
	 */
	public function test_case_10_missing_transaction_id_returns_wp_error() {
		$gateway = new RY_ECPay_Gateway_Credit();
		$order   = $this->create_ecpay_order( $gateway->id, 1000, null );

		$this->assertSame( '', $order->get_transaction_id(), '前置條件：訂單應無綠界交易編號' );

		$result = $gateway->process_refund( $order->get_id(), 1000, '顧客申請全額退款' );

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'訂單缺少綠界交易編號（TradeNo）時應回傳 WP_Error，而非嘗試呼叫 API'
		);
		$this->assertNotEmpty(
			$result->get_error_message(),
			'WP_Error 應攜帶說明訊息'
		);
	}

	// ------------------------------------------------------------------
	// 案例 11：DoAction 回應綁定防偽（回應 TradeNo 與訂單不符）
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例11：DoAction 回應的 TradeNo 與訂單不符時應回傳 WP_Error（回應綁定防偽）
	 * @group security
	 */
	public function test_case_11_do_action_response_trade_no_mismatch_returns_wp_error() {
		$gateway = new RY_ECPay_Gateway_Credit();
		$order   = $this->create_ecpay_order( $gateway->id, 1000, 'EC' . wp_rand( 100000, 999999 ) );

		$this->queue_query_response(
			[
				'MerchantTradeNo' => $order->get_meta( '_ecpay_MerchantTradeNo' ),
				'TradeNo'         => $order->get_transaction_id(),
				'TradeAmt'        => '1000',
			]
		);
		// 綠界 DoAction 回應不含 CheckMacValue，防偽改綁定 TradeNo；此處模擬回應的 TradeNo 被竄改為他筆交易。
		$this->queue_doaction_response(
			[
				'MerchantTradeNo' => $order->get_meta( '_ecpay_MerchantTradeNo' ),
				'TradeNo'         => 'EVILTRADENO999',
				'RtnCode'         => '1',
				'RtnMsg'          => 'Succeeded',
			]
		);

		$result = $gateway->process_refund( $order->get_id(), 1000, '顧客申請全額退款' );

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'DoAction 回應的 TradeNo 與本訂單不符時應回傳 WP_Error，不可誤判為退款成功'
		);
	}

	// ------------------------------------------------------------------
	// 案例 12：DoAction 回應綁定防偽（回應 MerchantTradeNo 與訂單不符）
	// ------------------------------------------------------------------

	/**
	 * @testdox 案例12：DoAction 回應的 MerchantTradeNo 與訂單不符時應回傳 WP_Error（回應綁定防偽）
	 * @group security
	 */
	public function test_case_12_do_action_response_merchant_trade_no_mismatch_returns_wp_error() {
		$gateway = new RY_ECPay_Gateway_Credit();
		$order   = $this->create_ecpay_order( $gateway->id, 1000, 'EC' . wp_rand( 100000, 999999 ) );

		$this->queue_query_response(
			[
				'MerchantTradeNo' => $order->get_meta( '_ecpay_MerchantTradeNo' ),
				'TradeNo'         => $order->get_transaction_id(),
				'TradeAmt'        => '1000',
			]
		);
		// 回應的 MerchantTradeNo 被竄改為他筆訂單，綁定驗證應攔截。
		$this->queue_doaction_response(
			[
				'MerchantTradeNo' => 'EVILMTN000000',
				'TradeNo'         => $order->get_transaction_id(),
				'RtnCode'         => '1',
				'RtnMsg'          => 'Succeeded',
			]
		);

		$result = $gateway->process_refund( $order->get_id(), 1000, '顧客申請全額退款' );

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'DoAction 回應的 MerchantTradeNo 與本訂單不符時應回傳 WP_Error'
		);
	}
}

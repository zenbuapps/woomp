<?php
/**
 * 綠界／藍新回呼來源驗證整合測試
 *
 * 金流回呼網址是公開的，任何人都能 POST。驗證：
 * - 金鑰為空一律拒絕（fail-closed）
 * - 簽章錯誤、付款方式不是本金流、MerchantID／訂單編號／金額不符一律拒絕
 * - 綠界 SimulatePaid=1 不付款；測試模式只接受在測試模式下送出付款的訂單
 * - 已取消／已退款訂單收到舊成功通知不改狀態；已付款訂單不被失敗通知降級
 * - 正常回呼仍能完成付款（回歸）
 *
 * 綠界 CheckMacValue、藍新 TradeInfo／TradeSha 都用官方演算法在測試端獨立產生。
 * 回呼資料以「PHP 解析後的 $_POST 陣列」直接注入（綠界簽的就是解碼後的原值），
 * 刻意放入中文、空白與字面 +，**不可**改用 http_build_query()／parse_str() 產生。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 綠界／藍新回呼來源驗證測試
 *
 * @group gateway
 * @group ecpay
 * @group newebpay
 * @group callback-verification
 */
class EcpayNewebpayCallbackVerificationTest extends WP_UnitTestCase {

	private const ECPAY_MERCHANT_ID = '3999001';
	private const ECPAY_HASH_KEY    = 'ProdHashKey12345';
	private const ECPAY_HASH_IV     = 'ProdHashIV123456';

	// 與 RY_ECPay_Gateway::get_ecpay_api_info() 測試模式的公開金鑰一致
	private const ECPAY_TEST_MERCHANT_ID = '3002599';
	private const ECPAY_TEST_HASH_KEY    = 'spPjZn66i0OhqJsQ';
	private const ECPAY_TEST_HASH_IV     = 'hT5OJckN45isQTTs';

	private const ECPAY_SHIPPING_MERCHANT_ID = '3999002';
	private const ECPAY_SHIPPING_HASH_KEY    = 'ShipHashKey12345';
	private const ECPAY_SHIPPING_HASH_IV     = 'ShipHashIV123456';

	private const NEWEBPAY_MERCHANT_ID = 'MS3999003';
	private const NEWEBPAY_HASH_KEY    = 'abcdefghijklmnopqrstuvwxyz123456'; // 32 bytes
	private const NEWEBPAY_HASH_IV     = 'ABCDEFGHIJKLMNOP'; // 16 bytes

	/**
	 * 原本的 $_POST
	 *
	 * @var array
	 */
	private $original_post = [];

	/**
	 * 原本的物流測試模式
	 *
	 * @var mixed
	 */
	private $original_shipping_testmode;

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! class_exists( 'Woomp_Payment_Callback_Guard' ) ) {
			$this->markTestSkipped( 'Woomp_Payment_Callback_Guard 未載入' );
		}

		// 確保回呼 hook 已註冊（init() 重複呼叫不會重複註冊同一 callback）
		if ( class_exists( 'RY_ECPay_Gateway_Response' ) ) {
			RY_ECPay_Gateway_Response::init();
		}
		if ( class_exists( 'RY_NewebPay_Gateway_Response' ) ) {
			RY_NewebPay_Gateway_Response::init();
		}
		if ( class_exists( 'RY_ECPay_Shipping_Response' ) ) {
			RY_ECPay_Shipping_Response::init();
			$this->original_shipping_testmode = RY_ECPay_Shipping::$testmode;
		}

		// 先前若有測試觸發 woocommerce_api_request，die_success()/die_error() 會真的 die()
		$this->reset_do_die( 'RY_ECPay' );
		$this->reset_do_die( 'RY_NewebPay' );

		// 綠界金流：預設正式模式 + 自訂金鑰
		RY_WT::update_option( 'ecpay_gateway_testmode', 'no' );
		RY_WT::update_option( 'ecpay_gateway_MerchantID', self::ECPAY_MERCHANT_ID );
		RY_WT::update_option( 'ecpay_gateway_HashKey', self::ECPAY_HASH_KEY );
		RY_WT::update_option( 'ecpay_gateway_HashIV', self::ECPAY_HASH_IV );
		RY_WT::update_option( 'ecpay_gateway_order_prefix', '' );

		// 綠界物流
		RY_WT::update_option( 'ecpay_shipping_MerchantID', self::ECPAY_SHIPPING_MERCHANT_ID );
		RY_WT::update_option( 'ecpay_shipping_HashKey', self::ECPAY_SHIPPING_HASH_KEY );
		RY_WT::update_option( 'ecpay_shipping_HashIV', self::ECPAY_SHIPPING_HASH_IV );
		RY_WT::update_option( 'ecpay_shipping_order_prefix', '' );

		// 藍新
		RY_WT::update_option( 'newebpay_gateway_MerchantID', self::NEWEBPAY_MERCHANT_ID );
		RY_WT::update_option( 'newebpay_gateway_HashKey', self::NEWEBPAY_HASH_KEY );
		RY_WT::update_option( 'newebpay_gateway_HashIV', self::NEWEBPAY_HASH_IV );
		RY_WT::update_option( 'newebpay_gateway_order_prefix', '' );
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		$_POST = $this->original_post;
		if ( class_exists( 'RY_ECPay_Shipping' ) ) {
			RY_ECPay_Shipping::$testmode = $this->original_shipping_testmode;
		}
		foreach ( [
			'ecpay_gateway_testmode',
			'ecpay_gateway_MerchantID',
			'ecpay_gateway_HashKey',
			'ecpay_gateway_HashIV',
			'ecpay_gateway_order_prefix',
			'ecpay_shipping_MerchantID',
			'ecpay_shipping_HashKey',
			'ecpay_shipping_HashIV',
			'ecpay_shipping_order_prefix',
			'newebpay_gateway_MerchantID',
			'newebpay_gateway_HashKey',
			'newebpay_gateway_HashIV',
			'newebpay_gateway_order_prefix',
		] as $option ) {
			RY_WT::delete_option( $option );
		}
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// 共用工具
	// ------------------------------------------------------------------

	/**
	 * 把 RY_ECPay／RY_NewebPay 的 $do_die 設回 false
	 *
	 * @param string $class 類別
	 */
	private function reset_do_die( string $class ): void {
		if ( ! class_exists( $class ) ) {
			return;
		}
		\Closure::bind(
			static function () {
				self::$do_die = false;
			},
			null,
			$class
		)();
	}

	/**
	 * 訂單備註內容
	 *
	 * @param WC_Order $order 訂單
	 * @return string[]
	 */
	private function get_note_contents( WC_Order $order ): array {
		return array_map(
			static fn( $note ) => (string) $note->content,
			wc_get_order_notes( [ 'order_id' => $order->get_id() ] )
		);
	}

	/**
	 * 重新讀取訂單
	 *
	 * @param WC_Order $order 訂單
	 */
	private function reload( WC_Order $order ): WC_Order {
		return wc_get_order( $order->get_id() );
	}

	// ------------------------------------------------------------------
	// 綠界
	// ------------------------------------------------------------------

	/**
	 * 綠界 .NET 風格 URL encode
	 *
	 * @param string $string 原字串
	 */
	private function ecpay_urlencode( string $string ): string {
		return str_replace(
			[ '%2D', '%2d', '%5F', '%5f', '%2E', '%2e', '%2A', '%2a', '%21', '%28', '%29' ],
			[ '-', '-', '_', '_', '.', '.', '*', '*', '!', '(', ')' ],
			urlencode( $string )
		);
	}

	/**
	 * 依綠界官方演算法產生 CheckMacValue
	 *
	 * @param array  $params 參數（不含 CheckMacValue）
	 * @param string $key    HashKey
	 * @param string $iv     HashIV
	 * @param string $algo   sha256（金流）或 md5（物流）
	 */
	private function ecpay_check_mac( array $params, string $key, string $iv, string $algo = 'sha256' ): string {
		unset( $params['CheckMacValue'] );
		ksort( $params, SORT_STRING | SORT_FLAG_CASE );
		$pairs = [ 'HashKey=' . $key ];
		foreach ( $params as $k => $v ) {
			$pairs[] = $k . '=' . $v;
		}
		$pairs[] = 'HashIV=' . $iv;
		return strtoupper( hash( $algo, strtolower( $this->ecpay_urlencode( implode( '&', $pairs ) ) ) ) );
	}

	/**
	 * 建立綠界訂單
	 *
	 * @param string $payment_method 付款方式
	 * @param string $status         狀態
	 * @param string $testmode       _ry_ecpay_testmode
	 */
	private function create_ecpay_order( string $payment_method = 'ry_ecpay_credit', string $status = 'pending', string $testmode = 'no' ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( $payment_method );
		$order->set_total( 1000 );
		$order->set_status( $status );
		$order->save();
		$order->update_meta_data( '_ecpay_MerchantTradeNo', $order->get_id() . 'TS51234567890' );
		$order->update_meta_data( '_ry_ecpay_testmode', $testmode );
		$order->save();
		return $this->reload( $order );
	}

	/**
	 * 綠界付款結果通知欄位
	 *
	 * @param WC_Order $order    訂單
	 * @param array    $override 覆寫欄位
	 */
	private function ecpay_params( WC_Order $order, array $override = [] ): array {
		return array_merge(
			[
				'MerchantID'           => self::ECPAY_MERCHANT_ID,
				'MerchantTradeNo'      => (string) $order->get_meta( '_ecpay_MerchantTradeNo' ),
				'StoreID'              => '',
				'RtnCode'              => '1',
				'RtnMsg'               => '交易成功',
				'TradeNo'              => '2411131200001234',
				'TradeAmt'             => '1000',
				'PaymentDate'          => '2026/11/13 12:05:00',
				'PaymentType'          => 'Credit_CreditCard',
				'PaymentTypeChargeFee' => '25',
				'TradeDate'            => '2026/11/13 12:00:00',
				'SimulatePaid'         => '0',
				'CustomField1'         => 'A+B 測試', // 字面 + 與空白：綠界簽的是原值
				'CustomField2'         => '',
				'CustomField3'         => '',
				'CustomField4'         => '',
			],
			$override
		);
	}

	/**
	 * 送出綠界金流回呼
	 *
	 * @param array       $params 欄位（不含 CheckMacValue）
	 * @param string|null $mac    指定 CheckMacValue（null 則用正式金鑰正確簽章）
	 * @param string      $key    HashKey
	 * @param string      $iv     HashIV
	 */
	private function send_ecpay_callback( array $params, ?string $mac = null, string $key = self::ECPAY_HASH_KEY, string $iv = self::ECPAY_HASH_IV ): void {
		$params['CheckMacValue'] = $mac ?? $this->ecpay_check_mac( $params, $key, $iv );
		$_POST                   = wp_slash( $params );
		RY_ECPay_Gateway_Response::check_callback();
	}

	/**
	 * 需要綠界金流模組
	 */
	private function require_ecpay(): void {
		if ( ! class_exists( 'RY_ECPay_Gateway_Response' ) ) {
			$this->markTestSkipped( '綠界金流模組未載入' );
		}
	}

	/**
	 * @testdox 綠界：正常付款通知可完成付款並記下綠界交易編號（回歸）
	 */
	public function test_ecpay_valid_callback_completes_payment() {
		$this->require_ecpay();
		$order = $this->create_ecpay_order();

		$this->send_ecpay_callback( $this->ecpay_params( $order ) );

		$order = $this->reload( $order );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( '2411131200001234', $order->get_transaction_id() );
	}

	/**
	 * @testdox 綠界：正式模式金鑰為空時，以空金鑰算出的簽章被拒絕
	 */
	public function test_ecpay_empty_secrets_rejected() {
		$this->require_ecpay();
		RY_WT::update_option( 'ecpay_gateway_HashKey', '' );
		RY_WT::update_option( 'ecpay_gateway_HashIV', '' );
		$order = $this->create_ecpay_order();

		$this->send_ecpay_callback( $this->ecpay_params( $order ), null, '', '' );

		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox 綠界：簽章錯誤被拒絕
	 */
	public function test_ecpay_wrong_signature_rejected() {
		$this->require_ecpay();
		$order = $this->create_ecpay_order();

		$this->send_ecpay_callback( $this->ecpay_params( $order ), null, 'WrongHashKey0000', self::ECPAY_HASH_IV );

		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox 綠界：訂單付款方式不是綠界（例如貨到付款）時拒絕
	 */
	public function test_ecpay_payment_method_mismatch_rejected() {
		$this->require_ecpay();
		$order = $this->create_ecpay_order( 'cod' );

		$this->send_ecpay_callback( $this->ecpay_params( $order ) );

		$order = $this->reload( $order );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 'pending', $order->get_status() );
	}

	/**
	 * @testdox 綠界：wmp_ecpay_ 分期閘道的訂單可正常付款
	 */
	public function test_ecpay_wmp_installment_gateway_accepted() {
		$this->require_ecpay();
		$order = $this->create_ecpay_order( 'wmp_ecpay_credit_installment_3' );

		$this->send_ecpay_callback( $this->ecpay_params( $order ) );

		$this->assertTrue( $this->reload( $order )->is_paid() );
	}

	/**
	 * @testdox 綠界：金額不符時拒絕
	 */
	public function test_ecpay_amount_mismatch_rejected() {
		$this->require_ecpay();
		$order = $this->create_ecpay_order();

		$this->send_ecpay_callback( $this->ecpay_params( $order, [ 'TradeAmt' => '1' ] ) );

		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox 綠界：MerchantID 不符時拒絕
	 */
	public function test_ecpay_merchant_id_mismatch_rejected() {
		$this->require_ecpay();
		$order = $this->create_ecpay_order();

		$this->send_ecpay_callback( $this->ecpay_params( $order, [ 'MerchantID' => '3999999' ] ) );

		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox 綠界：MerchantTradeNo 不是這筆訂單送出的編號時拒絕
	 */
	public function test_ecpay_merchant_trade_no_mismatch_rejected() {
		$this->require_ecpay();
		$order = $this->create_ecpay_order();

		$this->send_ecpay_callback( $this->ecpay_params( $order, [ 'MerchantTradeNo' => $order->get_id() . 'TS99999999999' ] ) );

		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox 綠界：在較早付款頁（歷史編號）完成的付款仍接受，並把編號更新為實際付款的編號
	 */
	public function test_ecpay_history_trade_no_accepted() {
		$this->require_ecpay();
		$order   = $this->create_ecpay_order();
		$old_no  = $order->get_id() . 'TS01111111111';
		$current = (string) $order->get_meta( '_ecpay_MerchantTradeNo' );
		$order->update_meta_data( '_ecpay_MerchantTradeNo_history', [ $old_no, $current ] );
		$order->save();

		$this->send_ecpay_callback( $this->ecpay_params( $order, [ 'MerchantTradeNo' => $old_no ] ) );

		$order = $this->reload( $order );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( $old_no, $order->get_meta( '_ecpay_MerchantTradeNo' ) );
	}

	/**
	 * @testdox 綠界：SimulatePaid=1 只寫備註，不標記付款
	 */
	public function test_ecpay_simulate_paid_does_not_complete_payment() {
		$this->require_ecpay();
		$order = $this->create_ecpay_order();

		$this->send_ecpay_callback( $this->ecpay_params( $order, [ 'SimulatePaid' => '1' ] ) );

		$order = $this->reload( $order );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertNotEmpty(
			array_filter( $this->get_note_contents( $order ), static fn( $c ) => false !== strpos( $c, 'SimulatePaid=1' ) )
		);
	}

	/**
	 * @testdox 綠界：已取消訂單收到舊成功通知（重放）不改狀態，只寫備註
	 */
	public function test_ecpay_cancelled_order_replay_not_reopened() {
		$this->require_ecpay();
		$order = $this->create_ecpay_order( 'ry_ecpay_credit', 'cancelled' );

		$this->send_ecpay_callback( $this->ecpay_params( $order ) );

		$order = $this->reload( $order );
		$this->assertSame( 'cancelled', $order->get_status() );
		$this->assertSame( '', (string) $order->get_transaction_id() );
		$this->assertNotEmpty(
			array_filter( $this->get_note_contents( $order ), static fn( $c ) => false !== strpos( $c, '未變更訂單狀態' ) )
		);
	}

	/**
	 * @testdox 綠界：已付款訂單的交易編號不會被後來的回呼覆寫
	 */
	public function test_ecpay_paid_order_transaction_id_not_overwritten() {
		$this->require_ecpay();
		$order = $this->create_ecpay_order();
		$order->payment_complete( 'ORIGINALTRADENO' );

		$this->send_ecpay_callback( $this->ecpay_params( $order, [ 'TradeNo' => 'OTHERTRADENO0001' ] ) );

		$this->assertSame( 'ORIGINALTRADENO', $this->reload( $order )->get_transaction_id() );
	}

	/**
	 * @testdox 綠界：已付款訂單收到付款失敗通知不會被降級
	 */
	public function test_ecpay_failed_callback_does_not_downgrade_paid_order() {
		$this->require_ecpay();
		$order = $this->create_ecpay_order();
		$order->payment_complete( 'ORIGINALTRADENO' );
		$status = $this->reload( $order )->get_status();

		$this->send_ecpay_callback( $this->ecpay_params( $order, [ 'RtnCode' => '10100058', 'RtnMsg' => '付款失敗' ] ) );

		$this->assertSame( $status, $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox 綠界：測試模式（公開金鑰）不接受「非測試模式下送出付款」的訂單
	 */
	public function test_ecpay_testmode_rejects_order_not_created_in_testmode() {
		$this->require_ecpay();
		RY_WT::update_option( 'ecpay_gateway_testmode', 'yes' );
		$order = $this->create_ecpay_order( 'ry_ecpay_credit', 'pending', 'no' );

		$this->send_ecpay_callback(
			$this->ecpay_params( $order, [ 'MerchantID' => self::ECPAY_TEST_MERCHANT_ID ] ),
			null,
			self::ECPAY_TEST_HASH_KEY,
			self::ECPAY_TEST_HASH_IV
		);

		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox 綠界：測試模式下送出付款的訂單，測試回呼仍可付款
	 */
	public function test_ecpay_testmode_accepts_order_created_in_testmode() {
		$this->require_ecpay();
		RY_WT::update_option( 'ecpay_gateway_testmode', 'yes' );
		$order = $this->create_ecpay_order( 'ry_ecpay_credit', 'pending', 'yes' );

		$this->send_ecpay_callback(
			$this->ecpay_params( $order, [ 'MerchantID' => self::ECPAY_TEST_MERCHANT_ID ] ),
			null,
			self::ECPAY_TEST_HASH_KEY,
			self::ECPAY_TEST_HASH_IV
		);

		$this->assertTrue( $this->reload( $order )->is_paid() );
	}

	// ------------------------------------------------------------------
	// 綠界物流
	// ------------------------------------------------------------------

	/**
	 * 需要綠界物流模組
	 */
	private function require_ecpay_shipping(): void {
		if ( ! class_exists( 'RY_ECPay_Shipping_Response' ) || ! class_exists( 'RY_ECPay_Shipping' ) ) {
			$this->markTestSkipped( '綠界物流模組未載入' );
		}
		RY_ECPay_Shipping::$testmode = false;
	}

	/**
	 * 送出綠界物流貨態回呼（黑貓 3003 配達完成 → completed）
	 *
	 * @param WC_Order $order        訂單
	 * @param string   $logistics_id 綠界物流編號
	 * @param string   $key          HashKey
	 * @param string   $iv           HashIV
	 */
	private function send_shipping_callback( WC_Order $order, string $logistics_id, string $key = self::ECPAY_SHIPPING_HASH_KEY, string $iv = self::ECPAY_SHIPPING_HASH_IV ): void {
		$params                  = [
			'MerchantID'        => self::ECPAY_SHIPPING_MERCHANT_ID,
			'MerchantTradeNo'   => $order->get_id() . 'TS71234567890',
			'RtnCode'           => '3003',
			'RtnMsg'            => '配達完成',
			'AllPayLogisticsID' => $logistics_id,
			'LogisticsType'     => 'HOME',
			'LogisticsSubType'  => 'TCAT',
			'GoodsAmount'       => '1000',
			'UpdateStatusDate'  => '2026/11/13 12:00:00',
			'ReceiverName'      => '王 小明',
			'ReceiverPhone'     => '',
			'ReceiverCellPhone' => '0912345678',
			'ReceiverEmail'     => 'a+b@example.com',
			'ReceiverAddress'   => '台北市',
			'CVSPaymentNo'      => '',
			'CVSValidationNo'   => '',
			'BookingNote'       => '',
		];
		$params['CheckMacValue'] = $this->ecpay_check_mac( $params, $key, $iv, 'md5' );
		$_POST                   = wp_slash( $params );
		RY_ECPay_Shipping_Response::check_shipping_callback();
	}

	/**
	 * 建立有綠界物流單的訂單
	 *
	 * @param string $logistics_id 綠界物流編號（空字串代表沒有物流單）
	 */
	private function create_shipping_order( string $logistics_id ): WC_Order {
		$order = wc_create_order();
		$order->set_total( 1000 );
		$order->set_status( 'processing' );
		if ( '' !== $logistics_id ) {
			$order->update_meta_data(
				'_ecpay_shipping_info',
				[
					$logistics_id => [
						'ID'     => $logistics_id,
						'status' => 300,
					],
				]
			);
		}
		$order->save();
		return $this->reload( $order );
	}

	/**
	 * @testdox 綠界物流：訂單沒有該綠界物流單時，偽造的配達完成回呼不改訂單狀態
	 */
	public function test_ecpay_shipping_unknown_logistics_id_rejected() {
		$this->require_ecpay_shipping();
		$order = $this->create_shipping_order( '' );

		$this->send_shipping_callback( $order, '1234567' );

		$this->assertSame( 'processing', $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox 綠界物流：金鑰為空時拒絕
	 */
	public function test_ecpay_shipping_empty_secrets_rejected() {
		$this->require_ecpay_shipping();
		RY_WT::update_option( 'ecpay_shipping_HashKey', '' );
		RY_WT::update_option( 'ecpay_shipping_HashIV', '' );
		$order = $this->create_shipping_order( '1234567' );

		$this->send_shipping_callback( $order, '1234567', '', '' );

		$this->assertSame( 'processing', $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox 綠界物流：簽章錯誤時拒絕
	 */
	public function test_ecpay_shipping_wrong_signature_rejected() {
		$this->require_ecpay_shipping();
		$order = $this->create_shipping_order( '1234567' );

		$this->send_shipping_callback( $order, '1234567', 'WrongHashKey0000' );

		$this->assertSame( 'processing', $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox 綠界物流：訂單上的綠界物流單收到配達完成，仍會改成已完成（回歸）
	 */
	public function test_ecpay_shipping_valid_callback_completes_order() {
		$this->require_ecpay_shipping();
		$order = $this->create_shipping_order( '1234567' );

		$this->send_shipping_callback( $order, '1234567' );

		$this->assertSame( 'completed', $this->reload( $order )->get_status() );
	}

	// ------------------------------------------------------------------
	// 藍新
	// ------------------------------------------------------------------

	/**
	 * 需要藍新模組
	 */
	private function require_newebpay(): void {
		if ( ! class_exists( 'RY_NewebPay_Gateway_Response' ) ) {
			$this->markTestSkipped( '藍新金流模組未載入' );
		}
	}

	/**
	 * 建立藍新訂單
	 *
	 * @param string $payment_method 付款方式
	 * @param string $status         狀態
	 */
	private function create_newebpay_order( string $payment_method = 'ry_newebpay_credit', string $status = 'pending' ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( $payment_method );
		$order->set_total( 1000 );
		$order->set_status( $status );
		$order->save();
		$order->update_meta_data( '_newebpay_MerchantOrderNo', $order->get_id() . 'TS51234567890' );
		$order->save();
		return $this->reload( $order );
	}

	/**
	 * 依藍新 MPG 規格加密 TradeInfo（AES-256-CBC + PKCS#7，hex）
	 *
	 * @param string $plain 明文
	 * @param string $key   HashKey
	 * @param string $iv    HashIV
	 */
	private function newebpay_encrypt( string $plain, string $key, string $iv ): string {
		$pad    = 32 - ( strlen( $plain ) % 32 );
		$plain .= str_repeat( chr( $pad ), $pad );
		return bin2hex( openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv ) );
	}

	/**
	 * 送出藍新 NotifyURL 回呼
	 *
	 * @param WC_Order    $order           訂單
	 * @param array       $result_override 覆寫 Result 欄位
	 * @param string      $status          Status
	 * @param string      $key             HashKey
	 * @param string      $iv              HashIV
	 * @param string|null $trade_sha       指定 TradeSha（null 則正確計算）
	 */
	private function send_newebpay_callback( WC_Order $order, array $result_override = [], string $status = 'SUCCESS', string $key = self::NEWEBPAY_HASH_KEY, string $iv = self::NEWEBPAY_HASH_IV, ?string $trade_sha = null ): void {
		$result = array_merge(
			[
				'MerchantID'      => self::NEWEBPAY_MERCHANT_ID,
				'Amt'             => 1000,
				'TradeNo'         => '24111312000012345',
				'MerchantOrderNo' => (string) $order->get_meta( '_newebpay_MerchantOrderNo' ),
				'PaymentType'     => 'CREDIT',
				'RespondType'     => 'JSON',
				'PayTime'         => '2026-11-13 12:05:00',
				'IP'              => '127.0.0.1',
				'EscrowBank'      => 'HNCB',
			],
			$result_override
		);
		$json = wp_json_encode(
			[
				'Status'  => $status,
				'Message' => 'SUCCESS' === $status ? '授權成功' : '授權失敗 + 拒絕交易',
				'Result'  => $result,
			],
			JSON_UNESCAPED_UNICODE
		);

		$trade_info = '' === $key ? bin2hex( $json ) : $this->newebpay_encrypt( $json, $key, $iv );
		$_POST      = wp_slash(
			[
				'Status'     => $status,
				'MerchantID' => self::NEWEBPAY_MERCHANT_ID,
				'Version'    => '1.5',
				'TradeInfo'  => $trade_info,
				'TradeSha'   => $trade_sha ?? strtoupper( hash( 'sha256', 'HashKey=' . $key . '&' . $trade_info . '&HashIV=' . $iv ) ),
			]
		);
		RY_NewebPay_Gateway_Response::check_callback();
	}

	/**
	 * @testdox 藍新：正常付款通知可完成付款，且不會被強制改成 processing（回歸）
	 */
	public function test_newebpay_valid_callback_completes_payment() {
		$this->require_newebpay();
		$order = $this->create_newebpay_order();

		$this->send_newebpay_callback( $order );

		$order = $this->reload( $order );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( '24111312000012345', $order->get_transaction_id() );
		// 沒有需要出貨的商品時 payment_complete() 會直接 completed；以前會被強制拉回 processing
		$this->assertSame( $order->needs_processing() ? 'processing' : 'completed', $order->get_status() );
	}

	/**
	 * @testdox 藍新：金鑰為空時拒絕（不進入 AES 解密）
	 */
	public function test_newebpay_empty_secrets_rejected() {
		$this->require_newebpay();
		RY_WT::update_option( 'newebpay_gateway_HashKey', '' );
		RY_WT::update_option( 'newebpay_gateway_HashIV', '' );
		$order = $this->create_newebpay_order();

		$this->send_newebpay_callback( $order, [], 'SUCCESS', '', '' );

		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox 藍新：TradeSha 錯誤時拒絕
	 */
	public function test_newebpay_wrong_trade_sha_rejected() {
		$this->require_newebpay();
		$order = $this->create_newebpay_order();

		$this->send_newebpay_callback( $order, [], 'SUCCESS', self::NEWEBPAY_HASH_KEY, self::NEWEBPAY_HASH_IV, str_repeat( 'A', 64 ) );

		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox 藍新：訂單付款方式不是藍新時拒絕
	 */
	public function test_newebpay_payment_method_mismatch_rejected() {
		$this->require_newebpay();
		$order = $this->create_newebpay_order( 'bacs' );

		$this->send_newebpay_callback( $order );

		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox 藍新：金額不符時拒絕
	 */
	public function test_newebpay_amount_mismatch_rejected() {
		$this->require_newebpay();
		$order = $this->create_newebpay_order();

		$this->send_newebpay_callback( $order, [ 'Amt' => 1 ] );

		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox 藍新：MerchantID 不符時拒絕
	 */
	public function test_newebpay_merchant_id_mismatch_rejected() {
		$this->require_newebpay();
		$order = $this->create_newebpay_order();

		$this->send_newebpay_callback( $order, [ 'MerchantID' => 'MS0000000' ] );

		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox 藍新：MerchantOrderNo 不是這筆訂單送出的編號時拒絕
	 */
	public function test_newebpay_merchant_order_no_mismatch_rejected() {
		$this->require_newebpay();
		$order = $this->create_newebpay_order();

		$this->send_newebpay_callback( $order, [ 'MerchantOrderNo' => $order->get_id() . 'TS99999999999' ] );

		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox 藍新：已退款訂單收到舊成功通知不會被拉回 processing
	 */
	public function test_newebpay_refunded_order_not_pulled_back() {
		$this->require_newebpay();
		$order = $this->create_newebpay_order( 'ry_newebpay_credit', 'refunded' );

		$this->send_newebpay_callback( $order );

		$this->assertSame( 'refunded', $this->reload( $order )->get_status() );
	}

	/**
	 * @testdox 藍新：已付款訂單收到非 SUCCESS 通知不會被改成 failed
	 */
	public function test_newebpay_failure_does_not_downgrade_paid_order() {
		$this->require_newebpay();
		$order = $this->create_newebpay_order( 'ry_newebpay_credit', 'processing' );
		$order->set_transaction_id( 'ORIGINALTRADENO' );
		$order->set_date_paid( time() );
		$order->save();

		$this->send_newebpay_callback( $order, [ 'TradeNo' => 'OTHERTRADENO0001' ], 'MPG03009' );

		$order = $this->reload( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 'ORIGINALTRADENO', $order->get_transaction_id() );
	}

	/**
	 * @testdox 藍新：未付款訂單收到非 SUCCESS 通知仍會改成 failed（回歸）
	 */
	public function test_newebpay_failure_marks_pending_order_failed() {
		$this->require_newebpay();
		$order = $this->create_newebpay_order();

		$this->send_newebpay_callback( $order, [], 'MPG03009' );

		$this->assertSame( 'failed', $this->reload( $order )->get_status() );
	}
}

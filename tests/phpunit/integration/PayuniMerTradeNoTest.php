<?php
/**
 * PayUni V2 MerTradeNo 遞增修復迴歸測試（GitHub issue #131）
 *
 * 背景：統一金流 PAYUNi V2（includes/payuni/src/）的商店訂單編號
 * MerTradeNo = {order_id}{suffix}，suffix 來自訂單 meta _payuni_order_suffix。
 * 官方規格：MerTradeNo 10 分鐘內不可重複。
 *
 * Bug A：suffix 原本只在付款成功後才 +1（Response::card_response()），失敗時
 * 完全不動，顧客重試同一張訂單就會沿用同一組編號，撞
 * CREDIT04001「已存在相同商店訂單編號」。
 * 修正：改為「送出即取號」（Request::burn_order_suffix()），不論成功或失敗，
 * 這組編號都不會再被用第二次。
 *
 * Bug B：Request::build_request() 在 API 同步回錯誤時完全不寫訂單備註，
 * 後台只剩一張沒有任何線索的待付款單。
 * 修正：失敗分支呼叫 Response::format_failure_note() 寫入訂單備註，
 * 該函式會排除 card_hash（CreditHash 是可續扣憑證，不可外洩到備註）。
 *
 * 執行指令：
 * docker exec 8ff7dd3fcb4c83a6b416cc98db6c6d51-tests-cli-1 sh -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --filter PayuniMerTradeNo'
 *
 * @package Woomp\Tests\Integration
 */

/**
 * PayUni V2 MerTradeNo 遞增修復迴歸測試類別
 *
 * @covers \PAYUNI\Gateways\Request::burn_order_suffix
 * @covers \PAYUNI\Gateways\Request::get_transaction_args
 * @covers \PAYUNI\Gateways\Request::build_subscription_request
 * @covers \PAYUNI\Gateways\Request::build_request
 * @covers \PAYUNI\Gateways\Response::format_failure_note
 * @group gateway
 * @group payuni
 * @group regression
 */
final class PayuniMerTradeNoTest extends WP_UnitTestCase {

	/**
	 * 測試過程中建立的訂單 ID，供 tearDown 清理。
	 *
	 * @var int[]
	 */
	private $order_ids = array();

	/**
	 * intercept_subscription_request() 攔截到的請求 body（含 EncryptInfo），供斷言用。
	 *
	 * @var array|null
	 */
	private $captured_subscription_body = null;

	/**
	 * 供 pre_http_request 攔截器組出假回應時，用來取得訂單 ID 的參照。
	 *
	 * @var \WC_Order|null
	 */
	private $http_mock_order = null;

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\PAYUNI\Gateways\Request' )
			|| ! class_exists( '\PAYUNI\Gateways\Credit' )
			|| ! class_exists( '\PAYUNI\Gateways\CreditSubscription' ) ) {
			$this->markTestSkipped( 'PayUni v1 Gateway 類別不存在，跳過 MerTradeNo 測試' );
		}

		// 設定 PayUni v1 測試憑證，AES-256-GCM 加解密需要這些選項才能運作。
		update_option( 'payuni_payment_testmode', 'yes' );
		update_option( 'payuni_payment_merchant_no_test', 'TEST_MERCHANT' );
		update_option( 'payuni_payment_hash_key_test', 'TEST_HASH_KEY_1234567890123456' );
		update_option( 'payuni_payment_hash_iv_test', 'TEST_HASH_IV_123456' );
		update_option( 'payuni_3d_auth', 'no' );

		$this->order_ids                  = array();
		$this->captured_subscription_body = null;
		$this->http_mock_order            = null;
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );

		foreach ( $this->order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$order->delete( true );
			}
		}

		parent::tearDown();
	}

	// ========================================================================
	// Helpers
	// ========================================================================

	/**
	 * 建立測試用訂單。
	 *
	 * @param string $payment_method 付款方式 ID。
	 *
	 * @return \WC_Order
	 */
	private function create_test_order( string $payment_method = 'payuni-credit' ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( $payment_method );
		$order->set_billing_email( 'test@example.com' );
		$order->set_total( 1000 );
		$order->save();

		$this->order_ids[] = $order->get_id();

		return $order;
	}

	/**
	 * 清快取後從資料庫重新讀取訂單。
	 *
	 * 專案既有的踩坑教訓：訂單 meta 寫入後若不清快取，同一次請求內的
	 * wc_get_order() 可能仍讀到寫入前的快取值，造成偽陰性。
	 *
	 * @param int $order_id 訂單 ID。
	 *
	 * @return \WC_Order
	 */
	private function reload_order( int $order_id ): WC_Order {
		wp_cache_flush();

		return wc_get_order( $order_id );
	}

	/**
	 * 從 get_transaction_args() / build_request() 送出的 body（含 EncryptInfo）解密出 MerTradeNo。
	 *
	 * @param array $body 含 EncryptInfo 的參數陣列。
	 *
	 * @return string
	 */
	private function decrypt_mer_trade_no_from_body( array $body ): string {
		$data = \PAYUNI\APIs\Payment::decrypt( $body['EncryptInfo'] ?? '' );

		return (string) ( $data['MerTradeNo'] ?? '' );
	}

	/**
	 * pre_http_request 攔截器：讓 build_subscription_request() 打出的 API 呼叫
	 * 回傳一組合成的「授權成功」加密回應，同時把送出的 body 記錄下來供斷言。
	 *
	 * @param mixed  $pre  短路值。
	 * @param array  $args 請求參數。
	 * @param string $url  請求網址。
	 *
	 * @return mixed
	 */
	public function intercept_subscription_request( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'api/credit' ) ) {
			return $pre;
		}

		$this->captured_subscription_body = $args['body'] ?? array();

		$data = array(
			'Status'       => 'SUCCESS',
			'Message'      => '授權成功',
			'MerTradeNo'   => (string) $this->http_mock_order->get_id(),
			'TradeNo'      => 'FAKE_TRADE_NO',
			'CardBank'     => '004',
			'AuthBankName' => '台灣銀行',
			'Card4No'      => '4242',
			'CreditHash'   => 'FAKE_CREDIT_HASH',
			'CreditLife'   => '0630',
			'CardInst'     => '',
			'EachAmt'      => '',
			'FirstAmt'     => '',
			'TradeAmt'     => '500', // 刻意不等於 '5'，避免誤觸 5 元退刷（is_hash_request）分支。
			'AuthType'     => '0',
		);

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'EncryptInfo' => \PAYUNI\APIs\Payment::encrypt( $data ) ) ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * pre_http_request 攔截器：讓 build_request() 打出的 API 呼叫回傳一組
	 * 「已存在相同商店訂單編號」的失敗加密回應。
	 *
	 * @param mixed  $pre  短路值。
	 * @param array  $args 請求參數。
	 * @param string $url  請求網址。
	 *
	 * @return mixed
	 */
	public function intercept_build_request_failure( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'api/credit' ) ) {
			return $pre;
		}

		$data = array(
			'Status'     => 'CREDIT04001',
			'Message'    => '已存在相同商店訂單編號',
			'MerTradeNo' => (string) $this->http_mock_order->get_id(),
			'CreditHash' => 'SHOULD_NOT_APPEAR_IN_NOTE',
		);

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'EncryptInfo' => \PAYUNI\APIs\Payment::encrypt( $data ) ) ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	// ========================================================================
	// Rule: 首次送出用裸編號，且 suffix 送出當下立即落地（issue #131 核心回歸）
	// ========================================================================

	/**
	 * 測試首次呼叫 get_transaction_args() 送出裸訂單編號，且 suffix 立即落地為 1。
	 *
	 * 防止的迴歸：修復前 suffix 只在付款成功後才 +1，若這裡沒有立即落地，
	 * 失敗重試時就會沿用同一組編號重送。
	 *
	 * @testdox 首次送出應使用裸訂單編號，且 suffix 送出當下立即落地為 1
	 */
	public function test_first_send_uses_bare_order_id_and_burns_suffix_to_one(): void {
		$order   = $this->create_test_order();
		$request = new \PAYUNI\Gateways\Request( new \PAYUNI\Gateways\Credit() );

		$parameter    = $request->get_transaction_args( $order, null );
		$mer_trade_no = $this->decrypt_mer_trade_no_from_body( $parameter );

		$this->assertSame(
			(string) $order->get_id(),
			$mer_trade_no,
			'首次送出應使用裸訂單編號，不帶任何尾碼'
		);

		$reloaded = $this->reload_order( $order->get_id() );
		$this->assertSame(
			1,
			(int) $reloaded->get_meta( '_payuni_order_suffix' ),
			'送出當下應立即遞增並落地 suffix 為 1，不應等到付款成功才遞增'
		);
	}

	/**
	 * 測試對同一張訂單第二次送出時使用 -1 尾碼，suffix 落地為 2。
	 *
	 * 這是本次修復的核心迴歸測試：修復前（suffix 只在成功後才 +1）第二次
	 * 送出仍會是裸編號，重送同一張失敗訂單就會 100% 撞
	 * CREDIT04001「已存在相同商店訂單編號」。
	 *
	 * @testdox 對同一張訂單第二次送出應使用 -1 尾碼，且 suffix 落地為 2
	 */
	public function test_second_send_uses_dash_one_suffix_and_burns_suffix_to_two(): void {
		$order   = $this->create_test_order();
		$request = new \PAYUNI\Gateways\Request( new \PAYUNI\Gateways\Credit() );

		// 第一次送出：消耗掉裸編號。
		$request->get_transaction_args( $order, null );
		$reloaded = $this->reload_order( $order->get_id() );

		// 第二次送出（模擬顧客重試同一張訂單）。
		$parameter    = $request->get_transaction_args( $reloaded, null );
		$mer_trade_no = $this->decrypt_mer_trade_no_from_body( $parameter );

		$this->assertSame(
			$reloaded->get_id() . '-1',
			$mer_trade_no,
			'第二次送出應使用 -1 尾碼；修復前這裡仍會是裸編號並撞 CREDIT04001'
		);

		$reloaded_again = $this->reload_order( $order->get_id() );
		$this->assertSame(
			2,
			(int) $reloaded_again->get_meta( '_payuni_order_suffix' ),
			'第二次送出後 suffix 應落地為 2'
		);
	}

	// ========================================================================
	// Rule: 續扣路徑（build_subscription_request）同樣走取號即燒毀邏輯
	// ========================================================================

	/**
	 * 測試定期定額續扣（build_subscription_request）也會依序遞增 suffix。
	 *
	 * 防止的迴歸：burn_order_suffix() 若只在 get_transaction_args() 生效、
	 * 續扣路徑漏接，續扣重試一樣會撞商店訂單編號重複。
	 *
	 * @testdox 定期定額續扣路徑（build_subscription_request）也會依序遞增 MerTradeNo 尾碼
	 */
	public function test_subscription_request_also_burns_suffix_progressively(): void {
		$order = $this->create_test_order( 'payuni-credit-subscription' );

		$this->http_mock_order = $order;
		add_filter( 'pre_http_request', array( $this, 'intercept_subscription_request' ), 10, 3 );

		$request = new \PAYUNI\Gateways\Request( new \PAYUNI\Gateways\CreditSubscription() );

		// 第一次續扣送出。
		$request->build_subscription_request( 500.0, $order );
		$first_mer_trade_no = $this->decrypt_mer_trade_no_from_body( $this->captured_subscription_body );

		$this->assertSame(
			(string) $order->get_id(),
			$first_mer_trade_no,
			'首次續扣送出應使用裸訂單編號'
		);

		$reloaded = $this->reload_order( $order->get_id() );
		$this->assertSame(
			1,
			(int) $reloaded->get_meta( '_payuni_order_suffix' ),
			'首次續扣送出後 suffix 應立即落地為 1'
		);

		// 第二次續扣送出（模擬下一期扣款，或重試）。
		$this->http_mock_order = $reloaded;
		$request->build_subscription_request( 500.0, $reloaded );
		$second_mer_trade_no = $this->decrypt_mer_trade_no_from_body( $this->captured_subscription_body );

		$this->assertSame(
			$reloaded->get_id() . '-1',
			$second_mer_trade_no,
			'第二次續扣送出應使用 -1 尾碼，續扣路徑必須與一般交易共用同一套取號即燒毀邏輯'
		);

		$reloaded_again = $this->reload_order( $order->get_id() );
		$this->assertSame(
			2,
			(int) $reloaded_again->get_meta( '_payuni_order_suffix' ),
			'第二次續扣送出後 suffix 應落地為 2'
		);
	}

	// ========================================================================
	// Rule: format_failure_note() 排除 card_hash
	// ========================================================================

	/**
	 * 測試 Response::format_failure_note() 會排除 card_hash，但保留其他診斷欄位。
	 *
	 * 防止的迴歸：CreditHash 是可直接拿來續扣的憑證，不應該外洩到後台任何人
	 * 都看得到的訂單備註裡；同時備註仍要保留狀態碼等診斷資訊，不能矯枉過正
	 * 把整包資料都拿掉。
	 *
	 * @testdox format_failure_note() 應排除 card_hash，但保留狀態碼與卡號末四碼等診斷欄位
	 */
	public function test_format_failure_note_excludes_card_hash(): void {
		$formatted_decrypted_data = array(
			'status'            => 'CREDIT04001',
			'order_id'          => 123,
			'user_id'           => 0,
			'message'           => '已存在相同商店訂單編號',
			'trade_no'          => '',
			'card_bank'         => '004',
			'card_bank_name'    => '台灣銀行',
			'card_4no'          => '4242',
			'card_hash'         => 'SECRET_CREDIT_HASH_VALUE',
			'card_expiry_month' => '06',
			'card_expiry_year'  => '2030',
			'card_inst'         => '',
			'each_amt'          => '',
			'first_amt'         => '',
			'is_3d_auth'        => false,
		);

		$note = \PAYUNI\Gateways\Response::format_failure_note( $formatted_decrypted_data );

		$this->assertStringNotContainsString(
			'SECRET_CREDIT_HASH_VALUE',
			$note,
			'card_hash（CreditHash）不應出現在失敗備註中，那是可續扣的憑證而非診斷資訊'
		);
		$this->assertStringContainsString( '統一金流交易失敗', $note, '備註應有明確標題' );
		$this->assertStringContainsString( 'CREDIT04001', $note, '備註應保留狀態碼供事後追查' );
		$this->assertStringContainsString( '4242', $note, '備註應保留其他診斷欄位（如卡號末四碼）' );
	}

	// ========================================================================
	// Rule: woomp_copy_order() 複製出的新訂單，MerTradeNo 仍然唯一
	// ========================================================================

	/**
	 * 測試 woomp_copy_order() 複製出的新訂單不繼承 _payuni_order_suffix，
	 * 且與原訂單的 MerTradeNo 必然不同。
	 *
	 * 本測試原本斷言的是「suffix 會被完整複製」，理由是「繼承也不會撞號，
	 * 所以不需要額外處理」。issue #127 的 meta blocklist 上線後改為不繼承，
	 * 原因不是撞號風險（兩種做法都因訂單 ID 不同而唯一），而是語意：
	 * _payuni_order_suffix 自 3.5.18（issue #131）起代表「這張訂單送出過幾次」，
	 * 而複製出來的新訂單一次都還沒送出，繼承 3 等於宣稱它送過 3 次。
	 * 連續複製（A→B→C）時這個數字還會一路虛長，事後看 log 會誤導。
	 *
	 * 唯一性（本測試真正要守的結論）在兩種做法下都成立，故一併保留斷言。
	 *
	 * @testdox 複製訂單不繼承 _payuni_order_suffix，MerTradeNo 仍因訂單 ID 不同而唯一
	 */
	public function test_copied_order_produces_unique_mer_trade_no_despite_inherited_suffix(): void {
		if ( ! function_exists( 'woomp_copy_order' ) ) {
			$this->markTestSkipped( 'woomp_copy_order() 函式不存在，跳過測試' );
		}

		$order = $this->create_test_order();
		$order->update_meta_data( '_payuni_order_suffix', 3 ); // 模擬原單已重試過 3 次。
		$order->save();

		$new_order_id       = woomp_copy_order( $order );
		$this->order_ids[] = $new_order_id;

		$reloaded_original = $this->reload_order( $order->get_id() );
		$copied_order       = $this->reload_order( $new_order_id );

		$this->assertSame(
			0,
			(int) $copied_order->get_meta( '_payuni_order_suffix' ),
			'woomp_copy_order() 應排除 _payuni_order_suffix，複製單從 0 重新計數（issue #127 meta blocklist）'
		);

		$request = new \PAYUNI\Gateways\Request( new \PAYUNI\Gateways\Credit() );

		$original_parameter = $request->get_transaction_args( $reloaded_original, null );
		$copied_parameter    = $request->get_transaction_args( $copied_order, null );

		$original_mer_trade_no = $this->decrypt_mer_trade_no_from_body( $original_parameter );
		$copied_mer_trade_no    = $this->decrypt_mer_trade_no_from_body( $copied_parameter );

		$this->assertNotSame(
			$original_mer_trade_no,
			$copied_mer_trade_no,
			'兩張訂單的 MerTradeNo 應因訂單 ID 不同而唯一'
		);
		$this->assertStringStartsWith(
			(string) $reloaded_original->get_id() . '-3',
			$original_mer_trade_no,
			'原訂單已送出過 3 次，MerTradeNo 應為「原訂單 ID-3」'
		);
		$this->assertStringStartsWith(
			(string) $copied_order->get_id(),
			$copied_mer_trade_no,
			'複製單 suffix 歸 0，MerTradeNo 應為「複製單 ID」且不帶後綴'
		);
		$this->assertStringNotContainsString(
			'-',
			$copied_mer_trade_no,
			'複製單第一次送出不應帶 -n 後綴（suffix 未繼承，從 0 起算）'
		);
	}

	// ========================================================================
	// Rule: build_request() API 同步回錯誤時應寫入訂單備註（Bug B 回歸）
	// ========================================================================

	/**
	 * 測試 build_request() 在 API 階段同步回錯誤時會寫入含錯誤碼的訂單備註。
	 *
	 * 防止的迴歸（Bug B）：修復前這個分支完全不寫訂單備註，後台只剩一張
	 * 沒有任何線索的待付款單，事後追查只能回頭問顧客。
	 *
	 * @testdox build_request() API 同步回錯誤時應寫入包含錯誤碼與錯誤訊息的訂單備註
	 */
	public function test_build_request_failure_writes_order_note_with_status_code(): void {
		$order = $this->create_test_order();

		$this->http_mock_order = $order;
		add_filter( 'pre_http_request', array( $this, 'intercept_build_request_failure' ), 10, 3 );

		$request = new \PAYUNI\Gateways\Request( new \PAYUNI\Gateways\Credit() );
		$result  = $request->build_request( $order, null );

		$this->assertSame( 'failed', $result['result'], 'API 同步回錯誤時應回傳 failed' );
		$this->assertSame( 'CREDIT04001', $result['status_code'], '應回傳統一金流原始狀態碼' );

		$notes         = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$note_contents = implode( "\n", wp_list_pluck( $notes, 'content' ) );

		$this->assertStringContainsString(
			'CREDIT04001',
			$note_contents,
			'失敗時應寫入包含錯誤碼的訂單備註，避免後台留下沒有任何線索的待付款單'
		);
		$this->assertStringContainsString(
			'已存在相同商店訂單編號',
			$note_contents,
			'備註應保留 PayUni 回傳的錯誤訊息'
		);
		$this->assertStringNotContainsString(
			'SHOULD_NOT_APPEAR_IN_NOTE',
			$note_contents,
			'card_hash 同樣不應出現在 build_request() 失敗分支寫入的備註中'
		);
	}
}

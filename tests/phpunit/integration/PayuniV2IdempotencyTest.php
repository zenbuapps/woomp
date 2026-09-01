<?php
/**
 * PayUni V2 信用卡付款完成冪等保護整合測試（issue #127 PR B）
 *
 * 背景：V2 信用卡開 3D 驗證時，補上 NotifyURL 後同一筆交易會有「幕後通知」與
 * 「瀏覽器導回」兩個請求進到 Response::card_response()。沒有冪等保護的話，
 * payment_complete() 會跑兩次——重複開發票、重複寄信、重複扣庫存、
 * _payuni_order_suffix 加兩次、建兩張重複的付款 Token。
 *
 * 本檔驗證：
 * - complete_paid_order() 是唯一寫入路徑，第二次呼叫必須被擋下
 * - 冪等判斷用「值為自己 order_id 的戳記」，不用「meta 有沒有值」——後者會被
 *   woomp_copy_order() / wcs_copy_order_meta() 整包複製的 meta 誤導
 * - PaidLock 以 wp_options UNIQUE 索引實作跨行程互斥
 * - 三種進入情境（sync / return / notify）各自的收尾行為
 *
 * 執行指令：
 * docker exec <woomp-tests-cli> sh -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --filter PayuniV2Idempotency'
 *
 * @package Woomp\Tests\Integration
 */

use PAYUNI\Gateways\PaidLock;
use PAYUNI\Gateways\Response;
use Payuni\APIs\Payment;

/**
 * 用來中止 finish() 的 exit：掛在 woomp_payuni_v2_card_response_finish 上，
 * 在 exit 之前把控制權丟回測試。
 */
final class PayuniV2FinishInterrupt extends \RuntimeException {

	/**
	 * finish() 收到的 context
	 *
	 * @var string
	 */
	public string $context;

	/**
	 * finish() 收到的訂單
	 *
	 * @var \WC_Order|null
	 */
	public ?\WC_Order $order;

	/**
	 * finish() 收到的導向網址
	 *
	 * @var string
	 */
	public string $redirect;

	/**
	 * 建構子
	 *
	 * @param string         $context  進入情境。
	 * @param \WC_Order|null $order    訂單物件。
	 * @param string         $redirect 導向網址。
	 */
	public function __construct( string $context, ?\WC_Order $order, string $redirect ) {
		parent::__construct( 'finish interrupted' );
		$this->context  = $context;
		$this->order    = $order;
		$this->redirect = $redirect;
	}
}

/**
 * PayUni V2 冪等保護測試類別
 *
 * @covers \PAYUNI\Gateways\Response::card_response
 * @covers \PAYUNI\Gateways\Response::complete_paid_order
 * @covers \PAYUNI\Gateways\Response::is_payuni_completed
 * @covers \PAYUNI\Gateways\PaidLock
 * @group gateway
 * @group payuni
 * @group regression
 */
final class PayuniV2IdempotencyTest extends WP_UnitTestCase {

	/**
	 * 測試過程中建立的訂單 ID，供 tearDown 清理。
	 *
	 * @var int[]
	 */
	private array $order_ids = [];

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( Response::class ) ) {
			$this->markTestSkipped( 'PayUni V2 模組未載入，跳過冪等測試' );
		}

		// V2 的 Payment::encrypt / decrypt / hash_info 直接讀這些 option。
		update_option( 'payuni_payment_testmode', 'yes' );
		update_option( 'payuni_payment_merchant_no_test', 'TEST_MERCHANT' );
		update_option( 'payuni_payment_hash_key_test', 'TEST_HASH_KEY_1234567890123456' );
		update_option( 'payuni_payment_hash_iv_test', 'TEST_HASH_IV_123456' );

		$this->order_ids = [];
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		remove_all_actions( 'woomp_payuni_v2_card_response_finish' );
		unset( $_REQUEST['EncryptInfo'], $_REQUEST['HashInfo'] );

		foreach ( $this->order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$order->delete( true );
			}
		}
		$this->order_ids = [];

		parent::tearDown();
	}

	// ========================================================================
	// Fixtures
	// ========================================================================

	/**
	 * 建立一張 pending 的 V2 信用卡訂單。
	 *
	 * @param float $total 訂單金額。
	 *
	 * @return \WC_Order
	 */
	private function create_pending_credit_order( float $total = 1000 ): \WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( 'payuni-credit' );
		$order->set_total( $total );
		$order->set_status( 'pending' );
		$order->update_meta_data( '_payuni_is_3d_auth', 'yes' );
		$order->update_meta_data( '_payuni_mer_trade_no', (string) $order->get_id() );
		$order->save();

		$this->order_ids[] = $order->get_id();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * 組出 get_formatted_decrypted_data() 的輸出形狀，供直接呼叫 complete_paid_order()。
	 *
	 * @param \WC_Order $order    訂單物件。
	 * @param string    $trade_no 統一金流交易編號。
	 * @param array     $extra    覆蓋欄位。
	 *
	 * @return array
	 */
	private function build_formatted( \WC_Order $order, string $trade_no, array $extra = [] ): array {
		return array_merge(
			[
				'status'            => 'SUCCESS',
				'order_id'          => $order->get_id(),
				'user_id'           => 0,
				'message'           => '交易成功',
				'trade_no'          => $trade_no,
				'card_bank'         => '822',
				'card_bank_name'    => '中國信託',
				'card_4no'          => '4321',
				'card_hash'         => '',
				'card_expiry_month' => '12',
				'card_expiry_year'  => '2030',
				'card_inst'         => '',
				'each_amt'          => '',
				'first_amt'         => '',
				'is_3d_auth'        => true,
			],
			$extra
		);
	}

	/**
	 * 組出 PayUni 回傳的加密資料（模擬真實回呼）。
	 *
	 * @param \WC_Order $order    訂單物件。
	 * @param string    $status   狀態碼。
	 * @param string    $trade_no 交易編號。
	 *
	 * @return array{EncryptInfo:string, HashInfo:string}
	 */
	private function build_encrypted_response( \WC_Order $order, string $status = 'SUCCESS', string $trade_no = 'PAYUNI_TN_001' ): array {
		$encrypt_info = Payment::encrypt(
			[
				'Status'       => $status,
				'Message'      => 'SUCCESS' === $status ? '交易成功' : '授權失敗',
				'MerTradeNo'   => (string) $order->get_id(),
				'TradeNo'      => $trade_no,
				'TradeAmt'     => (string) (int) $order->get_total(),
				'AuthType'     => '1',
				'Card4No'      => '4321',
				'CardBank'     => '822',
				'AuthBankName' => '中國信託',
			]
		);

		return [
			'EncryptInfo' => $encrypt_info,
			'HashInfo'    => Payment::hash_info( $encrypt_info ),
		];
	}

	/**
	 * 掛上 finish 攔截器：在 finish() 執行 exit 之前丟例外，把 context / order / redirect 帶回測試。
	 *
	 * @return void
	 */
	private function interrupt_finish(): void {
		add_action(
			'woomp_payuni_v2_card_response_finish',
			static function ( string $context, ?\WC_Order $order, string $redirect ): void {
				throw new PayuniV2FinishInterrupt( $context, $order, $redirect );
			},
			10,
			3
		);
	}

	/**
	 * 取訂單備註的純文字清單。
	 *
	 * @param int $order_id 訂單 ID。
	 *
	 * @return string[]
	 */
	private function get_note_texts( int $order_id ): array {
		return array_map(
			static fn( $note ) => (string) $note->content,
			wc_get_order_notes( [ 'order_id' => $order_id ] )
		);
	}

	// ========================================================================
	// Rule: 首次成功完整寫入，第二次被擋
	// ========================================================================

	/**
	 * @testdox 首次成功應 payment_complete、寫入 8 個 _payuni_resp_* meta、suffix 為 1，並回傳 true
	 */
	public function test_first_success_completes_order(): void {
		$order = $this->create_pending_credit_order();

		$done = Response::complete_paid_order( $order, $this->build_formatted( $order, 'PAYUNI_TN_001' ), 'test' );

		$this->assertTrue( $done, '首次成功應回傳 true' );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertTrue( $reloaded->is_paid(), '訂單應已付款' );
		$this->assertSame( 'PAYUNI_TN_001', $reloaded->get_transaction_id(), 'transaction_id 應為統一金流交易編號' );
		$this->assertSame( 'PAYUNI_TN_001', $reloaded->get_meta( '_payuni_resp_trade_no' ) );
		$this->assertSame( 'SUCCESS', $reloaded->get_meta( '_payuni_resp_status' ) );
		$this->assertSame( '4321', $reloaded->get_meta( '_payuni_card_number' ) );
		$this->assertSame( '(822)中國信託', $reloaded->get_meta( '_payuni_resp_card_bank' ) );
		$this->assertSame( '1', (string) $reloaded->get_meta( '_payuni_order_suffix' ), 'suffix 應從 0 變 1' );
		$this->assertSame(
			$order->get_id(),
			(int) $reloaded->get_meta( Response::META_PAID_ORDER_ID ),
			'付款完成戳記的值應為訂單自己的 ID'
		);
	}

	/**
	 * @testdox 第二次收到相同成功通知應回傳 false，且 suffix 仍為 1、交易紀錄備註只有一則
	 */
	public function test_second_success_is_skipped_and_does_not_increment_suffix(): void {
		$order     = $this->create_pending_credit_order();
		$formatted = $this->build_formatted( $order, 'PAYUNI_TN_001' );

		$this->assertTrue( Response::complete_paid_order( $order, $formatted, 'first' ) );

		$second = Response::complete_paid_order( wc_get_order( $order->get_id() ), $formatted, 'second' );

		$this->assertFalse( $second, '重複通知應被冪等保護擋下' );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( '1', (string) $reloaded->get_meta( '_payuni_order_suffix' ), 'suffix 不應被重複通知再加一次' );

		$trade_notes = array_filter(
			$this->get_note_texts( $order->get_id() ),
			static fn( $text ) => false !== strpos( $text, '統一金流交易紀錄' )
		);
		$this->assertCount( 1, $trade_notes, '交易紀錄備註只能有一則' );

		$skip_notes = array_filter(
			$this->get_note_texts( $order->get_id() ),
			static fn( $text ) => false !== strpos( $text, '重複通知' )
		);
		$this->assertNotEmpty( $skip_notes, '應留下「重複通知已略過」的備註供追查' );
	}

	// ========================================================================
	// Rule: 冪等判斷不能被複製的 meta 誤導
	// ========================================================================

	/**
	 * issue 原文建議用「_payuni_resp_status 有沒有值」判斷重複。
	 * WCS 的 wcs_copy_order_meta() 會把父訂單的 _payuni_* meta 整包複製到續訂單，
	 * 續訂單本身是 pending、從沒付過款，卻帶著前一張訂單的 SUCCESS 與戳記。
	 * 用「meta 有沒有值」判斷會讓它首次付款就被略過——又一次掉單。
	 *
	 * 戳記的值是「訂單自己的 ID」，被複製過去時對不上，所以不會誤判。
	 *
	 * @testdox pending 訂單帶著「別張訂單」的付款戳記與交易結果時，不得被誤判為已完成，首次付款必須成功
	 */
	public function test_order_with_inherited_meta_is_not_treated_as_duplicate(): void {
		$order = $this->create_pending_credit_order();

		// 模擬整包複製 meta 的結果：戳記指向別張訂單、交易結果是別人的。
		$order->update_meta_data( Response::META_PAID_ORDER_ID, $order->get_id() + 100000 );
		$order->update_meta_data( '_payuni_resp_status', 'SUCCESS' );
		$order->update_meta_data( '_payuni_resp_trade_no', 'PAYUNI_TN_SOMEONE_ELSE' );
		$order->update_meta_data( '_payuni_order_suffix', 3 );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $reloaded->get_status(), '前提：訂單本身是 pending' );
		$this->assertFalse( Response::is_payuni_completed( $reloaded ), '戳記值是別張訂單的 ID，不應被視為已完成' );

		$done = Response::complete_paid_order( $reloaded, $this->build_formatted( $reloaded, 'PAYUNI_TN_MINE' ), 'first' );

		$this->assertTrue( $done, '帶著別人 meta 的 pending 訂單首次付款應成功完成' );

		$paid = wc_get_order( $order->get_id() );
		$this->assertTrue( $paid->is_paid() );
		$this->assertSame( 'PAYUNI_TN_MINE', $paid->get_meta( '_payuni_resp_trade_no' ), '交易結果應被本次真實回應覆蓋' );
		$this->assertSame( $order->get_id(), (int) $paid->get_meta( Response::META_PAID_ORDER_ID ), '戳記應改為自己的 ID' );
	}

	/**
	 * woomp_copy_order() 已在 PR A 加了 blocklist，這裡驗證整條路徑：
	 * pending 原訂單帶殘留 meta → 複製 → 新訂單可正常完成首次付款。
	 *
	 * @testdox woomp_copy_order() 複製出的 pending 訂單，首次付款必須成功完成
	 */
	public function test_copied_pending_order_completes_normally(): void {
		$original = $this->create_pending_credit_order();
		$original->update_meta_data( '_payuni_resp_status', 'SUCCESS' );
		$original->update_meta_data( '_payuni_resp_trade_no', 'PAYUNI_TN_STALE' );
		$original->save();

		$copied_id         = woomp_copy_order( wc_get_order( $original->get_id() ) );
		$this->order_ids[] = $copied_id;
		$copied            = wc_get_order( $copied_id );

		$this->assertSame( 'pending', $copied->get_status() );
		$this->assertFalse( Response::is_payuni_completed( $copied ), '複製出的 pending 訂單不應被視為已完成' );

		$done = Response::complete_paid_order( $copied, $this->build_formatted( $copied, 'PAYUNI_TN_COPY' ), 'copy' );

		$this->assertTrue( $done, '複製出的訂單首次付款應成功完成' );
		$this->assertTrue( wc_get_order( $copied_id )->is_paid() );
	}

	/**
	 * @testdox 已完成後被退款（is_paid 變 false）的訂單，再收到重複通知不得被重新完成
	 */
	public function test_refunded_order_is_not_recompleted(): void {
		$order     = $this->create_pending_credit_order();
		$formatted = $this->build_formatted( $order, 'PAYUNI_TN_001' );

		Response::complete_paid_order( $order, $formatted, 'first' );

		$paid = wc_get_order( $order->get_id() );
		$paid->update_status( 'refunded' );
		$paid->save();

		$refunded = wc_get_order( $order->get_id() );
		$this->assertFalse( $refunded->is_paid(), '前提：退款後 is_paid() 應為 false' );
		$this->assertTrue( Response::is_payuni_completed( $refunded ), '戳記應讓退款後的訂單仍被視為已處理' );

		$again = Response::complete_paid_order( $refunded, $formatted, 'late' );

		$this->assertFalse( $again, '遲到的重複通知不應重新完成已退款訂單' );
		$this->assertSame( 'refunded', wc_get_order( $order->get_id() )->get_status(), '訂單狀態應維持 refunded' );
	}

	/**
	 * @testdox 訂閱物件完成後狀態應為 active，且 is_payuni_completed 認得它
	 */
	public function test_subscription_order_uses_active_status(): void {
		if ( ! function_exists( 'wcs_create_subscription' ) ) {
			$this->markTestSkipped( 'WooCommerce Subscriptions 未載入' );
		}

		$parent = $this->create_pending_credit_order();

		$subscription = wcs_create_subscription(
			[
				'order_id'         => $parent->get_id(),
				'status'           => 'pending',
				'billing_period'   => 'month',
				'billing_interval' => 1,
			]
		);
		$subscription->set_payment_method( 'payuni-credit-subscription' );
		$subscription->set_total( 1000 );
		$subscription->update_meta_data( '_payuni_mer_trade_no', (string) $subscription->get_id() );
		$subscription->save();
		$this->order_ids[] = $subscription->get_id();

		$done = Response::complete_paid_order( $subscription, $this->build_formatted( $subscription, 'PAYUNI_TN_SUB' ), 'sub' );

		$this->assertTrue( $done );

		$reloaded = wcs_get_subscription( $subscription->get_id() );
		$this->assertSame( 'active', $reloaded->get_status(), '訂閱物件的成功狀態應為 active' );
		$this->assertTrue( Response::is_payuni_completed( $reloaded ), 'active 訂閱應被視為已完成' );
	}

	// ========================================================================
	// Rule: 失敗通知不得覆蓋已付款訂單
	// ========================================================================

	/**
	 * NotifyURL 上線後 PayUni 在未收到 "1" 時會重送；一則遲到的失敗通知不能把已付款訂單打成 failed。
	 *
	 * @testdox 已付款訂單收到遲到的失敗通知，狀態不得變為 failed
	 */
	public function test_late_failure_notice_does_not_fail_paid_order(): void {
		$order = $this->create_pending_credit_order();
		Response::complete_paid_order( $order, $this->build_formatted( $order, 'PAYUNI_TN_001' ), 'first' );

		$resp = (object) $this->build_encrypted_response( wc_get_order( $order->get_id() ), 'FAIL', 'PAYUNI_TN_001' );

		// sync 情境不 redirect、不 exit，可直接呼叫。
		Response::card_response( $resp, Response::CONTEXT_SYNC );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertTrue( $reloaded->is_paid(), '已付款訂單不應被遲到的失敗通知改狀態' );
		$this->assertNotSame( 'failed', $reloaded->get_status() );

		$ignore_notes = array_filter(
			$this->get_note_texts( $order->get_id() ),
			static fn( $text ) => false !== strpos( $text, '忽略此失敗通知' )
		);
		$this->assertNotEmpty( $ignore_notes, '應留下忽略失敗通知的備註' );
	}

	// ========================================================================
	// Rule: PaidLock 跨行程互斥
	// ========================================================================

	/**
	 * @testdox 鎖被其他行程持有時，complete_paid_order 應回傳 false 且不完成訂單
	 */
	public function test_lock_blocks_concurrent_completion(): void {
		$order = $this->create_pending_credit_order();
		$key   = (string) $order->get_meta( '_payuni_mer_trade_no' );

		$this->assertTrue( PaidLock::acquire( $key ), '前提：第一次取鎖應成功' );

		try {
			$done = Response::complete_paid_order( $order, $this->build_formatted( $order, 'PAYUNI_TN_001' ), 'blocked' );

			$this->assertFalse( $done, '鎖被持有時應被擋下' );
			$this->assertFalse( wc_get_order( $order->get_id() )->is_paid(), '被擋下的請求不應完成訂單' );
		} finally {
			PaidLock::release( $key );
		}
	}

	/**
	 * @testdox 陳舊超過 90 秒的鎖應可被搶走（前一行程 fatal 不得永久鎖死訂單）
	 */
	public function test_stale_lock_is_stolen(): void {
		global $wpdb;

		$key  = 'STALE_' . wp_rand( 1000, 9999 );
		$name = 'payuni_v2_lock_' . $key;

		// 直接寫一筆 91 秒前的鎖。
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				$name,
				(string) ( time() - 91 )
			)
		);

		try {
			$this->assertTrue( PaidLock::acquire( $key ), '過期的鎖應可被搶走' );
		} finally {
			PaidLock::release( $key );
		}
	}

	/**
	 * @testdox 尚未過期的鎖不可被搶
	 */
	public function test_fresh_lock_is_not_stolen(): void {
		$key = 'FRESH_' . wp_rand( 1000, 9999 );

		$this->assertTrue( PaidLock::acquire( $key ) );

		try {
			$this->assertFalse( PaidLock::acquire( $key ), '剛取得的鎖不應被第二次 acquire 搶走' );
		} finally {
			PaidLock::release( $key );
		}
	}

	/**
	 * @testdox complete_paid_order 完成後鎖應已釋放，wp_options 內不再有該 row
	 */
	public function test_lock_is_released_after_completion(): void {
		global $wpdb;

		$order = $this->create_pending_credit_order();
		$key   = (string) $order->get_meta( '_payuni_mer_trade_no' );

		Response::complete_paid_order( $order, $this->build_formatted( $order, 'PAYUNI_TN_001' ), 'test' );

		$row = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'payuni_v2_lock_' . $key )
		);
		$this->assertNull( $row, '完成後鎖 row 應被刪除' );
	}

	// ========================================================================
	// Rule: 三種進入情境的收尾
	// ========================================================================

	/**
	 * @testdox 背景通知端點 woocommerce_api_payuni_notify_card_bg 必須已註冊（否則 wc-api 回 400，通知永遠收不到）
	 */
	public function test_background_notify_action_is_registered(): void {
		$this->assertNotFalse(
			has_action( 'woocommerce_api_payuni_notify_card_bg' ),
			'背景通知 hook 未註冊'
		);
	}

	/**
	 * @testdox notify 情境成功後 finish 收到 context=notify 且訂單已完成（不 redirect）
	 */
	public function test_notify_context_completes_order_and_finishes_as_notify(): void {
		$order = $this->create_pending_credit_order();
		$enc   = $this->build_encrypted_response( $order );

		$_REQUEST['EncryptInfo'] = $enc['EncryptInfo'];
		$_REQUEST['HashInfo']    = $enc['HashInfo'];

		$this->interrupt_finish();

		try {
			Response::card_notify();
			$this->fail( 'finish 攔截器應丟出例外' );
		} catch ( PayuniV2FinishInterrupt $interrupt ) {
			$this->assertSame( Response::CONTEXT_NOTIFY, $interrupt->context );
			$this->assertInstanceOf( WC_Order::class, $interrupt->order );
		}

		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid(), 'notify 情境應完成訂單' );
	}

	/**
	 * @testdox return 情境成功後 finish 收到導向訂單完成頁的網址
	 */
	public function test_return_context_redirects_to_order_received(): void {
		$order = $this->create_pending_credit_order();
		$enc   = $this->build_encrypted_response( $order );

		$_REQUEST['EncryptInfo'] = $enc['EncryptInfo'];
		$_REQUEST['HashInfo']    = $enc['HashInfo'];

		$this->interrupt_finish();

		try {
			Response::card_response( null );
			$this->fail( 'finish 攔截器應丟出例外' );
		} catch ( PayuniV2FinishInterrupt $interrupt ) {
			$this->assertSame( Response::CONTEXT_RETURN, $interrupt->context, '無 $resp 時預設應為 return 情境' );
			$this->assertStringContainsString( 'order-received', $interrupt->redirect );
		}
	}

	/**
	 * @testdox notify 與 return 幾乎同時抵達時，訂單只完成一次、suffix 只加一次
	 */
	public function test_notify_then_return_completes_once(): void {
		$order = $this->create_pending_credit_order();
		$enc   = $this->build_encrypted_response( $order );

		$_REQUEST['EncryptInfo'] = $enc['EncryptInfo'];
		$_REQUEST['HashInfo']    = $enc['HashInfo'];

		$this->interrupt_finish();

		try {
			Response::card_notify();
		} catch ( PayuniV2FinishInterrupt $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// 預期中止。
		}

		try {
			Response::card_response( null );
		} catch ( PayuniV2FinishInterrupt $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// 預期中止。
		}

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertTrue( $reloaded->is_paid() );
		$this->assertSame( '1', (string) $reloaded->get_meta( '_payuni_order_suffix' ), '兩個入口先後進來，suffix 仍應只有 1' );

		$trade_notes = array_filter(
			$this->get_note_texts( $order->get_id() ),
			static fn( $text ) => false !== strpos( $text, '統一金流交易紀錄' )
		);
		$this->assertCount( 1, $trade_notes, '交易紀錄備註只能有一則' );
	}

	// ========================================================================
	// Rule: 對外公開端點的防呆
	// ========================================================================

	/**
	 * @testdox MerTradeNo 指向不存在的訂單時不得 fatal，finish 收到 order=null
	 */
	public function test_null_order_does_not_fatal(): void {
		$encrypt_info = Payment::encrypt(
			[
				'Status'     => 'SUCCESS',
				'Message'    => '交易成功',
				'MerTradeNo' => '999999999',
				'TradeNo'    => 'PAYUNI_TN_GHOST',
				'TradeAmt'   => '1000',
				'AuthType'   => '1',
			]
		);

		$_REQUEST['EncryptInfo'] = $encrypt_info;
		$_REQUEST['HashInfo']    = Payment::hash_info( $encrypt_info );

		$this->interrupt_finish();

		try {
			Response::card_response( null );
			$this->fail( 'finish 攔截器應丟出例外' );
		} catch ( PayuniV2FinishInterrupt $interrupt ) {
			$this->assertNull( $interrupt->order, '找不到訂單時應以 order=null 收尾，而非 fatal' );
		}
	}

	/**
	 * @testdox HashInfo 不符時應拒絕處理，訂單不得完成
	 */
	public function test_invalid_hash_info_is_rejected(): void {
		$order = $this->create_pending_credit_order();
		$enc   = $this->build_encrypted_response( $order );

		$_REQUEST['EncryptInfo'] = $enc['EncryptInfo'];
		$_REQUEST['HashInfo']    = 'DEADBEEF' . substr( $enc['HashInfo'], 8 );

		$this->interrupt_finish();

		try {
			Response::card_response( null );
			$this->fail( 'finish 攔截器應丟出例外' );
		} catch ( PayuniV2FinishInterrupt $interrupt ) {
			$this->assertNull( $interrupt->order, '驗簽失敗應在取得訂單前就中止' );
		}

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid(), '驗簽失敗不得完成訂單' );
	}

	/**
	 * @testdox sync 情境呼叫後不 redirect（wp_redirect 不得被觸發）
	 */
	public function test_sync_context_does_not_redirect(): void {
		$order = $this->create_pending_credit_order();
		$resp  = (object) $this->build_encrypted_response( $order );

		$redirect_calls = 0;
		$counter        = static function ( $location ) use ( &$redirect_calls ) {
			++$redirect_calls;
			return false; // 阻止真的 redirect。
		};
		add_filter( 'wp_redirect', $counter );

		try {
			Response::card_response( $resp );
		} finally {
			remove_filter( 'wp_redirect', $counter );
		}

		$this->assertSame( 0, $redirect_calls, 'sync 情境絕不能 redirect，否則 build_request() 的 return 會被截斷' );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid(), 'sync 情境仍應完成訂單' );
	}
}

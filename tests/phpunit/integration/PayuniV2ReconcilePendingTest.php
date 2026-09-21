<?php
/**
 * PayUni V2 待付款訂單補償對帳（issue #127 PR D）整合測試
 *
 * 驗證 PAYUNI\Gateways\PendingReconciler：
 * - 排程階梯與去重
 * - pending + 已付款 + 金額相符 → 補完（走 Response::complete_paid_order）
 * - cancelled / failed 查到已付款 → 只告警不改狀態（使用者決策）
 * - 金額不符 / DataSource=B / 非終態 → 不補完、排下一階
 * - 終態 / 階梯用盡 / HTTP 錯誤 的降級行為
 * - 以 V2 規則的 MerTradeNo 查詢、Result 多筆時挑對的那筆
 *
 * 執行指令：
 * docker exec <woomp-tests-cli> sh -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --filter PayuniV2ReconcilePending'
 *
 * @package Woomp\Tests\Integration
 */

use J7\Payuni\Shared\Utils\EncryptUtils;
use PAYUNI\Gateways\PendingReconciler;
use PAYUNI\Gateways\Response;

/**
 * V2 補償對帳測試類別
 *
 * @covers \PAYUNI\Gateways\PendingReconciler
 * @group payuni
 * @group reconcile
 * @group regression
 */
final class PayuniV2ReconcilePendingTest extends WP_UnitTestCase {

	/**
	 * 預先組好、供 pre_http_request 回傳的 HTTP body。
	 *
	 * @var string|null
	 */
	private ?string $http_mock_body = null;

	/**
	 * 攔截到的查詢請求，解密後的 EncryptInfo。
	 *
	 * @var array
	 */
	private array $sent_queries = [];

	/**
	 * 是否讓下一次 HTTP 回傳 WP_Error。
	 *
	 * @var bool
	 */
	private bool $http_should_fail = false;

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

		if ( ! class_exists( PendingReconciler::class ) ) {
			$this->markTestSkipped( 'PayUni V2 模組未載入' );
		}

		update_option( 'payuni_payment_testmode', 'yes' );
		update_option( 'payuni_payment_merchant_no_test', 'TEST_MERCHANT' );
		update_option( 'payuni_payment_hash_key_test', 'TEST_HASH_KEY_1234567890123456' );
		update_option( 'payuni_payment_hash_iv_test', 'TEST_HASH_IV_123456' );

		$this->reset_setting_dto();

		$this->http_mock_body   = null;
		$this->sent_queries     = [];
		$this->http_should_fail = false;
		$this->order_ids        = [];

		add_filter( 'pre_http_request', [ $this, 'filter_http' ], 10, 3 );
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'filter_http' ], 10 );
		remove_all_filters( 'woomp_payuni_v2_reconcile_delays' );
		remove_all_actions( 'woomp_payuni_v2_reconcile_exhausted' );

		foreach ( $this->order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$order->delete( true );
			}
		}
		$this->order_ids = [];

		$this->reset_setting_dto();

		parent::tearDown();
	}

	// ========================================================================
	// Fixtures
	// ========================================================================

	/**
	 * 建立 V2 信用卡訂單。
	 *
	 * @param string $status 訂單狀態。
	 * @param float  $total  金額。
	 *
	 * @return \WC_Order
	 */
	private function create_v2_order( string $status = 'pending', float $total = 1000 ): \WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( 'payuni-credit' );
		$order->set_total( $total );
		$order->set_status( $status );
		$order->update_meta_data( '_payuni_is_3d_auth', 'yes' );
		$order->update_meta_data( '_payuni_mer_trade_no', (string) $order->get_id() );
		$order->save();

		$this->order_ids[] = $order->get_id();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * 組出 /trade/query 的單筆結果。
	 *
	 * @param \WC_Order $order     訂單物件。
	 * @param string    $status    TradeStatus。
	 * @param array     $overrides 覆蓋欄位。
	 *
	 * @return array
	 */
	private function trade_row( \WC_Order $order, string $status = '1', array $overrides = [] ): array {
		return array_merge(
			[
				'MerTradeNo'  => (string) $order->get_meta( '_payuni_mer_trade_no' ),
				'TradeNo'     => 'RECON_V2_' . $order->get_id(),
				'TradeStatus' => $status,
				'TradeAmt'    => (string) (int) $order->get_total(),
				'Card4No'     => '4242',
				'CardBank'    => '822',
				'DataSource'  => 'A',
			],
			$overrides
		);
	}

	/**
	 * 讓 /trade/query 回傳指定的 Result 列。
	 *
	 * @param array $rows Result 陣列。
	 *
	 * @return void
	 */
	private function mock_query( array $rows ): void {
		$encrypt_info = EncryptUtils::encrypt( [ 'Result' => $rows ] );

		$this->http_mock_body = wp_json_encode(
			[
				'Status'      => 'SUCCESS',
				'MerID'       => 'TEST_MERCHANT',
				'Version'     => '2.0',
				'EncryptInfo' => $encrypt_info,
				'HashInfo'    => EncryptUtils::hash_info( $encrypt_info ),
			]
		);
	}

	/**
	 * pre_http_request 回呼：僅攔截 /trade/query，記錄送出的查詢參數。
	 *
	 * @param mixed  $pre  短路值。
	 * @param array  $args 請求參數。
	 * @param string $url  請求網址。
	 *
	 * @return mixed
	 */
	public function filter_http( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'trade/query' ) ) {
			return $pre;
		}

		$body = $args['body'] ?? [];
		if ( is_array( $body ) && isset( $body['EncryptInfo'] ) ) {
			$this->sent_queries[] = EncryptUtils::decrypt( $body['EncryptInfo'] );
		}

		if ( $this->http_should_fail ) {
			return new WP_Error( 'http_request_failed', '模擬連線失敗' );
		}

		return [
			'headers'  => [],
			'body'     => (string) $this->http_mock_body,
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * 指定訂單在指定階梯是否已排程。
	 *
	 * @param int $order_id 訂單 ID。
	 * @param int $attempt  階梯。
	 *
	 * @return bool
	 */
	private function is_scheduled( int $order_id, int $attempt ): bool {
		$args = [ $order_id, $attempt ];

		if ( function_exists( 'as_next_scheduled_action' ) && false !== as_next_scheduled_action( PendingReconciler::HOOK, $args ) ) {
			return true;
		}

		return false !== wp_next_scheduled( PendingReconciler::HOOK, $args );
	}

	/**
	 * 取訂單備註純文字。
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

	/**
	 * 重置 V3 SettingDTO 單例（HttpClient 用它讀憑證）。
	 *
	 * @return void
	 */
	private function reset_setting_dto(): void {
		if ( ! class_exists( \J7\Payuni\Contracts\DTOs\SettingDTO::class ) ) {
			return;
		}
		$ref = new \ReflectionProperty( \J7\Payuni\Contracts\DTOs\SettingDTO::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	// ========================================================================
	// Rule: 排程
	// ========================================================================

	/**
	 * @testdox schedule 應排出 payuni_v2_reconcile_pending，args 為 [order_id, 0]
	 */
	public function test_schedule_registers_action_with_attempt_arg(): void {
		$order = $this->create_v2_order();

		PendingReconciler::schedule( $order );

		$this->assertTrue( $this->is_scheduled( $order->get_id(), 0 ) );
	}

	/**
	 * @testdox 同訂單同階梯重複 schedule 只應有一筆待處理排程
	 */
	public function test_schedule_is_deduplicated(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		$order = $this->create_v2_order();

		PendingReconciler::schedule( $order );
		PendingReconciler::schedule( $order );

		$actions = as_get_scheduled_actions(
			[
				'hook'   => PendingReconciler::HOOK,
				'args'   => [ $order->get_id(), 0 ],
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ids'
		);
		$this->assertCount( 1, $actions );
	}

	// ========================================================================
	// Rule: pending 訂單補完
	// ========================================================================

	/**
	 * @testdox pending + TradeStatus=1 + 金額相符 → 補完訂單並寫入 V2 meta
	 */
	public function test_reconcile_completes_pending_order_when_paid(): void {
		$order = $this->create_v2_order();
		$this->mock_query( [ $this->trade_row( $order ) ] );

		PendingReconciler::reconcile( $order->get_id(), 0 );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertTrue( $reloaded->is_paid() );
		$this->assertSame( 'RECON_V2_' . $order->get_id(), $reloaded->get_meta( '_payuni_resp_trade_no' ) );
		// 補償補完不得改動 _payuni_order_suffix：它在送出當下就已取號（issue #131），
		// 補完時再加一次會讓序號與實際送出過的交易對不上。
		$this->assertSame( '', (string) $reloaded->get_meta( '_payuni_order_suffix' ), '補償補完不應改動 suffix' );
		$this->assertSame( $order->get_id(), (int) $reloaded->get_meta( Response::META_PAID_ORDER_ID ) );
		$this->assertFalse( $this->is_scheduled( $order->get_id(), 1 ), '補完後不應排下一階' );
	}

	/**
	 * @testdox 查詢應以落地的 _payuni_mer_trade_no 送出（V2 規則，非 get_order_number）
	 */
	public function test_reconcile_uses_persisted_mer_trade_no(): void {
		$order = $this->create_v2_order();
		$order->update_meta_data( '_payuni_mer_trade_no', $order->get_id() . '-2' );
		$order->save();

		$this->mock_query( [ $this->trade_row( wc_get_order( $order->get_id() ), '9' ) ] );

		PendingReconciler::reconcile( $order->get_id(), 0 );

		$this->assertNotEmpty( $this->sent_queries );
		$this->assertSame( $order->get_id() . '-2', $this->sent_queries[0]['MerTradeNo'] );
	}

	/**
	 * @testdox 舊訂單沒有 _payuni_mer_trade_no 時，應以 get_id() 加 suffix 重建
	 */
	public function test_reconcile_rebuilds_mer_trade_no_for_legacy_order(): void {
		$order = $this->create_v2_order();
		$order->delete_meta_data( '_payuni_mer_trade_no' );
		$order->update_meta_data( '_payuni_order_suffix', 1 );
		$order->save();

		$this->mock_query( [] );

		PendingReconciler::reconcile( $order->get_id(), 0 );

		$this->assertSame( $order->get_id() . '-1', $this->sent_queries[0]['MerTradeNo'] );
	}

	/**
	 * @testdox Result 多筆時應挑 MerTradeNo 相符的那筆，不盲取第一筆
	 */
	public function test_reconcile_picks_row_by_mer_trade_no(): void {
		$order = $this->create_v2_order();
		$this->mock_query(
			[
				$this->trade_row( $order, '2', [ 'MerTradeNo' => $order->get_id() . '-0', 'TradeNo' => 'WRONG_ROW' ] ),
				$this->trade_row( $order, '1', [ 'TradeNo' => 'RIGHT_ROW' ] ),
			]
		);

		PendingReconciler::reconcile( $order->get_id(), 0 );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertTrue( $reloaded->is_paid() );
		$this->assertSame( 'RIGHT_ROW', $reloaded->get_meta( '_payuni_resp_trade_no' ) );
	}

	// ========================================================================
	// Rule: 不補完的情境
	// ========================================================================

	/**
	 * @testdox 已 processing 的訂單不應查詢也不應變更
	 */
	public function test_reconcile_ignores_completed_order(): void {
		$order = $this->create_v2_order( 'processing' );
		$this->mock_query( [ $this->trade_row( $order ) ] );

		PendingReconciler::reconcile( $order->get_id(), 0 );

		$this->assertEmpty( $this->sent_queries, '已完成訂單不應發查詢' );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox 非 V2 信用卡閘道的訂單應跳過
	 */
	public function test_reconcile_skips_non_v2_gateway(): void {
		$order = $this->create_v2_order();
		$order->set_payment_method( 'payuni-credit-v3' );
		$order->save();
		$this->mock_query( [ $this->trade_row( $order ) ] );

		PendingReconciler::reconcile( $order->get_id(), 0 );

		$this->assertEmpty( $this->sent_queries );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}

	/**
	 * @testdox 金額不符時不應補完，只留警示備註
	 */
	public function test_reconcile_rejects_amount_mismatch(): void {
		$order = $this->create_v2_order( 'pending', 1000 );
		$this->mock_query( [ $this->trade_row( $order, '1', [ 'TradeAmt' => '999' ] ) ] );

		PendingReconciler::reconcile( $order->get_id(), 0 );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertFalse( $reloaded->is_paid(), '金額不符不得完成訂單' );
		$this->assertSame( 'pending', $reloaded->get_status() );

		$notes = array_filter( $this->get_note_texts( $order->get_id() ), static fn( $t ) => false !== strpos( $t, '金額不符' ) );
		$this->assertNotEmpty( $notes );
	}

	/**
	 * @testdox DataSource=B（PayUni 處理中）應視為未決並排下一階
	 */
	public function test_reconcile_treats_datasource_b_as_unresolved(): void {
		$order = $this->create_v2_order();
		$this->mock_query( [ $this->trade_row( $order, '1', [ 'DataSource' => 'B' ] ) ] );

		PendingReconciler::reconcile( $order->get_id(), 0 );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertTrue( $this->is_scheduled( $order->get_id(), 1 ), '應排第二階' );
	}

	/**
	 * @testdox TradeStatus=9（未付款）應維持 pending 並排下一階
	 */
	public function test_reconcile_schedules_next_step_when_unpaid(): void {
		$order = $this->create_v2_order();
		$this->mock_query( [ $this->trade_row( $order, '9' ) ] );

		PendingReconciler::reconcile( $order->get_id(), 0 );

		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
		$this->assertTrue( $this->is_scheduled( $order->get_id(), 1 ) );
	}

	/**
	 * @testdox TradeStatus=2（付款失敗）為終態，不應再排下一階
	 */
	public function test_reconcile_stops_ladder_on_definitive_failure(): void {
		$order = $this->create_v2_order();
		$this->mock_query( [ $this->trade_row( $order, '2' ) ] );

		PendingReconciler::reconcile( $order->get_id(), 0 );

		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status(), '終態只留備註不改狀態' );
		$this->assertFalse( $this->is_scheduled( $order->get_id(), 1 ), '終態不應再排' );
	}

	/**
	 * @testdox 最後一階仍未決時應留人工核對備註並觸發 exhausted action
	 */
	public function test_reconcile_leaves_manual_note_when_exhausted(): void {
		$order = $this->create_v2_order();
		$this->mock_query( [ $this->trade_row( $order, '9' ) ] );

		$fired = 0;
		add_action( 'woomp_payuni_v2_reconcile_exhausted', static function () use ( &$fired ) { ++$fired; } );

		PendingReconciler::reconcile( $order->get_id(), 2 ); // 最後一階（index 2）

		$this->assertSame( 1, $fired, '應觸發 exhausted action' );
		$this->assertFalse( $this->is_scheduled( $order->get_id(), 3 ) );

		$notes = array_filter( $this->get_note_texts( $order->get_id() ), static fn( $t ) => false !== strpos( $t, '用盡重試' ) );
		$this->assertNotEmpty( $notes );
		$this->assertStringContainsString( (string) $order->get_id(), reset( $notes ), '備註應含 MerTradeNo 供人工核對' );
	}

	/**
	 * @testdox 查詢 HTTP 錯誤時訂單應完全不變，並排下一階
	 */
	public function test_reconcile_degrades_safely_on_http_error(): void {
		$order                  = $this->create_v2_order();
		$this->http_should_fail = true;

		PendingReconciler::reconcile( $order->get_id(), 0 );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertFalse( $reloaded->is_paid() );
		$this->assertTrue( $this->is_scheduled( $order->get_id(), 1 ), 'HTTP 錯誤屬未決，應排下一階' );
	}

	// ========================================================================
	// Rule: cancelled / failed 只告警不改狀態（使用者決策）
	// ========================================================================

	/**
	 * @testdox cancelled 訂單查到已付款 → 狀態不變、寫 _payuni_lost_payment_detected、留告警備註
	 */
	public function test_reconcile_alerts_but_does_not_revive_cancelled_order(): void {
		$order = $this->create_v2_order( 'cancelled' );
		$this->mock_query( [ $this->trade_row( $order ) ] );

		PendingReconciler::reconcile( $order->get_id(), 0 );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( 'cancelled', $reloaded->get_status(), '取消訂單不得被自動救回（庫存已回補）' );
		$this->assertFalse( $reloaded->is_paid() );
		$this->assertStringStartsWith( 'RECON_V2_', (string) $reloaded->get_meta( PendingReconciler::META_LOST_PAYMENT ) );

		$notes = array_filter( $this->get_note_texts( $order->get_id() ), static fn( $t ) => false !== strpos( $t, '已扣款' ) );
		$this->assertNotEmpty( $notes, '應留下已扣款告警備註' );
		$this->assertFalse( $this->is_scheduled( $order->get_id(), 1 ), '告警後不應再排' );
	}

	/**
	 * @testdox failed 訂單查到未付款 → 不告警、不改狀態
	 */
	public function test_reconcile_failed_order_with_unpaid_trade_does_nothing(): void {
		$order = $this->create_v2_order( 'failed' );
		$this->mock_query( [ $this->trade_row( $order, '2' ) ] );

		PendingReconciler::reconcile( $order->get_id(), 0 );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( 'failed', $reloaded->get_status() );
		$this->assertSame( '', (string) $reloaded->get_meta( PendingReconciler::META_LOST_PAYMENT ) );
	}

	/**
	 * @testdox 已告警過的取消訂單再次 reconcile 不應重複查詢
	 */
	public function test_reconcile_does_not_realert_cancelled_order(): void {
		$order = $this->create_v2_order( 'cancelled' );
		$order->update_meta_data( PendingReconciler::META_LOST_PAYMENT, 'RECON_V2_X|' . time() );
		$order->save();
		$this->mock_query( [ $this->trade_row( $order ) ] );

		PendingReconciler::reconcile( $order->get_id(), 0 );

		$this->assertEmpty( $this->sent_queries, '已告警過不應再發查詢' );
	}

	// ========================================================================
	// Rule: Token 缺口
	// ========================================================================

	/**
	 * @testdox 顧客勾選存卡但查詢回應無 CreditHash → 寫 _payuni_token_bind_failed 並留備註
	 */
	public function test_reconcile_warns_when_token_cannot_be_bound(): void {
		$order = $this->create_v2_order();
		$order->update_meta_data( '_payuni_token_maybe_save', 'yes' );
		$order->save();
		$this->mock_query( [ $this->trade_row( wc_get_order( $order->get_id() ) ) ] );

		PendingReconciler::reconcile( $order->get_id(), 0 );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertTrue( $reloaded->is_paid() );
		$this->assertSame( 'yes', $reloaded->get_meta( '_payuni_token_bind_failed' ) );

		$notes = array_filter( $this->get_note_texts( $order->get_id() ), static fn( $t ) => false !== strpos( $t, '記憶卡號未建立' ) );
		$this->assertNotEmpty( $notes );
	}

	// ========================================================================
	// Rule: reconcile_now（供 CREDIT04001 反查）
	// ========================================================================

	/**
	 * @testdox reconcile_now 查得已付款應同步補完並回傳 true
	 */
	public function test_reconcile_now_completes_and_returns_true(): void {
		$order = $this->create_v2_order();
		$this->mock_query( [ $this->trade_row( $order ) ] );

		$this->assertTrue( PendingReconciler::reconcile_now( $order ) );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
	}

	/**
	 * @testdox reconcile_now 查得未付款或查詢失敗應回傳 false 且不動訂單
	 */
	public function test_reconcile_now_returns_false_when_unpaid_or_error(): void {
		$order = $this->create_v2_order();
		$this->mock_query( [ $this->trade_row( $order, '9' ) ] );
		$this->assertFalse( PendingReconciler::reconcile_now( $order ) );

		$this->http_should_fail = true;
		$this->assertFalse( PendingReconciler::reconcile_now( wc_get_order( $order->get_id() ) ) );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}
}

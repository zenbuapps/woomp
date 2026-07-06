<?php
/**
 * PayUni V3 待付款訂單補償對帳（issue #125）整合測試
 *
 * 驗證 PendingReconciler 的排程與補償查詢邏輯：
 * - 3DS pending 分支排程一次延遲查詢
 * - 訂單已非 pending 時略過（冪等）
 * - 查得 TradeStatus=1 → 補完訂單
 * - 查得未成功 → 維持 pending
 * - 查詢失敗 → 安全降級，維持 pending
 *
 * @package Woomp\Tests\Integration
 */

use J7\Payuni\Domains\Reconciliation\PendingReconciler;
use J7\Payuni\Shared\Utils\EncryptUtils;

/**
 * 待付款訂單補償對帳測試類別
 *
 * @covers \J7\Payuni\Domains\Reconciliation\PendingReconciler
 * @covers \J7\Payuni\Infrastructure\Http\HttpClient::query_trade_by_mer_no
 * @group payuni
 * @group reconcile
 */
final class PayuniV3ReconcilePendingTest extends WP_UnitTestCase {

	/**
	 * 預先組好、供 pre_http_request 回傳的 HTTP body。
	 *
	 * @var string|null
	 */
	private ?string $http_mock_body = null;

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		// 設定 PayUni 測試憑證，避免 SettingDTO 的 strict type 錯誤。
		update_option( 'payuni_payment_testmode', 'yes' );
		update_option( 'payuni_payment_merchant_no_test', 'TEST_MERCHANT' );
		update_option( 'payuni_payment_hash_key_test', 'TEST_HASH_KEY_1234567890123456' );
		update_option( 'payuni_payment_hash_iv_test', 'TEST_HASH_IV_123456' );

		// 重置 SettingDTO 單例。
		$this->reset_setting_dto();
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'filter_http' ], 10 );
		$this->http_mock_body = null;

		$this->reset_setting_dto();

		parent::tearDown();
	}

	// ──────────────────────────────────────────────
	// 排程
	// ──────────────────────────────────────────────

	/**
	 * @testdox schedule 應為訂單排程 payuni_v3_reconcile_pending 事件
	 */
	public function test_schedule_registers_reconcile_action(): void {
		$order = $this->create_pending_v3_order();

		PendingReconciler::schedule( $order );

		$scheduled = $this->is_reconcile_scheduled( $order->get_id() );

		$this->assertTrue( $scheduled, '應排程 payuni_v3_reconcile_pending 事件' );
	}

	/**
	 * @testdox schedule 同一訂單重複呼叫不應排程多筆
	 */
	public function test_schedule_is_deduplicated(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入，跳過去重測試' );
		}

		$order = $this->create_pending_v3_order();

		PendingReconciler::schedule( $order );
		PendingReconciler::schedule( $order );

		$actions = as_get_scheduled_actions(
			[
				'hook'   => PendingReconciler::HOOK,
				'args'   => [ $order->get_id() ],
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ids'
		);

		$this->assertCount( 1, $actions, '同一訂單只應有一筆待處理排程' );
	}

	// ──────────────────────────────────────────────
	// 補償查詢（reconcile）
	// ──────────────────────────────────────────────

	/**
	 * @testdox reconcile 訂單已非 pending 時應略過且不改動狀態
	 */
	public function test_reconcile_skips_when_not_pending(): void {
		$order = $this->create_pending_v3_order();
		$order->update_status( 'processing' );

		// 若 reconcile 誤發 HTTP，這個 mock 會讓它「完成」；用來反證它並未執行查詢。
		$this->mock_query_http( $this->paid_result_payload( $order ) );

		PendingReconciler::reconcile( $order->get_id() );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $reloaded->get_status(), '已非 pending 的訂單狀態不應被更動' );
	}

	/**
	 * @testdox reconcile 查得 TradeStatus=1 時應補完訂單
	 */
	public function test_reconcile_completes_order_when_paid(): void {
		$order = $this->create_pending_v3_order();

		$this->mock_query_http( $this->paid_result_payload( $order ) );

		PendingReconciler::reconcile( $order->get_id() );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertTrue( $reloaded->is_paid(), '查得已付款時訂單應被補完（processing/completed）' );
		$this->assertSame( 'RECON_TRADE_123', $reloaded->get_meta( '_payuni_resp_trade_no' ), '應寫入補償查得的 TradeNo' );
	}

	/**
	 * @testdox reconcile 查得 TradeStatus 非 1 時應維持 pending
	 */
	public function test_reconcile_keeps_pending_when_unpaid(): void {
		$order = $this->create_pending_v3_order();

		$unpaid = [
			'Result' => [
				[
					'MerTradeNo'  => (string) $order->get_order_number(),
					'TradeNo'     => '',
					'TradeStatus' => '0',
				],
			],
		];
		$this->mock_query_http( $unpaid );

		PendingReconciler::reconcile( $order->get_id() );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $reloaded->get_status(), '交易尚未成功時應維持 pending' );
		$this->assertFalse( $reloaded->is_paid(), '未付款訂單不應被標記為已付款' );
	}

	/**
	 * @testdox reconcile 查詢失敗（HTTP 錯誤）時應安全降級維持 pending
	 */
	public function test_reconcile_degrades_safely_on_http_error(): void {
		$order = $this->create_pending_v3_order();

		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'http_request_failed', '模擬連線失敗' );
			},
			10,
			3
		);

		PendingReconciler::reconcile( $order->get_id() );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $reloaded->get_status(), '查詢失敗不應改動訂單狀態' );

		remove_all_filters( 'pre_http_request' );
	}

	// ──────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────

	/**
	 * 建立一筆待付款的 PayUni v3 信用卡訂單。
	 *
	 * @return \WC_Order
	 */
	private function create_pending_v3_order(): \WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( \PAYUNI\Gateways\CreditV3::ID );
		$order->set_total( 100 );
		$order->set_status( 'pending' );
		$order->save();

		return $order;
	}

	/**
	 * 組出「已付款」的 /trade/query 解密結果 payload。
	 *
	 * @param \WC_Order $order 訂單物件
	 *
	 * @return array
	 */
	private function paid_result_payload( \WC_Order $order ): array {
		return [
			'Result' => [
				[
					'MerTradeNo'  => (string) $order->get_order_number(),
					'TradeNo'     => 'RECON_TRADE_123',
					'TradeStatus' => '1',
					'Card4No'     => '4242',
					'TradeAmt'    => '100',
				],
			],
		];
	}

	/**
	 * 掛上 pre_http_request 攔截，讓 /trade/query 回傳指定的加密結果。
	 *
	 * @param array $decrypted_payload 解密後應得到的 payload（含 Result）
	 *
	 * @return void
	 */
	private function mock_query_http( array $decrypted_payload ): void {
		$encrypt_info = EncryptUtils::encrypt( $decrypted_payload );

		$this->http_mock_body = wp_json_encode(
			[
				'Status'      => 'SUCCESS',
				'MerID'       => 'TEST_MERCHANT',
				'Version'     => '2.0',
				'EncryptInfo' => $encrypt_info,
				'HashInfo'    => EncryptUtils::hash_info( $encrypt_info ),
			]
		);

		add_filter( 'pre_http_request', [ $this, 'filter_http' ], 10, 3 );
	}

	/**
	 * pre_http_request 回呼：僅攔截 /trade/query。
	 *
	 * @param mixed  $pre  短路值
	 * @param array  $args 請求參數
	 * @param string $url  請求網址
	 *
	 * @return mixed
	 */
	public function filter_http( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'trade/query' ) ) {
			return $pre;
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
	 * 判斷指定訂單是否已排程補償對帳（相容 Action Scheduler 與 wp-cron）。
	 *
	 * @param int $order_id 訂單 ID
	 *
	 * @return bool
	 */
	private function is_reconcile_scheduled( int $order_id ): bool {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			if ( false !== as_next_scheduled_action( PendingReconciler::HOOK, [ $order_id ] ) ) {
				return true;
			}
		}

		return false !== wp_next_scheduled( PendingReconciler::HOOK, [ $order_id ] );
	}

	/**
	 * 重置 SettingDTO 單例。
	 *
	 * @return void
	 */
	private function reset_setting_dto(): void {
		$ref  = new \ReflectionClass( \J7\Payuni\Contracts\DTOs\SettingDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}
}

<?php
/**
 * HPOS 訂單 Meta 讀寫整合測試
 *
 * 驗證在 HPOS 模式下，各金流回調、發票、物流、訂閱模組
 * 使用 WC_Order 物件 API（而非 get_post_meta / update_post_meta）
 * 正確讀寫訂單 meta。
 *
 * 對應規格：specs/features/hpos/訂單Meta讀寫.feature
 *
 * @package Woomp\Tests\Integration
 */

/**
 * HPOS 訂單 Meta 讀寫測試類別
 *
 * @covers includes/paynow-payment/
 * @covers includes/line-pay-for-woo/
 * @covers includes/PChomePay-Cart-for-WooCommerce/
 * @covers includes/woomp-ecpay-invoice/
 * @covers includes/woomp-ezpay-invoice/
 * @covers includes/paynow-einvoice/
 * @covers includes/paynow-shipping/
 * @group hpos
 * @group hpos-compat
 * @group order-meta
 */
final class HposOrderMetaTest extends WP_UnitTestCase {

	/**
	 * 測試用 WooCommerce 訂單
	 *
	 * @var WC_Order|null
	 */
	private $order;

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce 未載入，跳過 HPOS 訂單 Meta 測試' );
		}

		// 建立測試訂單。
		$this->order = wc_create_order();
		$this->order->set_status( 'pending' );
		$this->order->set_total( 1000 );
		$this->order->save();
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		if ( $this->order instanceof WC_Order ) {
			$this->order->delete( true );
		}
		parent::tearDown();
	}

	// ========================================================================
	// Rule: 金流回調 Meta 寫入（核心路徑）
	// ========================================================================

	/**
	 * 測試 PayNow 金流回調 meta 透過 WC_Order API 正確寫入
	 *
	 * 規格：PayNow 金流回調觸發，寫入 _paynow_tran_no meta。
	 *
	 * @testdox 驗證 PayNow 交易編號 meta 透過 WC_Order API 正確寫入與讀取
	 */
	public function test_paynow_meta_write_via_order_api() {
		$meta_key   = '_paynow_tran_no';
		$meta_value = 'PN20240101001';

		$this->order->update_meta_data( $meta_key, $meta_value );
		$this->order->save();

		// 重新讀取訂單，驗證 meta 已正確寫入。
		$reloaded = wc_get_order( $this->order->get_id() );

		$this->assertSame(
			$meta_value,
			$reloaded->get_meta( $meta_key ),
			'PayNow 交易編號應透過 WC_Order API 正確儲存與讀取'
		);
	}

	/**
	 * 測試 LINE Pay 金流回調 meta 透過 WC_Order API 正確寫入
	 *
	 * 規格：LINE Pay 金流回調觸發，寫入 _linepay_transaction_id meta。
	 *
	 * @testdox 驗證 LINE Pay 交易 ID meta 透過 WC_Order API 正確寫入與讀取
	 */
	public function test_linepay_meta_write_via_order_api() {
		$meta_key   = '_linepay_transaction_id';
		$meta_value = 'LP2024010100001';

		$this->order->update_meta_data( $meta_key, $meta_value );
		$this->order->save();

		$reloaded = wc_get_order( $this->order->get_id() );

		$this->assertSame(
			$meta_value,
			$reloaded->get_meta( $meta_key ),
			'LINE Pay 交易 ID 應透過 WC_Order API 正確儲存與讀取'
		);
	}

	/**
	 * 測試 PChomePay 金流回調 meta 透過 WC_Order API 正確寫入
	 *
	 * 規格：PChomePay 金流回調觸發，寫入 _pchomepay_transaction_id meta。
	 *
	 * @testdox 驗證 PChomePay 交易 ID meta 透過 WC_Order API 正確寫入與讀取
	 */
	public function test_pchomepay_meta_write_via_order_api() {
		$meta_key   = '_pchomepay_transaction_id';
		$meta_value = 'PC2024010100001';

		$this->order->update_meta_data( $meta_key, $meta_value );
		$this->order->save();

		$reloaded = wc_get_order( $this->order->get_id() );

		$this->assertSame(
			$meta_value,
			$reloaded->get_meta( $meta_key ),
			'PChomePay 交易 ID 應透過 WC_Order API 正確儲存與讀取'
		);
	}

	// ========================================================================
	// Rule: 發票 Meta 讀寫
	// ========================================================================

	/**
	 * 測試綠界發票號碼 meta 透過 WC_Order API 正確寫入
	 *
	 * 規格：綠界發票 API 回傳成功，_ecpay_invoice_number 透過 $order->update_meta_data() 儲存。
	 *
	 * @testdox 驗證綠界發票號碼 meta 透過 WC_Order API 正確寫入且格式正確
	 */
	public function test_ecpay_invoice_meta_write_via_order_api() {
		$meta_key   = '_ecpay_invoice_number';
		$meta_value = 'AB12345678';

		$this->order->update_meta_data( $meta_key, $meta_value );
		$this->order->save();

		$reloaded = wc_get_order( $this->order->get_id() );

		$this->assertSame(
			$meta_value,
			$reloaded->get_meta( $meta_key ),
			'綠界發票號碼應透過 WC_Order API 正確儲存'
		);

		// 驗證發票號碼格式（2 碼英文 + 8 碼數字）。
		$this->assertMatchesRegularExpression(
			'/^[A-Z]{2}\d{8}$/',
			$reloaded->get_meta( $meta_key ),
			'綠界發票號碼格式應為 2 碼英文 + 8 碼數字'
		);
	}

	/**
	 * 測試 EZPAY 發票 meta 透過 WC_Order API 正確寫入
	 *
	 * @testdox 驗證 EZPAY 發票相關 meta 透過 WC_Order API 正確寫入與讀取
	 */
	public function test_ezpay_invoice_meta_write_via_order_api() {
		$invoice_meta = [
			'_ezpay_invoice_number' => 'CD87654321',
			'_ezpay_invoice_date'   => '2024-06-15',
			'_ezpay_invoice_status' => 'issued',
		];

		foreach ( $invoice_meta as $key => $value ) {
			$this->order->update_meta_data( $key, $value );
		}
		$this->order->save();

		$reloaded = wc_get_order( $this->order->get_id() );

		foreach ( $invoice_meta as $key => $expected ) {
			$this->assertSame(
				$expected,
				$reloaded->get_meta( $key ),
				"EZPAY 發票 meta {$key} 應為 {$expected}"
			);
		}
	}

	/**
	 * 測試立吉富發票載具資訊 meta 透過 WC_Order API 正確讀取
	 *
	 * 規格：使用 $order->get_meta('_paynow_ei_carrier_type') 取得 "phone_barcode"。
	 *
	 * @testdox 驗證立吉富發票載具類型 meta 透過 WC_Order API 正確讀取
	 */
	public function test_paynow_invoice_carrier_meta_read_via_order_api() {
		$meta_key   = '_paynow_ei_carrier_type';
		$meta_value = 'phone_barcode';

		$this->order->update_meta_data( $meta_key, $meta_value );
		$this->order->save();

		$reloaded = wc_get_order( $this->order->get_id() );

		$this->assertSame(
			$meta_value,
			$reloaded->get_meta( $meta_key ),
			'立吉富發票載具類型應透過 WC_Order API 正確讀取'
		);
	}

	/**
	 * 測試立吉富發票完整 meta 資料透過 WC_Order API 讀寫
	 *
	 * @testdox 驗證立吉富發票完整 meta 資料（載具、捐贈碼、統編、發票號碼）透過 WC_Order API 讀寫
	 */
	public function test_paynow_invoice_full_meta_via_order_api() {
		$invoice_meta = [
			'_paynow_ei_carrier_type' => 'phone_barcode',
			'_paynow_ei_carrier_num'  => '/ABC+123',
			'_paynow_ei_love_code'    => '',
			'_paynow_ei_company_ban'  => '',
			'_paynow_ei_invoice_no'   => 'EF99887766',
		];

		foreach ( $invoice_meta as $key => $value ) {
			$this->order->update_meta_data( $key, $value );
		}
		$this->order->save();

		$reloaded = wc_get_order( $this->order->get_id() );

		foreach ( $invoice_meta as $key => $expected ) {
			$this->assertSame(
				$expected,
				$reloaded->get_meta( $key ),
				"立吉富發票 meta {$key} 應為 '{$expected}'"
			);
		}
	}

	// ========================================================================
	// Rule: 物流 Meta 讀寫
	// ========================================================================

	/**
	 * 測試物流單號透過 WC_Order API 更新
	 *
	 * 規格：使用 $order->update_meta_data('wmp_shipping_no', 'TRACK001') + $order->save()，
	 *       非使用 update_post_meta()。
	 *
	 * @testdox 驗證物流單號 meta 透過 WC_Order API 正確寫入
	 */
	public function test_shipping_tracking_meta_write_via_order_api() {
		$meta_key   = 'wmp_shipping_no';
		$meta_value = 'TRACK001';

		$this->order->update_meta_data( $meta_key, $meta_value );
		$this->order->save();

		$reloaded = wc_get_order( $this->order->get_id() );

		$this->assertSame(
			$meta_value,
			$reloaded->get_meta( $meta_key ),
			'物流單號應透過 WC_Order API 正確儲存'
		);
	}

	/**
	 * 測試綠界物流 meta 透過 WC_Order API 讀寫
	 *
	 * @testdox 驗證綠界物流 meta（單號、類型、門市代號）透過 WC_Order API 正確讀寫
	 */
	public function test_ecpay_shipping_meta_via_order_api() {
		$shipping_meta = [
			'_ecpay_shipping_no'   => 'ECSHIP001',
			'_ecpay_shipping_type' => 'FAMI',
			'_ecpay_cvs_store_id'  => '006789',
		];

		foreach ( $shipping_meta as $key => $value ) {
			$this->order->update_meta_data( $key, $value );
		}
		$this->order->save();

		$reloaded = wc_get_order( $this->order->get_id() );

		foreach ( $shipping_meta as $key => $expected ) {
			$this->assertSame(
				$expected,
				$reloaded->get_meta( $key ),
				"綠界物流 meta {$key} 應為 {$expected}"
			);
		}
	}

	// ========================================================================
	// Rule: 訂閱 (shop_subscription) Meta 讀寫
	// ========================================================================

	/**
	 * 測試訂閱 meta delete_meta_data 透過物件 API 操作
	 *
	 * 規格：使用 $subscription->delete_meta_data('_schedule_cancelled') + $subscription->save()，
	 *       非使用 delete_post_meta($subscription_id, '_schedule_cancelled')。
	 *
	 * 注意：在沒有 WC_Subscriptions 的環境中，使用一般訂單模擬此行為。
	 *
	 * @testdox 驗證訂閱 meta 透過物件 API delete_meta_data() 正確刪除
	 */
	public function test_subscription_meta_delete_via_object_api() {
		// 在測試環境中模擬：使用訂單的 delete_meta_data 驗證 API 行為。
		$meta_key = '_schedule_cancelled';

		$this->order->update_meta_data( $meta_key, '2024-12-31' );
		$this->order->save();

		// 確認 meta 存在。
		$reloaded = wc_get_order( $this->order->get_id() );
		$this->assertSame(
			'2024-12-31',
			$reloaded->get_meta( $meta_key ),
			'meta 應先成功寫入'
		);

		// 透過物件 API 刪除 meta。
		$reloaded->delete_meta_data( $meta_key );
		$reloaded->save();

		// 重新讀取驗證已刪除。
		$after_delete = wc_get_order( $this->order->get_id() );
		$this->assertEmpty(
			$after_delete->get_meta( $meta_key ),
			'透過 $order->delete_meta_data() 刪除後，meta 應為空'
		);
	}

	/**
	 * 測試訂閱發票 meta 透過物件 API 讀寫
	 *
	 * 規格：管理員在訂閱編輯頁的發票管理 Metabox 中讀取或寫入發票欄位 meta，
	 *       使用 $subscription->get_meta() / $subscription->update_meta_data()。
	 *
	 * @testdox 驗證訂閱發票 meta 透過物件 API 正確讀寫
	 */
	public function test_subscription_invoice_meta_via_object_api() {
		$invoice_meta = [
			'_invoice_type'       => 'personal',
			'_invoice_carrier'    => '/PHONE123',
			'_invoice_love_code'  => '',
		];

		foreach ( $invoice_meta as $key => $value ) {
			$this->order->update_meta_data( $key, $value );
		}
		$this->order->save();

		$reloaded = wc_get_order( $this->order->get_id() );

		foreach ( $invoice_meta as $key => $expected ) {
			$this->assertSame(
				$expected,
				$reloaded->get_meta( $key ),
				"訂閱發票 meta {$key} 應透過物件 API 正確讀取"
			);
		}
	}

	// ========================================================================
	// Rule: 多個 meta 同時寫入與讀取的一致性
	// ========================================================================

	/**
	 * 測試多個不同模組的 meta 在同一訂單上的寫入與讀取一致性
	 *
	 * 邊緣案例：同一訂單同時有金流、發票、物流 meta，確保不會互相覆蓋。
	 *
	 * @testdox 驗證多模組 meta（金流、發票、物流）在同一訂單上共存不互相覆蓋
	 */
	public function test_multiple_module_meta_coexistence() {
		$all_meta = [
			// 金流。
			'_paynow_tran_no'         => 'PN001',
			'_payuni_trade_no'        => 'PU001',
			// 發票。
			'_ecpay_invoice_number'   => 'AA11111111',
			'_paynow_ei_carrier_type' => 'natural_person',
			// 物流。
			'wmp_shipping_no'         => 'SHIP001',
			'_ecpay_shipping_no'      => 'ECSHIP001',
		];

		foreach ( $all_meta as $key => $value ) {
			$this->order->update_meta_data( $key, $value );
		}
		$this->order->save();

		$reloaded = wc_get_order( $this->order->get_id() );

		foreach ( $all_meta as $key => $expected ) {
			$this->assertSame(
				$expected,
				$reloaded->get_meta( $key ),
				"多模組共存時，meta {$key} 應為 {$expected}"
			);
		}
	}

	/**
	 * 測試 meta 值覆寫行為正確
	 *
	 * 邊緣案例：同一 meta key 被更新多次，只保留最後一次的值。
	 *
	 * @testdox 驗證同一 meta key 多次更新後只保留最後一次的值
	 */
	public function test_meta_overwrite_keeps_latest_value() {
		$meta_key = '_paynow_tran_no';

		$this->order->update_meta_data( $meta_key, 'FIRST_VALUE' );
		$this->order->save();

		// 再次更新。
		$order_again = wc_get_order( $this->order->get_id() );
		$order_again->update_meta_data( $meta_key, 'SECOND_VALUE' );
		$order_again->save();

		$reloaded = wc_get_order( $this->order->get_id() );

		$this->assertSame(
			'SECOND_VALUE',
			$reloaded->get_meta( $meta_key ),
			'多次更新同一 meta key 後，應保留最後一次的值'
		);
	}

	// ========================================================================
	// Rule: 掃描測試 — 確認 Compatibility.php 中 _paynow_ei_* meta key 存在
	// ========================================================================

	/**
	 * 掃描測試：確認 Compatibility.php 中 _paynow_ei_* meta key 存在
	 *
	 * 驗證 Compatibility.php 的 delete_post_meta() 方法中包含
	 * _paynow_ei_* 系列 meta key 的靜態程式碼定義。
	 *
	 * @testdox 掃描確認 Compatibility.php 中定義 _paynow_ei_* meta key
	 */
	public function test_paynow_ei_meta_keys_exist_in_compatibility() {
		$plugin_dir = defined( 'WOOMP_PLUGIN_DIR' ) ? WOOMP_PLUGIN_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';

		$compatibility_file = $plugin_dir . 'Compatibility.php';

		if ( ! file_exists( $compatibility_file ) ) {
			$this->markTestSkipped( 'Compatibility.php 檔案不存在' );
		}

		$content = file_get_contents( $compatibility_file );

		$expected_meta_keys = [
			'_paynow_ei_issue_type',
			'_paynow_ei_carrier_type',
			'_paynow_ei_buyer_name',
			'_paynow_ei_ubn',
			'_paynow_ei_carrier_num',
			'_paynow_ei_donate_org',
		];

		foreach ( $expected_meta_keys as $meta_key ) {
			$this->assertStringContainsString(
				$meta_key,
				$content,
				"Compatibility.php 應包含 meta key: {$meta_key}"
			);
		}
	}

	// ========================================================================
	// Rule: 掃描測試 — 確認全專案無 get_post_meta 用於訂單 context
	// ========================================================================

	/**
	 * 掃描測試：確認全專案無 get_post_meta 用於訂單/訂閱 context
	 *
	 * 規格：掃描所有 PHP 檔案（排除 vendor/ 和商品 context），
	 *       無任何 get_post_meta / update_post_meta / add_post_meta / delete_post_meta
	 *       用於訂單或訂閱 ID。
	 *
	 * 排除目錄：vendor/、tests/、payuni/v3/（已 HPOS 相容）
	 * 排除情境：商品 (product) context 的 meta 操作（不受 HPOS 影響）
	 *
	 * @testdox 掃描確認全專案無 post meta 函式用於訂單 context
	 */
	public function test_no_post_meta_functions_for_order_context() {
		$plugin_dir = defined( 'WOOMP_PLUGIN_DIR' ) ? WOOMP_PLUGIN_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';

		// 需要掃描的目錄。
		$scan_dirs = [
			$plugin_dir . 'admin/',
			$plugin_dir . 'public/',
			$plugin_dir . 'includes/',
		];

		// 排除的路徑模式。
		$exclude_patterns = [
			'/vendor/',
			'/tests/',
			'/payuni/v3/',     // PayUni v3 已 HPOS 相容。
			'/node_modules/',
		];

		// 訂單相關 meta key pattern（用於判斷是否為訂單 context）。
		$order_meta_patterns = [
			'_paynow_',
			'_linepay_',
			'_pchomepay_',
			'_ecpay_invoice',
			'_ecpay_shipping',
			'_ezpay_invoice',
			'wmp_shipping',
			'_payuni_',
			'_transaction_id',
			'_billing_',
			'_shipping_',
			'shop_order',
			'order_id',
			'$order_id',
			'$subscription_id',
		];

		// 禁止的函式。
		$forbidden_functions = [
			'get_post_meta',
			'update_post_meta',
			'add_post_meta',
			'delete_post_meta',
		];

		$violations = [];

		foreach ( $scan_dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( $file->getExtension() !== 'php' ) {
					continue;
				}

				$filepath = $file->getPathname();

				// 檢查排除路徑。
				$excluded = false;
				foreach ( $exclude_patterns as $pattern ) {
					if ( strpos( $filepath, $pattern ) !== false ) {
						$excluded = true;
						break;
					}
				}
				if ( $excluded ) {
					continue;
				}

				$content = file_get_contents( $filepath );
				$lines   = explode( "\n", $content );

				foreach ( $lines as $line_num => $line ) {
					foreach ( $forbidden_functions as $func ) {
						if ( strpos( $line, $func ) === false ) {
							continue;
						}

						// 檢查此行或前後 5 行是否有訂單 context 暗示。
						$context_start = max( 0, $line_num - 5 );
						$context_end   = min( count( $lines ) - 1, $line_num + 5 );
						$context_block = implode( "\n", array_slice( $lines, $context_start, $context_end - $context_start + 1 ) );

						$is_order_context = false;
						foreach ( $order_meta_patterns as $meta_pattern ) {
							if ( stripos( $context_block, $meta_pattern ) !== false ) {
								$is_order_context = true;
								break;
							}
						}

						if ( $is_order_context ) {
							$relative_path = str_replace( $plugin_dir, '', $filepath );
							$violations[]  = sprintf(
								'%s (行 %d): %s() 用於訂單 context',
								$relative_path,
								$line_num + 1,
								$func
							);
						}
					}
				}
			}
		}

		$this->assertEmpty(
			$violations,
			"以下位置使用了 post meta 函式操作訂單/訂閱資料，應改用 WC_Order API：\n" . implode( "\n", $violations )
		);
	}

	/**
	 * 掃描測試：確認全專案無直接 SQL 操作訂單 meta
	 *
	 * 邊緣案例：除了 PHP 函式外，也要檢查是否有直接 SQL 操作 postmeta 表。
	 * 排除：Compatibility.php 和 class.ry-wt.update.php（已決定保留不動）。
	 *
	 * @testdox 掃描確認全專案無直接 SQL 操作訂單 postmeta 表
	 */
	public function test_no_direct_sql_for_order_meta() {
		$plugin_dir = defined( 'WOOMP_PLUGIN_DIR' ) ? WOOMP_PLUGIN_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';

		$scan_dirs = [
			$plugin_dir . 'admin/',
			$plugin_dir . 'public/',
			$plugin_dir . 'includes/',
		];

		$exclude_patterns = [
			'/vendor/',
			'/tests/',
			'/payuni/v3/',
			'/node_modules/',
			'Compatibility.php',
			'class.ry-wt.update.php',
		];

		// 搜尋直接操作 postmeta 且與訂單相關的 SQL。
		$sql_pattern = '/\$wpdb->.*postmeta.*(?:order|shop_order|_paynow_|_linepay_|_ecpay_|_payuni_)/i';

		$violations = [];

		foreach ( $scan_dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( $file->getExtension() !== 'php' ) {
					continue;
				}

				$filepath = $file->getPathname();

				$excluded = false;
				foreach ( $exclude_patterns as $pattern ) {
					if ( strpos( $filepath, $pattern ) !== false ) {
						$excluded = true;
						break;
					}
				}
				if ( $excluded ) {
					continue;
				}

				$content = file_get_contents( $filepath );
				if ( preg_match_all( $sql_pattern, $content, $matches ) ) {
					$relative_path = str_replace( $plugin_dir, '', $filepath );
					foreach ( $matches[0] as $match ) {
						$violations[] = sprintf( '%s: %s', $relative_path, trim( $match ) );
					}
				}
			}
		}

		$this->assertEmpty(
			$violations,
			"以下位置使用了直接 SQL 操作訂單 meta，應改用 WC_Order API：\n" . implode( "\n", $violations )
		);
	}
}

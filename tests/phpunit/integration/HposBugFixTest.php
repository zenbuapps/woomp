<?php
/**
 * HPOS 遷移既有 Bug 修復整合測試
 *
 * 驗證在 HPOS 遷移過程中發現並修復的既有 Bug：
 * - LINE Pay post_type 判斷使用 === 不是 =
 * - 退款刪除使用 WC API 不是 wp_delete_post
 * - 訂單取得使用 wc_get_order 不是 new WC_Order()
 * - Meta box callback 不依賴 global $post
 *
 * 對應規格：specs/features/hpos/修復既有Bug.feature
 *
 * @package Woomp\Tests\Integration
 */

/**
 * HPOS Bug 修復測試類別
 *
 * @covers includes/line-pay-for-woo/
 * @group hpos
 * @group hpos-compat
 */
final class HposBugFixTest extends WP_UnitTestCase {

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce 未載入，跳過 HPOS Bug 修復測試' );
		}
	}

	// ========================================================================
	// Rule: LINE Pay 賦值 Bug — post_type 判斷使用 === 不是 =
	// ========================================================================

	/**
	 * 掃描測試：確認 LINE Pay 模組中無 post_type 賦值 bug
	 *
	 * 規格：使用 === 比較運算子（而非 = 賦值）。
	 *
	 * 搜尋 pattern：$something = 'shop_order'（應為 === 或 ==）
	 * 注意：要排除正常的變數賦值（如 $post_type = 'shop_order';）
	 *
	 * @testdox 掃描確認 LINE Pay 模組中無 post_type 賦值 bug（= 而非 ===）
	 */
	public function test_linepay_no_assignment_instead_of_comparison() {
		$plugin_dir = defined( 'WOOMP_PLUGIN_DIR' ) ? WOOMP_PLUGIN_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';
		$linepay_dir = $plugin_dir . 'includes/line-pay-for-woo/';

		if ( ! is_dir( $linepay_dir ) ) {
			$this->markTestSkipped( 'LINE Pay 模組目錄不存在' );
		}

		$violations = [];

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $linepay_dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->getExtension() !== 'php' ) {
				continue;
			}

			$content = file_get_contents( $file->getPathname() );
			$lines   = explode( "\n", $content );

			foreach ( $lines as $line_num => $line ) {
				// 搜尋在 if/elseif 條件中使用 = 而非 == 或 === 比較 shop_order 的 pattern。
				// 例如：if ( $post_type = 'shop_order' )  ← 這是 bug（賦值而非比較）。
				if ( preg_match( '/\bif\s*\(.*[^=!<>]=[^=].*[\'"]shop_order[\'"]/', $line ) ) {
					$relative_path = str_replace( $plugin_dir, '', $file->getPathname() );
					$violations[]  = sprintf(
						'%s (行 %d): 可能的賦值 bug（= 而非 ===）：%s',
						$relative_path,
						$line_num + 1,
						trim( $line )
					);
				}

				// 也檢查反向 pattern：if ( 'shop_order' = $something )。
				if ( preg_match( '/\bif\s*\(.*[\'"]shop_order[\'"]\s*=[^=]/', $line ) ) {
					$relative_path = str_replace( $plugin_dir, '', $file->getPathname() );
					$violations[]  = sprintf(
						'%s (行 %d): 可能的賦值 bug（= 而非 ===）：%s',
						$relative_path,
						$line_num + 1,
						trim( $line )
					);
				}
			}
		}

		$this->assertEmpty(
			$violations,
			"LINE Pay 模組中發現 post_type 賦值 bug（使用 = 而非 ===）：\n" . implode( "\n", $violations )
		);
	}

	/**
	 * 掃描測試：確認 LINE Pay 模組同時支援 HPOS screen 判斷
	 *
	 * 規格：「同時支援 HPOS screen 判斷」。
	 *
	 * @testdox 掃描確認 LINE Pay 模組包含 HPOS screen 判斷支援
	 */
	public function test_linepay_supports_hpos_screen_detection() {
		$plugin_dir  = defined( 'WOOMP_PLUGIN_DIR' ) ? WOOMP_PLUGIN_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';
		$linepay_dir = $plugin_dir . 'includes/line-pay-for-woo/';

		if ( ! is_dir( $linepay_dir ) ) {
			$this->markTestSkipped( 'LINE Pay 模組目錄不存在' );
		}

		// 搜尋是否有 HPOS screen 判斷相關的程式碼。
		$hpos_patterns = [
			'wc-orders',
			'woocommerce_page_wc-orders',
			'Woomp_HPOS_Helper',
			'OrderUtil::custom_orders_table_usage_is_enabled',
		];

		$has_hpos_support = false;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $linepay_dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->getExtension() !== 'php' ) {
				continue;
			}

			$content = file_get_contents( $file->getPathname() );

			foreach ( $hpos_patterns as $pattern ) {
				if ( strpos( $content, $pattern ) !== false ) {
					$has_hpos_support = true;
					break 2;
				}
			}
		}

		$this->assertTrue(
			$has_hpos_support,
			'LINE Pay 模組應包含 HPOS screen 判斷支援（wc-orders / Woomp_HPOS_Helper / OrderUtil）'
		);
	}

	// ========================================================================
	// Rule: 退款刪除 — 使用 WC API 不是 wp_delete_post
	// ========================================================================

	/**
	 * 掃描測試：確認無 wp_delete_post 用於退款
	 *
	 * 規格：使用 $refund->delete(true)（WC_Order_Refund API），
	 *       非使用 wp_delete_post($refund->id, true)（HPOS 下退款非 post，會失效）。
	 *
	 * @testdox 掃描確認無 wp_delete_post() 用於退款 context
	 */
	public function test_no_wp_delete_post_for_refunds() {
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
					// 搜尋 wp_delete_post 且前後文包含 refund 相關字串。
					if ( strpos( $line, 'wp_delete_post' ) === false ) {
						continue;
					}

					// 檢查前後 10 行是否有 refund 相關字串。
					$context_start = max( 0, $line_num - 10 );
					$context_end   = min( count( $lines ) - 1, $line_num + 10 );
					$context_block = implode( "\n", array_slice( $lines, $context_start, $context_end - $context_start + 1 ) );

					$refund_keywords = [ 'refund', 'Refund', 'REFUND', '$refund', 'refund_id' ];
					$is_refund_context = false;

					foreach ( $refund_keywords as $keyword ) {
						if ( strpos( $context_block, $keyword ) !== false ) {
							$is_refund_context = true;
							break;
						}
					}

					if ( $is_refund_context ) {
						$relative_path = str_replace( $plugin_dir, '', $filepath );
						$violations[]  = sprintf(
							'%s (行 %d): wp_delete_post() 用於退款 context（應改用 $refund->delete(true)）',
							$relative_path,
							$line_num + 1
						);
					}
				}
			}
		}

		$this->assertEmpty(
			$violations,
			"以下位置使用 wp_delete_post 處理退款（HPOS 下退款非 post，會失效）：\n"
			. implode( "\n", $violations )
		);
	}

	/**
	 * 測試退款刪除使用 WC_Order_Refund API 正常運作
	 *
	 * 在 HPOS 下，退款儲存在 wc_orders 表而非 posts 表，
	 * 必須使用 WC API 才能正確刪除。
	 *
	 * @testdox 驗證退款透過 WC_Order_Refund API delete() 正確刪除
	 */
	public function test_refund_deletion_via_wc_api() {
		$order = wc_create_order();
		$order->set_total( 1000 );
		$order->set_status( 'completed' );
		$order->save();

		// 建立退款。
		$refund = wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => 500,
				'reason'   => '測試退款',
			]
		);

		$this->assertInstanceOf(
			'WC_Order_Refund',
			$refund,
			'應成功建立退款物件'
		);

		$refund_id = $refund->get_id();

		// 使用 WC API 刪除退款。
		$refund->delete( true );

		// 驗證退款已被刪除。
		$deleted_refund = wc_get_order( $refund_id );

		$this->assertFalse(
			$deleted_refund,
			'退款透過 $refund->delete(true) 刪除後，wc_get_order 應回傳 false'
		);

		// 清理。
		$order->delete( true );
	}

	// ========================================================================
	// Rule: 過時的訂單物件建立方式 — new WC_Order() → wc_get_order()
	// ========================================================================

	/**
	 * 掃描測試：確認無 new WC_Order() 出現
	 *
	 * 規格：使用 wc_get_order($order_id)（回傳正確的子類型），
	 *       非使用 new WC_Order($order_id)（過時寫法）。
	 *
	 * @testdox 掃描確認無 new WC_Order() 過時建構方式
	 */
	public function test_no_new_wc_order_constructor() {
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
		];

		// 搜尋 new WC_Order( 或 new \WC_Order(。
		// 排除 new WC_Order()（無參數的空建構，WC 內部可能使用）。
		$pattern = '/new\s+\\\\?WC_Order\s*\(\s*\$/';

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
				foreach ( $exclude_patterns as $pattern_excl ) {
					if ( strpos( $filepath, $pattern_excl ) !== false ) {
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
					if ( preg_match( $pattern, $line ) ) {
						$relative_path = str_replace( $plugin_dir, '', $filepath );
						$violations[]  = sprintf(
							'%s (行 %d): new WC_Order($...) — 應改用 wc_get_order($order_id)',
							$relative_path,
							$line_num + 1
						);
					}
				}
			}
		}

		$this->assertEmpty(
			$violations,
			"以下位置使用了 new WC_Order(\$id)（過時寫法），應改用 wc_get_order()：\n"
			. implode( "\n", $violations )
		);
	}

	/**
	 * 測試 wc_get_order() 回傳正確的訂單子類型
	 *
	 * 驗證 wc_get_order 比 new WC_Order 更適合使用的原因：
	 * 它能回傳正確的子類型（WC_Order_Refund 等）。
	 *
	 * @testdox 驗證 wc_get_order() 回傳正確的訂單子類型（WC_Order_Refund）
	 */
	public function test_wc_get_order_returns_correct_subtype() {
		$order = wc_create_order();
		$order->set_total( 1000 );
		$order->set_status( 'completed' );
		$order->save();

		// 建立退款。
		$refund = wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => 200,
				'reason'   => '測試子類型',
			]
		);

		// wc_get_order 應回傳 WC_Order_Refund 而非 WC_Order。
		$fetched_refund = wc_get_order( $refund->get_id() );

		$this->assertInstanceOf(
			'WC_Order_Refund',
			$fetched_refund,
			'wc_get_order() 應回傳正確的子類型 WC_Order_Refund'
		);

		// wc_get_order 對一般訂單應回傳 WC_Order。
		$fetched_order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf(
			'WC_Order',
			$fetched_order,
			'wc_get_order() 應回傳 WC_Order 實例'
		);

		// 清理。
		$refund->delete( true );
		$order->delete( true );
	}

	// ========================================================================
	// Rule: global $post 在訂單 context 中的使用
	// ========================================================================

	/**
	 * 掃描測試：確認無 global $post 在訂單相關 meta box callback 中
	 *
	 * 規格：Meta Box callback 不依賴 global $post，
	 *       使用 Woomp_HPOS_Helper::get_order($post_or_order) 從 callback 參數取得。
	 *       global $post 在 HPOS 下可能為 null。
	 *
	 * @testdox 掃描確認訂單 meta box callback 中無 global $post 使用
	 */
	public function test_no_global_post_in_order_meta_box_callbacks() {
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

				// 先找出檔案中是否有 add_meta_box 呼叫（表示這是 meta box 相關檔案）。
				$has_meta_box = false;
				foreach ( $lines as $line ) {
					if ( strpos( $line, 'add_meta_box' ) !== false ) {
						$has_meta_box = true;
						break;
					}
				}

				if ( ! $has_meta_box ) {
					continue;
				}

				// 在有 add_meta_box 的檔案中搜尋 global $post。
				foreach ( $lines as $line_num => $line ) {
					if ( preg_match( '/global\s+\$post\b/', $line ) ) {
						// 檢查此 global $post 是否在訂單相關的 context 中。
						$context_start = max( 0, $line_num - 20 );
						$context_end   = min( count( $lines ) - 1, $line_num + 20 );
						$context_block = implode( "\n", array_slice( $lines, $context_start, $context_end - $context_start + 1 ) );

						$order_keywords = [
							'shop_order',
							'order',
							'wc-orders',
							'invoice',
							'shipping',
							'payment',
							'meta_box',
							'metabox',
						];

						$is_order_context = false;
						foreach ( $order_keywords as $keyword ) {
							if ( stripos( $context_block, $keyword ) !== false ) {
								$is_order_context = true;
								break;
							}
						}

						if ( $is_order_context ) {
							$relative_path = str_replace( $plugin_dir, '', $filepath );
							$violations[]  = sprintf(
								'%s (行 %d): global $post 在訂單 meta box context 中使用（HPOS 下可能為 null）',
								$relative_path,
								$line_num + 1
							);
						}
					}
				}
			}
		}

		$this->assertEmpty(
			$violations,
			"以下位置在訂單相關 meta box 中使用了 global \$post（HPOS 下可能為 null，應改用 Woomp_HPOS_Helper::get_order）：\n"
			. implode( "\n", $violations )
		);
	}

	/**
	 * 掃描測試：確認 meta box callback 檔案使用 Woomp_HPOS_Helper 或型別判斷
	 *
	 * 規格：模組使用型別判斷取得 order ID 和 meta。
	 *
	 * @testdox 掃描確認 meta box callback 使用 Woomp_HPOS_Helper 或型別判斷
	 */
	public function test_meta_box_callbacks_use_type_checking_or_helper() {
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
		];

		// 在有 add_meta_box 的檔案中，應該要有以下其中一種 pattern。
		$helper_patterns = [
			'Woomp_HPOS_Helper',
			'instanceof WC_Order',
			'instanceof \\WC_Order',
			'is_a(',
			'$post_or_order',
			'$order_or_post',
		];

		$files_with_meta_box     = [];
		$files_without_helper    = [];

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

				// 只檢查有 add_meta_box 且 screen 包含 shop_order 的檔案。
				if ( strpos( $content, 'add_meta_box' ) === false ) {
					continue;
				}
				if ( strpos( $content, 'shop_order' ) === false && strpos( $content, 'wc-orders' ) === false ) {
					continue;
				}

				$relative_path = str_replace( $plugin_dir, '', $filepath );
				$files_with_meta_box[] = $relative_path;

				// 檢查是否有使用 helper 或型別判斷。
				$has_helper = false;
				foreach ( $helper_patterns as $hp ) {
					if ( strpos( $content, $hp ) !== false ) {
						$has_helper = true;
						break;
					}
				}

				if ( ! $has_helper ) {
					$files_without_helper[] = $relative_path;
				}
			}
		}

		// 如果有訂單相關 meta box 的檔案，它們應該使用 helper 或型別判斷。
		if ( ! empty( $files_with_meta_box ) ) {
			$this->assertEmpty(
				$files_without_helper,
				"以下檔案包含訂單 meta box 但未使用 Woomp_HPOS_Helper 或型別判斷：\n"
				. implode( "\n", $files_without_helper )
			);
		}
	}

	// ========================================================================
	// Rule: 邊緣案例 — wc_get_order 的防禦性使用
	// ========================================================================

	/**
	 * 測試 wc_get_order 對不存在的 ID 回傳 false
	 *
	 * 邊緣案例：確保使用 wc_get_order 而非 new WC_Order 的原因之一。
	 *
	 * @testdox 驗證 wc_get_order() 對不存在的 ID 回傳 false
	 */
	public function test_wc_get_order_returns_false_for_nonexistent_id() {
		$result = wc_get_order( 999999999 );

		$this->assertFalse(
			$result,
			'wc_get_order() 對不存在的 ID 應回傳 false'
		);
	}

	/**
	 * 測試 wc_get_order 對 0 回傳 false
	 *
	 * 邊緣案例：ID 為 0 時不應建立空訂單。
	 *
	 * @testdox 驗證 wc_get_order() 對 ID 為 0 回傳 false
	 */
	public function test_wc_get_order_returns_false_for_zero_id() {
		$result = wc_get_order( 0 );

		$this->assertFalse(
			$result,
			'wc_get_order(0) 應回傳 false'
		);
	}

	/**
	 * 測試 wc_get_order 對負數 ID 回傳 false
	 *
	 * 邊緣案例：負數 ID 不應造成例外。
	 *
	 * @testdox 驗證 wc_get_order() 對負數 ID 回傳 false
	 */
	public function test_wc_get_order_returns_false_for_negative_id() {
		$result = wc_get_order( -1 );

		$this->assertFalse(
			$result,
			'wc_get_order(-1) 應回傳 false'
		);
	}
}

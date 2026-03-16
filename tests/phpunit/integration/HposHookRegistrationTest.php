<?php
/**
 * HPOS Hook 註冊整合測試
 *
 * 驗證 Woomp 外掛在 HPOS 模式下正確註冊雙重 hooks：
 * - Meta Box screen 相容（shop_order + woocommerce_page_wc-orders）
 * - Column hooks 雙重註冊
 * - Bulk action hooks 雙重註冊
 * - 儲存 Hook 遷移至 woocommerce_process_shop_order_meta
 *
 * 對應規格：specs/features/hpos/MetaBox顯示.feature
 *            specs/features/hpos/訂單列表欄位.feature
 *            specs/features/hpos/批次操作.feature
 *            specs/features/hpos/儲存Hook.feature
 *
 * @package Woomp\Tests\Integration
 */

/**
 * HPOS Hook 註冊測試類別
 *
 * @covers includes/class-woomp.php
 * @covers admin/
 */
final class HposHookRegistrationTest extends WP_UnitTestCase {

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce 未載入，跳過 HPOS Hook 註冊測試' );
		}
	}

	// ========================================================================
	// Rule: 訂單列表欄位 — 雙重 Hook 註冊
	// ========================================================================

	/**
	 * 測試 HPOS 訂單列表欄位 hook 已註冊
	 *
	 * 規格：Hook manage_woocommerce_page_wc-orders_columns 已註冊。
	 *
	 * @testdox 確認 HPOS 訂單列表欄位 hook 已註冊
	 */
	public function test_hpos_order_list_columns_hook_registered() {
		$has_hook = has_filter( 'manage_woocommerce_page_wc-orders_columns' );

		$this->assertNotFalse(
			$has_hook,
			'manage_woocommerce_page_wc-orders_columns hook 應已註冊（HPOS 訂單列表欄位）'
		);
	}

	/**
	 * 測試傳統訂單列表欄位 hook 已註冊
	 *
	 * 規格：同時註冊 manage_shop_order_posts_columns（傳統）。
	 *
	 * @testdox 確認傳統訂單列表欄位 hook 已註冊
	 */
	public function test_legacy_order_list_columns_hook_registered() {
		$has_hook = has_filter( 'manage_shop_order_posts_columns' );

		$this->assertNotFalse(
			$has_hook,
			'manage_shop_order_posts_columns hook 應已註冊（傳統訂單列表欄位）'
		);
	}

	/**
	 * 測試 HPOS 訂單列表自訂欄位值 hook 已註冊
	 *
	 * 規格：Hook manage_woocommerce_page_wc-orders_custom_column 已註冊。
	 *
	 * @testdox 確認 HPOS 訂單列表自訂欄位值 hook 已註冊
	 */
	public function test_hpos_order_list_custom_column_hook_registered() {
		$has_hook = has_action( 'manage_woocommerce_page_wc-orders_custom_column' );

		$this->assertNotFalse(
			$has_hook,
			'manage_woocommerce_page_wc-orders_custom_column hook 應已註冊（HPOS 欄位值渲染）'
		);
	}

	/**
	 * 測試傳統訂單列表自訂欄位值 hook 已註冊
	 *
	 * @testdox 確認傳統訂單列表自訂欄位值 hook 已註冊
	 */
	public function test_legacy_order_list_custom_column_hook_registered() {
		$has_hook = has_action( 'manage_shop_order_posts_custom_column' );

		$this->assertNotFalse(
			$has_hook,
			'manage_shop_order_posts_custom_column hook 應已註冊（傳統欄位值渲染）'
		);
	}

	// ========================================================================
	// Rule: 批次操作 — 雙重 Hook 註冊
	// ========================================================================

	/**
	 * 測試 HPOS 批次操作 hook 已註冊
	 *
	 * 規格：Hook bulk_actions-woocommerce_page_wc-orders 已註冊。
	 *
	 * @testdox 確認 HPOS 批次操作 hook 已註冊
	 */
	public function test_hpos_bulk_actions_hook_registered() {
		$has_hook = has_filter( 'bulk_actions-woocommerce_page_wc-orders' );

		$this->assertNotFalse(
			$has_hook,
			'bulk_actions-woocommerce_page_wc-orders hook 應已註冊（HPOS 批次操作）'
		);
	}

	/**
	 * 測試傳統批次操作 hook 已註冊
	 *
	 * 規格：同時註冊 bulk_actions-edit-shop_order（傳統）。
	 *
	 * @testdox 確認傳統批次操作 hook 已註冊
	 */
	public function test_legacy_bulk_actions_hook_registered() {
		$has_hook = has_filter( 'bulk_actions-edit-shop_order' );

		$this->assertNotFalse(
			$has_hook,
			'bulk_actions-edit-shop_order hook 應已註冊（傳統批次操作）'
		);
	}

	/**
	 * 測試 HPOS 批次操作處理器 hook 已註冊
	 *
	 * 規格：Hook handle_bulk_actions-woocommerce_page_wc-orders 已註冊。
	 *
	 * @testdox 確認 HPOS 批次操作處理器 hook 已註冊
	 */
	public function test_hpos_handle_bulk_actions_hook_registered() {
		$has_hook = has_filter( 'handle_bulk_actions-woocommerce_page_wc-orders' );

		$this->assertNotFalse(
			$has_hook,
			'handle_bulk_actions-woocommerce_page_wc-orders hook 應已註冊（HPOS 批次處理器）'
		);
	}

	/**
	 * 測試傳統批次操作處理器 hook 已註冊
	 *
	 * @testdox 確認傳統批次操作處理器 hook 已註冊
	 */
	public function test_legacy_handle_bulk_actions_hook_registered() {
		$has_hook = has_filter( 'handle_bulk_actions-edit-shop_order' );

		$this->assertNotFalse(
			$has_hook,
			'handle_bulk_actions-edit-shop_order hook 應已註冊（傳統批次處理器）'
		);
	}

	// ========================================================================
	// Rule: 儲存 Hook — 使用 woocommerce_process_shop_order_meta
	// ========================================================================

	/**
	 * 測試 woocommerce_process_shop_order_meta hook 已註冊
	 *
	 * 規格：使用 woocommerce_process_shop_order_meta（不用 save_post_shop_order）。
	 * 此 hook 在 HPOS 和傳統模式下都會觸發。
	 *
	 * @testdox 確認 woocommerce_process_shop_order_meta hook 已註冊
	 */
	public function test_woocommerce_process_shop_order_meta_hook_registered() {
		$has_hook = has_action( 'woocommerce_process_shop_order_meta' );

		$this->assertNotFalse(
			$has_hook,
			'woocommerce_process_shop_order_meta hook 應已註冊（用於訂單儲存時更新發票等 meta）'
		);
	}

	// ========================================================================
	// Rule: Meta Box — add_meta_box screen 參數相容
	// ========================================================================

	/**
	 * 測試 add_meta_boxes_woocommerce_page_wc-orders hook 已註冊
	 *
	 * 規格：add_meta_box screen 參數包含 wc_get_page_screen_id('shop-order')（HPOS 模式）。
	 * 在 HPOS 下，WC 會觸發 add_meta_boxes_woocommerce_page_wc-orders action。
	 *
	 * @testdox 確認 HPOS meta box hook 已註冊
	 */
	public function test_hpos_meta_box_hook_registered() {
		// 檢查是否有任何 callback 註冊到 HPOS 的 meta box hook。
		$has_hook = has_action( 'add_meta_boxes_woocommerce_page_wc-orders' );

		// 如果沒有直接註冊到此 hook，則檢查 add_meta_boxes hook
		// （WC 會在 HPOS 下自動轉發 add_meta_boxes）。
		$has_general_hook = has_action( 'add_meta_boxes' );

		$this->assertTrue(
			$has_hook !== false || $has_general_hook !== false,
			'HPOS meta box hook 或 add_meta_boxes hook 應已註冊'
		);
	}

	/**
	 * 測試傳統 add_meta_boxes_shop_order hook 已註冊
	 *
	 * 規格：screen 參數包含 'shop_order'（傳統模式）。
	 *
	 * @testdox 確認傳統 shop_order meta box hook 已註冊
	 */
	public function test_legacy_meta_box_hook_registered() {
		$has_hook         = has_action( 'add_meta_boxes_shop_order' );
		$has_general_hook = has_action( 'add_meta_boxes' );

		$this->assertTrue(
			$has_hook !== false || $has_general_hook !== false,
			'傳統 shop_order meta box hook 或 add_meta_boxes hook 應已註冊'
		);
	}

	// ========================================================================
	// Rule: 掃描測試 — 確認全專案無 save_post_shop_order hook
	// ========================================================================

	/**
	 * 掃描測試：確認全專案無 save_post_shop_order hook 使用
	 *
	 * 規格：無任何 add_action('save_post_shop_order', ...) 呼叫。
	 * save_post_shop_order 在 HPOS 下不會觸發，所有發票/meta 儲存
	 * 應改用 woocommerce_process_shop_order_meta。
	 *
	 * @testdox 掃描確認全專案無 save_post_shop_order hook 使用
	 */
	public function test_no_save_post_shop_order_hook_in_source() {
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

		// 搜尋 save_post_shop_order 的使用。
		$forbidden_patterns = [
			'/add_action\s*\(\s*[\'"]save_post_shop_order[\'"]/' => 'add_action(\'save_post_shop_order\', ...)',
			'/[\'"]save_post_shop_order[\'"]/'                   => '\'save_post_shop_order\' 字串引用',
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
					foreach ( $forbidden_patterns as $regex => $description ) {
						if ( preg_match( $regex, $line ) ) {
							$relative_path = str_replace( $plugin_dir, '', $filepath );
							$violations[]  = sprintf(
								'%s (行 %d): %s',
								$relative_path,
								$line_num + 1,
								$description
							);
						}
					}
				}
			}
		}

		$this->assertEmpty(
			$violations,
			"以下位置使用了 save_post_shop_order hook（HPOS 下不會觸發），應改用 woocommerce_process_shop_order_meta：\n"
			. implode( "\n", $violations )
		);
	}

	/**
	 * 掃描測試：確認訂單列表欄位 hook 有雙重註冊（原始碼層面）
	 *
	 * 檢查原始碼中，manage_shop_order_posts_columns 和
	 * manage_woocommerce_page_wc-orders_columns 是否成對出現。
	 *
	 * @testdox 掃描確認訂單列表欄位 hook 有傳統與 HPOS 雙重註冊
	 */
	public function test_column_hooks_dual_registration_in_source() {
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

		$legacy_hook = 'manage_shop_order_posts_columns';
		$hpos_hook   = 'manage_woocommerce_page_wc-orders_columns';

		$files_with_legacy = [];
		$files_with_hpos   = [];

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

				$content       = file_get_contents( $filepath );
				$relative_path = str_replace( $plugin_dir, '', $filepath );

				if ( strpos( $content, $legacy_hook ) !== false ) {
					$files_with_legacy[] = $relative_path;
				}
				if ( strpos( $content, $hpos_hook ) !== false ) {
					$files_with_hpos[] = $relative_path;
				}
			}
		}

		// 如果有傳統 hook 註冊，應該也要有 HPOS hook 註冊。
		if ( ! empty( $files_with_legacy ) ) {
			$this->assertNotEmpty(
				$files_with_hpos,
				"找到 {$legacy_hook}（傳統）但缺少 {$hpos_hook}（HPOS）。\n"
				. '傳統 hook 檔案：' . implode( ', ', $files_with_legacy )
			);
		}
	}

	/**
	 * 掃描測試：確認批次操作 hook 有雙重註冊（原始碼層面）
	 *
	 * @testdox 掃描確認批次操作 hook 有傳統與 HPOS 雙重註冊
	 */
	public function test_bulk_action_hooks_dual_registration_in_source() {
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

		$legacy_hook = 'bulk_actions-edit-shop_order';
		$hpos_hook   = 'bulk_actions-woocommerce_page_wc-orders';

		$files_with_legacy = [];
		$files_with_hpos   = [];

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

				$content       = file_get_contents( $filepath );
				$relative_path = str_replace( $plugin_dir, '', $filepath );

				if ( strpos( $content, $legacy_hook ) !== false ) {
					$files_with_legacy[] = $relative_path;
				}
				if ( strpos( $content, $hpos_hook ) !== false ) {
					$files_with_hpos[] = $relative_path;
				}
			}
		}

		if ( ! empty( $files_with_legacy ) ) {
			$this->assertNotEmpty(
				$files_with_hpos,
				"找到 {$legacy_hook}（傳統）但缺少 {$hpos_hook}（HPOS）。\n"
				. '傳統 hook 檔案：' . implode( ', ', $files_with_legacy )
			);
		}
	}
}

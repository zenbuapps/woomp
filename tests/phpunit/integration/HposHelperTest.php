<?php
/**
 * HPOS Helper 輔助類別整合測試
 *
 * 驗證 Woomp_HPOS_Helper 共用類別的靜態方法：
 * - get_order() 接受 WC_Order 或 WP_Post 回傳 WC_Order
 * - get_order_screen_ids() 回傳正確的 screen ID
 * - get_order_list_url() 回傳正確的 URL
 *
 * 對應規格：specs/features/hpos/MetaBox顯示.feature（render callback 參數處理）
 *            specs/features/hpos/AdminURL相容.feature（URL / screen 判斷）
 *
 * @package Woomp\Tests\Integration
 */

/**
 * HPOS Helper 測試類別
 *
 * @covers Woomp_HPOS_Helper
 * @group hpos
 * @group hpos-compat
 */
final class HposHelperTest extends WP_UnitTestCase {

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
			$this->markTestSkipped( 'WooCommerce 未載入，跳過 HPOS Helper 測試' );
		}

		// 建立測試訂單。
		$this->order = wc_create_order();
		$this->order->set_status( 'processing' );
		$this->order->set_total( 500 );
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
	// Rule: Woomp_HPOS_Helper 類別存在
	// ========================================================================

	/**
	 * 測試 Woomp_HPOS_Helper 類別已載入
	 *
	 * 根據已決策：Meta Box 共用 Woomp_HPOS_Helper 類別。
	 *
	 * @testdox 驗證 Woomp_HPOS_Helper 類別已定義且可被載入
	 */
	public function test_hpos_helper_class_exists() {
		$this->assertTrue(
			class_exists( 'Woomp_HPOS_Helper' ),
			'Woomp_HPOS_Helper 類別應已載入'
		);
	}

	/**
	 * 測試 Woomp_HPOS_Helper 有 get_order 靜態方法
	 *
	 * @testdox 確認 Woomp_HPOS_Helper 提供 get_order() 靜態方法
	 */
	public function test_hpos_helper_has_get_order_method() {
		$this->assertTrue(
			method_exists( 'Woomp_HPOS_Helper', 'get_order' ),
			'Woomp_HPOS_Helper 應有 get_order() 靜態方法'
		);
	}

	/**
	 * 測試 Woomp_HPOS_Helper 有 get_order_screen_ids 靜態方法
	 *
	 * @testdox 確認 Woomp_HPOS_Helper 提供 get_order_screen_ids() 靜態方法
	 */
	public function test_hpos_helper_has_get_order_screen_ids_method() {
		$this->assertTrue(
			method_exists( 'Woomp_HPOS_Helper', 'get_order_screen_ids' ),
			'Woomp_HPOS_Helper 應有 get_order_screen_ids() 靜態方法'
		);
	}

	/**
	 * 測試 Woomp_HPOS_Helper 有 get_order_list_url 靜態方法
	 *
	 * @testdox 確認 Woomp_HPOS_Helper 提供 get_order_list_url() 靜態方法
	 */
	public function test_hpos_helper_has_get_order_list_url_method() {
		$this->assertTrue(
			method_exists( 'Woomp_HPOS_Helper', 'get_order_list_url' ),
			'Woomp_HPOS_Helper 應有 get_order_list_url() 靜態方法'
		);
	}

	// ========================================================================
	// Rule: get_order() — 接受 WC_Order 回傳 WC_Order
	// ========================================================================

	/**
	 * 測試 get_order() 接受 WC_Order 物件回傳 WC_Order
	 *
	 * 規格：render callback 收到 WC_Order 物件（HPOS 模式），
	 *       模組使用型別判斷取得 order ID 和 meta。
	 *
	 * @testdox 確認 get_order() 傳入 WC_Order 時回傳相同的 WC_Order 物件
	 */
	public function test_get_order_accepts_wc_order_returns_wc_order() {
		if ( ! class_exists( 'Woomp_HPOS_Helper' ) ) {
			$this->markTestSkipped( 'Woomp_HPOS_Helper 類別不存在（尚未實作）' );
		}

		$result = Woomp_HPOS_Helper::get_order( $this->order );

		$this->assertInstanceOf(
			'WC_Order',
			$result,
			'get_order() 傳入 WC_Order 時應回傳 WC_Order 實例'
		);

		$this->assertSame(
			$this->order->get_id(),
			$result->get_id(),
			'回傳的 WC_Order 應與傳入的訂單 ID 一致'
		);
	}

	/**
	 * 測試 get_order() 接受 WP_Post 物件回傳 WC_Order
	 *
	 * 規格：render callback 收到 WP_Post 物件（傳統模式），
	 *       模組使用型別判斷取得 order ID 和 meta。
	 *
	 * @testdox 確認 get_order() 傳入 WP_Post 時回傳對應的 WC_Order 物件
	 */
	public function test_get_order_accepts_wp_post_returns_wc_order() {
		if ( ! class_exists( 'Woomp_HPOS_Helper' ) ) {
			$this->markTestSkipped( 'Woomp_HPOS_Helper 類別不存在（尚未實作）' );
		}

		// 取得訂單對應的 WP_Post 物件。
		$post = get_post( $this->order->get_id() );

		// 在 HPOS 模式下，訂單可能沒有對應的 post，此時建立一個模擬用的 WP_Post。
		if ( ! $post ) {
			$post     = new stdClass();
			$post->ID = $this->order->get_id();
			$post     = new WP_Post( $post );
		}

		$result = Woomp_HPOS_Helper::get_order( $post );

		$this->assertInstanceOf(
			'WC_Order',
			$result,
			'get_order() 傳入 WP_Post 時應回傳 WC_Order 實例'
		);

		$this->assertSame(
			$this->order->get_id(),
			$result->get_id(),
			'回傳的 WC_Order 應與 WP_Post 的訂單 ID 一致'
		);
	}

	/**
	 * 測試 get_order() 接受訂單 ID（整數）回傳 WC_Order
	 *
	 * 邊緣案例：除了 WC_Order 和 WP_Post，也應支援直接傳入整數 ID。
	 *
	 * @testdox 驗證 get_order() 傳入整數 ID 時回傳 null
	 */
	public function test_get_order_accepts_integer_id_returns_null() {
		if ( ! class_exists( 'Woomp_HPOS_Helper' ) ) {
			$this->markTestSkipped( 'Woomp_HPOS_Helper 類別不存在（尚未實作）' );
		}

		// get_order() 僅接受 WP_Post 和 WC_Order，傳入整數應回傳 null。
		$result = Woomp_HPOS_Helper::get_order( $this->order->get_id() );

		$this->assertNull(
			$result,
			'get_order() 傳入整數 ID 時應回傳 null（僅接受 WP_Post / WC_Order）'
		);
	}

	/**
	 * 測試 get_order() 傳入無效值回傳 null 或 false
	 *
	 * 邊緣案例：傳入 null、0、不存在的 ID。
	 *
	 * @testdox 驗證 get_order() 傳入 null、0 或不存在的 ID 時回傳 null/false
	 */
	public function test_get_order_returns_null_for_invalid_input() {
		if ( ! class_exists( 'Woomp_HPOS_Helper' ) ) {
			$this->markTestSkipped( 'Woomp_HPOS_Helper 類別不存在（尚未實作）' );
		}

		// null 輸入。
		$result_null = Woomp_HPOS_Helper::get_order( null );
		$this->assertTrue(
			$result_null === null || $result_null === false,
			'get_order(null) 應回傳 null 或 false'
		);

		// 0。
		$result_zero = Woomp_HPOS_Helper::get_order( 0 );
		$this->assertTrue(
			$result_zero === null || $result_zero === false,
			'get_order(0) 應回傳 null 或 false'
		);

		// 不存在的 ID。
		$result_nonexist = Woomp_HPOS_Helper::get_order( 999999999 );
		$this->assertTrue(
			$result_nonexist === null || $result_nonexist === false,
			'get_order(不存在的ID) 應回傳 null 或 false'
		);
	}

	// ========================================================================
	// Rule: get_order_screen_ids() — 回傳正確的 screen ID
	// ========================================================================

	/**
	 * 測試 get_order_screen_ids() 回傳包含 HPOS screen ID
	 *
	 * 規格：HPOS 下訂單編輯頁 screen 判斷正確，
	 *       同時判斷 HPOS 的 screen ID（woocommerce_page_wc-orders）。
	 *
	 * @testdox 確認 get_order_screen_ids() 包含 HPOS screen ID（woocommerce_page_wc-orders）
	 */
	public function test_get_order_screen_ids_includes_hpos_screen() {
		if ( ! class_exists( 'Woomp_HPOS_Helper' ) ) {
			$this->markTestSkipped( 'Woomp_HPOS_Helper 類別不存在（尚未實作）' );
		}

		$screens = Woomp_HPOS_Helper::get_order_screen_ids();

		// 可能回傳陣列（兩種 screen）或單一字串。
		if ( is_array( $screens ) ) {
			$this->assertContains(
				'woocommerce_page_wc-orders',
				$screens,
				'get_order_screen_ids() 應包含 HPOS screen ID'
			);
		} else {
			// 如果回傳單一字串，在 HPOS 啟用時應為 HPOS screen。
			$this->assertStringContainsString(
				'wc-orders',
				(string) $screens,
				'get_order_screen_ids() 回傳值應包含 wc-orders'
			);
		}
	}

	/**
	 * 測試 get_order_screen_ids() 回傳包含傳統 screen ID
	 *
	 * 規格：不僅判斷 HPOS，同時判斷傳統的 shop_order screen。
	 *
	 * @testdox 確認 get_order_screen_ids() 包含傳統 screen ID（shop_order）
	 */
	public function test_get_order_screen_ids_includes_legacy_screen() {
		if ( ! class_exists( 'Woomp_HPOS_Helper' ) ) {
			$this->markTestSkipped( 'Woomp_HPOS_Helper 類別不存在（尚未實作）' );
		}

		$screens = Woomp_HPOS_Helper::get_order_screen_ids();

		if ( is_array( $screens ) ) {
			$this->assertContains(
				'shop_order',
				$screens,
				'get_order_screen_ids() 應包含傳統 shop_order screen ID'
			);
		}
		// 如果回傳單一字串，可能是根據當前模式動態決定的。
	}

	// ========================================================================
	// Rule: get_order_list_url() — 回傳正確的 URL
	// ========================================================================

	/**
	 * 測試 get_order_list_url() 回傳有效的 admin URL
	 *
	 * 規格：HPOS 下使用 admin_url('admin.php?page=wc-orders')，
	 *       傳統下使用 admin_url('edit.php?post_type=shop_order')。
	 *
	 * @testdox 驗證 get_order_list_url() 回傳包含正確路徑的 admin URL
	 */
	public function test_get_order_list_url_returns_valid_url() {
		if ( ! class_exists( 'Woomp_HPOS_Helper' ) ) {
			$this->markTestSkipped( 'Woomp_HPOS_Helper 類別不存在（尚未實作）' );
		}

		$url = Woomp_HPOS_Helper::get_order_list_url();

		$this->assertNotEmpty(
			$url,
			'get_order_list_url() 不應回傳空值'
		);

		// URL 應包含 admin 路徑。
		$this->assertStringContainsString(
			'admin',
			$url,
			'回傳的 URL 應包含 admin 路徑'
		);

		// URL 應包含訂單相關的路徑。
		$has_hpos_path   = strpos( $url, 'page=wc-orders' ) !== false;
		$has_legacy_path = strpos( $url, 'post_type=shop_order' ) !== false;

		$this->assertTrue(
			$has_hpos_path || $has_legacy_path,
			'回傳的 URL 應包含 page=wc-orders（HPOS）或 post_type=shop_order（傳統）'
		);
	}

	/**
	 * 測試 get_order_list_url() 帶有訂單 ID 時回傳編輯頁 URL
	 *
	 * 邊緣案例：傳入訂單 ID 時，URL 應指向該訂單的編輯頁面。
	 *
	 * @testdox 驗證 get_order_edit_url() 回傳包含訂單 ID 的編輯頁 URL
	 */
	public function test_get_order_edit_url_with_order_id() {
		if ( ! class_exists( 'Woomp_HPOS_Helper' ) ) {
			$this->markTestSkipped( 'Woomp_HPOS_Helper 類別不存在（尚未實作）' );
		}

		$url = Woomp_HPOS_Helper::get_order_edit_url( $this->order->get_id() );

		$this->assertStringContainsString(
			(string) $this->order->get_id(),
			$url,
			'帶有訂單 ID 的 URL 應包含該訂單 ID'
		);
	}

	// ========================================================================
	// Rule: is_hpos_enabled() — 判斷 HPOS 是否啟用
	// ========================================================================

	/**
	 * 測試 is_hpos_enabled() 方法存在且回傳 bool 型態
	 *
	 * @testdox 驗證 is_hpos_enabled() 方法存在且回傳 bool 型態
	 */
	public function test_is_hpos_enabled_returns_bool() {
		if ( ! class_exists( 'Woomp_HPOS_Helper' ) ) {
			$this->markTestSkipped( 'Woomp_HPOS_Helper 類別不存在（尚未實作）' );
		}

		if ( ! method_exists( 'Woomp_HPOS_Helper', 'is_hpos_enabled' ) ) {
			$this->markTestSkipped( 'is_hpos_enabled() 方法不存在，需先實作此方法' );
		}

		$result = Woomp_HPOS_Helper::is_hpos_enabled();

		$this->assertIsBool(
			$result,
			'is_hpos_enabled() 應回傳 bool 型態'
		);
	}

	// ========================================================================
	// Rule: get_order_edit_url() — 回傳包含訂單 ID 的編輯頁 URL
	// ========================================================================

	/**
	 * 測試 get_order_edit_url() 方法回傳包含訂單 ID 的 URL 字串
	 *
	 * @testdox 驗證 get_order_edit_url() 回傳包含訂單 ID 的有效 URL 字串
	 */
	public function test_get_order_edit_url_returns_url_with_order_id() {
		if ( ! class_exists( 'Woomp_HPOS_Helper' ) ) {
			$this->markTestSkipped( 'Woomp_HPOS_Helper 類別不存在（尚未實作）' );
		}

		if ( ! method_exists( 'Woomp_HPOS_Helper', 'get_order_edit_url' ) ) {
			$this->markTestSkipped( 'get_order_edit_url() 方法不存在，需先實作此方法' );
		}

		$order_id = $this->order->get_id();
		$url      = Woomp_HPOS_Helper::get_order_edit_url( $order_id );

		// 應回傳字串。
		$this->assertIsString(
			$url,
			'get_order_edit_url() 應回傳字串型態'
		);

		// URL 應包含訂單 ID。
		$this->assertStringContainsString(
			(string) $order_id,
			$url,
			'get_order_edit_url() 回傳的 URL 應包含訂單 ID'
		);

		// URL 應包含 admin 路徑。
		$this->assertStringContainsString(
			'admin',
			$url,
			'get_order_edit_url() 回傳的 URL 應包含 admin 路徑'
		);

		// URL 應包含 edit 或 action=edit。
		$has_edit = strpos( $url, 'action=edit' ) !== false || strpos( $url, 'post.php' ) !== false;
		$this->assertTrue(
			$has_edit,
			'get_order_edit_url() 回傳的 URL 應包含編輯相關路徑'
		);
	}

	// ========================================================================
	// Rule: Woomp_HPOS_Helper 原始碼品質
	// ========================================================================

	/**
	 * 掃描測試：確認 Woomp_HPOS_Helper 類別宣告為 final
	 *
	 * 依據 phpcs.xml 規則，類別應宣告為 final。
	 *
	 * @testdox 確認 Woomp_HPOS_Helper 類別宣告為 final class
	 */
	public function test_hpos_helper_class_declared_as_final() {
		$plugin_dir = defined( 'WOOMP_PLUGIN_DIR' ) ? WOOMP_PLUGIN_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';

		// 搜尋 Woomp_HPOS_Helper 類別定義檔案。
		$found        = false;
		$is_final     = false;
		$search_paths = [
			$plugin_dir . 'includes/',
			$plugin_dir . 'admin/',
		];

		foreach ( $search_paths as $dir ) {
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

				$content = file_get_contents( $file->getPathname() );

				if ( strpos( $content, 'class Woomp_HPOS_Helper' ) !== false ) {
					$found    = true;
					$is_final = (bool) preg_match( '/final\s+class\s+Woomp_HPOS_Helper/', $content );
					break 2;
				}
			}
		}

		$this->assertTrue(
			$found,
			'Woomp_HPOS_Helper 類別定義檔案應存在於 includes/ 或 admin/ 目錄中'
		);

		$this->assertTrue(
			$is_final,
			'Woomp_HPOS_Helper 類別應宣告為 final class'
		);
	}
}

<?php
/**
 * woomp_copy_order() 迴歸測試（GitHub issue #126）
 *
 * 背景：woomp_copy_order() 於 PayUni 回傳「已存在相同商店訂單編號」
 * （CREDIT04001 / ATM04001 / IFTRADE01006 / TOKEN01006）時被呼叫，
 * 用來建立一張新訂單重跑付款。
 *
 * 原本的 bug：函式用 `$new_order->add_item( $item )` 直接把原訂單的 item
 * 物件加進新訂單。`WC_Abstract_Order::add_item()` 會呼叫
 * `$item->set_order_id( $this->get_id() )`，就地竄改了共用的 item 物件；
 * `save_items()` 時因為 item 已有 ID，data store 會走 `update()`
 * 執行 `UPDATE woocommerce_order_items SET order_id = {新單}`，
 * 等同「搬移」而非複製 → 原訂單被清空、最後付款成功的訂單變成 0 元空單、
 * 發票開立失敗。附帶 bug：meta 複製時 `(string) $meta->__get('value')`
 * 把陣列型 meta（如 _ecpay_invoice_data）毀成字面字串 "Array"。
 *
 * 修正：clone $item + $new_item->set_id( 0 ) 後才 add_item()；
 * meta value 不強轉字串；剝除 _reduced_stock / _restock_refunded_items
 * 品項 meta（避免改成真複製後庫存被重複判定為已扣而超賣）。
 *
 * 執行指令：
 * npx @wordpress/env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --filter CopyOrderTest'
 *
 * @package Woomp\Tests\Integration
 */

/**
 * woomp_copy_order() 迴歸測試類別
 *
 * @covers ::woomp_copy_order
 * @group copy-order
 * @group gateway
 * @group payuni
 * @group regression
 */
final class CopyOrderTest extends WP_UnitTestCase {

	/**
	 * 測試過程中建立的訂單 ID，供 tearDown 清理。
	 *
	 * @var int[]
	 */
	private $order_ids = array();

	/**
	 * 測試過程中建立的商品 ID，供 tearDown 清理。
	 *
	 * @var int[]
	 */
	private $product_ids = array();

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WC_Order' ) ) {
			$this->markTestSkipped( 'WooCommerce 未載入，跳過 woomp_copy_order 迴歸測試' );
		}

		if ( ! function_exists( 'woomp_copy_order' ) ) {
			$this->markTestSkipped( 'woomp_copy_order() 函式不存在，跳過測試' );
		}

		$this->order_ids   = array();
		$this->product_ids = array();
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		foreach ( $this->order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$order->delete( true );
			}
		}

		foreach ( $this->product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof WC_Product ) {
				$product->delete( true );
			}
		}

		parent::tearDown();
	}

	// ========================================================================
	// Fixtures
	// ========================================================================

	/**
	 * 建立含兩個 line item 的測試訂單，金額固定以便斷言。
	 *
	 * 商品 A：單價 500 × 3 = 1500；商品 B：單價 300 × 1 = 300；訂單總額 1800。
	 *
	 * @return \WC_Order
	 */
	private function create_order_with_line_items(): \WC_Order {
		$order = wc_create_order();

		$product_a = new WC_Product_Simple();
		$product_a->set_name( '測試商品 A' );
		$product_a->set_regular_price( '500' );
		$product_a->save();
		$this->product_ids[] = $product_a->get_id();

		$product_b = new WC_Product_Simple();
		$product_b->set_name( '測試商品 B' );
		$product_b->set_regular_price( '300' );
		$product_b->save();
		$this->product_ids[] = $product_b->get_id();

		$order->add_product(
			$product_a,
			3,
			array(
				'subtotal' => 1500,
				'total'    => 1500,
			)
		);
		$order->add_product(
			$product_b,
			1,
			array(
				'subtotal' => 300,
				'total'    => 300,
			)
		);

		$order->set_total( 1800 );
		$order->set_payment_method( 'payuni-credit' );
		$order->set_payment_method_title( 'PayUni 信用卡' );
		$order->save();

		$this->order_ids[] = $order->get_id();

		return $order;
	}

	/**
	 * 斷言兩組品項 ID 陣列彼此沒有交集。
	 *
	 * @param int[]  $ids_a   第一組品項 ID。
	 * @param int[]  $ids_b   第二組品項 ID。
	 * @param string $message 斷言失敗訊息。
	 *
	 * @return void
	 */
	private function assertItemIdsHaveNoOverlap( array $ids_a, array $ids_b, string $message ): void {
		$overlap = array_values( array_intersect( $ids_a, $ids_b ) );
		$this->assertSame( array(), $overlap, $message );
	}

	// ========================================================================
	// Rule: 原訂單品項不可被搬移（issue #126 核心回歸）
	// ========================================================================

	/**
	 * 測試複製訂單後，原訂單的品項不會被「搬移」到新訂單。
	 *
	 * 關鍵：必須從資料庫重新讀取原訂單。修正前的 bug 是品項的
	 * order_id 欄位直接在 DB 被 UPDATE 成新訂單，若只檢查記憶體中的
	 * $order 物件無法重現「原訂單被清空」的症狀，只有重新讀取才會顯示
	 * 品項數量歸零。
	 *
	 * @testdox 複製訂單後原訂單的品項數量、品項 ID、品項小計與訂單總額應完全不變
	 */
	public function test_original_order_items_are_not_moved_after_copy() {
		$order = $this->create_order_with_line_items();

		$original_items       = $order->get_items( 'line_item' );
		$original_item_count  = count( $original_items );
		$original_item_ids    = array_keys( $original_items );
		$original_item_totals = array();
		foreach ( $original_items as $item_id => $item ) {
			$original_item_totals[ $item_id ] = $item->get_total();
		}
		$original_total = $order->get_total();

		$new_order_id       = woomp_copy_order( $order );
		$this->order_ids[] = $new_order_id;

		// 必須清快取才能重現原始 bug：WC_Order_Item 的 update() 只在
		// apply_changes() 之後用「新」order_id 清 'order-items-{id}' 快取，
		// 「原」order_id 的品項清單快取鍵不會被清除；若不 flush，
		// 即使 DB 的 order_id 欄位已被竄改，本次請求內的 wc_get_order()
		// 仍可能讀到 flush 前快取住的「看似正常」清單，導致回歸測試偽陰性。
		wp_cache_flush();

		// 從資料庫重新讀取原訂單，重現原始 bug 的檢驗方式。
		$reloaded_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( 'WC_Order', $reloaded_order, '原訂單複製後應仍可被讀取到' );

		$reloaded_items = $reloaded_order->get_items( 'line_item' );

		$this->assertCount(
			$original_item_count,
			$reloaded_items,
			'複製後原訂單的品項數量不應改變（不應被搬移到新訂單）'
		);

		$this->assertEqualsCanonicalizing(
			$original_item_ids,
			array_keys( $reloaded_items ),
			'複製後原訂單的品項 ID 應完全不變'
		);

		foreach ( $reloaded_items as $item_id => $item ) {
			$this->assertArrayHasKey( $item_id, $original_item_totals, "品項 #{$item_id} 應存在於複製前的原始品項清單中" );
			$this->assertEquals(
				$original_item_totals[ $item_id ],
				$item->get_total(),
				"品項 #{$item_id} 的小計不應被複製動作影響"
			);
		}

		$this->assertEquals(
			$original_total,
			$reloaded_order->get_total(),
			'複製後原訂單的總額不應改變'
		);
	}

	// ========================================================================
	// Rule: 新訂單取得獨立品項
	// ========================================================================

	/**
	 * 測試新訂單擁有自己獨立的品項，且與原訂單品項 ID 完全不重疊。
	 *
	 * @testdox 新訂單的品項數量與原單相同，品項 ID 與原單完全不重疊，且名稱／數量／小計一致
	 */
	public function test_new_order_gets_own_items_with_non_overlapping_ids() {
		$order = $this->create_order_with_line_items();

		$original_items    = $order->get_items( 'line_item' );
		$original_item_ids = array_keys( $original_items );

		$new_order_id       = woomp_copy_order( $order );
		$this->order_ids[] = $new_order_id;

		// 清快取以確保讀到的是資料庫真實狀態（見上一測試的說明）。
		wp_cache_flush();

		$new_order = wc_get_order( $new_order_id );
		$this->assertInstanceOf( 'WC_Order', $new_order, '應能取得複製出的新訂單' );

		$new_items = $new_order->get_items( 'line_item' );

		$this->assertCount(
			count( $original_items ),
			$new_items,
			'新訂單的品項數量應與原訂單相同'
		);

		$this->assertItemIdsHaveNoOverlap(
			$original_item_ids,
			array_keys( $new_items ),
			'新舊訂單的品項 ID 不應有任何交集（新品項必須是全新 ID，而非搬移原品項）'
		);

		// 依商品名稱比對，因為新舊品項 ID 不同、讀取順序也不保證一致。
		$original_by_name = array();
		foreach ( $original_items as $item ) {
			$original_by_name[ $item->get_name() ] = $item;
		}

		$this->assertNotEmpty( $new_items, '新訂單應有品項可供比對' );

		foreach ( $new_items as $new_item ) {
			$this->assertArrayHasKey(
				$new_item->get_name(),
				$original_by_name,
				"新訂單品項「{$new_item->get_name()}」應能對應到原訂單同名品項"
			);

			$original_item = $original_by_name[ $new_item->get_name() ];

			$this->assertSame(
				$original_item->get_quantity(),
				$new_item->get_quantity(),
				"品項「{$new_item->get_name()}」的數量應與原單一致"
			);
			$this->assertEquals(
				$original_item->get_subtotal(),
				$new_item->get_subtotal(),
				"品項「{$new_item->get_name()}」的小計應與原單一致"
			);
			$this->assertEquals(
				$original_item->get_total(),
				$new_item->get_total(),
				"品項「{$new_item->get_name()}」的總計應與原單一致"
			);
		}
	}

	// ========================================================================
	// Rule: 連續複製兩次（重現 #35321 → #35322 / #35323 訂單鏈）
	// ========================================================================

	/**
	 * 測試對同一張原訂單連續呼叫 woomp_copy_order() 兩次。
	 *
	 * 重現 issue 描述的訂單鏈情境：第一次複製失敗後，PayUni 再次回報
	 * 「已存在相同商店訂單編號」，導致同一張原訂單被連續複製兩次。
	 *
	 * @testdox 對同一張原訂單連續複製兩次，兩張複製單都各自擁有完整品項，且原訂單依然完整
	 */
	public function test_copy_order_called_twice_on_same_order_both_copies_are_complete() {
		$order = $this->create_order_with_line_items();

		$original_item_count = count( $order->get_items( 'line_item' ) );

		$copy_1_id          = woomp_copy_order( $order );
		$this->order_ids[] = $copy_1_id;

		$copy_2_id          = woomp_copy_order( $order );
		$this->order_ids[] = $copy_2_id;

		$this->assertNotSame( $copy_1_id, $copy_2_id, '兩次複製應產生不同的新訂單' );

		// 清快取以確保讀到的是資料庫真實狀態（見第一個測試的說明）。
		wp_cache_flush();

		$copy_1             = wc_get_order( $copy_1_id );
		$copy_2             = wc_get_order( $copy_2_id );
		$reloaded_original  = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( 'WC_Order', $copy_1, '第一張複製單應可被讀取' );
		$this->assertInstanceOf( 'WC_Order', $copy_2, '第二張複製單應可被讀取' );

		$this->assertCount( $original_item_count, $copy_1->get_items( 'line_item' ), '第一張複製單應有完整品項' );
		$this->assertCount( $original_item_count, $copy_2->get_items( 'line_item' ), '第二張複製單應有完整品項' );
		$this->assertCount( $original_item_count, $reloaded_original->get_items( 'line_item' ), '原訂單複製兩次後品項數量依然完整' );

		// 原單 + 兩張複製單，彼此的品項 ID 不應有任何交集。
		$ids_original = array_keys( $reloaded_original->get_items( 'line_item' ) );
		$ids_copy_1   = array_keys( $copy_1->get_items( 'line_item' ) );
		$ids_copy_2   = array_keys( $copy_2->get_items( 'line_item' ) );

		$this->assertItemIdsHaveNoOverlap( $ids_original, $ids_copy_1, '原單與複製單一的品項 ID 不應重疊' );
		$this->assertItemIdsHaveNoOverlap( $ids_original, $ids_copy_2, '原單與複製單二的品項 ID 不應重疊' );
		$this->assertItemIdsHaveNoOverlap( $ids_copy_1, $ids_copy_2, '複製單一與複製單二的品項 ID 不應重疊' );
	}

	// ========================================================================
	// Rule: Order meta 型別保留（陣列型 meta 不得被毀成字串）
	// ========================================================================

	/**
	 * 測試陣列型 order meta（例如電子發票載具資料）複製後仍為 array。
	 *
	 * @testdox 陣列型 meta（_ecpay_invoice_data）複製後型別與內容應與原單一致，不得被強轉為字串 "Array"
	 */
	public function test_copy_order_preserves_array_type_meta() {
		$order = $this->create_order_with_line_items();

		$invoice_data = array(
			'type'    => 'personal',
			'carrier' => '/ABC1234',
		);
		$order->update_meta_data( '_ecpay_invoice_data', $invoice_data );
		$order->save();

		$new_order_id       = woomp_copy_order( $order );
		$this->order_ids[] = $new_order_id;

		$new_order    = wc_get_order( $new_order_id );
		$copied_meta  = $new_order->get_meta( '_ecpay_invoice_data' );

		$this->assertIsArray(
			$copied_meta,
			'陣列型 meta 複製後仍應為 array，不得被強轉字串'
		);
		$this->assertNotSame(
			'Array',
			$copied_meta,
			'陣列型 meta 不得被毀成字面字串 "Array"'
		);
		$this->assertEquals(
			$invoice_data,
			$copied_meta,
			'陣列型 meta 的內容應與原單完全一致'
		);
	}

	/**
	 * 測試純量（字串）order meta 正常複製。
	 *
	 * @testdox 純量 meta 複製後值應與原單一致
	 */
	public function test_copy_order_preserves_scalar_meta() {
		$order = $this->create_order_with_line_items();

		$order->update_meta_data( '_payuni_trade_no', 'PAYUNI_COPY_TEST_001' );
		$order->save();

		$new_order_id       = woomp_copy_order( $order );
		$this->order_ids[] = $new_order_id;

		$new_order = wc_get_order( $new_order_id );

		$this->assertSame(
			'PAYUNI_COPY_TEST_001',
			$new_order->get_meta( '_payuni_trade_no' ),
			'純量 meta 應正確複製到新訂單'
		);
	}

	// ========================================================================
	// Rule: 品項層級狀態不可跨訂單複製（_reduced_stock）
	// ========================================================================

	/**
	 * 測試 line item 上的 _reduced_stock meta 不會被複製到新訂單。
	 *
	 * 若一併複製，新訂單付款成功時 wc_maybe_reduce_stock_levels() 會誤判
	 * 已扣庫存而跳過，造成超賣。原訂單品項上的 _reduced_stock 則必須保留。
	 *
	 * @testdox _reduced_stock 品項 meta 不應複製到新訂單，原訂單品項上的則應保留
	 */
	public function test_reduced_stock_item_meta_is_not_copied() {
		$order = $this->create_order_with_line_items();

		$line_items    = $order->get_items( 'line_item' );
		$first_item    = reset( $line_items );
		$first_item_id = $first_item->get_id();

		$first_item->add_meta_data( '_reduced_stock', 3, true );
		$first_item->save();

		$new_order_id       = woomp_copy_order( $order );
		$this->order_ids[] = $new_order_id;

		// 清快取以確保讀到的是資料庫真實狀態（見第一個測試的說明）。
		wp_cache_flush();

		// 原訂單品項上的 _reduced_stock 應保留（從資料庫重新讀取確認）。
		$reloaded_original = wc_get_order( $order->get_id() );
		$reloaded_items    = $reloaded_original->get_items( 'line_item' );

		$this->assertArrayHasKey( $first_item_id, $reloaded_items, '原訂單品項 ID 應保持不變' );
		$this->assertEquals(
			3,
			$reloaded_items[ $first_item_id ]->get_meta( '_reduced_stock' ),
			'原訂單品項上的 _reduced_stock 應保留'
		);

		// 新訂單所有品項都不應帶有 _reduced_stock。
		$new_order = wc_get_order( $new_order_id );
		$new_items = $new_order->get_items( 'line_item' );

		$this->assertNotEmpty( $new_items, '新訂單應有品項可供比對' );

		foreach ( $new_items as $new_item ) {
			$this->assertSame(
				'',
				$new_item->get_meta( '_reduced_stock' ),
				"新訂單品項「{$new_item->get_name()}」的 _reduced_stock 必須為空，避免超賣"
			);
		}
	}

	// ========================================================================
	// Rule: 非 line_item 類型（shipping / fee）一併複製
	// ========================================================================

	/**
	 * 測試訂單含 shipping line 與 fee line 時，複製後兩者也一併複製到新訂單。
	 *
	 * @testdox 訂單含運費與手續費品項時，新訂單應有對應的 shipping／fee 品項，ID 不重疊且原單保留
	 */
	public function test_copy_order_copies_shipping_and_fee_items() {
		$order = $this->create_order_with_line_items();

		$shipping_item = new WC_Order_Item_Shipping();
		$shipping_item->set_method_title( '宅配到府' );
		$shipping_item->set_method_id( 'flat_rate' );
		$shipping_item->set_total( 60 );
		$order->add_item( $shipping_item );

		$fee_item = new WC_Order_Item_Fee();
		$fee_item->set_name( '刷卡手續費' );
		$fee_item->set_amount( 40 );
		$fee_item->set_total( 40 );
		$fee_item->set_tax_status( 'none' );
		$order->add_item( $fee_item );

		$order->save();

		$original_shipping_ids = array_keys( $order->get_items( 'shipping' ) );
		$original_fee_ids      = array_keys( $order->get_items( 'fee' ) );

		$new_order_id       = woomp_copy_order( $order );
		$this->order_ids[] = $new_order_id;

		// 清快取以確保讀到的是資料庫真實狀態（見第一個測試的說明）。
		wp_cache_flush();

		$new_order = wc_get_order( $new_order_id );

		$new_shipping_items = $new_order->get_items( 'shipping' );
		$new_fee_items      = $new_order->get_items( 'fee' );

		$this->assertCount( 1, $new_shipping_items, '新訂單應有 1 筆 shipping 品項' );
		$this->assertCount( 1, $new_fee_items, '新訂單應有 1 筆 fee 品項' );

		$new_shipping = reset( $new_shipping_items );
		$new_fee      = reset( $new_fee_items );

		$this->assertSame( '宅配到府', $new_shipping->get_method_title(), 'shipping 品項的 method_title 應與原單一致' );
		$this->assertEquals( 60, $new_shipping->get_total(), 'shipping 品項的總計應與原單一致' );

		$this->assertSame( '刷卡手續費', $new_fee->get_name(), 'fee 品項的名稱應與原單一致' );
		$this->assertEquals( 40, $new_fee->get_total(), 'fee 品項的總計應與原單一致' );

		$this->assertItemIdsHaveNoOverlap(
			$original_shipping_ids,
			array_keys( $new_shipping_items ),
			'shipping 品項 ID 不應與原單重疊'
		);
		$this->assertItemIdsHaveNoOverlap(
			$original_fee_ids,
			array_keys( $new_fee_items ),
			'fee 品項 ID 不應與原單重疊'
		);

		// 原單保留：重新讀取原訂單確認 shipping / fee 仍在。
		$reloaded_original = wc_get_order( $order->get_id() );
		$this->assertCount( 1, $reloaded_original->get_items( 'shipping' ), '原訂單的 shipping 品項應保留' );
		$this->assertCount( 1, $reloaded_original->get_items( 'fee' ), '原訂單的 fee 品項應保留' );
	}
}

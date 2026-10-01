<?php
/**
 * 結帳離島超商門市驗證整合測試（issue #136）
 *
 * 原本 WooMP_Checkout::field_validate() 以 `1 !== ($fields['billing_island'] ?? '')`
 * 判斷，把「根本沒有離島欄位」也當成「沒勾選」；而離島欄位只在 onepage／twopage 模式
 * 且運送區域有設定離島郵遞區號時才會出現，導致 default 模式選離島門市必定被拒絕，
 * 畫面上又沒有欄位可修正。
 *
 * 修正後的規則（WooMP_Checkout::get_island_cvs_error()）：
 * - default 模式：放行，以 WooCommerce 運送區域設定為準。
 * - onepage／twopage 模式：該縣市沒設定離島郵遞區號 → 拒絕；有欄位但沒勾 → 拒絕；
 *   沒有欄位 → 放行。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 離島超商門市驗證測試類別
 *
 * @covers public/class-woomp-checkout.php
 * @group shipping
 * @group checkout
 */
class CheckoutIslandValidationTest extends WP_UnitTestCase {

	/**
	 * 金門門市地址
	 */
	private const KINMEN_ADDRESS = '金門縣金城鎮民生路32號';

	/**
	 * 澎湖門市地址
	 */
	private const PENGHU_ADDRESS = '澎湖縣馬公市中正路1號';

	/**
	 * 本島門市地址
	 */
	private const MAINLAND_ADDRESS = '台北市中正區重慶南路一段122號';

	/**
	 * 受測物件
	 *
	 * @var WooMP_Checkout
	 */
	private $checkout;

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WooMP_Checkout' ) ) {
			$this->markTestSkipped( 'WooMP_Checkout 未載入，跳過離島驗證測試' );
		}

		$this->checkout = new WooMP_Checkout();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * 設定 Woomp 結帳模式
	 *
	 * @param string $mode default／onepage／twopage.
	 */
	private function set_mode( string $mode ): void {
		update_option( 'wc_woomp_setting_mode', $mode );
	}

	/**
	 * 建立含指定郵遞區號的台灣運送區域
	 *
	 * @param array $postcodes 郵遞區號，例如 [ '890' ]（金門）.
	 */
	private function create_zone_with_postcodes( array $postcodes ): void {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( '離島測試區域' );
		$zone->add_location( 'TW', 'country' );
		foreach ( $postcodes as $postcode ) {
			$zone->add_location( $postcode, 'postcode' );
		}
		$zone->save();
	}

	/**
	 * 組出結帳送出的資料
	 *
	 * @param string    $cvs_address    門市地址.
	 * @param int|null  $billing_island 離島勾選欄位的值；null 代表沒有這個欄位.
	 * @return array
	 */
	private function build_fields( string $cvs_address, ?int $billing_island = null ): array {
		$fields = [
			'billing_first_name' => '王',
			'billing_last_name'  => '小明',
			'billing_country'    => 'TW',
			'billing_phone'      => '0912345678',
			'CVSAddress'         => $cvs_address,
		];
		if ( null !== $billing_island ) {
			$fields['billing_island'] = $billing_island;
		}
		return $fields;
	}

	// ------------------------------------------------------------------
	// default 模式：一律放行
	// ------------------------------------------------------------------

	/**
	 * default 模式、沒有離島欄位、運送區域為整個 TW → 金門門市放行（issue #136 回報情境）
	 */
	public function test_default_mode_allows_island_store_without_island_field(): void {
		$this->set_mode( 'default' );
		$this->create_zone_with_postcodes( [] );

		$this->assertSame( '', $this->checkout->get_island_cvs_error( $this->build_fields( self::KINMEN_ADDRESS ) ) );
	}

	/**
	 * 沒設定過結帳模式（option 不存在）視同 default → 放行
	 */
	public function test_unset_mode_allows_island_store(): void {
		delete_option( 'wc_woomp_setting_mode' );

		$this->assertSame( '', $this->checkout->get_island_cvs_error( $this->build_fields( self::PENGHU_ADDRESS ) ) );
	}

	// ------------------------------------------------------------------
	// onepage／twopage 模式
	// ------------------------------------------------------------------

	/**
	 * 運送區域沒設定任何離島郵遞區號 → 拒絕，訊息指出未配送的縣市
	 *
	 * @dataProvider provide_woomp_modes
	 * @param string $mode 結帳模式.
	 */
	public function test_woomp_mode_rejects_county_without_island_postcodes( string $mode ): void {
		$this->set_mode( $mode );
		$this->create_zone_with_postcodes( [] );

		$error = $this->checkout->get_island_cvs_error( $this->build_fields( self::KINMEN_ADDRESS ) );

		$this->assertStringContainsString( '本店目前未配送至金門縣', $error );
	}

	/**
	 * Woomp 結帳模式
	 *
	 * @return array
	 */
	public function provide_woomp_modes(): array {
		return [
			'onepage' => [ 'onepage' ],
			'twopage' => [ 'twopage' ],
		];
	}

	/**
	 * 有設定金門郵遞區號、顧客勾選離島 → 放行
	 */
	public function test_woomp_mode_allows_island_store_when_checked(): void {
		$this->set_mode( 'onepage' );
		$this->create_zone_with_postcodes( [ '890', '891' ] );

		$this->assertSame( '', $this->checkout->get_island_cvs_error( $this->build_fields( self::KINMEN_ADDRESS, 1 ) ) );
	}

	/**
	 * 有設定金門郵遞區號、有離島欄位但沒勾 → 拒絕，請顧客勾選
	 */
	public function test_woomp_mode_rejects_unchecked_island_field(): void {
		$this->set_mode( 'onepage' );
		$this->create_zone_with_postcodes( [ '890' ] );

		$error = $this->checkout->get_island_cvs_error( $this->build_fields( self::KINMEN_ADDRESS, 0 ) );

		$this->assertStringContainsString( '請勾選「寄送到離島區域」', $error );
	}

	/**
	 * 有設定金門郵遞區號、但離島欄位不存在（例如被其他外掛移除）→ 不要求顧客操作不存在的欄位
	 */
	public function test_woomp_mode_allows_when_island_field_absent(): void {
		$this->set_mode( 'twopage' );
		$this->create_zone_with_postcodes( [ '890' ] );

		$this->assertSame( '', $this->checkout->get_island_cvs_error( $this->build_fields( self::KINMEN_ADDRESS ) ) );
	}

	/**
	 * 只設定金門、顧客勾選離島但選了澎湖門市 → 拒絕
	 */
	public function test_woomp_mode_rejects_county_not_configured_even_if_checked(): void {
		$this->set_mode( 'onepage' );
		$this->create_zone_with_postcodes( [ '890' ] );

		$error = $this->checkout->get_island_cvs_error( $this->build_fields( self::PENGHU_ADDRESS, 1 ) );

		$this->assertStringContainsString( '本店目前未配送至澎湖縣', $error );
	}

	/**
	 * 本島門市、沒選門市 → 不受離島規則影響
	 */
	public function test_mainland_or_empty_store_is_not_affected(): void {
		$this->set_mode( 'onepage' );
		$this->create_zone_with_postcodes( [] );

		$this->assertSame( '', $this->checkout->get_island_cvs_error( $this->build_fields( self::MAINLAND_ADDRESS, 0 ) ) );
		$this->assertSame( '', $this->checkout->get_island_cvs_error( $this->build_fields( '', 0 ) ) );
		$this->assertSame( '', $this->checkout->get_island_cvs_error( [ 'billing_country' => 'TW' ] ) );
	}

	// ------------------------------------------------------------------
	// field_validate() 整合：錯誤代碼拆分、既有驗證不受影響
	// ------------------------------------------------------------------

	/**
	 * default 模式選金門門市，field_validate() 不再產生離島錯誤
	 */
	public function test_field_validate_default_mode_has_no_island_error(): void {
		$this->set_mode( 'default' );
		$errors = new WP_Error();

		$this->checkout->field_validate( $this->build_fields( self::KINMEN_ADDRESS ), $errors );

		$this->assertFalse( $errors->has_errors(), implode( ',', $errors->get_error_codes() ) );
	}

	/**
	 * 離島、姓名、電話錯誤使用各自的錯誤代碼，不再共用 validation
	 */
	public function test_field_validate_uses_distinct_error_codes(): void {
		$this->set_mode( 'onepage' );
		$this->create_zone_with_postcodes( [] );
		$errors = new WP_Error();

		$fields = $this->build_fields( self::KINMEN_ADDRESS );
		unset( $fields['billing_first_name'] );
		$fields['billing_last_name'] = '王';
		$fields['billing_phone']     = '091234';

		$this->checkout->field_validate( $fields, $errors );

		$codes = $errors->get_error_codes();
		sort( $codes );
		$this->assertSame( [ 'woomp_island_cvs', 'woomp_name_length', 'woomp_phone_length' ], $codes );
	}

	/**
	 * 非台灣地址 → 整個台灣欄位驗證不執行
	 */
	public function test_field_validate_skips_non_tw(): void {
		$this->set_mode( 'onepage' );
		$this->create_zone_with_postcodes( [] );
		$errors = new WP_Error();

		$fields                    = $this->build_fields( self::KINMEN_ADDRESS );
		$fields['billing_country'] = 'JP';
		$fields['billing_phone']   = '123';

		$this->checkout->field_validate( $fields, $errors );

		$this->assertFalse( $errors->has_errors() );
	}
}

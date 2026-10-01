<?php
/**
 * 綠界超商取貨「門市必選」驗證整合測試（issue #136）
 *
 * 原本門市是否已選擇只靠 add_cvs_info() 在 did_action('woocommerce_checkout_process')
 * 為真時把 CVSStoreName 設為 required。兩個情況會讓這個檢查失效、未選門市仍建立訂單：
 * 1. WC 會快取結帳欄位，其他外掛（例如 ThemeHigh Checkout Field Editor）在
 *    woocommerce_checkout_init 就讀取欄位時，required 會停在 false。
 * 2. 未勾選「運送到不同地址」時，WC 會略過整組 shipping 欄位驗證。
 *
 * 修正後由 RY_ECPay_Shipping::validate_cvs_store()（woocommerce_after_checkout_validation）
 * 直接以本次送出的物流與門市資料把關，不再依賴欄位 required 與初始化時機。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 綠界超商取貨門市驗證測試類別
 *
 * @covers includes/ry-woocommerce-tools/woocommerce/shipping/ecpay/ecpay-shipping.php
 * @group shipping
 * @group ecpay
 */
class EcpayCvsStoreValidationTest extends WP_UnitTestCase {

	/**
	 * 測試前的 $_POST，tearDown 時還原
	 *
	 * @var array
	 */
	private $original_post = [];

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'RY_ECPay_Shipping' ) ) {
			$this->markTestSkipped( 'ry-woocommerce-tools 綠界物流模組未載入，跳過門市驗證測試' );
		}

		$this->original_post = $_POST;
		RY_WT::update_option( 'ecpay_shipping_cvs_type', 'C2C' );
		$this->reset_checkout_fields_cache();
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		$_POST = $this->original_post;
		$this->reset_checkout_fields_cache();

		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * 清除 WC_Checkout 快取的結帳欄位，讓下一次 get_checkout_fields() 重新套用 filter
	 */
	private function reset_checkout_fields_cache(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->checkout() ) {
			return;
		}
		$prop = new ReflectionProperty( WC_Checkout::class, 'fields' );
		$prop->setAccessible( true );
		$prop->setValue( WC()->checkout(), null );
	}

	/**
	 * 組出結帳送出的資料（WC_Checkout::get_posted_data() 的格式）
	 *
	 * 預設為「7-ELEVEN C2C 超商取貨、門市資料完整」。
	 *
	 * @param array $overrides 覆蓋預設欄位的值.
	 * @return array
	 */
	private function build_data( array $overrides = [] ): array {
		return array_merge(
			[
				'shipping_method'  => [ 'ry_ecpay_shipping_cvs_711:3' ],
				'LogisticsSubType' => 'UNIMARTC2C',
				'CVSStoreID'       => '131386',
				'CVSStoreName'     => '建盛門市',
				'CVSAddress'       => '台北市中正區重慶南路一段122號',
				'CVSTelephone'     => '02-23111111',
			],
			$overrides
		);
	}

	/**
	 * 執行門市驗證並回傳錯誤
	 *
	 * @param array $data 結帳送出的資料.
	 * @return WP_Error
	 */
	private function validate( array $data ): WP_Error {
		$errors = new WP_Error();
		RY_ECPay_Shipping::validate_cvs_store( $data, $errors );
		return $errors;
	}

	// ------------------------------------------------------------------
	// Hook 註冊
	// ------------------------------------------------------------------

	/**
	 * 門市驗證掛在 woocommerce_after_checkout_validation（WC 在此之後才決定是否建立訂單）
	 */
	public function test_validate_cvs_store_is_hooked_after_checkout_validation(): void {
		$this->assertSame(
			10,
			has_action( 'woocommerce_after_checkout_validation', [ 'RY_ECPay_Shipping', 'validate_cvs_store' ] )
		);
	}

	// ------------------------------------------------------------------
	// 門市資料檢查
	// ------------------------------------------------------------------

	/**
	 * 選超商取貨但完全沒選門市 → 拒絕
	 */
	public function test_cvs_without_store_is_rejected(): void {
		$errors = $this->validate(
			$this->build_data(
				[
					'LogisticsSubType' => '',
					'CVSStoreID'       => '',
					'CVSStoreName'     => '',
					'CVSAddress'       => '',
					'CVSTelephone'     => '',
				]
			)
		);

		$this->assertSame( [ 'ry_ecpay_cvs_store_missing' ], $errors->get_error_codes() );
		$this->assertSame( '請選擇超商取貨門市', $errors->get_error_message( 'ry_ecpay_cvs_store_missing' ) );
	}

	/**
	 * 門市代號、名稱、地址任一缺漏 → 拒絕
	 *
	 * @dataProvider provide_missing_store_keys
	 * @param string $key 缺漏的欄位.
	 */
	public function test_cvs_with_partial_store_is_rejected( string $key ): void {
		$errors = $this->validate( $this->build_data( [ $key => '' ] ) );

		$this->assertSame( [ 'ry_ecpay_cvs_store_missing' ], $errors->get_error_codes() );
	}

	/**
	 * 門市必填欄位
	 *
	 * @return array
	 */
	public function provide_missing_store_keys(): array {
		return [
			'缺門市代號' => [ 'CVSStoreID' ],
			'缺門市名稱' => [ 'CVSStoreName' ],
			'缺門市地址' => [ 'CVSAddress' ],
		];
	}

	/**
	 * 門市欄位根本不在送出資料中（例如欄位被其他外掛移除）→ 視同未選門市
	 */
	public function test_cvs_store_keys_absent_is_rejected(): void {
		$errors = $this->validate( [ 'shipping_method' => [ 'ry_ecpay_shipping_cvs_711:3' ] ] );

		$this->assertSame( [ 'ry_ecpay_cvs_store_missing' ], $errors->get_error_codes() );
	}

	/**
	 * 7-ELEVEN／全家／萊爾富／OK 選了對應品牌門市 → 通過
	 *
	 * @dataProvider provide_matching_brand_stores
	 * @param string $method_id         物流 ID.
	 * @param string $logistics_subtype 綠界回傳的 LogisticsSubType.
	 */
	public function test_cvs_with_matching_store_passes( string $method_id, string $logistics_subtype ): void {
		$errors = $this->validate(
			$this->build_data(
				[
					'shipping_method'  => [ $method_id . ':5' ],
					'LogisticsSubType' => $logistics_subtype,
				]
			)
		);

		$this->assertFalse( $errors->has_errors(), implode( ',', $errors->get_error_codes() ) );
	}

	/**
	 * 各超商品牌與 C2C LogisticsSubType 對照
	 *
	 * @return array
	 */
	public function provide_matching_brand_stores(): array {
		return [
			'7-ELEVEN' => [ 'ry_ecpay_shipping_cvs_711', 'UNIMARTC2C' ],
			'全家'     => [ 'ry_ecpay_shipping_cvs_family', 'FAMIC2C' ],
			'萊爾富'   => [ 'ry_ecpay_shipping_cvs_hilife', 'HILIFEC2C' ],
			'OK 超商'  => [ 'ry_ecpay_shipping_cvs_okmart', 'OKMARTC2C' ],
		];
	}

	/**
	 * 先選 7-ELEVEN 門市再切換成全家、沒重新選店 → 拒絕（殘留前一家門市資料）
	 */
	public function test_store_from_other_brand_is_rejected(): void {
		$errors = $this->validate(
			$this->build_data(
				[
					'shipping_method'  => [ 'ry_ecpay_shipping_cvs_family:5' ],
					'LogisticsSubType' => 'UNIMARTC2C',
				]
			)
		);

		$this->assertSame( [ 'ry_ecpay_cvs_store_mismatch' ], $errors->get_error_codes() );
	}

	/**
	 * B2C／C2C 設定不一致（例如商家切換物流類型後顧客沿用舊門市）→ 拒絕；B2C 對應門市 → 通過
	 */
	public function test_logistics_subtype_follows_cvs_type_setting(): void {
		RY_WT::update_option( 'ecpay_shipping_cvs_type', 'B2C' );

		$this->assertSame(
			[ 'ry_ecpay_cvs_store_mismatch' ],
			$this->validate( $this->build_data( [ 'LogisticsSubType' => 'UNIMARTC2C' ] ) )->get_error_codes()
		);
		$this->assertFalse( $this->validate( $this->build_data( [ 'LogisticsSubType' => 'UNIMART' ] ) )->has_errors() );
	}

	/**
	 * 物流 ID 沒有 instance 後綴（舊版 shipping method 格式）也要能判斷
	 */
	public function test_method_without_instance_suffix_is_detected(): void {
		$this->assertSame( 'ry_ecpay_shipping_cvs_711', RY_ECPay_Shipping::get_chosen_cvs_method( [ 'ry_ecpay_shipping_cvs_711' ] ) );
		$this->assertSame( [ 'ry_ecpay_cvs_store_missing' ], $this->validate( [ 'shipping_method' => [ 'ry_ecpay_shipping_cvs_711' ] ] )->get_error_codes() );
	}

	// ------------------------------------------------------------------
	// 不需要門市的流程不可被誤擋
	// ------------------------------------------------------------------

	/**
	 * 綠界宅配、其他物流、沒有物流（虛擬商品）→ 不檢查門市
	 *
	 * @dataProvider provide_non_cvs_shipping
	 * @param mixed $shipping_method 結帳送出的物流.
	 */
	public function test_non_cvs_shipping_is_not_checked( $shipping_method ): void {
		$errors = $this->validate(
			[
				'shipping_method' => $shipping_method,
				'CVSStoreID'      => '',
				'CVSStoreName'    => '',
				'CVSAddress'      => '',
			]
		);

		$this->assertFalse( $errors->has_errors() );
	}

	/**
	 * 不需要超商門市的物流
	 *
	 * @return array
	 */
	public function provide_non_cvs_shipping(): array {
		return [
			'綠界黑貓宅配' => [ [ 'ry_ecpay_shipping_home_tcat:4' ] ],
			'綠界郵局宅配' => [ [ 'ry_ecpay_shipping_home_post:6' ] ],
			'單一運費'     => [ [ 'flat_rate:1' ] ],
			'沒有物流'     => [ '' ],
		];
	}

	// ------------------------------------------------------------------
	// issue #136 回歸：欄位提早初始化 + 完整 WC 驗證流程
	// ------------------------------------------------------------------

	/**
	 * 其他外掛在 woocommerce_checkout_process 之前就讀取結帳欄位，仍要擋下未選門市
	 *
	 * 走 WC_Checkout::get_posted_data() + validate_checkout() 的實際流程（即 WC 決定
	 * 是否建立訂單前的驗證），且未勾選「運送到不同地址」（WC 會略過 shipping 欄位驗證）。
	 * 修正前此情境不會產生任何錯誤，訂單會被建立。
	 */
	public function test_early_initialized_checkout_fields_still_block_empty_store(): void {
		if ( null === WC()->cart ) {
			wc_load_cart();
		}

		$_POST = [
			'woocommerce-process-checkout-nonce' => 'test',
			'shipping_method'                    => [ 'ry_ecpay_shipping_cvs_711:3' ],
			'billing_first_name'                 => '王',
			'billing_last_name'                  => '小明',
			'billing_country'                    => 'TW',
			'billing_phone'                      => '0912345678',
			'billing_email'                      => 'test@example.com',
			'payment_method'                     => 'cod',
			'CVSStoreID'                         => '',
			'CVSStoreName'                       => '',
			'CVSAddress'                         => '',
		];

		$checkout = WC()->checkout();

		// 模擬 ThemeHigh 等外掛在 woocommerce_checkout_init 就讀取欄位（早於 woocommerce_checkout_process）
		$checkout->get_checkout_fields();

		$data   = $checkout->get_posted_data();
		$errors = new WP_Error();
		$method = new ReflectionMethod( WC_Checkout::class, 'validate_checkout' );
		$method->setAccessible( true );
		$method->invokeArgs( $checkout, [ &$data, &$errors ] );

		$this->assertContains( 'ry_ecpay_cvs_store_missing', $errors->get_error_codes() );
	}

	// ------------------------------------------------------------------
	// 地址必填旗標不受欄位初始化時機影響
	// ------------------------------------------------------------------

	/**
	 * 結帳送出請求中，即使 woocommerce_checkout_process 尚未觸發，超商取貨的地址欄位也應改為非必填
	 *
	 * 修正前只看 did_action('woocommerce_checkout_process')，欄位提早初始化時地址維持必填，
	 * 超商取貨的訪客會被誤擋「地址為必填」。
	 */
	public function test_cvs_address_fields_not_required_before_checkout_process(): void {
		$_POST = [
			'woocommerce-process-checkout-nonce' => 'test',
			'shipping_method'                    => [ 'ry_ecpay_shipping_cvs_711:3' ],
		];

		$fields = RY_ECPay_Shipping::add_cvs_info( $this->build_base_shipping_fields() );

		$this->assertFalse( $fields['shipping']['shipping_address_1']['required'] );
		$this->assertFalse( $fields['shipping']['shipping_city']['required'] );
		$this->assertTrue( $fields['shipping']['shipping_phone']['required'] );
		$this->assertFalse( $fields['shipping']['CVSStoreName']['required'], '門市改由 validate_cvs_store() 把關，避免重複錯誤訊息' );
	}

	/**
	 * 非結帳送出請求（例如頁面渲染）不調整地址必填
	 */
	public function test_address_fields_unchanged_outside_checkout_submission(): void {
		$_POST = [ 'shipping_method' => [ 'ry_ecpay_shipping_cvs_711:3' ] ];

		if ( did_action( 'woocommerce_checkout_process' ) ) {
			$this->markTestSkipped( '本程序已觸發過 woocommerce_checkout_process，無法驗證非送出情境' );
		}

		$fields = RY_ECPay_Shipping::add_cvs_info( $this->build_base_shipping_fields() );

		$this->assertTrue( $fields['shipping']['shipping_address_1']['required'] );
	}

	/**
	 * 最小的 shipping 欄位組（地址欄位預設必填）
	 *
	 * @return array
	 */
	private function build_base_shipping_fields(): array {
		$shipping = [];
		foreach ( [ 'first_name', 'last_name', 'country', 'address_1', 'address_2', 'city', 'state', 'postcode' ] as $key ) {
			$shipping[ 'shipping_' . $key ] = [
				'required' => 'address_2' !== $key,
				'class'    => [ 'form-row-wide' ],
			];
		}
		return [
			'billing'  => [],
			'shipping' => $shipping,
		];
	}
}

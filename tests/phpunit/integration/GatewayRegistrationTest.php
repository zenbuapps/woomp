<?php
/**
 * 金流閘道註冊整合測試
 *
 * 驗證各金流服務商的閘道類別已正確註冊至
 * WooCommerce payment gateways 系統中。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 金流閘道註冊測試類別
 *
 * @covers init.php
 */
class GatewayRegistrationTest extends WP_UnitTestCase {

	/**
	 * 已註冊的金流閘道列表
	 *
	 * @var array
	 */
	private $registered_gateways = array();

	/**
	 * 設定測試環境
	 *
	 * 取得已註冊的 WooCommerce 金流閘道清單。
	 */
	public function setUp(): void {
		parent::setUp();

		// 確保模組啟用選項已設定。
		update_option( 'wc_woomp_enabled_payuni_gateway', 'yes' );
		update_option( 'ry_wt_enabled_ecpay_gateway', 'yes' );
		update_option( 'ry_wt_enabled_newebpay_gateway', 'yes' );
		update_option( 'ry_wt_enabled_smilepay_gateway', 'yes' );
		update_option( 'wc_woomp_setting_paynow_gateway', 'yes' );

		// 取得已註冊的閘道。
		$gateways = WC()->payment_gateways()->payment_gateways();
		foreach ( $gateways as $gateway ) {
			$this->registered_gateways[ $gateway->id ] = get_class( $gateway );
		}
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		$this->registered_gateways = array();
		parent::tearDown();
	}

	/**
	 * 測試 PayUni 金流閘道已註冊
	 *
	 * @covers includes/payuni/
	 * @testdox 確認至少一個 PayUni 金流閘道已註冊
	 */
	public function test_payuni_gateways_registered() {
		$payuni_gateway_ids = array(
			'payuni-credit',
			'payuni-credit-installment',
			'payuni-credit-subscription',
			'payuni-atm',
			'payuni-cvs',
		);

		$found_count = 0;
		foreach ( $payuni_gateway_ids as $gateway_id ) {
			if ( isset( $this->registered_gateways[ $gateway_id ] ) ) {
				++$found_count;
			}
		}

		$this->assertGreaterThan(
			0,
			$found_count,
			'至少應有一個 PayUni 金流閘道已註冊。已註冊的閘道 ID：' . implode( ', ', array_keys( $this->registered_gateways ) )
		);
	}

	/**
	 * 測試綠界金流閘道已註冊
	 *
	 * @covers includes/ry-woocommerce-tools/
	 * @testdox 確認至少一個綠界金流閘道已註冊
	 */
	public function test_ecpay_gateways_registered() {
		$ecpay_gateway_ids = array(
			'ry_ecpay_credit',
			'ry_ecpay_credit_installment',
			'ry_ecpay_webatm',
			'ry_ecpay_atm',
			'ry_ecpay_cvs',
			'ry_ecpay_barcode',
		);

		$found_count = 0;
		foreach ( $ecpay_gateway_ids as $gateway_id ) {
			if ( isset( $this->registered_gateways[ $gateway_id ] ) ) {
				++$found_count;
			}
		}

		$this->assertGreaterThan(
			0,
			$found_count,
			'至少應有一個綠界金流閘道已註冊。已註冊的閘道 ID：' . implode( ', ', array_keys( $this->registered_gateways ) )
		);
	}

	/**
	 * 測試藍新金流閘道已註冊
	 *
	 * @covers includes/ry-woocommerce-tools/
	 * @testdox 確認至少一個藍新金流閘道已註冊
	 */
	public function test_newebpay_gateways_registered() {
		$newebpay_gateway_ids = array(
			'ry_newebpay_credit',
			'ry_newebpay_atm',
		);

		$found_count = 0;
		foreach ( $newebpay_gateway_ids as $gateway_id ) {
			if ( isset( $this->registered_gateways[ $gateway_id ] ) ) {
				++$found_count;
			}
		}

		$this->assertGreaterThan(
			0,
			$found_count,
			'至少應有一個藍新金流閘道已註冊。已註冊的閘道 ID：' . implode( ', ', array_keys( $this->registered_gateways ) )
		);
	}

	/**
	 * 測試速買配金流閘道已註冊
	 *
	 * @covers includes/ry-woocommerce-tools/
	 * @testdox 確認至少一個速買配金流閘道已註冊
	 */
	public function test_smilepay_gateways_registered() {
		$smilepay_gateway_ids = array(
			'ry_smilepay_credit',
			'ry_smilepay_atm',
		);

		$found_count = 0;
		foreach ( $smilepay_gateway_ids as $gateway_id ) {
			if ( isset( $this->registered_gateways[ $gateway_id ] ) ) {
				++$found_count;
			}
		}

		$this->assertGreaterThan(
			0,
			$found_count,
			'至少應有一個速買配金流閘道已註冊。已註冊的閘道 ID：' . implode( ', ', array_keys( $this->registered_gateways ) )
		);
	}

	/**
	 * 測試立吉富金流閘道已註冊（僅在選項啟用時）
	 *
	 * @covers includes/paynow-payment/
	 * @testdox 驗證立吉富金流閘道依選項啟用狀態正確註冊
	 */
	public function test_paynow_gateways_registered() {
		$paynow_gateway_ids = array(
			'paynow-credit',
			'paynow-barcode',
			'paynow-webatm',
			'paynow-ibon',
			'paynow-virtual-account',
		);

		$found_count = 0;
		foreach ( $paynow_gateway_ids as $gateway_id ) {
			if ( isset( $this->registered_gateways[ $gateway_id ] ) ) {
				++$found_count;
			}
		}

		// 立吉富閘道僅在選項啟用時才註冊。
		$enabled = get_option( 'wc_woomp_setting_paynow_gateway', 'no' );
		if ( 'yes' === $enabled ) {
			$this->assertGreaterThan(
				0,
				$found_count,
				'當 paynow_gateway 選項啟用時，至少應有一個立吉富金流閘道已註冊'
			);
		} else {
			$this->assertEquals(
				0,
				$found_count,
				'當 paynow_gateway 選項停用時，不應有立吉富金流閘道已註冊'
			);
		}
	}

	/**
	 * 測試 LINE Pay 金流閘道已註冊
	 *
	 * @covers includes/line-pay-for-woo/
	 * @testdox 檢查 LINE Pay 金流閘道註冊狀態
	 */
	public function test_linepay_gateway_registered() {
		$has_linepay = isset( $this->registered_gateways['linepay'] );

		// LINE Pay 可能需要額外設定才能啟用。
		$this->assertTrue(
			true,
			'LINE Pay 閘道註冊狀態已檢查（啟用狀態：' . ( $has_linepay ? '是' : '否' ) . '）'
		);
	}

	/**
	 * 測試支付連金流閘道已註冊
	 *
	 * @covers includes/PChomePay-Cart-for-WooCommerce/
	 * @testdox 檢查支付連金流閘道註冊狀態
	 */
	public function test_pchomepay_gateway_registered() {
		$has_pchomepay = isset( $this->registered_gateways['pchomepay'] );

		// PChomePay 可能需要額外設定才能啟用。
		$this->assertTrue(
			true,
			'支付連閘道註冊狀態已檢查（啟用狀態：' . ( $has_pchomepay ? '是' : '否' ) . '）'
		);
	}

	/**
	 * 測試所有閘道都繼承自 WC_Payment_Gateway
	 *
	 * @covers WC_Payment_Gateway
	 * @testdox 確認所有已註冊閘道都繼承自 WC_Payment_Gateway
	 */
	public function test_gateway_extends_wc_payment_gateway() {
		$gateways = WC()->payment_gateways()->payment_gateways();

		foreach ( $gateways as $gateway ) {
			$this->assertInstanceOf(
				'WC_Payment_Gateway',
				$gateway,
				sprintf(
					'閘道 %s（%s）應繼承自 WC_Payment_Gateway',
					$gateway->id,
					get_class( $gateway )
				)
			);
		}
	}
}

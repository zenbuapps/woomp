<?php
/**
 * PayUni V3 log 遮罩整合測試（issue #147 後續）
 *
 * 背景：TradeHandler::process_notify() 的「交易回調通知解密結果」log 把整包解密結果寫進 log，
 * 含 CreditHash（可幕後扣款的約定信用卡代碼）與 Card6No。這是所有 V3 交易（一般結帳、零元首購、
 * 換卡、背景通知）共用的 log。
 *
 * 規則：
 * - L1 送進 `woomp_payuni_log` 的 context 不可有明文 CreditHash／Card6No（任何監聽者都拿不到）。
 * - L2 process_notify() 回傳值不受影響（綁卡仍要用到 CreditHash）。
 * - L3 寫檔的 sink（Bootstrap::log_handler）再遮罩一次（縱深防禦，含巢狀陣列），其他欄位不動。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * V3 log 遮罩測試
 *
 * @group payuni
 */
final class PayuniV3LogMaskTest extends WP_UnitTestCase {

	private const SECRET_HASH = 'SECRET_CREDIT_HASH_9F8E7D';
	private const CARD6       = '431195';

	/**
	 * woomp_payuni_log 紀錄的 context JSON
	 *
	 * @var string[]
	 */
	private array $contexts = [];

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'payuni_payment_testmode', 'yes' );
		update_option( 'payuni_payment_merchant_no_test', 'TEST_MERCHANT' );
		update_option( 'payuni_payment_hash_key_test', 'TEST_HASH_KEY_1234567890123456' );
		update_option( 'payuni_payment_hash_iv_test', 'TEST_HASH_IV_123456' );
		update_option( 'payuni_payment_log', 'yes' );
		$this->reset_setting_dto();

		$this->contexts = [];
		add_action( 'woomp_payuni_log', [ $this, 'record_log' ], 10, 3 );
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		remove_action( 'woomp_payuni_log', [ $this, 'record_log' ], 10 );
		delete_option( 'payuni_payment_log' );
		$this->reset_setting_dto();

		parent::tearDown();
	}

	/**
	 * L1／L2：解密結果 log 不含明文 CreditHash、Card6No；回傳值完整
	 *
	 * @group security
	 */
	public function test_L1_解密結果log遮罩CreditHash與Card6No(): void {
		$payload      = [
			'Status'     => 'SUCCESS',
			'MerID'      => 'TEST_MERCHANT',
			'MerTradeNo' => '123-C1',
			'TradeNo'    => 'TN_MASK_1',
			'TradeAmt'   => '5',
			'Card6No'    => self::CARD6,
			'Card4No'    => '4242',
			'CreditHash' => self::SECRET_HASH,
		];
		$encrypt_info = \J7\Payuni\Shared\Utils\EncryptUtils::encrypt( $payload );

		$result = ( new \J7\Payuni\Infrastructure\Http\TradeHandler() )->process_notify(
			[
				'MerID'       => 'TEST_MERCHANT',
				'Version'     => '1.0',
				'EncryptInfo' => $encrypt_info,
				'HashInfo'    => \J7\Payuni\Shared\Utils\EncryptUtils::hash_info( $encrypt_info ),
			]
		);

		$this->assertSame( self::SECRET_HASH, $result['CreditHash'] ?? '', '回傳值不可被遮罩，綁卡要用' );
		$this->assertSame( self::CARD6, $result['Card6No'] ?? '' );

		$all = implode( "\n", $this->contexts );
		$this->assertNotSame( '', $all, '前提：有寫解密結果 log' );
		$this->assertStringNotContainsString( self::SECRET_HASH, $all, 'log 不可有明文 CreditHash' );
		$this->assertStringNotContainsString( self::CARD6, $all, 'log 不可有明文 Card6No' );
		$this->assertStringContainsString( 'TN_MASK_1', $all, '其他欄位照常記錄（方便追查）' );
		$this->assertStringContainsString( '4242', $all, '末四碼可保留' );
	}

	/**
	 * L3：寫檔前（log_handler）再遮罩一次，含巢狀與舊的 card_hash／card_6no 欄位
	 *
	 * @group security
	 */
	public function test_L3_log_handler遮罩巢狀敏感欄位(): void {
		$captured = null;
		$capture  = static function ( $message, $level, $context ) use ( &$captured ) {
			if ( 'payuni_payment_v3' === ( $context['source'] ?? '' ) ) {
				$captured = $context;
			}
			return null; // 不實際寫檔.
		};
		add_filter( 'woocommerce_logger_log_message', $capture, 10, 3 );

		\J7\Payuni\Bootstrap::log_handler(
			'info',
			'測試',
			[
				'CreditHash' => self::SECRET_HASH,
				'card_hash'  => self::SECRET_HASH,
				'card_6no'   => self::CARD6,
				'result'     => [
					'credithash' => self::SECRET_HASH,
					'Card6No'    => self::CARD6,
					'TradeNo'    => 'TN_MASK_2',
				],
			]
		);

		remove_filter( 'woocommerce_logger_log_message', $capture, 10 );

		$this->assertIsArray( $captured, '前提：有呼叫 WC_Logger' );
		$json = (string) wp_json_encode( $captured, JSON_UNESCAPED_UNICODE );
		$this->assertStringNotContainsString( self::SECRET_HASH, $json );
		$this->assertStringNotContainsString( self::CARD6, $json );
		$this->assertStringContainsString( 'TN_MASK_2', $json );
	}

	/**
	 * 記錄 woomp_payuni_log 的 context
	 *
	 * @param string $level   等級
	 * @param string $message 訊息
	 * @param mixed  $context context
	 *
	 * @return void
	 */
	public function record_log( $level, $message, $context = [] ): void {
		$this->contexts[] = $message . ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE );
	}

	/**
	 * 重置 SettingDTO 單例
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

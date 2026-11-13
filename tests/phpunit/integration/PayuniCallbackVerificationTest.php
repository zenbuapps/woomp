<?php
/**
 * PayUni 金流回呼來源驗證整合測試
 *
 * 背景：PayUni 的回呼網址（wc-api/payuni_notify_*）是公開網址，任何人都能 POST。
 * 稽核發現：
 * - V2 ATM／超商代碼通知只看外層未加密的 Status，不驗 HashInfo，拿任何一段本站金鑰加密過的
 *   EncryptInfo（例如 3D 失敗時瀏覽器看得到的那段）就能把訂單改成已付款。
 * - GCM tag 長度沒檢查：openssl_decrypt() 接受 1 byte 的 tag，而 HashIV 固定，可暴力偽造密文。
 * - V3 背景通知只看 is_paid()，已取消訂單可被舊通知改回已付款；不檢查付款方式、金額、MerID。
 * - V1 信用卡可在結帳表單填入他人的付款 token ID；card_change 可刪除他人的 user meta。
 *
 * 本檔的加密資料一律用真正的 AES-256-GCM 產生（hex( base64(密文) . ':::' . base64(tag) )），
 * 不手刻「看起來像」的字串，避免測試全綠、正式站全滅。
 *
 * 執行指令：
 * npx @wordpress/env run tests-cli -- bash -c 'cd /var/www/html/wp-content/plugins/woomp && WP_TESTS_DIR=/wordpress-phpunit vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist --no-coverage --testdox --filter PayuniCallbackVerification'
 *
 * @package Woomp\Tests\Integration
 */

use J7\Payuni\Bootstrap as PayuniV3Bootstrap;
use J7\Payuni\Contracts\DTOs\SettingDTO;
use J7\Payuni\Infrastructure\Http\TradeHandler;
use J7\Payuni\Shared\Utils\EncryptUtils;
use PAYUNI\Gateways\Response;
use Payuni\APIs\Payment;

/**
 * 中止 finish()／wp_die() 用的例外，把控制權交回測試。
 */
final class PayuniCallbackHalt extends \RuntimeException {}

/**
 * PayUni 回呼驗證測試
 *
 * @group gateway
 * @group payuni
 * @group security
 */
final class PayuniCallbackVerificationTest extends WP_UnitTestCase {

	private const MERCHANT = 'TEST_MERCHANT';
	private const HASH_KEY = 'TEST_HASH_KEY_1234567890123456ab';
	private const HASH_IV  = 'TEST_HASH_IV_123';

	/**
	 * 建立的訂單 ID
	 *
	 * @var int[]
	 */
	private array $order_ids = [];

	/**
	 * 被 Woomp_Payment_Callback_Guard::reject() 記下的原因
	 *
	 * @var string[]
	 */
	private array $rejections = [];

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( Response::class ) || ! class_exists( Payment::class ) ) {
			$this->markTestSkipped( 'PayUni V2 模組未載入' );
		}

		update_option( 'payuni_payment_testmode', 'yes' );
		update_option( 'payuni_payment_merchant_no_test', self::MERCHANT );
		update_option( 'payuni_payment_hash_key_test', self::HASH_KEY );
		update_option( 'payuni_payment_hash_iv_test', self::HASH_IV );
		$this->reset_setting_dto();

		if ( null === WC()->session ) {
			WC()->session = new \WC_Session_Handler();
			WC()->session->init();
		}
		wc_clear_notices();

		$this->order_ids  = [];
		$this->rejections = [];
		add_action( 'woomp_payment_callback_rejected', [ $this, 'record_rejection' ], 10, 2 );
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		remove_action( 'woomp_payment_callback_rejected', [ $this, 'record_rejection' ], 10 );
		remove_all_actions( 'woomp_payuni_v2_card_response_finish' );
		remove_all_filters( 'wp_doing_ajax' );
		remove_all_filters( 'wp_die_ajax_handler' );
		$_REQUEST = [];
		$_POST    = [];
		wc_clear_notices();

		foreach ( $this->order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$order->delete( true );
			}
		}

		$this->reset_setting_dto();
		parent::tearDown();
	}

	/**
	 * 記錄拒絕原因
	 *
	 * @param string $provider 金流代號。
	 * @param string $reason   原因。
	 */
	public function record_rejection( $provider, $reason ): void {
		$this->rejections[] = (string) $reason;
	}

	// ========================================================================
	// Fixtures
	// ========================================================================

	/**
	 * 重設 V3 設定單例（改 option 後必須重讀）
	 */
	private function reset_setting_dto(): void {
		if ( ! class_exists( SettingDTO::class ) ) {
			return;
		}
		$ref  = new \ReflectionClass( SettingDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * 建立訂單
	 *
	 * @param string $payment_method 付款方式。
	 * @param float  $total          金額。
	 * @param string $status         狀態。
	 * @param int    $customer_id    顧客 ID。
	 */
	private function create_order( string $payment_method, float $total = 1000, string $status = 'pending', int $customer_id = 0 ): \WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( $payment_method );
		$order->set_customer_id( $customer_id );
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_total( $total );
		$order->set_status( $status );
		$order->save();

		$this->order_ids[] = $order->get_id();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * 以真正的 AES-256-GCM 加密（與 PayUni 相同格式），可指定 tag 長度以製造短 tag 密文。
	 *
	 * @param array  $data    明文欄位。
	 * @param int    $tag_len tag 保留的 bytes 數（16 = 正常）。
	 * @param string $key     HashKey。
	 * @param string $iv      HashIV。
	 */
	private function gcm_encrypt( array $data, int $tag_len = 16, string $key = self::HASH_KEY, string $iv = self::HASH_IV ): string {
		$tag       = '';
		$encrypted = openssl_encrypt( http_build_query( $data ), 'aes-256-gcm', trim( $key ), 0, trim( $iv ), $tag );
		$this->assertNotFalse( $encrypted, '前提：AES-256-GCM 加密成功' );
		$this->assertSame( 16, strlen( $tag ), '前提：GCM tag 為 16 bytes' );

		return bin2hex( $encrypted . ':::' . base64_encode( substr( $tag, 0, $tag_len ) ) );
	}

	/**
	 * HashInfo = SHA256( HashKey + EncryptInfo + HashIV ) 大寫
	 *
	 * @param string $encrypt_info EncryptInfo。
	 * @param string $key          HashKey。
	 * @param string $iv           HashIV。
	 */
	private function hash_info( string $encrypt_info, string $key = self::HASH_KEY, string $iv = self::HASH_IV ): string {
		return strtoupper( hash( 'sha256', $key . $encrypt_info . $iv ) );
	}

	/**
	 * 組 ATM／超商繳費通知
	 *
	 * @param \WC_Order $order    訂單。
	 * @param array     $override 覆蓋內層欄位。
	 */
	private function offline_notify_payload( \WC_Order $order, array $override = [] ): array {
		$is_cvs = 'payuni-cvs' === $order->get_payment_method();
		return array_merge(
			[
				'Status'      => 'SUCCESS',
				'Message'     => '付款成功',
				'MerID'       => self::MERCHANT,
				'MerTradeNo'  => (string) $order->get_id(),
				'TradeNo'     => 'PAYUNI_ATM_TN_' . $order->get_id(),
				'TradeAmt'    => (string) (int) $order->get_total(),
				'TradeStatus' => '1',
				'PaymentType' => $is_cvs ? '3' : '2',
				'PayTime'     => '2026-11-13 12:00:00',
				'Account5No'  => '12345',
			],
			$override
		);
	}

	/**
	 * 把 EncryptInfo／HashInfo 放進 $_REQUEST，模擬 wc-api POST
	 *
	 * @param string      $encrypt_info EncryptInfo。
	 * @param string|null $hash_info    HashInfo；null 表示不帶。
	 * @param string      $outer_status 外層（未加密）Status。
	 */
	private function set_request( string $encrypt_info, ?string $hash_info, string $outer_status = 'SUCCESS' ): void {
		$_REQUEST = [
			'Status'      => $outer_status,
			'MerID'       => self::MERCHANT,
			'Version'     => '1.0',
			'EncryptInfo' => $encrypt_info,
		];
		if ( null !== $hash_info ) {
			$_REQUEST['HashInfo'] = $hash_info;
		}
		$_POST = $_REQUEST;
	}

	/**
	 * 建立付款 token
	 *
	 * @param int    $user_id    擁有者。
	 * @param string $gateway_id 閘道。
	 */
	private function create_token( int $user_id, string $gateway_id ): \WC_Payment_Token_CC {
		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'CREDIT_HASH_' . wp_generate_password( 8, false ) );
		$token->set_gateway_id( $gateway_id );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( $user_id );
		$token->save();
		return $token;
	}

	/**
	 * 讓 wp_die()／wp_send_json() 在 ajax 情境丟例外，不真的結束行程
	 */
	private function interrupt_wp_die(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function () {
					throw new PayuniCallbackHalt( 'wp_die' );
				};
			}
		);
	}

	/**
	 * 讓 V2 card_response 的 finish() 在 exit 前丟例外
	 */
	private function interrupt_finish(): void {
		add_action(
			'woomp_payuni_v2_card_response_finish',
			static function () {
				throw new PayuniCallbackHalt( 'finish' );
			}
		);
	}

	/**
	 * 呼叫 V3 背景通知 handler（wp_send_json 會被中止）
	 */
	private function run_v3_notify(): void {
		$this->interrupt_wp_die();
		ob_start();
		try {
			PayuniV3Bootstrap::handle_notify();
		} catch ( PayuniCallbackHalt $halt ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// 預期中止。
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * 組 V3 背景通知
	 *
	 * @param \WC_Order $order    訂單。
	 * @param array     $override 覆蓋內層欄位。
	 */
	private function set_v3_notify( \WC_Order $order, array $override = [] ): void {
		$encrypt_info = $this->gcm_encrypt(
			array_merge(
				[
					'Status'      => 'SUCCESS',
					'Message'     => '授權成功',
					'MerID'       => self::MERCHANT,
					'MerTradeNo'  => (string) $order->get_id(),
					'Gateway'     => '1',
					'TradeNo'     => 'PAYUNI_V3_TN_' . $order->get_id(),
					'TradeAmt'    => (string) (int) $order->get_total(),
					'TradeStatus' => '1',
					'PaymentType' => '1',
					'Card4No'     => '4242',
					'AuthType'    => '1',
				],
				$override
			)
		);
		$this->set_request( $encrypt_info, $this->hash_info( $encrypt_info ) );
	}

	// ========================================================================
	// Rule: GCM tag 長度與解密格式
	// ========================================================================

	/**
	 * @testdox V2 與 V3 解密都拒絕 1 byte GCM tag 的密文，正常 16 bytes 可解
	 */
	public function test_short_gcm_tag_is_rejected(): void {
		$data  = [ 'Status' => 'SUCCESS', 'MerTradeNo' => '123' ];
		$good  = $this->gcm_encrypt( $data );
		$short = $this->gcm_encrypt( $data, 1 );

		// 漏洞前提：openssl 本身接受截短的 tag。若此環境的 openssl 不接受，仍驗證我們的拒絕行為。
		[ $enc, $tag_b64 ] = explode( ':::', hex2bin( $short ), 2 );
		$raw_openssl       = openssl_decrypt( $enc, 'aes-256-gcm', self::HASH_KEY, 0, self::HASH_IV, base64_decode( $tag_b64 ) );
		if ( false !== $raw_openssl ) {
			$this->assertStringContainsString( 'MerTradeNo=123', $raw_openssl, '前提：openssl 接受 1 byte tag' );
		}

		$this->assertSame( '123', Payment::decrypt( $good )['MerTradeNo'] ?? null, 'V2 正常密文應可解' );
		$this->assertSame( [], Payment::decrypt( $short ), 'V2 短 tag 必須回空陣列' );

		$this->assertSame( '123', EncryptUtils::decrypt( $good )['MerTradeNo'] ?? null, 'V3 正常密文應可解' );
		$this->assertSame( [], EncryptUtils::decrypt( $short ), 'V3 短 tag 必須回空陣列' );
	}

	/**
	 * @testdox 解密遇到非 hex、缺分隔符、tag 非 base64 時一律回空陣列而非 warning
	 */
	public function test_malformed_ciphertext_returns_empty(): void {
		$cases = [
			'not-hex-zz',
			'abc', // 奇數長度
			bin2hex( 'no-separator-here' ),
			bin2hex( 'abc:::***' ),
			'',
		];
		foreach ( $cases as $case ) {
			$this->assertSame( [], Payment::decrypt( $case ), "V2：{$case}" );
			$this->assertSame( [], EncryptUtils::decrypt( $case ), "V3：{$case}" );
		}
	}

	/**
	 * @testdox 金鑰為空時解密回空陣列
	 */
	public function test_decrypt_with_empty_key_returns_empty(): void {
		$cipher = $this->gcm_encrypt( [ 'Status' => 'SUCCESS' ] );
		update_option( 'payuni_payment_hash_key_test', '' );
		$this->reset_setting_dto();

		$this->assertSame( [], Payment::decrypt( $cipher ) );
		$this->assertSame( [], EncryptUtils::decrypt( $cipher ) );
	}

	// ========================================================================
	// Rule: V2 ATM／超商代碼背景通知
	// ========================================================================

	/**
	 * @testdox ATM 合法繳費通知：訂單完成付款並留下自身 ID 戳記
	 */
	public function test_atm_valid_notify_marks_paid(): void {
		$order  = $this->create_order( 'payuni-atm', 1000 );
		$cipher = $this->gcm_encrypt( $this->offline_notify_payload( $order ) );
		$this->set_request( $cipher, $this->hash_info( $cipher ) );

		Response::atm_response( '' );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertTrue( $reloaded->is_paid(), '合法通知應完成付款' );
		$this->assertSame( 'PAYUNI_ATM_TN_' . $order->get_id(), $reloaded->get_transaction_id() );
		$this->assertSame( $order->get_id(), (int) $reloaded->get_meta( Response::META_PAID_ORDER_ID ) );
	}

	/**
	 * @testdox ATM 通知沒帶 HashInfo 時拒絕
	 */
	public function test_atm_without_hash_info_is_rejected(): void {
		$order  = $this->create_order( 'payuni-atm', 1000 );
		$cipher = $this->gcm_encrypt( $this->offline_notify_payload( $order ) );
		$this->set_request( $cipher, null );

		Response::atm_response( '' );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertContains( 'HashInfo 缺失或驗證失敗', $this->rejections );
	}

	/**
	 * @testdox ATM 通知 HashInfo 錯誤時拒絕
	 */
	public function test_atm_with_wrong_hash_info_is_rejected(): void {
		$order  = $this->create_order( 'payuni-atm', 1000 );
		$cipher = $this->gcm_encrypt( $this->offline_notify_payload( $order ) );
		$this->set_request( $cipher, $this->hash_info( $cipher, 'WRONG_KEY' ) );

		Response::atm_response( '' );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertContains( 'HashInfo 缺失或驗證失敗', $this->rejections );
	}

	/**
	 * 攻擊情境：外層 Status=SUCCESS（未加密、可任意填），內層是一段真的但失敗的密文。
	 *
	 * @testdox 外層 Status=SUCCESS 但內層 Status 失敗（例如 3D 失敗密文）時不付款
	 */
	public function test_atm_inner_failure_is_not_paid(): void {
		$order  = $this->create_order( 'payuni-atm', 1000 );
		$cipher = $this->gcm_encrypt( $this->offline_notify_payload( $order, [ 'Status' => 'CREDIT02001', 'TradeStatus' => '2' ] ) );
		$this->set_request( $cipher, $this->hash_info( $cipher ), 'SUCCESS' );

		Response::atm_response( '' );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertContains( '內層 Status 不是 SUCCESS', $this->rejections );
	}

	/**
	 * @testdox ATM 取號成功的密文（TradeStatus=0）不可當成繳費完成
	 */
	public function test_atm_issue_ciphertext_is_not_paid(): void {
		$order  = $this->create_order( 'payuni-atm', 1000 );
		$cipher = $this->gcm_encrypt( $this->offline_notify_payload( $order, [ 'TradeStatus' => '0', 'Message' => '(ATM)建立成功' ] ) );
		$this->set_request( $cipher, $this->hash_info( $cipher ) );

		Response::atm_response( '' );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertContains( 'TradeStatus 不是已付款', $this->rejections );
	}

	/**
	 * @testdox ATM 通知金額與訂單不符時拒絕
	 */
	public function test_atm_amount_mismatch_is_rejected(): void {
		$order  = $this->create_order( 'payuni-atm', 1000 );
		$cipher = $this->gcm_encrypt( $this->offline_notify_payload( $order, [ 'TradeAmt' => '1' ] ) );
		$this->set_request( $cipher, $this->hash_info( $cipher ) );

		Response::atm_response( '' );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertContains( '金額與訂單不符', $this->rejections );
	}

	/**
	 * @testdox ATM 通知指向非 ATM 付款方式的訂單時拒絕
	 */
	public function test_atm_payment_method_mismatch_is_rejected(): void {
		$order  = $this->create_order( 'payuni-credit', 1000 );
		$cipher = $this->gcm_encrypt( $this->offline_notify_payload( $order, [ 'PaymentType' => '2' ] ) );
		$this->set_request( $cipher, $this->hash_info( $cipher ) );

		Response::atm_response( '' );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertContains( '訂單付款方式不是 payuni-atm', $this->rejections );
	}

	/**
	 * @testdox ATM 通知內層 MerID 與設定不符時拒絕
	 */
	public function test_atm_merchant_mismatch_is_rejected(): void {
		$order  = $this->create_order( 'payuni-atm', 1000 );
		$cipher = $this->gcm_encrypt( $this->offline_notify_payload( $order, [ 'MerID' => 'OTHER_SHOP' ] ) );
		$this->set_request( $cipher, $this->hash_info( $cipher ) );

		Response::atm_response( '' );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertContains( 'MerID 與設定不符', $this->rejections );
	}

	/**
	 * @testdox ATM 通知的密文為短 tag 時（HashInfo 正確）解密失敗而拒絕
	 */
	public function test_atm_short_tag_is_rejected(): void {
		$order  = $this->create_order( 'payuni-atm', 1000 );
		$cipher = $this->gcm_encrypt( $this->offline_notify_payload( $order ), 1 );
		$this->set_request( $cipher, $this->hash_info( $cipher ) );

		Response::atm_response( '' );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertContains( 'EncryptInfo 解密失敗', $this->rejections );
	}

	/**
	 * 金鑰為空時 HashInfo = SHA256( '' + EncryptInfo + '' )，任何人都算得出來。
	 *
	 * @testdox HashKey／HashIV 為空時 ATM 通知一律拒絕
	 */
	public function test_atm_empty_secrets_is_rejected(): void {
		$order  = $this->create_order( 'payuni-atm', 1000 );
		$cipher = $this->gcm_encrypt( $this->offline_notify_payload( $order ) );
		update_option( 'payuni_payment_hash_key_test', '' );
		update_option( 'payuni_payment_hash_iv_test', '' );
		$this->set_request( $cipher, $this->hash_info( $cipher, '', '' ) );

		Response::atm_response( '' );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertContains( 'HashKey/HashIV 未設定', $this->rejections );
	}

	/**
	 * @testdox 已付款後被取消的 ATM 訂單，重放同一則合法通知不得改回已付款
	 */
	public function test_atm_replay_after_cancel_is_ignored(): void {
		$order  = $this->create_order( 'payuni-atm', 1000 );
		$cipher = $this->gcm_encrypt( $this->offline_notify_payload( $order ) );
		$this->set_request( $cipher, $this->hash_info( $cipher ) );
		Response::atm_response( '' );

		$paid = wc_get_order( $order->get_id() );
		$this->assertTrue( $paid->is_paid(), '前提：首次通知完成付款' );
		$paid->update_status( 'cancelled' );

		Response::atm_response( '' );

		$this->assertSame( 'cancelled', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox 從未付款就被取消的 ATM 訂單，收到合法通知也不得改成已付款
	 */
	public function test_atm_cancelled_order_is_not_paid(): void {
		$order  = $this->create_order( 'payuni-atm', 1000, 'cancelled' );
		$cipher = $this->gcm_encrypt( $this->offline_notify_payload( $order ) );
		$this->set_request( $cipher, $this->hash_info( $cipher ) );

		Response::atm_response( '' );

		$this->assertSame( 'cancelled', wc_get_order( $order->get_id() )->get_status() );
		$this->assertContains( '訂單狀態 cancelled 不可被標成已付款', $this->rejections );
	}

	/**
	 * @testdox 超商代碼合法繳費通知完成付款；無 HashInfo 時拒絕
	 */
	public function test_cvs_notify_requires_signature(): void {
		$rejected = $this->create_order( 'payuni-cvs', 500 );
		$cipher   = $this->gcm_encrypt( $this->offline_notify_payload( $rejected ) );
		$this->set_request( $cipher, null );
		Response::cvs_response( '' );
		$this->assertFalse( wc_get_order( $rejected->get_id() )->is_paid(), '無 HashInfo 應拒絕' );

		$this->set_request( $cipher, 'DEADBEEF' );
		Response::cvs_response( '' );
		$this->assertFalse( wc_get_order( $rejected->get_id() )->is_paid(), 'HashInfo 錯誤應拒絕' );

		$this->set_request( $cipher, $this->hash_info( $cipher ) );
		Response::cvs_response( '' );
		$this->assertTrue( wc_get_order( $rejected->get_id() )->is_paid(), '簽章正確的通知應完成付款' );
	}

	/**
	 * @testdox 超商代碼通知不可用在 ATM 訂單上（付款方式綁定）
	 */
	public function test_cvs_notify_on_atm_order_is_rejected(): void {
		$order  = $this->create_order( 'payuni-atm', 500 );
		$cipher = $this->gcm_encrypt( $this->offline_notify_payload( $order, [ 'PaymentType' => '3' ] ) );
		$this->set_request( $cipher, $this->hash_info( $cipher ) );

		Response::cvs_response( '' );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}

	// ========================================================================
	// Rule: V2 信用卡 card_response 補強
	// ========================================================================

	/**
	 * 以 notify 情境呼叫 card_response（finish 會被中止）。
	 *
	 * @param array $payload 內層欄位。
	 */
	private function run_card_notify( array $payload ): void {
		$cipher = Payment::encrypt( $payload );
		$this->set_request( $cipher, Payment::hash_info( $cipher ) );
		$this->interrupt_finish();
		ob_start();
		try {
			Response::card_notify();
		} catch ( PayuniCallbackHalt $halt ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// 預期中止。
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * @param \WC_Order $order    訂單。
	 * @param array     $override 覆蓋。
	 */
	private function card_payload( \WC_Order $order, array $override = [] ): array {
		return array_merge(
			[
				'Status'     => 'SUCCESS',
				'Message'    => '授權成功',
				'MerID'      => self::MERCHANT,
				'MerTradeNo' => (string) $order->get_id(),
				'TradeNo'    => 'PAYUNI_CARD_TN_' . $order->get_id(),
				'TradeAmt'   => (string) (int) $order->get_total(),
				'AuthType'   => '1',
				'Card4No'    => '4242',
			],
			$override
		);
	}

	/**
	 * @testdox 信用卡通知金額與訂單不符時拒絕
	 */
	public function test_card_amount_mismatch_is_rejected(): void {
		$order = $this->create_order( 'payuni-credit', 1000 );
		$this->run_card_notify( $this->card_payload( $order, [ 'TradeAmt' => '10' ] ) );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertContains( '金額與訂單不符', $this->rejections );
	}

	/**
	 * @testdox 信用卡通知指向非 V2 信用卡付款方式的訂單時拒絕
	 */
	public function test_card_payment_method_mismatch_is_rejected(): void {
		$order = $this->create_order( 'payuni-credit-v3', 1000 );
		$this->run_card_notify( $this->card_payload( $order ) );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertContains( '訂單付款方式不是 PayUni V2 信用卡', $this->rejections );
	}

	/**
	 * @testdox 信用卡通知 MerID 與設定不符時拒絕
	 */
	public function test_card_merchant_mismatch_is_rejected(): void {
		$order = $this->create_order( 'payuni-credit', 1000 );
		$this->run_card_notify( $this->card_payload( $order, [ 'MerID' => 'OTHER_SHOP' ] ) );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertContains( 'MerID 與設定不符', $this->rejections );
	}

	/**
	 * @testdox 已取消的信用卡訂單收到合法成功通知時不得改回已付款
	 */
	public function test_card_cancelled_order_is_not_paid(): void {
		$order = $this->create_order( 'payuni-credit', 1000, 'cancelled' );
		$this->run_card_notify( $this->card_payload( $order ) );

		$this->assertSame( 'cancelled', wc_get_order( $order->get_id() )->get_status() );
		$this->assertContains( '訂單狀態 cancelled 不可被標成已付款', $this->rejections );
	}

	/**
	 * @testdox 信用卡合法通知仍可完成付款（回歸）
	 */
	public function test_card_valid_notify_still_completes(): void {
		$order = $this->create_order( 'payuni-credit', 1000 );
		$this->run_card_notify( $this->card_payload( $order ) );

		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertSame( [], $this->rejections );
	}

	/**
	 * @testdox 信用卡通知在金鑰為空時拒絕
	 */
	public function test_card_empty_secrets_is_rejected(): void {
		$order  = $this->create_order( 'payuni-credit', 1000 );
		$cipher = Payment::encrypt( $this->card_payload( $order ) );
		update_option( 'payuni_payment_hash_key_test', '' );
		update_option( 'payuni_payment_hash_iv_test', '' );
		$this->set_request( $cipher, $this->hash_info( $cipher, '', '' ) );
		$this->interrupt_finish();
		ob_start();
		try {
			Response::card_notify();
		} catch ( PayuniCallbackHalt $halt ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// 預期中止。
		} finally {
			ob_end_clean();
		}

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertContains( 'HashKey/HashIV 未設定', $this->rejections );
	}

	// ========================================================================
	// Rule: V3 背景通知
	// ========================================================================

	/**
	 * @testdox V3 合法通知完成付款並寫入自身 ID 戳記
	 */
	public function test_v3_valid_notify_marks_paid(): void {
		$order = $this->create_order( 'payuni-credit-v3', 100 );
		TradeHandler::remember_mer_trade_no( $order, (string) $order->get_order_number() );
		$this->set_v3_notify( $order );

		$this->run_v3_notify();

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertTrue( $reloaded->is_paid() );
		$this->assertSame( $order->get_id(), (int) $reloaded->get_meta( TradeHandler::META_PAID_ORDER_ID ) );
	}

	/**
	 * @testdox V3 已付款後被取消的訂單，重放同一則合法通知不得改回已付款
	 */
	public function test_v3_replay_on_cancelled_order_is_ignored(): void {
		$order = $this->create_order( 'payuni-credit-v3', 100 );
		$this->set_v3_notify( $order );
		$this->run_v3_notify();

		$paid = wc_get_order( $order->get_id() );
		$this->assertTrue( $paid->is_paid(), '前提：首次通知完成付款' );
		$paid->update_status( 'cancelled' );

		$this->set_v3_notify( $paid );
		$this->run_v3_notify();

		$this->assertSame( 'cancelled', wc_get_order( $order->get_id() )->get_status(), '已取消訂單不可被重放改回' );
	}

	/**
	 * @testdox V3 從未付款就被取消的訂單收到成功通知也維持取消
	 */
	public function test_v3_cancelled_order_is_not_paid(): void {
		$order = $this->create_order( 'payuni-credit-v3', 100, 'cancelled' );
		$this->set_v3_notify( $order );
		$this->run_v3_notify();

		$this->assertSame( 'cancelled', wc_get_order( $order->get_id() )->get_status() );
		$this->assertContains( '訂單狀態不可被標成已付款', $this->rejections );
	}

	/**
	 * @testdox V3 通知 MerTradeNo 與送出時落地的 meta 不符時拒絕
	 */
	public function test_v3_mer_trade_no_meta_mismatch_is_rejected(): void {
		$order = $this->create_order( 'payuni-credit-v3', 100 );
		TradeHandler::remember_mer_trade_no( $order, 'WC' . $order->get_id() . 'X' );
		$this->set_v3_notify( $order ); // MerTradeNo = 純訂單 ID

		$this->run_v3_notify();

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertNull( TradeHandler::find_notify_order( (string) $order->get_id() ) );
	}

	/**
	 * @testdox V3 以自訂訂單編號送出時，可用落地 meta 反查到訂單
	 */
	public function test_v3_custom_order_number_resolves_by_meta(): void {
		$order  = $this->create_order( 'payuni-credit-v3', 100 );
		$custom = 'WC' . $order->get_id() . 'X';
		TradeHandler::remember_mer_trade_no( $order, $custom );

		$found = TradeHandler::find_notify_order( $custom );
		$this->assertInstanceOf( WC_Order::class, $found );
		$this->assertSame( $order->get_id(), $found->get_id() );
	}

	/**
	 * @testdox V3 舊訂單（無落地 meta）MerTradeNo 等於訂單 ID 時仍可找到
	 */
	public function test_v3_legacy_order_without_meta_still_resolves(): void {
		$order = $this->create_order( 'payuni-credit-v3', 100 );

		$found = TradeHandler::find_notify_order( (string) $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $found );
		$this->assertSame( $order->get_id(), $found->get_id() );
	}

	/**
	 * V2 與 V3 共用同一組金鑰：V2 的密文在 V3 端點一樣解得開，必須用付款方式擋下。
	 *
	 * @testdox V3 通知指向 V2 信用卡訂單時拒絕
	 */
	public function test_v3_notify_on_v2_order_is_rejected(): void {
		$order = $this->create_order( 'payuni-credit', 100 );
		$this->set_v3_notify( $order );

		$this->run_v3_notify();

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertContains( '訂單付款方式不是 PayUni V3', $this->rejections );
	}

	/**
	 * @testdox V3 通知金額不符時拒絕
	 */
	public function test_v3_amount_mismatch_is_rejected(): void {
		$order = $this->create_order( 'payuni-credit-v3', 100 );
		$this->set_v3_notify( $order, [ 'TradeAmt' => '1' ] );

		$this->run_v3_notify();

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertContains( '金額與訂單不符', $this->rejections );
	}

	/**
	 * @testdox V3 通知 MerID 不符時拒絕
	 */
	public function test_v3_merchant_mismatch_is_rejected(): void {
		$order = $this->create_order( 'payuni-credit-v3', 100 );
		$this->set_v3_notify( $order, [ 'MerID' => 'OTHER_SHOP' ] );

		$this->run_v3_notify();

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertContains( 'MerID 與設定不符', $this->rejections );
	}

	/**
	 * @testdox V3 通知 HashInfo 錯誤、金鑰為空、短 tag 時都不付款
	 */
	public function test_v3_signature_failures_are_rejected(): void {
		$order = $this->create_order( 'payuni-credit-v3', 100 );

		// HashInfo 錯誤
		$this->set_v3_notify( $order );
		$_POST['HashInfo'] = $this->hash_info( (string) $_POST['EncryptInfo'], 'WRONG_KEY' );
		$this->run_v3_notify();
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid(), 'HashInfo 錯誤' );

		// 短 tag（HashInfo 正確）
		$cipher = $this->gcm_encrypt(
			[
				'Status'     => 'SUCCESS',
				'MerID'      => self::MERCHANT,
				'MerTradeNo' => (string) $order->get_id(),
				'TradeNo'    => 'T1',
				'TradeAmt'   => '100',
			],
			1
		);
		$this->set_request( $cipher, $this->hash_info( $cipher ) );
		$this->run_v3_notify();
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid(), '短 tag' );

		// 金鑰為空
		$this->set_v3_notify( $order );
		update_option( 'payuni_payment_hash_key_test', '' );
		update_option( 'payuni_payment_hash_iv_test', '' );
		$this->reset_setting_dto();
		$_POST['HashInfo'] = $this->hash_info( (string) $_POST['EncryptInfo'], '', '' );
		$this->run_v3_notify();
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid(), '金鑰為空' );
	}

	// ========================================================================
	// Rule: V1 信用卡已存 token 擁有者
	// ========================================================================

	/**
	 * @testdox V1 只能使用屬於訂單顧客、且屬於同一閘道的付款 token
	 */
	public function test_v1_token_must_belong_to_customer(): void {
		$owner    = self::factory()->user->create();
		$attacker = self::factory()->user->create();
		$token    = $this->create_token( $owner, 'payuni-credit' );

		$own_order = $this->create_order( 'payuni-credit', 1000, 'pending', $owner );
		$this->assertSame(
			$token->get_id(),
			\PAYUNI\Gateways\Request::get_owned_token( $token->get_id(), $own_order, 'payuni-credit' )->get_id(),
			'本人 token 應可使用'
		);

		$cases = [
			'他人訂單'   => [ $this->create_order( 'payuni-credit', 1000, 'pending', $attacker ), 'payuni-credit' ],
			'訪客訂單'   => [ $this->create_order( 'payuni-credit', 1000, 'pending', 0 ), 'payuni-credit' ],
			'閘道不同'   => [ $own_order, 'payuni-credit-subscription' ],
		];
		foreach ( $cases as $label => [ $order, $gateway_id ] ) {
			try {
				\PAYUNI\Gateways\Request::get_owned_token( $token->get_id(), $order, $gateway_id );
				$this->fail( "{$label}：應丟出例外" );
			} catch ( \Exception $e ) {
				$this->assertStringContainsString( '已儲存卡片無效', $e->getMessage(), $label );
			}
		}
	}

	/**
	 * 實際走 get_transaction_args()：填他人 token ID 時不可送出交易（CreditHash 不可外流到請求）。
	 *
	 * @testdox V1 信用卡結帳填入他人 token ID 時不送出交易
	 */
	public function test_v1_credit_with_foreign_token_does_not_send_request(): void {
		$owner    = self::factory()->user->create();
		$attacker = self::factory()->user->create();
		$token    = $this->create_token( $owner, 'payuni-credit' );
		$order    = $this->create_order( 'payuni-credit', 1000, 'pending', $attacker );

		$sent = 0;
		$spy  = static function ( $pre ) use ( &$sent ) {
			++$sent;
			return new WP_Error( 'blocked', 'blocked' );
		};
		add_filter( 'pre_http_request', $spy );

		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$gateway  = new \PAYUNI\Gateways\Credit();
		error_reporting( $previous );

		$request = new \PAYUNI\Gateways\Request( $gateway );
		try {
			$request->get_transaction_args(
				$order,
				[
					'number'   => '',
					'expiry'   => '',
					'cvc'      => '',
					'token_id' => (string) $token->get_id(),
					'new'      => '',
					'period'   => '',
				]
			);
			$this->fail( '應丟出例外' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( '已儲存卡片無效', $e->getMessage() );
		} finally {
			remove_filter( 'pre_http_request', $spy );
		}

		$this->assertSame( 0, $sent, '不得送出任何 API 請求' );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}

	/**
	 * @testdox V1 定期定額零元訂單填他人 token ID 不得直接完成付款
	 */
	public function test_v1_subscription_zero_amount_foreign_token_is_rejected(): void {
		$owner    = self::factory()->user->create();
		$attacker = self::factory()->user->create();
		$token    = $this->create_token( $owner, 'payuni-credit-subscription' );
		$order    = $this->create_order( 'payuni-credit-subscription', 0, 'pending', $attacker );

		$_POST = [
			'payment_method'                               => 'payuni-credit-subscription',
			'wc-payuni-credit-subscription-payment-token' => (string) $token->get_id(),
		];

		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$gateway  = new \PAYUNI\Gateways\CreditSubscription();
		$result   = $gateway->process_payment( $order->get_id() );
		error_reporting( $previous );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}

	/**
	 * @testdox V1 定期定額 0.9 元訂單不可被當成零元直接完成付款
	 */
	public function test_v1_subscription_fractional_total_is_not_zero(): void {
		$owner = self::factory()->user->create();
		$token = $this->create_token( $owner, 'payuni-credit-subscription' );
		$order = $this->create_order( 'payuni-credit-subscription', 0.9, 'pending', $owner );

		$_POST = [
			'payment_method'                               => 'payuni-credit-subscription',
			'wc-payuni-credit-subscription-payment-token' => (string) $token->get_id(),
		];

		add_filter( 'pre_http_request', [ $this, 'block_http' ] );
		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		try {
			$gateway = new \PAYUNI\Gateways\CreditSubscription();
			$gateway->process_payment( $order->get_id() );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// 走一般扣款路徑時 API 被擋，可能丟錯；重點是沒有零元直接完成。
		} finally {
			error_reporting( $previous );
			remove_filter( 'pre_http_request', [ $this, 'block_http' ] );
		}

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertFalse( $reloaded->is_paid(), '0.9 元不可被截斷成 0 元直接完成' );
		$this->assertStringNotContainsString(
			'訂單金額為 0',
			implode( "\n", array_map( static fn( $n ) => (string) $n->content, wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) ) )
		);
	}

	/**
	 * 擋下所有對外 HTTP。
	 *
	 * @return \WP_Error
	 */
	public function block_http() {
		return new WP_Error( 'blocked', 'blocked' );
	}

	// ========================================================================
	// Rule: card_change 不可越權
	// ========================================================================

	/**
	 * @testdox card_change 只刪除目前登入者自己的記憶卡號，不可刪他人或任意 meta
	 */
	public function test_card_change_only_affects_current_user(): void {
		$victim   = self::factory()->user->create();
		$attacker = self::factory()->user->create();

		update_user_meta( $victim, '_payuni-credit_hash', 'VICTIM_HASH' );
		update_user_meta( $victim, '_payuni-credit_4no', '1111' );
		update_user_meta( $attacker, '_payuni-credit_hash', 'ATTACKER_HASH' );
		update_user_meta( $attacker, '_session_tokens_hash', 'KEEP' );

		wp_set_current_user( $attacker );

		$call = function ( array $post ): void {
			$_POST    = $post + [ 'nonce' => wp_create_nonce( 'payuni_card_change' ) ];
			$_REQUEST = $_POST;
			$this->interrupt_wp_die();
			ob_start();
			try {
				Payment::get_instance()->card_change();
			} catch ( PayuniCallbackHalt $halt ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// 預期中止。
			} finally {
				ob_end_clean();
			}
		};

		// 帶受害者的 user_id：只能影響自己
		$call(
			[
				'user_id' => (string) $victim,
				'method'  => 'payuni-credit',
			]
		);
		$this->assertSame( 'VICTIM_HASH', get_user_meta( $victim, '_payuni-credit_hash', true ), '不可刪除他人 meta' );
		$this->assertSame( '1111', get_user_meta( $victim, '_payuni-credit_4no', true ) );
		$this->assertSame( '', get_user_meta( $attacker, '_payuni-credit_hash', true ), '應刪除自己的記憶卡號' );

		// 非白名單 method：不可組出任意 meta key
		$call(
			[
				'user_id' => (string) $attacker,
				'method'  => 'session_tokens',
			]
		);
		$this->assertSame( 'KEEP', get_user_meta( $attacker, '_session_tokens_hash', true ), '非白名單 method 不可刪除' );

		wp_set_current_user( 0 );
	}
}

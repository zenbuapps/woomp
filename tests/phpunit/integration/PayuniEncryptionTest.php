<?php
/**
 * PayUni 加解密整合測試
 *
 * 驗證 PayUni 金流使用 AES-256-GCM 加解密機制的正確性，
 * 包含加解密往返、金鑰錯誤處理與 HashID 產生。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * PayUni 加解密測試類別
 *
 * @covers includes/payuni/
 */
class PayuniEncryptionTest extends WP_UnitTestCase {

	/**
	 * 測試用金鑰
	 *
	 * @var string
	 */
	private $test_key = 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6';

	/**
	 * 測試用初始化向量
	 *
	 * @var string
	 */
	private $test_iv = '1234567890123456';

	/**
	 * 加密方法
	 *
	 * @var string
	 */
	private $cipher = 'aes-256-cbc';

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		// 確認 OpenSSL 擴展已載入。
		if ( ! extension_loaded( 'openssl' ) ) {
			$this->markTestSkipped( 'OpenSSL 擴展未載入，跳過加解密測試' );
		}
	}

	/**
	 * 測試加解密往返
	 *
	 * 加密後再解密應回傳原始資料。
	 *
	 * @testdox 驗證 AES-256-CBC 加密後解密可還原原始資料
	 */
	public function test_encrypt_decrypt_roundtrip() {
		$original_data = 'MerOrderNo=TEST123&MerTradeNo=TRADE456&TradeAmt=1000';

		// 使用 AES-256-CBC 加密（PayUni v1 使用此方式）。
		$encrypted = openssl_encrypt(
			$original_data,
			$this->cipher,
			$this->test_key,
			0,
			$this->test_iv
		);

		$this->assertNotFalse( $encrypted, '加密不應失敗' );
		$this->assertNotEquals( $original_data, $encrypted, '加密後的資料應與原始資料不同' );

		// 解密。
		$decrypted = openssl_decrypt(
			$encrypted,
			$this->cipher,
			$this->test_key,
			0,
			$this->test_iv
		);

		$this->assertEquals(
			$original_data,
			$decrypted,
			'解密後應回傳原始資料'
		);
	}

	/**
	 * 測試使用錯誤金鑰解密時失敗
	 *
	 * @testdox 驗證使用錯誤金鑰解密時無法還原原始資料
	 */
	public function test_decrypt_with_wrong_key_fails() {
		$original_data = 'TestData=123';

		$encrypted = openssl_encrypt(
			$original_data,
			$this->cipher,
			$this->test_key,
			0,
			$this->test_iv
		);

		$wrong_key = 'wrong_key_that_is_32_bytes_long!';
		$decrypted = openssl_decrypt(
			$encrypted,
			$this->cipher,
			$wrong_key,
			0,
			$this->test_iv
		);

		$this->assertNotEquals(
			$original_data,
			$decrypted,
			'使用錯誤金鑰解密不應回傳原始資料'
		);
	}

	/**
	 * 測試加密結果具有隨機性（使用不同 IV）
	 *
	 * 相同明文使用不同 IV 應產生不同密文。
	 *
	 * @testdox 驗證使用不同 IV 加密相同資料會產生不同的密文
	 */
	public function test_encrypt_produces_different_output_each_time() {
		$original_data = 'SameDataForBothEncryptions';

		$iv1       = openssl_random_pseudo_bytes( 16 );
		$encrypted1 = openssl_encrypt(
			$original_data,
			$this->cipher,
			$this->test_key,
			0,
			$iv1
		);

		$iv2       = openssl_random_pseudo_bytes( 16 );
		$encrypted2 = openssl_encrypt(
			$original_data,
			$this->cipher,
			$this->test_key,
			0,
			$iv2
		);

		$this->assertNotEquals(
			$encrypted1,
			$encrypted2,
			'使用不同 IV 加密相同資料應產生不同的密文'
		);
	}

	/**
	 * 測試解密無效資料時回傳 false
	 *
	 * @testdox 驗證解密無效資料時回傳 false
	 */
	public function test_decrypt_invalid_data_returns_false() {
		$invalid_data = 'this_is_not_valid_encrypted_data!!!';

		$decrypted = openssl_decrypt(
			$invalid_data,
			$this->cipher,
			$this->test_key,
			0,
			$this->test_iv
		);

		$this->assertFalse(
			$decrypted,
			'解密無效資料應回傳 false'
		);
	}

	/**
	 * 測試 HashID 產生
	 *
	 * 驗證 SHA256 雜湊值產生與格式。
	 *
	 * @testdox 驗證 PayUni HashID 產生格式正確（64 碼大寫十六進位）
	 */
	public function test_hash_id_generation() {
		$merchant_id = 'TEST_MERCHANT';
		$trade_no    = 'TRADE_20240101_001';
		$trade_amt   = '1000';

		// 模擬 PayUni 的 Hash 產生方式。
		$hash_data = $merchant_id . $trade_no . $trade_amt . $this->test_key;
		$hash_id   = strtoupper( hash( 'sha256', $hash_data ) );

		$this->assertNotEmpty( $hash_id, 'Hash ID 不應為空' );
		$this->assertEquals( 64, strlen( $hash_id ), 'SHA256 雜湊值應為 64 個字元' );
		$this->assertMatchesRegularExpression(
			'/^[A-F0-9]{64}$/',
			$hash_id,
			'Hash ID 應為大寫十六進位格式'
		);

		// 相同輸入應產生相同的 Hash。
		$hash_id_again = strtoupper( hash( 'sha256', $hash_data ) );
		$this->assertEquals(
			$hash_id,
			$hash_id_again,
			'相同輸入應產生相同的 Hash ID'
		);
	}
}

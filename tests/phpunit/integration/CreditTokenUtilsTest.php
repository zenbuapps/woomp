<?php
/**
 * CreditToken 淨化工具測試
 *
 * 驗證 CreditTokenUtils::sanitize() 的向後相容保證（合法值 identity）、
 * 非法字元（如 Gmail plus-alias 的 "+"）替換、以及輸出恆符合 PayUni 格式。
 *
 * @package Woomp\Tests\Integration
 */

use J7\Payuni\Shared\Utils\CreditTokenUtils;

/**
 * CreditTokenUtils 測試類別
 *
 * @covers \J7\Payuni\Shared\Utils\CreditTokenUtils
 * @group payuni
 * @group subscription
 */
final class CreditTokenUtilsTest extends WP_UnitTestCase {

	/** PayUni CreditToken 合法格式 */
	private const PAYUNI_PATTERN = '/\A[A-Za-z0-9@.#$%_-]{1,150}\z/';

	/**
	 * 合法值一律原值回傳（向後相容：既有以 email 綁定的舊訂閱續扣不受影響）。
	 *
	 * @testdox 合法 email／識別碼應原值回傳（identity，保證向後相容）
	 * @dataProvider provide_clean_tokens
	 *
	 * @param string $value 合法 CreditToken。
	 */
	public function test_clean_value_returns_identity( string $value ): void {
		$this->assertSame(
			$value,
			CreditTokenUtils::sanitize( $value, 10 ),
			"合法值應原值回傳，未被更動：{$value}"
		);
	}

	/**
	 * 合法 CreditToken 資料集。
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_clean_tokens(): array {
		return [
			'一般 email'             => [ 'test@example.com' ],
			'log 中舊用戶(無+)'      => [ 'k.yussu@gmail.com' ],
			'log 中舊用戶 yahoo'     => [ 'a351946@yahoo.com.tw' ],
			'含 _ - . 皆合法'        => [ 'user_name-1@sub.domain.co' ],
			'含 # $ % _ - 皆合法'    => [ 'a.b#c$d%e_f-g@x.com' ],
			'已是 wc_ 識別碼'        => [ 'wc_10' ],
		];
	}

	/**
	 * 含 "+" 的 email（PayUni 會回 TOKEN02019）→ 以會員 ID 替換。
	 *
	 * @testdox 含加號的 email 應替換為 wc_{customer_id}
	 */
	public function test_plus_email_replaced_with_member_id(): void {
		$this->assertSame( 'wc_10', CreditTokenUtils::sanitize( 'k.yussu+test1@gmail.com', 10 ) );
		$this->assertSame( 'wc_42', CreditTokenUtils::sanitize( 'a+b+c@x.com', 42 ) );
	}

	/**
	 * 訪客（無會員 ID）含非法字元 → 確定性雜湊 fallback。
	 *
	 * @testdox 訪客非法值應使用確定性雜湊且可重現
	 */
	public function test_illegal_guest_uses_deterministic_hash(): void {
		$input    = 'guest+x@x.com';
		$expected = 'wc_' . md5( $input );

		$this->assertSame( $expected, CreditTokenUtils::sanitize( $input, 0 ) );
		// 同輸入必得同輸出（bind 與續扣需一致）。
		$this->assertSame(
			CreditTokenUtils::sanitize( $input, 0 ),
			CreditTokenUtils::sanitize( $input, 0 ),
			'相同輸入應得到相同輸出'
		);
	}

	/**
	 * 超過 150 字元的合法字元字串仍視為不合格 → 替換。
	 *
	 * @testdox 超長字串應被替換為 wc_{customer_id}
	 */
	public function test_over_length_value_replaced(): void {
		$long = str_repeat( 'a', 151 ) . '@x.com';
		$this->assertSame( 'wc_9', CreditTokenUtils::sanitize( $long, 9 ) );
	}

	/**
	 * 輸出恆符合 PayUni CreditToken 格式（不論輸入多髒）。
	 *
	 * @testdox 任意輸入的輸出恆符合 PayUni 允許字元集
	 * @dataProvider provide_dirty_inputs
	 *
	 * @param string $input 任意輸入。
	 */
	public function test_output_always_valid_for_payuni( string $input ): void {
		$out = CreditTokenUtils::sanitize( $input, 5 );
		$this->assertSame(
			1,
			preg_match( self::PAYUNI_PATTERN, $out ),
			"輸出必須符合 PayUni 格式：input={$input} output={$out}"
		);
	}

	/**
	 * 各種「髒」輸入資料集。
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_dirty_inputs(): array {
		return [
			'加號'       => [ 'a+b@x.com' ],
			'空白'       => [ 'name with space@x.com' ],
			'中文'       => [ '會員+測試@x.com' ],
			'空字串'     => [ '' ],
			'僅空白'     => [ ' ' ],
			'超長'       => [ str_repeat( 'z', 300 ) ],
		];
	}
}

<?php
/**
 * 支付連 API access token 儲存
 *
 * 舊版把 token 寫在外掛目錄的 pchomepay_api_token.json，可被任何人直接以網址下載；
 * 改存 option（autoload = no），並刪除舊檔。
 *
 * @package Woomp
 */

defined( 'ABSPATH' ) || exit;

/**
 * 支付連 API access token 儲存
 */
final class PChomePayTokenStorage {

	/** 正式環境 token 的 option 名稱 */
	const OPTION_NAME = 'woomp_pchomepay_api_token';

	/** 測試環境 token 的 option 名稱 */
	const SANDBOX_OPTION_NAME = 'woomp_pchomepay_sandbox_api_token';

	/**
	 * Option 名稱
	 *
	 * @var string
	 */
	private $option_name;

	/**
	 * Constructor
	 *
	 * @param bool $sandbox 是否為測試環境.
	 */
	public function __construct( $sandbox = false ) {
		$this->option_name = $sandbox ? self::SANDBOX_OPTION_NAME : self::OPTION_NAME;
		self::delete_legacy_files();
	}

	/**
	 * 取得 JSON 字串格式的 token
	 *
	 * @return string|false
	 */
	public function get_token_str() {
		$token = get_option( $this->option_name, '' );
		return is_string( $token ) && '' !== $token ? $token : false;
	}

	/**
	 * 儲存 JSON 字串格式的 token
	 *
	 * @param string $token JSON 字串.
	 * @return bool
	 */
	public function save_token_str( $token ) {
		update_option( $this->option_name, (string) $token, false );
		return true;
	}

	/**
	 * 刪除舊版存在外掛目錄、可被公開下載的 token 檔
	 */
	private static function delete_legacy_files(): void {
		foreach ( [ 'pchomepay_api_token.json', 'sandbox_pchomepay_api_token.json' ] as $file ) {
			$path = dirname( __DIR__ ) . '/' . $file;
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}
}

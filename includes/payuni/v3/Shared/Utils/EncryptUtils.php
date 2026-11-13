<?php

declare( strict_types = 1 );

namespace J7\Payuni\Shared\Utils;

use J7\Payuni\Contracts\DTOs\SettingDTO;

/**
 * 加密 utils
 *
 * @see https://docs.payuni.com.tw/web/#/7/29
 */
final class EncryptUtils {
    
    /** PAYUNi encrypt */
    public static function encrypt( array $encryptInfo ): string {
        $settings = SettingDTO::instance();
        $tag = '';
        $encrypted = \openssl_encrypt(
            \http_build_query( $encryptInfo ), 'aes-256-gcm', \trim( $settings->hash_key ), 0,
            \trim( $settings->hash_iv ), $tag
        );
        return \trim( \bin2hex( $encrypted . ':::' . \base64_encode( $tag ) ) );
    }
    
    /**
     * PAYUNi decrypt
     *
     * 任何格式錯誤一律回空陣列。GCM tag 必須剛好 16 bytes：openssl_decrypt() 會接受 1 byte 的 tag，
     * 而 PayUni 的 IV（HashIV）固定不變，短 tag 只需約 256 次嘗試就能偽造出「解密成功」的密文。
     */
    public static function decrypt( string $encryptStr = '' ): array {
        $settings = SettingDTO::instance();
        $key      = \trim( $settings->hash_key );
        $iv       = \trim( $settings->hash_iv );

        if( '' === $encryptStr || '' === $key || '' === $iv ) {
            return [];
        }

        if( 0 !== \strlen( $encryptStr ) % 2 || !\ctype_xdigit( $encryptStr ) ) {
            return [];
        }

        $raw = \hex2bin( $encryptStr );
        if( false === $raw || false === \strpos( $raw, ':::' ) ) {
            return [];
        }

        [ $encryptData, $tagB64 ] = \explode( ':::', $raw, 2 );
        $tag = \base64_decode( $tagB64, true );
        if( '' === $encryptData || false === $tag || 16 !== \strlen( $tag ) ) {
            return [];
        }

        $encryptInfo = \openssl_decrypt( $encryptData, 'aes-256-gcm', $key, 0, $iv, $tag );

        if( false === $encryptInfo || '' === $encryptInfo ) {
            return [];
        }

        \parse_str( $encryptInfo, $encryptArr );

        return \is_array( $encryptArr ) ? $encryptArr : [];
    }

    /** HashKey 與 HashIV 是否都已設定（任一為空時簽章可被任何人算出） */
    public static function has_secrets(): bool {
        $settings = SettingDTO::instance();
        return \Woomp_Payment_Callback_Guard::has_secrets( $settings->hash_key, $settings->hash_iv );
    }
    
    /** PAYUNi Hash Info */
    public static function hash_info( string $encrypt = '' ): string {
        $settings = SettingDTO::instance();
        return \strtoupper( \hash( 'sha256', $settings->hash_key . $encrypt . $settings->hash_iv ) );
    }
}
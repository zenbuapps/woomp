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
    
    /** PAYUNi decrypt */
    public static function decrypt( string $encryptStr = '' ): array {
        $settings = SettingDTO::instance();
        
        [ $encryptData, $tag ] = \explode( ':::', \hex2bin( $encryptStr ), 2 );
        
        $encryptInfo = \openssl_decrypt(
            $encryptData, 'aes-256-gcm', \trim( $settings->hash_key ), 0, \trim( $settings->hash_iv ),
            \base64_decode( $tag )
        );

        if( $encryptInfo === false ) {
            return [];
        }

        \parse_str( $encryptInfo, $encryptArr );
        
        return $encryptArr;
    }
    
    /** PAYUNi Hash Info */
    public static function hash_info( string $encrypt = '' ): string {
        $settings = SettingDTO::instance();
        return \strtoupper( \hash( 'sha256', $settings->hash_key . $encrypt . $settings->hash_iv ) );
    }
}
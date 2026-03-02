<?php

declare( strict_types = 1 );

namespace J7\Payuni\Contracts\DTOs;

use J7\Payuni\Shared\Utils\EncryptUtils;

final class TradeReqDTO {
    
    /** @var string 商店代號 (必填) */
    public string $MerID = '';
    
    /** @var string 版本 */
    public string $Version = '1.0';
    
    /** @var string AES加密字串 */
    public string $EncryptInfo = '';
    
    /** @var string SHA256加密字串 */
    public string $HashInfo = '';
    
    /** @var string API網址 */
    public string $ApiUrl = '';
    
    /** 建構函式 */
    public function __construct( array $args = [] ) {
        foreach ( $args as $key => $value ) {
            if( !\property_exists( $this, $key ) ) {
                continue;
            }
            $this->$key = $value;
        }
    }
    
    /** 取得實例 */
    public static function of( \WC_Order $order ): self {
        $setting = SettingDTO::instance();
        $trade_params = TradeReqHashDTO::of( $order )->to_array();
        
        $encrypt_info = EncryptUtils::encrypt( $trade_params );
        $args = [
            'MerID'       => $setting->merchant_id,
            'Version'     => '1.0',
            'EncryptInfo' => $encrypt_info,
            'HashInfo'    => EncryptUtils::hash_info( $encrypt_info ),
            'ApiUrl'      => "{$setting->mode->base_api_url()}/iframe/merchant_trade"
        ];
        return new self( $args );
    }
    
    /** To Array */
    public function to_array(): array {
        $array = \get_object_vars( $this );
        return \array_filter( $array, static fn( $value ) => !\is_null( $value ) );
    }
}
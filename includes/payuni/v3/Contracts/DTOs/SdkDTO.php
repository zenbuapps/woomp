<?php

declare( strict_types = 1 );

namespace J7\Payuni\Contracts\DTOs;

use J7\Payuni\Shared\Utils\EncryptUtils;

final class SdkDTO {
    
    /** @var string $Status 狀態代碼 ex SUCCESS */
    public string $Status = '';
    
    /** @var string $Message 狀態說明 */
    public string $Message = '';
    
    /** @var string $MerID 商店代號 */
    public string $MerID = '';
    
    /** @var string $Token SDK_Token */
    public string $Token = '';
    
    /** @var string $TokenExpired Token 逾期時間 ex 2026-01-28 14:53:20 */
    public string $TokenExpired = '';
    
    /** 實例化 */
    private function __construct( array $args = [] ) {
        foreach ( $args as $key => $value ) {
            if( !\property_exists( $this, $key ) ) {
                continue;
            }
            $this->$key = $value;
        }
    }
    
    /**
     * 從 API 的結果實例化
     *
     * @param array{
     * Status: string,
     * MerID: string,
     * Version: string,
     * EncryptInfo:string,
     * HashInfo:string,
     * } $result Get Token 的 API 加密回應
     *
     * @return self
     *
     */
    public static function from( array $result ): self {
        $EncryptInfo = $result['EncryptInfo'] ?? null;
        if( !$EncryptInfo ) {
            throw new \Exception( 'EncryptInfo is null' );
        }
        
        $args = EncryptUtils::decrypt( $EncryptInfo );
        return new self( $args );
    }
}
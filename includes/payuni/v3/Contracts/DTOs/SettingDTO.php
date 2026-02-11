<?php

declare( strict_types = 1 );

namespace J7\Payuni\Contracts\DTOs;

use J7\Payuni\Shared\Enums\EMode;

final class SettingDTO {
    
    /** @var ?SettingDTO 實例 */
    private static ?self $instance = null;
    /** @var EMode 測試|正式 */
    public EMode $mode = EMode::TEST;
    /** @var bool 是否啟用 log 紀錄 */
    public bool $enable_log = true;
    /** @var bool 是否啟用 3D 驗證 */
    public bool $enable_3d_auth = true;
    /** @var bool 是否啟用分期付款 */
    public bool $enable_installment = false;
    /** @var array 可用的分期期數 */
    public array $installment_options = [];
    /** @var bool 是否啟用記憶卡號 */
    public bool $enable_tokenization = false;
    /** @var string $merchant_id */
    public string $merchant_id = '';
    /** @var string $hash_key */
    public string $hash_key = '';
    /** @var string $hash_iv */
    public string $hash_iv = '';
    
    /** 實例化 */
    private function __construct( array $args = [] ) {
        foreach ( $args as $key => $value ) {
            if( !\property_exists( $this, $key ) ) {
                continue;
            }
            $this->$key = $value;
        }
    }
    
    /** 取得單例 */
    public static function instance(): self {
        if( self::$instance === null ) {
            
            $is_test = \wc_string_to_bool( \get_option( 'payuni_payment_testmode' ) );
            
            // 取得 CreditV3 Gateway 的設定
            $gateway_settings = \get_option( 'woocommerce_payuni-credit-v3_settings', [] );
            $installment_options = $gateway_settings['installment_options'] ?? [];
            
            $args = [
                'mode'                => $is_test ? EMode::TEST : EMode::PROD,
                'enable_log'          => \wc_string_to_bool( \get_option( 'payuni_payment_log' ) ),
                'enable_3d_auth'      => \wc_string_to_bool( \get_option( 'payuni_3d_auth' ) ),
                'enable_installment'  => !empty( $installment_options ),
                'installment_options' => \is_array( $installment_options ) ? \array_map(
                    'intval', $installment_options
                ) : [],
                'enable_tokenization' => \wc_string_to_bool( $gateway_settings['enable_tokenization'] ?? 'no' ),
                'merchant_id'         => $is_test ? \get_option( 'payuni_payment_merchant_no_test' ) : \get_option(
                    'payuni_payment_merchant_no'
                ),
                'hash_key'            => $is_test ? \get_option( 'payuni_payment_hash_key_test' ) : \get_option(
                    'payuni_payment_hash_key'
                ),
                'hash_iv'             => $is_test ? \get_option( 'payuni_payment_hash_iv_test' ) : \get_option(
                    'payuni_payment_hash_iv'
                ),
            ];
            
            self::$instance = new self( $args );
        }
        
        return self::$instance;
    }
}
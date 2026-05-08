<?php

declare( strict_types = 1 );

namespace J7\Payuni\Shared\Enums;


enum EMode:string {
    case TEST = 'TEST';
    case PROD = 'PROD';
    
    /** API URL */
    public function base_api_url():string {
        return match($this) {
            self::TEST => "https://sandbox-api.payuni.com.tw/api",
            self::PROD => "https://api.payuni.com.tw/api",
        };
    }
}

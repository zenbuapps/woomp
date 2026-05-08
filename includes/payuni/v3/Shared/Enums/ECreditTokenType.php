<?php

declare( strict_types = 1 );

namespace J7\Payuni\Shared\Enums;

/**
 * 信用卡 Token 紀錄類型，預設為會員。
 */
enum ECreditTokenType:int {
    /** 會員: 會員旗下所有商店代號共用此Token */
    case MEMBER  = 1;
    
    /** 商店: 僅限於首次交易商店代號可使用此Token */
    case MERCHANT  = 2;
    
}

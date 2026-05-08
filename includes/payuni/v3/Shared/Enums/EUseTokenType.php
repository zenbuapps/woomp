<?php

declare( strict_types = 1 );

namespace J7\Payuni\Shared\Enums;

/**
 * 信用卡 Token 類型
 * 如需使用信用卡 Token 交易
 */
enum EUseTokenType:int {
    /** 約定信用卡，至付款頁面時消費者可自行取消約定 */
    case OPTIONAL_BIND  = 1;
    
    /** 記憶卡號功能，預設為記憶卡號+到期日 */
    case REMEMBER_CARD  = 2;
    
    /** 強制約定信用卡，消費者無法取消 */
    case FORCE_BIND  = 3;
}

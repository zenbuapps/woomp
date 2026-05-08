<?php

declare( strict_types = 1 );

namespace J7\Payuni\Shared\Enums;

enum EOrderStatus :string{
    case PROCESSING = 'processing';
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case ON_HOLD = 'on-hold';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';
    case FAILED = 'failed';
    case CHECKOUT_DRAFT = 'checkout-draft';
    
    
    /** 取代 from */
    public static function parse(string $value): self{
        if(\str_starts_with($value, 'wc-')){
            $value = \substr($value, 3);
        }
        return self::from($value);
    }
    
    /** 取代 tryFrom */
    public static function tryParse(string $value): self{
        if(\str_starts_with($value, 'wc-')){
            $value = \substr($value, 3);
        }
        return self::tryFrom($value);
    }
    
    /** @return string 取得值 */
    public function value( bool $prefix = false  ):string {
        return $prefix ? "wc-{$this->value}" : $this->value;
    }
}
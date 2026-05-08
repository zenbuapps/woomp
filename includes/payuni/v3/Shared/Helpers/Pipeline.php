<?php

declare( strict_types = 1 );

namespace J7\Payuni\Shared\Helpers;


/**
 * 簡單的管道操作
 */
final class Pipeline {
    
    /** @var array<callable> */
    protected array $queue = [];
    
    /**
     * 新增管道
     *
     * @param callable $callback 管道操作 callback
     *
     * @return $this
     */
    public function add( callable $callback ): self {
        if( !\is_callable( $callback ) ) {
            throw new \InvalidArgumentException( 'Invalid callback provided.' );
        }
        $this->queue[] = $callback;
        return $this;
    }
    
    /**
     * 執行管道
     *
     * @param mixed|null $payload 初始資料
     *
     * @return mixed 處理後的資料
     */
    public function process( mixed $payload = null ): mixed {
        foreach ( $this->queue as $callback ) {
            $payload = $callback( $payload );
        }
        return $payload;
    }
}

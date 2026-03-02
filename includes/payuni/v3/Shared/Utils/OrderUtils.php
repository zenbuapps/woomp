<?php

declare( strict_types = 1 );

namespace J7\Payuni\Shared\Utils;

final class OrderUtils {
    
    
    /**
     * 更新暫存資料
     *
     * @param \WC_Order $order
     * @param array     $posted_data
     *
     * @return void
     */
    public static function update_tmp_data( \WC_Order $order, array $posted_data ): void {
        $keys = [
            'sdk_token_tmp',
            'payuni_save_card',
            'payuni_installment',
            'payuni_use_saved_token',
            'payuni_saved_token_id',
            'payuni_carrier_type',
            'payuni_carrier_info',
            'payuni_inv_buyer_name',
        ];
        
        foreach ( $keys as $key ) {
            // $posted_data 讀 billing_ 前綴欄位（可能為空），fallback 讀 additionalData 無前綴版本
            $value = ($posted_data[$key] ?? '') ?: ($_POST[$key] ?? '');  // phpcs:ignore WordPress.Security.NonceVerification
            if( !$value ) {
                continue;
            }
            $order->update_meta_data( $key, $value );
        }
        $order->save_meta_data();
    }
    
    /**
     * 取得暫存資料
     *
     * @param \WC_Order $order
     *
     * @return array{0:string, 1:bool, 2:int, 3:bool, 4:string, 5:string, 6:string, 7:string} [sdk_token, save_card, installment, use_saved_token, saved_token_id, carrier_type, carrier_info, inv_buyer_name]
     */
    public static function get_tmp_data( \WC_Order $order ): array {
        $sdk_token_tmp   = $order->get_meta( 'sdk_token_tmp', true );
        $save_card       = \wc_string_to_bool( $order->get_meta( 'payuni_save_card', true ) );
        $installment     = (int) ( $order->get_meta( 'payuni_installment', true ) ?: 1 );
        $use_saved_token = \wc_string_to_bool( $order->get_meta( 'payuni_use_saved_token', true ) );
        $saved_token_id  = $order->get_meta( 'payuni_saved_token_id', true );
        $carrier_type    = $order->get_meta( 'payuni_carrier_type', true ) ?: '';
        $carrier_info    = $order->get_meta( 'payuni_carrier_info', true ) ?: '';
        $inv_buyer_name  = $order->get_meta( 'payuni_inv_buyer_name', true ) ?: '';
        
        return [ $sdk_token_tmp, $save_card, $installment, $use_saved_token, $saved_token_id, $carrier_type, $carrier_info, $inv_buyer_name ];
    }
    
    
    /**
     * 刪除暫存資料
     *
     * @param \WC_Order $order
     *
     * @return void
     */
    public static function delete_tmp_data( \WC_Order $order ): void {
        $order->delete_meta_data( 'sdk_token_tmp' );
        $order->delete_meta_data( 'payuni_save_card' );
        $order->delete_meta_data( 'payuni_installment' );
        $order->delete_meta_data( 'payuni_use_saved_token' );
        $order->delete_meta_data( 'payuni_saved_token_id' );
        $order->delete_meta_data( 'payuni_carrier_type' );
        $order->delete_meta_data( 'payuni_carrier_info' );
        $order->delete_meta_data( 'payuni_inv_buyer_name' );
        $order->save_meta_data();
    }
    
    /** 擴展暫存資料欄位 */
    public static function extend_checkout_field( array $fields ): array {
        $fields['billing']['sdk_token_tmp'] = [
            'type' => 'hidden',
        ];
        $fields['billing']['payuni_save_card'] = [
            'type' => 'hidden',
        ];
        $fields['billing']['payuni_installment'] = [
            'type' => 'hidden',
        ];
        $fields['billing']['payuni_use_saved_token'] = [
            'type' => 'hidden',
        ];
        $fields['billing']['payuni_saved_token_id'] = [
            'type' => 'hidden',
        ];
        return $fields;
    }
    
}
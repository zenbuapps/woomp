<?php

declare( strict_types = 1 );

namespace J7\Payuni\Infrastructure\Http;

use J7\Payuni\Contracts\DTOs\SettingDTO;
use J7\Payuni\Shared\Utils\EncryptUtils;

/**
 * 處理 PayUni 交易請求
 *
 * @description 負責幕後信用卡交易授權的 HTTP 請求處理
 * @see         https://docs.payuni.com.tw/web/#/29/383
 */
final class TradeHandler {
    
    private const TIMEOUT = 60;
    private const USER_AGENT = 'payuni';
    
    /**
     * 執行幕後信用卡交易授權
     *
     * @param array $request_body 交易請求參數
     *
     * @return array 交易回應結果
     * @throws \Exception 當交易失敗時
     */
    public function execute_trade( array $request_body ): array {
        $setting = SettingDTO::instance();
        $api_url = "{$setting->mode->base_api_url()}/iframe/merchant_trade";
        
        try {
            $options = [
                'body'       => $request_body,
                'blocking'   => true,
                'timeout'    => self::TIMEOUT,
                'user-agent' => self::USER_AGENT,
            ];
            
            $response = \wp_remote_post( $api_url, $options );
            
            if( \is_wp_error( $response ) ) {
                throw new \Exception( $response->get_error_message() );
            }
            
            /** @var array $response_body */
            $response_body = \json_decode( \wp_remote_retrieve_body( $response ), true );
            
            \do_action( 'woomp_payuni_log', 'info', '幕後交易授權結果', [
                'endpoint' => $api_url,
                'body'     => $request_body,
                'result'   => $response_body
            ] );
            
            return $response_body;
        }
        catch ( \Throwable $e ) {
            \do_action( 'woomp_payuni_log', 'error', '幕後交易授權失敗: ' . $e->getMessage(), [
                'body' => $request_body
            ] );
            throw $e;
        }
    }
    
    /**
     * 處理交易回調通知
     *
     * @param array{
     *     MerID:string,
     *     Version: string,
     *     EncryptInfo: string,
     *     HashInfo:string
     * } $encrypted_data 加密的回調資料
     *
     * @return array 解密後的交易結果
     * @throws \Exception 當解密或驗證失敗時
     */
    public function process_notify( array $encrypted_data ): array {
        $encrypt_info = $encrypted_data['EncryptInfo'] ?? '';
        $hash_info = $encrypted_data['HashInfo'] ?? '';
        
        if( empty( $encrypt_info ) || empty( $hash_info ) ) {
            throw new \Exception( '缺少加密資料' );
        }
        
        // 驗證 Hash
        $calculated_hash = EncryptUtils::hash_info( $encrypt_info );
        if( $calculated_hash !== $hash_info ) {
            throw new \Exception( 'Hash 驗證失敗' );
        }
        
        // 解密交易結果
        $decrypted = EncryptUtils::decrypt( $encrypt_info );
        
        \do_action( 'woomp_payuni_log', 'info', '交易回調通知解密結果', $decrypted );
        
        return $decrypted;
    }
    
    /**
     * 更新訂單狀態
     *
     * @param \WC_Order $order        訂單物件
     * @param array{
     *      Status: string,
     *      Message: string,
     *      MerID: string,
     *      MerTradeNo: string,
     *      Gateway: string,
     *      TradeNo: string,
     *      TradeAmt: string,
     *      TradeStatus: string,
     *      PaymentType: string,
     *      CardBank: string,
     *      Card6No: string,
     *      Card4No: string,
     *      CardInst: string,
     *      FirstAmt: string,
     *      EachAmt: string,
     *      ResCode: string,
     *      ResCodeMsg: string,
     *      AuthCode: string,
     *      AuthBank: string,
     *      AuthBankName: string,
     *      AuthType: string,
     *      AuthDay: string,
     *      AuthTime: string,
     *      CreditHash?: string,
     *      CreditLife?: string,
     *      CoBrandCode?:string
     *  }               $trade_result 交易結果
     *
     * @return void
     */
    public function update_order_status( \WC_Order $order, array $trade_result ): void {
        $status = $trade_result['Status'] ?? '';
        $message = $trade_result['Message'] ?? '';
        $trade_no = $trade_result['TradeNo'] ?? '';
        $card_4no = $trade_result['Card4No'] ?? '';
        $card_hash = $trade_result['CreditHash'] ?? '';
        
        
        // 儲存交易資訊到訂單
        $order->update_meta_data( '_payuni_v3_resp', $trade_result );
        
        if( 'SUCCESS' === $status ) {
            // 交易成功
            $order->payment_complete( $trade_no );
            $order->add_order_note(
                \sprintf( '統一金流 PAYUNi 信用卡付款成功。交易編號: %s', $trade_no )
            );
            
            // 檢查是否需要儲存卡片
            $should_save_card = \wc_string_to_bool( $order->get_meta( 'payuni_save_card', true ) );
            $user_id = $order->get_customer_id();
            // 儲存卡號
            if( $card_hash && $user_id && $should_save_card ) {
                $credit_life = $trade_result['CreditLife'] ?? '';
                $this->save_payment_token( $user_id, $card_hash, $card_4no, $credit_life );
            }
            
        }
        else {
            // 交易失敗
            $order->update_status(
                'failed', \sprintf( '統一金流 PAYUNi 信用卡付款失敗。狀態: %s, 訊息: %s', $status, $message )
            );
        }
        
        $order->save();
//        OrderUtils::delete_tmp_data( $order );
    }
    
    /**
     * 儲存 Payment Token 到 WooCommerce
     *
     * @param int    $customer_id 客戶 ID
     * @param string $card_hash   信用卡 Hash
     * @param string $card_4no    信用卡末四碼
     * @param string $card_exp    有效期限 (MMYY)
     *
     * @return void
     */
    private function save_payment_token( int $customer_id, string $card_hash, string $card_4no, string $card_exp
    ): void {
        // 檢查是否已存在相同的 Token
        $existing_tokens = \WC_Payment_Tokens::get_customer_tokens( $customer_id, \PAYUNI\Gateways\CreditV3::ID );
        
        foreach ( $existing_tokens as $existing_token ) {
            if( $existing_token->get_token() === $card_hash ) {
                // 已存在相同的卡片，不需要重複儲存
                \do_action( 'woomp_payuni_log', 'info', '信用卡 Token 已存在，跳過儲存', [
                    'customer_id' => $customer_id,
                    'card_4no'    => $card_4no
                ] );
                return;
            }
        }
        
        // 解析有效期限
        $expiry_month = '';
        $expiry_year = '';
        
        if( \strlen( $card_exp ) === 4 ) {
            $expiry_month = \substr( $card_exp, 0, 2 );
            $expiry_year = '20' . \substr( $card_exp, 2, 2 ); // 假設為 2000 年後
        }
        
        // 建立新的 Payment Token
        $token = new \WC_Payment_Token_CC();
        $token->set_token( $card_hash );
        $token->set_gateway_id( \PAYUNI\Gateways\CreditV3::ID );
//        $token->set_card_type( 'visa' ); // 可以根據卡號前幾碼判斷，這裡先預設
        $token->set_last4( $card_4no );
        $token->set_expiry_month( $expiry_month );
        $token->set_expiry_year( $expiry_year );
        $token->set_user_id( $customer_id );
        
        // 如果是第一張卡，設為預設
        if( empty( $existing_tokens ) ) {
            $token->set_default( true );
        }
        
        $token->save();
        
        \do_action( 'woomp_payuni_log', 'info', '信用卡 Token 儲存成功', [
            'customer_id' => $customer_id,
            'card_4no'    => $card_4no,
            'token_id'    => $token->get_id()
        ] );
    }
}

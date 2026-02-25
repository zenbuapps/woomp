<?php
/**
 * Payuni_Payment_Credit class file
 *
 * @package payuni
 */

namespace PAYUNI\Gateways;

use J7\Payuni\Contracts\DTOs\TradeReqDTO;
use J7\Payuni\Infrastructure\Http\TradeHandler;

\defined( 'ABSPATH' ) || exit;

/**
 * Payuni_Payment_Credit class for Credit Card payment
 */
class CreditV3 extends AbstractGateway {
    
    public const ID = 'payuni-credit-v3';
    
    /** Constructor */
    public function __construct() {
        $this->id = self::ID;
        parent::__construct();
        $this->has_fields = true;
        // $this->order_button_text = __( '統一金流 PAYUNi 信用卡', 'woomp' );
        
        $this->method_title = '統一金流 PAYUNi 信用卡 v3';
        $this->method_description = '透過統一金流 PAYUNi 信用卡進行站內付款';
        
        $this->init_form_fields();
        $this->init_settings();
        
        $this->title = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->supports = [ 'products', 'refunds', 'tokenization' ];
        $this->api_endpoint_url = 'api/credit';
        
        \add_action( "woocommerce_update_options_payment_gateways_{$this->id}", [ $this, 'process_admin_options', ] );

//        \add_action( 'woocommerce_before_checkout_form', [ $this, 'form' ] );
    }
    
    /** @return void 設定後台 form fields */
    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled'             => [
                'title'   => __( 'Enable/Disable', 'woocommerce' ),
                'type'    => 'checkbox',
                'label'   => \sprintf( __( 'Enable %s', 'woomp' ), $this->method_title ),
                'default' => 'no',
            ],
            'title'               => [
                'title'       => __( 'Title', 'woocommerce' ),
                'type'        => 'text',
                'default'     => $this->method_title,
                'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
                'desc_tip'    => true,
            ],
            'description'         => [
                'title'       => __( 'Description', 'woocommerce' ),
                'type'        => 'textarea',
                'css'         => 'width: 400px;',
                'default'     => $this->order_button_text,
                'description' => __(
                    'This controls the description which the user sees during checkout.', 'woocommerce'
                ),
                'desc_tip'    => true,
            ],
            'enable_tokenization' => [
                'title'       => __( '記憶卡號', 'woomp' ),
                'type'        => 'checkbox',
                'label'       => __( '啟用記憶卡號功能，允許客戶儲存信用卡以便下次快速結帳', 'woomp' ),
                'default'     => 'no',
                'description' => __( '啟用後，登入的客戶可以選擇儲存信用卡以便未來使用。', 'woomp' ),
                'desc_tip'    => true,
            ],
            'installment_options' => [
                'title'             => __( '分期付款選項', 'woomp' ),
                'type'              => 'multiselect',
                'class'             => 'wc-enhanced-select',
                'css'               => 'width: 400px;',
                'default'           => [],
                'description'       => __( '選擇要啟用的分期期數。留空則不啟用分期付款。', 'woomp' ),
                'desc_tip'          => true,
                'options'           => [
                    3  => \sprintf( __( '%d 期', 'woomp' ), 3 ),
                    6  => \sprintf( __( '%d 期', 'woomp' ), 6 ),
                    9  => \sprintf( __( '%d 期', 'woomp' ), 9 ),
                    12 => \sprintf( __( '%d 期', 'woomp' ), 12 ),
                    18 => \sprintf( __( '%d 期', 'woomp' ), 18 ),
                    24 => \sprintf( __( '%d 期', 'woomp' ), 24 ),
                    30 => \sprintf( __( '%d 期', 'woomp' ), 30 ),
                ],
                'custom_attributes' => [
                    'data-placeholder' => __( '選擇分期期數', 'woomp' ),
                ],
            ],
        ];
    }
    
    public function validate_fields(): bool {
        return true;
    }
    
    /**
     * 輸出付款表單欄位
     *
     * @description 在結帳頁面輸出信用卡輸入框的容器，供 PayUni SDK iframe 渲染
     * @return void
     */
    public function payment_fields(): void {
        // 輸出付款方式描述
        if( $this->description ) {
            echo \wpautop( \wptexturize( $this->description ) );
        }
        
        $enable_tokenization = \wc_string_to_bool( $this->get_option( 'enable_tokenization', 'no' ) );
        $installment_options = $this->get_option( 'installment_options', [] );
        $saved_tokens = [];
        
        // 取得已儲存的卡片
        if( $enable_tokenization && \is_user_logged_in() ) {
            $saved_tokens = \WC_Payment_Tokens::get_customer_tokens( \get_current_user_id(), $this->id );
        }
        
        // 輸出已儲存卡片選項
        if( !empty( $saved_tokens ) ) {
            echo '<div class="payuni-saved-tokens">';
            echo '<p class="form-row form-row-wide">';
            echo '<label>' . \esc_html__( '選擇已儲存的卡片', 'woomp' ) . '</label>';
            
            foreach ( $saved_tokens as $token ) {
                $token_id = $token->get_id();
                $last4 = $token->get_last4();
                $expiry = $token->get_expiry_month() . '/' . $token->get_expiry_year();
                $is_default = $token->is_default() ? ' checked="checked"' : '';
                
                echo '<label class="payuni-saved-token-label">';
                echo '<input type="radio" name="payuni_saved_token" value="' . \esc_attr(
                        $token_id
                    ) . '" class="payuni-saved-token-radio"' . $is_default . ' />';
                echo \sprintf( ' **** **** **** %s (到期: %s)', \esc_html( $last4 ), \esc_html( $expiry ) );
                echo '</label><br/>';
            }
            
            echo '<label class="payuni-saved-token-label">';
            echo '<input type="radio" name="payuni_saved_token" value="new" class="payuni-saved-token-radio payuni-new-card-radio" />';
            echo ' ' . \esc_html__( '使用新卡片', 'woomp' );
            echo '</label>';
            echo '</p>';
            echo '</div>';
        }
        
        // 輸出信用卡輸入框容器
        $new_card_style = !empty( $saved_tokens ) ? ' style="display:none;"' : '';
        echo '<div class="payuni-credit-v3-form payuni-new-card-form"' . $new_card_style . '>';
        echo '<div class="payuni-form-group">';
        echo '<label for="put_card_no">' . \esc_html__( '信用卡號碼', 'woomp' ) . '</label>';
        echo '<div id="put_card_no"></div>';
        echo '</div>';
        echo '<div class="payuni-form-group">';
        echo '<label for="put_card_exp">' . \esc_html__( '有效期限', 'woomp' ) . '</label>';
        echo '<div id="put_card_exp"></div>';
        echo '</div>';
        echo '<div class="payuni-form-group">';
        echo '<label for="put_card_cvc">' . \esc_html__( '安全碼', 'woomp' ) . '</label>';
        echo '<div id="put_card_cvc"></div>';
        echo '</div>';
        
        // 記憶卡號勾選框 - 根據 PayUni 文件格式設置
        // SDK 會根據 useTokenType 事件決定是否在 put_token_type 容器產生 checkbox
        if( $enable_tokenization && \is_user_logged_in() ) {
            echo <<<HTML
                <div id="token_type_checkbox_area" style="display: flex; align-items: center; display: none;">
                <div id="put_token_type" style="display: flex; align-items: center;">
                  <!-- 按照 useTokenType 值決定是否在此容器產生 checkbox 選項-->
                </div>
                <label id="token_type_text" for="type-checkbox" style="margin-left: 8px;">
                  <!-- 此區域您可放置 token_type_text 預設文字或是其他  -->
                </label>
                </div>
            HTML;
            
        }
        
        echo '</div>'; // .payuni-credit-v3-form
        
        // 分期付款選項
        if( !empty( $installment_options ) && \is_array( $installment_options ) ) {
            echo '<div class="payuni-installment-options">';
            echo '<p class="form-row form-row-wide">';
            echo '<label for="payuni_installment">' . \esc_html__( '分期付款', 'woomp' ) . '</label>';
            echo '<select name="payuni_installment" id="payuni_installment" class="payuni-installment-select">';
            echo '<option value="1">' . \esc_html__( '不分期', 'woomp' ) . '</option>';
            
            foreach ( $installment_options as $period ) {
                echo '<option value="' . \esc_attr( $period ) . '">';
                echo \sprintf( \esc_html__( '%d 期', 'woomp' ), (int) $period );
                echo '</option>';
            }
            
            echo '</select>';
            echo '</p>';
            echo '</div>';
        }
        
        // 隱藏欄位：儲存使用的 Token ID
        echo '<input type="hidden" name="payuni_used_token_id" id="payuni_used_token_id" value="" />';
    }
    
    
    /**
     * 處理付款
     *
     * 流程：
     * 1. 組裝加密交易參數
     * 2. server-side 呼叫 PayUni merchant_trade API（避免瀏覽器 CORS 問題）
     * 3. 依回應更新訂單狀態
     *
     * @param int $order_id 訂單 ID
     *
     * @return array{result:string, redirect?:string, order_id?:int} 'success'|'failure'
     */
    public function process_payment( $order_id ): array {
        $order = \wc_get_order( $order_id );
        /** @var \WC_Order $order */

        try {
            // 組裝加密請求參數（EncryptInfo, HashInfo, MerID, Version, ApiUrl）
            $request_body = TradeReqDTO::of( $order )->to_array();

            // Server-side 呼叫 PayUni merchant_trade（wp_remote_post，無 CORS 問題）
            $handler      = new TradeHandler();
            $raw_response = $handler->execute_trade( $request_body );

            // 若有 EncryptInfo → 驗證 HashInfo 並解密（直接授權流程）
            // 否則使用 raw response（幕後3D流程：TradeNo 由 webhook 補傳）
            if ( ! empty( $raw_response['EncryptInfo'] ) ) {
                $trade_result = $handler->process_notify( $raw_response );
            } else {
                $trade_result = $raw_response;
            }

            // 依交易結果更新訂單狀態（SUCCESS → payment_complete, 其他 → failed）
            $handler->update_order_status( $order, $trade_result );

            return [
                'result'   => 'success',
                'redirect' => $order->get_checkout_order_received_url(),
                'order_id' => $order_id,
            ];

        } catch ( \Throwable $e ) {
            \do_action( 'woomp_payuni_log', 'error', 'process_payment 失敗: ' . $e->getMessage(), [
                'order_id' => $order_id,
            ] );
            \wc_add_notice( \esc_html( $e->getMessage() ), 'error' );

            return [
                'result' => 'failure',
            ];
        }
    }
    
    /**
     * Display payment detail after order table
     *
     * @param \WC_Order $order The order object.
     *
     * @return void
     */
    public function get_detail_after_order_table( \WC_Order $order ) {
        if( $order->get_payment_method() !== $this->id ) {
            return;
        }
        
        
        $status = \esc_html( $order->get_meta( '_payuni_resp_status', true ) );
        $message = \esc_html( $order->get_meta( '_payuni_resp_message', true ) );
        $trade_no = \esc_html( $order->get_meta( '_payuni_resp_trade_no', true ) );
        $card_4no = \esc_html( $order->get_meta( '_payuni_card_number', true ) );
        
        $html = <<<HTML
            <h2 class="woocommerce-order-details__title">交易明細</h2>
            <div class="responsive-table">
                <table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
                    <tbody>
                        <tr>
                            <th>狀態碼：</th>
                            <td>{$status}</td>
                        </tr>
                        <tr>
                            <th>交易訊息：</th>
                            <td>{$message}</td>
                        </tr>
                        <tr>
                            <th>交易編號：</th>
                            <td>{$trade_no}</td>
                        </tr>
                        <tr>
                            <th>卡號末四碼：</th>
                            <td>{$card_4no}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        HTML;
        
        echo $html;
    }
    
    
    /**
     * Checkout fields 結帳欄位
     * Payment form on checkout page copy from WC_Payment_Gateway_CC
     * To add the input name and get value with $_POST
     *
     * @return void
     */
    public function form(): void {}
}

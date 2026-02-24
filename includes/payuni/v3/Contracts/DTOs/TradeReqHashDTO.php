<?php

declare( strict_types = 1 );

namespace J7\Payuni\Contracts\DTOs;

use J7\Payuni\Shared\Utils\OrderUtils;

final class TradeReqHashDTO {
    
    /** @var string 商店代號 (必填) */
    public string $MerID = '';
    
    /** @var string 商店訂單編號 (必填) - 限制長度: 25, 格式: [A-Za-z0-9_-], 10分鐘內不可重複 */
    public string $MerTradeNo = '';
    
    /** @var string SDK_Token (必填) - 由 token_get API 取得 */
    public string $Token = '';
    
    /** @var int 訂單金額 (必填) - 請參考訂單金額限制說明 */
    public int $TradeAmt = 0;
    
    /** @var int 時間戳記 (必填) - 格式: time() */
    public int $Timestamp = 0;
    
    /** @var string 前景通知網址 (條件性) - 付款完成返回指定網址(Form Post), 若空值則付款後呈現 PAYUNi 付款結果頁, 交易結果請以NotifyURL為主, 格式: 完整網址 */
    public string $ReturnURL = '';
    
    /** @var string 背景通知網址 (條件性) - 將交易資料通知指定網址, 格式: 完整網址, 僅限80與443 port */
    public string $NotifyURL = '';
    
    /** @var string 消費者信箱 (條件性) - 格式: 信箱格式, 付款頁帶入付款人信箱, 若未帶參數則空白 */
    public string $UsrMail = '';
    
    /** @var string 商品說明 (必填) - 長度限制: 550, 若超出則系統將自動截斷移除, 格式: 可透過半形分號(;)帶入多個敘述 */
    public string $ProdDesc = '';
    
    /** @var int 指定3D (條件性) - 1=指定3D, 當商店信用卡3D設定為關閉3D時, 可帶入此參數表示此筆交易指定使用3D交易 */
    public int $API3D = 1;
    
    /** @var string 買方會員已綁定 Hash (條件性) - 交易時帶入買方 Hash 可完成買方驗證及交易綁定, 註: 買方 Hash 經由 UPP 交易使用 BuyerToken 綁定後取得 */
    public string $BuyerHash;
    
    /** @var int 信用卡分期期數 (條件性) - 1=不分期, 3/6/9/12/18/24/30=分期期數 */
    public int $CardInst = 1;
    
    /** @var string 發票載具類別 (必填/條件性) - 如需開立發票此參數必帶, 無須開立則不用帶此參數, 3J0002=手機條碼, CQ0001=自然人憑證, amego=會員載具, Donate=捐贈碼, Company=公司發票 */
    public string $CarrierType = '';
    
    /** @var string 載具內容 (必填/條件性) - 當 CarrierType 為3J0002、CQ0001、Donate、Company 時此欄必需填入對應資訊, CarrierType=amego時此欄位免填 */
    public string $CarrierInfo = '';
    
    /** @var string 買方名稱或公司抬頭 (必填/條件性) - 當 CarrierType 有帶參數時, 此欄位必填 */
    public string $InvBuyerName = '';
    
    /** @var string 消費者IP (條件性) - 若有帶入則會列入全平台風險管控機制, 協助阻擋異常交易, 格式: 支援IPv4 和 IPv6 格式 */
    public string $UserIP = '';
    
    /** 建構函式 */
    public function __construct( array $args = [] ) {
        foreach ( $args as $key => $value ) {
            if( !\property_exists( $this, $key ) ) {
                continue;
            }
            $this->$key = $value;
        }
    }
    
    /** 取得 trade params */
    public static function of( \WC_Order $order ): self {
        $setting_dto = SettingDTO::instance();
        [
            $sdk_token,
            $card_hash,
            $save_card,
            $installment,
            $use_saved_token,
            $saved_token_id
        ] = OrderUtils::get_tmp_data( $order );
        
        // 如果使用已儲存的卡片，從 WC_Payment_Tokens 取得 CardHash
        \do_action( 'woomp_payuni_log', 'info', '使用已記錄的卡片付款', [
            'use_saved_token' => $use_saved_token,
            'saved_token_id'  => $saved_token_id,
        ] );
        
        if( $use_saved_token && $saved_token_id ) {
            $token = \WC_Payment_Tokens::get( (int) $saved_token_id );
            if( $token && $token->get_user_id() === $order->get_customer_id() ) {
                $card_hash = $token->get_token();
            }
        }
        
        $args = [
            'MerID'      => $setting_dto->merchant_id,
            'MerTradeNo' => $order->get_order_number(),
            'Token'      => $sdk_token,
            'TradeAmt'   => (int) $order->get_total(),
            'Timestamp'  => \time(),
            'ReturnURL'  => $order->get_checkout_order_received_url(),
            'NotifyURL'  => self::get_notify_url(), // WooCommerce API 回調網址
            'UsrMail'    => $order->get_billing_email(),
            'ProdDesc'   => self::get_product_desc( $order ),
        ];
        
        if( $setting_dto->enable_3d_auth ) {
            $args['API3D'] = 1;
        }
        
        // 設定分期期數（若大於 1 則表示有分期）
        if( $installment > 1 ) {
            $args['CardInst'] = $installment;
        }
        
        
        $ip = $order->get_customer_ip_address();
        if( $ip ) {
            $args['UserIP'] = $ip;
        }
        
        return new self( $args );
    }
    
    /** 取得商品描述 */
    private static function get_product_desc( \WC_Order $order ): string {
        /** @var \WC_Order_Item_Product[] $items */
        $items = $order->get_items();
        $item_names = \array_map( static fn( $item ) => $item->get_name(), $items );
        return \implode( ';', $item_names );
    }
    
    /** 取得背景通知網址，local 環境改用 Cloudflare Tunnel，確保 PayUni 可回調 */
    private static function get_notify_url(): string {
        if( 'local' === \wp_get_environment_type() ) {
            return 'https://payuni-test.powerhouse.tw/wc-api/payuni_notify';
        }
        return \home_url( '/wc-api/payuni_notify' );
    }
    
    /** To Array */
    public function to_array(): array {
        $array = \get_object_vars( $this );
        return \array_filter( $array, static fn( $value ) => !\is_null( $value ) );
    }
}

<?php

declare( strict_types = 1 );

namespace J7\Payuni\Infrastructure\Http;

use J7\Payuni\Contracts\DTOs\SettingDTO;
use J7\Payuni\Shared\Utils\EncryptUtils;

/**
 * 到 payuni 請求類
 *
 * @see https://docs.payuni.com.tw/web/#/29/383
 */
final class HttpClient {
    
    /** @var array<string, string> 錯誤代碼, 訊息 */
    public static array $error_mapper = [
        "1000"       => "尚未設定連動相關 div ID (請參考 基本教學 中 Step 2 initOption 的 elements)",
        "1001"       => "iframe 連線失敗, 無法使用相關 function",
        "1002"       => "無法使用分期, 在 PAYUNi 平台上沒有開啟分期選項",
        "1003"       => "填寫分期數有誤，請重新確認",
        "1004"       => "function 帶入參數有誤，請重新確認",
        "1005"       => "填寫欄位有誤，請重新確認 (請配合 onUpdate 去確認狀態)",
        "1006"       => "沒有填寫 SDK token",
        "1007"       => "非法的跨域溝通，Token 設定的 限定網域名稱 與 當前網域端 不吻合",
        "1008"       => "iframe 嘗試連線時間過長, 中斷連線 (timeout)",
        "1009"       => "iframe 無法取得當前網域, 中斷連線 (timeout)",
        "OBJ01000"   => "處理Token異常",
        "OBJ01001"   => "查無符合對應類型",
        "OBJ01002"   => "未有 Token",
        "OBJ01003"   => "Token 已過期",
        "OBJ01004"   => "未有商店資料",
        "OBJ01005"   => "未有訂單資料",
        "OBJ01006"   => "未有任何支付工具可使用",
        'TOKEN00000' => '系統異常',
        
        'TOKEN01001' => '未有商店代號',
        'TOKEN01002' => '資料 HASH 比對不符合',
        'TOKEN01003' => '資料解密失敗',
        'TOKEN01004' => '解密資料不存在',
        'TOKEN01005' => '查無符合商店(代理商)資料',
        'TOKEN01006' => '已存在相同商店訂單編號',
        
        'TOKEN02000' => 'Token設定失敗',
        'TOKEN02001' => 'AesType，格式錯誤',
        'TOKEN02002' => '商店未有設定AesType',
        'TOKEN02003' => '商店AesType不符合',
        'TOKEN02004' => '未有商店代號',
        'TOKEN02005' => '未有商店訂單編號',
        'TOKEN02006' => '商店訂單編號，超過長度限制',
        'TOKEN02007' => '商店訂單編號，格式錯誤(英數字-_)',
        'TOKEN02008' => '未有訂單金額',
        'TOKEN02009' => '訂單金額，僅可輸入整數',
        'TOKEN02010' => '訂單金額，格式錯誤',
        'TOKEN02011' => '時間戳記，已過期',
        'TOKEN02012' => '時間戳記，僅可輸入整數',
        'TOKEN02013' => '時間戳記，已過期',
        'TOKEN02014' => '前景通知網址，格式錯誤',
        'TOKEN02015' => '背景通知網址，格式錯誤',
        'TOKEN02016' => '綁定類型，格式錯誤',
        'TOKEN02017' => '未有綁定Token',
        'TOKEN02018' => '綁定Token，長度超過限制',
        'TOKEN02019' => '綁定Token，格式錯誤',
        'TOKEN02020' => '綁定Token類型，格式錯誤',
        'TOKEN02021' => '超過額度，未有會員HASH',
        'TOKEN02022' => 'Domain，不得空白',
        'TOKEN02023' => 'Domain，格式錯誤',
        'TOKEN02024' => 'GrantExport參數值錯誤',
        'TOKEN02025' => '未有商品說明',
        'TOKEN02026' => '未有消費者電子信箱',
        'TOKEN02027' => '消費者電子信箱，格式錯誤',
        'TOKEN02028' => '未有買方名稱(抬頭)',
        'TOKEN02029' => '未有載具類別',
        'TOKEN02030' => '無法辨識的載具類別',
        'TOKEN02031' => '載具資料不可為空',
        'TOKEN02032' => '載具資料，格式錯誤',
        'TOKEN02033' => '載具資料，格式錯誤(長度)',
        'TOKEN02034' => '商店不提供捐贈發票選項',
        'TOKEN02035' => '此捐贈碼不在商店提供範圍內',
        'TOKEN02036' => '手機條碼不正確',
        
        'TOKEN03001' => '未有商店資料',
        'TOKEN03002' => '確認支付工具異常',
        'TOKEN03003' => '商店資料異常',
        'TOKEN03004' => '未有設定允許Domain',
        'TOKEN03005' => '未有設定允許幕後IP',
        'TOKEN03006' => '設定允許幕後IP不符合',
        'TOKEN03007' => '代理商未開啟撥款指示功能',
        'TOKEN03008' => '商店未提供約定信用卡交易',
        
        'TOKEN04001' => '買方會員資料取得(驗證)失敗',
        
        "IFTRADE00000" => "系統異常",
        "IFTRADE01001" => "未有商店代號",
        "IFTRADE01002" => "資料 HASH 比對不符合",
        "IFTRADE01003" => "資料解密失敗",
        "IFTRADE01004" => "解密資料不存在",
        "IFTRADE01005" => "查無符合商店(代理商)資料",
        "IFTRADE01006" => "已存在相同商店訂單編號",
        "IFTRADE02001" => "AesType，格式錯誤",
        "IFTRADE02002" => "商店未有設定AesType",
        "IFTRADE02003" => "商店AesType不符合",
        "IFTRADE02004" => "未有交易Token",
        "IFTRADE02005" => "未有商店代號",
        "IFTRADE02006" => "未有商店訂單編號",
        "IFTRADE02007" => "商店訂單編號，超過長度限制",
        "IFTRADE02008" => "商店訂單編號，格式錯誤(英數字-_)",
        "IFTRADE02009" => "未有訂單金額",
        "IFTRADE02010" => "訂單金額，僅可輸入整數",
        "IFTRADE02011" => "訂單金額，格式錯誤",
        "IFTRADE02012" => "未有時間戳記",
        "IFTRADE02013" => "時間戳記，僅可輸入整數",
        "IFTRADE02014" => "時間戳記，已過期",
        "IFTRADE02015" => "前景通知網址，格式錯誤",
        "IFTRADE02016" => "背景通知網址，格式錯誤 | 綁定類型，格式錯誤",
        "IFTRADE02017" => "超過額度，未有會員HASH",
        "IFTRADE02018" => "未有商品說明",
        "IFTRADE02019" => "未有消費者電子信箱",
        "IFTRADE02020" => "消費者電子信箱，格式錯誤",
        "IFTRADE02021" => "未有買方名稱(抬頭)",
        "IFTRADE02022" => "未有載具類別",
        "IFTRADE02023" => "無法辨識的載具類別",
        "IFTRADE02024" => "載具資料不可為空",
        "IFTRADE02025" => "載具資料，格式錯誤",
        "IFTRADE02026" => "載具資料，格式錯誤(長度)",
        "IFTRADE02027" => "商店不提供捐贈發票選項",
        "IFTRADE02028" => "此捐贈碼不在商店提供範圍內",
        "IFTRADE02029" => "手機條碼不正確",
        "IFTRADE02030" => "API3D，格式錯誤",
        "IFTRADE03001" => "買方會員資料取得(驗證)失敗",
        "IFTRADE04001" => "Token已過期",
        "IFTRADE04002" => "未有交易設定資料",
        "IFTRADE04003" => "交易設定資料異常",
        "IFTRADE05001" => "交易設定異常(原始資料)",
        "IFTRADE05002" => "交易設定異常(輸入資料)",
        "IFTRADE05003" => "交易設定異常(商店資料)",
        "TRADE00000"   => "系統異常",
        "TRADE00001"   => "無API對應程式",
        "TRADE01001"   => "送出資料解析失敗",
        "TRADE01002"   => "送出資料解析失敗(KEY)",
        "TRADE01003"   => "送出資料解析失敗(Decrypt)",
        "TRADE01004"   => "未有Token",
        "TRADE01005"   => "Token已過期",
        "TRADE01006"   => "未有支付方式",
        "TRADE01007"   => "未有符合支付方式",
        "TRADE02001"   => "未有解密資料",
        "TRADE02002"   => "支付方式錯誤",
        "TRADE02003"   => "信用卡號，僅可輸入整數",
        "TRADE02004"   => "信用卡號，僅可輸入整數",
        "TRADE02005"   => "信用卡號，長度限制錯誤",
        "TRADE02006"   => "未有信用卡到期日",
        "TRADE02007"   => "信用卡到期日，格式錯誤(MMYY)",
        "TRADE02008"   => "信用卡到期日，已逾期",
        "TRADE02009"   => "信用卡末三碼，長度限制錯誤",
        "TRADE02010"   => "信用卡末三碼，格式錯誤",
        "TRADE02011"   => "未有信用卡分期數",
        "TRADE02012"   => "信用卡分期數，期數格式錯誤",
        "TRADE02013"   => "未有信用卡末三碼",
        "TRADE03001"   => "未有商店資料",
        "TRADE03002"   => "確認支付工具異常",
        "TRADE03003"   => "商店資料異常",
        "API00001"     => "無API類型",
        "API00002"     => "無API版本號",
        "API00003"     => "無API對應程式",
        "API00004"     => "無API加密資料",
        "API00005"     => "無API加密比對資料",
        "API00007"     => "Token已失效",
        "API00008"     => "Gateway錯誤",
        "API00009"     => "已有相同資料處理中",
        "API00010"     => "EncryptInfo 格式錯誤",
        "API00011"     => "HashInfo 格式錯誤",
        
        "API01001" => "執行幕後3D，未有訂單編號",
        "API01002" => "執行幕後3D，未有暫存資訊",
        "API01003" => "執行幕後3D，已超過允許時間",
        "API01004" => "執行幕後3D，解析資料失敗",
        
        "API02001" => "SamsungPay處理異常(RefID)",
        "API02002" => "SamsungPay處理異常(SendDT)",
        
        "DEF01001" => "未有商店代號",
        "DEF01002" => "資料解密失敗",
        "DEF01003" => "代理商不存在",
        "DEF01004" => "代理商狀態不符合",
        "DEF01005" => "商店不存在",
        "DEF01006" => "商店狀態不符合",
        "DEF01007" => "Hash比對不符合",
    ];
    
    private const TIMEOUT = 60;
    private const USER_AGENT = 'payuni';
    private const VERSION = '3.0';
    
    /** @var int 如使用代理商金鑰串接時，需增加請求參數 IsPlatForm=1 且與 MerID Version EncryptInfo HashInfo 同層 */
    private const IS_PLATFORM = 1;
    
    /** Construct */
    public function __construct() {}
    
    /**
     * 取得交易 SDK TOKEN
     *
     * @return array{
     *     Status: string,
     *     MerID: string,
     *     Version: string,
     *     EncryptInfo:string,
     *     HashInfo:string,
     * }
     */
    public function get_sdk_token(): array {
        $setting = SettingDTO::instance();
        $encrypt_info = [
            'MerID'        => $setting->merchant_id,
            'Timestamp'    => \time(),
            'IFrameDomain' => self::get_site_url(),
        ];
        $current_user = \wp_get_current_user();
        if( $current_user ) {
            $encrypt_info['UseTokenType'] = 2;
            $encrypt_info['CreditToken'] = \get_user_meta(
                $current_user->ID, 'billing_email', true
            ) ?: $current_user->user_email;
            $encrypt_info['CreditTokenType'] = 1;
        }
        
        
        return $this->post( '/iframe/token_get', $this->get_auth_body_params( $encrypt_info ) );
    }
    
    #region 內部方法
    
    
    /** 取得 IFrameDomain，因為不能使用 .local 的本地網域  */
    private static function get_site_url(): string {
        if( 'local' === \wp_get_environment_type() ) {
            return 'https://payuni-test.powerhouse.tw';
        }
        
        return \untrailingslashit( \site_url() );
    }
    
    /** 發起 Post 請求 */
    private function post( string $endpoint, array $request_body = [] ): array {
        try {
            $options = [
                'body'       => $request_body,
                'blocking'   => true,
                'timeout'    => self::TIMEOUT,
                'user-agent' => self::USER_AGENT,
            ];
            
            $setting = SettingDTO::instance();
            $api_url = $setting->mode->base_api_url() . $endpoint;
            
            $response = \wp_remote_post( $api_url, $options );
            if( \is_wp_error( $response ) ) {
                throw new \Exception( $response->get_error_message() );
            }
            /** @var array<string, mixed>|array{code: int, msg: string} $response_body */
            $response_body = \json_decode( \wp_remote_retrieve_body( $response ), true );
            
            \do_action( 'woomp_payuni_log', 'info', "{$endpoint} API 發送結果", [
                'endpoint' => $api_url,
                'body'     => $request_body,
                'result'   => $response_body
            ] );
            
            
            self::ensure_response_success( $response_body );
            
            return $response_body;
        }
        catch ( \Throwable $e ) {
            \do_action( 'woomp_payuni_log', 'error', $e->getMessage(), [] );
            throw $e;
        }
    }
    
    /** 確保回應 OK */
    private static function ensure_response_success( array $result ): void {
        if( 'SUCCESS' !== $result['Status'] ) {
            throw new \Exception( self::get_error_msg( $result['Status'] ) );
        }
    }
    
    /** 取得錯誤訊息 */
    private static function get_error_msg( string $code ): string {
        $msg = self::$error_mapper[$code] ?? null;
        
        if( !$msg ) {
            return "{$code}: 未知錯誤";
        }
        
        return "{$code}: {$msg}";
    }
    
    /**
     * 取得加密請求體的方法
     *
     * @param array $encrypt_info AES加密字串
     */
    private function get_auth_body_params( array $encrypt_info = [] ): array {
        $setting = SettingDTO::instance();
        \do_action( 'woomp_payuni_log', 'debug', 'encrypt_info', [
            'encrypt_info' => $encrypt_info,
            'hash_key'     => $setting->hash_key,
            'hash_iv'      => $setting->hash_iv,
        ] );
        $parameter = [];
        $parameter['MerID'] = $setting->merchant_id;
        $parameter['Version'] = self::VERSION;
        $parameter['EncryptInfo'] = EncryptUtils::encrypt( $encrypt_info );
        $parameter['HashInfo'] = EncryptUtils::hash_info( $parameter['EncryptInfo'] );
        // $parameter['IsPlatForm'] = self::IS_PLATFORM;
        return $parameter;
        
    }
    
    #endregion 內部方法
}
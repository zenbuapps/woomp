<?php

declare( strict_types = 1 );

namespace J7\Payuni\Infrastructure\Http;

use J7\Payuni\Contracts\DTOs\SettingDTO;
use J7\Payuni\Shared\Enums\ECreditTokenType;
use J7\Payuni\Shared\Enums\EUseTokenType;
use J7\Payuni\Shared\Utils\CreditTokenUtils;
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

        // 交易查詢 trade/query（@see https://docs.payuni.com.tw/web/#/7/30）
        'QUERY01001' => '未有商店代號',
        'QUERY01002' => '資料 HASH 比對不符合',
        'QUERY01003' => '資料解密失敗',
        'QUERY01004' => '解密資料不存在',
        'QUERY01005' => '查無符合商店資料',
        'QUERY01006' => '網路連線異常',
        'QUERY02001' => '未有商店代號',
        'QUERY02002' => '商店訂單或訂單編號，請擇一送入',
        'QUERY02003' => '商店訂單編號，超過長度限制',
        'QUERY02004' => '商店訂單編號，格式錯誤',
        'QUERY02005' => '訂單編號，超過長度限制',
        'QUERY02006' => '訂單編號，格式錯誤',
        'QUERY02007' => '未有時間戳記',
        'QUERY02008' => '時間戳記，僅可輸入整數',
        'QUERY02009' => '時間戳記，已過期',
        'QUERY02010' => '未有查詢類別',
        'QUERY02011' => '非可使用的查詢類別',
        'QUERY02012' => '參數格式錯誤(QueryNo)',
        'QUERY02013' => '超過單次可查詢筆數上限',
        'QUERY03001' => '查無符合訂單資料',
        'QUERY04001' => '未有API處理結果',
        'QUERY04002' => '回傳加密失敗',
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
    public function get_sdk_token( ?EUseTokenType $use_token_type = null ): array {
        $setting = SettingDTO::instance();
        $encrypt_info = [
            'MerID'        => $setting->merchant_id,
            'Timestamp'    => \time(),
            'IFrameDomain' => self::get_site_url(),
        ];
        $current_user = \wp_get_current_user();
        if( $current_user && $current_user->ID ) {
            $raw_credit_token = \get_user_meta( $current_user->ID, 'billing_email', true ) ?: $current_user->user_email;
            // UseTokenType 決定 PayUni 建立哪一種 Token：
            //   2（記憶卡號）僅供下次結帳帶出卡號，PayUni 不會壓可幕後扣款的 CreditHash；
            //   3（強制約定信用卡）才會壓 CreditHash，定期定額續扣必須用這個。
            // 此值必須與前端 getTradeResult({ useTokenType }) 一致，否則 PayUni 不執行綁定。
            $encrypt_info['UseTokenType'] = ( $use_token_type ?? EUseTokenType::REMEMBER_CARD )->value;
            // 淨化 CreditToken：email 含非法字元（如 Gmail plus-alias 的 "+"）會被 PayUni 以 TOKEN02019（綁定Token，格式錯誤）拒絕。
            $encrypt_info['CreditToken'] = CreditTokenUtils::sanitize( (string) $raw_credit_token, (int) $current_user->ID );
            $encrypt_info['CreditTokenType'] = ECreditTokenType::MEMBER->value;
        }


        return $this->post( '/iframe/token_get', $this->get_auth_body_params( $encrypt_info ) );
    }

    #region 內部方法


    /** 取得 IFrameDomain，因為不能使用 .local 的本地網域  */
    private static function get_site_url(): string {
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
            $raw_body = \wp_remote_retrieve_body( $response );
            /** @var array<string, mixed>|array{code: int, msg: string}|null $response_body */
            $response_body = \json_decode( $raw_body, true );

            \do_action( 'woomp_payuni_log', 'info', "{$endpoint} API 發送結果", [
                'endpoint' => $api_url,
                'body'     => $request_body,
                'result'   => $response_body
            ] );

            // 非 JSON 回應（如 404 HTML）→ 給出可診斷的錯誤，避免後續對 null 操作噴 TypeError。
            if ( ! \is_array( $response_body ) ) {
                $http_code = \wp_remote_retrieve_response_code( $response );
                throw new \Exception(
                    \sprintf( 'PayUni API 回應非 JSON（HTTP %s，endpoint: %s）：%s', $http_code, $api_url, \mb_substr( (string) $raw_body, 0, 200 ) )
                );
            }


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
        // 安全性：不得將 hash_key / hash_iv（AES 金鑰）寫入日誌。
        \do_action( 'woomp_payuni_log', 'debug', 'encrypt_info', [
            'encrypt_info' => $encrypt_info,
        ] );
        $parameter = [];
        $parameter['MerID'] = $setting->merchant_id;
        $parameter['Version'] = self::VERSION;
        $parameter['EncryptInfo'] = EncryptUtils::encrypt( $encrypt_info );
        $parameter['HashInfo'] = EncryptUtils::hash_info( $parameter['EncryptInfo'] );
        // $parameter['IsPlatForm'] = self::IS_PLATFORM;
        return $parameter;

    }

    /**
     * 查詢單筆交易狀態
     *
     * @param string $trade_no PayUni 交易編號
     *
     * @return array 解密後的交易資料
     * @throws \Exception 查詢失敗時拋出例外
     */
    public function query_trade( string $trade_no ): array {
        $setting = SettingDTO::instance();

        $encrypt_info = [
            'MerID'     => $setting->merchant_id,
            'TradeNo'   => $trade_no,
            'Timestamp' => \time(),
        ];

        $request_body            = $this->get_auth_body_params( $encrypt_info );
        $request_body['Version'] = '2.0';

        // base_api_url() 已含 /api 結尾，endpoint 不可再帶 /api 前綴（否則 /api/api/... 404）。
        $response = $this->post( '/trade/query', $request_body );

        return EncryptUtils::decrypt( $response['EncryptInfo'] ?? '' );
    }

    /**
     * 以商店訂單編號查詢交易狀態
     *
     * 用於 3DS 待付款訂單的補償對帳（issue #125）：訂單於 3DS 流程中尚未取得 PayUni
     * TradeNo，無法用 query_trade()，改以送出交易時相同的 MerTradeNo 反查。
     *
     * @param string $mer_trade_no 商店訂單編號（= 送出交易時的 MerTradeNo）
     *
     * @return array 解密後的交易資料（含 Result 陣列）
     * @throws \Exception 查詢失敗時拋出例外
     */
    public function query_trade_by_mer_no( string $mer_trade_no ): array {
        $setting = SettingDTO::instance();

        $encrypt_info = [
            'MerID'      => $setting->merchant_id,
            'MerTradeNo' => $mer_trade_no,
            'Timestamp'  => \time(),
        ];

        $request_body            = $this->get_auth_body_params( $encrypt_info );
        $request_body['Version'] = '2.0';

        // base_api_url() 已含 /api 結尾，endpoint 不可再帶 /api 前綴（否則 /api/api/... 404）。
        $response = $this->post( '/trade/query', $request_body );

        return EncryptUtils::decrypt( $response['EncryptInfo'] ?? '' );
    }

    /**
     * 取消交易授權
     *
     * @param string $trade_no PayUni 交易編號
     *
     * @return array 解密後的回應資料
     * @throws \Exception 取消失敗時拋出例外
     */
    public function cancel_trade( string $trade_no ): array {
        $setting = SettingDTO::instance();

        $encrypt_info = [
            'MerID'     => $setting->merchant_id,
            'TradeNo'   => $trade_no,
            'Timestamp' => \time(),
        ];

        $request_body = $this->get_auth_body_params( $encrypt_info );
        // 官方 PHP SDK trade_cancel 預設 Version 1.0；帶 3.0 會回 API00003（無API對應程式）。
        $request_body['Version'] = '1.0';

        // base_api_url() 已含 /api 結尾，endpoint 不可再帶 /api 前綴（否則 /api/api/... 404）。
        $response = $this->post( '/trade/cancel', $request_body );

        return EncryptUtils::decrypt( $response['EncryptInfo'] ?? '' );
    }

    /**
     * 退款（請款取消）
     *
     * @param string $trade_no PayUni 交易編號
     * @param int    $amount   退款金額
     *
     * @return array 解密後的回應資料
     * @throws \Exception 退款失敗時拋出例外
     */
    public function refund( string $trade_no, int $amount ): array {
        $setting = SettingDTO::instance();

        $encrypt_info = [
            'MerID'     => $setting->merchant_id,
            'TradeNo'   => $trade_no,
            'TradeAmt'  => $amount,
            'Timestamp' => \time(),
            'CloseType' => 2,
        ];

        $request_body = $this->get_auth_body_params( $encrypt_info );
        // 官方 PHP SDK trade_close 預設 Version 1.0；帶 3.0 會回 API00003（無API對應程式）。
        $request_body['Version'] = '1.0';

        // base_api_url() 已含 /api 結尾，endpoint 不可再帶 /api 前綴（否則 /api/api/... 404）。
        $response = $this->post( '/trade/close', $request_body );

        return EncryptUtils::decrypt( $response['EncryptInfo'] ?? '' );
    }

    #endregion 內部方法
}
# PayNow PHP 8.1+ 程式碼範例

> 對象：Power Checkout（WooCommerce 外掛，PHP 8.1+，`declare(strict_types=1)`、`final class`）。
> 以下範例依官方規格（docs.paynow.com.tw）撰寫，涵蓋三套體系的簽章 / 加解密 / 建立訂單 /
> callback 驗簽 / 退款 / 查詢 / 發票開立作廢折讓查詢。可直接調整命名空間後套用。
>
> ⚠️ 體系 2（舊版 CashFlow）的 ApplePay Signature 與 AES256 zero-padding 細節文件描述較簡略，
> 標記 `[需以官方測試向量驗證]` 處請用官方範例輸出核對後再上線。

## 目錄（TOC）

1. `PaynowRestClient`（體系 1：建立 / 查詢付款意圖、退款）
2. `PaynowWebhookVerifier`（體系 1：HMAC-SHA256 驗簽）
3. Component SDK 前端範例（體系 1）
4. `PaynowLegacyCrypto`（體系 2：SHA-1 / AES256 / SHA256 / HMAC-SHA256 / TripleDES / TimeStr）
5. `PaynowLegacyClient`（體系 2：GP→GK→操作；請款 / 退款 / 查詢）
6. 導轉式 form-post + callback 驗簽（體系 2）
7. `PaynowInvoiceClient`（體系 3：開立 / 作廢 / 折讓 / 折讓作廢 / 查詢）

---

## 1. PaynowRestClient（體系 1）

```php
<?php
declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\Http;

/**
 * PayNow 新版 REST API client（PaymentIntent + Refund + Customer）。
 * 認證：Authorization: Bearer {PrivateKey}。
 */
final class PaynowRestClient {

	private const PROD_BASE    = 'https://api.paynow.com.tw';
	private const SANDBOX_BASE = 'https://sandboxapi.paynow.com.tw';

	public function __construct(
		private readonly string $private_key,
		private readonly bool $is_sandbox = true,
	) {}

	private function base_url(): string {
		return $this->is_sandbox ? self::SANDBOX_BASE : self::PROD_BASE;
	}

	/**
	 * 建立付款意圖 — POST /api/v1/payment-intents
	 *
	 * @param array{amount:int|float,currency?:string,description?:string,resultUrl?:string,webhookUrl?:string,allowedPaymentMethods?:array<string>,allowInstallments?:array<int>,expireDays?:int,customer?:string} $params
	 * @return array{id:string,secret:string,status:string} result 內容
	 * @throws \RuntimeException
	 */
	public function create_payment_intent( array $params ): array {
		$params['currency'] ??= 'TWD';
		$res = $this->request( 'POST', '/api/v1/payment-intents', $params );
		return $res['result'];
	}

	/** 查詢付款意圖 — GET /api/v1/payment-intents/:id */
	public function retrieve_payment_intent( string $id ): array {
		$res = $this->request( 'GET', "/api/v1/payment-intents/{$id}" );
		return $res['result'];
	}

	/**
	 * 退款開立 — POST /api/v1/payment-intents/:id/refunds
	 * ATM 退款需帶 bankCode / bankBranchCode / bankAccount。
	 *
	 * @param array{amount:int|float,reason:string,bankCode?:string,bankBranchCode?:string,bankAccount?:string} $params
	 */
	public function refund( string $payment_intent_id, array $params ): array {
		$res = $this->request( 'POST', "/api/v1/payment-intents/{$payment_intent_id}/refunds", $params );
		return $res['result'];
	}

	/** 退款查詢 — GET /api/v1/refunds/:uuid */
	public function retrieve_refund( string $uuid ): array {
		$res = $this->request( 'GET', "/api/v1/refunds/{$uuid}" );
		return $res['result'];
	}

	/** 建立 Customer（綁卡用）— POST /api/v1/customers */
	public function create_customer( array $params ): array {
		$res = $this->request( 'POST', '/api/v1/customers', $params );
		return $res['result'];
	}

	/**
	 * 共用請求；外層回應固定 { status, type, message, result, requestId, paginate }。
	 *
	 * @param 'GET'|'POST' $method
	 * @return array{status:int,type:string,message:string,result:mixed,requestId:?string}
	 * @throws \RuntimeException
	 */
	private function request( string $method, string $path, ?array $body = null ): array {
		$args = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->private_key,
				'Accept'        => 'application/json',
			],
		];
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = \wp_json_encode( $body );
		}

		$response = \wp_remote_request( $this->base_url() . $path, $args );
		if ( \is_wp_error( $response ) ) {
			throw new \RuntimeException( 'PayNow 連線失敗：' . $response->get_error_message() );
		}

		$raw  = \wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'PayNow 回應非 JSON：' . $raw );
		}
		if ( ( $data['type'] ?? '' ) !== 'success' && (int) ( $data['status'] ?? 0 ) !== 200 ) {
			throw new \RuntimeException( 'PayNow API 錯誤：' . ( $data['message'] ?? $raw ) );
		}
		return $data;
	}
}
```

**用法**

```php
$client = new PaynowRestClient( private_key: 'pk_live_xxx', is_sandbox: true );

$intent = $client->create_payment_intent([
	'amount'                => 199,
	'currency'              => 'TWD',
	'description'           => '訂單 #1234',
	'resultUrl'             => 'https://shop.example.com/order-received/1234',
	'webhookUrl'            => 'https://shop.example.com/wp-json/power-checkout/paynow/notify',
	'allowedPaymentMethods' => [ 'CreditCard', 'ATM', 'CreditCardInstallment' ],
	'allowInstallments'     => [ 3, 6, 12 ],
	'expireDays'            => 3,
]);
// $intent['id'] => pp_xxx；$intent['secret'] => pp_xxx_st_xxx（交給前端 SDK）

// 信用卡全額退款
$client->refund( $intent['id'], [ 'amount' => 199, 'reason' => '客戶取消' ] );

// ATM 退款（需帶銀行資料）
$client->refund( $intent['id'], [
	'amount' => 199, 'reason' => '客戶取消',
	'bankCode' => '004', 'bankBranchCode' => '0037', 'bankAccount' => '1234567890',
] );
```

---

## 2. PaynowWebhookVerifier（體系 1）

```php
<?php
declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\Http;

/**
 * 驗證 PayNow payment_result Webhook。
 * 簽章在 Header X-Payment-Center-Hmac-Sha256 = HMAC-SHA256(raw payload, key=PrivateKey)。
 */
final class PaynowWebhookVerifier {

	public function __construct( private readonly string $private_key ) {}

	/**
	 * @param string $raw_body  原始 request body（勿先 decode 再 re-encode）
	 * @param string $signature Header X-Payment-Center-Hmac-Sha256 的值
	 */
	public function verify( string $raw_body, string $signature ): bool {
		$calc = strtoupper( hash_hmac( 'sha256', $raw_body, $this->private_key ) );
		return hash_equals( $calc, strtoupper( $signature ) );
	}
}
```

**WordPress REST callback（永遠回 200）**

```php
public static function handle_notify( \WP_REST_Request $request ): \WP_REST_Response {
	try {
		$raw = $request->get_body();
		$sig = $request->get_header( 'X-Payment-Center-Hmac-Sha256' ) ?? '';

		$verifier = new PaynowWebhookVerifier( $private_key );
		if ( ! $verifier->verify( $raw, $sig ) ) {
			\J7\PowerCheckout\Plugin::logger( 'PayNow Webhook 驗簽失敗', 'error' );
			return new \WP_REST_Response( null, 200 ); // 仍回 200，避免重送風暴；但不處理
		}

		$payload = json_decode( $raw, true );
		// 用 $payload['OrderNo'] / $payload['PaymentNo'] 找訂單；冪等檢查
		// $payload['Status'] === 'Success' → payment_complete()；其餘 → pending + note
		// $payload['PaymentType']、$payload['Meta']['LastFourDigitsOfCard'] 等寫入 order meta
		return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
	} catch ( \Throwable $e ) {
		\J7\PowerCheckout\Plugin::logger( $e->getMessage(), 'error' );
		return new \WP_REST_Response( null, 200 ); // 任何例外都回 200
	}
}
```

---

## 3. Component SDK 前端範例（體系 1）

```html
<script src="https://js.paynow.com.tw/sdk/v2/index.js"></script>
<div id="paynow-container"></div>
<button id="checkoutButton">付款</button>

<script>
  PayNow.createPayment({
    publicKey: '<?php echo esc_js( $public_key ); ?>',
    secret:    '<?php echo esc_js( $payment_intent_secret ); ?>', // 後端建立 intent 的 result.secret
    env:       '<?php echo $is_sandbox ? "sandbox" : "production"; ?>'
  });
  PayNow.mount('#paynow-container', { locale: 'zh_tw' });

  PayNow.on('paymentMethodSelected', (m) => console.log('method', m));
  document.getElementById('checkoutButton').onclick = () => {
    PayNow.checkout().then((response) => {
      if (response.error) { /* 顯示錯誤 */ return; }
      // 前端流程完成；最終以後端 Webhook / retrieve 為準
      window.location = '<?php echo esc_js( $order_received_url ); ?>';
    });
  };
</script>
```

---

## 4. PaynowLegacyCrypto（體系 2）

```php
<?php
declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\Shared;

/**
 * PayNow 舊版 CashFlow 加密 / 簽章工具。
 * SHA-1 PassCode、AES256(CBC+Zeros,base64)、SHA256、HMAC-SHA256、TripleDES、TimeStr。
 */
final class PaynowLegacyCrypto {

	/** 握手階段固定 bootstrap 金鑰（官方公開） */
	public const BOOTSTRAP_KEY = 'paynowencryptpaynowcomtw28229955'; // 32 bytes
	public const BOOTSTRAP_IV  = 'encrypt282299550';                 // 16 bytes

	/** SHA-1 PassCode（ASCII 輸入、hex 輸出，預設大寫）；各值直接相接、不含 +。 */
	public static function passcode( string $concat, bool $upper = true ): string {
		$h = sha1( $concat );
		return $upper ? strtoupper( $h ) : $h;
	}

	/** SHA256（ASCII → hex 大寫） */
	public static function sha256_upper( string $content ): string {
		return strtoupper( hash( 'sha256', $content ) );
	}

	/** HMAC-SHA256（hex 大寫；握手 GK / 回覆用，key 常為 CheckNum） */
	public static function hmac_sha256_upper( string $content, string $key ): string {
		return strtoupper( hash_hmac( 'sha256', $content, $key ) );
	}

	/** AES256-CBC + PaddingMode.Zeros → base64 */
	public static function aes256_encrypt( string $content, string $key, string $iv ): string {
		$content = self::zero_pad( $content, 16 );
		$raw     = openssl_encrypt( $content, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv );
		if ( false === $raw ) {
			throw new \RuntimeException( 'PayNow AES256 加密失敗' );
		}
		return base64_encode( $raw );
	}

	/** AES256-CBC 解密（去除 zero padding） */
	public static function aes256_decrypt( string $b64, string $key, string $iv ): string {
		$raw = openssl_decrypt( base64_decode( $b64 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv );
		if ( false === $raw ) {
			throw new \RuntimeException( 'PayNow AES256 解密失敗' );
		}
		return rtrim( $raw, "\0" );
	}

	/** TripleDES ECB + Zeros → base64（票券核銷 / 分店資料；key8 預設 28229955） */
	public static function tripledes_encrypt( string $content, string $key8 = '28229955' ): string {
		$key     = '1234567890' . $key8 . '123456'; // 24 bytes
		$content = self::zero_pad( $content, 8 );
		$raw     = openssl_encrypt( $content, 'des-ede3', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING );
		if ( false === $raw ) {
			throw new \RuntimeException( 'PayNow TripleDES 加密失敗' );
		}
		return base64_encode( $raw );
	}

	/** TimeStr：年末1碼 + 一年第幾天(3) + 時(2)分(2)秒(2)；date('z') 從 0 起算需 +1 */
	public static function timestr( ?int $ts = null ): string {
		$ts = $ts ?? time();
		$y  = (int) gmdate( 'Y', $ts );
		return substr( (string) $y, -1 )
			. str_pad( (string) ( (int) gmdate( 'z', $ts ) + 1 ), 3, '0', STR_PAD_LEFT )
			. gmdate( 'H', $ts ) . gmdate( 'i', $ts ) . gmdate( 's', $ts );
	}

	private static function zero_pad( string $s, int $block ): string {
		$pad = $block - ( strlen( $s ) % $block );
		return ( $pad === $block ) ? $s : $s . str_repeat( "\0", $pad );
	}
}
```

> 加權檢核碼（GP / GK 16 碼）演算法見 `references/encryption.md` 第 6 節；若需自行實作握手，
> 須照該節重排商家帳號 + TimeStr 與固定基數 `93193193193193193193193` 逐位相乘取個位相加。

---

## 5. PaynowLegacyClient（體系 2：GP→GK→操作）

```php
<?php
declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\Http;

use J7\PowerCheckout\Domains\Payment\Paynow\Shared\PaynowLegacyCrypto as Crypto;

/**
 * PayNow 舊版背景交易 client（請款 CP_gp / 退款 R_gp / 取消授權 CPA_gp / 查詢 PQS_gp）。
 * 流程：GP 取 CheckNum → GK 取動態 Key/IV → 操作（業務 JSON AES256 加密後對半拆 JStr1/JStr2）。
 */
final class PaynowLegacyClient {

	private const PROD = 'https://www.paynow.com.tw/service/PayNowAPI_JS.aspx';
	private const TEST = 'https://test.paynow.com.tw/service/PayNowAPI_JS.aspx';

	public function __construct(
		private readonly string $mem_cid,          // 商家帳號（統編 / 身分證）
		private readonly string $merchant_password, // 商家交易密碼
		private readonly bool $is_test = true,
	) {}

	private function endpoint(): string {
		return $this->is_test ? self::TEST : self::PROD;
	}

	/**
	 * 退款（OP=R_gp）。回傳成功 / 失敗訊息。
	 *
	 * @return array{success:bool,message:string}
	 */
	public function refund(
		string $buysafe_no,
		float $refund_price,
		string $reason,
		array $bank = []  // mem_bankaccno / accountbankno / mem_bankaccount（信用卡退原卡可空）
	): array {
		[ $key, $iv, $time_str, $check_num ] = $this->handshake();

		$passcode = Crypto::passcode( '2822' . $this->mem_cid . $this->merchant_password . '9955' );
		$json     = \wp_json_encode([
			'mem_type'        => '2',
			'buysafeno'       => $buysafe_no,
			'mem_cid'         => $this->mem_cid,
			'passcode'        => $passcode,
			'mem_bankaccno'   => $bank['mem_bankaccno'] ?? '',
			'accountbankno'   => $bank['accountbankno'] ?? '',
			'mem_bankaccount' => $bank['mem_bankaccount'] ?? '',
			'refundvalue'     => $reason,
			'refundmode'      => $bank['refundmode'] ?? '',
			'buyerid'         => '',
			'buyername'       => '',
			'buyeremail'      => '',
			'refundprice'     => (string) $refund_price,
		]);

		$result = $this->operation( 'R_gp', $json, $key, $iv, $time_str, $check_num );
		return $this->parse_result( $result );
	}

	/** 請款（OP=CP_gp） */
	public function capture( string $buysafe_no ): array {
		[ $key, $iv, $time_str, $check_num ] = $this->handshake();
		$passcode = Crypto::passcode( '2822' . $this->mem_cid . $this->merchant_password . '9955' );
		$json     = \wp_json_encode( [ 'UserID' => $this->mem_cid, 'Buysafeno' => $buysafe_no, 'PassCode' => $passcode ] );
		return $this->parse_result( $this->operation( 'CP_gp', $json, $key, $iv, $time_str, $check_num ) );
	}

	/** 交易狀態查詢（OP=PQS_gp）— 回傳原始字串（解析見 cashflow-legacy-api.md 第 8 節） */
	public function query_trade( string $order_no ): string {
		[ $key, $iv, $time_str, $check_num ] = $this->handshake();
		$passcode = Crypto::passcode( '2822' . $this->mem_cid . $order_no . $this->merchant_password . '9955' );
		$json     = \wp_json_encode( [ 'mem_cid' => $this->mem_cid, 'OrderNO' => $order_no, 'passcode' => $passcode ] );
		return $this->operation( 'PQS_gp', $json, $key, $iv, $time_str, $check_num );
	}

	/**
	 * GP→GK 握手，回 [EncryptionKey, EncryptionIV, TimeStr, CheckNum]。
	 *
	 * 注意：GP/GK 的 PassCode 需用「加權檢核碼」演算法（見 encryption.md 第 6 節）。
	 * 此處以 weighted_check_code() 表示；務必照官方範例實作並用測試向量驗證。
	 *
	 * @return array{0:string,1:string,2:string,3:string}
	 */
	private function handshake(): array {
		$time_str = Crypto::timestr();

		// --- OP=GP ---
		$gp_passcode = Crypto::sha256_upper( $this->mem_cid . $this->weighted_check_code( $time_str, 'GP' ) );
		$gp_json     = \wp_json_encode( [ 'mem_cid' => $this->mem_cid, 'PassCode' => $gp_passcode, 'TimeStr' => $time_str ] );
		$gp_jstr     = Crypto::aes256_encrypt( $gp_json, Crypto::BOOTSTRAP_KEY, Crypto::BOOTSTRAP_IV );
		$gp_resp     = $this->post( [ 'OP' => 'GP', 'JStr' => $gp_jstr ] );
		$gp          = json_decode( Crypto::aes256_decrypt( urldecode( $gp_resp ), Crypto::BOOTSTRAP_KEY, Crypto::BOOTSTRAP_IV ), true );
		$check_num   = $gp['CheckNum'];

		// --- OP=GK ---
		$gk_passcode = Crypto::hmac_sha256_upper( $this->mem_cid . $this->weighted_check_code( $time_str, 'GK' ), $check_num );
		$gk_json     = \wp_json_encode( [ 'mem_cid' => $this->mem_cid, 'PassCode' => $gk_passcode, 'TimeStr' => $time_str, 'CheckNum' => $check_num ] );
		$gk_jstr     = Crypto::aes256_encrypt( $gk_json, Crypto::BOOTSTRAP_KEY, Crypto::BOOTSTRAP_IV );
		$gk_resp     = $this->post( [ 'OP' => 'GK', 'JStr' => $gk_jstr ] );
		$gk          = json_decode( Crypto::aes256_decrypt( urldecode( $gk_resp ), Crypto::BOOTSTRAP_KEY, Crypto::BOOTSTRAP_IV ), true );

		return [ $gk['EncryptionKey'], $gk['EncryptionIV'], $time_str, $check_num ];
	}

	/** 操作層：業務 JSON 用動態 Key/IV 加密 → 字串對半拆 JStr1/JStr2 → POST */
	private function operation( string $op, string $json, string $key, string $iv, string $time_str, string $check_num ): string {
		$enc  = Crypto::aes256_encrypt( $json, $key, $iv );
		$half = (int) floor( strlen( $enc ) / 2 );
		return $this->post([
			'OP'       => $op,
			'JStr1'    => substr( $enc, 0, $half ),
			'JStr2'    => substr( $enc, $half ),
			'mem_cid'  => $this->mem_cid,
			'TimeStr'  => $time_str,
			'CheckNum' => $check_num,
		]);
	}

	/** form POST（urlencoded）；回傳原始字串 */
	private function post( array $fields ): string {
		$response = \wp_remote_post( $this->endpoint(), [
			'timeout' => 30,
			'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8' ],
			'body'    => $fields, // wp_remote_post 會自動 urlencode
		] );
		if ( \is_wp_error( $response ) ) {
			throw new \RuntimeException( 'PayNow 背景交易連線失敗：' . $response->get_error_message() );
		}
		return \wp_remote_retrieve_body( $response );
	}

	/** 操作回應：S_成功資訊 / F_錯誤訊息（urlencode） */
	private function parse_result( string $raw ): array {
		$decoded = urldecode( $raw );
		$success = str_starts_with( $decoded, 'S_' );
		return [ 'success' => $success, 'message' => substr( $decoded, 2 ) ];
	}

	/**
	 * [需以官方測試向量驗證] 加權檢核碼（16 碼）。
	 * 演算法見 references/encryption.md 第 6 節：商家帳號(9 碼左補 0)依 GP/GK 規則重排 + TimeStr，
	 * 與固定基數 93193193193193193193193 逐位相乘取個位相加，10-(S%10) 得檢查碼，取前 15 碼 + 檢查碼。
	 */
	private function weighted_check_code( string $time_str, string $mode ): string {
		$cid  = str_pad( $this->mem_cid, 9, '0', STR_PAD_LEFT );
		$base = '93193193193193193193193'; // 23 碼固定基數

		$weight = 'GP' === $mode
			? substr( $cid, 0, 5 ) . $time_str . substr( $time_str, 0, 4 ) . substr( $cid, -4 )
			: substr( $cid, -5 )   . $time_str . substr( $time_str, 0, 4 ) . substr( $cid, 0, 4 );
		$weight = substr( $weight, 0, 23 );

		$sum = 0;
		for ( $i = 0; $i < 23; $i++ ) {
			$sum += ( (int) $weight[ $i ] * (int) $base[ $i ] ) % 10;
		}
		$chk = ( 10 - ( $sum % 10 ) ) % 10; // 若為 10 取 0
		return substr( $weight, 0, 15 ) . $chk;
	}
}
```

> **驗證提醒**：`weighted_check_code()` 依官方文字描述實作，務必用 encryption.md 第 6 節的官方範例
>（mem_cid=028229955 / TimeStr=9328005018 → GP `0282293280050186`、GK `2995593280050186`）跑單元測試核對。

---

## 6. 導轉式 form-post + callback 驗簽（體系 2）

**送出（auto-submit form）**

```php
$web_no      = '12345678';
$order_no    = 'ORDER-1234';
$total_price = '199';
$apicode     = 'your_api_code';

$pass_code = \J7\PowerCheckout\Domains\Payment\Paynow\Shared\PaynowLegacyCrypto::passcode(
	$web_no . $order_no . $total_price . $apicode
);

$fields = [
	'WebNo'         => $web_no,
	'PassCode'      => $pass_code,
	'ReceiverName'  => $customer_name,
	'ReceiverID'    => $customer_id,
	'ReceiverTel'   => $customer_tel,
	'ReceiverEmail' => $customer_email,
	'OrderNo'       => $order_no,
	'ECPlatform'    => 'My Shop',
	'TotalPrice'    => $total_price,
	'OrderInfo'     => '訂單 1234 商品',
	'Note1'         => '', 'Note2' => '',
	'PayType'       => '01', // 信用卡
	'EPT'           => '1',
];
$action = $is_test
	? 'https://test.paynow.com.tw/service/etopm.aspx'
	: 'https://www.paynow.com.tw/service/etopm.aspx';
?>
<form id="paynow-form" method="POST" action="<?php echo esc_url( $action ); ?>">
	<?php foreach ( $fields as $k => $v ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
	<?php endforeach; ?>
</form>
<script>document.getElementById('paynow-form').submit();</script>
```

**回傳驗簽（導頁 / 離線回傳）**

```php
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\PaynowLegacyCrypto as Crypto;

// $_POST 來自 PayNow（信用卡 / WebATM / 銀聯 / 分期 / 超商條碼）
$web_no      = sanitize_text_field( $_POST['WebNo'] ?? '' );
$order_no    = sanitize_text_field( $_POST['OrderNo'] ?? '' );
$total_price = sanitize_text_field( $_POST['TotalPrice'] ?? '' );
$tran_status = sanitize_text_field( $_POST['TranStatus'] ?? '' );
$recv_pass   = sanitize_text_field( $_POST['PassCode'] ?? '' );

$expected = Crypto::passcode( $web_no . $order_no . $total_price . $merchant_password . $tran_status );
if ( ! hash_equals( $expected, $recv_pass ) ) {
	// 驗簽失敗，拒絕處理
	wp_die( 'PassCode 驗證失敗', '', 400 );
}
// $tran_status === 'S' → 付款成功；'F' → 失敗（$_POST['ErrDesc']）
// 虛擬帳號 / 超商條碼：先回繳款資訊（ATMNo / BarCode1~3），離線回傳才有 TranStatus=S
```

---

## 7. PaynowInvoiceClient（體系 3）

```php
<?php
declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Paynow\Http;

/**
 * PayNow 電子發票 client（開立 / 作廢 / 折讓 / 折讓作廢 / 查詢）。
 * 認證：Authorization: Bearer {商家 JWT-Token}。
 */
final class PaynowInvoiceClient {

	private const PROD = 'https://invoiceapi-prod.paynow.com.tw';
	private const DEV  = 'https://invoiceapi-dev.paynow.com.tw';

	public function __construct(
		private readonly string $jwt_token,
		private readonly bool $is_sandbox = true,
	) {}

	private function base(): string {
		return $this->is_sandbox ? self::DEV : self::PROD;
	}

	/**
	 * 開立發票 — POST /api/invoices/issue
	 *
	 * @param array $params order_no/total_amount/tax_amount/tax_type/carrier_type/npoban/buyer/items 等
	 */
	public function issue( array $params ): array {
		return $this->request( 'POST', '/api/invoices/issue', $params );
	}

	/** 作廢發票 — POST /api/invoices/cancel */
	public function cancel( string $invoice_number ): array {
		return $this->request( 'POST', '/api/invoices/cancel', [ 'invoice_number' => $invoice_number ] );
	}

	/** 開立折讓 — POST /api/invoices/allowance */
	public function allowance( string $invoice_number, array $items, string $remark = '' ): array {
		return $this->request( 'POST', '/api/invoices/allowance', [
			'invoice_number' => $invoice_number,
			'remark'         => $remark,
			'items'          => $items, // 每筆 quantity/unit_price/amount/tax/tax_type/invoice_body_sequence_number
		] );
	}

	/** 作廢折讓 — POST /api/invoices/cancel-allowance */
	public function cancel_allowance( string $allowance_number ): array {
		return $this->request( 'POST', '/api/invoices/cancel-allowance', [ 'allowance_number' => $allowance_number ] );
	}

	/** 查詢發票 — GET /api/invoices?InvoiceNumber=&OrderNo=&Limit=&Page= */
	public function query( array $query = [] ): array {
		$qs = $query ? '?' . http_build_query( $query ) : '';
		return $this->request( 'GET', '/api/invoices' . $qs );
	}

	/**
	 * @param 'GET'|'POST' $method
	 * @return array{status:int,type:?string,message:?string,result:mixed,request_id:?string}
	 * @throws \RuntimeException
	 */
	private function request( string $method, string $path, ?array $body = null ): array {
		$args = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->jwt_token,
				'Accept'        => 'application/json',
			],
		];
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = \wp_json_encode( $body );
		}

		$response = \wp_remote_request( $this->base() . $path, $args );
		if ( \is_wp_error( $response ) ) {
			throw new \RuntimeException( 'PayNow 發票連線失敗：' . $response->get_error_message() );
		}
		$data = json_decode( \wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'PayNow 發票回應非 JSON' );
		}
		if ( (int) ( $data['status'] ?? 0 ) !== 200 && ( $data['type'] ?? '' ) !== 'success' ) {
			throw new \RuntimeException( 'PayNow 發票錯誤：' . ( $data['message'] ?? '' ) );
		}
		return $data;
	}
}
```

**用法**

```php
$inv = new PaynowInvoiceClient( jwt_token: 'eyJ...', is_sandbox: true );

// 非統編 B2C 發票（手機條碼載具，tax_amount 帶 0 由國稅局算稅）
$inv->issue([
	'order_no'             => 'ORDER-1234',
	'send_paper'           => false,
	'send_sms'             => false,
	'carrier_type'         => 'PhoneBarCodeCarrier',
	'carrier_id1'          => '/ABC1234',
	'carrier_id2'          => '/ABC1234',
	'total_amount'         => 1050,
	'tax_amount'           => 0,
	'tax_type'             => 'SaleTax',
	'is_pass_customs'      => null,
	'zero_tax_rate_reason' => 'None',
	'buyer'                => [ 'name' => '王小明', 'identifier' => '', 'phone' => '0912345678', 'email' => 'buyer@example.com' ],
	'items'                => [
		[ 'quantity' => 1, 'unit_price' => 1050, 'amount' => 1050, 'tax_type' => 'SaleTax', 'tax_amount' => 0, 'description' => '商品 A' ],
	],
]);

// 作廢
$inv->cancel( 'AB12345678' );

// 折讓（退一件）
$inv->allowance( 'AB12345678', [
	[ 'quantity' => 1, 'unit_price' => 1050, 'amount' => 1050, 'tax' => 0, 'tax_type' => 'SaleTax', 'invoice_body_sequence_number' => '1' ],
], '部分退貨' );

// 查詢
$inv->query( [ 'OrderNo' => 'ORDER-1234', 'Limit' => 10, 'Page' => 1 ] );
```

---

> **整合到 Power Checkout（provider 架構）**：金流走體系 1 時，可比照 `Domains/Payment/Ecpg`
>（站內付）或 `Domains/Payment/PayuniUniEmbed`（iframe）結構新增 `Domains/Payment/Paynow`，
> 在 `before_process_payment()` 呼叫 `create_payment_intent()` 取 secret 存入 order meta，
> 前端 `MountPaynow()` 載入 Component SDK；NotifyURL callback 用 `PaynowWebhookVerifier`。
> 發票走體系 3 時比照 `Domains/Invoice/Ezpay`，實作 `IInvoiceService` 的 `issue()`/`cancel()`。
> 詳見專案 `.claude/rules/provider-guide.rule.md`。

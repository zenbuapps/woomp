<?php
/**
 * 信用卡管理 Metabox
 */

declare (strict_types = 1);

namespace J7\Woomp\Admin\CardManagement;

// 如果沒有啟用訂閱，就不初始化卡片管理
if (!class_exists('WC_Subscriptions')) {
	return;
}

/**
 * Class ShopSubscription
 */
final class CardManagement {

	const METABOX_ID = 'woomp_card_management';

	/**
	 * Constructor
	 */
	public function __construct() {
		\add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		\add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );

		\add_action( 'wp_ajax_woomp_set_default', [ $this, 'woomp_set_default_callback' ] );
		\add_action( 'wp_ajax_woomp_remove', [ $this, 'woomp_remove_callback' ] );
	}

	/**
	 * Adds the meta box container.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	public function add_meta_box( string $post_type ): void {
		// Limit meta box to certain post types.
		$post_types = [ 'shop_subscription' ];

		if ( in_array( $post_type, $post_types, true ) ) {
			\add_meta_box( self::METABOX_ID, __( '信用卡儲存資訊', 'woomp' ), [ $this, 'render_meta_box_content' ], $post_types, 'normal', 'default' );
		}
	}

	/**
	 * Render Meta Box content.
	 *
	 * @param \WP_Post $post    The current post.
	 * @return void
	 */
	public function render_meta_box_content( $post ): void {
		// HPOS 相容：判斷 $post 類型
		$is_order = false;
		if ( $post instanceof \WC_Order ) {
			$is_order = true;
		} elseif ( $post instanceof \WP_Post ) {
			$is_order = 'shop_order' === \get_post_type( $post );
		}
		$order = $this->get_order_by_post( $post );
		if ( ! $order ) {
			echo $is_order ? '找不到訂單資訊' : '找不到此訂閱的上層訂單資訊';
			return;
		}

		$customer_id           = $order->get_customer_id();
		$customer_display_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
		$payment_tokens        = \WC_Payment_Tokens::get_customer_tokens( $customer_id, 'payuni-credit-subscription' );

		$nonce    = \wp_create_nonce( 'woomp' );
		$ajax_url = \admin_url( 'admin-ajax.php' );

		// 給 templates/row.php：AJAX 以訂閱 ID 驗證卡片擁有者（HPOS 下網址沒有 ?post=，不可再讀 $_GET）.
		$subscription_id = 0;
		if ( $post instanceof \WC_Order ) {
			$subscription_id = (int) $post->get_id();
		} elseif ( $post instanceof \WP_Post ) {
			$subscription_id = (int) $post->ID;
		}

		echo '<div class="woomp">';
		echo "<p>客戶: {$customer_display_name} #{$customer_id}</p>";
		echo '<div class="grid grid-cols-6 text-center [&_div]:py-3">';
		include __DIR__ . '/templates/row-head.php';
		foreach ( $payment_tokens as $payment_token ) {
			$args = $this->format_payment_token( $payment_token );
			include __DIR__ . '/templates/row.php';
		}
		echo '</div>';
		echo '</div>';

		?>
<script>
	(function($){
		const ajaxurl = '<?php echo $ajax_url; ?>';
		const nonce = '<?php echo $nonce; ?>';
		$('.woomp_ajax').on('click', function(){
			const data = $(this).data();

			const confirm_msg_mapper = {
				woomp_set_default: '注意，設為預設卡號，未來續訂扣款，都將已此卡為主，確定要設定嗎?',
				woomp_remove: '注意，移除預設卡片，將會影響之後扣款，確定要移除嗎?',
			}

			if (confirm(confirm_msg_mapper?.[data?.action])) {
					// 用戶點擊了"確定"
					confirmAction(data);
				}

			function confirmAction(data){
						$.blockUI();
						data.nonce = nonce;

						const action_label_mapper = {
							woomp_set_default: '設為預設卡號',
							woomp_remove: '移除 token',
						}

						const action_label = action_label_mapper?.[data?.action] || '未知';

						$.post(ajaxurl, data, function(res){

							if(200 === res.code){
								alert(`${action_label} 操作成功，即將重新載入頁面`);
								location.reload();
							}else{
								alert(`${action_label} 操作失敗，請再試一次或連繫管理員`);
								console.log(res);
							}
							$.unblockUI();
						});
			}

		});
	})(jQuery)
</script>
		<?php
	}


	/**
	 * Enqueue scripts and styles.
	 *
	 * @return void
	 */
	public function admin_enqueue_scripts(): void {
		$screen         = \get_current_screen();
		$screen_id      = $screen ? $screen->id : '';
		$allowed_screen = [
			'shop_subscription',
			'shop_order',
		];
		if ( ! in_array( $screen_id, $allowed_screen, true ) ) {
			return;
		}
		\wp_enqueue_script( 'jquery-blockui' );
		\wp_enqueue_style( 'woomp-main', WOOMP_PLUGIN_URL . 'admin/css/main.min.css', [], \WOOMP_VERSION );

		// \wp_enqueue_script( 'woomp-admin', \WOOMP_URL . 'admin/assets/js/admin.js', array( 'jquery' ), \WOOMP_VERSION, true );
	}

	/**
	 * 根據 post 獲取訂單或訂閱的父訂單
	 *
	 * @param \WP_Post $post 文章物件.
	 * @return \WC_Order|null 訂單或訂閱的父訂單
	 */
	private function get_order_by_post( $post ) {
		// HPOS 相容：$post 可能是 WC_Order 物件
		if ( $post instanceof \WC_Order ) {
			return $post;
		}

		$post_type = \get_post_type( $post );
		$is_order  = 'shop_order' === $post_type;

		if ( $is_order ) {
			$order_id = (int) $post->ID;
			$order    = \wc_get_order( $order_id );
			return $order;
		}

		$subscription_id = (int) $post->ID;
		$subscription    = \wcs_get_subscription( $subscription_id );
		if ($subscription) {
			return $subscription->get_parent();
		}
		return null;
	}

	/**
	 * 格式化支付令牌資料
	 *
	 * @param \WC_Payment_Token $payment_token 支付令牌物件.
	 * @return array 格式化後的支付令牌資料
	 */
	private function format_payment_token( \WC_Payment_Token $payment_token ): array {
		$payment_token_data = $payment_token->get_data();

		$formatted_data                 = [];
		$formatted_data['user_id']      = $payment_token->get_user_id();
		$formatted_data['token_id']     = $payment_token->get_id();
		$formatted_data['token']        = $payment_token_data['token'];
		$formatted_data['is_default']   = $payment_token_data['is_default'];
		$formatted_data['type']         = $payment_token_data['type'];
		$formatted_data['last4']        = $payment_token_data['last4'];
		$formatted_data['expiry_year']  = $payment_token_data['expiry_year'];
		$formatted_data['expiry_month'] = $payment_token_data['expiry_month'];
		$formatted_data['card_type']    = $payment_token_data['card_type'];
		$formatted_data['card_name']    = match ( $payment_token_data['card_type'] ) {
			'visa'                          =>'VISA',
			'mastercard'                    =>'MasterCard',
			'jcb'                           =>'JCB',
			'union pay'                     =>'Union Pay',
			'american express'              =>'American Express',
		};

		return $formatted_data;
	}

	/**
	 * 設置支付令牌為默認的回調函數
	 *
	 * @return void
	 */
	public function woomp_set_default_callback(): void {
		$user_id = (int) ( $_POST['user_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 於 validate_token_request() 驗證.

		[ $token, $subscription ] = $this->validate_token_request();

		// 設預設卡的對象必須就是這張訂閱的顧客（validate_token_request() 已確認 token 屬於該顧客）.
		if ( $user_id !== (int) $token->get_user_id() ) {
			$this->send_error( 'user_id 與卡片擁有者不符', 403 );
		}

		$token_id = (int) $token->get_id();
		\WC_Payment_Tokens::set_users_default( $user_id, $token_id );
		$parent_order = $subscription->get_parent();
		if ($parent_order) {
			$parent_order->update_meta_data( '_payuni_token_id', $token_id );
			$parent_order->save();
		}

		// Make your array as json
		\wp_send_json(
			[
				'code'    => 200,
				'message' => '設定為預設卡號成功',
				'data'    => $_POST,
			]
		);

		// Don't forget to stop execution afterward.
		\wp_die();
	}

	/**
	 * 移除支付令牌的回調函數
	 *
	 * @return void
	 */
	public function woomp_remove_callback(): void {
		[ $token, $subscription ] = $this->validate_token_request();

		$token_id = (int) $token->get_id();
		\WC_Payment_Tokens::delete( $token_id );

		$subscription->add_order_note("移除信用卡，移除 token_id #{$token_id}");

		// Make your array as json
		\wp_send_json(
			[
				'code'    => 200,
				'message' => '移除成功',
				'data'    => $_POST,
			]
		);

		// Don't forget to stop execution afterward.
		\wp_die();
	}

	/**
	 * 驗證卡片管理 AJAX 請求，通過時回傳 [ token, subscription ]，否則直接回應錯誤並結束
	 *
	 * wp_ajax_ 只要求登入，nonce 也只證明請求來自後台頁面；還必須：
	 * - 有管理訂單權限（訂閱的 capability_type 與訂單相同）
	 * - token 存在、屬於 payuni-credit-subscription，且擁有者就是這張訂閱的顧客（不能操作別人的卡）
	 *
	 * @return array{0: \WC_Payment_Token, 1: \WC_Subscription}
	 */
	private function validate_token_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$nonce           = isset( $_POST['nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['nonce'] ) ) : '';
		$token_id        = isset( $_POST['token_id'] ) ? \absint( $_POST['token_id'] ) : 0;
		$subscription_id = isset( $_POST['subscription_id'] ) ? \absint( $_POST['subscription_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! \wp_verify_nonce( $nonce, 'woomp' ) ) {
			$this->send_error( 'nonce 錯誤', 403 );
		}

		if ( ! \current_user_can( 'edit_shop_orders' ) ) {
			$this->send_error( '權限不足', 403 );
		}

		if ( ! $token_id || ! $subscription_id ) {
			$this->send_error( '缺少 token_id 或 subscription_id', 400 );
		}

		$subscription = \function_exists( 'wcs_get_subscription' ) ? \wcs_get_subscription( $subscription_id ) : false;
		if ( ! $subscription ) {
			$this->send_error( '找不到訂閱', 404 );
		}

		$token = \WC_Payment_Tokens::get( $token_id );
		if ( ! $token || 'payuni-credit-subscription' !== $token->get_gateway_id() ) {
			$this->send_error( '找不到卡片', 404 );
		}

		$customer_id = (int) $subscription->get_customer_id();
		if ( 0 === $customer_id || (int) $token->get_user_id() !== $customer_id ) {
			$this->send_error( '卡片不屬於此訂閱的顧客', 403 );
		}

		return [ $token, $subscription ];
	}

	/**
	 * 回應錯誤並結束請求
	 *
	 * @param string $message 錯誤訊息
	 * @param int    $code    回應中的 code 欄位（前端以 200 判斷成功）
	 * @return never
	 */
	private function send_error( string $message, int $code ): void {
		\wp_send_json(
			[
				'code'    => $code,
				'message' => $message,
			]
		);
		\wp_die();
	}
}

new CardManagement();

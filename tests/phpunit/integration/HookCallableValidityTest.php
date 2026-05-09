<?php
/**
 * Hook Callable 合法性回歸測試
 *
 * 驗證所有掛在關鍵 WooCommerce filter 上的 array callable
 * 都是真實存在的 method／function。
 *
 * 起因：issue #108——ecpay-gateway.php 註冊了
 * `[ RY_ECPay_Gateway::class, 'add_email_action' ]`
 * 但 RY_ECPay_Gateway 並不存在 add_email_action method，
 * PHP 8 strict callable 驗證在 WC_Emails::init_transactional_emails()
 * 被觸發時直接 TypeError 致前台 cart/checkout 整頁 500。
 *
 * 本測試遍歷以下 4 個 critical filter 上的 array callable，
 * 每一筆都跑 is_callable() 驗證，避免再次發生同類問題。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * Hook Callable 合法性測試類別
 *
 * @group core
 * @group smoke
 * @group regression
 */
class HookCallableValidityTest extends WP_UnitTestCase {

	/**
	 * 待驗證的關鍵 hook 名稱
	 *
	 * - woocommerce_email_actions: 本 bug 直接命中
	 * - woocommerce_email_classes: 同檔案同 pattern
	 * - woocommerce_payment_gateways: 同檔案同 pattern
	 * - woocommerce_shipping_methods: 廣用同 pattern
	 *
	 * @var string[]
	 */
	private array $critical_hooks = [
		'woocommerce_email_actions',
		'woocommerce_email_classes',
		'woocommerce_payment_gateways',
		'woocommerce_shipping_methods',
	];

	/**
	 * 測試前置：確保 hook 已完整註冊
	 *
	 * `do_action( 'wp_loaded' )` 會觸發大多數延遲到
	 * plugins_loaded 之後才註冊的 hook。
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'wp_loaded' );
	}

	/**
	 * 驗證所有 critical hook 上的 array callable 都通過 is_callable()
	 *
	 * 僅檢查 `[ class_name|object, method_name ]` 形式的 callable，
	 * 跳過 closure 與字串函式名稱以避免假陽性
	 *（WordPress core / WooCommerce 本身有許多 lazy-loaded callable
	 *  在 PHPUnit 環境下不一定載入完整）。
	 *
	 * @testdox 確認 critical filter 上的 array callable 全部合法
	 */
	public function test_array_callables_on_critical_filters_are_valid(): void {
		global $wp_filter;

		$failures = [];

		foreach ( $this->critical_hooks as $hook ) {
			$this->assertArrayHasKey(
				$hook,
				$wp_filter,
				sprintf( 'Hook "%s" 應已存在於 $wp_filter（即使 callbacks 為空）', $hook )
			);

			$wp_hook = $wp_filter[ $hook ];

			if ( empty( $wp_hook->callbacks ) ) {
				continue;
			}

			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$cb = $callback['function'] ?? null;

					// 僅檢查 array callable —— 跳過 closure 與 string function name。
					if ( ! is_array( $cb ) || count( $cb ) !== 2 ) {
						continue;
					}

					if ( ! is_callable( $cb ) ) {
						$class_name  = is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0];
						$method_name = (string) $cb[1];
						$failures[]  = sprintf(
							'hook="%s", callable=%s::%s, priority=%d',
							$hook,
							$class_name,
							$method_name,
							$priority
						);
					}
				}
			}
		}

		$this->assertSame(
			[],
			$failures,
			sprintf(
				"以下掛載於 critical filter 上的 array callable 不合法（method/function 不存在），會在 hook 觸發時造成 TypeError：\n%s",
				implode( "\n", $failures )
			)
		);
	}

	/**
	 * 額外回歸：明確檢查 issue #108 的特定 bug 不會復發
	 *
	 * RY_ECPay_Gateway 不應註冊指向不存在的 add_email_action method。
	 *
	 * @testdox 回歸驗證：RY_ECPay_Gateway::add_email_action 不應掛在 woocommerce_email_actions 上
	 */
	public function test_issue_108_ry_ecpay_gateway_add_email_action_not_registered(): void {
		global $wp_filter;

		if ( ! class_exists( 'RY_ECPay_Gateway' ) ) {
			$this->markTestSkipped( 'RY_ECPay_Gateway 未載入，跳過特定回歸檢查' );
		}

		// 確認該 method 確實不存在（這是 bug 的根因）。
		$this->assertFalse(
			method_exists( 'RY_ECPay_Gateway', 'add_email_action' ),
			'RY_ECPay_Gateway::add_email_action 不存在（這是 issue #108 的根因）—— 若此測試失敗代表有人新增了該 method，請評估是否該移除回歸檢查或改寫測試'
		);

		// 確認沒有 callable 指向這個不存在的 method。
		if ( ! isset( $wp_filter['woocommerce_email_actions'] ) ) {
			return;
		}

		foreach ( $wp_filter['woocommerce_email_actions']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$cb = $callback['function'] ?? null;
				if ( ! is_array( $cb ) || count( $cb ) !== 2 ) {
					continue;
				}
				$class_name  = is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0];
				$method_name = (string) $cb[1];

				$this->assertFalse(
					'RY_ECPay_Gateway' === $class_name && 'add_email_action' === $method_name,
					sprintf(
						'issue #108 回歸：RY_ECPay_Gateway::add_email_action 不應再被註冊到 woocommerce_email_actions（priority=%d）',
						$priority
					)
				);
			}
		}
	}
}

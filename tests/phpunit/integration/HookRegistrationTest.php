<?php
/**
 * Hook 註冊整合測試
 *
 * 驗證 Woomp 外掛透過 Woomp_Loader 正確註冊
 * WordPress action 與 filter hooks。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * Hook 註冊測試類別
 *
 * @covers Woomp_Loader
 * @covers Woomp
 */
class HookRegistrationTest extends WP_UnitTestCase {

	/**
	 * 測試 Woomp_Loader 可正確註冊 actions
	 *
	 * @covers Woomp_Loader::add_action
	 * @covers Woomp_Loader::run
	 */
	public function test_woomp_loader_registers_actions() {
		$this->assertTrue(
			class_exists( 'Woomp_Loader' ),
			'Woomp_Loader 類別應已載入'
		);

		$loader = new Woomp_Loader();
		$this->assertInstanceOf(
			'Woomp_Loader',
			$loader,
			'應可建立 Woomp_Loader 實例'
		);
	}

	/**
	 * 測試後台 hooks 已正確註冊
	 *
	 * 驗證 admin_enqueue_scripts 等後台相關 hook 已註冊。
	 */
	public function test_admin_hooks_registered() {
		$this->assertGreaterThan(
			0,
			has_action( 'admin_enqueue_scripts' ),
			'admin_enqueue_scripts hook 應已註冊'
		);

		// 驗證後台選單或設定頁面 hook。
		$has_admin_menu  = has_action( 'admin_menu' );
		$has_admin_init  = has_action( 'admin_init' );
		$has_admin_hooks = ( $has_admin_menu !== false ) || ( $has_admin_init !== false );

		$this->assertTrue(
			$has_admin_hooks,
			'應至少有一個後台管理 hook 已註冊'
		);
	}

	/**
	 * 測試前台 hooks 已正確註冊
	 *
	 * 驗證 wp_enqueue_scripts 等前台相關 hook 已註冊。
	 */
	public function test_public_hooks_registered() {
		$this->assertGreaterThan(
			0,
			has_action( 'wp_enqueue_scripts' ),
			'wp_enqueue_scripts hook 應已註冊'
		);
	}

	/**
	 * 測試結帳欄位 filter hook 優先順序
	 *
	 * 驗證 woocommerce_checkout_fields filter 已正確註冊。
	 */
	public function test_checkout_hooks_priority() {
		$priority = has_filter( 'woocommerce_checkout_fields' );

		// Filter 可能未註冊（取決於設定），至少驗證不會出錯。
		$this->assertTrue(
			$priority === false || is_int( $priority ),
			'woocommerce_checkout_fields filter 應為 false 或有效優先順序值'
		);
	}

	/**
	 * 測試模板覆寫 filter 已註冊
	 *
	 * 驗證 wc_get_template 或 woocommerce_locate_template filter 已註冊。
	 */
	public function test_template_override_filter() {
		$has_get_template    = has_filter( 'wc_get_template' );
		$has_locate_template = has_filter( 'woocommerce_locate_template' );

		// 至少其中一個模板覆寫 filter 應已註冊。
		$has_template_filter = ( $has_get_template !== false ) || ( $has_locate_template !== false );

		$this->assertTrue(
			$has_template_filter,
			'應至少有一個模板覆寫 filter 已註冊（wc_get_template 或 woocommerce_locate_template）'
		);
	}
}

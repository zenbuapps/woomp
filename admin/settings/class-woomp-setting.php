<?php

class Woomp_Setting {


	/**
	 * Bootstraps the class and hooks required actions & filters.
	 */
	public static function init() {
		add_filter( 'woocommerce_settings_tabs_array', __CLASS__ . '::add_settings_tab', 50 );
		add_action( 'woocommerce_settings_tabs_woomp_setting', __CLASS__ . '::settings_tab' );
		add_action( 'woocommerce_update_options_woomp_setting', __CLASS__ . '::update_settings' );
		add_action( 'admin_head', __CLASS__ . '::set_checkbox_toggle' );
		add_filter( 'woocommerce_get_settings_pages', __CLASS__ . '::set_more_tabs' );
	}

	/**
	 * Add a new settings tab to the WooCommerce settings tabs array.
	 *
	 * @param array $settings_tabs Array of WooCommerce setting tabs & their labels, excluding the Subscription tab.
	 * @return array $settings_tabs Array of WooCommerce setting tabs & their labels, including the Subscription tab.
	 */
	public static function add_settings_tab( $settings_tabs ) {
		$settings_tabs['woomp_setting'] = __( '好用版擴充', 'woomp' );
		return $settings_tabs;
	}

	/**
	 * Uses the WooCommerce admin fields API to output settings via the @see woocommerce_admin_fields() function.
	 *
	 * @uses woocommerce_admin_fields()
	 * @uses self::get_settings()
	 */
	public static function settings_tab() {
		woocommerce_admin_fields( self::get_settings() );
	}

	/**
	 * Uses the WooCommerce options API to save settings via the @see woocommerce_update_options() function.
	 *
	 * @uses woocommerce_update_options()
	 * @uses self::get_settings()
	 */
	public static function update_settings() {
		woocommerce_update_options( self::get_settings() );
	}

	/**
	 * Get all the settings for this plugin for @see woocommerce_admin_fields() function.
	 *
	 * @return array Array of settings for @see woocommerce_admin_fields() function.
	 */
	public static function get_settings() {

		$settings = [
			'morepower_link'                            => [
				'name' => '教學文件',
				'type' => 'text',
				'id'   => 'wc_woomp_setting_link',
				// 'desc' => __('<a target="_blank" href="https://cloud.luke.cafe/docs/woomp/">好用版擴充 教學文件</a>', 'woomp'),
			],
			'section_payuni'                            => [
				'name' => __( '統一金流 PAYUNi 設定', 'woomp' ),
				'type' => 'title',
				'id'   => 'wc_woomp_setting_payuni',
			],
			'payuni_gateway'                            => [
				'name'     => __( '啟用 PAYUNi 金流', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( '啟用 PAYUNi 金流模組', 'woomp' ),
				'id'       => 'wc_woomp_enabled_payuni_gateway',
				'class'    => 'toggle',
				'default'  => 'no',
				'desc_tip' => true,
			],
			// 'payuni_shipping' => array(
			// 'name' => __('啟用 PAYUNi 物流', 'woomp'),
			// 'type' => 'checkbox',
			// 'desc' => __('Enable payuni shipping method', 'woomp'),
			// 'id' => 'wc_woomp_enabled_payuni_shipping',
			// 'class' => 'toggle',
			// 'default' => 'no',
			// 'desc_tip' => true,
			// ),
			'section_payuni_end'                        => [
				'type' => 'sectionend',
			],
			'section_ecpay'                             => [
				'name' => __( '綠界設定', 'woomp' ),
				'type' => 'title',
				'id'   => 'wc_woomp_setting_ecpay',
			],
			'ecpay_gateway'                             => [
				'name'     => __( 'Enable ECPay gateway', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( 'Enable ECPay gateway method', 'ry-woocommerce-tools' ),
				'id'       => RY_WT::$option_prefix . 'enabled_ecpay_gateway',
				'class'    => 'toggle',
				'default'  => 'no',
				'desc_tip' => true,
			],
			'ecpay_shipping'                            => [
				'name'     => __( 'Enable ECPay shipping', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( 'Enable ECPay shipping method. The POST method doesn\'t support goods\' weight rule. You should notice that your goods can not over 20kg by yourself.', 'woomp' ),
				'id'       => RY_WT::$option_prefix . 'enabled_ecpay_shipping',
				'class'    => 'toggle',
				'default'  => 'no',
				'desc_tip' => true,
			],
			// 'ecpay_invoice'                  => array(
			// 'name'     => __( '啟用綠界電子發票', 'woomp' ),
			// 'type'     => 'checkbox',
			// 'desc'     => __( 'Enable ECPay invoice method', 'ry-woocommerce-ecpay-invoice' ),
			// 'id'       => RY_WEI::$option_prefix . 'enabled_invoice',
			// 'class'    => 'toggle',
			// 'default'  => 'no',
			// 'desc_tip' => true,
			// ),
			'wmp_ecpay_invoice'                         => [
				'name'     => __( '啟用綠界電子發票(好用版)', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( 'Enable ECPay invoice method', 'ry-woocommerce-ecpay-invoice' ),
				'id'       => 'wc_woomp_enabled_ecpay_invoice',
				'class'    => 'toggle',
				'default'  => 'no',
				'desc_tip' => true,
			],
			'section_ecpay_end'                         => [
				'type' => 'sectionend',
			],
			'section_newebpay'                          => [
				'name' => __( '藍新設定', 'woomp' ),
				'type' => 'title',
				'id'   => 'wc_woomp_setting_newebpay',
			],
			'newebpay_gateway'                          => [
				'name'     => __( '啟用藍新金流', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( 'Enable NewebPay gateway method', 'ry-woocommerce-tools' ),
				'id'       => RY_WT::$option_prefix . 'enabled_newebpay_gateway',
				'class'    => 'toggle',
				'default'  => 'no',
				'desc_tip' => true,
			],
			'newebpay_shipping'                         => [
				'name'     => __( '啟用藍新物流', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( 'Enable NewebPay shipping method', 'ry-woocommerce-tools' ),
				'id'       => RY_WT::$option_prefix . 'enabled_newebpay_shipping',
				'class'    => 'toggle',
				'default'  => 'no',
				'desc_tip' => true,
			],
			'ezpay_invoice'                             => [
				'name'     => __( '啟用藍新電子發票', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( '啟用 ezPay 藍新電子發票模組', 'woomp' ),
				'id'       => 'wc_woomp_enabled_ezpay_invoice',
				'class'    => 'toggle',
				'default'  => 'no',
				'desc_tip' => true,
			],
			'section_newebpay_end'                      => [
				'type' => 'sectionend',
			],
			'section_smilepay'                          => [
				'name' => __( '速買配設定', 'woomp' ),
				'type' => 'title',
				'id'   => 'wc_woomp_setting_smilepay',
			],
			'smilepay_gateway'                          => [
				'name'     => __( '啟用速買配金流', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( 'Enable SmilePay gateway method', 'ry-woocommerce-tools' ),
				'id'       => RY_WT::$option_prefix . 'enabled_smilepay_gateway',
				'class'    => 'toggle',
				'default'  => 'no',
				'desc_tip' => true,
			],
			'smilepay_shipping'                         => [
				'name'     => __( '啟用速買配物流', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( 'Enable SmilePay shipping method', 'ry-woocommerce-tools' ),
				'id'       => RY_WT::$option_prefix . 'enabled_smilepay_shipping',
				'class'    => 'toggle',
				'default'  => 'no',
				'desc_tip' => true,
			],
			'section_smilepay_end'                      => [
				'type' => 'sectionend',
			],
			'section_paynow'                            => [
				'name' => __( '立吉富設定', 'woomp' ),
				'type' => 'title',
				'id'   => 'wc_woomp_setting_paynow',
			],
			'paynow_gateway'                            => [
				'name'     => __( '啟用立吉富金流', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( '啟用 立吉富金流 模組', 'woomp' ),
				'id'       => 'wc_woomp_setting_paynow_gateway',
				'class'    => 'toggle',
				'default'  => 'no',
				'desc_tip' => true,
			],
			'paynow_shipping'                           => [
				'name'     => __( '啟用立吉富物流', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( '啟用 立吉富物流 模組', 'woomp' ),
				'id'       => 'wc_woomp_setting_paynow_shipping',
				'class'    => 'toggle',
				'default'  => 'no',
				'desc_tip' => true,
			],
			'paynow_invoice'                            => [
				'name'     => __( '啟用立吉富電子發票', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( '啟用 立吉富電子發票 模組', 'woomp' ),
				'id'       => 'wc_settings_tab_active_paynow_einvoice',
				'class'    => 'toggle',
				'default'  => 'no',
				'desc_tip' => true,
			],
			'section_paynow_end'                        => [
				'type' => 'sectionend',
			],
			'section_linepay'                           => [
				'name' => __( 'LINE Pay 設定', 'woomp' ),
				'type' => 'title',
				'id'   => 'wc_woomp_setting_linepay',
			],
			'linepay_gateway'                           => [
				'name'     => __( '啟用 LINE Pay 金流', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => '啟用 LINE Pay 金流模組',
				'id'       => 'woocommerce_linepay_enabled',
				'class'    => 'toggle',
				'default'  => 'no',
				'desc_tip' => true,
			],
			'section_linepay_end'                       => [
				'type' => 'sectionend',
			],
			'section_pchomepay'                         => [
				'name' => __( '支付連設定', 'woomp' ),
				'type' => 'title',
				'id'   => 'wc_woomp_setting_pchomepay',
			],
			'pchomepay_gateway'                         => [
				'name'     => __( '啟用支付連金流', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => '啟用支付連金流模組',
				'id'       => 'woocommerce_pchomepay_enabled',
				'class'    => 'toggle',
				'default'  => 'no',
				'desc_tip' => true,
			],
			'section_pchomepay_end'                     => [
				'type' => 'sectionend',
			],
			'section_checkout_title'                    => [
				'name' => __( '結帳相關設定', 'woomp' ),
				'type' => 'title',
				'desc' => '',
				'id'   => 'wc_woomp_setting_section_checkout_title',
			],
			// 'replace'                 => array(
			// 'name'     => __( '一頁結帳模式', 'woomp' ),
			// 'type'     => 'checkbox',
			// 'desc'     => __( '將原本兩段式結帳改成一頁式結帳，並改變結帳順序為 「選物流 -> 選金流 -> 填結帳欄位」，以適應超商取貨等物流模式', 'woomp' ),
			// 'id'       => 'wc_woomp_setting_replace',
			// 'class'    => 'toggle',
			// 'default'  => 'yes',
			// 'desc_tip' => true,
			// ),
			'mode'                                      => [
				'name'     => __( '結帳流程設定', 'woomp' ),
				'type'     => 'select',
				'desc'     => __( '將原本兩段式結帳改成一頁式或兩頁式結帳，並改變結帳順序為 「選物流 -> 選金流 -> 填��帳欄位」，以適應超商取貨等物流模式', 'woomp' ),
				'id'       => 'wc_woomp_setting_mode',
				'class'    => 'wc_woomp_setting_mode',
				'options'  => [
					'default' => '預設',
					'onepage' => '一頁式結帳',
					'twopage' => '兩頁式結帳',
				],
				'default'  => 'yes',
				'desc_tip' => true,
			],
			'mode_twopage_message'                      => [
				'name'     => __( '兩頁式結帳返回購物車文字', 'woomp' ),
				'type'     => 'textarea',
				'desc'     => __( '啟用兩頁式結帳後顯示於結帳頁最上方的返回購物車說明文字', 'woomp' ),
				'id'       => 'wc_woomp_setting_mode_twopage_message',
				'class'    => 'wc_woomp_setting_mode_twopage_message',
				'desc_tip' => true,
			],
			'billing_country_pos'                       => [
				'name'     => __( '結帳頁國家欄位置頂', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( '若需運送至多個國家，建議開啟此選項，將國家欄位移至物流選項之前', 'woomp' ),
				'id'       => 'wc_woomp_setting_billing_country_pos',
				'class'    => 'toggle',
				'desc_tip' => true,
				'default'  => 'yes',
				'std'      => 'yes',
			],
			'tw_address'                                => [
				'name'     => __( '縣市/鄉鎮市下拉式選單', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( '開啟此選項套用結帳中的台灣地址下拉選單', 'woomp' ),
				'id'       => 'wc_woomp_setting_tw_address',
				'class'    => 'toggle',
				'desc_tip' => true,
				'default'  => 'yes',
				'std'      => 'yes',
			],
			'one_line_address'                          => [
				'name'     => __( '訂單地址欄位整併', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( '開啟此選項套用後台訂單管理地址欄位整合為一行', 'woomp' ),
				'id'       => 'wc_woomp_setting_one_line_address',
				'class'    => 'toggle',
				'desc_tip' => true,
				'default'  => 'yes',
				'std'      => 'yes',
			],
			'show_phone'                                => [
				'name'     => __( '顯示訂單電話資訊', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( '開啟此選項套用後台訂單管理列表顯示帳單電話與運送電話', 'woomp' ),
				'id'       => 'wc_woomp_setting_show_phone',
				'class'    => 'toggle',
				'desc_tip' => true,
				'default'  => 'yes',
				'std'      => 'yes',
			],
			'tw_field_valitdate'                        => [
				'name'     => __( 'Validate the length of name and phone', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( 'Check the box to set the validate rule of name and phone.', 'woomp' ),
				'id'       => 'wc_woomp_setting_tw_field_valitdate',
				'class'    => 'toggle',
				'desc_tip' => true,
				'default'  => 'yes',
				'std'      => 'yes',
			],
			'virtual_product_address'                   => [
				'name'     => __( '虛擬商品隱藏地址欄位', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( '開啟此選項套用結帳虛擬商品時隱藏地址欄位', 'woomp' ),
				'id'       => 'wc_woomp_setting_virtual_product_address',
				'class'    => 'toggle',
				'desc_tip' => true,
				'default'  => 'yes',
				'std'      => 'yes',
			],
			'cod_payment'                               => [
				'name'     => __( '新增取代貨到付款方式', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( '開啟此選項以取代貨到付款方式', 'woomp' ),
				'id'       => 'wc_woomp_setting_cod_payment',
				'class'    => 'toggle',
				'desc_tip' => true,
				'default'  => 'yes',
				'std'      => 'yes',
			],
			[
				'title'    => __( 'Repay action', 'ry-woocommerce-tools' ),
				'desc'     => __( 'Enable order to change payment', 'ry-woocommerce-tools' ),
				'id'       => RY_WT::$option_prefix . 'repay_action',
				'type'     => 'checkbox',
				'default'  => 'no',
				'class'    => 'toggle',
				'desc_tip' => true,
			],
			[
				'title'    => __( 'strength password', 'ry-woocommerce-tools' ),
				'desc'     => __( 'Enable the strength password check.', 'ry-woocommerce-tools' ),
				'id'       => RY_WT::$option_prefix . 'strength_password',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'class'    => 'toggle',
				'desc_tip' => true,
			],
			[
				'title'    => __( 'show not paid info at order detail', 'ry-woocommerce-tools' ),
				'desc'     => __( 'Show not paid info at order detail payment method info.', 'ry-woocommerce-tools' ),
				'id'       => RY_WT::$option_prefix . 'show_unpay_title',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'class'    => 'toggle',
				'desc_tip' => true,
			],
			'place_order_text'                          => [
				'name'     => __( '結帳按鈕文字設定', 'woomp' ),
				'type'     => 'text',
				'desc'     => __( '設定結帳頁確定購買按鈕的文字內容', 'woomp' ),
				'id'       => 'wc_woomp_setting_place_order_text',
				'class'    => 'toggle',
				'desc_tip' => true,
				'default'  => '',
			],
			'section_end'                               => [
				'type' => 'sectionend',
				'id'   => 'wc_woomp_setting_section_checkout_title',
			],
			[
				'title' => __( '商品相關設定', 'woomp' ),
				'type'  => 'title',
				'id'    => 'product_page_options',
			],
			'product_variations_ui'                     => [
				'name'     => __( '可變商品編輯介面', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( '開啟此選項以套用好用版可變商品操作介面', 'woomp' ),
				'id'       => 'wc_woomp_setting_product_variations_ui',
				'class'    => 'toggle',
				'desc_tip' => true,
				'default'  => 'yes',
				'std'      => 'yes',
			],
			'product_variations_frontend_ui'            => [
				'name'     => __( '可變商品前台顯示介面', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( '開啟此選項以套用好用版可變商品前台顯示介面', 'woomp' ),
				'id'       => 'wc_woomp_setting_product_variations_frontend_ui',
				'class'    => 'toggle',
				'desc_tip' => true,
				'default'  => 'yes',
				'std'      => 'yes',
			],
			'product_variations_frontend_ui_default'    => [
				'name'     => __( '可變商品規格預設為標籤式', 'woomp' ),
				'type'     => 'checkbox',
				'desc'     => __( '開啟此選項以套用好用版可變商品規格預設為標籤式', 'woomp' ),
				'id'       => 'wc_woomp_setting_product_variations_frontend_ui＿default',
				'class'    => 'toggle',
				'desc_tip' => true,
				'default'  => 'no',
				'std'      => 'no',
			],
			'product_variations_frontend_ui_bg_color'   => [
				'name'     => __( '可變商品規格標籤背景顏色', 'woomp' ),
				'type'     => 'color',
				'desc'     => __( '設定標籤式可變商品規格的背景顏色', 'woomp' ),
				'id'       => 'wc_woomp_setting_product_variations_frontend_ui＿bg_color',
				'class'    => '',
				'desc_tip' => true,
			],
			'product_variations_frontend_ui_text_color' => [
				'name'     => __( '可變商品規格標籤文字顏色', 'woomp' ),
				'type'     => 'color',
				'desc'     => __( '設定標籤式可變商品規格的文字顏色', 'woomp' ),
				'id'       => 'wc_woomp_setting_product_variations_frontend_ui＿text_color',
				'class'    => '',
				'desc_tip' => true,
			],
			'virtual_product_order_auto_complete'       => [
				'title'    => __( '虛擬商品自動完成訂單', 'woomp' ),
				'desc'     => __( '開啟此選項虛擬商品完成結帳後自動完成訂單', 'woomp' ),
				'id'       => 'wc_woomp_setting_virtual_product_order_auto_complete',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'class'    => 'toggle',
				'desc_tip' => true,
			],
			[
				'type' => 'sectionend',
				'id'   => 'product_page_options',
			],
			[
				'title' => __( '運送相關設定', 'ry-woocommerce-tools' ),
				'type'  => 'title',
				'id'    => 'checkout_page_options',
			],
			[
				'title'    => __( 'Show Country', 'ry-woocommerce-tools' ),
				'desc'     => __( 'Show Country select item', 'ry-woocommerce-tools' ),
				'id'       => RY_WT::$option_prefix . 'show_country_select',
				'type'     => 'checkbox',
				'default'  => 'no',
				'class'    => 'toggle',
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Last name first', 'ry-woocommerce-tools' ),
				'desc'     => __( 'Show Last name before first name input item', 'ry-woocommerce-tools' ),
				'id'       => RY_WT::$option_prefix . 'last_name_first',
				'type'     => 'checkbox',
				'default'  => 'no',
				'class'    => 'toggle',
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Address zip first', 'ry-woocommerce-tools' ),
				'desc'     => __( 'Show address input item in zip state address', 'ry-woocommerce-tools' ),
				'id'       => RY_WT::$option_prefix . 'address_zip_first',
				'type'     => 'checkbox',
				'default'  => 'no',
				'class'    => 'toggle',
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Free shipping hint', 'woomp' ),
				'desc'     => __( 'Show free shipping condition on checkout page', 'woomp' ),
				'id'       => 'wc_woomp_setting_free_shipping_hint',
				'type'     => 'checkbox',
				'default'  => 'no',
				'class'    => 'toggle',
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Free shipping hint condition text', 'woomp' ),
				'desc'     => __( 'Use {{price}} to display amount', 'woomp' ),
				'id'       => 'wc_woomp_setting_free_shipping_text_left',
				'type'     => 'text',
				'default'  => __( 'Free shipping left amount', 'woomp' ),
				'desc_tip' => true,
				'class'    => 'free-shipping-hint-setting',
			],
			[
				'title'    => __( 'Achive free shipping text, ex:free', 'woomp' ),
				'desc'     => __( 'Set Achive free shipping text', 'woomp' ),
				'id'       => 'wc_woomp_setting_free_shipping_text_free_shipping',
				'type'     => 'text',
				'default'  => __( 'Free', 'woomp' ),
				'desc_tip' => true,
				'class'    => 'free-shipping-hint-setting',
			],
			[
				'title'    => __( 'Free shipping hint background color', 'woomp' ),
				'desc'     => __( 'Set hint background', 'woomp' ),
				'id'       => 'wc_woomp_setting_free_shipping_bg_color',
				'type'     => 'color',
				'desc_tip' => true,
				'class'    => 'free-shipping-hint-setting ',
			],
			[
				'title'    => __( 'Free shipping hint text color', 'woomp' ),
				'desc'     => __( 'Set hint text color', 'woomp' ),
				'id'       => 'wc_woomp_setting_free_shipping_text_color',
				'type'     => 'color',
				'desc_tip' => true,
				'class'    => 'free-shipping-hint-setting ',
			],
			[
				'type' => 'sectionend',
				'id'   => 'checkout_page_options',
			],
		];

		return apply_filters( 'wc_woomp_setting_settings', $settings );
	}

	public static function set_checkbox_toggle() {
		if ( isset( $_GET['tab'] ) && 'woomp_setting' === $_GET['tab'] ) {?>
		<style>
			h2 {
				position: relative;
				border-top: 1px solid #ccc;
				padding: 2rem 0 0 1rem;
				margin: 2rem 0 1rem 0;
			}
			h2:before{
				content: '';
				position: absolute;
				top: 31px;
				left: 0;
				width: 5px;
				height: 20px;
				background-color: #cc99c2;
			}
			h1 + h2,
			h1 + #message + h2 {
				border-top: 0;
				margin-top: 0;
			}
			input.toggle[type=checkbox]{
				height: 0;
				width: 0;
				visibility: hidden;
			}

			input.toggle + label {
				cursor: pointer;
				text-indent: -9999px;
				width: 50px;
				height: 26px;
				background: grey;
				display: block;
				border-radius: 100px;
				position: relative;
			}

			input.toggle + label:after {
				content: '';
				position: absolute;
				top: 3px;
				left: 3px;
				width: 20px;
				height: 20px;
				background: #fff;
				border-radius: 40px;
				transition: 0.3s;
			}

			input.toggle:checked + label {
				background: #cc99c2;
			}

			input.toggle:checked + label:after {
				left: calc(100% - 3px);
				transform: translateX(-100%);
			}

			input.toggle + label:active:after {
				width: 130px;
			}

			.form-table td fieldset label {
				margin-top: 0!important;
				margin-left: -10px!important;
				margin-bottom: 3px!important;
			}

			legend + label:after {
				content: '停用 / 啟用';
				margin-left: 10px;
			}
		</style>
		<script>
			jQuery(document).ready(function(){
				jQuery('.form-table td fieldset label').each(function(){
					jQuery(this).find('input').after('<label for="'+ jQuery(this).attr('for') +'">Toggle</label>');
				})
			})
		</script>
			<?php
		}
	}

	public static function set_more_tabs( $settings ) {
		$settings[] = include WOOMP_PLUGIN_DIR . 'admin/settings/class-woomp-setting-gateway.php';
		$settings[] = include WOOMP_PLUGIN_DIR . 'admin/settings/class-woomp-setting-shipping.php';
		$settings[] = include WOOMP_PLUGIN_DIR . 'admin/settings/class-woomp-setting-invoice.php';
		return $settings;
	}
}

Woomp_Setting::init();

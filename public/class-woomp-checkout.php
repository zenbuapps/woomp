<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Utilities\NumberUtil;

if ( ! class_exists( 'WooMP_Checkout' ) ) {
	final class WooMP_Checkout {


		private static $scripts = [];

		/**
		 * 將購物車&小計置入結帳頁
		 */
		public function display_cart_in_checkout_page(): void {
			if ( \is_wc_endpoint_url( 'order-received' ) ) {
				return;
			}
			printf(
			/*html*/'<div class="cart-shortcode">%1$s</div>',
			\do_shortcode( '[woocommerce_cart]' )
			);
		}

		/**
		 * 購物車轉跳結帳頁
		 */
		public function redirect_cart_page_to_checkout() {
			if ( is_cart() && WC()->cart->get_cart_contents_count() > 0 ) {
				?>
			<script>
				window.location.href="<?php echo wc_get_checkout_url(); ?>"
			</script>
				<?php
			}
		}


		/**
		 * woocommerce/inluces/class-wc-frontend-scripts.php
		 */
		private static function register_script( $handle, $path, $deps = [ 'jquery' ], $version = WC_VERSION, $in_footer = true ) {
			self::$scripts[] = $handle;
			wp_register_script( $handle, $path, $deps, $version, $in_footer );
		}

		/**
		 * woocommerce/inluces/class-wc-frontend-scripts.php
		 */
		private static function enqueue_script( $handle, $path = '', $deps = [ 'jquery' ], $version = WC_VERSION, $in_footer = true ) {
			if ( ! in_array( $handle, self::$scripts, true ) && $path ) {
				self::register_script( $handle, $path, $deps, $version, $in_footer );
			}
			wp_enqueue_script( $handle );
		}

		/**
		 * woocommerce/inluces/class-wc-frontend-scripts.php
		 */
		public static function load_scripts() {
			if ( is_checkout() ) {
				self::enqueue_script( 'wc-cart' );
			}
		}

		/**
		 * woocommerce/inluces/class-wc-frontend-scripts.php
		 */
		public static function init() {
			add_action( 'wp_enqueue_scripts', [ __CLASS__, 'load_scripts' ], 20 );
		}

		/**
		 * 設定取消必填欄位
		 *
		 * @param array $fields Fields.
		 * @return array $fields
		 */
		public function set_shipping_field( $fields ) {
			if (!\function_exists('wc_get_chosen_shipping_method_ids')) {
				return $fields;
			}

			$shipping_method = [
				'ry_ecpay_shipping_cvs_711',
				'ry_ecpay_shipping_cvs_711_freeze',
				'ry_ecpay_shipping_cvs_hilife',
				'ry_ecpay_shipping_cvs_family',
				'ry_ecpay_shipping_cvs_okmart',
				'ry_newebpay_shipping_cvs',
				'paynow_shipping_c2c_711',
				'paynow_shipping_c2c_family',
				'paynow_shipping_c2c_hilife',
				'ry_smilepay_shipping_cvs_711',
				'ry_smilepay_shipping_cvs_fami',
			];

			foreach ( $shipping_method as $method ) {
				global $woocommerce;
				$chosen_methods = wc_get_chosen_shipping_method_ids();
				if ( count( $chosen_methods ) > 0 ) {

					$chosen_shipping = $chosen_methods[0];

					if ( $chosen_shipping == $method ) {
						$fields['billing']['billing_postcode']['required']     = false;
						$fields['billing']['billing_state']['required']        = false;
						$fields['billing']['billing_city']['required']         = false;
						$fields['billing']['billing_address_1']['required']    = false;
						$fields['shipping']['shipping_first_name']['required'] = false;
						$fields['shipping']['shipping_last_name']['required']  = false;
						$fields['shipping']['shipping_phone']['required']      = false;
					}
				}

				if ( $this->is_virtual_cart() || $this->is_free_cart() ) {
					$fields['billing']['billing_postcode']['required']     = false;
					$fields['billing']['billing_state']['required']        = false;
					$fields['billing']['billing_city']['required']         = false;
					$fields['billing']['billing_address_1']['required']    = false;
					$fields['shipping']['shipping_first_name']['required'] = false;
					$fields['shipping']['shipping_last_name']['required']  = false;
					$fields['shipping']['shipping_phone']['required']      = false;
				}

				/**
				 * 增加運送離島選項
				 */
				if ( $this->has_island_postcodes() && ! $this->is_virtual_cart() ) {
					$fields['billing']['billing_island'] = [
						'type'  => 'checkbox',
						'label' => '寄送到離島區域',
						'class' => [ $this->get_postcodes()[2] ],
						'clear' => true,
					];
				}
			}
			return $fields;
		}

		/**
		 * 新增沒送到的離島縣市欄位
		 *
		 * @param array $checkout Checkout Fields.
		 */
		public function set_checkout_field( $checkout ) {
			woocommerce_form_field(
			'billing_island_none',
			[
				'type'    => 'text',
				'label'   => '沒送到的離島縣市',
				'default' => implode( ',', $this->get_island_hide() ),
			],
			);
		}

		/**
		 * 修改購買按鈕文字
		 */
		public function custom_button_text( $button_text ) {
			return get_option( ' wc_woomp_setting_place_order_text' );
		}

		/**
		 * 結帳欄位格式驗證
		 *
		 * @param array  $fields checkout fields.
		 * @param object $errors error object.
		 */
		public function field_validate( $fields, $errors ) {

			// 台灣限定：優先使用 shipping_country，若為空（未勾選寄送到不同地址）則 fallback 至 billing_country
			$shipping_country = $fields['shipping_country'] ?? '';
			$billing_country  = $fields['billing_country'] ?? '';
			$country          = ! empty( $shipping_country ) ? $shipping_country : $billing_country;

			if ( 'TW' !== $country ) {
				return;
			}

			// 如果只留下 billing_last_name.
			if ( ! array_key_exists( 'billing_first_name', $fields ) ) {
				if ( mb_strlen( $fields['billing_last_name'], 'utf-8' ) < 2 ) {
					$errors->add( 'validation', '<strong>姓名欄位</strong> 至少兩個字以上' );
				}
			}

			// 如果只留下 billing_first_name.
			if ( ! array_key_exists( 'billing_last_name', $fields ) ) {
				if ( mb_strlen( $fields['billing_first_name'], 'utf-8' ) < 2 ) {
					$errors->add( 'validation', '<strong>姓名欄位</strong> 至少兩個字以上' );
				}
			}

			// 在沒有勾選運送到離島的狀況下選擇離島超商取貨.
			if ( array_key_exists( 'CVSAddress', $fields ) ) {
				if ( 1 !== ( $fields['billing_island'] ?? '' ) && ! empty( $fields['CVSAddress'] ) ) {
					if ( strpos( $fields['CVSAddress'], '金門縣' ) > -1 || strpos( $fields['CVSAddress'], '澎湖縣' ) > -1 || strpos( $fields['CVSAddress'], '連江縣' ) > -1 ) {
						$errors->add( 'validation', '<strong>外島超商</strong> 您選擇的運送方式不在運送範圍內' );
					}
				}
			}

			// 電話位數一定要 10 碼.
			if ( array_key_exists( 'billing_phone', $fields ) ) {
				if ( mb_strlen( $fields['billing_phone'], 'utf-8' ) !== 10 ) {
					$errors->add( 'validation', '<strong>聯絡電話</strong> 長度有誤，必須為 10 碼' );
				}
			}
		}

		/**
		 * 取得所有離島郵遞區號
		 */
		public function get_postcodes() {
			global $wpdb;
			$sql              = "SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zone_locations";
			$post_coded       = $wpdb->get_col( $sql, 2 );
			$island_postcodes = [ 209, 210, 211, 212, 880, 881, 882, 883, 884, 885, 890, 891, 892, 893, 894, 896 ];
			$result           = array_intersect( $post_coded, $island_postcodes );
			return $result;
		}

		/**
		 * 檢查是否有設定台灣離島郵遞區號
		 */
		public function has_island_postcodes(): bool {
			return count( $this->get_postcodes() ) > 0;
		}

		/**
		 * 取得沒有被勾選的外島縣市
		 */
		public function get_island_hide() {
			global $wpdb;

			$island_hide      = [];
			$island_kinmen    = [ 890, 891, 892, 893, 894, 896 ];
			$island_penghu    = [ 880, 881, 882, 883, 884, 885 ];
			$island_lianjiang = [ 209, 210, 211, 212 ];

			$sql        = "SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zone_locations";
			$post_coded = $wpdb->get_col( $sql, 2 );

			if ( ! array_intersect( $post_coded, $island_kinmen ) ) {
				$island_hide[] = '金門縣';
			}

			if ( ! array_intersect( $post_coded, $island_penghu ) ) {
				$island_hide[] = '澎湖縣';
			}

			if ( ! array_intersect( $post_coded, $island_lianjiang ) ) {
				$island_hide[] = '連江縣';
			}

			return $island_hide;
		}

		/**
		 * 檢查購物車是否只有虛擬商品
		 *
		 * @return bool
		 */
		private function is_virtual_cart(): bool {
			global $woocommerce;
			$only_virtual = true;
			$items        = $woocommerce->cart->get_cart();

			foreach ( $items as $hash => $cart_item ) {
				/**
				 * @var WC_Product $_product
				 */
				$_product    = $cart_item['data'];
				$_is_virtual = $_product->is_virtual();
				if ( !$_is_virtual ) {
					$only_virtual = false;
					break;
				}
			}

			return $only_virtual;
		}

		/**
		 * 檢查購物車總金額為 0
		 */
		private function is_free_cart() {
			global $woocommerce;
			$total = $woocommerce->cart->cart_contents_total;
			if ( '0' === $total ) {
				return true;
			}
			return false;
		}

		/**
		 * 移除欄位的選填文字
		 *
		 * @param string $field 欄位.
		 * @param string $key 鍵.
		 * @param array  $args 參數.
		 * @param string $value 值.
		 * @return string
		 */
		public function remove_checkout_optional_fields_label( $field, $key, $args, $value ) {
			if ( is_checkout() && ! is_wc_endpoint_url() ) {
				$optional = '&nbsp;<span class="optional">(' . esc_html__( 'optional', 'woocommerce' ) . ')</span>';
				$field    = str_replace( $optional, '', $field );
			}
			return $field;
		}

		/**
		 * 免運提示文字
		 *
		 * @param \WC_Shipping_Rate $method Shipping method.
		 * @param int               $index 固定是 0
		 *
		 * @return void
		 */
		public function display_free_shipping_hint( $method, $index ): void {
			$method_id = str_replace( ':', '_', $method->id );
			/**
			 * @var array<{title:string,description:string,tax_status:string,cost:string,cost_requires:string,min_amount:string,weight_plus_cost:string,ignore_discounts:string,requires:string,}> $settings
			 */
			$settings = \get_option( "woocommerce_{$method_id}_settings" );

			$diff = self::get_diff_amount( $method, [ 'min_amount', 'either', 'both', 'min_amount_or_coupon', 'min_amount_and_coupon' ] );

			$cost = (float) $method->get_cost();

			if ($cost && ( null === $diff || $diff <= 0 )) {
				return;
			}

			$is_free_shipping = !$cost;

			$free_text        = \esc_html( \get_option( 'wc_woomp_setting_free_shipping_text_free_shipping' ) ? \get_option( 'wc_woomp_setting_free_shipping_text_free_shipping' ) : '免運' );
			$background_color = \get_option( 'wc_woomp_setting_free_shipping_bg_color' ) ? \get_option( 'wc_woomp_setting_free_shipping_bg_color' ) : '#d36f6f';
			$text_color       = \get_option( 'wc_woomp_setting_free_shipping_text_color' ) ? \get_option( 'wc_woomp_setting_free_shipping_text_color' ) : '#ffffff';

			$styles = [
				'white-space'   => 'nowrap',
				'width'         => 'auto',
				'position'      => 'relative',
				'top'           => $is_free_shipping ? 'unset' : '-1px',
				'font-size'     => '14px',
				'margin'        => '0 6px',
				'background'    => \esc_attr( $background_color ),
				'padding'       => '2px 8px',
				'border-radius' => '3px',
				'color'         => \esc_attr( $text_color ),
			];

			$style = '';
			foreach ( $styles as $key => $value ) {
				$style .= "{$key}:{$value};";
			}

			$non_free_text = \esc_html( str_replace( '{{price}}', "{$diff} 元", \get_option( 'wc_woomp_setting_free_shipping_text_left' ) ) );

			printf(
			/*html*/'<span class="fee-tag" style="%1$s">%2$s</span>',
			$style,
			$is_free_shipping ? $free_text : $non_free_text
			);
		}

		/**
		 * 購物車運送類別判斷
		 *
		 * @param bool $passed 是否通過.
		 * @param int  $product_id 商品 ID.
		 * @param int  $quantity 數量.
		 * @return bool
		 */
		public function set_one_shipping_class( $passed, $product_id, $quantity ) {

			$product       = wc_get_product( $product_id );
			$product_class = ( $product->get_shipping_class_id() === 0 ) ? __( 'No shipping class', 'woomp' ) : get_term( $product->get_shipping_class_id(), 'product_shipping_class' )->name;

			if ( count( WC()->cart->get_cart() ) > 0 ) {
				foreach ( WC()->cart->get_cart() as $key => $values ) {
					$cart_item  = $values['data'];
					$cart_class = ( $cart_item->get_shipping_class_id() === 0 ) ? __( 'No shipping class', 'woomp' ) : get_term( $cart_item->get_shipping_class_id(), 'product_shipping_class' )->name;
					$cart_limit;
					if ( $cart_class !== $product_class ) {
						// translators: %1$s: Cart shipping class, %2$s: Product shipping class.
						wc_add_notice( sprintf( __( '<b>Cart error occured.</b>The cart can\'t be added the products with different shipping classes.<br>Cart shipping class: <b>%1$s</b><br>Product shipping class: <b>%2$s</b>', 'woomp' ), $cart_class, $product_class ), 'error' );
						return false;
					} else {
						return $passed;
					}
				}
			} else {
				return $passed;
			}
		}

		/**
		 * 取得差多少可達免運金額
		 *
		 * @param \WC_Shipping_Rate $method Shipping method.
		 * @param array<string>     $check_requires_list 檢查的條件列表
		 * @param bool              $original 是否檢查原始金額
		 * @return float|null
		 */
		public static function get_diff_amount( $method, $check_requires_list, $original = false ): float|null {

			$method_id = str_replace( ':', '_', $method->id );
			$settings  = \get_option( "woocommerce_{$method_id}_settings" );

			if ( ! $settings ) {
				return null;
			}
			return self::get_diff_amount_by_settings( $settings, $check_requires_list, $original );
		}

		/**
		 * 取得差多少可達免運金額
		 *
		 * @param array<{cost_requires:string,requires:string,ignore_discounts:string,min_amount:string}> $settings WC_Shipping_Rate 的設定
		 * @param array<string>                                                                           $check_requires_list 檢查的條件列表
		 * @param bool                                                                                    $original 是否檢查原始金額
		 * @return float|null
		 */
		public static function get_diff_amount_by_settings( $settings, $check_requires_list, $original = false ): float|null {

			$requires         = @$settings['cost_requires'] ?: @$settings['requires'];
			$ignore_discounts = $settings['ignore_discounts'] ?? 'no';
			$min_amount       = $settings['min_amount'] ?? 0;

			if ( in_array( $requires, $check_requires_list, true ) ) {
				$total = \WC()->cart->get_displayed_subtotal();

				if ( 'no' === $ignore_discounts ) {
					$total = $total - \WC()->cart->get_discount_total();
					if ( \WC()->cart->display_prices_including_tax() ) {
						$total = $total - \WC()->cart->get_discount_tax();
					}
				}

				$total = NumberUtil::round( $total, \wc_get_price_decimals() );

				return (float) ( $min_amount - $total );
			}
			return null;
		}
	}

	/**
	 * 指定載入 Woo 客製範本寫外掛主檔案 woomp.php Line 93
	 */
}

$checkout = new WooMP_Checkout();

if ( get_option( 'wc_woomp_setting_mode', 1 ) === 'onepage' ) {
	add_action( 'wp_head', [ $checkout, 'redirect_cart_page_to_checkout' ], 1 );
	add_action( 'woocommerce_before_checkout_form', [ $checkout, 'display_cart_in_checkout_page' ] );
	add_filter( 'woocommerce_checkout_fields', [ $checkout, 'set_shipping_field' ], 10000 );
	add_action( 'woocommerce_after_order_notes', [ $checkout, 'set_checkout_field' ] );
} elseif ( get_option( 'wc_woomp_setting_mode', 1 ) === 'twopage' ) {
	add_filter( 'woocommerce_checkout_fields', [ $checkout, 'set_shipping_field' ], 10000 );
	add_action( 'woocommerce_after_order_notes', [ $checkout, 'set_checkout_field' ] );
}

if ( 'yes' === get_option( 'wc_woomp_setting_tw_field_valitdate', 'yes' ) ) {
	add_action( 'woocommerce_after_checkout_validation', [ $checkout, 'field_validate' ], 10, 2 );
}

if ( ! empty( get_option( ' wc_woomp_setting_place_order_text' ) ) ) {
	add_filter( 'woocommerce_order_button_text', [ $checkout, 'custom_button_text' ], 99, 1 );
}

if ( 'yes' === \get_option( 'wc_woomp_setting_free_shipping_hint' ) ) {
	\add_action( 'woocommerce_after_shipping_rate', [ $checkout, 'display_free_shipping_hint' ], 99, 2 );
}

add_filter( 'woocommerce_form_field', [ $checkout, 'remove_checkout_optional_fields_label' ], 10, 4 );
// add_filter( 'woocommerce_add_to_cart_validation', array( $checkout, 'set_one_shipping_class' ), 10, 3 );

WooMP_Checkout::init();

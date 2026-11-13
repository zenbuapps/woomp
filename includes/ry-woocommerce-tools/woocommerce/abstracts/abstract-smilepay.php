<?php
if ( ! class_exists( 'RY_SmilePay' ) ) {
	abstract class RY_SmilePay {

		protected static function generate_trade_no( $order_id, $order_prefix = '' ) {
			$trade_no = $order_prefix . $order_id . 'TS' . rand( 0, 9 ) . strrev( (string) time() );
			$trade_no = substr( $trade_no, 0, 20 );
			$trade_no = apply_filters( 'ry_smilepay_trade_no', $trade_no );

			return substr( $trade_no, 0, 20 );
		}

		protected static function link_server( $post_url, $args ) {
			wc_set_time_limit( 40 );

			return wp_remote_post(
				$post_url . '?' . http_build_query( $args, '', '&' ),
				[
					'timeout' => 20,
				]
			);
		}

		protected static function clean_post_data( $change_convert = false ) {
			if ( $change_convert ) {
				if ( function_exists( 'mb_convert_encoding' ) ) {
					foreach ( $_POST as $key => $value ) {
						if ( ! is_array( $value ) ) {
							$_POST[ $key ] = mb_convert_encoding( $value, 'UTF-8', 'BIG-5' );
						} else {
							unset( $_POST[ $key ] );
						}
					}
				}
			}

			$ipn_info = [];
			foreach ( $_POST as $key => $value ) {
				if ( ! is_array( $value ) ) {
					$ipn_info[ $key ] = wp_unslash( $value );
				}
			}

			return $ipn_info;
		}

		protected static function get_transaction_id( $ipn_info ) {
			if ( isset( $ipn_info->SmilePayNO ) ) {
				return (string) $ipn_info->SmilePayNO;
			}
			if ( isset( $ipn_info['Smseid'] ) ) {
				return $ipn_info['Smseid'];
			}
			return false;
		}

		protected static function get_status( $ipn_info ) {
			if ( isset( $ipn_info['Shipstatus'] ) ) {
				return trim( $ipn_info['Shipstatus'] );
			}
			if ( isset( $ipn_info['Status'] ) ) {
				return trim( $ipn_info['Status'] );
			}
			return false;
		}

		protected static function get_check_value( $ipn_info ) {
			if ( isset( $ipn_info['Mid_smilepay'] ) ) {
				return $ipn_info['Mid_smilepay'];
			}
			return false;
		}

		protected static function get_order_id( $ipn_info, $order_prefix = '' ) {
			if ( isset( $ipn_info['Data_id'] ) ) {
				$order_id = $ipn_info['Data_id'];
				$order_id = substr( $order_id, strlen( $order_prefix ), strrpos( $order_id, 'TS' ) );
				$order_id = (int) $order_id;
				if ( $order_id > 0 ) {
					return $order_id;
				}
			}
			return false;
		}

		/**
		 * 記下送給速買配的 Data_id（回呼只接受本站真的送出過的 Data_id）
		 *
		 * 同一張訂單可能重新取號，所以保留最近 10 筆，避免消費者繳了舊代碼後回呼被拒。
		 *
		 * @param WC_Order $order    訂單
		 * @param string   $meta_key 清單 meta key
		 * @param string   $data_id  Data_id
		 */
		public static function remember_data_id( $order, $meta_key, $data_id ) {
			$list = $order->get_meta( $meta_key, true );
			if ( ! is_array( $list ) ) {
				$list = [];
			}
			$list[] = (string) $data_id;
			$list   = array_slice( array_values( array_unique( $list ) ), -10 );
			$order->update_meta_data( $meta_key, $list );
			// 記下取號時的環境；測試模式的參數碼是公開的，正式環境的訂單不可接受測試參數碼簽的回呼。
			$order->update_meta_data( '_smilepay_testmode', self::is_testmode() ? 'yes' : 'no' );
		}

		/**
		 * Data_id 是否為本站送出過的
		 *
		 * @param WC_Order $order    訂單
		 * @param string   $meta_key 清單 meta key
		 * @param mixed    $data_id  回呼帶來的 Data_id
		 * @param string   $legacy   舊版單筆 meta key（升級前取號的訂單）
		 */
		public static function is_known_data_id( $order, $meta_key, $data_id, $legacy = '' ) {
			if ( ! is_string( $data_id ) || '' === $data_id ) {
				return false;
			}
			$list = $order->get_meta( $meta_key, true );
			if ( ! is_array( $list ) ) {
				$list = [];
			}
			if ( '' !== $legacy ) {
				$list[] = (string) $order->get_meta( $legacy, true );
			}
			foreach ( $list as $known ) {
				if ( is_string( $known ) && '' !== $known && hash_equals( $known, $data_id ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * 目前是否為測試模式
		 */
		public static function is_testmode() {
			return 'yes' === RY_WT::get_option( 'smilepay_gateway_testmode', 'yes' );
		}

		/**
		 * 回呼使用的環境是否與訂單取號時相同
		 *
		 * 測試模式的參數碼 9527 是公開的。升級前取號的訂單沒有記錄環境：
		 * 正式模式下放行（沿用原本行為），測試模式下一律拒絕。
		 *
		 * @param WC_Order $order 訂單
		 */
		public static function is_same_mode_as_order( $order ) {
			$order_mode   = (string) $order->get_meta( '_smilepay_testmode', true );
			$current_mode = self::is_testmode() ? 'yes' : 'no';
			if ( '' === $order_mode ) {
				return 'no' === $current_mode;
			}
			return $order_mode === $current_mode;
		}

		protected static function die_success() {
			die( '<Roturlstatus>RY_SmilePay</Roturlstatus>' );
		}

		protected static function die_error() {
			die( '0|' );
		}
	}
}

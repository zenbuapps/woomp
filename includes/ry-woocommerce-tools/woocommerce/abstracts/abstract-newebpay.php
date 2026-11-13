<?php
if ( ! class_exists( 'RY_NewebPay' ) ) {
	abstract class RY_NewebPay {

		protected static $do_die = false;

		protected static function generate_trade_no( $order_id, $order_prefix = '' ) {
			$trade_no = $order_prefix . $order_id . 'TS' . rand( 0, 9 ) . strrev( (string) time() );
			$trade_no = substr( $trade_no, 0, 30 );
			$trade_no = apply_filters( 'ry_newebpay_trade_no', $trade_no );
			return substr( $trade_no, 0, 30 );
		}

		// 新增 get_item_name function
		protected static function get_item_name( $item_name, $order ) {
			if ( empty( $item_name ) ) {
				$items = $order->get_items();
				if ( count( $items ) ) {
					$item      = reset( $items );
					$item_name = trim( $item->get_name() );
				}
			}
			$item_name = str_replace( [ '^', '\'', '`', '!', '@', '＠', '#', '%', '&', '*', '+', '\\', '"', '<', '>', '|', '_', '[', ']' ], '', $item_name );

			return $item_name;
		}

		protected static function args_encrypt( $args, $HashKey, $HashIV ) {
			ksort( $args );

			$args_string = http_build_query( $args );

			$pad          = 32 - ( strlen( $args_string ) % 32 );
			$args_string .= str_repeat( chr( $pad ), $pad );

			if ( function_exists( 'openssl_encrypt' ) ) {
				$encrypt_string = openssl_encrypt( $args_string, 'aes-256-cbc', $HashKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $HashIV );
			}
			$encrypt_string = trim( bin2hex( $encrypt_string ) );

			return $encrypt_string;
		}

		protected static function args_decrypt( $string, $HashKey, $HashIV ) {
			// 金鑰為空時解不出可信任的資料；非十六進位字串 hex2bin 會丟 warning
			if ( ! is_string( $string ) || '' === $string || 0 !== strlen( $string ) % 2 || ! ctype_xdigit( $string ) ) {
				return false;
			}
			if ( ! is_string( $HashKey ) || '' === $HashKey || ! is_string( $HashIV ) || '' === $HashIV || ! function_exists( 'openssl_decrypt' ) ) {
				return false;
			}

			$decrypt_string = openssl_decrypt( hex2bin( $string ), 'aes-256-cbc', $HashKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $HashIV );
			if ( ! is_string( $decrypt_string ) || '' === $decrypt_string ) {
				return false;
			}

			// PKCS#7 padding（加密端以 32 bytes 為區塊）
			$slast = ord( substr( $decrypt_string, -1 ) );
			if ( $slast < 1 || $slast > 32 || $slast > strlen( $decrypt_string ) ) {
				return false;
			}
			if ( substr( $decrypt_string, -$slast ) !== str_repeat( chr( $slast ), $slast ) ) {
				return false;
			}
			return substr( $decrypt_string, 0, strlen( $decrypt_string ) - $slast );
		}

		protected static function generate_hash_value( $string, $HashKey, $HashIV ) {
			$string = 'HashKey=' . $HashKey
			. '&' . $string
			. '&HashIV=' . $HashIV;
			$string = hash( 'sha256', $string );
			$string = strtoupper( $string );

			return $string;
		}

		protected static function get_tradeInfo_value( $ipn_info ) {
			if ( isset( $ipn_info['TradeInfo'] ) ) {
				return $ipn_info['TradeInfo'];
			}
			return false;
		}

		protected static function get_tradeSha_value( $ipn_info ) {
			if ( isset( $ipn_info['TradeSha'] ) ) {
				return $ipn_info['TradeSha'];
			}
			return false;
		}

		protected static function get_status( $ipn_info ) {
			if ( isset( $ipn_info->Status ) ) {
				return $ipn_info->Status;
			}
			return false;
		}

		protected static function get_status_msg( $ipn_info ) {
			if ( isset( $ipn_info->Message ) ) {
				return $ipn_info->Message;
			}
			return false;
		}

		protected static function get_transaction_id( $ipn_info ) {
			if ( isset( $ipn_info->Result->TradeNo ) ) {
				return $ipn_info->Result->TradeNo;
			}
			return false;
		}

		protected static function get_payment_type( $ipn_info ) {
			if ( isset( $ipn_info->Result->PaymentType ) ) {
				return $ipn_info->Result->PaymentType;
			}
			return false;
		}

		protected static function get_order_id( $ipn_info, $order_prefix = '' ) {
			if ( isset( $ipn_info->Result->MerchantOrderNo ) ) {
				$order_id = $ipn_info->Result->MerchantOrderNo;
				$order_id = substr( $order_id, strlen( $order_prefix ), strrpos( $order_id, 'TS' ) );
				$order_id = (int) $order_id;
				if ( $order_id > 0 ) {
					return $order_id;
				}
			}
			return false;
		}

		public static function set_do_die() {
			self::$do_die = true;
		}

		protected static function die_success() {
			if ( self::$do_die ) {
				die( '1|OK' );
			}
		}

		protected static function die_error() {
			if ( self::$do_die ) {
				die( '1|OK' );
			}
		}
	}
}

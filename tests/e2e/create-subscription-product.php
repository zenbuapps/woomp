<?php
/**
 * 建立訂閱商品 — 用於 E2E 測試
 */

$product = new WC_Product_Subscription();
$product->set_name( 'E2E Monthly Sub' );
$product->set_regular_price( '99' );
$product->set_status( 'publish' );
$product->save();

$id = $product->get_id();
update_post_meta( $id, '_subscription_price', '99' );
update_post_meta( $id, '_subscription_period', 'month' );
update_post_meta( $id, '_subscription_period_interval', '1' );
update_post_meta( $id, '_subscription_length', '0' );
update_post_meta( $id, '_subscription_sign_up_fee', '0' );
update_post_meta( $id, '_subscription_trial_length', '0' );
wp_set_object_terms( $id, 'subscription', 'product_type' );

// 設定 PayUni V3 訂閱環境
update_option( 'wc_woomp_enabled_payuni_gateway', 'yes' );
update_option( 'payuni_payment_testmode_enabled', 'yes' );
update_option( 'payuni_payment_merchant_no_test', 'S05584374' );
update_option( 'payuni_payment_hash_key_test', 'tnfdY03NofsO0gRux1LOtXVEp3xZOXBf' );
update_option( 'payuni_payment_hash_iv_test', 'UffVePT5rgd3O8CR' );
update_option( 'payuni_subscription_version', 'v3' );

// 啟用閘道
update_option( 'woocommerce_payuni-credit-subscription-v3_settings', [
	'enabled'     => 'yes',
	'title'       => '統一金流 PAYUNi 信用卡定期定額 v3',
	'description' => '透過統一金流 PAYUNi 信用卡定期定額進行站內付款',
] );

update_option( 'woocommerce_payuni-credit-v3_settings', [
	'enabled'              => 'yes',
	'title'                => '統一金流 PAYUNi 信用卡 v3',
	'description'          => '透過統一金流 PAYUNi 信用卡進行站內付款',
	'enable_tokenization'  => 'yes',
	'installment_options'  => [],
	'enable_3d_auth'       => 'no',
] );

// 設定台灣為銷售國家
update_option( 'woocommerce_allowed_countries', 'specific' );
update_option( 'woocommerce_specific_allowed_countries', [ 'TW' ] );
update_option( 'woocommerce_default_country', 'TW' );
update_option( 'woocommerce_currency', 'TWD' );

echo 'SUBSCRIPTION_PRODUCT_ID=' . $id . PHP_EOL;
echo 'URL=' . get_permalink( $id ) . PHP_EOL;

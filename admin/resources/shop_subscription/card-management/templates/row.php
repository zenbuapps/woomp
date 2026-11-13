<?php


$default_args = [
	'user_id'      => 0,
	'token_id'     => '',
	'token'        => '',
	'is_default'   => false,
	'type'         => '',
	'last4'        => '',
	'expiry_year'  => '',
	'expiry_month' => '',
	'card_type'    => '',
	'card_name'    => '',
];

$args = wp_parse_args( $args, $default_args );

[
	'user_id'  => $user_id,
	'token_id' => $token_id,
	'token'        => $token,
	'is_default'   => $is_default,
	'type'         => $type,
	'last4'        => $last4,
	'expiry_year'  => $expiry_year,
	'expiry_month' => $expiry_month,
	'card_type'    => $card_type,
	'card_name'    => $card_name,

] = $args;


$order_url = add_query_arg(
	[
		'post_type'      => 'shop_order',
		'_customer_user' => $user_id,
		'last4'          => $last4,
	],
	admin_url( 'edit.php' )
);

// $subscription_id 由 CardManagement::render_meta_box_content() 提供.
$subscription_id = isset( $subscription_id ) ? absint( $subscription_id ) : 0;

$setting_html = $is_default ? '<span class="rounded-md px-4 py-2 bg-[#C6E1C6] text-[#5B841B]">預設</span>' : "<span class='woomp_ajax cursor-pointer' data-action='woomp_set_default' data-token_id='{$token_id}' data-user_id='{$user_id}' data-subscription_id='{$subscription_id}'>設為預設</span>";

printf(
/*html*/    '
<div>%1$s</div>
<div>%2$s</div>
<div>%3$s</div>
<div>%4$s</div>
<div>%5$s</div>
<div>%6$s</div>
',
	$last4,
	sprintf( '%s/%s', $expiry_month, $expiry_year ),
	$card_name,
	"<a target='_blank' href='{$order_url}'>查看</a>",
	$setting_html,
	"<span class='woomp_ajax cursor-pointer text-red-500' data-action='woomp_remove' data-token_id='{$token_id}' data-subscription_id='{$subscription_id}'>移除</span>",
);

jQuery(
	function ($) {
		$(document).ready(
			function () {
				$('.btnPayuniV3SubscriptionPayManual').click(
					function () {
						$.blockUI({ message: '<p>處理中...</p>' });

						const data = {
							action: 'payuni_v3_subscription_pay_manual',
							nonce: payuni_v3_subscription_params.ajax_nonce,
							orderId: $(this).val(),
						};

						$.post(
							ajaxurl,
							data,
							function (response) {
								if (response?.success) {
									alert(response.data);
									location.reload(true);
								} else {
									alert(response?.data || '扣款失敗，請查看訂單備註');
									location.reload(true);
								}
							}
						);
					}
				);
			}
		);
	}
);

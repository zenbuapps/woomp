var ecpayShippingInfo;

jQuery(
	function ($) {

		if (typeof ry_shipping_params !== 'undefined') {
			ecpayShippingInfo = ry_shipping_params.postData;
		}
		$( '.woocommerce-checkout p.ry-hide' ).hide();

		$( document.body ).on(
			'updated_checkout',
			function (e, data) {
				if (data !== undefined) {
					if (data.fragments.ecpay_shipping_info !== undefined) {
						if (data.fragments.ecpay_shipping_info.postData === undefined) {
							$( '.woocommerce-shipping-fields__field-wrapper p.cvs-info' ).hide();
							$( '.woocommerce-shipping-fields__field-wrapper p:not(.cvs-info)' ).show();
							$( '.woocommerce-shipping-fields__field-wrapper p#shipping_phone_field' ).show();
							RYECPayRemoveSendCvs();
						} else {
							ecpayShippingInfo = data.fragments.ecpay_shipping_info.postData;
							$( '.woocommerce-shipping-fields__field-wrapper p:not(.cvs-info)' ).hide();
							$( '.woocommerce-shipping-fields__field-wrapper p#shipping_first_name_field' ).show();
							$( '.woocommerce-shipping-fields__field-wrapper p#shipping_last_name_field' ).show();
							$( '.woocommerce-shipping-fields__field-wrapper p#shipping_country_field' ).show();
							$( '.woocommerce-shipping-fields__field-wrapper p#shipping_phone_field' ).show();
							$( '.woocommerce-shipping-fields__field-wrapper p.cvs-info' ).show();
							if ($( '#ship-to-different-address-checkbox' ).prop( 'checked' ) === false) {
								$( '#ship-to-different-address-checkbox' ).click();
							}
							if ($( 'input#LogisticsSubType' ).length) {
								if ($( 'input#LogisticsSubType' ).val() == ecpayShippingInfo.LogisticsSubType) {
									$( '#CVSStoreName_field strong' ).text( $( 'input#CVSStoreName' ).val() );
									$( '#CVSAddress_field strong' ).text( $( 'input#CVSAddress' ).val() );
									$( '#CVSTelephone_field strong' ).text( $( 'input#CVSTelephone' ).val() );

									if ($( 'input#CVSStoreName' ).val() != '') {
										$( '.choose_cvs .show_choose_cvs_name' ).show();
										$( '.choose_cvs .choose_cvs_name' ).text( $( 'input#CVSStoreName' ).val() );
									}
								} else {
									RYECPayRemoveSendCvs();
								}
							} else {
								RYECPayRemoveSendCvs();
							}
						}
					} else if (data.fragments.newebpay_shipping_info !== undefined) {
						$( '.woocommerce-shipping-fields__field-wrapper p' ).hide();
						if ($( '#ship-to-different-address-checkbox' ).prop( 'checked' ) === false) {
							$( '#ship-to-different-address-checkbox' ).click();
						}
					} else if (data.fragments.smilepay_shipping_info !== undefined) {
						$( '.woocommerce-shipping-fields__field-wrapper p:not(.cvs-info)' ).hide();
						$( '.woocommerce-shipping-fields__field-wrapper p#shipping_first_name_field' ).show();
						$( '.woocommerce-shipping-fields__field-wrapper p#shipping_last_name_field' ).show();
						$( '.woocommerce-shipping-fields__field-wrapper p#shipping_phone_field' ).show();
						$( '.woocommerce-shipping-fields__field-wrapper p.cvs-info' ).show();
						$( '.woocommerce-shipping-fields__field-wrapper #CVSStoreName_field' ).hide();
						$( '.woocommerce-shipping-fields__field-wrapper #CVSAddress_field' ).hide();
						$( '.woocommerce-shipping-fields__field-wrapper #CVSTelephone_field' ).hide();
						if ($( '#ship-to-different-address-checkbox' ).prop( 'checked' ) === false) {
							$( '#ship-to-different-address-checkbox' ).click();
						}
					} else {
						$( '.woocommerce-shipping-fields__field-wrapper p.cvs-info' ).hide();
						$( '.woocommerce-shipping-fields__field-wrapper p:not(.cvs-info)' ).show();
						RYECPayRemoveSendCvs();
					}
				}

				if (window.sessionStorage.getItem( 'RYECPayTempCheckoutForm' ) !== null) {
					var formData = JSON.parse( window.sessionStorage.getItem( 'RYECPayTempCheckoutForm' ) ),
					notSetData   = ['LogisticsSubType', 'CVSStoreID', 'CVSStoreName', 'CVSAddress', 'CVSTelephone', 'terms'];
					for (var idx in formData) {
						if (formData[idx].name.substr( 0, 1 ) == '_') {
						} else if (notSetData.includes( formData[idx].name )) {
						} else {
							var $item = jQuery( '[name="' + formData[idx].name + '"]' );
							switch ($item.prop( 'tagName' )) {
								case 'INPUT':
									if ($item.attr( 'type' ) == 'checkbox') {
										if ($item.prop( 'checked' ) === false) {
											$item.trigger( 'click' );
										}
										break;
									}
									if ($item.attr( 'type' ) == 'radio') {
										$item = jQuery( '[name="' + formData[idx].name + '"][value="' + formData[idx].value + '"]' );
										if ($item.prop( 'checked' ) === false) {
											$item.trigger( 'click' );
										}
										break;
									}
								case 'TEXTAREA':
								case 'SELECT':
									var oldVal = $item.val();
									$item.val( formData[idx].value );
									if (oldVal != formData[idx].value) {
										$item.trigger( 'change' );
									}
									break;
								default:
									break;
							}
						}
					}
					window.sessionStorage.removeItem( 'RYECPayTempCheckoutForm' );
				}
			}
		);

		// 選了綠界超商取貨但沒選門市時，阻止送出（按鈕點擊與 Enter 送出都會觸發 checkout_place_order）
		// 這裡只是提早提示，後端 RY_ECPay_Shipping::validate_cvs_store() 才是最終把關（issue #136）
		$( 'form.checkout' ).on(
			'checkout_place_order',
			function (e) {
				// 不擋時不回傳值：回傳 true 會覆蓋先綁定的其他外掛回傳的 false，讓它們的阻擋失效
				if ( ! RYECPayIsCvsChosen() || RYECPayHasCvsStore()) {
					return;
				}
				e.stopImmediatePropagation();
				RYECPayShowCheckoutError( '請選擇超商取貨門市' );
				return false;
			}
		);
	}
);

function RYECPayIsCvsChosen() {
	var chosen = false;
	jQuery( 'select.shipping_method, input[name^="shipping_method"][type="radio"]:checked, input[name^="shipping_method"][type="hidden"]' ).each(
		function () {
			if (String( jQuery( this ).val() ).indexOf( 'ry_ecpay_shipping_cvs' ) === 0) {
				chosen = true;
			}
		}
	);
	return chosen;
}

function RYECPayHasCvsStore() {
	return String( jQuery( 'input#CVSStoreID' ).val() || '' ).trim() !== ''
		&& String( jQuery( 'input#CVSStoreName' ).val() || '' ).trim() !== '';
}

function RYECPayShowCheckoutError(message) {
	var $form = jQuery( 'form.checkout' );
	jQuery( '.woocommerce-NoticeGroup-checkout' ).remove();
	var $notice = jQuery( '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><ul class="woocommerce-error" role="alert"><li></li></ul></div>' );
	$notice.find( 'li' ).text( message );
	$form.prepend( $notice );
	if (typeof jQuery.scroll_to_notices === 'function') {
		jQuery.scroll_to_notices( $notice );
	}
	jQuery( document.body ).trigger( 'checkout_error', [ message ] );
}

function RYECPaySendCvsPost() {
	window.sessionStorage.setItem( 'RYECPayTempCheckoutForm', JSON.stringify( jQuery( 'form.checkout' ).serializeArray() ) );
	var html = '<form id="RYECPaySendCvsForm" action="' + ry_shipping_params.postUrl + '" method="post">';
	for (var idx in ecpayShippingInfo) {
		html += '<input type="hidden" name="' + idx + '" value="' + ecpayShippingInfo[idx] + '">';
	}
	if (window.innerWidth < 1024) {
		html += '<input type="hidden" name="Device" value="1">';
	}
	html                    += '</form>';
	document.body.innerHTML += html;
	document.getElementById( 'RYECPaySendCvsForm' ).submit();
}

function RYECPayRemoveSendCvs() {
	// 一併清掉門市代號與物流子類型，避免切換超商後送出前一家門市的殘留資料（issue #136）
	jQuery( 'input#CVSStoreID' ).val( '' );
	jQuery( 'input#LogisticsSubType' ).val( '' );
	jQuery( 'input#CVSStoreName' ).remove();
	jQuery( 'input#CVSAddress' ).remove();
	jQuery( 'input#CVSTelephone' ).remove();
	jQuery( '#CVSStoreName_field strong' ).text( '' );
	jQuery( '#CVSAddress_field strong' ).text( '' );
	jQuery( '#CVSTelephone_field strong' ).text( '' );
	jQuery( '.choose_cvs .show_choose_cvs_name' ).hide();
}

var paynow_shipping_info;

(function ($) {
	'use strict';

	if (typeof paynow_shipping_object !== 'undefined') {
		paynow_shipping_info = paynow_shipping_object;
	}

	$( document ).on(
		'click',
		'#choose-cvs-btn',
		function () {
			console.log( 'click choose cvs btn' )

			var html = '<form id="paynow-choose-cvs-form" action="' + paynow_shipping_info.ajax_url + '" method="post">';

			for (const [key, value] of Object.entries( paynow_shipping_info )) {
				html += '<input type="hidden" name="' + key + '" value="' + value + '">';
			}
			html += '</form>';

			console.log( html );
			document.body.innerHTML += html;
			document.getElementById( 'paynow-choose-cvs-form' ).submit();

		}
	);

	$( document.body ).on(
		'updated_checkout',
		function (e, data) {
			if ( data && data.fragments && data.fragments.paynow_shipping_info !== undefined ) {
				console.log( 'on updated_checkout' );
				// console.log(data.fragments.paynow_shipping_info.shipping_data);
				// console.log(paynow_shipping_object);
				// if the shipping method is cvs, always check ship to different address
				if (typeof data.fragments.paynow_shipping_info.shipping_data.Logistic_serviceID !== 'undefined') {

					if (data.fragments.paynow_shipping_info.shipping_data.is_paynow_cvs) {
						paynow_shipping_info = data.fragments.paynow_shipping_info.shipping_data;
						console.log( paynow_shipping_info );
						$( '.woocommerce-shipping-fields__field-wrapper p:not(.paynow-shipping-field)' ).hide();

						$( '.woocommerce-shipping-fields__field-wrapper p#shipping_first_name_field' ).show();
						$( '.woocommerce-shipping-fields__field-wrapper p#shipping_last_name_field' ).show();

						if (data.fragments.paynow_shipping_info.shipping_data.Logistic_serviceID != $( '#paynow_service' ).val()) {
							$( '#paynow_service' ).val( '' );
							$( '#paynow_storeid' ).val( '' );
							$( '#paynow_storename' ).val( '' );
							$( '#paynow_storeaddress' ).val( '' );
							$( '#paynow_reservedno' ).val( '' );
							$( '#paynow_shipdate' ).val( '' );
						}

						$( '.woocommerce-shipping-fields__field-wrapper p.paynow-shipping-field' ).show();

						// Family B2C Frozen shipping
						if (data.fragments.paynow_shipping_info.shipping_data.Logistic_serviceID === '23' || data.fragments.paynow_shipping_info.shipping_data.Logistic_serviceID === '24' ) {
							$( '.paynow-shipping-family-frozen-field' ).show();
						} else {
							$( '.paynow-shipping-family-frozen-field' ).hide();
						}

						if ($( '#paynow_storename' ).val() != '') {
							$( '#choose-cvs-btn' ).show();
							$( '#choose-cvs-btn' ).html( '選擇超商:' + $( 'input#paynow_storename' ).val() );
						}

						// 超取要強制勾選 ship to different address
						if ($( '#ship-to-different-address-checkbox' ).prop( 'checked' ) === false) {
							$( '#ship-to-different-address-checkbox' ).click();
						}
					} else {
						// 宅配不需要超商資訊，但需要手機
						$( '.woocommerce-shipping-fields__field-wrapper p:not(.paynow-shipping-field)' ).show();
						$( '.woocommerce-shipping-fields__field-wrapper p.paynow-shipping-field' ).hide();
						$( '.woocommerce-shipping-fields__field-wrapper p.paynow-shipping-field#shipping_phone_field' ).show();
					}

				} else {
					console.log( 'no logisc data' );
					$( '.woocommerce-shipping-fields__field-wrapper p.paynow-shipping-field' ).hide();
					$( '.woocommerce-shipping-fields__field-wrapper p:not(.paynow-shipping-field)' ).show();
				}
			}
		}
	);

})( jQuery );

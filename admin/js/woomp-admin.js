(function ($) {
	// 後台訂單地址整併為一欄
	function set_one_line_address(type) {
		var addr0 = $("#_" + type + "_postcode").val();
		var addr1 = $("#_" + type + "_country option:selected").text();
		var addr2 = $("#_" + type + "_state").val();
		var addr3 = $("#_" + type + "_city").val();
		var addr4 = $("#_" + type + "_address_1").val();
		var addr5 = $("#_" + type + "_address_2").val();
		var isShipping = type === "shipping" ? "Shipping" : "";

		$(".full-address" + isShipping + " input").val(
			addr0 + " " + addr1 + addr2 + addr3 + addr4 + addr5
		);
		$("#fullAddress" + isShipping + " span").html(
			addr0 + " " + addr1 + addr2 + addr3 + addr4 + addr5
		);

		$("#billingName").insertAfter(
			".order_data_column:nth-child(2) .address p:first-child"
		);
		$("#fullAddress").insertAfter("p#billingName");

		$("#fullAddressShipping").insertAfter(
			".order_data_column:nth-child(3) .address p:first-child"
		);

		$(".full-address" + isShipping + " input").on("paste change", function () {
			setTimeout(() => {
				var addrf1 = $(".full-address" + isShipping + " input")
					.val()
					.replaceAll("市", "市 ");
				var addrf2 = addrf1.replaceAll("區", "區 ");
				var addr = addrf2.split(" ");
				$("#_" + type + "_state").val(addr[1]);
				$("#_" + type + "_city").val(addr[2]);
				$("#_" + type + "_postcode").val(addr[0]);
				$("#_" + type + "_address_1").val(addr[3]);
			}, 300);
		});
	}

	set_one_line_address("billing");
	set_one_line_address("shipping");

	function set_cvs_info() {
		if ($('input[name="_shipping_cvs_store_ID"]').length > 0) {
			$("#cvsStore").insertAfter(
				".order_data_column:nth-child(3) .address p:first-child"
			);
			$("#cvsNumber").insertAfter("#cvsStore");
			$("#cvsName").insertAfter("#cvsNumber");
			$("#cvsAddress").insertAfter("#cvsName");
		}
	}
	set_cvs_info();

	// 離島選擇介面初始化
	function init_island() {
		$(".wc-shipping-zone-postcodes-toggle").after(
			`<fieldset class="toggle" id="zoneTwIslandToggle"><span class="woocommerce-help-tip tip-island"></span><label for="zoneTwIslandToggleBtn"><input name="zoneTwIslandToggleBtn" id="zoneTwIslandToggleBtn" type="checkbox" class="toggle"><label for="zoneTwIslandToggleBtn">Toggle</label><span class="description" style="font-size: 14px;">這是離島區域<br></span></label></fieldset>`
		);

		// 增加離島複選方塊
		$("#zoneTwIslandToggle").after(`
			<table id="zoneTwIslandWrap" style="display:none;">
				<tr>
					<th>金門縣</th>
					<th>澎湖縣</th>
					<th>連江縣</th>
				</tr>
				<tr>
					<td><label for="group1"><input id="group1" class="group" type="checkbox">全選</label></td>
					<td><label for="group2"><input id="group2" class="group" type="checkbox">全選</label></td>
					<td><label for="group3"><input id="group3" class="group" type="checkbox">全選</label></td>
				</tr>
				<tr>
						<td><label for="island1"><input id="island1" class="group1 island" name="island[]" type="checkbox" value="890">金沙鎮</label></td>
						<td><label for="island3"><input id="island3" class="group2 island" name="island[]" type="checkbox" value="880">馬公市</label></td>
						<td><label for="island2"><input id="island2" class="group3 island" name="island[]" type="checkbox" value="209">南竿鄉</label></td>
				</tr>
				<tr>
						<td><label for="island4"><input id="island4" class="group1 island" name="island[]" type="checkbox" value="891">金湖鎮</label></td>
						<td><label for="island6"><input id="island6" class="group2 island" name="island[]" type="checkbox" value="881">西嶼鄉</label></td>
						<td><label for="island5"><input id="island5" class="group3 island" name="island[]" type="checkbox" value="210">北竿鄉</label></td>
				</tr>
				<tr>
						<td><label for="island7"><input id="island7" class="group1 island" name="island[]" type="checkbox" value="892">金寧鄉</label></td>
						<td><label for="island9"><input id="island9" class="group2 island" name="island[]" type="checkbox" value="882">望安鄉</label></td>
						<td><label for="island8"><input id="island8" class="group3 island" name="island[]" type="checkbox" value="211">莒光鄉</label></td>
				</tr>
				<tr>
						<td><label for="island10"><input id="island10" class="group1 island" name="island[]" type="checkbox" value="893">金城鎮</label></td>
						<td><label for="island12"><input id="island12" class="group2 island" name="island[]" type="checkbox" value="883">七美鄉</label></td>
						<td><label for="island11"><input id="island11" class="group3 island" name="island[]" type="checkbox" value="212">東引鄉</label></td>
				</tr>
				<tr>
						<td><label for="island13"><input id="island13" class="group1 island" name="island[]" type="checkbox" value="894">烈嶼鄉</label></td>
						<td><label for="island14"><input id="island14" class="group2 island" name="island[]" type="checkbox" value="884">白沙鄉</label></td>
						<td>&nbsp;</td>
				</tr>
				<tr>
						<td><label for="island15"><input id="island15" class="group1 island" name="island[]" type="checkbox" value="896">烏坵鄉</label></td>
					<td><label for="island16"><input id="island16" class="group2 island" name="island[]" type="checkbox" value="885">湖西鄉</label></td>
					<td>&nbsp;</td>
				</tr>
			</table>
			`);

		// 離島 Tooltip 說明
		$(".tip-island").hover(function () {
			$("#tiptip_content").text(
				"台灣本島 與 台灣離島，需要分別建立自己的運送區域。台灣本島是一個運送區域，台灣離島是另一個運送區域，兩個區域可以有不同的運送方式&運費。若啟用此選項，即會將此運送區域改為 台灣離島的運送區域，並且在上一層運送區域的列表，請務必將離島區域，置放於本島區域的上面，才能正確啟用"
			);
		});
	}

	if ($('#zone_locations option[value="country:TW"]').is(":selected")) {
		init_island();
		$("#zoneTwIslandToggle").show();
	}

	$("#zone_locations").on("change", function () {
		if ($('#zone_locations option[value="country:TW"]').is(":selected")) {
			if ($("#zoneTwIslandToggle").length === 0) {
				// 避免重複新增介面
				init_island();
			}
			$("#zoneTwIslandToggle").show();
			$("#zoneTwIslandToggleBtn").prop("checked", false);
		} else {
			$("#zoneTwIslandToggle").hide();
			$("#zoneTwIslandWrap").hide();
			$("#zoneTwIslandWrap input").prop("checked", false);
			$(".wc-shipping-zone-postcodes-toggle").show();
			updatePostCode();
		}
	});

	// 取得有勾選的地區
	function updatePostCode() {
		var checkedCode = $("#zoneTwIslandWrap input.island:checkbox:checked")
			.map(function () {
				return $(this).val();
			})
			.get();

		$("#zone_postcodes").text(checkedCode.sort().join("\n"));
		$("#zone_postcodes").trigger("input");
	}

	// 寫入郵遞區號
	$("body").on("change", "#zoneTwIslandToggleBtn", function () {
		if ($(this).is(":checked")) {
			$("#zoneTwIslandWrap").show();
			$("#zoneTwIslandWrap input").prop("checked", true);
			$(".wc-shipping-zone-postcodes").hide();
			updatePostCode();
		} else {
			$("#zoneTwIslandWrap").hide();
			$("#zoneTwIslandWrap input").prop("checked", false);
			$(".wc-shipping-zone-postcodes").show();
			updatePostCode();
		}
	});

	// 判斷是否有輸入郵遞區號
	if (
		$("#zone_postcodes").val() != "" &&
		$('#zone_locations option[value="country:TW"]').is(":selected")
	) {
		$("#zoneTwIslandToggleBtn").prop("checked", true);
		$("#zoneTwIslandWrap").show();
	} else {
		$("#zoneTwIslandToggleBtn").prop("checked", false);
		$("#zoneTwIslandWrap").hide();
	}

	// 讀取既有的郵遞區號
	if ($("#zone_postcodes").length > 0) {
		var current_code = $("#zone_postcodes").val().split("\n");
		for (let index = 0; index < current_code.length; index++) {
			$(
				"#zoneTwIslandWrap input.island[value='" + current_code[index] + "']"
			).prop("checked", true);
		}
	}

	// 郵遞區號寫入與刪除
	$("body").on(
		"change",
		'#zoneTwIslandWrap input[type="checkbox"]',
		function () {
			updatePostCode();
		}
	);

	// 全選寫入與刪除
	for (let index = 1; index < 4; index++) {
		$("body").on("change", "#group" + index, function () {
			if ($(this).is(":checked")) {
				$(".group" + index).prop("checked", true);
				updatePostCode();
			} else {
				$(".group" + index).prop("checked", false);
				updatePostCode();
			}
		});
	}

	function triggerSaveAtrributes() {
		$("body")
			.find(".save_attributes_after")
			.on("click", function (e) {
				e.preventDefault();
				$(".save_attributes").trigger("click");
			});
		$("body")
			.find(".attribute_name")
			.on("keypress", function (e) {
				setTimeout(() => {
					$(".save_attributes").removeClass("disabled");
					$(".save_attributes").removeAttr("aria-disabled");
				}, 200);
			});
	}

	$(document.body).on("woocommerce_added_attribute", function () {
		triggerSaveAtrributes();
	});
	$("#variable_product_options").on("reload", function () {
		triggerSaveAtrributes();
	});
	triggerSaveAtrributes();

	// 訂單列表頁更新綠界物流訂單號
	$(".btnShippingNoEcpay").on("click", function (e) {
		e.preventDefault();
	});

	// 訂單列表頁更新非綠界物流訂單號
	$('.shippingNoWrap input[name="shippingNo"]').on("change", function () {
		$(this).parent().find(".shipping-no-loading").show();
		var data = {
			action: "update_shipping_number",
			shippingNo: $(this).val(),
			orderId: $(this).next().val(),
			nonce: window.woompAdmin ? window.woompAdmin.shippingNoNonce : "",
		};

		$.ajax({
			url: ajaxurl,
			data: data,
			type: "POST",
			dataType: "json",
			success: function (data) {
				$(".shipping-no-loading").hide();
			},
		});
	});

	// 訂單管理頁刪除綠界物流資訊
	$(".btnEcpayShippingCvsRemove").on("click", function (e) {
		e.preventDefault();

		$("#ry-ecpay-shipping-info").addClass("loading");

		const data = {
			action: "delete_shipping_ecpay_cvs",
			ecpayShippingId: $(this).data("item"),
			orderId: $(this).data("order"),
		};

		const ecpayShippingId = data.ecpayShippingId;

		$.ajax({
			url: ajaxurl,
			data: data,
			type: "POST",
			dataType: "json",
			success: function (data) {
				$("#ry-ecpay-shipping-info").removeClass("loading");
				alert(data?.data);
				if (data?.success) {
					$(
						"#ry-ecpay-shipping-info tr.shippingId-" + ecpayShippingId
					).remove();
				}
			},
			error: function (data) {
				console.log(data);
				$("#ry-ecpay-shipping-info").removeClass("loading");
				alert(`發生錯誤:${data?.data}`);
			},
		});
	});

	// 訂單批次操作列印託運單另開視窗
	$("#bulk-action-selector-top,#bulk-action-selector-bottom").on(
		"change",
		function () {
			var action = $(this).val();
			if (
				action.includes("ry_print_ecpay_cvs") ||
				action.includes("ry_print_ecpay_home_tcat")
			) {
				$("#posts-filter").attr("target", "_blank");
			} else {
				$("#posts-filter").removeAttr("target");
			}
		}
	);

	// 調整帳單電話位置
	$(".column-billing_address").each(function () {
		$(this).find(".billing-phone").insertBefore($(this).find(".description"));
	});
	// 調整運送電話位置
	$(".column-shipping_address").each(function () {
		$(this).find(".shipping-phone").insertBefore($(this).find(".description"));
	});

	// 判斷設定頁兩頁式結帳提示文字顯示
	$("#wc_woomp_setting_mode").change(function () {
		if ($(this).val() === "twopage") {
			$("#wc_woomp_setting_mode_twopage_message").parent().parent().show();
		} else {
			$("#wc_woomp_setting_mode_twopage_message").parent().parent().hide();
		}
	});
	if ($("#wc_woomp_setting_mode").val() === "twopage") {
		$("#wc_woomp_setting_mode_twopage_message").parent().parent().show();
	} else {
		$("#wc_woomp_setting_mode_twopage_message").parent().parent().hide();
	}

	// 判斷設定頁免運標籤選項顯示
	$("#wc_woomp_setting_free_shipping_hint").change(function () {
		if ($(this).is(":checked")) {
			$(".free-shipping-hint-setting").parent().parent().show();
		} else {
			$(".free-shipping-hint-setting").parent().parent().hide();
		}
	});
	if ($("#wc_woomp_setting_free_shipping_hint").is(":checked")) {
		$(".free-shipping-hint-setting").parent().parent().show();
	} else {
		$(".free-shipping-hint-setting").parent().parent().hide();
	}
})(jQuery);

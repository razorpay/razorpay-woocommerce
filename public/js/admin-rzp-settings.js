window.onload = function() {
	// for confirmation of toggling 1cc
	var enableRzpCheckout = document.getElementById('woocommerce_razorpay_enable_1cc');
	if (enableRzpCheckout) {
		enableRzpCheckout.onclick = function(e) {
			var current_val = enableRzpCheckout.checked;
			if (current_val) {
				var message = 'Do you want to activate Magic Checkout?'
			} else {
				var message = 'Are you sure you want to deactivate Magic Checkout?'
			}
			if (!confirm(message)) {
				if (current_val) {
					enableRzpCheckout.checked = false;
				} else {
					enableRzpCheckout.checked = true;
				}
			}
		}
	}

	//instrumentation
	var rzpAdminElements = [
		'woocommerce_razorpay_key_id',
		'woocommerce_razorpay_key_secret',
		'woocommerce_razorpay_route_enable'
	];

	if (enableRzpCheckout) {
		rzpAdminElements.push('woocommerce_razorpay_enable_1cc');
	}

	var sensitive_fields = [
		'woocommerce_razorpay_key_id',
		'woocommerce_razorpay_key_secret'
	];

	rzpAdminElements.forEach(registerFocusOutEvent);

	function registerFocusOutEvent(item, index)
	{
		var rzpElement = document.getElementById(item);

		var data = {
			'action' : 'rzpInstrumentation',
			'event' : 'formfield.interacted',
			'properties' : {
				'page_url' : window.location.href,
				'field_type' : rzpElement.type,
				'field_name' : item
			}
		};

		if (!sensitive_fields.includes(item)) {
			data['properties']['field_value'] = rzpElement.value;
		}

		rzpElement.onfocusout = function(e)
		{
			rzpAjaxCall(data);
		}
	}
}

function rzpAjaxCall(data) {
	jQuery.ajax({
		url : ajaxurl, // this will point to admin-ajax.php
		type : 'POST',
		data : data,
		success : function (response) {}
	});
}

function rzpSignupClicked(e) {
	var data = {
		'action' : 'rzpInstrumentation',
		'event' : 'signup.initiated',
		'properties' : {
				'next_page_url' : 'https://easy.razorpay.com/onboarding/?recommended_product=payment_gateway&source=woocommerce'
			}
	};

	rzpAjaxCall(data);
}

function rzpLoginClicked(e) {
	var data = {
		'action' : 'rzpInstrumentation',
		'event' : 'login.initiated',
		'properties' : {
			'next_page_url' : 'https://dashboard.razorpay.com/signin?screen=sign_in&source=woocommerce'
		}
	};

	rzpAjaxCall(data);
}


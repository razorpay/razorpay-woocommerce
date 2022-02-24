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
}

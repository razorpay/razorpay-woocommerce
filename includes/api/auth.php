<?php

/**
 * custom auth to secure 1cc APIs
 */

require_once __DIR__ . '/../../razorpay-sdk/Razorpay.php';
use Razorpay\Api\Errors;

function checkAuthCredentials()
{
    return true;
}

/**
 * Validate HMAC signature using Razorpay Webhook Secret.
 * Expects header 'X-Razorpay-Signature' computed over raw request body with HMAC-SHA256.
 *
 * @param WP_REST_Request $request
 * @return bool|WP_Error
 */
function checkHmacSignature($request)
{
	$signature = '';
	if (isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'])) {
		$signature = sanitize_text_field($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']);
	}

	if (empty($signature)) {
		return new WP_Error('rest_forbidden', __('Signature missing'), array('status' => 403));
	}

    $payload = file_get_contents('php://input');

    // Retrieve webhook secret similar to webhook processing
    $secret = get_option('webhook_secret');

	if (empty($secret)) {
		return new WP_Error('rest_forbidden', __('Webhook secret not configured'), array('status' => 403));
	}

	// Verify using Razorpay SDK (same as webhook)
	try {
		$rzp = new WC_Razorpay(false);
		$api = $rzp->getRazorpayApiInstance();
		$api->utility->verifyWebhookSignature($payload, $signature, $secret);
	} catch (Errors\SignatureVerificationError $e) {
		return new WP_Error('rest_forbidden', __('Invalid signature'), array('status' => 403));
	}

	return true;
}

/**
 * Coupon list permission check:
 *  - Validate HMAC signature
 *  - Ensure order is in draft/checkout-draft state
 *
 * @param WP_REST_Request $request
 * @return bool|WP_Error
 */
function checkCouponListPermission($request)
{
	// HMAC validation
	$hmac = checkHmacSignature($request);
	if ($hmac instanceof WP_Error) {
		return $hmac;
	}

	$params  = $request->get_params();
	$orderId = isset($params['order_id']) ? sanitize_text_field($params['order_id']) : '';
	if (empty($orderId)) {
		return new WP_Error('rest_forbidden', __('Order id missing'), array('status' => 403));
	}

	$order = wc_get_order($orderId);
	if (!$order) {
		return new WP_Error('rest_forbidden', __('Invalid order id'), array('status' => 403));
	}

	$status = $order->get_status(); 
	if ($status === 'draft' || $status === 'checkout-draft') {
		return true;
	}

	return new WP_Error('rest_forbidden', __('Order not in draft state'), array('status' => 403));
}

?>

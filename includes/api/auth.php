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
	if (isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']))
	{
		$signature = sanitize_text_field($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']);
	}

	if (empty($signature))
	{
		rzpLogError('1cc_hmac_auth_failed: SIGNATURE_MISSING');
		return new WP_Error('rest_forbidden', __('Signature missing'), array('status' => 403));
	}

	// Prefer the body WordPress already parsed; fall back to the raw stream.
	// Both are the same bytes in production, but get_body() is populated under
	// PHPUnit where php://input is empty, which makes this function testable.
	$payload = '';
	if ($request instanceof WP_REST_Request)
	{
		$payload = $request->get_body();
	}
	if (empty($payload))
	{
		$payload = file_get_contents('php://input');
	}

	// Retrieve 1CC signing HMAC secret saved at plugin load time
	$secret = get_option('rzp1cc_hmac_secret');

	if (empty($secret))
	{
		rzpLogError('1cc_hmac_auth_failed: SECRET_NOT_CONFIGURED');
		return new WP_Error('rest_forbidden', __('Secret not configured'), array('status' => 403));
	}

	// Verify using Razorpay SDK (same as webhook)
	try
	{
		$rzp = new WC_Razorpay(false);
		$api = $rzp->getRazorpayApiInstance();
		$api->utility->verifySignature($payload, $signature, $secret);
	}
	catch (Errors\SignatureVerificationError $e)
	{
		rzpLogError('1cc_hmac_auth_failed: SIGNATURE_INVALID');
		return new WP_Error('rest_forbidden', __('Invalid signature'), array('status' => 403));
	}

	return true;
}

/**
 * Bind a 1CC request to its order: $rzpOrderId must name the Razorpay order stored on WC order $orderId.
 * Without this, a caller can name any order id (Patchstack PSID 1b9300d255b0, WPScan #45854).
 *
 * @param int    $orderId
 * @param string $rzpOrderId
 * @return string|WP_Error the stored Razorpay order id, to be used downstream instead of the caller's
 */
function rzp1ccGetVerifiedRazorpayOrderId($orderId, $rzpOrderId)
{
	$order = wc_get_order($orderId);
	if (!$order)
	{
		return new WP_Error('invalid_order', 'Invalid order');
	}

	$sessionKey = ($order->get_meta('is_magic_checkout_order') === 'yes') ? 'razorpay_order_id_1cc' : 'razorpay_order_id';
	$storedRazorpayOrderId = $order->get_meta($sessionKey . $orderId);

	if (empty($storedRazorpayOrderId))
	{
		return new WP_Error('razorpay_order_not_found', 'Razorpay order not found for this order');
	}

	// Magic Checkout sends the id without the "order_" prefix while createOrGetRazorpayOrderId()
	// stores it with one; both name the same order, and a different order fails in either spelling.
	if (preg_replace('/^order_/', '', $storedRazorpayOrderId) !== preg_replace('/^order_/', '', $rzpOrderId))
	{
		return new WP_Error('razorpay_order_id_mismatch', 'Razorpay order id mismatch');
	}

	return $storedRazorpayOrderId;
}

?>

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

?>

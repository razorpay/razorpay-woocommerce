<?php

/**
 * custom auth to secure 1cc APIs
 */

function checkAuthCredentials($request = null)
{
    if ($request instanceof WP_REST_Request)
    {
        $nonce = $request->get_header('X-WP-Nonce');

        if (empty($nonce) === false && wp_verify_nonce($nonce, 'wp_rest') !== false)
        {
            return true;
        }

        $hmacResult = checkHmacSignature($request);
        if ($hmacResult === true)
        {
            return true;
        }
    }

    return new WP_Error('rest_forbidden', __('Authentication failed'), array('status' => 403));
}

function checkRazorpayHmacCredentials($request)
{
    return checkHmacSignature($request);
}

function rzp1ccServerErrorResponse($logMessage = '')
{
    if (empty($logMessage) === false && function_exists('rzpLogError') === true)
    {
        rzpLogError($logMessage);
    }

    return new WP_REST_Response(
        [
            'message' => 'Something went wrong, please try again after sometime.',
            'code'    => 'WOOCOMMERCE_SERVER_ERROR',
        ],
        500
    );
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
    $signature = ($request instanceof WP_REST_Request) ? $request->get_header('X-Razorpay-Signature') : '';
    if (empty($signature) === true && isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']))
    {
        $signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'];
    }

    $signature = sanitize_text_field($signature);

    if (empty($signature))
    {
        return new WP_Error('rest_forbidden', __('Signature missing'), array('status' => 403));
    }

    $payload = ($request instanceof WP_REST_Request) ? $request->get_body() : file_get_contents('php://input');

    if ($payload === false)
    {
        $payload = '';
    }

    // Retrieve 1CC signing HMAC secret saved at plugin load time
    $secret = get_option('rzp1cc_hmac_secret');

    if (empty($secret))
    {
        return new WP_Error('rest_forbidden', __('Secret not configured'), array('status' => 403));
    }

    $expectedSignature = hash_hmac('sha256', $payload, $secret);

    if (hash_equals($expectedSignature, $signature) === false)
    {
        return new WP_Error('rest_forbidden', __('Invalid signature'), array('status' => 403));
    }

    return true;
}

?>

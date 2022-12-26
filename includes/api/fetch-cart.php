<?php

/**
 * Fetch cart details 
 */
function fetchCartData(WP_REST_Request $request)
{
    rzpLogInfo("fetchCartData");
    global $woocommerce;
    $params           = $request->get_params();
    $logObj           = array();
    $logObj['api']    = 'fetchCartData';
    $logObj['params'] = $params;

    //Abandoment cart plugin decode the coupon code from token
    $couponCode = null;
    if (isset($params['token'])) {
        $token = sanitize_text_field($params['token']);
        parse_str(base64_decode(urldecode($token)), $token);
        if (is_array($token) && array_key_exists('wcf_session_id', $token) && isset($token['wcf_coupon_code'])) {
            $couponCode = $token['wcf_coupon_code'];
        }
    }

    intiCartCommon();

    // check if cart is empty
    checkCartEmpty($logObj);

    // Get coupon if already added on cart.
    $coupons = WC()->cart->get_applied_coupons();
    if (!empty($coupons)) {
        $couponCode = $coupons[0];
    }

    //Get Cart line Item
    $data = getCartLineItem();
    $cartTotal = WC()->cart->total;

    $response['line_items'] = $data;
    $response['promotions'] = $couponCode;
    $response['total_amount'] = $cartTotal*100;

    return new WP_REST_Response($response, 200);
}
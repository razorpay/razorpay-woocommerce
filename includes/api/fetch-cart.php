<?php

/**
 * Fetch cart details 
 */
function fetchCartData(WP_REST_Request $request)
{
    rzpLogInfo("fetchCartData");
    global $woocommerce;
    $params           = $request->get_params();
    $logObj           = ['api' => 'fetchCartData', 'params' => $params];

    //Abandoment cart plugin decode the coupon code from token
    $couponCode = null;
    if (isset($params['token'])) {
        $token = sanitize_text_field($params['token']);
        parse_str(base64_decode(urldecode($token)), $token);
        if (is_array($token) && array_key_exists('wcf_session_id', $token) && isset($token['wcf_coupon_code'])) {
            $couponCode = $token['wcf_coupon_code'];
        }
    }

    initCartCommon();

    // check if cart is empty
    checkCartEmpty($logObj);

    // Get coupon if already added on cart.
    $couponCode = null;
    $coupons = WC()->cart->get_applied_coupons();
    if (!empty($coupons)) {
        $couponCode = $coupons[0];
    }

    //Get Cart line Item
    $data = getCartLineItem();
    $cartTotal = WC()->cart->subtotal;
    $response['cart'] = ['line_items' => $data, 'promotions' => $couponCode,  'total_price' => $cartTotal*100];

    $razorpay = new WC_Razorpay(false);
    $response["_"] = $razorpay->getVersionMetaInfo($response);

    $prefillData = getPrefillCartData($couponCode);
    $response['prefill'] = $prefillData;

    $response['enable_ga_analytics'] = get_option('woocommerce_razorpay_settings')['enable_1cc_ga_analytics'] === 'yes' ? true : false;
    $response['enable_fb_analytics'] = get_option('woocommerce_razorpay_settings')['enable_1cc_fb_analytics'] === 'yes' ? true : false;
    $response['redirect']            = true;
    $response['one_click_checkout']  = true;
    $response['mandatory_login'] = false;
    $response['key']  =  get_option('woocommerce_razorpay_settings')['key_id'];
    $response['name']  = html_entity_decode(get_bloginfo('name'), ENT_QUOTES);
    $response['currency'] = 'INR';

    return new WP_REST_Response($response, 200);
}
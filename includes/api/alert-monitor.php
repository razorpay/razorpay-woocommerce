<?php

/**
 * wooc alert and monitoring API
 */

function monitorAlertingData(WP_REST_Request $request)
{
    global $woocommerce;
    global $wpdb; 

    //$params = $request->get_params();
    $status = 400;

    //dummy data for testing
    $configData = ["enable_1cc" => "yes" , "enable_1cc_pdp_checkout" => "yes", "enable_1cc_mini_cart_checkout" => "yes"];  

    $validateInput = validateAlertConfigApi($params);

    $logObj           = [];
    $logObj["api"]    = "validateAlertConfigApi";
    //$logObj["params"] = $params; 

    if ($validateInput != null) {

        $res['response']["failure_reason"] = $validateInput;
        $res['response']["failure_code"] = "VALIDATION_ERROR";
        $res['status_code'] =  $status;

        $logObj["response"]         = $result;
        rzpLogError(json_encode($logObj));

        return new WP_REST_Response($res, $status);
    }

    $rzpSettingData = [];
    
    $rzpSettingData = get_option('woocommerce_razorpay_settings');

    //update the Buy Now button config
    $rzpSettingData['enable_1cc_pdp_checkout'] = $configData['enable_1cc_pdp_checkout'];

    //update the mini cart button config
    $rzpSettingData['enable_1cc_mini_cart_checkout'] = $configData['enable_1cc_mini_cart_checkout'];

    //update the magic checkout config
    $rzpSettingData['enable_1cc'] = $configData['enable_1cc'];

    update_option('woocommerce_razorpay_settings', $rzpSettingData);

    return new WP_REST_Response('success', 200);

}


function validateAlertConfigApi($param)
{
    $failureReason = null;
    if (empty(sanitize_text_field($param["enable_1cc"])) === true) {
        $failureReason = "1cc config is required.";
    } elseif (empty(sanitize_text_field($param["enable_1cc_pdp_checkout"])) === true) {
        $failureReason = "1cc pdp checkout is required.";
    }elseif (empty(sanitize_text_field($param["enable_1cc_mini_cart_checkout"])) === true) {
        $failureReason = "1cc mini cart checkout.";
    }

    return $failureReason;
}



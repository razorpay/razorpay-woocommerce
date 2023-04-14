<?php

/**
 * wooc alert and monitoring API
 */

function update1ccConfig(WP_REST_Request $request)
{
    global $woocommerce;
    global $wpdb; 

    rzpLogInfo("magic config start");

    $params = $request->get_params();
    $status = 400;

    rzpLogInfo("config param :" . json_encode($params));

    $validateInput = validateAlertConfigApi($params);

    $logObj           = [];
    $logObj["api"]    = "validateAlertConfigApi";

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
    if(isset($params['buyNowEnabled'])){
        $rzpSettingData['enable_1cc_pdp_checkout'] = $params['buyNowEnabled'] === 'true' ? 'yes' : 'no';
    }
    
    
    //update the mini cart button config
     if(isset($params['miniCartEnabled'])){
         $rzpSettingData['enable_1cc_mini_cart_checkout'] = $params['miniCartEnabled'] === 'true' ? 'yes' : 'no';
     }
    

    //update the magic checkout config
    if(isset($params['oneClickCheckoutEnabled'])){
        $rzpSettingData['enable_1cc'] = $params['oneClickCheckoutEnabled'] === 'true' ? 'yes' : 'no';
     }

    update_option('woocommerce_razorpay_settings', $rzpSettingData);

    return new WP_REST_Response('success', 200);

}


function validateAlertConfigApi($param)
{
    $failureReason = null;

    if( $param["oneClickCheckoutEnabled"] == null && $param["buyNowEnabled"] == null && $param["miniCartEnabled"]== null){
        $failureReason = "Config is required";
    }

    return $failureReason;
}



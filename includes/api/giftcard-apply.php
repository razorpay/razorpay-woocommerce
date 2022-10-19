<?php

/**
 * for coupon related API
 */

function validateGiftCardData(WP_REST_Request $request)
{
    global $woocommerce;
    global $wpdb;    

    $status         = 400;
    $giftCard =array();

    $params = $request->get_params();

    $validateInput = validateApplyGiftCardApi($params);

    $logObj           = [];
    $logObj["api"]    = "validateApplyGiftCardApi";
    $logObj["params"] = $params;

    //initializes the session
    initCustomerSessionAndCart();

    if ($validateInput != null) {

        $response["failure_reason"] = $validateInput;
        $response["failure_code"]   = "VALIDATION_ERROR";
        $giftCardRes['response'] = $response;
        $giftCardRes['status_code'] =  $status;

        $logObj["response"]         = $response;

        rzpLogError(json_encode($logObj));

        return new WP_REST_Response($giftCardRes, 400);
    }

    $giftCardNumber = sanitize_text_field($params['gift_card_number']);


    //Yith gift card plugin 
    if(is_plugin_active('yith-woocommerce-gift-cards/init.php'))
    {
        $yithCard = new YITH_YWGC_Gift_Card( $args = array('gift_card_number'=> $giftCardNumber));

        $giftCardBalance = $yithCard->get_balance();

        if ( $giftCardBalance <= 0 ) {
            $response = getApplyGiftCardErrors('ZERO_BALANCE');
            return new WP_REST_Response($response, 400);
        }else{
            $giftCardData['gift_card_number'] = $giftCardNumber;
            $giftCardData['balance']   = intval ($giftCardBalance) *100;
            $giftCardData['allowedPartialRedemption']   = 1;
            $logObj['response']         = $response;
            $logObj['status_code']      = 200;
            $giftCard['gift_card_promotion'] = $giftCardData;

            rzpLogError(json_encode($logObj));
            return new WP_REST_Response($giftCard, 200);
        }

    }

    //PW gift card plugin
    else if(is_plugin_active('pw-woocommerce-gift-cards/pw-gift-cards.php')){
        $result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->pimwick_gift_card}` WHERE `number` = %s and `active` =%s", $giftCardNumber, 1 ) );

        $giftCardData= array();

        if ( $result == null ) {
            $response = getApplyGiftCardErrors('INVALID_GIFTCODE');
            return new WP_REST_Response($response, $statusCode);
        
        }

       $balance = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$wpdb->pimwick_gift_card_activity} WHERE pimwick_gift_card_id = %d", $result->pimwick_gift_card_id ) );

       if($balance <= 0 ){
            $response = getApplyGiftCardErrors('ZERO_BALANCE');
            return new WP_REST_Response($response, $statusCode);
       }

        $giftCardData['gift_card_number'] = $giftCardNumber;
        $giftCardData['balance']   = intval ($balance) *100;
        $giftCardData['allowedPartialRedemption']   = 1;
        $logObj['response']         = $response;
        $logObj['status_code']      = 200;
        $giftCard['gift_card_promotion'] = $giftCardData;
        rzpLogError(json_encode($logObj));
        return new WP_REST_Response($giftCard, 200);
       
    }else{
        $response = getApplyGiftCardErrors('INVALID_GIFTCODE');
        return new WP_REST_Response($response, 400);
    }

}

function validateApplyGiftCardApi($param)
{
    if (empty(sanitize_text_field($param["gift_card_number"])) === true) {
        $failureReason = "Gift code is required.";
    } 
    return $failureReason;
}

function getApplyGiftCardErrors($errCode)
{
    $statusCode  = 400;
    $error =[];
    if($errCode == 'ZERO_BALANCE'){
        $error['failure_code'] = 'ZERO_BALANCE';
        $error['failure_reason']   = 'This gift card has a zero balance..';
    }elseif($errCode == 'INVALID_GIFTCODE'){
        $error['failure_code'] = 'INVALID_GIFTCODE';
        $error['failure_reason']   = "Card number does not exist";
    }else
    {
        $error['failure_code'] = 'INVALID_GIFTCODE';
        $error['failure_reason']   = "Card number does not exist";
    }

    $logObj['response']         = $response;
    $logObj['status_code']      = $statusCode;
     rzpLogError(json_encode($logObj));

     return $error;

}

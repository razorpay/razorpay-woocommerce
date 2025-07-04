<?php

/**
 * for coupon related API
 */

function validateGiftCardData(WP_REST_Request $request)
{
    global $woocommerce;
    global $wpdb;    

    $status = 400;
    $giftCard = [];
    $giftCardData= [];

    $params = $request->get_params();

    $validateInput = validateApplyGiftCardApi($params);

    $logObj           = [];
    $logObj["api"]    = "validateApplyGiftCardApi";
    $logObj["params"] = $params;

    //initializes the session
    initCustomerSessionAndCart();

    if ($validateInput != null) {

        $giftCardRes['response']["failure_reason"] = $validateInput;
        $giftCardRes['response']["failure_code"] = "VALIDATION_ERROR";
        $giftCardRes['status_code'] =  $status;

        $logObj["response"]         = $response;
        rzpLogError(json_encode($logObj));

        return new WP_REST_Response($giftCardRes, $status);
    }

    $giftCardNumber = sanitize_text_field($params['gift_card_number']);

    //Yith gift card plugin 
    if(is_plugin_active('yith-woocommerce-gift-cards/init.php'))
    {
        $yithArgs = ['gift_card_number'=> $giftCardNumber];

        $yithCard = new YITH_YWGC_Gift_Card($yithArgs);

        // check post status
        $post  = get_post($yithCard->ID);
        if ('trash' == $post->post_status ) {
            $response = getApplyGiftCardErrors('INVALID_GIFTCODE');
            return new WP_REST_Response($response, $status);
        }

        $giftCardBalance = $yithCard->get_balance();

        if(!$yithCard->exists()){
            $response = getApplyGiftCardErrors('INVALID_GIFTCODE');
            return new WP_REST_Response($response, $status);

        }elseif($giftCardBalance <= 0) {
            $response = getApplyGiftCardErrors('ZERO_BALANCE');
            return new WP_REST_Response($response, $status);
        }else{
            $giftCardData['gift_card_number'] = $giftCardNumber;
            $giftCardData['balance']   = floatval($giftCardBalance) *100;
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

        if ( $result == null ) {
            $response = getApplyGiftCardErrors('INVALID_GIFTCODE');
            return new WP_REST_Response($response, $status);
        
        }

       $balance = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$wpdb->pimwick_gift_card_activity} WHERE pimwick_gift_card_id = %d", $result->pimwick_gift_card_id ) );

       if($balance <= 0 ){
            $response = getApplyGiftCardErrors('ZERO_BALANCE');
            return new WP_REST_Response($response, $status);
       }

        $giftCardData['gift_card_number'] = $giftCardNumber;
        $giftCardData['balance']   = floatval ($balance) *100;
        // 1 for Gift card allowed Partial Redemption
        $giftCardData['allowedPartialRedemption']   = 1;
        $giftCard['gift_card_promotion'] = $giftCardData;

        $logObj['response']         = $response;
        $logObj['status_code']      = 200;
        
        rzpLogError(json_encode($logObj));
        return new WP_REST_Response($giftCard, 200);
       
    }else{
        $response = getApplyGiftCardErrors('INVALID_GIFTCODE');
        return new WP_REST_Response($response, $status);
    }

}

function validateApplyGiftCardApi($param)
{
    $failureReason = null;
    if (empty(sanitize_text_field($param["gift_card_number"])) === true) {
        $failureReason = "Gift code is required.";
    } 
    return $failureReason;
}

function getApplyGiftCardErrors($errCode)
{
    $statusCode  = 400;
    $error =[];

    switch ($errCode)
    {
        case "ZERO_BALANCE" :
            $error['failure_code'] = 'ZERO_BALANCE';
            $error['failure_reason']   = 'This gift card has a zero balance..';
            break;

        case "INVALID_GIFTCODE" :
            $error['failure_code'] = 'INVALID_GIFTCODE';
            $error['failure_reason']   = 'Card number does not exist.';
            break;

        default :
            $error['failure_code'] = 'INVALID_GIFTCODE';
            $error['failure_reason']   = "Card number does not exist";
            break;
    }

    $logObj['response']         = $response;
    $logObj['status_code']      = $statusCode;
    rzpLogError(json_encode($logObj));

    return $error;

}

<?php

/**
 * create cart 
 */
function createCartData(WP_REST_Request $request)
{
    rzpLogInfo("fetchCartData");
    global $woocommerce;
    $params           = $request->get_params();
    $logObj           = array();
    $logObj['api']    = 'createCartData';
    $logObj['params'] = $params;

    $couponCode = null;

    intiCartCommon();

    if (empty($params['pdpCheckout']) === false) {
        $variations = [];
        // Cleanup cart.
        WC()->cart->empty_cart();

        $variation_id = (empty($params['variationId']) === false) ? (int) $params['variationId'] : 0;

        if (empty($params['variations']) === false) {
            $variations_arr = json_decode($params['variations'], true);

            foreach ($variations_arr as $key => $value) {
                $var_key          = explode('_', $key);
                $variations_key[] = ucwords(end($var_key));
                $variations_val[] = ucwords($value);
            }

            $variations = array_combine($variations_key, $variations_val);
        }

        //To add custom fields to buy now orders
        if (empty($params['fieldObj']) === false) {
            foreach ($params['fieldObj'] as $key => $value) {
                if (!empty($value)) {
                    $variations[$key] = $value;
                }
            }
        }

        WC()->cart->add_to_cart($params['productId'], $params['quantity'], $variation_id, $variations);
    }

    // check if cart is empty
    checkCartEmpty($logObj);

    //Get Cart line Item
    $data = getCartLineItem();
    $cartTotal = WC()->cart->total;

    $response['line_items'] = $data;
    $response['promotions'] = $couponCode;
    $response['total_amount'] = $cartTotal*100;

    return new WP_REST_Response($response, 200);
}
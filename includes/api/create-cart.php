<?php

/**
 * create cart 
 */
function createCartData(WP_REST_Request $request)
{
    rzpLogInfo("createCartData");
    global $woocommerce;
    $params           = $request->get_params();
    $logObj           = ['api' => 'createCartData', 'params' => $params];

    $couponCode = null;

    if (empty($params['pdpCheckout']) === false) {
        $variations = [];
        // Cleanup cart.
        WC()->cart->empty_cart();

        $variationId = (empty($params['variationId']) === false) ? (int) $params['variationId'] : 0;

        if (empty($params['variations']) === false) {
            $variationsArr = json_decode($params['variations'], true);

            foreach ($variationsArr as $key => $value) {
                $varKey          = explode('_', $key);
                $variationsKey[] = ucwords(end($varKey));
                $variationsVal[] = ucwords($value);
            }

            $variations = array_combine($variationsKey, $variationsVal);
        }

        //To add custom fields to buy now orders
        if (empty($params['fieldObj']) === false) {
            foreach ($params['fieldObj'] as $key => $value) {
                if (!empty($value)) {
                    $variations[$key] = $value;
                }
            }
        }

        WC()->cart->add_to_cart($params['productId'], $params['quantity'], $variationId, $variations);
    }

    initCartCommon();

    // check if cart is empty
    checkCartEmpty($logObj);

    //Get Cart line Item
    $data = getCartLineItem();
    $cartTotal = WC()->cart->total - WC()->cart->get_shipping_total();

    $response = ['line_items' => $data, 'promotions' => $couponCode,  'total_price' => $cartTotal*100];

    return new WP_REST_Response($response, 200);
}
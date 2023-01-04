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
    $cartTotal = WC()->cart->subtotal;
    $response['cart'] = ['line_items' => $data, 'promotions' => $couponCode,  'total_price' => $cartTotal*100];

    $razorpay = new WC_Razorpay(false);
    $response["_"] = $razorpay->getVersionMetaInfo($response);
    
    $prefillData = getPrefillCartData();
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
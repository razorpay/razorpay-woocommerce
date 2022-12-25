<?php

/**
 * create order with status pending
 * user, adddress, coupon and shipping are left blank
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

    initCustomerSessionAndCart();

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
    if (WC()->cart->get_cart_contents_count() == 0) {
        $response['message'] = 'Cart cannot be empty';
        $response['code']    = 'BAD_REQUEST_EMPTY_CART';

        $statusCode            = 400;
        $logObj['status_code'] = $statusCode;
        $logObj['response']    = $response;
        rzpLogError(json_encode($logObj));

        return new WP_REST_Response($response, $statusCode);
    }


    $cart = WC()->cart->get_cart();
    $i = 0;

    foreach($cart as $item_id => $item) { 
        $product =  wc_get_product( $item['data']->get_id()); 
        $price = get_post_meta($values['product_id'] , '_price', true);


       $data[$i]['type'] = "e-commerce";
       $data[$i]['sku'] = $product->get_sku();
       $data[$i]['quantity'] = $item['quantity'];
       $data[$i]['name'] = mb_substr($product->get_title(), 0, 125, "UTF-8");
       $data[$i]['description'] = mb_substr($product->get_title(), 0, 250,"UTF-8");
       $productImage = $product->get_image_id()?? null;
       $data[$i]['image_url'] = $productImage? wp_get_attachment_url( $productImage ) : null;
       $data[$i]['product_url'] = $product->get_permalink();
       $data[$i]['price'] = $product->get_price();

       $i++;
    } 

    $response['line_items'] = $data;
    $response['promotions'] = $couponCode;
    $response['total_amount'] = WC()->cart->total;

     return new WP_REST_Response($response, 200);
}
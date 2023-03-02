<?php
/**
 * For cart related functionality
 */

// Fetch cart data on cart and mini cart page

function fetchCartData(WP_REST_Request $request)
{
    rzpLogInfo("fetchCartData");
    global $woocommerce;

    $params = $request->get_params();
    $logObj = ['api' => 'fetchCartData', 'params' => $params];

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

    $response = cartResponse($couponCode);

    return new WP_REST_Response($response, 200);
}

// create cart data on product page

function createCartData(WP_REST_Request $request)
{
    rzpLogInfo("createCartData");
    global $woocommerce;
    $params = $request->get_params();
    $logObj = ['api' => 'createCartData', 'params' => $params];

    $couponCode = null;
    initCartCommon();

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

    // check if cart is empty
    checkCartEmpty($logObj);

    $response = cartResponse($couponCode);

    return new WP_REST_Response($response, 200);
}

/**
 * Create the cart object for the line items exist in order
 */
function create1ccCart($orderId)
{
    global $woocommerce;

    $order = wc_get_order($orderId);

    $variationAttributes = [];
    if ($order && $order->get_item_count() > 0) {
        foreach ($order->get_items() as $item_id => $item) {
            $productId   = $item->get_product_id();
            $variationId = $item->get_variation_id();
            $quantity    = $item->get_quantity();

            $customData['item_id'] = $item_id;
            $product               = $item->get_product();
            if ($product->is_type('variation')) {
                $variation_attributes = $product->get_variation_attributes();
                foreach ($variation_attributes as $attribute_taxonomy => $term_slug) {
                    $taxonomy                                 = str_replace('attribute_', '', $attribute_taxonomy);
                    $value                                    = wc_get_order_item_meta($item_id, $taxonomy, true);
                    $variationAttributes[$attribute_taxonomy] = $value;
                }
            }

            $woocommerce->cart->add_to_cart($productId, $quantity, $variationId, $variationAttributes, $customData);
        }

        return true;
    } else {
        return false;
    }
}

function getCartLineItem()
{
    $cart = WC()->cart->get_cart();
    $i = 0;

    foreach($cart as $item_id => $item) { 
        $product =  wc_get_product( $item['product_id']); 
        $price = round($item['line_subtotal']*100) + round($item['line_subtotal_tax']*100);

        $type = "e-commerce";
        $productDetails = $product->get_data();

       // check product type for gift card plugin
       if(is_plugin_active('pw-woocommerce-gift-cards/pw-gift-cards.php') || is_plugin_active('yith-woocommerce-gift-cards/init.php')){
           if($product->is_type('variation')){
                $parentProductId = $product->get_parent_id();
                $parentProduct = wc_get_product($parentProductId);
             
                if($parentProduct->get_type() == 'pw-gift-card' || $parentProduct->get_type() == 'gift-card'){
                    $type = 'gift_card';
                }

           }else{

               if($product->get_type() == 'pw-gift-card' || $product->get_type() == 'gift-card'){
                      $type = 'gift_card'; 
               }
           }
       }

       $data[$i]['type'] = $type;
       $data[$i]['sku'] = $product->get_sku();
       $data[$i]['quantity'] = $item['quantity'];
       $data[$i]['name'] = mb_substr($product->get_title(), 0, 125, "UTF-8");
       $data[$i]['description'] = mb_substr($product->get_title(), 0, 250,"UTF-8");
       $productImage = $product->get_image_id()?? null;
       $data[$i]['image_url'] = $productImage? wp_get_attachment_url( $productImage ) : null;
       $data[$i]['product_url'] = $product->get_permalink();
       $data[$i]['price'] = (empty($product->get_price())=== false) ? $price/$item['quantity'] : 0;
       $data[$i]['variant_id'] = $item['variation_id'];
       $data[$i]['offer_price'] = (empty($productDetails['sale_price'])=== false) ? (int) $productDetails['sale_price']*100 : $price/$item['quantity'];
       $i++;
    } 

    return $data;
}

function getPrefillCartData($couponCode){

    $currentUser = wp_get_current_user();

    if ($currentUser instanceof WP_User) {
        $prefillData['email']   = $current_user->user_email ?? '';
        $contact = get_user_meta($current_user->ID, 'billing_phone', true);
        $prefillData['contact'] = $contact ?? '';
        $prefillData['coupon_code'] = $couponCode ?? '';
    }

    return $prefillData;
}

function checkCartEmpty($logObj){

    if (WC()->cart->get_cart_contents_count() == 0) {
        $response = ['message' => 'Cart cannot be empty', 'code' => 'BAD_REQUEST_EMPTY_CART'];

        $logObj['status_code'] = 400;
        $logObj['response'] = $response;

        rzpLogError(json_encode($logObj));

        return new WP_REST_Response($response, $statusCode);
    }
}

function cartResponse($couponCode){

    //Get Cart line Item
    $data = getCartLineItem();
    $cartTotal = WC()->cart->subtotal;
    $response['cart'] = ['line_items' => $data, 'promotions' => $couponCode,  'total_price' => round($cartTotal*100)];

    $razorpay = new WC_Razorpay(false);
    $response["_"] = $razorpay->getVersionMetaInfo();

    $prefillData = getPrefillCartData($couponCode);
    $response['prefill'] = $prefillData;

    $response['enable_ga_analytics'] = get_option('woocommerce_razorpay_settings')['enable_1cc_ga_analytics'] === 'yes' ? true : false;
    $response['enable_fb_analytics'] = get_option('woocommerce_razorpay_settings')['enable_1cc_fb_analytics'] === 'yes' ? true : false;
    
    $response += ['redirect' => true, 'one_click_checkout' => true, 'mandatory_login' => false, 'key' => get_option('woocommerce_razorpay_settings')['key_id'], 'name' => html_entity_decode(get_bloginfo('name'), ENT_QUOTES), 'currency' => 'INR'];

    return $response;
}


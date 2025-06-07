<?php

/**
 * create order with status pending
 * user, adddress, coupon and shipping are left blank
 */
use Automattic\WooCommerce\Utilities\OrderUtil;

function createWcOrder(WP_REST_Request $request)
{
    rzpLogInfo("createWcOrder");
    global $woocommerce;
    $params           = $request->get_params();
    $logObj           = array();
    $logObj['api']    = 'createWcOrder';
    $logObj['params'] = $params;

    // fetching wp_woocommerce_session_ from cookies
    $sessionVal = array_filter($params['cookies'], function($key) {
       return strpos($key, 'wp_woocommerce_session_') === 0;
    }, ARRAY_FILTER_USE_KEY);

    foreach($sessionVal as $key => $value){
        $expKey = explode('wp_woocommerce_session_', $key);
        $sessionResult = $expKey[1];
    }
    
    //Abandoment cart plugin decode the coupon code from token
    $couponCode = null;
    if (isset($params['token'])) {
        $token = sanitize_text_field($params['token']);
        parse_str(base64_decode(urldecode($token)), $token);
        if (is_array($token) && array_key_exists('wcf_session_id', $token) && isset($token['wcf_coupon_code'])) {
            $couponCode = $token['wcf_coupon_code'];
        }
    }

    $nonce     = $request->get_header('X-WP-Nonce');
    $verifyReq = wp_verify_nonce($nonce, 'wp_rest');

    if ($verifyReq === false) {
        $response['status']  = false;
        $response['message'] = 'Authentication failed';

        $statusCode            = 401;
        $logObj['status_code'] = $statusCode;
        $logObj['response']    = $response;
        rzpLogError(json_encode($logObj));

        return new WP_REST_Response($response, $statusCode);
    }

    initCartCommon();

    // check if cart is empty
    checkCartEmpty($logObj);

    $cartHash  = WC()->cart->get_cart_hash();
    $hash = $sessionResult."_".$cartHash;
    $orderIdFromHash = get_transient(RZP_1CC_CART_HASH . $hash);

    if (isHposEnabled()) {
        $updateOrderStatus = 'checkout-draft';
    }else{
        $updateOrderStatus = 'draft';
    }

    if ($orderIdFromHash == null) {
        $checkout = WC()->checkout();
        $orderId  = $checkout->create_order(array());

        if (is_wp_error($orderId)) {
            $checkout_error = $orderId->get_error_message();
        }
        //Keep order in draft status untill customer info available
        updateOrderStatus($orderId, $updateOrderStatus);
    } else {
        $existingOrder = wc_get_order($orderIdFromHash);
        $orderStatus   = $existingOrder->get_status();
        $existingOrder->calculate_totals();
        if ($orderStatus != $updateOrderStatus && $existingOrder->needs_payment() == false) {
            $woocommerce->session->__unset(RZP_1CC_CART_HASH . $cartHash);
            $checkout = WC()->checkout();
            $orderId  = $checkout->create_order(array());

            if (is_wp_error($orderId)) {
                $checkout_error = $orderId->get_error_message();
            }
            //Keep order in draft status untill customer info available
            updateOrderStatus($orderId, $updateOrderStatus);
        } else {
            $orderId = $orderIdFromHash;
            //To get the applied coupon details from cart object.
            $coupons    = WC()->cart->get_coupons();
            $couponCode = !empty($coupons) ? array_key_first($coupons) : null;
        }
    }

    $order = wc_get_order($orderId);
    
    if($order){

        $disableCouponFlag = false;

        // Woo dynamic discount price plugin
        if(is_plugin_active('yith-woocommerce-dynamic-pricing-and-discounts-premium/init.php')) {

            foreach ($order->get_items() as $itemId => $item) {
                $dynamicRules = $item->get_meta('_ywdpd_discounts');

                if(empty($dynamicRules) == false){
                    
                    foreach ($dynamicRules['applied_discounts'] as $appliedDiscount) {
                        if (isset( $appliedDiscount['set_id'])){
                            $ruleId = $appliedDiscount['set_id'];
                            $rule    = ywdpd_get_rule($ruleId);
                        } else {
                            $rule = $appliedDiscount['by'];
                        }
                        // check coupon is disable with discount price
                        if($rule->is_disabled_with_other_coupon() == 1){
                            $disableCouponFlag = true;
                        }
                    }
                }
            }
        }

        // Pixel your site PRO UTM data
        if (is_plugin_active('pixelyoursite-pro/pixelyoursite-pro.php') || is_plugin_active('pixelyoursite/facebook-pixel-master.php')) {
            rzpLogInfo("pixelyoursite-pro/pixelyoursite-pro.php is activated for order id-". $orderId);
            $pysData = get_option('pys_core');

            // Store UTM data only if config enabled.
            if ($pysData['woo_enabled_save_data_to_orders'] == true) {
                rzpLogInfo("woo_enabled_save_data_to_orders value is ".$pysData['woo_enabled_save_data_to_orders']);
                wooSaveCheckoutUTMFields($order, $params);
            }
        }

        // To remove coupon added on order.
        $coupons = $order->get_coupon_codes();
        if (!empty($coupons)) {
            foreach ($coupons as $coupon) {
                $order->remove_coupon($coupon);
            }
            $couponCode = $coupons[0];
        }

        //To remove by default shipping method added on order.
        $items = (array) $order->get_items('shipping');

        if (sizeof($items) > 0) {
            // Loop through shipping items
            foreach ($items as $item_id => $item) {
                $order->remove_item($item_id);
            }
        }

        $order->calculate_totals();

        if (isHposEnabled()) {
            $order->update_meta_data( 'is_magic_checkout_order', 'yes' );
            $order->save();
        }else{
            update_post_meta($orderId, 'is_magic_checkout_order', 'yes');
        }

        $minCartAmount1cc = !empty(get_option('woocommerce_razorpay_settings')['1cc_min_cart_amount']) ? get_option('woocommerce_razorpay_settings')['1cc_min_cart_amount'] : 0;

        // Response sent to the user when order creation fails
        if ($order->get_total() < $minCartAmount1cc) {
            $response['status']  = false;
            $response['message'] = 'Your current order total is ₹' . $order->get_total() . ' — you must have an order with a minimum of ₹' . $minCartAmount1cc . ' to place your order';
            $response['code']    = 'MIN_CART_AMOUNT_CHECK_FAILED';

            $status                 = 400;
            $logObj['response']     = $response;
            $logObj['rzp_order_id'] = $rzp_order_id;
            $logObj['rzp_response'] = $rzp_response;
            rzpLogError(json_encode($logObj));

            return new WP_REST_Response($response, $status);
        }

        $razorpay = new WC_Razorpay(false);

        $rzp_order_id = $razorpay->createOrGetRazorpayOrderId($order, $orderId, 'yes');
        $rzp_response = $razorpay->getDefaultCheckoutArguments($order);

        // Response sent to the user when order creation fails
        if (empty($rzp_response['order_id'])) {
            $response['status']  = false;
            $response['message'] = 'Unable to create order';
            $response['code']    = 'ORDER_CREATION_FAILED';

            $status                 = 400;
            $logObj['response']     = $response;
            $logObj['rzp_order_id'] = $rzp_order_id;
            $logObj['rzp_response'] = $rzp_response;
            rzpLogError(json_encode($logObj));

            return new WP_REST_Response($response, $status);
        }

        // TODO: getDefaultCheckoutArguments() is already being called in L65 above
        $response = $razorpay->getDefaultCheckoutArguments($order);

        $fbAnalytics = get_option('woocommerce_razorpay_settings')['enable_1cc_fb_analytics'] === 'yes' ? true : false;


        if($disableCouponFlag == true){
           $response['show_coupons']  = false;
        }

        if ($fbAnalytics === true) {
            //Customer cart related data for FB analytics.
            $customer_cart['value']        = (string) WC()->cart->subtotal;
            $customer_cart['content_type'] = 'product';
            $customer_cart['currency']     = 'INR';

            $x = 0;
            // Loop over $cart items
            foreach (WC()->cart->get_cart() as $cart_item) {

                $customer_cart['contents'][$x]['id']         = (string) $cart_item['product_id'];
                $customer_cart['contents'][$x]['name']       = $cart_item['data']->get_title();
                $customer_cart['contents'][$x]['quantity']   = (string) $cart_item['quantity'];
                $customer_cart['contents'][$x]['value']      = (string) ($cart_item['line_subtotal'] + $cart_item['line_subtotal_tax']) / $cart_item['quantity'];
                $customer_cart['contents'][$x]['variant_id'] = (string) $cart_item['variation_id'];

                $x++;
            }

            $response['customer_cart'] = $customer_cart ?? '';
        }

        $hash = $sessionResult."_".$cartHash;
        $woocommerce->session->set(RZP_1CC_CART_HASH . $hash, $orderId);
        set_transient(RZP_1CC_CART_HASH . $orderId, $hash, 14400);
        set_transient(RZP_1CC_CART_HASH . $hash, $orderId, 14400);
        set_transient($razorpay::SESSION_KEY, $orderId, 14400);

        $logObj['response'] = $response;
        rzpLogInfo(json_encode($logObj));

        return new WP_REST_Response($response, 200);
    } else {
        $response['status']  = false;
        $response['message'] = $checkout_error;
        $response['code']    = 'WOOCOMMERCE_ORDER_CREATION_FAILED';

        $logObj['response']    = $response;
        $logObj['status_code'] = 400;
        rzpLogError(json_encode($logObj));

        return new WP_REST_Response($response, 400);
    }
}

//Update order status according to the steps.
function updateOrderStatus($orderId, $orderStatus)
{
    $order = wc_get_order( $orderId );
    if (isHposEnabled()) {
        $order->update_status($orderStatus);
        $order->save();
    }else{
        wp_update_post(array(
            'ID'          => $orderId,
            'post_status' => $orderStatus,
         ));
    }
    
}

function wooSaveCheckoutUTMFields($order, $params)
{
	if (!$order) return;
    $getQuery               = $params['requestData'];
    $browserTime            = $params['dateTime'];
	rzpLogInfo('Razorpay Log: sbjs_current - ' . json_encode($_COOKIE['sbjs_current']));
    $data = [];

    // Get data from Sourcebuster.js (sbjs) cookies
    if (!empty($_COOKIE['sbjs_current'])) {
        parse_str(str_replace('|', '&', $_COOKIE['sbjs_current']), $sbjs_current);
        $data['source_type'] = $sbjs_current['typ'] ?? 'direct';
        $data['utm_source']  = $sbjs_current['src'] ?? '(direct)';
        $data['utm_medium']  = $sbjs_current['mdm'] ?? '(none)';
        $data['utm_campaign'] = $sbjs_current['cmp'] ?? '(none)';
        $data['referrer'] = $sbjs_current['rf'] ?? '';
    }

    // Get data from WooCommerce Order Attribution Script (wc_order_attribution)
    if (!empty($_COOKIE['wc_order_attribution'])) {
        $wc_order_attribution = json_decode(stripslashes($_COOKIE['wc_order_attribution']), true);
        if (!empty($wc_order_attribution['fields'])) {
            foreach ($wc_order_attribution['fields'] as $key => $cookie_key) {
                if (isset($_COOKIE[$cookie_key])) {
                    $data[$key] = $_COOKIE[$cookie_key];
                }
            }
        }
    }

    // Capture referrer from HTTP headers (fallback)
    if (empty($data['referrer']) && !empty($_SERVER['HTTP_REFERER'])) {
        $data['referrer'] = $_SERVER['HTTP_REFERER'];
    }

    // Capture user agent
    $data['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
	rzpLogInfo('Store Cookie Data  - ' . json_encode($data));

    //Save each key-value pair as order meta (WooCommerce format)
    foreach ($data as $key => $value) {
        $order->update_meta_data('_wc_order_attribution_' . $key, $value);
    }
	$order->save();

}

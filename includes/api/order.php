<?php

/**
 * create order with status pending
 * user, adddress, coupon and shipping are left blank
 */
use Automattic\WooCommerce\Utilities\OrderUtil;

function createWcOrder(WP_REST_Request $request)
{
    try
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
        //Setting the $orderIdFromHash to null, to create a fresh RZP order for each checkout initialisation.
        //In future if we need to revert back to earlier flow then consider it from transient as mentioned below.
        // $orderIdFromHash = get_transient(RZP_1CC_CART_HASH . $hash);
        $orderIdFromHash = null;

        if (isHposEnabled()) {
          $updateOrderStatus = 'checkout-draft';
        } else {
            // Check if WooCommerce supports the "checkout-draft" status (added in newer versions).
            $postStatus = get_post_status_object('wc-checkout-draft');
            if ($postStatus) {
                $updateOrderStatus = 'checkout-draft'; 
            } else {
                $updateOrderStatus = 'draft'; // Older WooCommerce versions fallback
            }
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
            if (is_plugin_active('pixelyoursite-pro/pixelyoursite-pro.php')) {

                $pysData = get_option('pys_core');

                // Store UTM data only if config enabled.
                if ($pysData['woo_enabled_save_data_to_orders'] == true) {
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

                $trackObject = $razorpay->newTrackPluginInstrumentation();
                $properties = [
                    'error' => 'Unable to create order',
                    'log'   => $logObj
                ];
                $trackObject->rzpTrackDataLake('razorpay.1cc.create.order.failed', $properties);

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

            $rzp = new WC_Razorpay();
            $trackObject = $rzp->newTrackPluginInstrumentation();
            $properties = [
                'error' => $checkout_error,
                'log'   => $logObj
            ];
            $trackObject->rzpTrackDataLake('razorpay.1cc.create.woocommerce.order.failed', $properties);

            return new WP_REST_Response($response, 400);
        }
    }
    catch (Throwable $e)
    {
        $rzp = new WC_Razorpay();
        $trackObject = $rzp->newTrackPluginInstrumentation();
        $properties = [
            'error' => $e->getMessage(),
            'code'  => $e->getCode(),
            'file'  => $e->getFile(),
            'line'  => $e->getLine()
        ];
        $trackObject->rzpTrackDataLake('razorpay.1cc.create.order.processing.failed', $properties);
        rzpLogError(json_encode($properties));

        return new WP_REST_Response(['message' => "woocommerce server error : " . $e->getMessage()], 500);
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
        // Handling order status update for WooCommerce versions that do not support HPOS.
        // We are unsure if older versions use `wp_update_post()`, while newer versions may use `$order->update_status()`.
        // To maintain compatibility across different WooCommerce versions, we add an additional if-else condition.
        if (!isHposEnabled()) { // Explicitly checking if HPOS is NOT enabled
            $order->update_status($orderStatus);
			$order->save(); // Save changes
        } else {
            wp_update_post([
                'ID'          => $orderId,
                'post_status' => $orderStatus,
            ]);
        }
    }

}

function wooSaveCheckoutUTMFields($order, $params)
{
    $pysData                = [];
    $cookieData             = $params['cookies'];
    $getQuery               = $params['requestData'];
    $browserTime            = $params['dateTime'];
    $pysData['pys_landing'] = isset($cookieData['pys_landing_page']) ? ($cookieData['pys_landing_page']) : "";
    $pysData['pys_source']  = isset($cookieData['pysTrafficSource']) ? ($cookieData['pysTrafficSource']) : "direct";
    if ($pysData['pys_source'] == 'direct') {
        $pysData['pys_source'] = $params['referrerDomain'] != '' ? $params['referrerDomain'] : "direct";
    }
    $pysUTMSource   = $cookieData['pys_utm_source'] ?? $getQuery['utm_source'];
    $pysUTMMedium   = $cookieData['pys_utm_medium'] ?? $getQuery['utm_medium'];
    $pysUTMCampaign = $cookieData['pys_utm_campaign'] ?? $getQuery['utm_medium'];
    $pysUTMTerm     = $cookieData['pys_utm_term'] ?? $getQuery['utm_term'];
    $pysUTMContent  = $cookieData['pys_utm_content'] ?? $getQuery['utm_content'];

    $pysData['pys_utm']          = "utm_source:" . $pysUTMSource . "|utm_medium:" . $pysUTMMedium . "|utm_campaign:" . $pysUTMCampaign . "|utm_term:" . $pysUTMTerm . "|utm_content:" . $pysUTMContent;
    $pysData['pys_browser_time'] = $browserTime[0] . "|" . $browserTime[1] . "|" . $browserTime[2];

    if (isHposEnabled()) {
        $order->update_meta_data( 'pys_enrich_data', $pysData );
        $order->save();
    }else{
        update_post_meta($order->get_id(), "pys_enrich_data", $pysData);
    }

}

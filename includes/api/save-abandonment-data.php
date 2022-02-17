<?php
/**
 * For abandon cart recovery related API
 */

function saveCartAbandonmentData(WP_REST_Request $request)
{
    global $woocommerce;
    global $wpdb;

    $params     = $request->get_params();
    $rzpOrderId = sanitize_text_field($params['order_id']);

    $logObj           = array();
    $logObj['api']    = "saveCartAbandonmentData";
    $logObj['params'] = $params;

    $razorpay = new WC_Razorpay(false);
    $api      = $razorpay->getRazorpayApiInstance();
    try
    {
        $razorpayData = $api->order->fetch($rzpOrderId);
    } catch (Exception $e) {
        $response['status']  = false;
        $response['message'] = 'RZP order id does not exist';
        $statusCode          = 400;

        $logObj['response']    = $response;
        $logObj['status_code'] = $statusCode;
        rzpLogError(json_encode($logObj));

        return new WP_REST_Response($response, $statusCode);
    }

    if (isset($razorpayData['receipt'])) {
        $wcOrderId = $razorpayData['receipt'];

        $order = wc_get_order($wcOrderId);
    }

    $razorpay->UpdateOrderAddress($razorpayData, $order);

    initCustomerSessionAndCart();

    $customerEmail = get_post_meta($wcOrderId, '_billing_email', true);

    //Retrieving cart products and their quantities.
    // check plugin is activated or not
    rzpLogInfo('Woocommerce order id:');
    rzpLogInfo(json_encode($wcOrderId));

    if (is_plugin_active('woo-cart-abandonment-recovery/woo-cart-abandonment-recovery.php') && empty($customerEmail) == false) {

        $products    = WC()->cart->get_cart();
        $currentTime = current_time('Y-m-d H:i:s');

        if (isset($razorpayData['shipping_fee'])) {
            $cartTotal = $razorpayData['amount'] / 100 - $razorpayData['shipping_fee'] / 100;
        } else {
            $cartTotal = $razorpayData['amount'] / 100;
        }

        $otherFields = array(
            'wcf_billing_company'     => "",
            'wcf_billing_address_1'   => $razorpayData['customer_details']['billing_address']['line1'] ?? '',
            'wcf_billing_address_2'   => $razorpayData['customer_details']['billing_address']['line2'] ?? '',
            'wcf_billing_state'       => $razorpayData['customer_details']['billing_address']['state'] ?? '',
            'wcf_billing_postcode'    => $razorpayData['customer_details']['billing_address']['zipcode'] ?? '',
            'wcf_shipping_first_name' => $razorpayData['customer_details']['billing_address']['name'] ?? '',
            'wcf_shipping_last_name'  => "",
            'wcf_shipping_company'    => "",
            'wcf_shipping_country'    => $razorpayData['customer_details']['shipping_address']['country'] ?? '',
            'wcf_shipping_address_1'  => $razorpayData['customer_details']['shipping_address']['line1'] ?? '',
            'wcf_shipping_address_2'  => $razorpayData['customer_details']['shipping_address']['line2'] ?? '',
            'wcf_shipping_city'       => $razorpayData['customer_details']['shipping_address']['city'] ?? '',
            'wcf_shipping_state'      => $razorpayData['customer_details']['shipping_address']['state'] ?? '',
            'wcf_shipping_postcode'   => $razorpayData['customer_details']['shipping_address']['zipcode'] ?? '',
            'wcf_order_comments'      => "",
            'wcf_first_name'          => $razorpayData['customer_details']['shipping_address']['name'] ?? '',
            'wcf_last_name'           => "",
            'wcf_phone_number'        => $razorpayData['customer_details']['shipping_address']['contact'] ?? '',
            'wcf_location'            => $razorpayData['customer_details']['shipping_address']['country'] ?? '',
        );

        $checkoutDetails = array(
            'email'         => $razorpayData['customer_details']['email'] ?? $customerEmail,
            'cart_contents' => serialize($products),
            'cart_total'    => sanitize_text_field($cartTotal),
            'time'          => $currentTime,
            'other_fields'  => serialize($otherFields),
            'checkout_id'   => wc_get_page_id('cart'),
        );

        $sessionId = WC()->session->get('wcf_session_id');

        $cartAbandonmentTable = $wpdb->prefix . "cartflows_ca_cart_abandonment";

        $logObj['checkoutDetails'] = $checkoutDetails;

        if (empty($checkoutDetails) == false) {
            $result = $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM `' . $cartAbandonmentTable . '` WHERE session_id = %s and order_status IN (%s, %s)', $sessionId, 'normal', 'abandoned') // phpcs:ignore
            );

            if (isset($result)) {
                $wpdb->update(
                    $cartAbandonmentTable,
                    $checkoutDetails,
                    array('session_id' => $sessionId)
                );

                if ($wpdb->last_error) {
                    $response['status']  = false;
                    $response['message'] = $wpdb->last_error;
                    $statusCode          = 400;
                } else {
                    $response['status']  = true;
                    $response['message'] = 'Data successfully updated for wooCommerce cart abandonment recovery';
                    $statusCode          = 200;
                }

            } else {
                $sessionId                     = md5(uniqid(wp_rand(), true));
                $checkoutDetails['session_id'] = sanitize_text_field($sessionId);

                // Inserting row into Database.
                $wpdb->insert(
                    $cartAbandonmentTable,
                    $checkoutDetails
                );

                if ($wpdb->last_error) {
                    $response['status']  = false;
                    $response['message'] = $wpdb->last_error;
                    $statusCode          = 400;
                } else {
                    // Storing session_id in WooCommerce session.
                    WC()->session->set('wcf_session_id', $sessionId);
                    $response['status']  = true;
                    $response['message'] = 'Data successfully inserted for wooCommerce cart abandonment recovery';
                    $statusCode          = 200;
                }
            }

            $logObj['response']    = $response;
            $logObj['status_code'] = $statusCode;
            rzpLogInfo(json_encode($logObj));
        }

    } else {
        $response['status']  = false;
        $response['message'] = 'Failed to insert data';
        $statusCode          = 400;

        $logObj['response']    = $response;
        $logObj['status_code'] = $statusCode;
        rzpLogInfo(json_encode($logObj));
    }

    return new WP_REST_Response($response, $statusCode);

}

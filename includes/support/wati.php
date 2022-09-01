<?php

function saveWatiCartAbandonmentData($razorpayData){

	$wati = WATI_Chat_And_Notification_Aband_Cart::get_instance();
    $wati->webhook_setting_script();
    $wati->cart_abandonment_tracking_script();
    $wati->save_cart_abandonment_data();
    
    if ( isset( $razorpayData['customer_details']['email'] ) ) {
       
        global $wpdb;

        $cartAbandonmentTable    = $wpdb->prefix . 'wati_abandonment';
        $userEmail               = sanitize_email( $razorpayData['customer_details']['email'] );
        $sessionID               = WC()->session->get( 'wcf_session_id' );
        $sessionCheckoutDetails  = null;

        if ( isset( $sessionID ) ) {
            $sessionCheckoutDetails = $wati->get_checkout_details( $sessionID );
        } else {
            $sessionCheckoutDetails = $wati->get_checkout_details_by_email( $userEmail );
            if ( $sessionCheckoutDetails ) {
                $sessionID = $sessionCheckoutDetails->session_id;
                WC()->session->set( 'wcf_session_id', $sessionID );
            } else {
                $sessionID = md5( uniqid( wp_rand(), true ) );
            }
        }

        $checkoutDetails = prepareAbandonmentData( $razorpayData );

        if ( isset( $sessionCheckoutDetails ) && $sessionCheckoutDetails->order_status === "completed" ) {
            WC()->session->__unset( 'wcf_session_id' );
            $sessionID = md5( uniqid( wp_rand(), true ) );
        }

        if ( isset( $checkoutDetails['cart_total'] ) && $checkoutDetails['cart_total'] > 0 ) {

            if ( ( ! is_null( $sessionID ) ) && ! is_null( $sessionCheckoutDetails ) ) {

                // Updating row in the Database where users Session id = same as prevously saved in Session.
                $wpdb->update(
                    $cartAbandonmentTable,
                    $checkoutDetails,
                    array( 'session_id' => $sessionID )
                );
                $wati->webhook_abandonedCheckout_to_wati($sessionID, '');
            } else {

                $checkoutDetails['session_id'] = sanitize_text_field( $sessionID );
                // Inserting row into Database.
                $wpdb->insert(
                    $cartAbandonmentTable,
                    $checkoutDetails
                );

                // Storing session_id in WooCommerce session.
                WC()->session->set( 'wcf_session_id', $sessionID );
                $wati->webhook_abandonedCheckout_to_wati($sessionID, '');
            }
        }

        $response['status']  = true;
        $response['message'] = 'Data successfully inserted for Wati plugin';
        $statusCode          = 200;

        $result['response']    = $response;
        $result['status_code'] = $statusCode;

        return $result;
    }
}


function prepareAbandonmentData( $razorpayData ) {

    if ( function_exists( 'WC' ) ) {

        // Retrieving cart total value and currency.
        $cartTotal = WC()->cart->total;

        // Retrieving cart products and their quantities.
        $products     = WC()->cart->get_cart();
        $currentTime  = current_time( 'Y-m-d H:i:s' );
        $otherFields  = array(
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
            'wcf_location'            => $razorpayData['customer_details']['shipping_address']['country'] ?? ''.','.$razorpayData['customer_details']['shipping_address']['city'] ?? '',
        );

        $checkoutDetails = array(
            'email'         => $razorpayData['customer_details']['email'] ?? $customerEmail,
            'cart_contents' => serialize( $products ),
            'cart_total'    => sanitize_text_field( $cartTotal ),
            'time'          => sanitize_text_field( $currentTime ),
            'other_fields'  => serialize( $otherFields ),
            'checkout_id'   => wc_get_page_id('cart'),
        );
    }
    return $checkoutDetails;
}

function handleWatiRecoveredOrder($orderID){
    $wati = WATI_Chat_And_Notification_Aband_Cart::get_instance();
    $wati->wati_ca_update_order_status($orderID,'pending','processing');
}

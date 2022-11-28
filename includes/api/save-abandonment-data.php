<?php
/**
 * For abandon cart recovery related API
 */

require_once __DIR__ . '/../support/cartbounty.php';
require_once __DIR__ . '/../support/wati.php';
require_once __DIR__ . '/../support/abandoned-cart-hooks.php';

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

        if (isset($razorpayData['customer_details']['shipping_address'])) {
            //Update the order status to wc-pending as we have the customer address info at this point.
            updateOrderStatus($wcOrderId, 'wc-pending');

        }
        $order = wc_get_order($wcOrderId);
    }

    $razorpay->UpdateOrderAddress($razorpayData, $order);

    abandonedPluginHook($razorpayData); // do_action to notify/send the abandonedCart data to 3rd party plugins

    initCustomerSessionAndCart();

    $customerEmail = get_post_meta($wcOrderId, '_billing_email', true);

    //Retrieving cart products and their quantities.
    // check plugin is activated or not
    rzpLogInfo('Woocommerce order id:');
    rzpLogInfo(json_encode($wcOrderId));

    $result['response']    = "";
    $result['status_code'] = 400;

 //check woocommerce cart abandonment recovery plugin is activated or not
 if (is_plugin_active('woo-cart-abandonment-recovery/woo-cart-abandonment-recovery.php') && empty($customerEmail) == false) {

    //save abandonment data
    $res = saveWooCartAbandonmentRecoveryData($razorpayData);

    if($res['status_code'] == 200){
        $result['response']    = "Data inserted for WooCart abandoned recovery plugin";
        $result['status_code'] = 200;
    }else{
        $result['response']    = "Failed to insert data for WooCart abandoned recovery plugin";
        $result['status_code'] = 400;
    }
}

    // Check Wati.io retargetting plugin is active or not
    if (is_plugin_active('wati-chat-and-notification/wati-chat-and-notification.php')){

        $res = saveWatiCartAbandonmentData($razorpayData);
        if($res['status_code'] == 200){
            $result['response']    = $result['response']."Data inserted for Wati plugin";
            $result['status_code'] = 200;
        }else{
            $result['response']    = $result['response']."Failed to insert data for Wati plugin";
            $result['status_code'] = 400;
        }

    }

    //Check CartBounty plugin is activated or not
    if (is_plugin_active('woo-save-abandoned-carts/cartbounty-abandoned-carts.php') && (empty($razorpayData['customer_details']['email']) == false || empty($customerEmail) == false)) {

        $res = saveCartBountyData($razorpayData); //save abandonment data

        if($res['status_code'] == 200){
            $result['response']    = $result['response'].PHP_EOL."Data inserted for CartBounty plugin";
            $result['status_code'] = 200;
        }else{
            $result['response']    = $result['response'].PHP_EOL."Failed to insert data for CartBounty plugin";
        }
    }

    // check yith woocommerce recover abandoned cart plugin activated or not
    if (is_plugin_active('yith-woocommerce-recover-abandoned-cart-premium/init.php') && (empty($razorpayData['customer_details']['email']) == false) && (empty($razorpayData['customer_details']['shipping_address']))== true) {
        if ((email_exists($razorpayData['customer_details']['email'])) == false) {

            $meta_cart = array(
                'user_id'         => '0',
                'user_email'      => $razorpayData['customer_details']['email'],
                'user_first_name' => '',
                'user_last_name'  => '',
                'user_phone'      => $razorpayData['customer_details']['contact'],
                'language'        => substr( get_bloginfo( 'language' ), 0, 2 ),
                'email_sent'      => 'no',
                'cart_status'     => 'open',
                'user_currency'   => get_woocommerce_currency(),
            );

            $title = $razorpayData['customer_details']['email'];

            $postId = abandonedCart( $title, $meta_cart );

            if ( $postId ) {
                // add a cookie to the user.
                setcookie( 'ywrac_guest_cart', $postId, ywrac_get_timestamp() + $this->delete_abandoned_time * 60, '/' );
                $result['message']   = $result['response'].PHP_EOL."Data successfully inserted for yith cart abandonment recovery and postid";
                $result['status_code']           = 200;
            }else{
                $result['message']   = $result['response'].PHP_EOL."Data falied to inserted for yith cart abandonment recovery";
                $result['status_code'] = 400;
            }

        }
    
    }

    if (is_plugin_active('klaviyo/klaviyo.php') && empty($razorpayData['customer_details']['email']) == false) {
        WC()->cart->empty_cart();
        $cart1cc = create1ccCart($wcOrderId);

        $cart = WC()->cart;
        //Insert data for tracking started checkout.
        $eventData = wck_build_cart_data($cart);
        if (empty($eventData['$extra']['Items'])) {
            $response['status']    = false;
            $response['message']   = 'Failed to insert as Cart item does not exist in klaviyo';
            $statusCode            = 400;
            $result['response']    = $result['response'].PHP_EOL.$response['message'];
        }
        $eventData['$service'] = 'woocommerce';
        unset($eventData['Tags']);
        unset($eventData['Quantity']);
        $email = $customerEmail;

        //Get token from kalviyo plugin
        $klaviyoApi  = WooCommerceKlaviyo::instance();
        $token       = $klaviyoApi->options->get_klaviyo_option('klaviyo_public_api_key');
        $eventObject = ['token' => $token, 'event' => '$started_checkout', 'customer_properties' => array('$email' => $email), 'properties' => $eventData];
        $dataParam   = json_encode($eventObject);
        $data        = base64_encode($dataParam);
        $event       = 'track';

        $logObj['klaviyoData'] = $eventData;
        //calling klaviyo plugin public api
        $url = "https://a.klaviyo.com/api/" . $event . '?data=' . $data;
        file_get_contents($url);
    }

    //check Abandonment cart lite plugin active or not
    if (is_plugin_active('woocommerce-abandoned-cart/woocommerce-ac.php') && empty($razorpayData['customer_details']['email']) == false) {
        //To verify whether the email id is already exist on WordPress
        if (email_exists($razorpayData['customer_details']['email'])) {
            $response['status']    = false;
            $statusCode            = 400;
            $result['response']    = $result['response'].PHP_EOL."Failed to insert data for Abandonment Cart Lite plugin for registered user";
        }

        // Save Abandonment data for Abandonment cart lite
        $res = saveWooAbandonmentCartLiteData($razorpayData, $wcOrderId);

        if($res['status_code'] == 200){
            $result['response']    = $result['response'].PHP_EOL."Successfully inserted data for Abandonment Cart Lite plugin";
            $result['status_code'] = 200;
        }else{
            $result['response']    = $result['response'].PHP_EOL."Failed to insert data for Abandonment Cart Lite plugin";
        }

    } else {
        $response['status']    = false;
        $response['message']   = 'Failed to insert data';
        $statusCode            = 400;
        $result['response']    = $result['response'].PHP_EOL."Failed to insert data for Abandonment Cart Lite plugin";
        $logObj['response']    = $response;
        $logObj['status_code'] = $statusCode;
        rzpLogInfo(json_encode($logObj));
    }

    return new WP_REST_Response($result['response'], $result['status_code']);
}

//Save abandonment data for woocommerce Abondonment cart lite plugin
function saveWooAbandonmentCartLiteData($razorpayData, $wcOrderId)
{
    global $woocommerce;
    global $wpdb;
    $billingFirstName = $razorpayData['customer_details']['billing_address']['name'] ?? '';
    $billingLastName  = " ";
    $billingZipcode   = $razorpayData['customer_details']['billing_address']['zipcode'] ?? '';
    $shippingZipcode  = $razorpayData['customer_details']['shipping_address']['zipcode'] ?? '';
    $shippingCharges  = $razorpayData['shipping_fee'] / 100;
    $email            = $razorpayData['customer_details']['email'];

    // Insert record in abandoned cart table for the guest user.
    $userId = saveGuestUserDetails($billingFirstName, $billingLastName, $email, $billingZipcode, $shippingZipcode, $shippingCharges);

    wcal_common::wcal_set_cart_session('user_id', $userId);

    $currentTime = current_time('timestamp'); // phpcs:ignore

    $results = checkUserIdExist($userId);

    $cart = array();

    $cart['cart'] = WC()->session->cart;

    if (count($results) === 0) {
        $getCookie = WC()->session->get_session_cookie();

        $cartInfo   = wp_json_encode($cart);
        $recResults = checkRecordBySession($getCookie[0]);

        if (get_post_meta($wcOrderId, 'abandoned_user_id', true) == '') {
            add_post_meta($wcOrderId, 'abandoned_user_id', $userId);} else {
            update_post_meta($wcOrderId, 'abandoned_user_id', $userId);
        }

        if (count($recResults) === 0) {
            $abandoned_cart_id = saveUserDetails($userId, $cartInfo, $currentTime, $getCookie[0]);

            wcal_common::wcal_set_cart_session('abandoned_cart_id_lite', $abandoned_cart_id);

            insertCartInfo($userId, $cartInfo);
        } else {
            updateUserDetails($userId, $cartInfo, $currentTime, $getCookie[0]);

            $get_abandoned_record = getAbandonedRecord($userId, $getCookie[0]);

            if (count($get_abandoned_record) > 0) {
                $abandoned_cart_id = $get_abandoned_record[0]->id;
                wcal_common::wcal_set_cart_session('abandoned_cart_id_lite', $abandoned_cart_id);
            }

            insertCartInfo($userId, $cartInfo);
        }
    }

    $response['status']    = true;
    $response['message']   = 'Data successfully inserted for cart abandonment recovery lite';
    $statusCode            = 200;
    $logObj['response']    = $response;
    $logObj['status_code'] = $statusCode;
    rzpLogInfo(json_encode($logObj));

    return $logObj;
}

//Save abandonment data for woocommerce cart abandonment recovery plugin
function saveWooCartAbandonmentRecoveryData($razorpayData)
{
    global $woocommerce;
    global $wpdb;
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
        'wcf_phone_number'        => $razorpayData['customer_details']['shipping_address']['contact'] ?? '', 'wcf_location' => $razorpayData['customer_details']['shipping_address']['country'] ?? '',
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
            $sessionId                     = md5(uniqid(wp_rand(), true));  // nosemgrep: php.lang.security.weak-crypto.weak-crypto

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

    return $logObj;
}

//Insert abandonment data into guest user history
function saveGuestUserDetails($firstName, $lastName, $email, $billingZipcode, $shippingZipcode, $shippingCharges)
{
    global $woocommerce;
    global $wpdb;
    $wpdb->query( // phpcs:ignore
        $wpdb->prepare(
            'INSERT INTO `' . $wpdb->prefix . 'ac_guest_abandoned_cart_history_lite`( billing_first_name, billing_last_name, email_id, billing_zipcode, shipping_zipcode, shipping_charges ) VALUES ( %s, %s, %s, %s, %s, %s )',
            $firstName,
            $lastName,
            $email,
            $billingZipcode,
            $shippingZipcode,
            $shippingCharges
        )
    );

    return $wpdb->insert_id;
}

//Update usermeta
function insertCartInfo($userId, $cartInfo)
{
    global $woocommerce;
    global $wpdb;
    $wpdb->query( // phpcs:ignore
        $wpdb->prepare(
            'INSERT INTO `' . $wpdb->prefix . 'usermeta`( user_id, meta_key, meta_value ) VALUES ( %s, %s, %s )',
            $userId,
            '_woocommerce_persistent_cart',
            $cartInfo
        )
    );
}

//Update abandonment cart data
function updateUserDetails($userId, $cartInfo, $currentTime, $cookie)
{
    global $woocommerce;
    global $wpdb;
    $wpdb->query( // phpcS:ignore
        $wpdb->prepare(
            'UPDATE `' . $wpdb->prefix . 'ac_abandoned_cart_history_lite` SET user_id = %s, abandoned_cart_info = %s, abandoned_cart_time = %s WHERE session_id = %s AND cart_ignored = %s',
            $userId,
            $cartInfo,
            $currentTime,
            $cookie,
            0
        )
    );
}

//Insert abandonment data into cart history
function saveUserDetails($userId, $cartInfo, $time, $cookie)
{
    global $woocommerce;
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query(
        $wpdb->prepare(
            'INSERT INTO `' . $wpdb->prefix . 'ac_abandoned_cart_history_lite`( user_id, abandoned_cart_info, abandoned_cart_time, cart_ignored, recovered_cart, user_type, session_id ) VALUES ( %s, %s, %s, %s, %s, %s, %s )',
            $userId,
            $cartInfo,
            $time,
            0,
            0,
            'GUEST',
            $cookie
        )
    );

    return $wpdb->insert_id;
}

//Get record by userid and session
function getAbandonedRecord($userId, $cookie)
{
    global $woocommerce;
    global $wpdb;
    $get_abandoned_record = $wpdb->get_results( // phpcS:ignore
        $wpdb->prepare(
            'SELECT * FROM `' . $wpdb->prefix . 'ac_abandoned_cart_history_lite` WHERE user_id = %d AND cart_ignored = %s AND session_id = %s',
            $userId,
            0,
            $cookie
        )
    );

    return $get_abandoned_record;
}

//Check record already exist or not
function checkUserIdExist($userId)
{
    global $woocommerce;
    global $wpdb;
    $results = $wpdb->get_results( // phpcs:ignore
        $wpdb->prepare(
            'SELECT * FROM `' . $wpdb->prefix . 'ac_abandoned_cart_history_lite` WHERE user_id = %d AND cart_ignored = %s AND recovered_cart = %s AND user_type = %s',
            $userId,
            0,
            0,
            'GUEST'
        )
    );

    return $results;
}

//Get record by session
function checkRecordBySession($cookie)
{
    global $woocommerce;
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $results = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT * FROM `' . $wpdb->prefix . 'ac_abandoned_cart_history_lite` WHERE session_id LIKE %s AND cart_ignored = %s AND recovered_cart = %s',
            $cookie,
            0,
            0
        )
    );

    return $results;
}

// prepare cart data
function abandonedCart( $title, $metas ) {

    if ( apply_filters( 'ywrac_add_abandoned_cart', false ) ) {
        return;
    }

    $post = array(
        'post_content' => '',
        'post_status'  => 'publish',
        'post_title'   => $title,
        'post_type'    => 'ywrac_cart',
    );

    $cartId = wp_insert_post( $post );

    $recoverCart = new YITH_WC_Recover_Abandoned_Cart();

    if ( $cartId && ! empty( $metas ) ) {
        update_post_meta( $cartId, '_language', $recoverCart->get_user_language() );
        foreach ( $metas as $meta_key => $meta_value ) {
            update_post_meta( $cartId, '_' . $meta_key, $meta_value );
        }

        update_post_meta( $cartId, '_cart_content', $recoverCart->get_item_cart() );
        update_post_meta( $cartId, '_cart_subtotal', $recoverCart->get_subtotal_cart() );
    }

    return $cartId;
}


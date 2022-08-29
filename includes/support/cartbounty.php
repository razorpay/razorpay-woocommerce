<?php
//Save cart abandonment data for CartBounty plugin
function saveCartBountyData($razorpayData)
{
    global $wpdb;
    $products         = WC()->cart->get_cart();
    $ghost            = false;
    $name             = $razorpayData['customer_details']['billing_address']['name'] ?? '';
    $surname          = " ";
    $email            = $razorpayData['customer_details']['email'];
    $phone            = $razorpayData['customer_details']['contact'];
    $cartTable        = $wpdb->prefix . "cartbounty";
    $cart             = readCartCB($razorpayData['receipt']);
    $cartbountyPublic = new CartBounty_Public(CARTBOUNTY_PLUGIN_NAME_SLUG, CARTBOUNTY_VERSION_NUMBER);
    $cartbountyAdmin  = new CartBounty_Admin(CARTBOUNTY_PLUGIN_NAME_SLUG, CARTBOUNTY_VERSION_NUMBER);

    $location = array(
        'country'  => $razorpayData['customer_details']['shipping_address']['country'] ?? '',
        'city'     => $razorpayData['customer_details']['shipping_address']['city'] ?? '',
        'postcode' => $razorpayData['customer_details']['shipping_address']['zipcode'] ?? '',
    );

    $otherFields = array(
        'cartbounty_billing_company'     => '',
        'cartbounty_billing_address_1'   => $razorpayData['customer_details']['billing_address']['line1'] ?? '',
        'cartbounty_billing_address_2'   => $razorpayData['customer_details']['billing_address']['line2'] ?? '',
        'cartbounty_billing_state'       => $razorpayData['customer_details']['billing_address']['state'] ?? '',
        'cartbounty_shipping_first_name' => $razorpayData['customer_details']['billing_address']['name'] ?? '',
        'cartbounty_shipping_last_name'  => '',
        'cartbounty_shipping_company'    => '',
        'cartbounty_shipping_country'    => $razorpayData['customer_details']['shipping_address']['country'] ?? '',
        'cartbounty_shipping_address_1'  => $razorpayData['customer_details']['shipping_address']['line1'] ?? '',
        'cartbounty_shipping_address_2'  => $razorpayData['customer_details']['shipping_address']['line2'] ?? '',
        'cartbounty_shipping_city'       => $razorpayData['customer_details']['shipping_address']['city'] ?? '',
        'cartbounty_shipping_state'      => $razorpayData['customer_details']['shipping_address']['state'] ?? '',
        'cartbounty_shipping_postcode'   => $razorpayData['customer_details']['shipping_address']['zipcode'] ?? '',
        'cartbounty_order_comments'      => '',
        'cartbounty_create_account'      => '',
        'cartbounty_ship_elsewhere'      => '',
    );

    $userData = array(
        'name'         => $name,
        'surname'      => $surname,
        'email'        => $email,
        'phone'        => $phone,
        'location'     => $location,
        'other_fields' => $otherFields,
    );

    $sessionID = getSessionID($razorpayData['receipt']);

    if ($cartbountyPublic->cart_saved($sessionID) == false) {
        //If cart is not saved
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO $cartTable
          ( name, surname, email, phone, location, cart_contents, cart_total, currency, time, session_id, other_fields)
          VALUES ( %s, %s, %s, %s, %s, %s, %0.2f, %s, %s, %s, %s)",
                array(
                    'name'         => sanitize_text_field($userData['name']),
                    'surname'      => sanitize_text_field($userData['surname']),
                    'email'        => sanitize_email($userData['email']),
                    'phone'        => filter_var($userData['phone'], FILTER_SANITIZE_NUMBER_INT),
                    'location'     => sanitize_text_field(serialize($userData['location'])),
                    'products'     => serialize($cart['product_array']),
                    'total'        => sanitize_text_field($cart['cart_total']),
                    'currency'     => sanitize_text_field($cart['cart_currency']),
                    'time'         => sanitize_text_field($cart['current_time']),
                    'session_id'   => sanitize_text_field($cart['session_id']),
                    'other_fields' => sanitize_text_field(serialize($userData['other_fields'])),
                )
            )
        );

        $cartbountyPublic->increase_recoverable_cart_count();
        $cartbountyPublic->set_cartbounty_session($sessionID);
    }
    $updatedRows = $wpdb->query(
        $wpdb->prepare(
            "UPDATE $cartTable
        SET name     = %s,
        surname      = %s,
        email        = %s,
        phone        = %s,
        location     = %s,
        other_fields = '$otherFields'
        WHERE session_id = %s and type=0",
            sanitize_text_field($userData['name']),
            sanitize_text_field($userData['surname']),
            sanitize_email($userData['email']),
            filter_var($userData['phone'], FILTER_SANITIZE_NUMBER_INT),
            sanitize_text_field(serialize($userData['location'])),
            $sessionID
        )
    );

    deleteDuplicateCarts($sessionID, $updatedRows, $cartTable);
    $cartbountyPublic->set_cartbounty_session($sessionID);
    $response['status']  = true;
    $response['message'] = 'Data successfully inserted for CartBounty plugin';
    $statusCode          = 200;

    update_post_meta($sessionID, 'FromEmail', "Y");
    WC()->session->set('cartbounty_from_link', true);

    $result['response']    = $response;
    $result['status_code'] = $statusCode;
    return $result;
}

//Delete Duplicate carts CartBounty plugin
function deleteDuplicateCarts($sessionID, $duplicateCount, $cartTable)
{
    global $wpdb;
    $cartbountyAdmin = new CartBounty_Admin(CARTBOUNTY_PLUGIN_NAME_SLUG, CARTBOUNTY_VERSION_NUMBER);
    if ($duplicateCount) {
        //If we have updated at least one row
        if ($duplicateCount > 1) {
            //Checking if we have updated more than a single row to know if there were duplicates
            $whereSentence = $cartbountyAdmin->get_where_sentence('ghost');
            //First delete all duplicate ghost carts
            $deletedDuplicateGhostCarts = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $cartTable
                    WHERE session_id = %s
                    $whereSentence",
                    $sessionID
                )
            );

            $limit = $duplicateCount - $deletedDuplicateGhostCarts - 1;
            if ($limit < 1) {
                $limit = 0;
            }

            $wpdb->query( //Leaving one cart remaining that can be identified
                $wpdb->prepare(
                    "DELETE FROM $cartTable
                    WHERE session_id = %s AND
                    type != %d
                    ORDER BY id DESC
                    LIMIT %d",
                    $sessionID,
                    1,
                    $limit
                )
            );
        }
    }
}

function getSessionID($orderID)
{
    $sessionID = WC()->session->get_customer_id();
    $order     = wc_get_order($orderID);
    $userID    = $order->get_user_id();

    if ($userID != 0 or $userID != null) {
        //Used to check whether user is logged in
        $sessionID = $userID;
    } else {
        $sessionID = WC()->session->get('cartbounty_session_id');
        if (empty($sessionID)) { //If session value does not exist - set one now
            $sessionID = WC()->session->get_customer_id(); //Retrieving customer ID from WooCommerce sessions variable
        }
        if (WC()->session->get('cartbounty_from_link') && WC()->session->get('cartbounty_session_id')) {
            $sessionID = WC()->session->get('cartbounty_session_id');
        }
    }
    return $sessionID;
}

function handleCBRecoveredOrder($orderID)
{
    global $wpdb;

    if (!isset($orderID)) {
        //Exit if Order ID is not present
        return;
    }

    $cartbountyPublic = new CartBounty_Public(CARTBOUNTY_PLUGIN_NAME_SLUG, CARTBOUNTY_VERSION_NUMBER);
    $cartbountyAdmin  = new CartBounty_Admin(CARTBOUNTY_PLUGIN_NAME_SLUG, CARTBOUNTY_VERSION_NUMBER);

    $cartbountyPublic->update_logged_customer_id(); //In case a user chooses to create an account during checkout process, the session id changes to a new one so we must update it

    $cartTable = $wpdb->prefix . CARTBOUNTY_TABLE_NAME;

    if (WC()->session) {
        //If session exists
        $cart      = readCartCB($orderID);
        $type      = $cartbountyAdmin->get_cart_type('ordered'); //Default type describing an order has been placed
        $sessionID = getSessionID($orderID);
        $fromEmail = get_post_meta(getSessionID($orderID), 'FromEmail', true);

        if ($sessionID != null) {
            if ($fromEmail === "Y") {
                //If the user has arrived from CartBounty link
                $type = $cartbountyAdmin->get_cart_type('recovered');
                update_post_meta($sessionID, 'FromEmail', "N");
            }
            $cartbountyAdmin->update_cart_type($sessionID, $type); //Update cart type to recovered

        }
    }
    clearCartData($orderID, $cartTable); //Clearing abandoned cart after it has been synced

}

//CartBounty admin function to clearCartData
function clearCartData($wcOrderId, $cartTable)
{
    //If a new Order is added from the WooCommerce admin panel, we must check if WooCommerce session is set. Otherwise we would get a Fatal error.
    if (!isset(WC()->session)) {
        return;
    }

    global $wpdb;
    $cart = readCartCB($wcOrderId);

    if (!isset($cart['session_id'])) {
        return;
    }

    //Cleaning Cart data
    $update_result = $wpdb->query(
        $wpdb->prepare(
            "UPDATE $cartTable
            SET cart_contents = '',
            cart_total        = %d,
            currency          = %s,
            time              = %s
            WHERE session_id  = %s AND
            type              = %d",
            0,
            sanitize_text_field($cart['cart_currency']),
            sanitize_text_field($cart['current_time']),
            $cart['session_id'],
            0
        )
    );
}

//CartBounty plugin function for retrieving the cart data
function readCartCB($wcOrderId)
{
    WC()->cart->empty_cart();
    $cart1cc          = create1ccCart($wcOrderId);
    $cart             = WC()->cart;
    $cartbountyPublic = new CartBounty_Public(CARTBOUNTY_PLUGIN_NAME_SLUG, CARTBOUNTY_VERSION_NUMBER);

    if (!WC()->cart) {
        //Exit if Woocommerce cart has not been initialized
        return;
    }

    $cartTotal    = WC()->cart->total; //Retrieving cart total value and currency
    $cartCurrency = get_woocommerce_currency();
    $currentTime  = current_time('mysql', false); //Retrieving current time
    $sessionID    = getSessionID($wcOrderId);
    //Retrieving cart
    $products     = WC()->cart->get_cart_contents();
    $productArray = array();

    foreach ($products as $key => $product) {
        $item                  = wc_get_product($product['data']->get_id());
        $productTitle          = $item->get_title();
        $productQuantity       = $product['quantity'];
        $productVariationPrice = '';
        $productTax            = '';

        if (isset($product['line_total'])) {
            $productVariationPrice = $product['line_total'];
        }

        if (isset($product['line_tax'])) {
            //If we have taxes, add them to the price
            $productTax = $product['line_tax'];
        }

        // Handling product variations
        if ($product['variation_id']) {
            //If user has chosen a variation
            $singleVariation = new WC_Product_Variation($product['variation_id']);

            //Handling variable product title output with attributes
            $productAttributes  = $cartbountyPublic->attribute_slug_to_title($singleVariation->get_variation_attributes());
            $productVariationID = $product['variation_id'];
        } else {
            $productAttributes  = false;
            $productVariationID = '';
        }

        $productData = array(
            'product_title'           => $productTitle . $productAttributes,
            'quantity'                => $productQuantity,
            'product_id'              => $product['product_id'],
            'product_variation_id'    => $productVariationID,
            'product_variation_price' => $productVariationPrice,
            'product_tax'             => $productTax,
        );

        $productArray[] = $productData;
    }

    return $resultsArray = array(
        'cart_total'    => $cartTotal,
        'cart_currency' => $cartCurrency,
        'current_time'  => $currentTime,
        'session_id'    => $sessionID,
        'product_array' => $productArray,
    );
}

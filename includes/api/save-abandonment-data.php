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

        if (isset($razorpayData['customer_details']['shipping_address'])) {
            //Update the order status to wc-pending as we have the customer address info at this point.
            updateOrderStatus($wcOrderId, 'wc-pending');

        }
        $order = wc_get_order($wcOrderId);
    }

    $razorpay->UpdateOrderAddress($razorpayData, $order);

    initCustomerSessionAndCart();

    $customerEmail = get_post_meta($wcOrderId, '_billing_email', true);

    //Retrieving cart products and their quantities.
    // check plugin is activated or not
    rzpLogInfo('Woocommerce order id:');
    rzpLogInfo(json_encode($wcOrderId));

    //check woocommerce cart abandonment recovery plugin is activated or not
    if (is_plugin_active('woo-cart-abandonment-recovery/woo-cart-abandonment-recovery.php') && empty($customerEmail) == false) {

        //save abandonment data
        $result = saveWooCartAbandonmentRecoveryData($razorpayData);

        return new WP_REST_Response($result['response'], $result['status_code']);
    }

    //Check CartBounty plugin is activated or not
    if (is_plugin_active('woo-save-abandoned-carts/cartbounty-abandoned-carts.php') && empty($razorpayData['customer_details']['email']) == false) {

        $result = saveCartBountyData($razorpayData); //save abandonment data

        return new WP_REST_Response($result['response'], $result['status_code']);
    }

    if (is_plugin_active('klaviyo/klaviyo.php') && empty($razorpayData['customer_details']['email']) == false) {
        WC()->cart->empty_cart();
        $cart1cc = create1ccCart($wcOrderId);

        $cart = WC()->cart;
        //Insert data for tracking started checkout.
        $eventData = wck_build_cart_data($cart);
        if (empty($eventData['$extra']['Items'])) {
            $response['status']  = false;
            $response['message'] = 'cart item not exist in kalviyo';
            $statusCode          = 400;
            return new WP_REST_Response($response, $statusCode);
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
        //calling kalviyo plugin public api
        $url = "https://a.klaviyo.com/api/" . $event . '?data=' . $data;
        file_get_contents($url);
    }

    //check Abondonment cart lite plugin active or not
    if (is_plugin_active('woocommerce-abandoned-cart/woocommerce-ac.php') && empty($razorpayData['customer_details']['email']) == false) {
        //To verify whether the email id is already exist on WordPress
        if (email_exists($razorpayData['customer_details']['email'])) {
            $response['status']  = false;
            $response['message'] = 'For Register user we can not insert data';
            $statusCode          = 400;
            return new WP_REST_Response($response, $statusCode);
        }

        // Save Abondonment data for Abondonment cart lite
        $result = saveWooAbandonmentCartLiteData($razorpayData, $wcOrderId);

        return new WP_REST_Response($result['response'], $result['status_code']);
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

    return $logObj;
}

//Save cart abandonment data for CartBounty plugin
function saveCartBountyData($razorpayData){
    global $wpdb;
    $products        = WC()->cart->get_cart();
    $ghost           = false;
    $name            = $razorpayData['customer_details']['billing_address']['name'] ?? '';
    $surname         = " ";
    $email           = $razorpayData['customer_details']['email'];
    $phone           = $razorpayData['customer_details']['contact'];
    $cart_table      = $wpdb->prefix ."cartbounty";
    $cart            = readCartCB($razorpayData['receipt']);
   
    $location = array(
      'country' 	=> $razorpayData['customer_details']['shipping_address']['country'] ?? '',
      'city' 		=> $razorpayData['customer_details']['shipping_address']['city'] ?? '',
      'postcode' 	=> $razorpayData['customer_details']['shipping_address']['zipcode'] ?? ''
  );

  $other_fields = array(
      'cartbounty_billing_company' 		=> '',
      'cartbounty_billing_address_1'    => $razorpayData['customer_details']['billing_address']['line1'] ?? '',
      'cartbounty_billing_address_2' 	=> $razorpayData['customer_details']['billing_address']['line2'] ?? '',
      'cartbounty_billing_state' 		=> $razorpayData['customer_details']['billing_address']['state'] ?? '',
      'cartbounty_shipping_first_name' 	=> $razorpayData['customer_details']['billing_address']['name'] ?? '',
      'cartbounty_shipping_last_name'   => '',
      'cartbounty_shipping_company' 	=> '',
      'cartbounty_shipping_country' 	=> $razorpayData['customer_details']['shipping_address']['country'] ?? '',
      'cartbounty_shipping_address_1'   => $razorpayData['customer_details']['shipping_address']['line1'] ?? '',
      'cartbounty_shipping_address_2'   => $razorpayData['customer_details']['shipping_address']['line2'] ?? '',
      'cartbounty_shipping_city' 		=> $razorpayData['customer_details']['shipping_address']['city'] ?? '',
      'cartbounty_shipping_state' 		=> $razorpayData['customer_details']['shipping_address']['state'] ?? '',
      'cartbounty_shipping_postcode' 	=> $razorpayData['customer_details']['shipping_address']['zipcode'] ?? '',
      'cartbounty_order_comments' 	    => '',
      'cartbounty_create_account' 	    => '',
      'cartbounty_ship_elsewhere' 		=> ''
  );

  $user_data = array(
      'name'			=> $name,
      'surname'		    => $surname,
      'email'			=> $email,
      'phone'			=> $phone,
      'location'		=> $location,
      'other_fields'	=> $other_fields
  );

  $session_id = getSessionID($razorpayData['receipt']);
  $cartSaved  = cartSaved($session_id,$cart_table);

  if(!$cartSaved){ //If cart is not saved
    $wpdb->query(
      $wpdb->prepare(
          "INSERT INTO $cart_table
          ( name, surname, email, phone, location, cart_contents, cart_total, currency, time, session_id, other_fields)
          VALUES ( %s, %s, %s, %s, %s, %s, %0.2f, %s, %s, %s, %s)",
          array(
              'name'			=> sanitize_text_field( $user_data['name'] ),
              'surname'		    => sanitize_text_field( $user_data['surname'] ),
              'email'			=> sanitize_email( $user_data['email'] ),
              'phone'			=> filter_var( $user_data['phone'], FILTER_SANITIZE_NUMBER_INT),
              'location'		=> sanitize_text_field( serialize( $user_data['location'] ) ),
              'products'		=> serialize($cart['product_array']),
              'total'			=> sanitize_text_field( $cart['cart_total'] ),
              'currency'		=> sanitize_text_field( $cart['cart_currency'] ),
              'time'			=> sanitize_text_field($cart['current_time']),
              'session_id'	    => sanitize_text_field($cart['session_id']),
              'other_fields'	=> sanitize_text_field(serialize($user_data['other_fields']))
          )
      )
  );

   increaseRecoverableCartCountCB();
   setCartBountySession($session_id);
}
  $updated_rows = $wpdb->query(
    $wpdb->prepare(
        "UPDATE $cart_table
        SET name = %s,
        surname = %s,
        email = %s,
        phone = %s,
        location = %s,
        other_fields = '$other_fields'
        WHERE session_id = %s and type=0",
        sanitize_text_field( $user_data['name'] ),
        sanitize_text_field( $user_data['surname'] ),
        sanitize_email( $user_data['email'] ),
        filter_var( $user_data['phone'], FILTER_SANITIZE_NUMBER_INT),
        sanitize_text_field( serialize( $user_data['location'] ) ),
        $session_id
    )
);

    deleteDuplicateCarts( $session_id, $updated_rows,$cart_table);
    setCartBountySession($session_id);
    $response['status']    = true;
    $response['message']   = 'Data successfully inserted for CartBounty plugin';
    $statusCode            = 200;

    update_post_meta($session_id,'FromEmail',"Y");
    WC()->session->set( 'cartbounty_from_link', true );
    
    $result['response']    = $response;
    $result['status_code'] = $statusCode;
    return $result;
}

function getSessionID($order_id){
  $session_id = WC()->session->get_customer_id();
  $order      = wc_get_order( $order_id );
  $user_id    = $order->get_user_id();
  
if($user_id != 0 or $user_id != null){  //Used to check whether user is logged in
    $session_id=$user_id;
  }else{
        $session_id = WC()->session->get( 'cartbounty_session_id' );
        if( empty( $session_id ) ){ //If session value does not exist - set one now
          $session_id = WC()->session->get_customer_id(); //Retrieving customer ID from WooCommerce sessions variable
         }
       if( WC()->session->get( 'cartbounty_from_link' ) && WC()->session->get( 'cartbounty_session_id' ) ){
          $session_id = WC()->session->get( 'cartbounty_session_id' );
         }
  }
  return $session_id;
}

function handleOrder( $order_id ){
    if( !isset($order_id) ){ //Exit if Order ID is not present
        return;
    }

     $public = new CartBounty_Public(CARTBOUNTY_PLUGIN_NAME_SLUG, CARTBOUNTY_VERSION_NUMBER);
     $public->update_logged_customer_id(); //In case a user chooses to create an account during checkout process, the session id changes to a new one so we must update it
     
     $cart_table = $wpdb->prefix . CARTBOUNTY_TABLE_NAME;

     if( WC()->session ){ //If session exists
        $cart      = readCartCB($order_id);
        $type      = getCartType('ordered'); //Default type describing an order has been placed
        $session_id= getSessionID($order_id);
        $fromEmail = get_post_meta(getSessionID($order_id),'FromEmail',true);

        if(getSessionID($order_id)!=null){
            if($fromEmail==="Y"){ //If the user has arrived from CartBounty link
                $type = getCartType('recovered');
                update_post_meta($session_id,'FromEmail',"N");
            }
            updateCartType($session_id, $type, $cart_table); //Update cart type to recovered
            
        }
    }
    clearCartData($order_id, $cart_table); //Clearing abandoned cart after it has been synced
    
}

function updateCartType( $session_id, $type, $cart_table ){
    if($session_id){
        global $wpdb;
        $field       = 'session_id';
        $where_value = $session_id;
        $data = array(
            'type = ' . sanitize_text_field($type)
        );

        if( $type == getCartType('recovered') ){ //If order should be marked as recovered
            //Increase total
            $data[] = 'mail_sent = 0';
            $public = new CartBounty_Public(CARTBOUNTY_PLUGIN_NAME_SLUG, CARTBOUNTY_VERSION_NUMBER);
            $public->increase_recovered_cart_count();
        }

        $data = implode(', ', $data);

        $updated_rows = $wpdb->query(
            $wpdb->prepare(
                "UPDATE $cart_table
                SET $data
                WHERE $field = %s AND
                type != %d",
                $where_value,
                getCartType('recovered')
            )
        );
    }
}

//Delete Duplicate carts CartBounty plugin
function deleteDuplicateCarts( $session_id, $duplicate_count , $cart_table){
    global $wpdb;
    if($duplicate_count){ //If we have updated at least one row
        if($duplicate_count > 1){ //Checking if we have updated more than a single row to know if there were duplicates
            $where_sentence = getWhereSentence('ghost');
            //First delete all duplicate ghost carts
            $deleted_duplicate_ghost_carts = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $cart_table
                    WHERE session_id = %s
                    $where_sentence",
                    $session_id
                )
            );

            $limit = $duplicate_count - $deleted_duplicate_ghost_carts - 1;
            if($limit < 1){
                $limit = 0;
            }

            $wpdb->query( //Leaving one cart remaining that can be identified
                $wpdb->prepare(
                    "DELETE FROM $cart_table
                    WHERE session_id = %s AND
                    type != %d
                    ORDER BY id DESC
                    LIMIT %d",
                    $session_id,
                    1,
                    $limit
                )
            );
        }
    }
}

//CartBounty admin function to getWhereSentence
function getWhereSentence( $cart_status ){
    $where_sentence = '';

    if($cart_status == 'recoverable'){
        $where_sentence = "AND (email != '' OR phone != '') AND type != ". getCartType('recovered') ." AND type != " . getCartType('ordered');

    }elseif($cart_status == 'ghost'){
        $where_sentence = "AND ((email IS NULL OR email = '') AND (phone IS NULL OR phone = '')) AND type != ". getCartType('recovered') ." AND type != " . getCartType('ordered');

    }elseif($cart_status == 'recovered'){
        $where_sentence = "AND type = ". getCartType('recovered');

    }elseif(get_option('cartbounty_exclude_ghost_carts')){ //In case Ghost carts have been excluded
        $where_sentence = "AND (email != '' OR phone != '')";
    }

    return $where_sentence;
}

//CartBounty admin function to get Cart type
function getCartType( $status ){
    if( empty($status) ){
        return;
    }

    $type = 0;

    switch ( $status ) {
        case 'abandoned':

            $type = 0;
            break;

        case 'recovered':

            $type = 1;
            break;

        case 'ordered':

            $type = 2;
            break;
            
    }
    return $type;
}

//CartBounty admin function to clearCartData
function clearCartData($wcOrderId, $cart_table){
    //If a new Order is added from the WooCommerce admin panel, we must check if WooCommerce session is set. Otherwise we would get a Fatal error.
    if( !isset( WC()->session ) ){
        return;
    }

    global $wpdb;
    $cart = readCartCB($wcOrderId);

    if( !isset( $cart['session_id'] ) ){
        return;
    }

    //Cleaning Cart data
    $update_result = $wpdb->query(
        $wpdb->prepare(
            "UPDATE $cart_table
            SET cart_contents = '',
            cart_total = %d,
            currency = %s,
            time = %s
            WHERE session_id = %s AND
            type = %d",
            0,
            sanitize_text_field( $cart['cart_currency'] ),
            sanitize_text_field( $cart['current_time'] ),
            $cart['session_id'],
            0
        )
    );
}

//Function for CartBounty to check whether the cart is saved
function cartSaved( $session_id, $cart_table ){
    $saved = false;
    if( $session_id !== NULL ){
        global $wpdb;
        //Checking if we have this abandoned cart in our database already
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT session_id
					FROM $cart_table
					WHERE session_id = %s AND
					type = %d",
                $session_id,
                0
            )
        );

        if($result){
            $saved = true;
        }
    }

    return $saved;
}

  // CartBounty function to set session_id
  function setCartBountySession($session_id){
     if(!WC()->session->get('cartbounty_session_id')){ //In case browser session is not set, we make sure it gets set
           WC()->session->set('cartbounty_session_id', $session_id); //Storing session_id in WooCommerce session
     }
}

  //CartBounty function to keep track of number of recoverable carts
  function increaseRecoverableCartCountCB(){
      if(!WC()->session){ //If session does not exist, exit function
          return;
      }
      if(WC()->session->get('cartbounty_recoverable_count_increased') || WC()->session->get('cartbounty_from_link')){//Exit function in case we already have run this once or user has returned form a recovery link
          return;
      }
      update_option('cartbounty_recoverable_cart_count', get_option('cartbounty_recoverable_cart_count') + 1);
      WC()->session->set('cartbounty_recoverable_count_increased', 1);

      if(WC()->session->get('cartbounty_ghost_count_increased')){ //In case we previously increased ghost cart count, we must now reduce it as it has been turned to recoverable
          decrease_ghost_cart_count( 1 );
      }
  }

  //CartBounty plugin function for retrieving the cart data
  function readCartCB($wcOrderId){
      WC()->cart->empty_cart();
      $cart1cc = create1ccCart($wcOrderId);
      $cart = WC()->cart;

      if( !WC()->cart ){ //Exit if Woocommerce cart has not been initialized
          return;
      }

      $cart_total    = WC()->cart->total;//Retrieving cart total value and currency
      $cart_currency = get_woocommerce_currency();
      $current_time  = current_time( 'mysql', false ); //Retrieving current time
      $session_id    = WC()->session->get( 'cartbounty_session_id' ); //Check if the session is already set

      if( empty( $session_id ) ){ //If session value does not exist - set one now
          $session_id = WC()->session->get_customer_id(); //Retrieving customer ID from WooCommerce sessions variable
      }

      if( WC()->session->get( 'cartbounty_from_link' ) && WC()->session->get( 'cartbounty_session_id' ) ){
          $session_id = WC()->session->get( 'cartbounty_session_id' );
      }

      //Retrieving cart
      $products      = WC()->cart->get_cart_contents();
      $product_array = array();

      foreach( $products as $key => $product ){
          $item                    = wc_get_product( $product['data']->get_id() );
          $product_title           = $item->get_title();
          $product_quantity        = $product['quantity'];
          $product_variation_price = '';
          $product_tax             = '';

          if( isset( $product['line_total'] ) ){
              $product_variation_price = $product['line_total'];
          }

          if( isset( $product['line_tax'] ) ){ //If we have taxes, add them to the price
              $product_tax = $product['line_tax'];
          }

          // Handling product variations
          if( $product['variation_id'] ){ //If user has chosen a variation
              $single_variation = new WC_Product_Variation( $product['variation_id'] );

              //Handling variable product title output with attributes
              $product_attributes = attribute_slug_to_title( $single_variation->get_variation_attributes() );
              $product_variation_id = $product['variation_id'];
          }else{
              $product_attributes = false;
              $product_variation_id = '';
          }

          $product_data = array(
              'product_title'           => $product_title . $product_attributes,
              'quantity'                => $product_quantity,
              'product_id'              => $product['product_id'],
              'product_variation_id'    => $product_variation_id,
              'product_variation_price' => $product_variation_price,
              'product_tax'             => $product_tax
          );

          $product_array[] = $product_data;
      }

      return $results_array = array(
          'cart_total'   	=> $cart_total,
          'cart_currency'   => $cart_currency,
          'current_time' 	=> $current_time,
          'session_id'  	=> $session_id,
          'product_array'   => $product_array
      );
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

<?php
/**
 * Given shipping address and product, attempts to calculate available shipping rates for the address.
 * Doesn't support shipping > 1 address.
 *
 * @param WP_REST_Request $request JSON request for shipping endpoint.
 * @return array|WP_Error|WP_REST_Response
 * @throws Exception If failed to add items to cart or no shipping options available for address.
 */
function calculateShipping1cc(WP_REST_Request $request)
{
    $params = $request->get_params();

    $logObj           = array();
    $logObj['api']    = 'calculateShipping1cc';
    $logObj['params'] = $params;

    $validateInput = validateInput('shipping', $params);

    if ($validateInput != null) {
        $response['failure_reason'] = $validateInput;
        $response['failure_code']   = 'VALIDATION_ERROR';
        $logObj['response']         = $response;
        rzpLogError(json_encode($logObj));
        return new WP_REST_Response($response, 400);
    }

    $cartResponse = false;
    $orderId      = (int) sanitize_text_field($params['order_id']);
    $addresses    = $params['addresses'];
    $rzpOrderId   = sanitize_text_field($params['razorpay_order_id']);

    initCustomerSessionAndCart();
    // Cleanup cart.
    WC()->cart->empty_cart();

    $cartResponse = create1ccCart($orderId);

    if ($cartResponse === false) {
        $response['status']         = false;
        $response['failure_reason'] = 'Invalid merchant order id';
        $response['failure_code']   = 'VALIDATION_ERROR';
        $logObj['response']         = $response;
        rzpLogError(json_encode($logObj));
        return new WP_REST_Response($response, 400);
    }

    foreach ($addresses as $address) {
        if ($cartResponse) {
            $customerResponse = shippingUpdateCustomerInformation1cc($address);
        }

        if ($customerResponse) {
            $response[] = shippingCalculatePackages1cc($address['id'], $orderId, $address, $rzpOrderId);
        } else {
            $response['failure_reason'] = 'Set customer shipping information failed';
            $response['failure_code']   = 'VALIDATION_ERROR';
            $logger->log('info', json_encode($response), array('source' => 'rzp1cc'));
            return new WP_REST_Response($response, 400);
        }
    }

    // Cleanup cart.
    WC()->cart->empty_cart();
    $logObj['response'] = $response;
    rzpLogInfo(json_encode($logObj));
    return new WP_REST_Response(array('addresses' => $response), 200);
}

/**
 * Update customer information.
 *
 * @param array $params The request params.
 *
 * @return mixed
 */
function shippingUpdateCustomerInformation1cc($params)
{

    $wcStateCode = normalizeWcStateCode($params['state_code']);

    // Update customer information.
    WC()->customer->set_props(
        array(
            'shipping_country'  => sanitize_text_field(strtoupper($params['country'])),
            'shipping_state'    => sanitize_text_field(strtoupper($wcStateCode)),
            'shipping_postcode' => sanitize_text_field($params['zipcode']),
            'shipping_city'     => sanitize_text_field($params['city']), //This field is required for custom shipping plugin support.
        )
    );

    // Calculate shipping.
    WC()->cart->calculate_shipping();
    WC()->cart->calculate_totals();

    // See if we need to calculate anything
    if (!WC()->cart->needs_shipping()) {
        return new WP_Error('shipping_methods_error', 'no shipping methods available for product and address', array('status' => 400));
    }

    // Return true for no error.
    return true;
}

/**
 * Calculate packages
 *
 * @return mixed
 */
function shippingCalculatePackages1cc($id, $orderId, $address, $rzpOrderId)
{
    // Get packages for the cart.
    $packages = WC()->cart->get_shipping_packages();

    // Currently we only support 1 shipping address per package.
    if (count($packages) > 1) {
        // Perform address check to make sure all are the same
        for ($x = 1; $x < count($packages); $x++) {
            if ($packages[0]->destination !== $packages[$x]->destination) {
                return new WP_Error('shipping_packages', 'Shipping package to > 1 address is not supported', array('status' => 400));
            }
        }
    }

    $vendorId               = array();
    $isStoreShippingEnabled = "";

    // check shipping option in multivendor plugin
    if (class_exists('WCFMmp')) {
        $shippingOptions = get_option('wcfm_shipping_options', array());
        // By default store shipping should be consider enable
        $isStoreShippingEnabled = isset($shippingOptions['enable_store_shipping']) ? $shippingOptions['enable_store_shipping'] : 'yes';
    }

    // Add package ID to array.
    foreach ($packages as $key => $package) {
        if (!isset($packages[$key]['package_id'])) {
            $packages[$key]['package_id'] = $key;
        }

        if (isset($packages[$key]['vendor_id']) && $isStoreShippingEnabled == 'yes') {
            $vendorId[] = $packages[$key]['vendor_id'];
        }
    }
    $calculatedPackages = wc()->shipping()->calculate_shipping($packages);

    return getItemResponse1cc($calculatedPackages, $id, $vendorId, $orderId, $address, $rzpOrderId);
}

/**
 * Build JSON response for line item.
 *
 * @param array $package WooCommerce shipping packages.
 * @return array
 */
function getItemResponse1cc($package, $id, $vendorId, $orderId, $address, $rzpOrderId)
{

    // Add product names and quantities.
    $items = array();

    //To support advancd free shipping plugin
    if (is_plugin_active('woocommerce-advanced-free-shipping/woocommerce-advanced-free-shipping.php')) {
        include_once ABSPATH . 'wp-content/plugins/woocommerce-advanced-free-shipping/woocommerce-advanced-free-shipping.php'; // nosemgrep: file-inclusion

        if (class_exists('Wafs_Free_Shipping_Method')) {
            $wasp_method          = new Wafs_Free_Shipping_Method();
            $advancedFreeShipping = $wasp_method->calculate_shipping($package[0]);
        }
    }

    $shippingResponse = prepareRatesResponse1cc($package, $vendorId, $orderId, $address, $rzpOrderId);

    $isServiceable = count($shippingResponse) > 0 ? true : false;
    // TODO: also return 'state'?
    return array(
        'id'           => $id,
        'zipcode'      => $package[0]['destination']['postcode'],
        'state_code'   => $package[0]['destination']['state'],
        'country'      => $package[0]['destination']['country'],
        'serviceable'  => $isServiceable,
        'cod'          => $isServiceable === false ? false : $shippingResponse['cod'],
        'shipping_fee' => isset($shippingResponse['shipping_fee']) ? ($shippingResponse['shipping_fee'] + $shippingResponse['shipping_fee_tax']) : 0,
        // hardcode as null as wc does not provide support
        'cod_fee'      => null,
    );
}

/**
 * Prepare an array of rates from a package for the response.
 *
 * @param array $package Shipping package complete with rates from WooCommerce.
 * @return array
 */
function prepareRatesResponse1cc($package, $vendorId, $orderId, $address, $rzpOrderId)
{

    $response = array();
    $order   = wc_get_order($orderId);

    if (isset($vendorId)) {
        foreach ($vendorId as $id) {
            $rates = $package[$id]['rates'];
            foreach ($rates as $rate) {
                $response[] = getRateResponse1cc($rate, $id, $orderId, $address, $order, $rzpOrderId);
            }
        }
    }

    $rates = $package[0]['rates'];
    foreach ($rates as $val) {
        $response[] = getRateResponse1cc($val, "", $orderId, $address, $order, $rzpOrderId);
    }

    if (empty($response) === true) {
        return array();
    }
    // add shipping in postmeta for multivendor plugin
    add_post_meta($orderId, '1cc_shippinginfo', $response);

    // Choosing the lowest shipping rate
    $price = array();
    foreach ($response as $key => $row) {
        $price[$key] = $row['price'];
    }
    // we only consider the lowest shipping fee
    array_multisort($price, SORT_ASC, $response);
   
    if (!empty($vendorId)) {
        foreach ($response as $key => $row) {
            $response['shipping_fee'] += isset($response[$key]['price']) ? $response[$key]['price'] : 0;
            $response['shipping_fee_tax'] += !empty($response[$key]['taxes']) ? 0 : 0; //By default tax is considered as zero.
        }
    } else {
        $response['shipping_fee']     = isset($response[0]['price']) ? $response[0]['price'] : 0;
        $response['shipping_fee_tax'] = !empty($response[0]['taxes']) ? 0 : 0; //By default tax is considered as zero.
    }

    // check shipping fee for gift card product
    if(is_plugin_active('pw-woocommerce-gift-cards/pw-gift-cards.php') || is_plugin_active('yith-woocommerce-gift-cards/init.php')){
        if(giftCardProduct($order)){
          $response['shipping_fee'] = 0;
        }
    }
    

    $response['cod'] = isset($response[0]['cod']) ? $response[0]['cod'] : false;

    return $response;
}

/**
 * Response for a single rate.
 *
 * @param WC_Shipping_Rate $rate Rate object.
 * @return array
 */

function getRateResponse1cc($rate, $vendorId, $orderId, $address, $order, $rzpOrderId)
{

    return array_merge(
        array(
            'rate_id'       => getRateProp1cc($rate, 'id'),
            'name'          => prepareHtmlResponse1cc(getRateProp1cc($rate, 'label')),
            'description'   => prepareHtmlResponse1cc(getRateProp1cc($rate, 'description')),
            'delivery_time' => prepareHtmlResponse1cc(getRateProp1cc($rate, 'delivery_time')),
            'price'         => convertToPaisa(getRateProp1cc($rate, 'cost')),
            'taxes'         => getRateProp1cc($rate, 'taxes'),
            'instance_id'   => getRateProp1cc($rate, 'instance_id'),
            'method_id'     => getRateProp1cc($rate, 'method_id'),
            'meta_data'     => getRateMetaData1cc($rate),
            'vendor_id'     => $vendorId,
            'cod'           => getCodShippingInfo1cc(getRateProp1cc($rate, 'instance_id'), getRateProp1cc($rate, 'method_id'), $orderId, $address, $order, $rzpOrderId),
        ),
        getStoreCurrencyResponse1cc()
    );
}

/**
 * Verify whether COD availbale for the shipping method
 *
 * @returns bool
 */
function getCodShippingInfo1cc($instanceId, $methodId, $orderId, $address, $order, $rzpOrderId)
{
    global $woocommerce;

    $availablePaymentMethods = WC()->payment_gateways->payment_gateways();

    $minCODAmount1cc = !empty(get_option('woocommerce_razorpay_settings')['1cc_min_COD_slab_amount']) ? get_option('woocommerce_razorpay_settings')['1cc_min_COD_slab_amount'] : 0;
    $maxCODAmount1cc = !empty(get_option('woocommerce_razorpay_settings')['1cc_max_COD_slab_amount']) ? get_option('woocommerce_razorpay_settings')['1cc_max_COD_slab_amount'] : 0;

    if (!isset($availablePaymentMethods['cod']) || 'no' == $availablePaymentMethods['cod']->enabled) {
        return false;
    }

    $amount = floatval($order->get_total());

    //To verify the min order amount required to place COD order
    if (!($minCODAmount1cc <= $amount)) {
        return false;
    }

    //To verify the max order amount restriction to place COD order
    if ($maxCODAmount1cc != 0 && ($maxCODAmount1cc <= $amount)) {
        return false;
    }

    //product and product catgaroy restriction for smart COD plugin
    if (class_exists('Wc_Smart_Cod')) {
        return smartCodRestriction($address, $order);
    }

    // Restrict shipping and payment
    if(is_plugin_active('woocommerce-conditional-shipping-and-payments/woocommerce-conditional-shipping-and-payments.php')){
        return restictPaymentGetway($rzpOrderId);
    }

    if (isset($availablePaymentMethods['cod'])) {

        $shipping_method_ids_cod = $availablePaymentMethods['cod']->enable_for_methods;

        foreach ($shipping_method_ids_cod as $shipping_method_id) {

            $shippingInstanceId[] = end(explode(':', $shipping_method_id));

            if (in_array('flat_rate', $shippingInstanceId) && ($methodId === 'flat_rate')) {
                return true;
            } elseif (in_array('free_shipping', $shippingInstanceId) && ($methodId === 'free_shipping')) {
                return true;
            } elseif (in_array($instanceId, $shippingInstanceId)) {
                return true;
            }
        }
    }
    return false;
}

function giftCardProduct($order){
    $items = $order->get_items();

    $giftProductCount = 0;
    $cartCount  = 0;
    foreach ($order->get_items() as $itemId => $item)  {
        $cartCount++;
        $product = $item->get_product();

        if($product->is_type('variation')){
             $parentProductId = $product->get_parent_id();
             $parentProduct = wc_get_product($parentProductId);
             
            if($parentProduct->get_type() == 'pw-gift-card' || $parentProduct->get_type() == 'gift-card'){
                $giftProductCount++;
            }
       }else{
            if($product->get_type() == 'pw-gift-card' || $product->get_type() == 'gift-card'){
                $giftProductCount++;
            }
             
       }
    }

    if($giftProductCount == $cartCount){
        return true;
    }

  return false;
}

/**
 * product and product catgaroy restriction for smart COD plugin
 *
 * @returns bool
 */
function smartCodRestriction($addresses, $order)
{
    $restriction         = get_option('woocommerce_cod_settings');
    $restrictionSettings = json_decode($restriction['restriction_settings']);

    $items = WC()->cart->get_cart();

    $products      = [];
    $restrictCount = 0;
    foreach ($items as $key => $item) {
        $id = isset($item['variation_id']) && $item['variation_id'] !== 0 ? $item['variation_id'] : $item['product_id'];
        array_push($products, $id);
    }

    $productCount = WC()->cart->cart_contents_count;
    $productVal   = [];

    if (empty($restriction['product_restriction'])) {
        $productVal[0] = $restriction['product_restriction'];
    } else {
        $productVal = $restriction['product_restriction'];
    }

    foreach ($products as $product_id) {
        if ($restrictionSettings->product_restriction === 0) {
            if (in_array($product_id, $productVal)) {
                if ($restriction['product_restriction_mode'] === 'one_product') {
                    return false;
                } else {
                    $restrictCount++;
                }
            }
        } else {
            if (!in_array($product_id, $productVal)) {
                if ($restriction['product_restriction_mode'] === 'all_products') {
                    return false;
                } else {
                    $restrictCount++;
                }
            }
        }

    }
    if ($restrictionSettings->product_restriction === 0) {
        if ($restriction['product_restriction_mode'] === 'all_products' && $restrictCount === $productCount) {
            return false;
        }
    } else {
        if ($restriction['product_restriction_mode'] === 'one_product' && $restrictCount === $productCount) {
            return false;
        }
    }

    // product category based restriction
    $restrictCatCount = 0;
    $productCat       = [];
    foreach ($products as $product_id) {
        $product = wc_get_product($product_id);
        $type    = $product->get_type();
        if ($type === 'variation') {
            $product = wc_get_product($product->get_parent_id());
        }

        $categoryIds = $product->get_category_ids();

        if (empty($restriction['category_restriction'])) {
            $productCat[0] = $restriction['category_restriction'];
        } else {
            $productCat = $restriction['category_restriction'];
        }
        if ($restrictionSettings->category_restriction === 0) {
            if (array_intersect($categoryIds, $productCat)) {
                if ($restriction['category_restriction_mode'] === 'one_product') {
                    return false;
                } else {
                    $restrictCatCount++;
                }
            }
        } else {
            if (!array_intersect($categoryIds, $productCat)) {
                if ($restriction['category_restriction_mode'] === 'all_products') {
                    return false;
                } else {
                    $restrictCatCount++;
                }
            }
        }
    }

    if ($restrictionSettings->category_restriction === 0) {
        if ($restriction['category_restriction_mode'] === 'all_products' && $restrictCatCount === $productCount) {
            return false;
        }
    } else {
        if ($restriction['category_restriction_mode'] === 'one_product' && $restrictCatCount === $productCount) {
            return false;
        }
    }

    // zip code restriction
    $postals         = explode(',', trim($restriction['restrict_postals']));
    $postals         = array_map('trim', $postals);
    $customerZipcode = $addresses['zipcode'];
    $flag            = 0;

    foreach ($postals as $p) {
        if (!$p) {
            continue;
        }
        $prepare = explode('...', $p);
        $count   = count($prepare);

        if ($count === 1) {
            // single
            if ($prepare[0] === $customerZipcode) {
                if ($restrictionSettings->restrict_postals === 0) {
                    return false;
                } else {
                    $flag = 1;
                }
            }

        } elseif ($count === 2) {
            // range
            if (!is_numeric($prepare[0]) || !is_numeric($prepare[1]) || !is_numeric($customerZipcode)) {
                continue;
            }

            if ($customerZipcode >= $prepare[0] && $customerZipcode <= $prepare[1]) {
                if ($restrictionSettings->restrict_postals === 0) {
                    return false;
                } else {
                    $flag = 1;
                }
            }
        } else {
            continue;
        }
    }

    if ($restrictionSettings->restrict_postals === 1 && $flag !== 1) {
        return false;
    }

    // country based restriction
    $country = strtoupper($addresses['country']);
    if (!empty($restriction['country_restrictions'])) {
        if ($restrictionSettings->country_restrictions === 0) {
            if (in_array($country, $restriction['country_restrictions'])) {
                return false;
            }
        } else {
            if (!in_array($country, $restriction['country_restrictions'])) {
                return false;
            }
        }
    }

    // state based restriction
    $stateCode = normalizeWcStateCode($addresses['state_code']);
    $state     = $country . '_' . $stateCode;
    if (!empty($restriction['state_restrictions'])) {
        if ($restrictionSettings->state_restrictions === 0) {
            if (in_array($state, $restriction['state_restrictions'])) {
                return false;
            }
        } else {
            if (!in_array($state, $restriction['state_restrictions'])) {
                return false;
            }
        }

    }

    // city based restriction
    if (!empty($restriction['city_restrictions'])) {
        $cityRes         = explode(',', trim($restriction['city_restrictions']));
        $restrict        = array_map('trim', $cityRes);
        $cityRestriction = array_map('strtolower', $restrict);
        if ($restrictionSettings->city_restrictions === 0) {
            if (in_array($addresses['city'], $cityRestriction)) {
                return false;
            }
        } else {
            if (!in_array($addresses['city'], $cityRestriction)) {
                return false;
            }
        }

    }

    // shipping zone based restriction
    if (!empty($restriction['shipping_zone_restrictions'])) {
        $items                = WC()->cart->get_cart();
        $package              = WC()->cart->get_shipping_packages();
        $customerShippingZone = WC_Shipping_Zones::get_zone_matching_package($package[0]);
        if ($restrictionSettings->shipping_zone_restrictions === 0) {
            if (in_array($customerShippingZone->get_id(), $restriction['shipping_zone_restrictions'])) {
                return false;
            }
        } else {
            if (!in_array($customerShippingZone->get_id(), $restriction['shipping_zone_restrictions'])) {
                return false;
            }
        }

    }

    // user role restriction
    $user = get_user_by('id', $order->get_user_id());
    $role = !empty($user) ? $user->roles : [];
    if (!empty($restriction['user_role_restriction']) && !empty($role)) {
        if ($restrictionSettings->user_role_restriction === 0) {
            if (array_intersect($restriction['user_role_restriction'], $role)) {
                return false;
            }
        } else {
            if (!array_intersect($restriction['user_role_restriction'], $role)) {
                return false;
            }
        }

    }

    // shipping class restriction
    if (!empty($restriction['shipping_class_restriction'])) {
        $restrictClassCount = 0;
        foreach ($products as $product_id) {
            $product         = wc_get_product($product_id);
            $shippingClassId = $product->get_shipping_class_id();
            if ($restrictionSettings->shipping_class_restriction === 0) {
                if (in_array($shippingClassId, $restriction['shipping_class_restriction'])) {
                    if ($restriction['shipping_class_restriction_mode'] === 'one_product') {
                        return false;
                    } else {
                        $restrictClassCount++;
                    }
                }
            } else {
                if (!in_array($shippingClassId, $restriction['shipping_class_restriction'])) {
                    if ($restriction['shipping_class_restriction_mode'] === 'all_products') {
                        return false;
                    } else {
                        $restrictClassCount++;
                    }
                }
            }

        }

        if ($restrictionSettings->shipping_class_restriction === 0) {
            if ($restriction['shipping_class_restriction_mode'] === 'all_products' && $restrictClassCount === $productCount) {
                return false;
            }
        } else {
            if ($restriction['shipping_class_restriction_mode'] === 'one_product' && $restrictClassCount === $productCount) {
                return false;
            }
        }

    }

    return true;
}


function restictPaymentGetway($rzpOrderId){

    // fetch coupon detail from rzp object
    $razorpay = new WC_Razorpay(false);
    $api      = $razorpay->getRazorpayApiInstance();

    try
    {
        $razorpayData = $api->order->fetch($rzpOrderId);

    } catch (Exception $e) {

        return true;
    }

    foreach($razorpayData['promotions'] as $promotion)
    {
        if($promotion['type'] != 'gift_card'){
            $couponCode = $promotion['code'];
        }
    }

    if(empty($couponCode) === false) {

        $globalRes = new WC_CSP_Restrict_Payment_Gateways();
        $globalRestrictionData = $globalRes->get_global_restriction_data();

        if(in_array('cod', $globalRestrictionData[0]['gateways'])) {
            foreach($globalRestrictionData[0]['conditions'] as $conditionData) {

                if($conditionData['condition_id'] == 'coupon_code_used') {
                    if(in_array($couponCode,$conditionData['value'])) {
                        return false;
                    }
                    
                }
            }
                
        }
    }
    
    return true; 
}

/**
 * Convert to paisa
 * @returns int
 */
function convertToPaisa($price)
{
    if (is_string($price)) {
        $price = (int) $price;
    }
    return $price * 100;
}

/**
 * Prepares a list of store currency data to return in responses.
 * @return array
 */
function getStoreCurrencyResponse1cc()
{
    $position = get_option('woocommerce_currency_pos');
    $symbol   = html_entity_decode(get_woocommerce_currency_symbol());
    $prefix   = '';
    $suffix   = '';

    switch ($position) {
        case 'left_space':
            $prefix = $symbol . ' ';
            break;
        case 'left':
            $prefix = $symbol;
            break;
        case 'right_space':
            $suffix = ' ' . $symbol;
            break;
        case 'right':
            $suffix = $symbol;
            break;
        default:
            break;
    }

    return array(
        'currency_code'               => get_woocommerce_currency(),
        'currency_symbol'             => $symbol,
        'currency_minor_unit'         => wc_get_price_decimals(),
        'currency_decimal_separator'  => wc_get_price_decimal_separator(),
        'currency_thousand_separator' => wc_get_price_thousand_separator(),
        'currency_prefix'             => $prefix,
        'currency_suffix'             => $suffix,
    );
}

/**
 * Gets a prop of the rate object, if callable.
 *
 * @param WC_Shipping_Rate $rate Rate object.
 * @param string           $prop Prop name.
 * @return string
 */
function getRateProp1cc($rate, $prop)
{
    $getter = 'get_' . $prop;
    return \is_callable(array($rate, $getter)) ? $rate->$getter() : '';
}

/**
 * Converts rate meta data into a suitable response object.
 *
 * @param WC_Shipping_Rate $rate Rate object.
 * @return array
 */
function getRateMetaData1cc($rate)
{
    $metaData = $rate->get_meta_data();

    return array_reduce(
        array_keys($metaData),
        function ($return, $key) use ($metaData) {
            $return[] = array(
                'key'   => $key,
                'value' => $metaData[$key],
            );
            return $return;
        },
        array()
    );
}

/**
 * Prepares HTML based content, such as post titles and content, for the API response.
 *
 * The wptexturize, convert_chars, and trim functions are also used in the `the_title` filter.
 * The function wp_kses_post removes disallowed HTML tags.
 *
 * @param string|array $response Data to format.
 * @return string|array Formatted data.
 */
function prepareHtmlResponse1cc($response)
{
    if (is_array($response)) {
        return array_map('prepareHtmlResponse1cc', $response);
    }
    return is_scalar($response) ? wp_kses_post(trim(convert_chars(wptexturize($response)))) : $response;
}

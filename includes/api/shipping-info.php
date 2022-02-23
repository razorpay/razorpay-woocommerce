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
            $response[] = shippingCalculatePackages1cc($address['id'], $orderId);
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
function shippingCalculatePackages1cc($id, $orderId)
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

    return getItemResponse1cc($calculatedPackages, $id, $vendorId, $orderId);
}

/**
 * Build JSON response for line item.
 *
 * @param array $package WooCommerce shipping packages.
 * @return array
 */
function getItemResponse1cc($package, $id, $vendorId, $orderId)
{

    // Add product names and quantities.
    $items = array();

    //To support advancd free shipping plugin
    if (is_plugin_active('woocommerce-advanced-free-shipping/woocommerce-advanced-free-shipping.php')) {
        include_once ABSPATH . 'wp-content/plugins/woocommerce-advanced-free-shipping/woocommerce-advanced-free-shipping.php';

        if (class_exists('Wafs_Free_Shipping_Method')) {
            $wasp_method          = new Wafs_Free_Shipping_Method();
            $advancedFreeShipping = $wasp_method->calculate_shipping($package[0]);
        }
    }

    $shippingResponse = prepareRatesResponse1cc($package, $vendorId, $orderId);

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
function prepareRatesResponse1cc($package, $vendorId, $orderId)
{

    $response = array();

    if (isset($vendorId)) {
        foreach ($vendorId as $id) {
            $rates = $package[$id]['rates'];
            foreach ($rates as $rate) {
                $response[] = getRateResponse1cc($rate, $id, $orderId);
            }
        }
    }

    $rates = $package[0]['rates'];
    foreach ($rates as $val) {
        $response[] = getRateResponse1cc($val, "", $orderId);
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
    $response['cod'] = isset($response[0]['cod']) ? $response[0]['cod'] : false;

    return $response;
}

/**
 * Response for a single rate.
 *
 * @param WC_Shipping_Rate $rate Rate object.
 * @return array
 */

function getRateResponse1cc($rate, $vendorId, $orderId)
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
            'cod'           => getCodShippingInfo1cc(getRateProp1cc($rate, 'instance_id'), getRateProp1cc($rate, 'method_id'), $orderId),
        ),
        getStoreCurrencyResponse1cc()
    );
}

/**
 * Verify whether COD availbale for the shipping method
 *
 * @returns bool
 */
function getCodShippingInfo1cc($instanceId, $methodId, $orderId)
{

    global $woocommerce;

    $availablePaymentMethods = WC()->payment_gateways->payment_gateways();

    if (!isset($availablePaymentMethods['cod']) || 'no' == $availablePaymentMethods['cod']->enabled || !(($minCODAmount1cc <= $amount) && ($maxCODAmount1cc <= $amount))) {
        return false;
    }

    $minCODAmount1cc = !empty(get_option('woocommerce_razorpay_settings')['1cc_min_COD_slab_amount']) ? get_option('woocommerce_razorpay_settings')['1cc_min_COD_slab_amount'] : 0;
    $maxCODAmount1cc = !empty(get_option('woocommerce_razorpay_settings')['1cc_max_COD_slab_amount']) ? get_option('woocommerce_razorpay_settings')['1cc_max_COD_slab_amount'] : 0;

    $order  = wc_get_order($orderId);
    $amount = floatval($order->get_total());

    //To verify the min order amount required to place COD order
    if (!($minCODAmount1cc <= $amount)) {
        return false;
    }

    //To verify the max order amount restriction to place COD order
    if ($maxCODAmount1cc != 0 && ($maxCODAmount1cc <= $amount)) {
        return false;
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

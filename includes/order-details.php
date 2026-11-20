<?php

/**
 * Builds the customer and order details that are sent to Razorpay while
 * creating an order.
 *
 * The values are read from the WooCommerce order at order-creation time and are
 * passed to the Razorpay order create API as `line_items`, `line_items_total`,
 * `shipping_fee` and `customer_details`, so downstream risk and post-payment
 * flows have the full order context without another round trip.
 */

/**
 * Razorpay accepts all amounts in the smallest currency unit (paise).
 *
 * @param  float|int|null $amount
 * @return int
 */
function rzpToSmallestCurrencyUnit($amount)
{
    return (int) round((float) ($amount ?? 0) * 100);
}

/**
 * Builds the line items for an order.
 *
 * `price` is the pre-discount per-unit price and `offer_price` is the per-unit
 * price the customer actually paid, both exclusive of tax so they stay
 * consistent with `line_items_total`.
 *
 * @param  WC_Order $order
 * @return array
 */
function rzpBuildOrderLineItems($order)
{
    $lineItems = [];

    foreach ($order->get_items() as $item)
    {
        $product  = $item->get_product();
        $quantity = (int) $item->get_quantity();

        if (empty($product) || $quantity <= 0)
        {
            continue;
        }

        $name       = mb_substr((string) $item->get_name(), 0, 125, 'UTF-8');
        $price      = max(0, rzpToSmallestCurrencyUnit($item->get_subtotal() / $quantity));
        $offerPrice = rzpToSmallestCurrencyUnit($item->get_total() / $quantity);
        // Coupon discounts do not change the product price, so cap the offer
        // price at the price used for `price` to keep offer_price <= price.
        $offerPrice = max(0, min($offerPrice, $price));
        $imageId    = $product->get_image_id();

        $lineItems[] = [
            'type'        => 'e-commerce',
            'sku'         => (string) $product->get_sku(),
            'variant_id'  => (string) ($item->get_variation_id() ?: $product->get_id()),
            'price'       => $price,
            'offer_price' => $offerPrice,
            'tax_amount'  => max(0, rzpToSmallestCurrencyUnit($item->get_total_tax() / $quantity)),
            'quantity'    => $quantity,
            'name'        => $name,
            'description' => $name,
            'image_url'   => $imageId ? (string) wp_get_attachment_url($imageId) : '',
            'product_url' => (string) $product->get_permalink(),
        ];
    }

    return $lineItems;
}

/**
 * Sum of the line item totals after discounts, exclusive of tax.
 *
 * @param  WC_Order $order
 * @return int
 */
function rzpBuildOrderLineItemsTotal($order)
{
    $total = 0;

    foreach ($order->get_items() as $item)
    {
        $total += rzpToSmallestCurrencyUnit($item->get_total());
    }

    return $total;
}

/**
 * Builds a billing or shipping address in the shape Razorpay expects.
 *
 * @param  WC_Order $order
 * @param  string   $type billing|shipping
 * @return array
 */
function rzpBuildOrderAddressDetails($order, $type)
{
    $country   = strtoupper((string) call_user_func([$order, "get_{$type}_country"]));
    $stateCode = (string) call_user_func([$order, "get_{$type}_state"]);
    $state     = $stateCode;

    if ($country !== '' && $stateCode !== '' &&
        function_exists('WC') && WC()->countries)
    {
        $states = WC()->countries->get_states($country);

        if (is_array($states) && isset($states[$stateCode]))
        {
            $state = $states[$stateCode];
        }
    }

    return [
        'line1'     => (string) call_user_func([$order, "get_{$type}_address_1"]),
        'line2'     => (string) call_user_func([$order, "get_{$type}_address_2"]),
        'city'      => (string) call_user_func([$order, "get_{$type}_city"]),
        'state'     => $state,
        'zipcode'   => (string) call_user_func([$order, "get_{$type}_postcode"]),
        'country'   => rzpGetIso3CountryCode($country),
        'name'      => trim(
            call_user_func([$order, "get_{$type}_first_name"]) . ' ' .
            call_user_func([$order, "get_{$type}_last_name"])
        ),
        'contact'   => (string) call_user_func([$order, "get_{$type}_phone"]),
        'latitude'  => null,
        'longitude' => null,
    ];
}

/**
 * Builds the `customer_details` object for an order.
 *
 * @param  WC_Order $order
 * @return array
 */
function rzpBuildOrderCustomerDetails($order)
{
    $billing  = rzpBuildOrderAddressDetails($order, 'billing');
    $shipping = strtoupper((string) $order->get_shipping_country()) !== ''
        ? rzpBuildOrderAddressDetails($order, 'shipping')
        : $billing;

    $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    if ($name === '')
    {
        $name = $billing['name'] !== '' ? $billing['name'] : $shipping['name'];
    }

    $contact = (string) $order->get_billing_phone();
    if ($contact === '' && method_exists($order, 'get_shipping_phone'))
    {
        $contact = (string) $order->get_shipping_phone();
    }

    return [
        'name'             => $name,
        'contact'          => $contact,
        'email'            => (string) $order->get_billing_email(),
        'insights'         => [
            'has_account'  => $order->get_user_id() > 0,
        ],
        'billing_address'  => $billing,
        'shipping_address' => $shipping,
    ];
}

/**
 * Adds the customer, line item and shipping details to the order create payload.
 *
 * Never blocks order creation: if any detail cannot be built, the payload is
 * returned unchanged so the payment flow can continue.
 *
 * @param  array    $data
 * @param  WC_Order $order
 * @return array
 */
function rzpAddOrderDetails($data, $order)
{
    try
    {
        if (strtoupper((string) $order->get_billing_country()) !== '')
        {
            $data['customer_details'] = rzpBuildOrderCustomerDetails($order);
        }

        if (empty($data['line_items']))
        {
            $lineItems = rzpBuildOrderLineItems($order);

            if (empty($lineItems) === false)
            {
                $data['line_items']       = $lineItems;
                $data['line_items_total'] = rzpBuildOrderLineItemsTotal($order);
            }
        }

        if (isset($data['shipping_fee']) === false)
        {
            $data['shipping_fee'] = rzpToSmallestCurrencyUnit($order->get_shipping_total())
                + rzpToSmallestCurrencyUnit($order->get_shipping_tax());
        }
    }
    catch (Throwable $e)
    {
        rzpLogError('Failed to build Razorpay order details: ' . $e->getMessage());
    }

    return $data;
}

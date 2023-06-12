<?php

/**
 * for coupon related API
 */

/**
 * create cart from "order_id" using $cart_1cc = create1ccCart($merchant_order_id);
 * get total amount of items from the cart (replace $amount with that)
 * run the query for coupons
 * apply checks for expired coupons (see GMT time)
 * check for min cart amount condition
 * check if the email exists in `$coupon->get_email_restrictions())`
 * convert all amounts to int in paise
 * return whatever clears
 */
function getCouponList($request)
{
    global $woocommerce;
    $couponData = [];

    $params           = $request->get_params();
    $logObj           = array();
    $logObj['api']    = 'getCouponList';
    $logObj['params'] = $params;

    $orderId = sanitize_text_field($request->get_params()['order_id']);
    $order   = wc_get_order($orderId);

    if (!$order) {
        $response['failure_reason'] = 'Invalid merchant order id';
        $response['failure_code']   = 'VALIDATION_ERROR';
        $statusCode                 = 400;
        $logObj['response']         = $response;
        $logObj['status_code']      = $statusCode;
        rzpLogError(json_encode($logObj));
        return new WP_REST_Response($response, $statusCode);
    }

    $amount  = floatval($order->get_total());
    $email   = sanitize_text_field($request->get_params()['email']);
    $contact = sanitize_text_field($request->get_params()['contact']);

    //Updating the email address to wc order.
    if (empty($email) == false) {
        update_post_meta($orderId, '_billing_email', $email);
        update_post_meta($orderId, '_shipping_email', $email);
    }

    if (empty($contact) == false) {
        update_post_meta($orderId, '_billing_phone', $contact);
        update_post_meta($orderId, '_shipping_phone', $contact);
    }

    $args = array(
        'post_type'      => 'shop_coupon',
        'orderby'        => 'title',
        'order'          => 'ASC',
        'meta_query'     => array(
            array(
                'key'     => 'discount_type',
                'value'   => array('fixed_cart', 'percent', 'fixed_product'),
                'compare' => 'IN',
            ),
            array(
                'key'     => 'coupon_generated_by',
                'compare' => 'NOT EXISTS',
            ),
        ),
        'fields'         => 'ids',
        'posts_per_page' => -1, // By default WP_Query will return only 10 posts, to avoid that we need to pass -1
    );

    //check woo-discount-rule plugin disabling the coupons
    if (is_plugin_active('woo-discount-rules/woo-discount-rules.php')) {
        $discountOptions = get_option('woo-discount-config-v2', []);
        if (!empty($discountOptions)) {
            $isCouponEnabled = $discountOptions['disable_coupon_when_rule_applied'];
            if ($isCouponEnabled == 'disable_coupon') {
                $args = array();
            }
        }
    }

    $coupons = new WP_Query($args);

    $couponData['promotions'] = array();

    if ($coupons->have_posts() && 'yes' === get_option('woocommerce_enable_coupons')) {
        while ($coupons->have_posts()) {
            $coupons->the_post();
            $coupon           = new WC_Coupon(get_the_ID());
            $items            = $order->get_items();
            $couponMinAmount  = floatval($coupon->get_minimum_amount());
            $couponMaxAmount  = floatval($coupon->get_maximum_amount());
            $couponExpiryDate = $coupon->get_date_expires() ? $coupon->get_date_expires()->getTimestamp() : null;

            //check coupon description
            if (empty($coupon->get_description()) === true) {
                continue;
            }

            // validation for email coupon
            if (empty($coupon->get_email_restrictions()) === false) {
                if (empty($email) === true || in_array($email, $coupon->get_email_restrictions()) === false) {
                    continue;
                }
            }

            if (
                ($amount < $couponMinAmount)
                || (($amount > $couponMaxAmount) && ($couponMaxAmount != 0))
                || ($couponExpiryDate !== null && $couponExpiryDate < time())
            ) {
                continue;
            }

            // Get usage count
            $count = $coupon->get_usage_count();
            // Get coupon limit
            $limit = $coupon->get_usage_limit();

            if (!empty($count) && !empty($limit)) {
                // Calculate remaining
                $remaining = $limit - $count;
                if ($remaining <= 0) {
                    continue;
                }
            }

            // Get coupon usage limit per user
            $userLimit = $coupon->get_usage_limit_per_user();

            if (!empty($userLimit)) {
                $dataStore  = $coupon->get_data_store();
                $usageCount = $order->get_customer_id() ? $dataStore->get_usage_by_user_id($coupon, $order->get_customer_id()) : $dataStore->get_usage_by_email($coupon, $email);

                if (!empty($usageCount) && !empty($userLimit)) {
                    // Calculate remaining
                    $remainingCount = $userLimit - $usageCount;
                    if ($remainingCount <= 0) {
                        continue;
                    }
                }
            }

            // Add item based coupons
            if (count($coupon->get_product_ids()) > 0) {
                $valid = false;
                // TODO: fix this logic
                foreach ($items as $item) {
                    if (in_array($item->get_product_id(), $coupon->get_product_ids()) || in_array($item->get_variation_id(), $coupon->get_product_ids())) {
                        $valid = true;
                        break;
                    }
                }
                if (!$valid) {
                    continue;
                }
            }

            // Exclude item based coupons
            if (count($coupon->get_excluded_product_ids()) > 0) {
                $valid = false;
                foreach ($items as $item) {
                    if (in_array($item->get_product_id(), $coupon->get_excluded_product_ids()) || in_array($item->get_variation_id(), $coupon->get_excluded_product_ids())) {
                        $valid = true;
                        break;
                    }
                }
                if ($valid) {
                    continue;
                }
            }

            // include and exclude product category items
            if (count($coupon->get_excluded_product_categories()) > 0) {
                $categories = array();
                foreach ($items as $item) {
                    $product_cats = wc_get_product_cat_ids($item->get_product_id());
                    $cat_id_list  = array_intersect($product_cats, $coupon->get_excluded_product_categories());
                    if (count($cat_id_list) > 0) {
                        foreach ($cat_id_list as $cat_id) {
                            $cat          = get_term($cat_id, 'product_cat');
                            $categories[] = $cat->name;
                        }
                    }
                }

                if (!empty($categories)) {
                    continue;
                }
            }

            if (count($coupon->get_product_categories()) > 0) {
                $valid = false;
                foreach ($items as $item) {
                    $product_cats = wc_get_product_cat_ids($item->get_product_id());
                    if (count(array_intersect($product_cats, $coupon->get_product_categories())) > 0) {
                        $valid = true;
                        break;
                    }
                }

                if (!$valid) {
                    continue;
                }
            }

            // exclude sale item from coupons
            if ($coupon->get_exclude_sale_items()) {
                $valid = false;
                foreach ($items as $item) {
                    $product = new WC_Product($item->get_product_id());
                    if ($product->is_on_sale()) {
                        $valid = true;
                        break;
                    }
                }

                if ($valid) {
                    continue;
                }
            }

            // Check for smart coupon plugin
            if (is_plugin_active('wt-smart-coupons-for-woocommerce/wt-smart-coupon.php')) {
                initCustomerSessionAndCart();
                // Cleanup cart.
                WC()->cart->empty_cart();
                create1ccCart($orderId);

                $items = WC()->cart->get_cart();

                // Quantity of matching Products
                $minMatchingProductQty = get_post_meta($coupon->get_id(), '_wt_min_matching_product_qty', true);
                $maxMatchingProductQty = get_post_meta($coupon->get_id(), '_wt_max_matching_product_qty', true);

                if ($minMatchingProductQty > 0 || $maxMatchingProductQty > 0) {
                    $quantityMatchingProduct = getQuantityOfProduct($coupon, $items,[], []);
                    if ($minMatchingProductQty > 0 && $quantityMatchingProduct < $minMatchingProductQty) {
                        continue;
                    }
                    if ($maxMatchingProductQty > 0 && $quantityMatchingProduct > $maxMatchingProductQty) {
                        continue;
                    }
                }

                //Subtotal of matching products
                $minMatchingProductSubtotal = get_post_meta($coupon->get_id(), '_wt_min_matching_product_subtotal', true);
                $maxMatchingProductSubtotal = get_post_meta($coupon->get_id(), '_wt_max_matching_product_subtotal', true);

                if ($minMatchingProductSubtotal !== 0 || $maxMatchingProductSubtotal !== 0) {
                    $subtotalMatchingProduct = getSubTotalOfProducts($coupon, $items, [], []);
                    if ($minMatchingProductSubtotal > 0 && $subtotalMatchingProduct < $minMatchingProductSubtotal) {
                        continue;
                    }
                    if ($maxMatchingProductSubtotal > 0 && $subtotalMatchingProduct > $maxMatchingProductSubtotal) {
                        continue;
                    }
                }

                // User role restriction
                $userRoles = get_post_meta($coupon->get_id(), '_wt_sc_user_roles', true);
                if ('' != $userRoles && !is_array($userRoles)) {
                    $userRoles = explode(',', $userRoles);
                } else {
                    $userRoles = array();
                }

                if (sizeof($userRoles) > 0) {
                    if (empty($email) === false) {
                        $user = get_user_by('email', $email);
                        $role = !empty($user) ? $user->roles : [];

                        if (!array_intersect($userRoles, $role)) {
                            continue;
                        }
                    } else {
                        continue;
                    }
                }
            }

            $couponData['promotions'][] = transformCouponResponse($coupon);
        }
    }

    $logObj['response']    = $couponData;
    $statusCode            = 200;
    $logObj['status_code'] = $statusCode;
    rzpLogInfo(json_encode($logObj));
    return new WP_REST_Response($couponData, $statusCode);
}

function transformCouponResponse($coupon)
{
    return array(
        'code'    => $coupon->get_code(),
        'summary' => $coupon->get_description(),
        'tnc'     => [],
    );
}

function transformAmountForRzp($amount)
{
    return wc_format_decimal($amount, 2) * 100;
}

function getQuantityOfProduct($coupon, $items, $couponProducts, $couponCategories, $couponExcludeProducts = array(), $couponExcludeCategories = array())
{
    global $woocommerce;        
    $qty = 0;

    $isProductRestrictionEnabled=count($couponProducts)>0;
    $isCategoryRestrictionEnabled=count($couponCategories)>0;

    foreach($items as $item) {
        if(isset($item['free_product']) && "wt_give_away_product"===$item['free_product']) {
            continue;
        }

        $itemQuantity=0; //always reset to zero on loop start
        
        if($isProductRestrictionEnabled) {
            if(in_array($item['product_id'], $couponProducts) || in_array($item['variation_id'], $couponProducts))
            {
                $itemQuantity = $item['quantity'];
            }
        }

        if(0===$itemQuantity && $isCategoryRestrictionEnabled) /* not already in coupon products and category restriction enabled */
        {
            $productCats = wc_get_product_cat_ids($item['product_id']);

            if(count(array_intersect($couponCategories, $productCats))>0) { 
                if(0 === count(array_intersect($couponExcludeCategories, $productCats))) {
                    $itemQuantity = $item['quantity'];
                }     
            }
        }


        if(!$isProductRestrictionEnabled && !$isCategoryRestrictionEnabled) {
            $productCats = wc_get_product_cat_ids($item['product_id']);
            
            if(!empty($couponExcludeCategories) || !empty($couponExcludeProducts)) {

                if(in_array($item['product_id'], $couponExcludeProducts) || in_array($item['variation_id'], $couponExcludeProducts))
                {
                    continue;

                } elseif (0 < count(array_intersect($couponExcludeCategories, $productCats))) {
                    continue;
                } else {
                    //not included in excluded product/category
                    $itemQuantity = $item['quantity'];
                }

            } else {
                $itemQuantity = $item['quantity'];
            }

        }

        $qty += $itemQuantity;              
    }

    return $qty;
}

function getSubTotalOfProducts($coupon,$items, $couponProducts, $couponCategories)
{
    global $woocommerce;
    $total = 0;

    $isProductRestrictionEnabled=count($couponProducts)>0;
    $isCategoryRestrictionEnabled=count($couponCategories)>0;

    if($isProductRestrictionEnabled || $isCategoryRestrictionEnabled) // check with matching products by include condition.
    { 
        foreach($items as $item) {   

            if(isset($item['free_product']) && "wt_give_away_product" === $item['free_product']) {
                continue;
            }
            
            $productCats = wc_get_product_cat_ids($item['product_id']);

            if(($isProductRestrictionEnabled && in_array($item['product_id'], $couponProducts)) ||  ($isCategoryRestrictionEnabled && count(array_intersect($couponCategories,$productCats)) > 0)) {                  
                $total += (float) $item['data']->get_price() * (int) $item['quantity'];
            }          
        }

    } else {
        foreach($items as $item) {
            $total += (float) $item['data']->get_price() * (int) $item['quantity'];
        }
    }

    return $total;
}

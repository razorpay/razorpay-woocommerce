<?php

/**
 * for coupon related API
 */

function applyCouponOnCart(WP_REST_Request $request)
{
    global $woocommerce;

    $status         = 400;
    $failure_reason = "";

    $params = $request->get_params();

    $logObj           = [];
    $logObj["api"]    = "applyCouponOnCart";
    $logObj["params"] = $params;

    $validateInput = validateApplyCouponApi($params);

    if ($validateInput != null) {
        $response["failure_reason"] = $validateInput;
        $response["failure_code"]   = "VALIDATION_ERROR";
        $logObj["response"]         = $response;

        rzpLogError(json_encode($logObj));

        return new WP_REST_Response($response, 400);
    }

    $couponCode = sanitize_text_field($params["code"]);
    $email      = sanitize_text_field($params["email"]) ?? "";
    $orderId    = sanitize_text_field($params["order_id"]);

    // initializes the session
    initCustomerSessionAndCart();

    // Set current user for smart coupon plugin
    if (is_plugin_active('wt-smart-coupons-for-woocommerce/wt-smart-coupon.php')) {
        if (empty($email) === false) {
            $user = get_user_by('email', $email);
            wp_set_current_user($user->id);
        }
    }

    // check for individual specific coupons
    // cart->apply does not enforce this
    $coupon = new WC_Coupon($couponCode);

    // check the enable coupon option
    if (get_option("woocommerce_enable_coupons") === "no") {
        $response["failure_reason"] = "Coupon feature disabled";
        $response["failure_code"]   = "INVALID_COUPON";
        $logObj["response"]         = $response;

        rzpLogError(json_encode($logObj));

        return new WP_REST_Response($response, 400);
    }

    //check woo-discount-rule plugin disabling the coupons
    if (is_plugin_active('woo-discount-rules/woo-discount-rules.php')) {
        $discountOptions = get_option('woo-discount-config-v2', []);
        if (!empty($discountOptions)) {
            $isCouponEnabled = $discountOptions['disable_coupon_when_rule_applied'];
            if ($isCouponEnabled == 'disable_coupon') {
                $response["failure_reason"] = "Coupon feature disabled";
                $response["failure_code"]   = "INVALID_COUPON";
                $logObj["response"]         = $response;

                rzpLogError(json_encode($logObj));

                return new WP_REST_Response($response, 400);
            }
        }
    }

    if (empty($coupon->get_email_restrictions()) === false) {
        if ($email == "") {
            $response["failure_reason"] = "User email is required";
            $response["failure_code"]   = "LOGIN_REQUIRED";
            $logObj["response"]         = $response;

            rzpLogError(json_encode($logObj));

            return new WP_REST_Response($response, 400);
        } elseif (in_array($email, $coupon->get_email_restrictions()) === false) {
            $response["failure_reason"] = "Coupon does not exist";
            $response["failure_code"]   = "INVALID_COUPON";
            $logObj["response"]         = $response;

            rzpLogError(json_encode($logObj));

            return new WP_REST_Response($response, 400);
        }
    }

    // Get coupon usage limit per user
    $userLimit = $coupon->get_usage_limit_per_user();
    if (!empty($userLimit)) {
        $dataStore  = $coupon->get_data_store();
        $order      = wc_get_order($orderId);
        $usageCount = $order->get_customer_id() ? $dataStore->get_usage_by_user_id($coupon, $order->get_customer_id()) : $dataStore->get_usage_by_email($coupon, $email);

        if (!empty($usageCount) && !empty($userLimit)) {
            // Calculate remaining
            $remainingCount = $userLimit - $usageCount;

            if ($remainingCount <= 0) {
                $response["failure_reason"] = "Coupon usage limit has been reached";
                $response["failure_code"]   = "REQUIREMENT_NOT_MET";
                $logObj["response"]         = $response;

                rzpLogError(json_encode($logObj));

                return new WP_REST_Response($response, 400);
            }
        }
    }

    // to clear any residual notices
    $temp = wc_print_notices(true);

    WC()->cart->empty_cart();

    $cart1cc = create1ccCart($orderId);

    WC()->cart->remove_coupon($couponCode);

    if ($cart1cc) {
        $applyCoupon = WC()->cart->add_discount($couponCode);

        if ($applyCoupon === true) {
            $status = true;
        } else {
            $markup       = wc_print_notices(true);
            $errorArray   = explode("<li>", $markup);
            $errorMessage = preg_replace(
                "/\t|\n/",
                "",
                strip_tags(end($errorArray))
            );
            $failureReason = html_entity_decode($errorMessage);
        }
    } else {
        $invalidCartResponse                   = [];
        $invalidCartResponse["failure_reason"] = "Invalid merchant order id";
        $invalidCartResponse["failure_code"]   = "VALIDATION_ERROR";

        $logObj["response"] = $invalidCartResponse;

        rzpLogError(json_encode($logObj));
        return new WP_REST_Response($invalidCartResponse, 400);
    }

    $newAmount      = (WC()->cart->cart_contents_total + WC()->cart->tax_total) * 100;
    $discountAmount = (WC()->cart->get_cart_discount_tax_total() + WC()->cart->get_cart_discount_total()) * 100;

    $couponError = getApplyCouponErrorCodes($failureReason);

    $promotion                 = [];
    $promotion["code"]         = $couponCode;
    $promotion["reference_id"] = $couponCode;
    $promotion["value"]        = round($discountAmount ?? 0);
    $response["promotion"]     = $promotion;

    if ($couponError["failure_reason"] === "") {
        $logObj["response"] = $response;
        rzpLogInfo(json_encode($logObj));
        return new WP_REST_Response($response, 200);
    } else {
        $logObj["response"] = array_merge($response, $couponError);
        rzpLogError(json_encode($logObj));
        return new WP_REST_Response($couponError, 400);
    }
}

function getApplyCouponErrorCodes($failureMessage)
{
    $result                 = [];
    $result["failure_code"] = "REQUIREMENT_NOT_MET";

    if ($failureMessage === "" || is_null($failureMessage)) {
        $result["failure_reason"] = "";
        $result["failure_code"]   = "";
    } elseif (stripos($failureMessage, "does not exist") !== false) {
        $result["failure_reason"] = "Coupon does not exist";
        $result["failure_code"]   = "INVALID_COUPON";
    } elseif (stripos($failureMessage, "coupon has expired") !== false) {
        $result["failure_reason"] = "This coupon has expired";
    } elseif (stripos($failureMessage, "minimum spend") !== false) {
        $result["failure_reason"] = "Cart is below the minimum amount";
    } elseif (stripos($failureMessage, "maximum spend") !== false) {
        $result["failure_reason"] = "Cart is above maximum allowed amount";
    } elseif (
        stripos($failureMessage, "not applicable to selected products") !==
        false
    ) {
        $result["failure_reason"] = "Required product is missing";
    } elseif (
        stripos($failureMessage, "Coupon usage limit has been reached") !==
        false
    ) {
        $result["failure_reason"] = "Coupon usage limit has been reached";
    } else {
        $result["failure_reason"] = "Coupon could not be applied";
        $result["failure_code"]   = "INVALID_COUPON";
    }

    return $result;
}

function validateApplyCouponApi($param)
{
    $failureReason = null;
    if (empty(sanitize_text_field($param["code"])) === true) {
        $failureReason = "Field code is required.";
    } elseif (empty(sanitize_text_field($param["order_id"])) === true) {
        $failureReason = "Field order id is required.";
    }
    return $failureReason;
}

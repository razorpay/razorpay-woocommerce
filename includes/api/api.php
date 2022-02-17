<?php

/**
 * custom APIs for Razorpay 1cc
 */

require_once __DIR__ . '/../debug.php';
require_once __DIR__ . '/../../woo-razorpay.php';
require_once __DIR__ . '/shipping-info.php';
require_once __DIR__ . '/coupon-apply.php';
require_once __DIR__ . '/coupon-get.php';
require_once __DIR__ . '/order.php';
require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../state-map.php';
require_once __DIR__ . '/save-abandonment-data.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

define('RZP_1CC_ROUTES_BASE', '1cc/v1');
define('RZP_1CC_CART_HASH', 'wc_razorpay_cart_hash_');

function rzp1ccInitRestApi()
{

    /**
     * coupon APIs required
     */

    // returns applicable coupons for an order
    register_rest_route(
        RZP_1CC_ROUTES_BASE . '/coupon',
        'list',
        array(
            'methods'             => 'POST',
            'callback'            => 'getCouponList',
            'permission_callback' => 'checkAuthCredentials',
        )
    );

    // checks if a coupon can be applied and returns discount amount
    register_rest_route(
        RZP_1CC_ROUTES_BASE . '/coupon',
        'apply',
        array(
            'methods'             => 'POST',
            'callback'            => 'applyCouponOnCart',
            'permission_callback' => 'checkAuthCredentials',
        )
    );

    /**
     * order APIs
     */

    // create new wc order
    register_rest_route(
        RZP_1CC_ROUTES_BASE . '/order',
        'create',
        array(
            'methods'             => 'POST',
            'callback'            => 'createWcOrder',
            'permission_callback' => 'checkAuthCredentials',
        )
    );

    /**
     * shipping APIs
     */

    // list of shipping methods for an order
    register_rest_route(
        RZP_1CC_ROUTES_BASE . '/shipping',
        'shipping-info',
        array(
            'methods'             => 'POST',
            'callback'            => 'calculateShipping1cc',
            'permission_callback' => 'checkAuthCredentials',
        )
    );

    // save abandoned cart data
    register_rest_route(
        RZP_1CC_ROUTES_BASE . '/',
        'abandoned-cart',
        array(
            'methods'             => 'POST',
            'callback'            => 'saveCartAbandonmentData',
            'permission_callback' => 'checkAuthCredentials',
        )
    );
}

add_action('rest_api_init', 'rzp1ccInitRestApi');

/**
 * Check any prerequisites for our REST request
 */
function initCustomerSessionAndCart()
{
    if (defined('WC_ABSPATH')) {
        // WC 3.6+ - Cart and other frontend functions are not included for REST requests.
        include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
        include_once WC_ABSPATH . 'includes/wc-notice-functions.php';
        include_once WC_ABSPATH . 'includes/wc-template-hooks.php';
    }

    if (null === WC()->session) {
        $session_class = apply_filters('woocommerce_session_handler', 'WC_Session_Handler');
        WC()->session  = new $session_class();
        WC()->session->init();
    }

    if (null === WC()->customer) {
        WC()->customer = new WC_Customer(get_current_user_id(), true);
    }

    if (null === WC()->cart) {
        WC()->cart = new WC_Cart();
        WC()->cart->get_cart();
    }
}

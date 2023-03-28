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
require_once __DIR__ . '/giftcard-apply.php';
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
        RZP_1CC_ROUTES_BASE,
        'abandoned-cart',
        array(
            'methods'             => 'POST',
            'callback'            => 'saveCartAbandonmentData',
            'permission_callback' => 'checkAuthCredentials',
        )
    );

    // cart data
    register_rest_route(
        RZP_1CC_ROUTES_BASE. '/cart',
        'fetch-cart',
        array(
            'methods'             => 'POST',
            'callback'            => 'fetchCartData',
            'permission_callback' => 'checkAuthCredentials',
        )
    );

    register_rest_route(
        RZP_1CC_ROUTES_BASE. '/cart',
        'create-cart',
        array(
            'methods'             => 'POST',
            'callback'            => 'createCartData',
            'permission_callback' => 'checkAuthCredentials',
        )
    );

     /**
     * Gift Card APIs
     */

     // validate gift card data
    register_rest_route(
        RZP_1CC_ROUTES_BASE.'/giftcard',
        'apply',
        array(
            'methods'             => 'POST',
            'callback'            => 'validateGiftCardData',
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
        include_once WC_ABSPATH . 'includes/wc-notice-functions.php'; // nosemgrep: file-inclusion
        include_once WC_ABSPATH . 'includes/wc-template-hooks.php'; // nosemgrep: file-inclusion
    }

    initCartCommon();
}

function initCartCommon()
{ 
    if (defined('WC_ABSPATH')) {
        // WC 3.6+ - Cart and other frontend functions are not included for REST requests.
        include_once WC_ABSPATH . 'includes/wc-cart-functions.php'; // nosemgrep: file-inclusion
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
    }

}

add_action('setup_extra_setting_fields', 'addMagicCheckoutSettingFields');

function addMagicCheckoutSettingFields(&$defaultFormFields)
{
    $magicCheckoutConfigFields = array(

        'enable_1cc'                    => array(
            'title'       => __('Activate Magic Checkout'),
            'type'        => 'checkbox',
            'description' => "",
            'label'       => __('Activate Magic Checkout'),
            'default'     => 'no',
        ),
        'enable_1cc_test_mode'          => array(
            'title'       => __('Activate test mode'),
            'type'        => 'checkbox',
            'description' => 'When test mode is active, only logged-in admin users will see the Razorpay Magic Checkout button',
            'label'       => __('Activate test mode for Magic Checkout'),
            'default'     => 'no',
        ),
        'enable_1cc_pdp_checkout'       => array(
            'title'       => __('Activate Buy Now Button'),
            'type'        => 'checkbox',
            'description' => 'By enabling the Buy Now button, user will be able to see the Razorpay Magic Checkout button on Product display page. ',
            'label'       => __('Activate Buy Now for Magic Checkout'),
            'default'     => 'yes',
        ),
        'enable_1cc_mini_cart_checkout' => array(
            'title'       => __('Activate Mini Cart Checkout'),
            'type'        => 'checkbox',
            'description' => 'By enabling the Mini Cart checkout button, user will be able to see the Razorpay Magic Checkout on click of checkout button. ',
            'label'       => __('Activate Mini Cart for Magic Checkout'),
            'default'     => 'yes',
        ),
        '1cc_min_cart_amount'           => array(
            'title'             => __('Set minimum cart amount (INR)'),
            'type'              => 'number',
            'description'       => 'Enter a minimum cart amount required to place an order via Magic Checkout.',
            'default'           => 0,
            'css'               => 'width: 120px;',
            'custom_attributes' => array(
                'min'  => 0,
                'step' => 1,
            ),
        ),
        '1cc_min_COD_slab_amount'       => array(
            'title'             => __('Set minimum amount (INR) for COD'),
            'type'              => 'number',
            'description'       => 'Enter a minimum amount required to place an order via COD (if enabled)',
            'default'           => 0,
            'css'               => 'width: 120px;',
            'custom_attributes' => array(
                'min'  => 0,
                'step' => 1,
            ),
        ),
        '1cc_max_COD_slab_amount'       => array(
            'title'             => __('Set maximum amount (INR) for COD'),
            'type'              => 'number',
            'description'       => 'Enter a maximum amount allowed to place an order via COD (if enabled)',
            'default'           => 0,
            'css'               => 'width: 120px;',
            'custom_attributes' => array(
                'min'  => 0,
                'step' => 1,
            ),
        ),
        'enable_1cc_ga_analytics'       => array(
            'title'       => __('Activate Google Analytics'),
            'type'        => 'checkbox',
            'description' => "To track orders using Google Analytics",
            'label'       => __('Activate Magic Checkout Google Analytics'),
            'default'     => 'no',
        ),
        'enable_1cc_fb_analytics'       => array(
            'title'       => __('Activate Facebook Analytics'),
            'type'        => 'checkbox',
            'description' => "To track orders using Facebook Pixel",
            'label'       => __('Activate Magic Checkout Facebook Analytics'),
            'default'     => 'no',
        ),
        '1cc_account_creation' => array(
            'title' => __('Allow customers to create store Account'),
            'type'        => 'checkbox',
            'description' => 'Allow customers to create store Account',
            'label'      =>  __('Allow customers to create store Account'),
            'default' => 'No',
        ),
    );

    $defaultFormFields = array_merge($defaultFormFields, $magicCheckoutConfigFields);

}

//To handle rest cookies invalid issue
add_filter("nonce_user_logged_out", function ($uid, $action) {
    if ($uid === 0 && $action === 'wp_rest') {
        return null;
    }
    return $uid;
}, 10, 2);

add_filter('rest_authentication_errors', function ($maybe_error) {
    return true;
});

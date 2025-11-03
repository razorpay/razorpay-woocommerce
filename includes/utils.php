<?php
/**
 * controls visibility of 1cc buttons
 * checks test mode, metrics collection config
 * NOTE: we add additional check to see if the config field exists as it may cause issues
 * during plugin updates
 */

/**
 * payment plugins are loaded even if they are disabled which triggers the 1cc button flow
 * we need to check if the plugin is disabled
 */
function isRazorpayPluginEnabled()
{
    return (
        empty(get_option('woocommerce_razorpay_settings')['enabled']) === false
        && 'yes' == get_option('woocommerce_razorpay_settings')['enabled']
    );
}

function isTestModeEnabled()
{
    return (
        empty(get_option('woocommerce_razorpay_settings')['enable_1cc_test_mode']) === false
        && 'yes' == get_option('woocommerce_razorpay_settings')['enable_1cc_test_mode']
    );
}

function is1ccEnabled()
{
    return (
        empty(get_option('woocommerce_razorpay_settings')['enable_1cc']) === false
        && 'yes' == get_option('woocommerce_razorpay_settings')['enable_1cc']
    );
}

function isProductSupported()
{

}

function isCartSupported()
{

}

function isDebugModeEnabled()
{
    return (
        empty(get_option('woocommerce_razorpay_settings')['enable_1cc_debug_mode']) === false
        && 'yes' == get_option('woocommerce_razorpay_settings')['enable_1cc_debug_mode']
    );
}

function isPdpCheckoutEnabled()
{
    return (
        empty(get_option('woocommerce_razorpay_settings')['enable_1cc_pdp_checkout']) === false
        && 'yes' == get_option('woocommerce_razorpay_settings')['enable_1cc_pdp_checkout']
    );
}

function isMiniCartCheckoutEnabled()
{
    return (
        empty(get_option('woocommerce_razorpay_settings')['enable_1cc_mini_cart_checkout']) === false
        && 'yes' == get_option('woocommerce_razorpay_settings')['enable_1cc_mini_cart_checkout']
    );
}

function isMandatoryAccCreationEnabled()
{
    return (
        empty(get_option('woocommerce_razorpay_settings')['1cc_account_creation']) === false
        && 'yes' == get_option('woocommerce_razorpay_settings')['1cc_account_creation']
    );
}
function validateInput($route, $param)
{
    $failure_reason = null;

    switch ($route) {
        case 'list':
            if (empty(sanitize_text_field($param['amount'])) === true) {
                $failure_reason = 'Field amount is required.';
            }
            break;

        case 'apply':
            if (empty(sanitize_text_field($param['code'])) === true) {
                $failure_reason = 'Field code is required.';

            } elseif (empty(sanitize_text_field($param['order_id'])) === true) {

                $failure_reason = 'Field order id is required.';

            }
            break;

        case 'shipping':
            if (empty(sanitize_text_field($param['order_id'])) === true) {
                $failure_reason = 'Field order id is required.';

            } elseif (empty($param['addresses']) === true) {

                $failure_reason = 'Field addresses is required.';

            }
            break;

        default:

            break;
    }

    return $failure_reason;
}
//Support for smart coupon plugin - restricted by payment method options
function smartCouponPaymentRestriction($couponCode){
    $coupon = new WC_Coupon($couponCode);

    // Get payment methods meta
    $methodsMeta = get_post_meta( $coupon->get_id(), '_wt_sc_payment_methods', true );

    // Normalize to array (handle both array and comma-separated string cases)
    // - If it's already an array, use it as-is.
    // - If it's a non-empty comma-separated string, split by commas and remove extra spaces or empty values.
    // - Otherwise, use an empty array.
    $methods = is_array($methodsMeta)
        ? $methodsMeta
        : (is_string($methodsMeta) && $methodsMeta !== '' 
           ? array_filter(preg_split('/\s*,\s*/', $methodsMeta)) 
           : array());

    // Normalize case (lowercase all methods)
    $methods = array_map( 'strtolower', $methods );

    // Check if Razorpay is allowed
    if ( in_array( 'razorpay', $methods, true ) && function_exists( 'WC' ) && WC()->session ) {
        WC()->session->set( 'chosen_payment_method', 'razorpay' );
    }
}
<?php
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
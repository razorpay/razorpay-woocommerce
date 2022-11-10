<?php

function abandonedPluginHook($razorpayData) {

    $rzpAbandonedData                                = array();
    $rzpAbandonedData['woocommerceOrderID']          = $razorpayData['receipt'];
    $rzpAbandonedData['amount']                      = $razorpayData['amount'];
    $rzpAbandonedData['currency']                    = $razorpayData['currency'];
    $rzpAbandonedData['customer_details']            = array();
    $rzpAbandonedData['customer_details']['contact'] = $razorpayData['customer_details']['contact']?? '';
    $rzpAbandonedData['customer_details']['email']   = $razorpayData['customer_details']['email']?? '';

    $magicShippingAddress                                     = $razorpayData['customer_details']['shipping_address'];
    $rzpAbandonedData['customer_details']['shipping_address'] = $magicShippingAddress;
    $magicBillingAddress                                      = $razorpayData['customer_details']['billing_address'];
    $rzpAbandonedData['customer_details']['billing_address']  = $magicBillingAddress;
    $rzpAbandonedData['created_at']                           = $razorpayData['created_at'];
    $rzpAbandonedData['shipping_fee']                         = $razorpayData['shipping_fee'];
    $rzpAbandonedData['line_items_total']                     = $razorpayData['line_items_total'];

     do_action('rzp_abandon',$rzpAbandonedData);

}






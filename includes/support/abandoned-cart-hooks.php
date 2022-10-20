<?php

function abandonedPluginHook($razorpayData) {

     $rzpAbandonedData                                = array();
     $rzpAbandonedData['woocommerceOrderID']          = $razorpayData['receipt'];
     $rzpAbandonedData['amount']                      = $razorpayData['amount'];
     $rzpAbandonedData['currency']                    = $razorpayData['currency'];
     $rzpAbandonedData['customer_details']            = array();
     $rzpAbandonedData['customer_details']['contact'] = $razorpayData['customer_details']['contact'];
     $rzpAbandonedData['customer_details']['email']   = $razorpayData['customer_details']['email'];

    $shippingAddress                                  = array(
        'name'     => $razorpayData['customer_details']['shipping_address']['name'],
        'line1'    => $razorpayData['customer_details']['shipping_address']['line1'],
        'line2'    => $razorpayData['customer_details']['shipping_address']['line2'],
        'zipcode'  => $razorpayData['customer_details']['shipping_address']['zipcode'],
        'city'     => $razorpayData['customer_details']['shipping_address']['city'],
        'state'    => $razorpayData['customer_details']['shipping_address']['state'],
        'tag'      => $razorpayData['customer_details']['shipping_address']['tag'],
        'landmark' => $razorpayData['customer_details']['shipping_address']['landmark'],
        'country'  => $razorpayData['customer_details']['shipping_address']['country'],
        'contact'  => $razorpayData['customer_details']['shipping_address']['contact'],
     );

     $rzpAbandonedData['customer_details']['shipping_address'] = $shippingAddress;

     $billingAddress                                           = array(
        'name'     => $razorpayData['customer_details']['billing_address']['name'],
        'line1'    => $razorpayData['customer_details']['billing_address']['line1'],
        'line2'    => $razorpayData['customer_details']['billing_address']['line2'],
        'zipcode'  => $razorpayData['customer_details']['billing_address']['zipcode'],
        'city'     => $razorpayData['customer_details']['billing_address']['city'],
        'state'    => $razorpayData['customer_details']['billing_address']['state'],
        'tag'      => $razorpayData['customer_details']['billing_address']['tag'],
        'landmark' => $razorpayData['customer_details']['billing_address']['landmark'],
        'country'  => $razorpayData['customer_details']['billing_address']['country'],
        'contact'  => $razorpayData['customer_details']['billing_address']['contact'],
     );

     $rzpAbandonedData['customer_details']['billing_address'] = $billingAddress;
     $rzpAbandonedData['created_at']                          = $razorpayData['created_at'];
     $rzpAbandonedData['shipping_fee']                        = $razorpayData['shipping_fee'];
     $rzpAbandonedData['line_items_total']                    = $razorpayData['line_items_total'];

     do_action('rzp_abandon',$rzpAbandonedData);

}






<?php

function abandonedPluginHook($razorpayData) {

     $rzpAbandonedData                       = array();
     $rzpAbandonedData['woocommerceOrderID'] = $razorpayData['receipt'];
     $rzpAbandonedData['amount']             = $razorpayData['amount'];
     $rzpAbandonedData['currency']           = $razorpayData['currency'];
     $rzpAbandonedData['customer_details']   = $razorpayData['customer_details'];
     $rzpAbandonedData['created_at']         = $razorpayData['created_at'];
     $rzpAbandonedData['shipping_fee']       = $razorpayData['shipping_fee'];
     $rzpAbandonedData['line_items_total']   = $razorpayData['line_items_total'];

     do_action('rzp_abandon',$rzpAbandonedData);

}





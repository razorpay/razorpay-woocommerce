<?php

function abandonedPluginHook($razorpayData) {

     $rzpAbandonedData                       = array();
     $rzpAbandonedData['woocommerceOrderID'] = $razorpayData['receipt'];
     $rzpAbandonedData['amount']             = $razorpayData['amount'];
     $rzpAbandonedData['amount_paid']        = $razorpayData['amount_paid'];
     $rzpAbandonedData['amount_due']         = $razorpayData['amount_due'];
     $rzpAbandonedData['currency']           = $razorpayData['currency'];
     $rzpAbandonedData['customer_details']   = $razorpayData['customer_details'];
     $rzpAbandonedData['created_at']         = $razorpayData['created_at'];
     $rzpAbandonedData['cod_fee']            = $razorpayData['cod_fee'];
     $rzpAbandonedData['shipping_fee']       = $razorpayData['shipping_fee'];
     $rzpAbandonedData['line_items_total']   = $razorpayData['line_items_total'];

     do_action('rzp_abandon',$rzpAbandonedData);

}

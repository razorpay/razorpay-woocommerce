<?php

use WOOMC\API;

function currencyConvert($amountInPaise,$order){
    return round(API::convert($amountInPaise,getOrderCurrency($order),"INR"),0);
}

function getOrderCurrency($order)
{
    if (version_compare(WOOCOMMERCE_VERSION, '2.7.0', '>='))
     {
        return $order->get_currency();
     }

  return $order->get_order_currency();
}
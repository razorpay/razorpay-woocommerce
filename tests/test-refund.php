<?php

/**
 * Class TestWC_Razorpay
 *
 * @package Woo_Razorpay
 */

class RefundTest extends WP_UnitTestCase
{
    function setup()
    {
        $this->razorpay = new WC_Razorpay(false);
        $this->order  = WC_Helper_Order::create_order();
    }

    function test_emptyOrder()
    {
       $response = $this->razorpay->process_refund($this->order->get_id());
       $this->assertInstanceOf(WP_Error::Class, $response);
    }
}

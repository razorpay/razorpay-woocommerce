<?php
/**
 * Class TestRZP_Webhook
 *
 * @package Woo_Razorpay
 */

class TestRZP_Webhook extends WP_UnitTestCase
{

    function setup()
    {
        $this->rzpWebhook = new RZP_Webhook();
    }

    function test_getOrderAmountAsInteger()
    {
        $order = WC_Helper_Order::create_order();
        $this->assertEquals($this->rzpWebhook->getOrderAmountAsInteger($order), 5000);
    }
}


<?php

require_once __DIR__ . '/../../../includes/utils.php';

use Razorpay\MockApi\MockApi;

/**
 * @covers ::isRazorpayPluginEnabled
 * @covers ::validateInput
 */ 

class Test_Utils extends \PHPUnit_Framework_TestCase
{
    public function setup(): void
    {
        parent::setup();
    }

    public function testisRazorpayPluginEnabled()
    {
        update_option('woocommerce_razorpay_settings', array('enabled' => 'yes'));

        $this->assertTrue(isRazorpayPluginEnabled());
    }

    public function testvalidateInput()
    {
        $this->assertSame(null, validateInput('', ''));

        $this->assertSame(null, validateInput('list', array('amount' => '2407')));

        $this->assertSame('Field amount is required.', validateInput('list', array('amount' => '')));

        $this->assertSame(null, validateInput('apply', array('code' => 'ABC2407', 'order_id' => '11')));

        $this->assertSame('Field code is required.', validateInput('apply', array('code' => '', 'order_id' => '11')));

        $this->assertSame('Field order id is required.', validateInput('apply', array('code' => 'ABC2407', 'order_id' => '')));

        $this->assertSame(null,validateInput('shipping', array('order_id' => '11', 'addresses' => 'Bangalore')));

        $this->assertSame('Field order id is required.', validateInput('shipping', array('order_id' => '', 'addresses' => 'Bangalore')));

        $this->assertSame('Field addresses is required.', validateInput('shipping', array('order_id' => '11', 'addresses' => '')));
    }
}

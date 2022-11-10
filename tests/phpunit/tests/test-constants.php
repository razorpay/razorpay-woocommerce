<?php

class Test_Constants extends WP_UnitTestCase
{
    public $razorpayTests;

    public function setup():void
    {
        parent::setup();
        $this->razorpayTests = new WC_Razorpay();
    }

    public function testPluginConstants()
    {
        $this->assertSame('razorpay_wc_order_id', $this->razorpayTests::SESSION_KEY);
    }
}

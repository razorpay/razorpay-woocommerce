<?php

class Test_Hooks extends WP_UnitTestCase
{
    public $razorpayTests;

    public function setup():void {
        parent::setup();
        $this->razorpayTests = new WC_Razorpay();
    }

    public function testRegisteredHooks(){
        $this->assertTrue(Util::has_action('woocommerce_update_options_payment_gateways_'.$this->razorpayTests->id,
            $this->razorpayTests, 'pluginInstrumentation'));
    }
}

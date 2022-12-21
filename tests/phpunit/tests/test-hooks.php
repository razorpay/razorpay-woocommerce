<?php

class Test_Hooks extends WP_UnitTestCase
{
    public $razorpayTests;

    public function setup():void
    {
        parent::setup();
        $this->razorpayTests = new WC_Razorpay();
    }

    public function testRegisteredHooks()
    {
        $this->assertTrue(Util::has_action('woocommerce_update_options_payment_gateways_' .
            $this->razorpayTests->id, $this->razorpayTests, 'pluginInstrumentation'));

        $this->assertTrue(Util::has_action('woocommerce_update_options_payment_gateways_' .
            $this->razorpayTests->id, $this->razorpayTests, 'process_admin_options'));

        $this->assertTrue(Util::has_action('woocommerce_update_options_payment_gateways_' .
            $this->razorpayTests->id, $this->razorpayTests, 'autoEnableWebhook'));

        $this->assertTrue(Util::has_action('woocommerce_update_options_payment_gateways_' .
            $this->razorpayTests->id, $this->razorpayTests, 'addAdminCheckoutSettingsAlert'));

        $this->assertTrue(Util::has_action('woocommerce_receipt_' . $this->razorpayTests->id,
            $this->razorpayTests, 'receipt_page'));

        $this->assertTrue(Util::has_action('woocommerce_api_' . $this->razorpayTests->id,
            $this->razorpayTests, 'check_razorpay_response'));
    }
}

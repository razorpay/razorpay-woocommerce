<?php

class Test_Plugin_Metadata extends WP_UnitTestCase
{
    public function testMetadata()
    {
        $pluginData = get_plugin_data(PLUGIN_DIR . '/woo-razorpay.php');

        $this->assertSame('1 Razorpay: Signup for FREE PG', $pluginData['Name']);

        $version = $pluginData['Version'];
        $v = explode(".", $version);
        $this->assertSame(3, count($v));

        $this->assertSame('Team Razorpay', $pluginData['AuthorName']);

        $this->assertSame('https://razorpay.com', $pluginData['AuthorURI']);

        $this->assertSame('https://razorpay.com', $pluginData['PluginURI']);

        $this->assertSame('Razorpay Payment Gateway Integration for WooCommerce.Razorpay Welcome Back Offer: New to Razorpay? Sign up to enjoy FREE payments* of INR 2 lakh till March 31st! Transact before January 10th to grab the offer. <cite>By <a href="https://razorpay.com">Team Razorpay</a>.</cite>', $pluginData['Description']);
    }
}


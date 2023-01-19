<?php

class Test_Plugin_Metadata extends WP_UnitTestCase
{
    public function testMetadata()
    {
        $pluginData = get_plugin_data(PLUGIN_DIR . '/woo-razorpay.php');

        $this->assertSame('Razorpay for WooCommerce', $pluginData['Name']);

        $version = $pluginData['Version'];
        $v = explode(".", $version);
        $this->assertSame(3, count($v));

        $this->assertSame('Team Razorpay', $pluginData['AuthorName']);

        $this->assertSame('https://razorpay.com', $pluginData['AuthorURI']);

        $this->assertSame('https://razorpay.com', $pluginData['PluginURI']);

        $this->assertSame('Razorpay Payment Gateway Integration for WooCommerce <cite>By <a href="https://razorpay.com">Team Razorpay</a>.</cite>', $pluginData['Description']);
    }
}


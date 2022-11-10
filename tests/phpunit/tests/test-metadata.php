<?php

class Test_Plugin_Metadata extends WP_UnitTestCase
{
    public function testMetadata()
    {
        $pluginData = get_plugin_data(PLUGIN_DIR. '/woo-razorpay.php');

        $this->assertSame('Razorpay for WooCommerce', $pluginData['Name']);
    }
}


<?php

class Test_Maigc_Api extends WP_UnitTestCase
{
    public function testMagicApi()
    {
        $this->assertFileExists(PLUGIN_DIR . '/includes/api/order.php');

        $this->assertFileExists(PLUGIN_DIR . '/includes/api/coupon-get.php');

        $this->assertFileExists(PLUGIN_DIR . '/includes/api/coupon-apply.php');

        $this->assertFileExists(PLUGIN_DIR . '/includes/api/shipping-info.php');

        $this->assertFileExists(PLUGIN_DIR . '/includes/api/save-abandonment-data.php');
    }
}

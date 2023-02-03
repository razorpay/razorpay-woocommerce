<?php

require_once __DIR__ . '/../../../includes/razorpay-affordability-widget.php';

use Razorpay\MockApi\MockApi;

class Test_AfdWidget extends \PHPUnit_Framework_TestCase
{
    public function testgetPrice()
    {
        global $product;

        $product = new WC_Product_Simple();

        $product->set_regular_price(15);

        $product->set_sale_price(10);

        $product->save();

        $this->assertSame('10', getPrice());
    }

    public function testaddSubSection()
    {
        ob_start();

        addSubSection();

        $result = ob_get_contents();

        ob_end_clean();
        
        $this->assertStringContainsString('</ul><br class="clear" />', $result);
    }

    public function testisAffordabilityWidgetTestModeEnabled()
    {
        $this->assertSame(false, isAffordabilityWidgetTestModeEnabled());

        add_option('rzp_afd_enable_test_mode', 'yes');

        $this->assertSame(true, isAffordabilityWidgetTestModeEnabled());
    }
}

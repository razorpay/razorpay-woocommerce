<?php

require_once __DIR__ . '/../../../includes/razorpay-affordability-widget.php';

use Razorpay\MockApi\MockApi;

class Test_AfdWidget extends \PHPUnit_Framework_TestCase
{
    public function setup(): void
    {
        parent::setup();

        $_POST = array();
    }

    public function testgetThemeColor()
    {
        $response = getThemeColor();

        $this->assertSame('#8BBFFF', $response);
    }

    public function testgetHeadingColor()
    {
        $response = getHeadingColor();

        $this->assertSame('black', $response);
    }

    public function testgetHeadingFontSize()
    {
        $response = getHeadingFontSize();

        $this->assertSame('10', $response);
    }

    public function testgetContentColor()
    {
        $response = getContentColor();

        $this->assertSame('grey', $response);
    }

    public function testgetContentFontSize()
    {
        $response = getContentFontSize();

        $this->assertSame('10', $response);
    }

    public function testgetLinkColor()
    {
        $response = getLinkColor();

        $this->assertSame('blue', $response);
    }

    public function testgetLinkFontSize()
    {
        $response = getLinkFontSize();

        $this->assertSame('10', $response);
    }

    public function testgetFooterColor()
    {
        $response = getFooterColor();

        $this->assertSame('grey', $response);
    }

    public function testgetFooterFontSize()
    {
        $response = getFooterFontSize();

        $this->assertSame('10', $response);
    }

    public function testgetCustomisation()
    {
        add_option('rzp_afd_theme_color', '#8BBFFF');
        
        $response = getCustomisation('rzp_afd_theme_color');

        $this->assertSame('#8BBFFF', $response);
    }

    public function testgetPrice()
    {
        global $product;

        $product = new WC_Product_Simple();
        $product->set_regular_price(15);
        $product->set_sale_price(10);
        $product->save();

        $this->assertSame('10', getPrice());

        $product = new WC_Product_Variable();
        $product->set_price(20);
        $product->save();

        $this->assertSame('20', getPrice());
    }

    public function testaddSubSection()
    {
        global $current_section;
        $current_section = 'affordability-widget';

        ob_start();
        addSubSection();
        $result = ob_get_contents();
        ob_end_clean();

        $pluginSubSection = '<li><a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=razorpay') . '" class="">Plugin Settings</a> | </li>';
        
        $affordabilitywidgetSubSection = '<li><a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=affordability-widget') . '" class="current">Affordability Widget</a>  </li>';

        $this->assertStringContainsString($pluginSubSection, $result);

        $this->assertStringContainsString($affordabilitywidgetSubSection, $result);

        $this->assertStringContainsString(admin_url(), $result);

        $this->assertStringContainsString('</ul><br class="clear" />', $result);
    }

    public function testisAffordabilityWidgetTestModeEnabled()
    {
        $this->assertFalse(isAffordabilityWidgetTestModeEnabled());

        add_option('rzp_afd_enable_test_mode', 'yes');

        $this->assertTrue(isAffordabilityWidgetTestModeEnabled());
    }
}

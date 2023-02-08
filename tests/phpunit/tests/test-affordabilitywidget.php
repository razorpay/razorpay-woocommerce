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

    public function testisEnabled()
    {
        $this->assertSame('true', isEnabled('rzp_afd_enable_dark_logo'));

        add_option('rzp_afd_enable_dark_logo', 'yes');

        $this->assertSame('true', isEnabled('rzp_afd_enable_dark_logo'));
    }

    public function testgetFooterDarkLogo()
    {
        $this->assertSame('true', getFooterDarkLogo());
    }

    public function testgetPayLater()
    {
        add_option('rzp_afd_enable_pay_later','yes');

        add_option('rzp_afd_limited_pay_later_providers','getsimpl,icic');

        $response = getPayLater();

        $this->assertStringContainsString('"providers": ["getsimpl","icic",]',$response);
    }

    public function testgetCardlessEmi()
    {
        add_option('rzp_afd_enable_cardless_emi','yes');

        add_option('rzp_afd_limited_cardless_emi_providers','hdfc,icic');

        $response = getCardlessEmi();

        $this->assertStringContainsString('"providers": ["hdfc","icic",]',$response);
    }

    public function testgetEmi()
    {
        add_option('rzp_afd_enable_emi','yes');

        add_option('rzp_afd_limited_emi_providers','HDFC,ICIC');

        $response = getEmi();

        $this->assertStringContainsString('"issuers": ["HDFC","ICIC",]',$response);
    }

    public function testgetOffers()
    {
        add_option('rzp_afd_limited_offers', "offer_ABC,offer_XYZ");

        add_option('rzp_afd_show_discount_amount', 'yes');

        $response = getOffers();

        $this->assertStringContainsString('"offerIds": ["offer_ABC","offer_XYZ",]', $response);

        $this->assertStringContainsString('"showDiscount": true', $response);
    }

    public function testgetAdditionalOffers()
    {
        add_option('rzp_afd_additional_offers', "offer_ABC,offer_XYZ");

        $response = getAdditionalOffers();

        $this->assertStringContainsString('offer_ABC', $response);

        $this->assertStringContainsString('offer_XYZ', $response);
    }
}

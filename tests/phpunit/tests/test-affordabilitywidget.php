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
    
    public function testaddAffordabilityWidgetHTML()
    {
        $current_user = wp_get_current_user();

        $current_user->add_cap('administrator');

        update_option('woocommerce_razorpay_settings', array('key_id' => 'key_id_2', 'key_secret' => 'key_secret2'));
        
        global $product;
        $product = new WC_Product_Simple();
        $product->set_regular_price(15);
        $product->set_sale_price(10);
        $product->save();
       
        add_option('rzp_afd_limited_offers', "offer_ABC,offer_XYZ");
        add_option('rzp_afd_show_discount_amount', 'yes');

        add_option('rzp_afd_enable_emi', 'yes');
        add_option('rzp_afd_limited_emi_providers', 'HDFC,ICIC');

        add_option('rzp_afd_enable_cardless_emi', 'yes');
        add_option('rzp_afd_limited_cardless_emi_providers', 'hdfc,icic');

        add_option('rzp_afd_enable_pay_later', 'yes');
        add_option('rzp_afd_limited_pay_later_providers', 'getsimpl,icic');

        ob_start();
        addAffordabilityWidgetHTML();
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertStringContainsString('"color": "#8BBFFF"', $result);

        $this->assertStringContainsString('const key = "key_id_2"', $result);

        $this->assertStringContainsString('"offers": { "offerIds": ["offer_ABC","offer_XYZ",],"showDiscount": true}', $result);

        $this->assertStringContainsString('"emi": { "issuers": ["HDFC","ICIC",] }', $result);

        $this->assertStringContainsString('"cardlessEmi": { "providers": ["hdfc","icic",] }', $result);

        $this->assertStringContainsString('"paylater": { "providers": ["getsimpl","icic",] }', $result);

        delete_option('woocommerce_razorpay_settings');
        delete_option('rzp_afd_limited_offers');
        delete_option('rzp_afd_enable_emi');
        delete_option('rzp_afd_enable_cardless_emi');
        delete_option('rzp_afd_enable_pay_later');
        delete_option('rzp_afd_limited_emi_providers');
        delete_option('rzp_afd_limited_cardless_emi_providers');
        delete_option('rzp_afd_limited_pay_later_providers');
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

    public function testgetKeyID()
    {
        update_option('woocommerce_razorpay_settings', array('key_id' => 'key_id_2', 'key_secret' => 'key_secret2'));

        $this->assertSame('key_id_2', getKeyId());
        
        delete_option('woocommerce_razorpay_settings');
    }

    public function testgetPriceSimpleProduct()
    {
        global $product;

        $product = new WC_Product_Simple();
        $product->set_regular_price(15);
        $product->set_sale_price(10);
        $product->save();

        $this->assertSame('10', getPrice());
    }

    public function testgetPriceVariableProduct()
    {
        global $product;
        
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

    public function testisAffordabilityWidgetTestModeEnabledYes()
    {
        add_option('rzp_afd_enable_test_mode', 'yes');

        $this->assertTrue(isAffordabilityWidgetTestModeEnabled());
    }

    public function testisAffordabilityWidgetTestModeEnabledNo()
    {   
        update_option('rzp_afd_enable_test_mode', 'no');

        $this->assertFalse(isAffordabilityWidgetTestModeEnabled());
    }

    public function testisAffordabilityWidgetTestModeNotEnabled()
    {
        $this->assertFalse(isAffordabilityWidgetTestModeEnabled());
    }
    
    public function testisEnabledYes()
    {
        add_option('rzp_afd_enable_dark_logo', 'yes');
        
        $this->assertSame('true', isEnabled('rzp_afd_enable_dark_logo'));

        delete_option('rzp_afd_enable_dark_logo');
    }

    public function testisEnabledNo()
    {
        add_option('rzp_afd_enable_dark_logo', 'no');
        
        $this->assertSame('false', isEnabled('rzp_afd_enable_dark_logo'));

        delete_option('rzp_afd_enable_dark_logo');
    }

    public function testisEnabledwithoutfeature()
    {
        $this->assertSame('true', isEnabled('rzp_afd_enable_dark_logo'));
    }

    public function testgetFooterDarkLogo()
    {
        $this->assertSame('true', getFooterDarkLogo());
    }

    public function testgetPayLaterOptionNo()
    {
        add_option('rzp_afd_enable_pay_later', 'no');

        $this->assertSame('false', getPayLater());

        delete_option('rzp_afd_enable_pay_later');
    }

    public function testgetPayLaterOptionYes()
    {
        add_option('rzp_afd_enable_pay_later', 'yes');

        $this->assertSame('true', getPayLater());

        delete_option('rzp_afd_enable_pay_later');
    }

    public function testgetPayLaterOptionwithProvider()
    {
        add_option('rzp_afd_enable_pay_later', 'yes');

        add_option('rzp_afd_limited_pay_later_providers', 'getsimpl,icic');

        $response = getPayLater();

        $this->assertStringContainsString('"providers": ["getsimpl","icic",]', $response);

        delete_option('rzp_afd_enable_pay_later');
    }

    public function testgetCardlessEmiOptionNo()
    {
        add_option('rzp_afd_enable_cardless_emi', 'no');

        $this->assertSame('false', getCardlessEmi());

        delete_option('rzp_afd_enable_cardless_emi');
    }

    public function testgetCardlessEmiOptionYes()
    {
        add_option('rzp_afd_enable_cardless_emi', 'yes');

        $this->assertSame('true', getCardlessEmi());

        delete_option('rzp_afd_enable_cardless_emi');
    }

    public function testgetCardlessEmiwithProvider()
    {
        add_option('rzp_afd_enable_cardless_emi', 'yes');

        add_option('rzp_afd_limited_cardless_emi_providers', 'hdfc,icic');

        $response = getCardlessEmi();

        $this->assertStringContainsString('"providers": ["hdfc","icic",]', $response);

        delete_option('rzp_afd_enable_cardless_emi');
    }

    public function testgetEmiOptionNo()
    {
        add_option('rzp_afd_enable_emi', 'no');

        $this->assertSame('false', getEmi());

        delete_option('rzp_afd_enable_emi');
    }

    public function testgetEmiOptionYes()
    {
        add_option('rzp_afd_enable_emi', 'yes');

        $this->assertSame('true', getEmi());

        delete_option('rzp_afd_enable_emi');
    }

    public function testgetEmiwithIssuer()
    {
        add_option('rzp_afd_enable_emi', 'yes');

        add_option('rzp_afd_limited_emi_providers', 'HDFC,ICIC');

        $response = getEmi();

        $this->assertStringContainsString('"issuers": ["HDFC","ICIC",]', $response);

        delete_option('rzp_afd_enable_emi');
    }

    public function testgetOffersOptionYes()
    {
        add_option('rzp_afd_show_discount_amount', 'yes');

        $response = getOffers();

        $this->assertStringContainsString('{"showDiscount": true}', $response);
    }

    public function testgetOfferswithOfferId()
    {
        add_option('rzp_afd_show_discount_amount', 'yes');

        add_option('rzp_afd_limited_offers', "offer_ABC,offer_XYZ");

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
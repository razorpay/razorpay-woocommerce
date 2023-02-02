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

        add_option('woocommerce_razorpay_settings', array('key_id' => 'key_id_2', 'key_secret' => 'key_secret2'));
        
        global $product;

        $product = new WC_Product_Simple();

        $product->set_regular_price( 15 );

        $product->set_sale_price(10);

        $product->save();
       
        add_option('rzp_afd_limited_offers', "offer_ABC,offer_XYZ");

        add_option('rzp_afd_show_discount_amount','yes');

        add_option('rzp_afd_enable_emi','yes');

        add_option('rzp_afd_limited_emi_providers','4');

        add_option('rzp_afd_enable_cardless_emi','yes');

        add_option('rzp_afd_limited_cardless_emi_providers','4');

        add_option('rzp_afd_enable_pay_later','yes');

        add_option('rzp_afd_limited_pay_later_providers','4');

        ob_start();

        addAffordabilityWidgetHTML();

        $result = ob_get_contents();
        
        ob_end_clean();

        $this->assertStringContainsString('"color": "#8BBFFF"', $result);

        $this->assertStringContainsString('const key = "key_id_2"', $result);

        $this->assertStringContainsString('"offers": { "offerIds": ["offer_ABC","offer_XYZ",],"showDiscount": true}', $result);

        $this->assertStringContainsString('"emi": { "issuers": ["4",] }', $result);

        $this->assertStringContainsString('"cardlessEmi": { "providers": ["4",] }', $result);

        $this->assertStringContainsString('"paylater": { "providers": ["4",] }', $result);
    }

    public function testgetKeyID()
    {
        add_option('woocommerce_razorpay_settings', array('key_id' => 'key_id_2', 'key_secret' => 'key_secret2'));

        $this->assertSame('key_id_2',getKeyId());
    }

    public function testgetPrice()
    {
        global $product;

        $product = new WC_Product_Simple();

        $product->set_regular_price( 15 );

        $product->set_sale_price(10);

        $product->save();

        $this->assertSame('10',getPrice());
    }
}
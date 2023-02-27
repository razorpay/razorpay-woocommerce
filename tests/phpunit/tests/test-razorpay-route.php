<?php

require_once __DIR__ . '/../../../includes/razorpay-route.php';
require_once __DIR__ .'/../../../woo-razorpay.php';

use Razorpay\MockApi\MockApi;

/**
 * @covers ::addRouteModuleSettingFields
 */

class Test_RzpRoute extends \PHPUnit_Framework_TestCase
{ 
    private $instance;
    private $rzpRoute;
    private $api;

    public function setup(): void
    {
        parent::setup();
        $this->rzpRoute = new RZP_Route();
        $this->instance = Mockery::mock('RZP_Route')->makePartial()->shouldAllowMockingProtectedMethods();

        $_POST = array();
        $_REQUEST = array();
        $_GET = array();
        $_SERVER = array();
    }

    public function testaddRouteModuleSettingFields()
    {
        update_option('woocommerce_currency', 'INR');
        
        $defaultFormFields = array(
            'key_id' => array(
                'title' => __('Key ID', 11),
                'type' => 'text',
                'description' => __('The key Id and key secret can be generated from "API Keys" section of Razorpay Dashboard. Use test or live for test or live mode.', 11)
            ),
            'key_secret' => array(
                'title' => __('Key Secret', 11),
                'type' => 'text',
                'description' => __('The key Id and key secret can be generated from "API Keys" section of Razorpay Dashboard. Use test or live for test or live mode.', 11)
            ),
        );

        addRouteModuleSettingFields($defaultFormFields);

        $description = "<span>For Route payments / transfers, first create a linked account <a href='https://dashboard.razorpay.com/app/route/payments' target='_blank'>here</a></span><br/><br/>Route Documentation - <a href='https://razorpay.com/docs/route/' target='_blank'>View</a>";

        $this->assertSame('Route Module', $defaultFormFields['route_enable']['title']);

        $this->assertSame('checkbox', $defaultFormFields['route_enable']['type']);

        $this->assertSame('Enable route module?', $defaultFormFields['route_enable']['label']);

        $this->assertSame($description, $defaultFormFields['route_enable']['description']);

        $this->assertSame('no', $defaultFormFields['route_enable']['default']);

        update_option('woocommerce_currency', 'USD');
    }

    public function testrazorpayRouteModule()
    {
        add_option('woocommerce_razorpay_settings', array('route_enable' => 'yes'));

        razorpayRouteModule();

        $this->assertTrue(has_action('admin_menu'));

        $this->assertTrue(has_action('admin_enqueue_scripts'));

        $this->assertTrue(has_action('woocommerce_product_data_panels'));

        $this->assertTrue(has_action('woocommerce_process_product_meta'));

        $this->assertTrue(has_action('add_meta_boxes'));

        $this->assertTrue(has_filter('woocommerce_product_data_tabs'));
    }

    public function testrzpAddPluginPage()
    {
        global $submenu;

        rzpAddPluginPage();

        $this->assertSame('razorpay-route-woocommerce', $GLOBALS['admin_page_hooks']['razorpayRouteWoocommerce']);

        $this->assertSame('razorpayTransfers', $GLOBALS['submenu'][''][0][2]);

        $this->assertSame('razorpayRouteReversals', $GLOBALS['submenu'][''][1][2]);

        $this->assertSame('razorpayRoutePayments', $GLOBALS['submenu'][''][2][2]);

        $this->assertSame('razorpaySettlementTransfers', $GLOBALS['submenu'][''][3][2]);

        $this->assertSame('razorpayPaymentsView', $GLOBALS['submenu'][''][4][2]);
    }
}

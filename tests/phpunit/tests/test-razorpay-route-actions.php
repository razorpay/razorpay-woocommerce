<?php
/**
 * @covers \RZP_Route_Action
 * @covers ::woocommerce_razorpay_init
 * @covers ::addRouteModuleSettingFields
 */

require_once __DIR__ . '/../../../includes/razorpay-route-actions.php';
require_once __DIR__ .'/../../../woo-razorpay.php';
require_once __DIR__ . '/../mockfactory/MockApi.php';

use Razorpay\MockApi\MockApi;

class Test_RzpRouteAction extends WP_UnitTestCase
{
    private $instance;

    public function setup(): void
    {
        parent::setup();
        $this->rzpRoute = new RZP_Route_Action();
        $this->instance = Mockery::mock('RZP_Route_Action')->makePartial()->shouldAllowMockingProtectedMethods();
    }

    public function testAddRouteAnalyticsScript()
    {
        $response = $this->instance->addRouteAnalyticsScript();

        $this->assertStringContainsString('<form method="POST" action="https://api.razorpay.com/v1/checkout/embedded" id="routeAnalyticsForm">', $response);
        $this->assertStringContainsString("<input type='hidden' name='_[x-integration]' value='Woocommerce'>", $response);
        $this->assertStringContainsString("<input type='hidden' name='_[x-integration-module]' value='Route'>", $response);
    }

}

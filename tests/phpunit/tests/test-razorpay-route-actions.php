<?php

require_once __DIR__ . '/../../../includes/razorpay-route-actions.php';
require_once __DIR__ .'/../../../woo-razorpay.php';
require_once __DIR__ . '/../mockfactory/MockApi.php';

use Razorpay\MockApi\MockApi;

class Test_RzpRouteAction extends \PHPUnit_Framework_TestCase
{
    private $instance;

    public function setup(): void
    {
        parent::setup();
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

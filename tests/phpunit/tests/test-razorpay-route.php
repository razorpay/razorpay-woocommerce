<?php

require_once __DIR__ . '/../../../includes/razorpay-route.php';
require_once __DIR__ .'/../../../woo-razorpay.php';
require_once __DIR__ . '/../mockfactory/MockApi.php';
require_once __DIR__ . '/../mockfactory/Request.php';
require_once __DIR__ . '/../mockfactory/Transfer.php';

use Razorpay\MockApi\MockApi;

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

    public function testprepareItems()
    {
        $this->instance->shouldReceive('get_pagenum')->andReturn(12);

        $_REQUEST = array('s' => 'ABC123');

        $reversalPage = array(
            'transfer_id' => '2345',
            'source' => 'Direct Transfer',
            'recipient' => 'Razorpay',
            'amount' => '2400',
            '16/2/23',
            'transfer_status' => 'Pending',
            'settlement_status' => 'Pending',
            'settlement_id' => '1234' 
        );

        $this->instance->shouldReceive('getItems')->with(10)->andReturn($reversalPage);

        $this->instance->shouldReceive('set_pagination_args');

        $this->instance->prepareItems();

        $this->assertTrue(true);
    }

    public function testprepareItemsnoOffset()
    {
        $this->instance->shouldReceive('get_pagenum')->andReturn(0);

        $_REQUEST = array('s' => 'ABC123');

        $reversalPage = array(
            'transfer_id' => '2345',
            'source' => 'Direct Transfer',
            'recipient' => 'Razorpay',
            'amount' => '2400',
            '16/2/23',
            'transfer_status' => 'Pending',
            'settlement_status' => 'Pending',
            'settlement_id' => '1234' 
        );

        $this->instance->shouldReceive('getItems')->with(10)->andReturn($reversalPage);

        $this->instance->shouldReceive('set_pagination_args');

        $this->instance->prepareItems();

        $this->assertTrue(true);
    }

    public function testgetItems()
    {
        $this->instance->shouldReceive('fetchRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
            });
        
        $response = $this->instance->getItems(4);

        $this->assertSame('<a href="?page=razorpayTransfers&id=abcd">abcd</a>', $response[0]['transfer_id']);

        $this->assertSame('order', $response[0]['source']);

        $this->assertSame('pay', $response[0]['recipient']);

        $this->assertSame('<span class="rzp-currency">₹</span> 12', $response[0]['amount']);

        $this->assertSame(date("d F Y h:i A", strtotime('+5 hour +30 minutes', '02/19/2023')), $response[0]['created_at']);

        $this->assertSame('Pending', $response[0]['transfer_status']);

        $this->assertSame('Pending', $response[0]['settlement_status']);

        $this->assertSame('<a href="?page=razorpaySettlementTransfers&id=Rzp123">Rzp123</a>', $response[0]['settlement_id']);
    }
}
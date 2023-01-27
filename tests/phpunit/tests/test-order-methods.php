<?php
/**
 * @covers \WC_Razorpay
 */

require_once __DIR__ . '/../mockfactory/MockApi.php';
require_once __DIR__ . '/../mockfactory/Request.php';
require_once __DIR__ . '/../mockfactory/Order.php';

use Razorpay\MockApi\MockApi;

class Test_OrderMethods extends WP_UnitTestCase
{
    private $instance;
    private $rzpPaymentObj;
    
    public function setup(): void
    {
        parent::setup();

        $this->rzpPaymentObj = new WC_Razorpay();

        $this->instance = Mockery::mock('WC_Razorpay')->makePartial()->shouldAllowMockingProtectedMethods();

        $_POST = array();
    }

    public function testverifyOrderAmount()
    {
        $order = wc_create_order();
        
        $wcOrderId = $order->get_id();

        
        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_3', 'key_secret3');
            });

        set_transient("razorpay_order_id".$wcOrderId, "razorpay_order_id", 18000);

        $razorpayOrderId = get_transient("razorpay_order_id".$wcOrderId);

        $response = $this->instance->verifyOrderAmount($razorpayOrderId,$wcOrderId);
        
        $this->assertSame(true,$response);
    }
}

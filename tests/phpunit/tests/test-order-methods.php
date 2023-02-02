<?php
/**
 * @covers \WC_Razorpay
 */

require_once __DIR__ . '/../mockfactory/MockApi.php';
require_once __DIR__ . '/../mockfactory/Request.php';

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

    public function testgetRazorpayPaymentParams()
    {    
        global $woocommerce;

        $order = wc_create_order();

        $wcOrderId = $order->get_id();

        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
            });

        $this->instance->shouldReceive('autoEnableWebhook');

        $razorpayOrderId = $this->instance->shouldReceive('createOrGetRazorpayOrderId')->with($wcOrderId)->andReturn('order_test');

        $this->assertEquals(['order_id' => 'order_test'], $this->instance->getRazorpayPaymentParams($wcOrderId));

        $getWebhookFlag = 2500;

        $this->assertEquals(['order_id' => 'order_test'], $this->instance->getRazorpayPaymentParams($wcOrderId));


    }

    public function testgetRazorpayPaymentParamsRzpidnull()
    {
        $order = wc_create_order();
        $wcOrderId = $order->get_id();

        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
            });
        $this->instance->shouldReceive('autoEnableWebhook');

        $razorpayOrderId=$this->instance->shouldReceive('createOrGetRazorpayOrderId')->with($wcOrderId)->andReturn(null);

        $message = 'RAZORPAY ERROR: Razorpay API could not be reached';

        try 
        {
            $this->instance->getRazorpayPaymentParams($wcOrderId);

            $this->fail("Expected Exception has not been raised.");
        }
        catch (Exception $ex) 
        {
            $this->assertEquals($message, $ex->getMessage());
        }  
    }

    public function testgetCustomOrdercreationMessage()
    {
        $order = wc_create_order();

        $defaultmessage = 'Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be processing your order soon.';

        $message = "The order was successful.";

        $this->instance->shouldReceive('getSetting')->with('order_success_message')->andReturn($message);

        $response = $this->instance->getCustomOrdercreationMessage("Order Placed",$order);

        $this->assertSame($message,$response);
    }

    public function testgetDefaultCheckoutArguments()
    {
        $order = wc_create_order();

        $orderId = $order->get_order_number();

        $wcOrderId = $order->get_id();

        $desc = "Order $orderId";

        $notes = array('woocommerce_order_id' => $orderId, 'woocommerce_order_number' => $wcOrderId);

        $sessionKey = "razorpay_order_id" . $orderId;

        $razorpayOrderId = get_transient($sessionKey);

        $address = array(
            'first_name' => 'Shaina',
            'last_name'  => 'Mirza',
            'company'    => 'Razorpay',
            'email'      => 'shaina.mirza@razorpay.com',
            'phone'      => '760-555-1212',
            'address_1'  => 'Housor Rd',
            'address_2'  => 'Adugodi',
            'city'       => 'Bangalore',
            'state'      => 'Karnataka',
            'postcode'   => '560030',
            'country'    => 'Bangalore'
        );

        $order->set_address($address, 'billing');

        $args = array(
            'name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'email'   => $order->get_billing_email(),
            'contact' => $order->get_billing_phone(),
        );

        $this->instance->expects($this->once())->method('getRedirectUrl')->with($orderId)->andReturn($this->returnValue($callbackUrl));
        
        $this->instance->shouldReceive('getOrderSessionKey')->with($orderId)->andReturn($sessionKey);

        $response = $this->instance->getDefaultCheckoutArguments($order);

        $this->assertNotNull($response['name']);

        $this->assertNotEmpty($response['callback_url']);

        $this->assertSame('INR', $response['currency']);

        $this->assertSame($desc, $response['description']);

        $this->assertSame($notes, $response['notes']);

        $this->assertSame($razorpayOrderId, $response['order_id']);

        $this->assertSame($args, $response['prefill']);
    }  
}

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

        //After the webhook flag is set

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

        $defaultmessage = "Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be processing your order soon.";

        $message = "The order was successful.";

        $this->instance->shouldReceive('getSetting')->with('order_success_message')->andReturn($message);

        $response = $this->instance->getCustomOrdercreationMessage("Order Placed", $order);

        $this->assertSame($message, $response);
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
    
    public function testUpdateOrder()
    {
        global $woocommerce;

        $order = wc_create_order();

        $cart = $woocommerce->cart;

        $woocommerce->cart->add_to_cart(14, 25);

        $order->set_payment_method('cod');

        $this->instance->updateOrder($order, true, "", 'razorpay_order_id');

        $this->assertSame('processing', $order->get_status());

        $this->instance->updateOrder($order, false, "Error message", 'razorpay_order_id');

        $reflection = new ReflectionProperty('WC_Razorpay', 'msg');
        $reflection->setAccessible(true);
        $msg = $reflection->getValue($this->instance);

        $this->assertSame($msg['class'], 'error');

        $this->assertSame($msg['message'], "Error message");

        $this->assertSame('failed', $order->get_status());
    }

    public function testupdateUserAddressInfo()
    {
        $order = wc_create_order();

        $order = wc_create_order(array('customer_id' => 189));

        $shippingAddress = [];

        $shippingAddress['first_name'] = 'ABC';
        $shippingAddress['address_1'] = 'SJR Cyber Laskar';
        $shippingAddress['address_2'] = 'Hosur Rd, Adugodi';
        $shippingAddress['city'] = 'Bengaluru';
        $shippingAddress['country'] = strtoupper('India');
        $shippingAddress['postcode'] = '560030';
        $shippingAddress['email'] = 'abc.xyz@razorpay.com';
        $shippingAddress['phone'] = '18001231272';

        $shippingState = strtoupper('Karnataka');
        $shippingStateName = str_replace(" ", '', $shippingState);
        $shippingStateCode = getWcStateCodeFromName($shippingStateName);

        $order->set_shipping_state($shippingStateCode);

        $this->instance->updateUserAddressInfo('shipping_', $shippingAddress, $shippingStateCode, $order);

        $firstName = get_user_meta($order->get_user_id(), 'shipping_first_name')[0];
        $address_1 = get_user_meta($order->get_user_id(), 'shipping_address_1')[0];
        $address_2 = get_user_meta($order->get_user_id(), 'shipping_address_2')[0];
        $country = get_user_meta($order->get_user_id(), 'shipping_country')[0];
        $postcode = get_user_meta($order->get_user_id(), 'shipping_postcode')[0];
        $email = get_user_meta($order->get_user_id(), 'shipping_email')[0];
        $phone = get_user_meta($order->get_user_id(), 'shipping_phone')[0];
        $city = get_user_meta($order->get_user_id(), 'shipping_city')[0];
        $state = get_user_meta($order->get_user_id(), 'shipping_state')[0];

        $this->assertSame($shippingAddress['first_name'], $firstName);

        $this->assertSame($shippingAddress['address_1'], $address_1);

        $this->assertSame($shippingAddress['address_2'], $address_2);

        $this->assertSame($shippingAddress['country'], $country);

        $this->assertSame($shippingAddress['postcode'], $postcode);

        $this->assertSame($shippingAddress['email'], $email);

        $this->assertSame($shippingAddress['phone'], $phone);

        $this->assertSame($shippingAddress['city'], $city);

        $this->assertSame($shippingStateCode, $state);
    }

    public function testhandleErrorCase()
    {
        global $woocommerce;

        $order = wc_create_order();

        $actual = "An error occured. Please contact administrator for assistance";

        $this->instance->handleErrorCase($order);

        $reflection = new ReflectionProperty('WC_Razorpay', 'msg');
        $reflection->setAccessible(true);
        $msg = $reflection->getValue($this->instance);

        $this->assertSame($msg['message'], $actual);

        $this->assertSame($msg['class'], 'error');
    }
}

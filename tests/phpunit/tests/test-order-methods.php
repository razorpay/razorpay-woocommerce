<?php
/**
 * @covers \WC_Razorpay
 * @covers ::is1ccEnabled
 * @covers ::addRouteModuleSettingFields
 * @covers ::isDebugModeEnabled
 * @covers razorpay_woo_plugin_links
 * @covers ::woocommerce_razorpay_init
 */

require_once __DIR__ . '/../mockfactory/MockApi.php';
require_once __DIR__ . '/../mockfactory/Request.php';
require_once __DIR__ . '/../mockfactory/Payment.php';

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

    public function testGetRazorpayPaymentParams()
    {
        global $woocommerce;

        $order = wc_create_order();

        $wcOrderId = $order->get_id();

        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
        });

        $this->instance->shouldReceive('autoEnableWebhook');

        $razorpayOrderId = $this->instance->shouldReceive('createOrGetRazorpayOrderId')->with($order, $wcOrderId)->andReturn('order_test');

        $this->assertEquals(['order_id' => 'order_test'], $this->instance->getRazorpayPaymentParams($order, $wcOrderId));

        //After the webhook flag is set

        $this->assertEquals(['order_id' => 'order_test'], $this->instance->getRazorpayPaymentParams($order, $wcOrderId));


    }

    public function testGetRazorpayPaymentParamsRzpIdNull()
    {
        $order = wc_create_order();
        $wcOrderId = $order->get_id();

        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
        });
        $this->instance->shouldReceive('autoEnableWebhook');

        $razorpayOrderId = $this->instance->shouldReceive('createOrGetRazorpayOrderId')->with($order, $wcOrderId)->andReturn(null);

        $message = 'RAZORPAY ERROR: Razorpay API could not be reached';

        try
        {
            $this->instance->getRazorpayPaymentParams($order, $wcOrderId);

            $this->fail("Expected Exception has not been raised.");
        }
        catch (Exception $ex)
        {
            $this->assertEquals($message, $ex->getMessage());
        }
    }

    public function testGetCustomOrderCreationMessage()
    {
        $order = wc_create_order();

        $defaultmessage = "Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be processing your order soon.";

        $message = "The order was successful.";

        $this->instance->shouldReceive('getSetting')->with('order_success_message')->andReturn($message);

        $response = $this->instance->getCustomOrdercreationMessage("Order Placed", $order);

        $this->assertSame($message, $response);
    }

    public function testGetDefaultCustomOrderCreationMessage()
    {
        $order = wc_create_order();

        $defaultmessage = 'Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be processing your order soon.';

        $this->instance->shouldReceive('getSetting')->with('order_success_message');

        $response = $this->instance->getCustomOrdercreationMessage("", $order);

        $this->assertSame($defaultmessage, $response);
    }

    public function testGetDefaultCheckoutArguments()
    {
        $order = wc_create_order();

        $orderId = $order->get_order_number();

        $wcOrderId = $order->get_id();

        $desc = "Order $orderId";

        $notes = array('woocommerce_order_id' => $orderId, 'woocommerce_order_number' => $wcOrderId);

        $sessionKey = "razorpay_order_id" . $orderId;

        $orderData = wc_get_order($wcOrderId);
        if ($this->isHposEnabled) 
        {
            $razorpayOrderId = $orderData->get_meta($sessionKey);
        }
        else
        {
            $razorpayOrderId = get_post_meta($wcOrderId, $sessionKey, true);
        }
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

    public function testUpdateUserAddressInfo()
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

    public function testHandleErrorCase()
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

    public function testWoocommerceRazorpayInit()
    {
        $this->assertNull(woocommerce_razorpay_init());
    }

    public function testProcessRefund()
    {
        $order = wc_create_order();
        $order->set_transaction_id('1236');
        $order->save();

        $wcOrderId = $order->get_id();

        add_post_meta($wcOrderId, '_transaction_id', 25, true);

        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
        });

        $response = $this->instance->process_refund($wcOrderId, 25.25, "not interested anymore");

        $this->assertSame($response, true);
    }

    public function testGetVersionMetaInfo()
    {
        $response = $this->instance->getVersionMetaInfo();

        $this->assertSame('woocommerce', $response['integration']);

        $integration_version = $response['integration_version'];
        $v1 = explode(".", $integration_version);
        $this->assertSame(3, count($v1));

        $integration_parent_version = $response['integration_parent_version'];
        $v2 = explode(".", $integration_parent_version);
        $this->assertSame(3, count($v2));

        $this->assertSame('plugin', $response['integration_type']);
    }

    public function testRazorpayWooPluginLinks()
    {
        $response = razorpay_woo_plugin_links([]);

        $this->assertSame('<a href="'. esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=razorpay')) .'">Settings</a>', $response['settings']);

        $this->assertSame('<a href="https://razorpay.com/docs/payment-gateway/ecommerce-plugins/woocommerce/woocommerce-pg/">Docs</a>', $response['docs']);

        $this->assertSame('<a href="https://razorpay.com/contact/">Support</a>', $response['support']);
    }

    public function testWoocommerceRazorpayInitHasAction()
    {
        woocommerce_razorpay_init();

        $this->assertSame(10, has_action('woocommerce_before_single_product', 'trigger_affordability_widget'));
    }

    public function testCheckRazorpayResponse()
    {
        global $woocommerce;
        global $wpdb;

        $order = wc_create_order();
        $wcOrderId = $order->get_id();

        $_GET = ['order_key' => 'root'];

        $_POST = ['razorpay_payment_id' => 'AVC123'];

        $wpdb->insert($wpdb->postmeta, array('post_id' => 22, 'meta_key' => '_order_key', 'meta_value' => 'root'));

        $wpdb->insert($wpdb->posts, array('post_status' => 'Pending', 'post_type' => 'shop_order', 'ID' => 22));

        $this->instance->shouldReceive('verifySignature');

        $this->instance->shouldReceive('redirectUser');

        $this->instance->shouldReceive('updateOrder');

        $response = $this->instance->check_razorpay_response();

        $this->assertTrue(true);
    }
}

<?php

require_once __DIR__ . '/../mockfactory/MockApi.php';
require_once __DIR__ . '/../mockfactory/Request.php';
require_once __DIR__ . '/../mockfactory/Order.php';

use Razorpay\MockApi\MockApi;
use Razorpay\Api\Errors\SignatureVerificationError;

class Test_Class_Fuctions extends WP_UnitTestCase
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

    public function testwebhookAPI()
    {
        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id', 'key_secret');
            });

        $webhookResponse = $this->instance->webhookAPI('GET', 'webhooks', []);

        $this->assertNotNull($webhookResponse);
        $this->assertArrayHasKey('items', $webhookResponse);
        $this->assertNotNull($webhookResponse['items'][0]['id']);
        $this->assertArrayHasKey('events', $webhookResponse['items'][0]);
    }

    public function testrzpThankYouMessage()
    {
        $this->assertSame($this->instance::DEFAULT_SUCCESS_MESSAGE, $this->instance->rzpThankYouMessage('', []));
    }

    public function testgetErrorMessage()
    {
        $actual = "An error occured. Please contact administrator for assistance";
        $this->assertSame($actual, $this->instance->getErrorMessage('orderId'));

        //error message with $_post['error']
        $_POST = [
            'error'=>[
                'description' => 'key id is a required field',
                'code' => 500
            ]
        ];

        $actual = "An error occured. Description : key id is a required field. Code : 500";
        $this->assertSame($actual, $this->instance->getErrorMessage('orderId'));

        $_POST['error']['field'] = 'key_id';
        $actual = "An error occured. Description : key id is a required field. Code : 500Field : key_id";
        $this->assertSame($actual, $this->instance->getErrorMessage('orderId'));
    }

    public function testWoocommerceAddRazorpayGateway()
    {
        $this->assertContains('WC_Razorpay', woocommerce_add_razorpay_gateway([]));
    }

    public function testverifySignature()
    {
        $this->expectNotToPerformAssertions();

        $_POST['razorpay_payment_id'] = 'razorpay_payment_id';
        $_POST['razorpay_signature'] = '9b942da85dde97f283166465cc056a20c980388fd9099c8159091ab5bffa22a1';
        $_POST['razorpay_order_id'] = 'razorpay_order_id';

        $this->instance->verifySignature('orderId');
    }

    public function testInvalidverifySignature()
    {
        $this->expectException(SignatureVerificationError::class);

        $_POST['razorpay_payment_id'] = 'razorpay_payment_id';
        $_POST['razorpay_signature'] = 'razorpay_signature';
        $_POST['razorpay_order_id'] = 'razorpay_order_id';

        $this->instance->verifySignature('orderId');
    }

    public function testReceiptPage()
    {
        $order = wc_create_order();
        $orderId = $order->get_id();

        $this->instance->shouldReceive('autoEnableWebhook');
        $this->instance->shouldReceive('createOrGetRazorpayOrderId')->with($orderId)->andReturn('order_test');
        $this->instance->shouldReceive('getRazorpayApiPublicInstance')->andReturnUsing(function () {
            return new MockApi('key_id', '');
        });

        ob_start();
        $this->instance->receipt_page($orderId);
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertStringContainsString("Thank you for your order, please click the button below to pay with Razorpay.", $result);
        $this->assertStringContainsString("<form name='razorpayform'", $result);
        $this->assertStringContainsString('<input type="hidden" name="razorpay_payment_id" id="razorpay_payment_id">', $result);
        $this->assertStringContainsString('<input type="hidden" name="razorpay_signature"  id="razorpay_signature" >', $result);
        $this->assertStringContainsString('<input type="hidden" name="razorpay_wc_form_submit" value="1">', $result);
        $this->assertStringContainsString('</form>', $result);
        $this->assertStringContainsString('Please wait while we are processing your payment.', $result);
        $this->assertStringContainsString('<button id="btn-razorpay">Pay Now</button>', $result);
        $this->assertStringContainsString('<button id="btn-razorpay-cancel" onclick="document.razorpayform.submit()">Cancel</button>', $result);
    }
    
    public function testCreateRazorpayOrderId()
    {
        $order = wc_create_order();
        $orderId = $order->get_id();

        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturnUsing(function () {
            return new MockApi('key_id', 'key_secret');
        });
        $this->instance->shouldReceive('autoEnableWebhook');

        $response = $this->instance->createOrGetRazorpayOrderId($orderId);
        $this->assertStringContainsString('razorpay_test_id', $response);
    }

    public function testGetRazorpayOrderId()
    {
        $order = wc_create_order();
        $orderId = $order->get_id();

        set_transient('razorpay_order_id' . (string) $orderId , 'razorpay_test_id', 18000);

        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturnUsing(function () {
            return new MockApi('key_id', 'key_secret');
        });
        $this->instance->shouldReceive('autoEnableWebhook');

        $response = $this->instance->createOrGetRazorpayOrderId($orderId);
        $this->assertStringContainsString('razorpay_test_id', $response);
    }

    public function testGenerateRazorpayFormException()
    {
        $order = wc_create_order();
        $orderId = $order->get_id();

        $this->instance->shouldReceive('getRazorpayPaymentParams')->andReturnUsing(function () {
            throw new \Exception('RAZORPAY ERROR: unable to process payment parameters');
        });
        $response = $this->instance->generate_razorpay_form($orderId);
        $this->assertSame('RAZORPAY ERROR: unable to process payment parameters', $response);
    }

    public function testGenerateOrderForm()
    {
        $mockObj = Mockery::mock('WC_Razorpay')->makePartial()->shouldAllowMockingProtectedMethods();
        $order = wc_create_order();
        $orderId = $order->get_id();

        $mockObj->shouldReceive('getRazorpayApiPublicInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_1', '');
            });

        $input = [
            "key" => "rzp_test",
            "name" => "mysite",
            "currency" => "INR",
            "description" => "Order " . $orderId,
            "notes" => [
                "woocommerce_order_id" => $orderId,
                "woocommerce_order_number" => $orderId
            ],
            "order_id" => "order_test",
            "callback_url" => "http://localhost:8888/wordpress",
            "prefill" => [
                "name" => "test",
                "email" => "testing@razorpay.com",
                "contact" => "00000000000"
            ],
            "_" => [
                "integration" => "woocommerce",
                "integration_version" => "4.3.3",
                "integration_parent_version" => "6.9.4"
            ],
            "cancel_url" => "http://127.0.0.1"
        ];

        $response = $mockObj->generateOrderForm($input);

        $this->assertStringContainsString('<form method="POST" action="https://api.razorpay.com/v1/checkout/embedded" id="checkoutForm">', $response);
        $this->assertStringContainsString('<input type="hidden" name="key_id" value="rzp_test">', $response);
        $this->assertStringContainsString('<input type="hidden" name="order_id" value="order_test">', $response);
        $this->assertStringContainsString('<input type="hidden" name="name" value="mysite">', $response);
        $this->assertStringContainsString('<input type="hidden" name="description" value="Order '.$orderId.'">', $response);
        $this->assertStringContainsString('<input type="hidden" name="image" value="image.png">', $response);
        $this->assertStringContainsString('<input type="hidden" name="callback_url" value="http://localhost:8888/wordpress">', $response);
        $this->assertStringContainsString('<input type="hidden" name="cancel_url" value="http://127.0.0.1">', $response);
        $this->assertStringContainsString("<input type='hidden' name='notes[woocommerce_order_id]' value='$orderId'>", $response);
        $this->assertStringContainsString("<input type='hidden' name='notes[woocommerce_order_number]' value='$orderId'>", $response);
        $this->assertStringContainsString("<input type='hidden' name='prefill[name]' value='test'>", $response);
        $this->assertStringContainsString("<input type='hidden' name='prefill[email]' value='testing@razorpay.com'>", $response);
        $this->assertStringContainsString("<input type='hidden' name='prefill[contact]' value='00000000000'>", $response);
        $this->assertStringContainsString("<input type='hidden' name='_[integration]' value='woocommerce'>", $response);
        $this->assertStringContainsString("name='_[integration_version]'", $response);
        $this->assertStringContainsString("name='_[integration_parent_version]'", $response);
        $this->assertStringContainsString("<input type='hidden' name='_[integration_type]' value='plugin'>", $response);
        $this->assertStringContainsString("</form>", $response);
    }
 
    public function testGetShippingZone()
    {
        $response = $this->instance->getShippingZone(0);
        $this->assertInstanceOf('WC_Shipping_Zone', $response);
    }

    public function testGetDescription()
    {
        $this->instance->shouldReceive('getSetting')->with('description')->andReturn('testing description');
        $response = $this->instance->get_description();
        $this->assertSame('testing description', $response);
    }

    public function testAdminOptions()
    {
        $this->instance->shouldReceive('generate_settings_html');
        ob_start();
        $this->instance->admin_options();
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertStringContainsString('Razorpay Payment Gateway', $result);
        $this->assertStringContainsString('Allows payments by Credit/Debit Cards, NetBanking, UPI, and multiple Wallets', $result);
        $this->assertStringContainsString('<table class="form-table">', $result);
        $this->assertStringContainsString('</table>', $result);
    }

    public function testProcessPayment()
    {
        $order = wc_create_order();
        $orderId = $order->get_id();

        $response = $this->instance->process_payment($orderId);
        $this->assertSame('success', $response['result']);
        $this->assertNotNull($response['redirect']);
    }
}

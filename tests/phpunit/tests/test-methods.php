<?php

require_once __DIR__ . '/../mockfactory/MockApi.php';
require_once __DIR__ . '/../mockfactory/Request.php';

use Razorpay\MockApi\MockApi;
use Razorpay\Api\Errors\SignatureVerificationError;

class Test_Class_Fuctions extends WP_UnitTestCase
{
    private $instance;
    private $rzpPaymentObj;

    public function setup(): void
    {
        parent::setup();
        $this->instance = Mockery::mock('WC_Razorpay')->makePartial();
        $this->rzpPaymentObj = new WC_Razorpay();
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
}

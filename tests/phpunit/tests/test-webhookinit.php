<?php


require_once __DIR__ . '/../../../includes/razorpay-webhook.php';
require_once __DIR__ . '/../mockfactory/MockApi.php';
require_once __DIR__ . '/../mockfactory/Request.php';

use Razorpay\MockApi\MockApi;

/**
 * @covers \RZP_Webhook
 * @covers \WC_Razorpay
 */

class Test_Webhook extends \PHPUnit_Framework_TestCase
{

    private $instance;
    private $rzpWebhook;

    public function setup(): void
    {
        parent::setup();
        $this->rzpWebhook = new RZP_Webhook();
        $this->instance = Mockery::mock('RZP_Webhook')->makePartial()->shouldAllowMockingProtectedMethods();
        $_POST = array();
    }

    public function testWebhookConstants()
    {
        $this->assertSame('payment.authorized', $this->rzpWebhook::PAYMENT_AUTHORIZED); 

        $this->assertSame('payment.failed', $this->rzpWebhook::PAYMENT_FAILED);

        $this->assertSame('payment.pending', $this->rzpWebhook::PAYMENT_PENDING);

        $this->assertSame('subscription.cancelled', $this->rzpWebhook::SUBSCRIPTION_CANCELLED);

        $this->assertSame('refund.created', $this->rzpWebhook::REFUNDED_CREATED);

        $this->assertSame('virtual_account.credited', $this->rzpWebhook::VIRTUAL_ACCOUNT_CREDITED);

        $this->assertSame('subscription.paused', $this->rzpWebhook::SUBSCRIPTION_PAUSED);

        $this->assertSame('subscription.resumed', $this->rzpWebhook::SUBSCRIPTION_RESUMED);

    }

    public function testPaymentFailed()
    {
        $response = $this->instance->PaymentFailed(array('hello'));

        $this->assertSame(null,$response);
    }

    public function testsubscriptionCancelled()
    {
        $response = $this->instance->subscriptionCancelled(array('hello'));

        $this->assertSame(null,$response);
    }

    public function testsubscriptionPaused()
    {
        $response = $this->instance->subscriptionPaused(array('hello'));

        $this->assertSame(null,$response);
    }

    public function testsubscriptionResumed()
    {
        $response = $this->instance->subscriptionResumed(array('hello'));

        $this->assertSame(null,$response);
    }


    public function testshouldConsumWebhook()
    {
        $order = wc_create_order();

        $wcorderId = $order->get_order_number();

        $data = array( 'event' => 'refund.created', 'payload' => array( 'payment' => array ( 'entity' => array('notes' => array('woocommerce_order_number' => $wcorderId)))));

        $response = $this->instance->shouldConsumeWebhook($data);

        $this->assertTrue($response);
    }

    public function testgetOrderAmountAsInteger()
    {

        $order = wc_create_order();

        $order->set_total(25.25);

        $response = $this->instance->getOrderAmountAsInteger($order);

        $this->assertSame($response, 2525);

    }

    public function testcheckisobject()
    {
        $order = wc_create_order();

        $orderId = $order->get_id();

        $order1 =  wc_get_order($orderId);

        $response = $this->instance->checkIsObject($orderId);

        $this->assertNotNull($response);

        $this->assertEquals($order1, $response);
    }
}


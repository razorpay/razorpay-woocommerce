<?php


require_once __DIR__ . '/../../../includes/razorpay-webhook.php';
require_once __DIR__ . '/../../../woo-razorpay.php';
require_once __DIR__ . '/../mockfactory/MockApi.php';
require_once __DIR__ . '/../mockfactory/Request.php';

use Razorpay\MockApi\MockApi;
use Razorpay\Api\Errors\SignatureVerificationError;

/**
 * @covers \RZP_Webhook
 * @covers \WC_Razorpay
 * @covers addRouteModuleSettingFields
 * @covers ::isDebugModeEnabled
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
        $this->razorpay = Mockery::mock('WC_Razorpay')->makePartial()->shouldAllowMockingProtectedMethods();
        $this->razorpay->shouldReceive('getRazorpayApiInstance')->andReturnUsing(
            function () {
                return new MockApi('key_id_2', 'key_secret2');
            });
        $_POST = array();
        $_SERVER = array();
    }

    public function testprocess()
    {
        $response = $this->instance->process();

        $this->assertSame(null, $response);
    }

    public function testprocessshouldntconsumewebhook()
    {
        $order = wc_create_order();

        $post = array('event' => 'subscription.paused', 'payload' => array('payment' => array('entity' => array('notes' => array('woocommerce_order_number' => $order->get_order_number()), 'order_id' => 'razorpay_order_id'))));
        
        $postEncoded = json_encode($post);

        $this->instance->shouldReceive('getContents')->andReturn($postEncoded);

        $this->instance->shouldReceive('shouldConsumeWebhook')->with($post)->andReturn(false);
        
        $response = $this->instance->process();

        $this->assertSame(null, $response);
    }

    public function testprocesswebhooksecret()
    {
        $order = wc_create_order();

        $post = array('event' => 'subscription.paused', 'payload' => array('payment' => array('entity' => array('notes' => array('woocommerce_order_number' => $order->get_order_number()), 'order_id' => 'razorpay_order_id'))));
        
        $postEncoded = json_encode($post);

        $this->instance->shouldReceive('getContents')->andReturn($postEncoded);

        $this->instance->shouldReceive('shouldConsumeWebhook')->with($post)->andReturn(true);
        
        $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] = true;

        $this->instance->shouldReceive('fetchSettings');

        $response = $this->instance->process();

        $this->assertSame(null, $response);
    }

    public function testprocesspaymentAuthorized()
    {        
        $order = wc_create_order();

        $post = array('event' => 'subscription.paused', 'payload' => array('payment' => array('entity' => array('notes' => array('woocommerce_order_number' => $order->get_order_number()), 'order_id' => 'razorpay_order_id'))));
        
        $postEncoded = json_encode($post);

        $_SERVER = array('HTTP_X_RAZORPAY_SIGNATURE' => true);

        add_option('webhook_secret', 'ABCD123');

        add_post_meta($order->get_order_number(), "rzp_webhook_notified_at", 1);

        $this->instance->shouldReceive('getContents')->andReturn($postEncoded);

        $this->instance->shouldReceive('verifyWebhook');

        $this->instance->shouldReceive('shouldConsumeWebhook')->with($post)->andReturn(true);
        
        $this->instance->shouldReceive('fetchSettings')->andReturn(true);

        $this->instance->shouldReceive('verifyWebhookSignature')->andReturn(true);

        $response = $this->instance->process();

        $this->assertSame(null,$response);

        delete_option('webhook_secret');

        delete_post_meta($order->get_order_number(), "rzp_webhook_notified_at");
    }

    public function testprocesssubscriptionpaused()
    {
        $order = wc_create_order();

        $post = array('event' => 'subscription.paused', 'payload' => array('payment' => array('entity' => array('notes' => array('woocommerce_order_number' => $order->get_order_number()), 'order_id' => 'razorpay_order_id'))));
        
        $postEncoded = json_encode($post);

        $_SERVER = array('HTTP_X_RAZORPAY_SIGNATURE' => true);

        add_option('webhook_secret', 'ABCD123');

        add_post_meta($order->get_order_number(), "rzp_webhook_notified_at", 1);

        $this->instance->shouldReceive('getContents')->andReturn($postEncoded);

        $this->instance->shouldReceive('verifyWebhook');

        $this->instance->shouldReceive('shouldConsumeWebhook')->with($post)->andReturn(true);
        
        $this->instance->shouldReceive('fetchSettings')->andReturn(true);

        $this->instance->shouldReceive('verifyWebhookSignature')->andReturn(true);

        $response = $this->instance->process();

        $this->assertSame(null,$response);

        delete_option('webhook_secret');

        delete_post_meta($order->get_order_number(), "rzp_webhook_notified_at");
    }

    public function testprocesspaymentfailed()
    {
        $order = wc_create_order();

        $post = array('event' => 'payment.failed', 'payload' => array('payment' => array('entity' => array('notes' => array('woocommerce_order_number' => $order->get_order_number()), 'order_id' => 'razorpay_order_id'))));
        
        $postEncoded = json_encode($post);

        $_SERVER = array('HTTP_X_RAZORPAY_SIGNATURE' => true);

        add_option('webhook_secret', 'ABCD123');

        add_post_meta($order->get_order_number(), "rzp_webhook_notified_at", 1);

        $this->instance->shouldReceive('getContents')->andReturn($postEncoded);

        $this->instance->shouldReceive('verifyWebhook');

        $this->instance->shouldReceive('shouldConsumeWebhook')->with($post)->andReturn(true);
        
        $this->instance->shouldReceive('fetchSettings')->andReturn(true);

        $this->instance->shouldReceive('verifyWebhookSignature')->andReturn(true);

        $response = $this->instance->process();

        $this->assertSame(null,$response);

        delete_option('webhook_secret');

        delete_post_meta($order->get_order_number(), "rzp_webhook_notified_at");
    }

    public function testprocesspaymentpending()
    {
        $order = wc_create_order();

        $post = array('event' => 'payment.pending', 'payload' => array('payment' => array('entity' => array('invoice_id' => true, 'notes' => array('woocommerce_order_number' => $order->get_order_number()), 'order_id' => 'razorpay_order_id'))));
        
        $postEncoded = json_encode($post);

        $_SERVER = array('HTTP_X_RAZORPAY_SIGNATURE' => true);

        add_option('webhook_secret', 'ABCD123');

        add_post_meta($order->get_order_number(), "rzp_webhook_notified_at", 1);

        $this->instance->shouldReceive('getContents')->andReturn($postEncoded);

        $this->instance->shouldReceive('verifyWebhook');

        $this->instance->shouldReceive('shouldConsumeWebhook')->with($post)->andReturn(true);
        
        $this->instance->shouldReceive('fetchSettings')->andReturn(true);

        $this->instance->shouldReceive('verifyWebhookSignature')->andReturn(true);

        $response = $this->instance->process();

        $this->assertSame(null,$response);

        delete_option('webhook_secret');

        delete_post_meta($order->get_order_number(), "rzp_webhook_notified_at");
    }

    public function testprocesssubscriptionCancelled()
    {
        $order = wc_create_order();

        $post = array('event' => 'subscription.cancelled', 'payload' => array('payment' => array('entity' => array('notes' => array('woocommerce_order_number' => $order->get_order_number()), 'order_id' => 'razorpay_order_id'))));
        
        $postEncoded = json_encode($post);

        $_SERVER = array('HTTP_X_RAZORPAY_SIGNATURE' => true);

        add_option('webhook_secret', 'ABCD123');

        add_post_meta($order->get_order_number(), "rzp_webhook_notified_at", 1);

        $this->instance->shouldReceive('getContents')->andReturn($postEncoded);

        $this->instance->shouldReceive('verifyWebhook');

        $this->instance->shouldReceive('shouldConsumeWebhook')->with($post)->andReturn(true);
        
        $this->instance->shouldReceive('fetchSettings')->andReturn(true);

        $this->instance->shouldReceive('verifyWebhookSignature')->andReturn(true);

        $response = $this->instance->process();

        $this->assertSame(null,$response);

        delete_option('webhook_secret');

        delete_post_meta($order->get_order_number(), "rzp_webhook_notified_at");
    }

    public function testprocesssubscriptionCharged()
    {
        $order = wc_create_order();

        $post = array('event' => 'subscription.charged', 'payload' => array('payment' => array('entity' => array('notes' => array('woocommerce_order_number' => $order->get_order_number()), 'order_id' => 'razorpay_order_id'))));
        
        $postEncoded = json_encode($post);

        $_SERVER = array('HTTP_X_RAZORPAY_SIGNATURE' => true);

        add_option('webhook_secret', 'ABCD123');

        add_post_meta($order->get_order_number(), "rzp_webhook_notified_at", 1);

        $this->instance->shouldReceive('getContents')->andReturn($postEncoded);

        $this->instance->shouldReceive('verifyWebhook');

        $this->instance->shouldReceive('shouldConsumeWebhook')->with($post)->andReturn(true);
        
        $this->instance->shouldReceive('fetchSettings')->andReturn(true);

        $this->instance->shouldReceive('verifyWebhookSignature')->andReturn(true);

        $response = $this->instance->process();

        $this->assertSame(null,$response);

        delete_option('webhook_secret');

        delete_post_meta($order->get_order_number(), "rzp_webhook_notified_at");
    }

    public function testprocesssubscriptionResumed()
    {
        $order = wc_create_order();

        $post = array('event' => 'subscription.resumed', 'payload' => array('payment' => array('entity' => array('notes' => array('woocommerce_order_number' => $order->get_order_number()), 'order_id' => 'razorpay_order_id'))));
        
        $postEncoded = json_encode($post);

        $_SERVER = array('HTTP_X_RAZORPAY_SIGNATURE' => true);

        add_option('webhook_secret', 'ABCD123');

        add_post_meta($order->get_order_number(), "rzp_webhook_notified_at", 1);

        $this->instance->shouldReceive('getContents')->andReturn($postEncoded);

        $this->instance->shouldReceive('verifyWebhook');

        $this->instance->shouldReceive('shouldConsumeWebhook')->with($post)->andReturn(true);
        
        $this->instance->shouldReceive('fetchSettings')->andReturn(true);

        $this->instance->shouldReceive('verifyWebhookSignature');

        $response = $this->instance->process();

        $this->assertSame(null,$response);

        delete_option('webhook_secret');

        delete_post_meta($order->get_order_number(), "rzp_webhook_notified_at");
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

    public function testsubscriptionCharged()
    {
        $response = $this->instance->subscriptionCharged(array('hello'));

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

    

    public function testgetPaymentEntity()
    {
        $order = wc_create_order();

        $data = array('event' => 'subscription.cancelled', 'payload' => array('payment' => array('entity' => array('notes' => array('woocommerce_order_number' => $order->get_order_number()), 'order_id' => 'razorpay_order_id'))));
        
        $payment = array('items' => array('id' => 'abcd', 'order_id' => 11, 'email' => 'abc.xyz@razorpay.com', 'amount' => 2300, 'created_at' => '02/19/2023', 'contact' => '9012345678', 'status' => 'Pending'));

        $this->instance->shouldReceive('fetchPayment')->andReturn($payment);

        $response = $this->instance->getPaymentEntity($payment, $data);

        $this->assertSame($payment, $response);
    }

    public function testpaymentAuthorized()
    {
        $order = wc_create_order();

        $orderId = $order->get_order_number();

        $order->set_total(25.25);
        
        $payment = array('id' => 'abcd', 'order_id' => 11, 'email' => 'abc.xyz@razorpay.com', 'amount' => 2300, 'created_at' => '02/19/2023', 'contact' => '9012345678', 'status' => 'captured');

        $data = ['payload' => ['payment' => ['entity' => ['id' => 'razorpay_order', 'notes' => ['woocommerce_order_number' => $orderId]]]]];

        $this->instance->shouldReceive('checkIsObject')->with($orderId)->andReturn($order);

        $this->instance->shouldReceive('fetchPayment')->andReturn($payment);

        $this->instance->shouldReceive('getPaymentEntity')->with('razorpay_order')->andReturn($payment);

        $this->instance->shouldReceive('fetchupdateOrder');

        $this->instance->shouldReceive('exitfunction');

        $this->instance->paymentAuthorized($data);

        $this->assertTrue(true);

        $data = ['payload' => ['payment' => ['entity' => ['invoice_id' => 'Abc123']]]];

        $this->assertSame(null, $this->instance->paymentAuthorized($data));
    }

    public function testpaymentPending()
    {
        $order = wc_create_order();

        $orderId = $order->get_order_number();

        $order->set_total(25.25);

        $data1 = ['payload' => ['payment' => ['entity' => ['invoice_id' => 'Abc123']]]];

        $this->assertSame(null, $this->instance->paymentPending($data1));

        $payment = array('id' => 'abcd', 'order_id' => 11, 'email' => 'abc.xyz@razorpay.com', 'amount' => 2300, 'created_at' => '02/19/2023', 'contact' => '9012345678', 'status' => 'pending');

        $data = ['payload' => ['payment' => ['entity' => ['method' => 'cod', 'id' => 'razorpay_order', 'notes' => ['woocommerce_order_number' => $orderId]]]]];

        $this->instance->shouldReceive('checkIsObject')->with($orderId)->andReturn($order);

        $this->instance->shouldReceive('fetchPayment')->andReturn($payment);

        $this->instance->shouldReceive('getPaymentEntity')->with('razorpay_order')->andReturn($payment);

        $this->instance->shouldReceive('fetchupdateOrder');

        $this->instance->shouldReceive('exitfunction');

        $this->instance->paymentPending($data);

        $this->assertTrue(true);
    }

    public function testvirtualAccountCredited()
    {
        $order = wc_create_order();

        $orderId = $order->get_order_number();

        $order->set_total(25.25);

        $data1 = ['payload' => ['payment' => ['entity' => ['invoice_id' => 'Abc123']]]];

        $this->assertSame(null, $this->instance->virtualAccountCredited($data1));

        $payment = array('id' => 'abcd', 'order_id' => 11, 'email' => 'abc.xyz@razorpay.com', 'amount' => 2300, 'created_at' => '02/19/2023', 'contact' => '9012345678', 'status' => 'captured');

        $data = ['payload' => ['virtual_account' => ['entity' => ['id' => 'Ac12', 'amount_paid' => 2525]], 'payment' => ['entity' => ['method' => 'cod', 'id' => 'razorpay_order', 'notes' => ['woocommerce_order_number' => $orderId]]]]];

        $this->instance->shouldReceive('checkIsObject')->with($orderId)->andReturn($order);

        $this->instance->shouldReceive('fetchPayment')->andReturn($payment);

        $this->instance->shouldReceive('getPaymentEntity')->with('razorpay_order')->andReturn($payment);

        $this->instance->shouldReceive('fetchupdateOrder');

        $this->instance->shouldReceive('exitfunction');

        $this->instance->virtualAccountCredited($data);
    }

    public function testrefundedCreated()
    {
        $order = wc_create_order();

        $orderId = $order->get_order_number();

        $order->set_total(0);

        $data1 = ['payload' => ['payment' => ['entity' => ['invoice_id' => 'Abc123']]]];

        $this->assertSame(null, $this->instance->refundedCreated($data1));

        $data1 = ['payload' => ['refund' => ['entity' => ['notes' => ['refund_from_website' => true]]]]];

        $this->assertSame(null, $this->instance->refundedCreated($data1));

        $payment = array('notes' => array('woocommerce_order_number' => $orderId));

        $data = ['payload' => ['refund' => ['entity' => ['amount' => 2525, 'id' => '123', 'payment_id' => 'razorpay_order', 'notes' => ['woocommerce_order_number' => $orderId, 'comment' => 'Not Required']]]]];

        $this->instance->shouldReceive('fetchPayment')->andReturn($payment);

        $this->instance->shouldReceive('getPaymentEntity')->with('razorpay_order')->andReturn($payment);

        $this->instance->shouldReceive('checkIsObject')->with($orderId)->andReturn($order);

        $this->instance->shouldReceive('exitfunction');

        $this->instance->refundedCreated($data);
    }

    public function testrefundednotCreated()
    {
        $order = wc_create_order();

        $orderId = $order->get_order_number();

        $order->set_total(25.25);

        $payment = array('notes' => array('woocommerce_order_number' => $orderId));

        $data = ['payload' => ['refund' => ['entity' => ['amount' => 2525, 'id' => '123', 'payment_id' => 'razorpay_order', 'notes' => ['woocommerce_order_number' => $orderId, 'comment' => 'Not Required']]]]];

        $this->instance->shouldReceive('fetchPayment')->andReturn($payment);

        $this->instance->shouldReceive('getPaymentEntity')->with('razorpay_order')->andReturn($payment);

        $this->instance->shouldReceive('checkIsObject')->with($orderId)->andReturn($order);

        $this->instance->shouldReceive('exitfunction');

        $this->instance->refundedCreated($data);

        $this->assertSame(null, $this->instance->refundedCreated($data));
    }

    public function testProcessRzpWebhookNotifiedEmpty()
    {        
        $order = wc_create_order();

        $post = array('event' => 'subscription.paused', 'payload' => array('payment' => array('entity' => array('notes' => array('woocommerce_order_number' => $order->get_order_number()), 'order_id' => 'razorpay_order_id'))));
        
        $postEncoded = json_encode($post);

        $this->instance->shouldReceive('getContents')->andReturn($postEncoded);

        $_SERVER = array('HTTP_X_RAZORPAY_SIGNATURE' => true);

        add_option('webhook_secret', 'ABCD123');

        add_post_meta($order->get_order_number(), "rzp_webhook_notified_at", 1);

        $this->instance->shouldReceive('verifyWebhook');

        $this->instance->shouldReceive('shouldConsumeWebhook')->with($post)->andReturn(true);
        
        $this->instance->shouldReceive('fetchSettings')->andReturn(true);

        $this->instance->shouldReceive('verifyWebhookSignature')->willThrowException('Errors\SignatureVerificationError');
        // $this->expectException("Errors\SignatureVerificationError");
        // $this->expectExceptionMessage("Payment Failed or error from gateway");

        $response = $this->instance->process();

        var_dump($response);
        // $this->assertSame(null,$response);

        delete_option('webhook_secret');

        delete_post_meta($order->get_order_number(), "rzp_webhook_notified_at");
    }
}


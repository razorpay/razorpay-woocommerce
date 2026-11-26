<?php

/**
 * The payment callback must not mark an order paid when:
 *  - the key secret or the order's stored Razorpay order id is empty (the signature is then forgeable),
 *  - the payment was refunded or failed after the signature was issued (a replayed callback),
 *  - another run is already finalising the same Magic Checkout order.
 * verifyOrderAmount must not treat a failed Razorpay order fetch as a verified amount.
 */

use Razorpay\Api\Errors\SignatureVerificationError;

class Test_Callback_Finalisation extends WP_UnitTestCase
{
    const KEY_ID     = 'rzp_test_unitKey';
    const KEY_SECRET = 'unitTestSecret';

    private $instance;

    private $settings;

    public function setUp(): void
    {
        parent::setUp();

        $this->instance = Mockery::mock('WC_Razorpay')->makePartial()->shouldAllowMockingProtectedMethods();
        $this->settings = array('key_id' => self::KEY_ID, 'key_secret' => self::KEY_SECRET);

        $this->instance->shouldReceive('getSetting')->andReturnUsing(function ($key)
        {
            return $this->settings[$key] ?? '';
        });

        $this->instance->shouldReceive('add_notice')->byDefault();
        $this->instance->shouldReceive('newTrackPluginInstrumentation')->andReturn(
            Mockery::mock('stdClass')->shouldReceive('rzpTrackDataLake')->getMock()
        )->byDefault();

        $_POST = array();
        $_GET  = array();
    }

    public function tearDown(): void
    {
        delete_option('woocommerce_razorpay_settings');
        $_POST = array();
        $_GET  = array();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function callProtected($method, array $args = array())
    {
        $reflection = new ReflectionMethod('WC_Razorpay', $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->instance, $args);
    }

    private function pendingOrder($razorpayOrderId = 'order_unit1')
    {
        $order = wc_create_order();
        $order->set_total(1000);
        $order->set_payment_method('razorpay');
        $order->save();

        if ($razorpayOrderId !== null)
        {
            update_post_meta($order->get_id(), 'razorpay_order_id' . $order->get_id(), $razorpayOrderId);
        }

        return wc_get_order($order->get_id());
    }

    private function enable1cc($order)
    {
        update_option('woocommerce_razorpay_settings', array('enable_1cc' => 'yes'));
        update_post_meta($order->get_id(), 'is_magic_checkout_order', 'yes');
    }

    private function noteText($order)
    {
        return implode(' ', wp_list_pluck(wc_get_order_notes(array('order_id' => $order->get_id())), 'content'));
    }

    private function signature($razorpayOrderId, $paymentId, $secret)
    {
        return hash_hmac('sha256', $razorpayOrderId . '|' . $paymentId, $secret);
    }

    /**
     * Stub the Razorpay API so payment->fetch returns $payment, or throws when $payment is an Exception.
     */
    private function stubPaymentFetch($payment)
    {
        $api          = new stdClass();
        $api->payment = new class($payment)
        {
            private $payment;

            public $fetched = array();

            public function __construct($payment)
            {
                $this->payment = $payment;
            }

            public function fetch($id)
            {
                $this->fetched[] = $id;

                if ($this->payment instanceof Exception)
                {
                    throw $this->payment;
                }

                return $this->payment;
            }
        };

        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturn($api);

        return $api;
    }

    /**
     * Run the callback for $order with a payment id; verifySignature and redirectUser are stubbed.
     * Returns the $success flag the callback passed to updateOrder.
     */
    private function runCallback($order, $updateOrderResult = null)
    {
        $_GET  = array('order_key' => $order->get_order_key());
        $_POST = array('razorpay_payment_id' => 'pay_unit1', 'razorpay_signature' => 'sig');

        $captured = new stdClass();
        $captured->success = null;

        $this->instance->shouldReceive('verifySignature')->once();
        $this->instance->shouldReceive('redirectUser')->once();
        $this->instance->shouldReceive('updateOrder')->once()->andReturnUsing(
            function ($order, $success) use ($captured, $updateOrderResult)
            {
                $captured->success = $success;

                return $updateOrderResult;
            });

        $this->instance->check_razorpay_response();

        return $captured->success;
    }

    private function insertQueueRow($order, $razorpayOrderId)
    {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'rzp_webhook_requests',
            array(
                'integration'                  => 'woocommerce',
                'order_id'                     => $order->get_id(),
                'rzp_order_id'                 => $razorpayOrderId,
                'rzp_webhook_data'             => '[]',
                'rzp_update_order_cron_status' => 0,
            )
        );
    }

    private function queueRowStatus($order)
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT rzp_update_order_cron_status FROM {$wpdb->prefix}rzp_webhook_requests WHERE order_id = %d",
            $order->get_id()
        ));
    }

    // ------------------------------------------------------- verifySignature

    public function testVerifySignaturePassesWithKeyStoredOrderAndValidSignature()
    {
        $this->expectNotToPerformAssertions();

        $order = $this->pendingOrder('order_unit1');

        $_POST['razorpay_payment_id'] = 'pay_unit1';
        $_POST['razorpay_signature']  = $this->signature('order_unit1', 'pay_unit1', self::KEY_SECRET);

        $this->callProtected('verifySignature', array($order->get_id()));
    }

    public function testVerifySignatureRejectsWrongSignature()
    {
        $order = $this->pendingOrder('order_unit1');

        $_POST['razorpay_payment_id'] = 'pay_unit1';
        $_POST['razorpay_signature']  = $this->signature('order_other', 'pay_unit1', self::KEY_SECRET);

        $this->expectException(SignatureVerificationError::class);

        $this->callProtected('verifySignature', array($order->get_id()));
    }

    public function testVerifySignatureRejectsForgedSignatureWhenKeySecretIsEmpty()
    {
        $this->settings['key_secret'] = '';

        $order = $this->pendingOrder('order_unit1');

        // With a blank secret anyone can compute this value
        $_POST['razorpay_payment_id'] = 'pay_forged';
        $_POST['razorpay_signature']  = $this->signature('order_unit1', 'pay_forged', '');

        $this->expectException(SignatureVerificationError::class);

        $this->callProtected('verifySignature', array($order->get_id()));
    }

    public function testVerifySignatureRejectsOrderWithoutStoredRazorpayOrderId()
    {
        // An order this plugin never created a Razorpay order for, for example one placed with another gateway
        $order = $this->pendingOrder(null);

        $_POST['razorpay_payment_id'] = 'pay_unit1';
        $_POST['razorpay_signature']  = $this->signature('', 'pay_unit1', self::KEY_SECRET);

        $this->expectException(SignatureVerificationError::class);

        $this->callProtected('verifySignature', array($order->get_id()));
    }

    public function testForgedCallbackOnBlankSecretStoreDoesNotCompleteOrder()
    {
        $this->settings['key_secret'] = '';

        $order = $this->pendingOrder(null);

        $_GET  = array('order_key' => $order->get_order_key());
        $_POST = array(
            'razorpay_payment_id' => 'pay_forged',
            'razorpay_signature'  => $this->signature('', 'pay_forged', ''),
        );

        $this->instance->shouldReceive('redirectUser')->once();

        $this->instance->check_razorpay_response();

        $fresh = wc_get_order($order->get_id());
        $this->assertEquals('failed', $fresh->get_status());
        $this->assertFalse($fresh->is_paid());
    }

    // ------------------------------------------- callback: payment re-check

    public function testCallbackCompletesCapturedPayment()
    {
        $order = $this->pendingOrder();
        $api   = $this->stubPaymentFetch(array('status' => 'captured'));

        $this->assertTrue($this->runCallback($order));
        $this->assertSame(array('pay_unit1'), $api->payment->fetched);
    }

    public function testCallbackCompletesAuthorizedPayment()
    {
        $order = $this->pendingOrder();
        $this->stubPaymentFetch(array('status' => 'authorized'));

        $this->assertTrue($this->runCallback($order));
    }

    public function testCallbackCompletesPendingCodPayment()
    {
        $order = $this->pendingOrder();
        $this->stubPaymentFetch(array('status' => 'pending', 'method' => 'cod'));

        $this->assertTrue($this->runCallback($order));
    }

    public function testReplayedCallbackForRefundedPaymentIsNotCompleted()
    {
        $order = $this->pendingOrder();
        $this->stubPaymentFetch(array('status' => 'refunded'));

        $this->assertFalse($this->runCallback($order));
    }

    public function testCallbackForFailedPaymentIsNotCompleted()
    {
        $order = $this->pendingOrder();
        $this->stubPaymentFetch(array('status' => 'failed'));

        $this->assertFalse($this->runCallback($order));
    }

    public function testCallbackStillCompletesWhenPaymentFetchFails()
    {
        // The signature proves the payment was authorised; a Razorpay outage must not strand a paid order
        $order = $this->pendingOrder();
        $this->stubPaymentFetch(new Exception('timeout'));

        $this->assertTrue($this->runCallback($order));
    }

    public function testRefundedPaymentReplayLeavesOrderUnpaid()
    {
        $order = $this->pendingOrder();
        $this->stubPaymentFetch(array('status' => 'refunded'));

        $_GET  = array('order_key' => $order->get_order_key());
        $_POST = array('razorpay_payment_id' => 'pay_unit1', 'razorpay_signature' => 'sig');

        $this->instance->shouldReceive('verifySignature')->once();
        $this->instance->shouldReceive('redirectUser')->once();

        $this->instance->check_razorpay_response();

        $fresh = wc_get_order($order->get_id());
        $this->assertEquals('failed', $fresh->get_status());
        $this->assertFalse($fresh->is_paid());
        $this->assertStringNotContainsString('Razorpay payment successful', $this->noteText($fresh));
    }

    // --------------------------------------------- updateOrder: Magic lock

    public function testUpdateOrderDoesNotCompleteMagicOrderAnotherRunIsProcessing()
    {
        $order = $this->pendingOrder();
        $this->enable1cc($order);

        set_transient('wc_order_under_process_' . $order->get_id(), true, 300);

        $this->instance->shouldReceive('update1ccOrderWC')->never();

        $result = $this->instance->updateOrder($order, true, '', 'pay_unit1', null, true);

        $fresh = wc_get_order($order->get_id());
        $this->assertFalse($result);
        $this->assertEquals('pending', $fresh->get_status());
        $this->assertFalse($fresh->is_paid());
        $this->assertStringNotContainsString('Razorpay payment successful', $this->noteText($fresh));
    }

    public function testUpdateOrderCompletesMagicOrderWhenNoOtherRunHoldsIt()
    {
        $order = $this->pendingOrder();
        $this->enable1cc($order);

        $this->instance->shouldReceive('update1ccOrderWC')->once()->andReturn(null);

        $result = $this->instance->updateOrder($order, true, '', 'pay_unit1', null, true);

        $fresh = wc_get_order($order->get_id());
        $this->assertNotFalse($result);
        $this->assertTrue($fresh->is_paid());
    }

    public function testUpdateOrderIgnoresLockForStandardOrder()
    {
        $order = $this->pendingOrder();

        set_transient('wc_order_under_process_' . $order->get_id(), true, 300);

        $this->instance->updateOrder($order, true, '', 'pay_unit1', null, true);

        $this->assertTrue(wc_get_order($order->get_id())->is_paid());
    }

    public function testUpdateOrderStillMarksLockedMagicOrderFailedOnFailure()
    {
        $order = $this->pendingOrder();
        $this->enable1cc($order);

        set_transient('wc_order_under_process_' . $order->get_id(), true, 300);

        $this->instance->updateOrder($order, false, 'The payment has failed.', 'pay_unit1', null, true);

        $this->assertEquals('failed', wc_get_order($order->get_id())->get_status());
    }

    // ------------------------------------------------ callback: queue row

    public function testCallbackMarksQueueRowProcessedWhenOrderFinalised()
    {
        $order = $this->pendingOrder('order_unit1');
        $this->insertQueueRow($order, 'order_unit1');
        $this->stubPaymentFetch(array('status' => 'captured'));

        $this->runCallback($order, null);

        $this->assertEquals(1, $this->queueRowStatus($order));
    }

    public function testCallbackLeavesQueueRowOpenWhenAnotherRunHoldsTheOrder()
    {
        $order = $this->pendingOrder('order_unit1');
        $this->insertQueueRow($order, 'order_unit1');
        $this->stubPaymentFetch(array('status' => 'captured'));

        // updateOrder reports it did not finalise the order
        $this->runCallback($order, false);

        $this->assertEquals(0, $this->queueRowStatus($order));
    }

    // --------------------------------------------------------- webhook cron

    public function testWebhookCronKeepsRowOpenWhenAnotherRunHoldsTheOrder()
    {
        $order = $this->pendingOrder();

        $this->instance->shouldReceive('updateOrder')->once()->andReturn(false);

        $webhook = Mockery::mock('RZP_Webhook')->makePartial()->shouldAllowMockingProtectedMethods();
        $webhook->shouldReceive('getPaymentEntity')->andReturn(array('status' => 'captured'));

        $property = new ReflectionProperty('RZP_Webhook', 'razorpay');
        $property->setAccessible(true);
        $property->setValue($webhook, $this->instance);

        $this->expectException(Exception::class);

        $webhook->paymentAuthorized(array(
            'event'                => 'payment.authorized',
            'woocommerce_order_id' => $order->get_id(),
            'razorpay_payment_id'  => 'pay_unit1',
        ));
    }

    public function testWebhookCronFinishesNormallyWhenOrderFinalised()
    {
        $order = $this->pendingOrder();

        $this->instance->shouldReceive('updateOrder')->once()->andReturn(null);

        $webhook = Mockery::mock('RZP_Webhook')->makePartial()->shouldAllowMockingProtectedMethods();
        $webhook->shouldReceive('getPaymentEntity')->andReturn(array('status' => 'captured'));

        $property = new ReflectionProperty('RZP_Webhook', 'razorpay');
        $property->setAccessible(true);
        $property->setValue($webhook, $this->instance);

        $webhook->paymentAuthorized(array(
            'event'                => 'payment.authorized',
            'woocommerce_order_id' => $order->get_id(),
            'razorpay_payment_id'  => 'pay_unit1',
        ));

        $this->assertTrue(true);
    }

    // ---------------------------------------------------- verifyOrderAmount

    public function testVerifyOrderAmountFailsClosedWhenRazorpayOrderFetchFails()
    {
        $order = $this->pendingOrder('order_unit1');

        $api        = new stdClass();
        $api->order = new class
        {
            public function fetch($id)
            {
                throw new Exception('Razorpay unreachable');
            }
        };

        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturn($api);

        $this->assertFalse($this->callProtected('verifyOrderAmount', array('order_unit1', $order->get_id())));
    }
}

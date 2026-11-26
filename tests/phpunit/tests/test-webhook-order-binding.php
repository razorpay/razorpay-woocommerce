<?php

/**
 * payment.pending (COD) and refund.created find the order through notes.woocommerce_order_id, which the
 * buyer's browser can change at checkout. They must act only when the payment belongs to that order:
 * its order_id is the Razorpay order stored on the WooCommerce order, or (for refunds) the order was
 * completed with that payment.
 */
class Test_Webhook_Order_Binding extends WP_UnitTestCase
{
    private $razorpay;

    private $webhook;

    public function setUp(): void
    {
        parent::setUp();

        $this->razorpay = Mockery::mock('WC_Razorpay')->makePartial()->shouldAllowMockingProtectedMethods();
        $this->webhook  = Mockery::mock('RZP_Webhook')->makePartial()->shouldAllowMockingProtectedMethods();

        $property = new ReflectionProperty('RZP_Webhook', 'razorpay');
        $property->setAccessible(true);
        $property->setValue($this->webhook, $this->razorpay);
    }

    private function callProtected($method, array $args)
    {
        $reflection = new ReflectionMethod('RZP_Webhook', $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->webhook, $args);
    }

    private function order($razorpayOrderId, $magic = false)
    {
        $order = wc_create_order();
        $order->set_total(1000);
        $order->save();

        $id = $order->get_id();

        if ($magic)
        {
            update_post_meta($id, 'is_magic_checkout_order', 'yes');
        }

        if ($razorpayOrderId !== null)
        {
            update_post_meta($id, ($magic ? 'razorpay_order_id_1cc' : 'razorpay_order_id') . $id, $razorpayOrderId);
        }

        return wc_get_order($id);
    }

    private function pendingEvent($wcOrderId, $paymentId = 'pay_cod1')
    {
        return array(
            'event'   => 'payment.pending',
            'payload' => array('payment' => array('entity' => array(
                'id'     => $paymentId,
                'method' => 'cod',
                'notes'  => array('woocommerce_order_id' => $wcOrderId),
            ))),
        );
    }

    private function refundEvent($paymentId = 'pay_1', $amount = 50000)
    {
        return array(
            'event'   => 'refund.created',
            'payload' => array(
                'payment' => array('entity' => array('id' => $paymentId)),
                'refund'  => array('entity' => array(
                    'id'         => 'rfnd_1',
                    'payment_id' => $paymentId,
                    'amount'     => $amount,
                    'notes'      => array('comment' => 'test'),
                )),
            ),
        );
    }

    // ------------------------------------------------------------- helper

    public function testPaymentOnTheStoredRazorpayOrderBelongsToTheOrder()
    {
        $order = $this->order('order_A');

        $this->assertTrue($this->callProtected('isPaymentForOrder', array(array('id' => 'pay_1', 'order_id' => 'order_A'), $order)));
    }

    public function testPaymentOnTheStoredMagicRazorpayOrderBelongsToTheOrder()
    {
        $order = $this->order('order_M', true);

        $this->assertTrue($this->callProtected('isPaymentForOrder', array(array('id' => 'pay_1', 'order_id' => 'order_M'), $order)));
    }

    public function testPaymentOnAnotherRazorpayOrderDoesNotBelongToTheOrder()
    {
        $order = $this->order('order_A');

        $this->assertFalse($this->callProtected('isPaymentForOrder', array(array('id' => 'pay_1', 'order_id' => 'order_B'), $order)));
    }

    public function testPaymentWithoutOrderIdDoesNotBelongToAnOrderWithoutStoredId()
    {
        $order = $this->order(null);

        $this->assertFalse($this->callProtected('isPaymentForOrder', array(array('id' => 'pay_1', 'order_id' => null), $order)));
        $this->assertFalse($this->callProtected('isPaymentForOrder', array(array('id' => 'pay_1'), $order)));
    }

    public function testPaymentTheOrderWasCompletedWithBelongsToTheOrder()
    {
        // The stored Razorpay order was replaced after payment; the transaction id still ties them
        $order = $this->order('order_NEW');
        $order->set_transaction_id('pay_1');
        $order->save();

        $this->assertTrue($this->callProtected('isPaymentForOrder', array(array('id' => 'pay_1', 'order_id' => 'order_OLD'), $order)));
    }

    // ------------------------------------------------------ payment.pending

    public function testCodPendingForAnotherOrdersPaymentIsIgnored()
    {
        // Buyer paid COD on Razorpay order_CHEAP but pointed the notes at this order
        $victim = $this->order('order_VICTIM');

        $this->webhook->shouldReceive('getPaymentEntity')->andReturn(
            array('id' => 'pay_cod1', 'status' => 'pending', 'method' => 'cod', 'order_id' => 'order_CHEAP')
        );
        $this->razorpay->shouldReceive('updateOrder')->never();

        $this->callProtected('paymentPending', array($this->pendingEvent($victim->get_id())));

        $fresh = wc_get_order($victim->get_id());
        $this->assertEquals('pending', $fresh->get_status());
        $this->assertFalse($fresh->is_paid());
    }

    // ------------------------------------------------------- refund.created

    public function testRefundForAnotherOrdersPaymentIsIgnored()
    {
        $victim = $this->order('order_VICTIM');
        $victim->set_transaction_id('pay_victim');
        $victim->set_status('processing');
        $victim->save();

        $this->webhook->shouldReceive('getPaymentEntity')->andReturn(array(
            'id'       => 'pay_1',
            'order_id' => 'order_OTHER',
            'notes'    => array('woocommerce_order_id' => $victim->get_id()),
        ));

        $this->webhook->refundedCreated($this->refundEvent('pay_1'));

        $fresh = wc_get_order($victim->get_id());
        $this->assertCount(0, $fresh->get_refunds());
        $this->assertEquals('processing', $fresh->get_status());
    }
}

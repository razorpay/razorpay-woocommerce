<?php

/**
 * PLUG-04: gift card balances are re-checked after payment for orders with a gift card promotion;
 * a failing order is blocked (refund/cancel/hold) before anything is debited, and blocked orders
 * are never completed. Orders without a gift card run the existing code unchanged.
 */

if (class_exists('YITH_YWGC_Gift_Card') === false)
{
    /**
     * Minimal YITH stand-in; behaviour is driven through the static $fixture.
     */
    class YITH_YWGC_Gift_Card
    {
        public static $fixture = array('exists' => true, 'balance' => 0, 'id' => 0);

        public $ID = 0;

        public function __construct($args = array())
        {
            $this->ID = self::$fixture['id'];
        }

        public function exists()
        {
            return self::$fixture['exists'];
        }

        public function get_balance()
        {
            return self::$fixture['balance'];
        }
    }
}

if (function_exists('woo_wallet') === false)
{
    /**
     * Minimal Terra Wallet (woo-wallet) stand-in; the balance is driven through $GLOBALS.
     */
    function woo_wallet()
    {
        $wallet = new class
        {
            public function get_wallet_balance($userId, $context = 'view')
            {
                return $GLOBALS['rzp_test_woo_wallet_balance'] ?? 0;
            }
        };

        return (object) array('wallet' => $wallet);
    }
}

/**
 * Razorpay payment entity stand-in: array access plus refund().
 */
class Rzp_Test_Fake_Payment implements ArrayAccess
{
    public $data;

    public $refunds = array();

    public $throwMessage = null;

    public $beforeRefund = null;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function offsetExists($key): bool
    {
        return isset($this->data[$key]);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($key)
    {
        return $this->data[$key];
    }

    public function offsetSet($key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function offsetUnset($key): void
    {
        unset($this->data[$key]);
    }

    public function refund($attributes)
    {
        if ($this->beforeRefund !== null)
        {
            ($this->beforeRefund)();
        }

        $this->refunds[] = $attributes;

        if ($this->throwMessage !== null)
        {
            throw new Exception($this->throwMessage);
        }

        return (object) array('id' => 'rfnd_1');
    }
}

class Test_GiftCardBalanceRecheck extends WP_UnitTestCase
{
    // Verifies once()/never() expectations and counts them as assertions
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    private $instance;

    private $settings = array();

    public function setUp(): void
    {
        parent::setUp();

        $this->instance = Mockery::mock('WC_Razorpay')->makePartial()->shouldAllowMockingProtectedMethods();
        $this->settings = array();

        $this->instance->shouldReceive('getSetting')->andReturnUsing(function ($key)
        {
            return $this->settings[$key] ?? '';
        });

        // Defaults; tests that care set explicit expectations, which replace these
        $this->instance->shouldReceive('add_notice')->byDefault();
        $this->instance->shouldReceive('newTrackPluginInstrumentation')->andReturn(
            Mockery::mock('stdClass')->shouldReceive('rzpTrackDataLake')->getMock()
        )->byDefault();

        YITH_YWGC_Gift_Card::$fixture = array('exists' => true, 'balance' => 0, 'id' => 0);

        $GLOBALS['rzp_test_woo_wallet_balance'] = 0;
    }

    public function tearDown(): void
    {
        delete_option('woocommerce_razorpay_settings');
        remove_all_filters('pre_option_active_plugins');
        remove_all_filters('wp_redirect');
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function callProtected($method, array $args = array())
    {
        $reflection = new ReflectionMethod('WC_Razorpay', $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->instance, $args);
    }

    private function setCheckedOrderId($orderId)
    {
        $property = new ReflectionProperty('WC_Razorpay', 'giftCardCheckedOrderId');
        $property->setAccessible(true);
        $property->setValue($this->instance, $orderId);
    }

    /**
     * The test bootstrap pins active_plugins through a pre_option filter, so extend that list.
     */
    private function activatePlugin($plugin)
    {
        add_filter('pre_option_active_plugins', function ($plugins) use ($plugin)
        {
            $plugins   = is_array($plugins) ? $plugins : array();
            $plugins[] = $plugin;

            return $plugins;
        }, 20);
    }

    private function giftCard($code, $paise)
    {
        return array('type' => 'gift_card', 'code' => $code, 'value' => $paise);
    }

    private function yithCard($exists, $balance, $postStatus = 'publish')
    {
        $this->activatePlugin('yith-woocommerce-gift-cards/init.php');

        $postId = self::factory()->post->create(array('post_status' => $postStatus));

        YITH_YWGC_Gift_Card::$fixture = array('exists' => $exists, 'balance' => $balance, 'id' => $postId);
    }

    /**
     * Pimwick tables (test DB) with one card 'GC1'. $activity = list of activity amounts (balance = SUM).
     */
    private function pimwickCard(array $activity, $active = 1)
    {
        global $wpdb;

        $this->activatePlugin('pw-woocommerce-gift-cards/pw-gift-cards.php');

        $wpdb->pimwick_gift_card          = $wpdb->prefix . 'pimwick_gift_card';
        $wpdb->pimwick_gift_card_activity = $wpdb->prefix . 'pimwick_gift_card_activity';

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->pimwick_gift_card} (pimwick_gift_card_id BIGINT PRIMARY KEY, number VARCHAR(64), active TINYINT(1) NOT NULL DEFAULT 1)");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->pimwick_gift_card_activity} (id BIGINT AUTO_INCREMENT PRIMARY KEY, pimwick_gift_card_id BIGINT, amount DECIMAL(15,4))");
        $wpdb->query("DELETE FROM {$wpdb->pimwick_gift_card}");
        $wpdb->query("DELETE FROM {$wpdb->pimwick_gift_card_activity}");

        $wpdb->insert($wpdb->pimwick_gift_card, array('pimwick_gift_card_id' => 7, 'number' => 'GC1', 'active' => $active));

        foreach ($activity as $amount)
        {
            $wpdb->insert($wpdb->pimwick_gift_card_activity, array('pimwick_gift_card_id' => 7, 'amount' => $amount));
        }
    }

    private function failure(array $promotions)
    {
        $order = wc_create_order();

        return $this->callProtected('giftCardPromotionFailure', array(array('promotions' => $promotions), $order));
    }

    private function payment($method = 'card', $amount = 30000, $status = null, $amountRefunded = 0)
    {
        return new Rzp_Test_Fake_Payment(array(
            'id'              => 'pay_X',
            'method'          => $method,
            'amount'          => $amount,
            'amount_refunded' => $amountRefunded,
            'status'          => $status ?? (($method === 'cod') ? 'pending' : 'captured'),
        ));
    }

    private function pendingOrder($total = 1000)
    {
        $order = wc_create_order();
        $order->set_total($total);
        $order->save();

        return $order;
    }

    private function block($order, $payment)
    {
        $this->callProtected('blockGiftCardOrder', array($order->get_id(), $payment, 'gift card ending GC1 cannot cover 300'));

        return wc_get_order($order->get_id());
    }

    private function noteText($order)
    {
        return implode(' ', wp_list_pluck(wc_get_order_notes(array('order_id' => $order->get_id())), 'content'));
    }

    private function enable1cc($order)
    {
        update_option('woocommerce_razorpay_settings', array('enable_1cc' => 'yes')); // is1ccEnabled() reads the option
        $order->update_meta_data('is_magic_checkout_order', 'yes');
        $order->set_payment_method('razorpay');
        $order->save();
    }

    private function blockFlag($order)
    {
        $order->update_meta_data(WC_Razorpay::GIFTCARD_BLOCKED_META, 'test');
        $order->save();
    }

    /**
     * Stub the Razorpay API used by update1ccOrderWC: order fetch returns $promotions, payment fetch returns $payment.
     */
    private function stubApi(array $promotions, $payment)
    {
        $orderData = array('promotions' => $promotions, 'notes' => array(), 'line_items_total' => 0, 'shipping_fee' => 0);

        $api          = new stdClass();
        $api->order   = new class($orderData)
        {
            private $data;

            public function __construct($data)
            {
                $this->data = $data;
            }

            public function fetch($id)
            {
                return $this->data;
            }
        };
        $api->payment = new class($payment)
        {
            private $payment;

            public function __construct($payment)
            {
                $this->payment = $payment;
            }

            public function fetch($id)
            {
                return $this->payment;
            }
        };

        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturn($api);
    }

    // ------------------------------------------------- re-check: YITH

    public function testYithSufficientAndExactBalancePass()
    {
        $this->yithCard(true, 500);
        $this->assertNull($this->failure(array($this->giftCard('GC1', 30000))));

        $this->yithCard(true, 300.0);
        $this->assertNull($this->failure(array($this->giftCard('GC1', 30000))));
    }

    public function testYithOnePaiseShortFails()
    {
        $this->yithCard(true, 299.99);

        $this->assertNotNull($this->failure(array($this->giftCard('GC1', 30000))));
    }

    public function testYithMissingCardFails()
    {
        $this->yithCard(false, 500);

        $this->assertNotNull($this->failure(array($this->giftCard('GC1', 30000))));
    }

    // Older YITH versions can return a trashed card; current YITH does not find it at all (covered by the missing-card test)
    public function testYithTrashedCardFails()
    {
        $this->yithCard(true, 500, 'trash');

        $this->assertNotNull($this->failure(array($this->giftCard('GC1', 30000))));
    }


    // ---------------------------------------------- re-check: Pimwick

    public function testPimwickSufficientAndExactBalancePass()
    {
        $this->pimwickCard(array('500.0000', '-200.0000')); // balance 300

        $this->assertNull($this->failure(array($this->giftCard('GC1', 30000))));
        $this->assertNull($this->failure(array($this->giftCard('GC1', 20000))));
    }

    public function testPimwickShortBalanceFails()
    {
        $this->pimwickCard(array('500.0000', '-200.0100')); // balance 299.99

        $this->assertNotNull($this->failure(array($this->giftCard('GC1', 30000))));
    }

    public function testPimwickMissingInactiveOrEmptyCardFails()
    {
        $this->pimwickCard(array('500.0000'));
        $this->assertNotNull($this->failure(array($this->giftCard('NOPE', 100))), 'missing card');

        $this->pimwickCard(array('500.0000'), 0);
        $this->assertNotNull($this->failure(array($this->giftCard('GC1', 100))), 'inactive card');

        $this->pimwickCard(array());
        $this->assertNotNull($this->failure(array($this->giftCard('GC1', 100))), 'no activity: NULL sum');
    }

    public function testNoGiftCardPluginFails()
    {
        $this->assertNotNull($this->failure(array($this->giftCard('GC1', 100))));
    }

    public function testSameCardTwiceIsCheckedAgainstTotalUse()
    {
        $this->yithCard(true, 100);

        $this->assertNull($this->failure(array($this->giftCard('GC1', 6000), $this->giftCard('GC1', 4000))));
        $this->assertNotNull($this->failure(array($this->giftCard('GC1', 6000), $this->giftCard('GC1', 6000))));
    }

    public function testSecondCardInvalidFailsTheOrder()
    {
        $this->pimwickCard(array('500.0000'));

        $this->assertNotNull($this->failure(array($this->giftCard('GC1', 100), $this->giftCard('NOPE', 100))));
    }

    public function testFailureReasonMasksTheCardCode()
    {
        $this->yithCard(true, 0);

        $reason = $this->failure(array($this->giftCard('SECRET-CODE-1234', 100)));

        $this->assertStringContainsString('1234', $reason);
        $this->assertStringNotContainsString('SECRET', $reason);
    }

    public function testTerraWalletInOrderIsChecked()
    {
        $this->yithCard(true, 500);
        $wallet = array('type' => 'terra_wallet', 'code' => 'W', 'value' => 10000);

        $this->assertNotNull($this->failure(array($this->giftCard('GC1', 100), $wallet)), 'wallet plugin inactive');

        $this->activatePlugin('woo-wallet/woo-wallet.php');
        $GLOBALS['rzp_test_woo_wallet_balance'] = 99.99;
        $this->assertNotNull($this->failure(array($this->giftCard('GC1', 100), $wallet)), 'wallet short');

        $GLOBALS['rzp_test_woo_wallet_balance'] = 100;
        $this->assertNull($this->failure(array($this->giftCard('GC1', 100), $wallet)), 'wallet exact');
    }

    // ------------------------------------------------------ block states

    public function testCapturedPaymentIsRefundedAndOrderCancelled()
    {
        $order   = $this->pendingOrder();
        $payment = $this->payment('card', 30000);

        $fresh = $this->block($order, $payment);

        $this->assertCount(1, $payment->refunds);
        $this->assertSame(30000, $payment->refunds[0]['amount']);
        $this->assertEquals('cancelled', $fresh->get_status());
        $this->assertNotSame('', $fresh->get_meta(WC_Razorpay::GIFTCARD_BLOCKED_META));
        $this->assertStringContainsString('Refund Id: rfnd_1', $this->noteText($fresh));
    }

    public function testFlagIsWrittenBeforeTheRefundCall()
    {
        $order   = $this->pendingOrder();
        $payment = $this->payment();
        $flagged = null;

        $payment->beforeRefund = function () use ($order, &$flagged)
        {
            $flagged = wc_get_order($order->get_id())->get_meta(WC_Razorpay::GIFTCARD_BLOCKED_META) !== '';
        };

        $this->block($order, $payment);

        $this->assertTrue($flagged);
    }

    public function testRefundFailurePutsOrderOnHoldWithoutRetry()
    {
        $order                 = $this->pendingOrder();
        $payment               = $this->payment();
        $payment->throwMessage = 'The payment has not been captured';

        $fresh = $this->block($order, $payment);

        $this->assertCount(1, $payment->refunds);
        $this->assertEquals('on-hold', $fresh->get_status());
        $this->assertNotSame('', $fresh->get_meta(WC_Razorpay::GIFTCARD_BLOCKED_META));
        $this->assertStringContainsString('Razorpay Dashboard', $this->noteText($fresh));
    }

    public function testCodIsCancelledWithoutRefund()
    {
        $order   = $this->pendingOrder();
        $payment = $this->payment('cod');

        $fresh = $this->block($order, $payment);

        $this->assertCount(0, $payment->refunds);
        $this->assertEquals('cancelled', $fresh->get_status());
        $this->assertNotSame('', $fresh->get_meta(WC_Razorpay::GIFTCARD_BLOCKED_META));
    }

    public function testAuthorizeModeIsCancelledWithoutRefund()
    {
        $this->settings['payment_action'] = WC_Razorpay::AUTHORIZE;

        $order   = $this->pendingOrder();
        $payment = $this->payment('card', 30000, 'authorized');

        $fresh = $this->block($order, $payment);

        $this->assertCount(0, $payment->refunds);
        $this->assertEquals('cancelled', $fresh->get_status());
        $this->assertStringContainsString('do not capture', $this->noteText($fresh));
    }

    public function testOrderAlreadyCompletedInParallelGoesOnHoldWithoutRefund()
    {
        foreach (array('processing', 'completed') as $status)
        {
            $order = $this->pendingOrder();
            $order->update_status($status);
            $payment = $this->payment();

            $fresh = $this->block($order, $payment);

            $this->assertCount(0, $payment->refunds, $status);
            $this->assertEquals('on-hold', $fresh->get_status(), $status);
            $this->assertNotSame('', $fresh->get_meta(WC_Razorpay::GIFTCARD_BLOCKED_META), $status);
        }
    }

    public function testUnpaidStatusesAreRefundedAndCancelled()
    {
        // WC 3.5 (CI) does not register the draft statuses; register them so set_status keeps them
        add_filter('wc_order_statuses', function ($statuses)
        {
            return $statuses + array('wc-checkout-draft' => 'Draft', 'wc-draft' => 'Draft');
        });

        foreach (array('failed', 'checkout-draft', 'draft') as $status)
        {
            $order = $this->pendingOrder();
            $order->set_status($status);
            $order->save();
            $this->assertEquals($status, wc_get_order($order->get_id())->get_status(), "fixture $status");

            $payment = $this->payment();
            $fresh   = $this->block($order, $payment);

            $this->assertCount(1, $payment->refunds, $status);
            $this->assertEquals('cancelled', $fresh->get_status(), $status);
        }
    }

    public function testCaptureModeAuthorizedPaymentAttemptsRefundThenHolds()
    {
        $order                 = $this->pendingOrder();
        $payment               = $this->payment('card', 30000, 'authorized');
        $payment->throwMessage = 'Only captured payments can be refunded';

        $fresh = $this->block($order, $payment);

        $this->assertCount(1, $payment->refunds);
        $this->assertEquals('on-hold', $fresh->get_status());
    }

    public function testCancelledOrderPaidLateIsRefundedAndStaysCancelled()
    {
        $order = $this->pendingOrder();
        $order->update_status('cancelled');
        $payment = $this->payment();

        $fresh = $this->block($order, $payment);

        $this->assertCount(1, $payment->refunds);
        $this->assertEquals('cancelled', $fresh->get_status());
        $this->assertStringContainsString('Refund Id: rfnd_1', $this->noteText($fresh));
    }

    public function testAuthorizeSettingButCapturedPaymentIsRefunded()
    {
        $this->settings['payment_action'] = WC_Razorpay::AUTHORIZE;

        $order   = $this->pendingOrder();
        $payment = $this->payment('card', 30000, 'captured');

        $fresh = $this->block($order, $payment);

        $this->assertCount(1, $payment->refunds);
        $this->assertEquals('cancelled', $fresh->get_status());
    }

    public function testAlreadyRefundedPaymentIsNotRefundedAgain()
    {
        $order   = $this->pendingOrder();
        $payment = $this->payment('card', 30000, 'refunded', 30000);

        $fresh = $this->block($order, $payment);

        $this->assertCount(0, $payment->refunds);
        $this->assertEquals('cancelled', $fresh->get_status());
    }

    public function testPartlyRefundedPaymentRefundsOnlyTheRemainder()
    {
        $order   = $this->pendingOrder();
        $payment = $this->payment('card', 30000, 'captured', 10000);

        $this->block($order, $payment);

        $this->assertSame(20000, $payment->refunds[0]['amount']);
    }

    public function testOtherStatusesAreLeftAsIsWithoutRefund()
    {
        foreach (array('on-hold', 'refunded') as $status)
        {
            $order = $this->pendingOrder();
            $order->update_status($status);
            $payment = $this->payment();

            $fresh = $this->block($order, $payment);

            $this->assertCount(0, $payment->refunds, $status);
            $this->assertEquals($status, $fresh->get_status(), $status);
            $this->assertNotSame('', $fresh->get_meta(WC_Razorpay::GIFTCARD_BLOCKED_META), $status);
        }
    }

    public function testSecondBlockOnAlreadyBlockedOrderTakesNoAction()
    {
        $order = $this->pendingOrder();
        $this->blockFlag($order);
        $order->update_status('cancelled');
        $payment = $this->payment();

        // Run as the browser callback so the shopper message is observable
        $this->instance->shouldReceive('add_notice')->once()
            ->with('Your gift card balance was insufficient, so your order could not be placed. Any amount charged will be refunded.', 'error');
        add_filter('wp_redirect', function ($location)
        {
            throw new RuntimeException('redirect:' . $location);
        });
        remove_all_actions('woocommerce_api_razorpay');
        add_action('woocommerce_api_razorpay', function () use ($order, $payment)
        {
            $this->block($order, $payment);
        });

        try
        {
            do_action('woocommerce_api_razorpay');
            $this->fail('expected a redirect');
        }
        catch (RuntimeException $e)
        {
            $this->assertEquals('redirect:' . wc_get_cart_url(), $e->getMessage());
        }

        $fresh = wc_get_order($order->get_id());
        $this->assertCount(0, $payment->refunds);
        $this->assertEquals('cancelled', $fresh->get_status());
        $this->assertStringContainsString('already blocked, no action taken', $this->noteText($fresh));
        $this->assertStringNotContainsString('check in the Razorpay Dashboard', $this->noteText($fresh));
    }

    public function testSecondBlockWhileFirstStillPendingAsksMerchantToCheck()
    {
        $order = $this->pendingOrder();
        $this->blockFlag($order); // the first run flagged it but has not settled it (still pending)
        $payment = $this->payment();

        $fresh = $this->block($order, $payment);

        $this->assertCount(0, $payment->refunds);
        $this->assertEquals('pending', $fresh->get_status());
        $this->assertStringContainsString('check in the Razorpay Dashboard that payment pay_X was refunded', $this->noteText($fresh));
    }

    public function testRefundFailureHoldsTheOrderBeforeTelemetry()
    {
        $order                 = $this->pendingOrder();
        $payment               = $this->payment();
        $payment->throwMessage = 'gateway timeout';
        $statusAtTelemetry     = null;
        $notesAtTelemetry      = '';

        $tracker = Mockery::mock('stdClass');
        $tracker->shouldReceive('rzpTrackDataLake')->once()->andReturnUsing(function () use ($order, &$statusAtTelemetry, &$notesAtTelemetry)
        {
            $statusAtTelemetry = wc_get_order($order->get_id())->get_status();
            $notesAtTelemetry  = $this->noteText($order);
        });
        $this->instance->shouldReceive('newTrackPluginInstrumentation')->andReturn($tracker);

        $this->block($order, $payment);

        $this->assertEquals('on-hold', $statusAtTelemetry);
        $this->assertStringContainsString('Automatic refund of payment pay_X failed', $notesAtTelemetry);
    }

    public function testBrowserCallbackGetsNoticeAndCartRedirect()
    {
        $order = $this->pendingOrder();

        $this->instance->shouldReceive('add_notice')->once()
            ->with('Your gift card balance was insufficient, so your order could not be placed. Any amount charged will be refunded.', 'error');
        add_filter('wp_redirect', function ($location)
        {
            throw new RuntimeException('redirect:' . $location);
        });
        remove_all_actions('woocommerce_api_razorpay');
        add_action('woocommerce_api_razorpay', function () use ($order)
        {
            $this->block($order, $this->payment());
        });

        try
        {
            do_action('woocommerce_api_razorpay');
            $this->fail('expected a redirect');
        }
        catch (RuntimeException $e)
        {
            $this->assertEquals('redirect:' . wc_get_cart_url(), $e->getMessage());
        }

        $this->assertEquals('cancelled', wc_get_order($order->get_id())->get_status());
    }

    public function testWebhookOrCronGetsNoNoticeAndNoRedirect()
    {
        $order = $this->pendingOrder();

        $this->instance->shouldReceive('add_notice')->never();
        add_filter('wp_redirect', function ($location)
        {
            throw new RuntimeException('unexpected redirect');
        });

        $fresh = $this->block($order, $this->payment());

        $this->assertEquals('cancelled', $fresh->get_status());
    }

    // ------------------------------------------- update1ccOrderWC gate

    public function testOrderWithoutGiftCardRunsExistingPromotions()
    {
        $order = $this->pendingOrder();
        $this->stubApi(array(array('type' => 'offer', 'code' => 'C1', 'value' => 100)), $this->payment());

        $this->instance->shouldReceive('giftCardPromotionFailure')->never();
        $this->instance->shouldReceive('handlePromotions')->once()->andReturn(100);

        $this->assertNotFalse($this->instance->update1ccOrderWC($order, $order->get_id(), 'pay_X'));
        $this->assertSame('', wc_get_order($order->get_id())->get_meta(WC_Razorpay::GIFTCARD_BLOCKED_META));
    }

    public function testTerraOnlyOrderRunsExistingPromotionsUnchecked()
    {
        $order = $this->pendingOrder();
        $this->stubApi(array(array('type' => 'terra_wallet', 'code' => 'W', 'value' => 100)), $this->payment());

        $this->instance->shouldReceive('giftCardPromotionFailure')->never();
        $this->instance->shouldReceive('handlePromotions')->once()->andReturn(100);

        $this->instance->update1ccOrderWC($order, $order->get_id(), 'pay_X');
    }

    public function testGiftCardOrderWithBalanceRunsExistingPromotions()
    {
        $this->yithCard(true, 500);
        $order = $this->pendingOrder();
        $this->stubApi(array($this->giftCard('GC1', 30000)), $this->payment());

        $fetched = array('promotions' => array($this->giftCard('GC1', 30000)), 'notes' => array(), 'line_items_total' => 0, 'shipping_fee' => 0);
        $this->instance->shouldReceive('handlePromotions')->once()
            ->with($fetched, Mockery::type('WC_Order'), $order->get_id(), 'pay_X')
            ->andReturn(30000);

        $this->assertNotFalse($this->instance->update1ccOrderWC($order, $order->get_id(), 'pay_X'));
        $this->assertSame('', wc_get_order($order->get_id())->get_meta(WC_Razorpay::GIFTCARD_BLOCKED_META));
    }

    public function testGiftCardOrderShortBalanceIsBlockedBeforeAnyDebit()
    {
        $this->yithCard(true, 100);
        $order   = $this->pendingOrder();
        $payment = $this->payment();
        $this->stubApi(array($this->giftCard('GC1', 30000)), $payment);

        $this->instance->shouldReceive('handlePromotions')->never();

        $this->assertFalse($this->instance->update1ccOrderWC($order, $order->get_id(), 'pay_X'));

        $fresh = wc_get_order($order->get_id());
        $this->assertEquals('cancelled', $fresh->get_status());
        $this->assertCount(1, $payment->refunds);
        $this->assertStringContainsString('cannot cover', $fresh->get_meta(WC_Razorpay::GIFTCARD_BLOCKED_META));
    }

    public function testExceptionInTheCheckBlocksTheOrder()
    {
        $order   = $this->pendingOrder();
        $payment = $this->payment();
        $this->stubApi(array($this->giftCard('GC1', 30000)), $payment);

        $this->instance->shouldReceive('giftCardPromotionFailure')->andThrow(new Error('plugin error'));
        $this->instance->shouldReceive('handlePromotions')->never();

        $this->assertFalse($this->instance->update1ccOrderWC($order, $order->get_id(), 'pay_X'));
        $this->assertEquals('cancelled', wc_get_order($order->get_id())->get_status());
        $this->assertCount(1, $payment->refunds);
        $this->assertStringContainsString('gift card check error', wc_get_order($order->get_id())->get_meta(WC_Razorpay::GIFTCARD_BLOCKED_META));
    }

    public function testSecondCardInvalidBlocksBeforeAnyDebit()
    {
        $this->pimwickCard(array('500.0000'));
        $order = $this->pendingOrder();
        $this->stubApi(array($this->giftCard('GC1', 100), $this->giftCard('NOPE', 100)), $this->payment());

        $this->instance->shouldReceive('handlePromotions')->never();

        $this->assertFalse($this->instance->update1ccOrderWC($order, $order->get_id(), 'pay_X'));
        $this->assertStringContainsString('ending NOPE cannot cover', wc_get_order($order->get_id())->get_meta(WC_Razorpay::GIFTCARD_BLOCKED_META));
    }

    public function testShortWalletInGiftCardOrderBlocksBeforeAnyDebit()
    {
        $this->yithCard(true, 500);
        $this->activatePlugin('woo-wallet/woo-wallet.php');
        $GLOBALS['rzp_test_woo_wallet_balance'] = 10;

        $order = $this->pendingOrder();
        $this->stubApi(array($this->giftCard('GC1', 100), array('type' => 'terra_wallet', 'code' => 'W', 'value' => 5000)), $this->payment());

        $this->instance->shouldReceive('handlePromotions')->never();

        $this->assertFalse($this->instance->update1ccOrderWC($order, $order->get_id(), 'pay_X'));
        $this->assertStringContainsString('Terra Wallet balance', wc_get_order($order->get_id())->get_meta(WC_Razorpay::GIFTCARD_BLOCKED_META));
    }

    public function testGuardOffRunsExistingPromotionsEvenWhenShort()
    {
        $this->settings['giftcard_balance_guard'] = 'no';
        $this->yithCard(true, 100);
        $order = $this->pendingOrder();
        $this->stubApi(array($this->giftCard('GC1', 30000)), $this->payment());

        $this->instance->shouldReceive('giftCardPromotionFailure')->never();
        $this->instance->shouldReceive('handlePromotions')->once()->andReturn(30000);

        $this->instance->update1ccOrderWC($order, $order->get_id(), 'pay_X');

        $this->assertSame('', wc_get_order($order->get_id())->get_meta(WC_Razorpay::GIFTCARD_BLOCKED_META));
    }


    public function testGuardSettingThroughRealSettings()
    {
        update_option('woocommerce_razorpay_settings', array('key_id' => 'k'));
        $this->assertTrue((new WC_Razorpay(false))->isGiftCardGuardEnabled(), 'not saved yet');

        update_option('woocommerce_razorpay_settings', array('giftcard_balance_guard' => 'yes'));
        $this->assertTrue((new WC_Razorpay(false))->isGiftCardGuardEnabled(), 'yes');

        update_option('woocommerce_razorpay_settings', array('giftcard_balance_guard' => 'no'));
        $this->assertFalse((new WC_Razorpay(false))->isGiftCardGuardEnabled(), 'no');
    }

    // ---------------------------------------------------- updateOrder

    public function testBlockedOrderIsNeverCompletedWhateverItsStatus()
    {
        $this->settings['giftcard_balance_guard'] = 'no'; // the guard on blocked orders ignores the kill switch

        foreach (array('pending', 'cancelled', 'on-hold', 'failed') as $status)
        {
            $order = $this->pendingOrder();
            $this->enable1cc($order);
            $order->update_status($status);
            $this->blockFlag($order);

            $this->instance->updateOrder($order, true, '', 'pay_X', null, true);

            $fresh = wc_get_order($order->get_id());
            $this->assertEquals($status, $fresh->get_status(), $status);
            $this->assertStringNotContainsString('Razorpay payment successful', $this->noteText($fresh), $status);
        }
    }

    public function testBlockedOrderIsNotFlippedToFailed()
    {
        $order = $this->pendingOrder();
        $order->update_status('cancelled');
        $this->blockFlag($order);

        $this->instance->updateOrder($order, false, 'failed', 'pay_X', null, true);

        $this->assertEquals('cancelled', wc_get_order($order->get_id())->get_status());
    }


    public function testUpdateOrderStopsWhenUpdate1ccBlocked()
    {
        $order = $this->pendingOrder();
        $this->enable1cc($order);

        $this->instance->shouldReceive('update1ccOrderWC')->once()->andReturn(false);

        $this->instance->updateOrder($order, true, '', 'pay_X', null, true);

        $fresh = wc_get_order($order->get_id());
        $this->assertEquals('pending', $fresh->get_status());
        $this->assertStringNotContainsString('Razorpay payment successful', $this->noteText($fresh));
    }

    public function testConcurrentBlockStopsCompletion()
    {
        $order = $this->pendingOrder();
        $this->enable1cc($order);

        // This run checked the order and passed; a concurrent run blocked it meanwhile
        $this->instance->shouldReceive('update1ccOrderWC')->once()->andReturnUsing(function () use ($order)
        {
            $this->setCheckedOrderId($order->get_id());
            $this->blockFlag(wc_get_order($order->get_id()));

            return null;
        });

        $this->instance->updateOrder($order, true, '', 'pay_X', null, true);

        $fresh = wc_get_order($order->get_id());
        $this->assertEquals('pending', $fresh->get_status());
        $this->assertStringNotContainsString('Razorpay payment successful', $this->noteText($fresh));
    }

    public function testCheckedGiftCardOrderThatPassedCompletes()
    {
        $order = $this->pendingOrder();
        $this->enable1cc($order);

        $this->instance->shouldReceive('update1ccOrderWC')->once()->andReturnUsing(function () use ($order)
        {
            $this->setCheckedOrderId($order->get_id());

            return null;
        });

        $this->instance->updateOrder($order, true, '', 'pay_X', null, true);

        $this->assertStringContainsString('Razorpay payment successful', $this->noteText(wc_get_order($order->get_id())));
    }

    public function testAnotherOrdersBlockDoesNotStopThisOrder()
    {
        $other = $this->pendingOrder();
        $this->blockFlag($other);
        $this->setCheckedOrderId($other->get_id());

        $order = $this->pendingOrder();
        $this->enable1cc($order);
        $this->instance->shouldReceive('update1ccOrderWC')->once()->andReturn(null);

        $this->instance->updateOrder($order, true, '', 'pay_X', null, true);

        $this->assertStringContainsString('Razorpay payment successful', $this->noteText(wc_get_order($order->get_id())));
    }

    public function testExceptionWithoutBlockStillCompletesLikeBefore()
    {
        $order = $this->pendingOrder();
        $this->enable1cc($order);

        $this->instance->shouldReceive('update1ccOrderWC')->once()->andThrow(new Exception('api timeout'));

        $this->instance->updateOrder($order, true, '', 'pay_X', null, true);

        $this->assertStringContainsString('Razorpay payment successful', $this->noteText(wc_get_order($order->get_id())));
    }


    // ------------------------------------------------------ prepay COD

    public function testPrepayCodRefusesBlockedOrderBeforeChangingIt()
    {
        update_option('woocommerce_razorpay_settings', array('key_id' => 'rzp_test_key', 'key_secret' => 'test_secret'));

        $order = $this->pendingOrder();
        $order->update_meta_data('is_magic_checkout_order', 'yes');
        $order->update_meta_data('razorpay_order_id_1cc' . $order->get_id(), 'order_TEST');
        $order->update_status('on-hold');
        $this->blockFlag($order);

        $request = new WP_REST_Request('POST', '/1cc/v1/cod/order/prepay');
        $request->set_param('woocommerce_order_id', $order->get_id());
        $request->set_param('razorpay_payment_id', 'pay_X');
        $request->set_param('razorpay_order_id', 'order_TEST');
        $request->set_param('razorpay_signature', hash_hmac('sha256', 'order_TEST|pay_X', 'test_secret'));
        $request->set_param('razorpay_offer', 0);

        $response = prepayCODOrder($request);

        $this->assertEquals(400, $response->get_status());
        $this->assertEquals('woocommerce_order_gift_card_blocked', $response->get_data()['code']);
        $this->assertEquals('on-hold', wc_get_order($order->get_id())->get_status());
    }
}

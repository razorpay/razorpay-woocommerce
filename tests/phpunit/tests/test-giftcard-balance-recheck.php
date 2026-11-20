<?php

/**
 * PLUG-04: post-payment gift card balance re-check in handlePromotions() and the
 * refund/flag behaviour of processRefundForOrdersWithGiftCard().
 *
 * @covers \WC_Razorpay::handlePromotions
 * @covers \WC_Razorpay::isGiftCardBalanceInsufficient
 * @covers \WC_Razorpay::processRefundForOrdersWithGiftCard
 */

if (class_exists('YITH_YWGC_Gift_Card') === false)
{
    /**
     * Minimal YITH stand-in; behaviour is driven through the static $fixture.
     */
    class YITH_YWGC_Gift_Card
    {
        public static $fixture = array('exists' => true, 'balance' => 0, 'id' => 0);

        public static $updatedBalance = null;

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

        public function update_balance($balance)
        {
            self::$updatedBalance = $balance;
        }

        public function register_order($orderId)
        {
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
                return isset($GLOBALS['rzp_test_woo_wallet_balance']) ? $GLOBALS['rzp_test_woo_wallet_balance'] : 0;
            }
        };

        return (object) array('wallet' => $wallet);
    }
}

class Test_GiftCardBalanceRecheck extends WP_UnitTestCase
{
    // Verifies once()/never() expectations and counts them as assertions
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    private $instance;

    private $guardOn = true;

    // Current runRefund() stubs; the Mockery expectations are registered once and read these
    private $refundApi = null;

    private $refundNotices = array();

    private $refundMocksRegistered = false;

    public function setUp(): void
    {
        parent::setUp();

        $this->instance = Mockery::mock('WC_Razorpay')->makePartial()->shouldAllowMockingProtectedMethods();

        // Kill switch explicitly ON for every test below unless a test flips $this->guardOn
        $this->guardOn = true;
        $this->instance->shouldReceive('isGiftCardGuardEnabled')->andReturnUsing(function ()
        {
            return $this->guardOn;
        });

        YITH_YWGC_Gift_Card::$updatedBalance = null;
        YITH_YWGC_Gift_Card::$fixture        = array('exists' => true, 'balance' => 0, 'id' => 0);

        $this->refundApi             = null;
        $this->refundNotices         = array();
        $this->refundMocksRegistered = false;

        $GLOBALS['rzp_test_woo_wallet_balance'] = 0;
    }

    public function tearDown(): void
    {
        delete_option('woocommerce_razorpay_settings');
        remove_all_filters('pre_option_active_plugins');
        remove_all_filters('wp_redirect');
        parent::tearDown();
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

    private function razorpayData($paise)
    {
        return array(
            'amount_paid' => $paise,
            'promotions'  => array(
                array('type' => 'gift_card', 'code' => 'GC1', 'value' => $paise),
            ),
        );
    }

    private function yithCard($exists, $balance, $postStatus = 'publish')
    {
        $postId = self::factory()->post->create(array('post_status' => $postStatus));

        YITH_YWGC_Gift_Card::$fixture = array('exists' => $exists, 'balance' => $balance, 'id' => $postId);
    }

    /**
     * Run handlePromotions for a YITH card and report whether a refund / debit happened.
     */
    private function runYith($paise)
    {
        $this->activatePlugin('yith-woocommerce-gift-cards/init.php');

        $order = wc_create_order();
        $this->instance->shouldReceive('processRefundForOrdersWithGiftCard')->times(0)->byDefault();

        $refunded = false;
        $this->instance->shouldReceive('processRefundForOrdersWithGiftCard')
            ->andReturnUsing(function () use (&$refunded)
            {
                $refunded = true;
            });

        $this->instance->handlePromotions($this->razorpayData($paise), $order, $order->get_id(), 'pay_X');

        return array('refunded' => $refunded, 'debited' => YITH_YWGC_Gift_Card::$updatedBalance !== null);
    }

    public function testYithSufficientBalanceDebitsWithoutRefund()
    {
        $this->yithCard(true, 500);

        $result = $this->runYith(30000);

        $this->assertFalse($result['refunded']);
        $this->assertTrue($result['debited']);
        $this->assertEquals(200, YITH_YWGC_Gift_Card::$updatedBalance);
    }

    public function testYithExactBalanceBoundaryDebits()
    {
        $this->yithCard(true, '300.00');

        $result = $this->runYith(30000);

        $this->assertFalse($result['refunded']);
        $this->assertTrue($result['debited']);
    }

    public function testYithOnePaiseOverBalanceRefundsAndDoesNotDebit()
    {
        $this->yithCard(true, '300.00');

        $result = $this->runYith(30001);

        $this->assertTrue($result['refunded']);
        $this->assertFalse($result['debited']);
    }

    public function testYithMissingCardRefunds()
    {
        $this->yithCard(false, 500);

        $result = $this->runYith(100);

        $this->assertTrue($result['refunded']);
        $this->assertFalse($result['debited']);
    }

    public function testYithTrashedCardRefunds()
    {
        $this->yithCard(true, 500, 'trash');

        $result = $this->runYith(100);

        $this->assertTrue($result['refunded']);
        $this->assertFalse($result['debited']);
    }

    public function testYithNonNumericBalanceRefunds()
    {
        $this->yithCard(true, null);

        $result = $this->runYith(100);

        $this->assertTrue($result['refunded']);
        $this->assertFalse($result['debited']);
    }

    /**
     * Pimwick: create the two tables as test (temporary) tables and seed them.
     */
    private function pimwickCard(array $activityAmounts)
    {
        global $wpdb;

        $wpdb->pimwick_gift_card          = $wpdb->prefix . 'pimwick_gift_card';
        $wpdb->pimwick_gift_card_activity = $wpdb->prefix . 'pimwick_gift_card_activity';

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->pimwick_gift_card} (pimwick_gift_card_id BIGINT PRIMARY KEY, number VARCHAR(64))");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->pimwick_gift_card_activity} (id BIGINT AUTO_INCREMENT PRIMARY KEY, pimwick_gift_card_id BIGINT, amount DECIMAL(15,4))");
        $wpdb->query("DELETE FROM {$wpdb->pimwick_gift_card}");
        $wpdb->query("DELETE FROM {$wpdb->pimwick_gift_card_activity}");

        $wpdb->insert($wpdb->pimwick_gift_card, array('pimwick_gift_card_id' => 7, 'number' => 'GC1'));

        foreach ($activityAmounts as $amount)
        {
            $wpdb->insert($wpdb->pimwick_gift_card_activity, array('pimwick_gift_card_id' => 7, 'amount' => $amount));
        }
    }

    private function runPimwick($paise)
    {
        $this->activatePlugin('pw-woocommerce-gift-cards/pw-gift-cards.php');

        $order    = wc_create_order();
        $refunded = false;
        $debited  = false;

        $this->instance->shouldReceive('processRefundForOrdersWithGiftCard')
            ->andReturnUsing(function () use (&$refunded)
            {
                $refunded = true;
            });
        $this->instance->shouldReceive('debitGiftCards')
            ->andReturnUsing(function () use (&$debited)
            {
                $debited = true;
            });

        $this->instance->handlePromotions($this->razorpayData($paise), $order, $order->get_id(), 'pay_X');

        return array('refunded' => $refunded, 'debited' => $debited);
    }

    public function testPimwickSufficientBalanceDebitsWithoutRefund()
    {
        $this->pimwickCard(array('500.0000'));

        $result = $this->runPimwick(30000);

        $this->assertFalse($result['refunded']);
        $this->assertTrue($result['debited']);
    }

    public function testPimwickExactBalanceBoundaryDebits()
    {
        $this->pimwickCard(array('300.0000'));

        $result = $this->runPimwick(30000);

        $this->assertFalse($result['refunded']);
        $this->assertTrue($result['debited']);
    }

    public function testPimwickInsufficientBalanceRefundsAndDoesNotDebit()
    {
        $this->pimwickCard(array('299.9900'));

        $result = $this->runPimwick(30000);

        $this->assertTrue($result['refunded']);
        $this->assertFalse($result['debited']);
    }

    public function testPimwickNullSumRefunds()
    {
        $this->pimwickCard(array());

        $result = $this->runPimwick(100);

        $this->assertTrue($result['refunded']);
        $this->assertFalse($result['debited']);
    }

    public function testPimwickZeroBalanceRefunds()
    {
        $this->pimwickCard(array('0.0000'));

        $result = $this->runPimwick(100);

        $this->assertTrue($result['refunded']);
        $this->assertFalse($result['debited']);
    }

    /**
     * Drive processRefundForOrdersWithGiftCard. Interactive calls end in wp_redirect+exit; the redirect
     * filter throws so exit is never reached. Returns array(order, apiCalls, notices).
     */
    private function runRefund($order, $refundThrows, $interactive = true, $errorMessage = 'refund api down')
    {
        $calls = new stdClass();
        $calls->refund = 0;

        $payment = new class($refundThrows, $calls, $errorMessage)
        {
            private $throws;
            private $calls;
            private $message;

            public function __construct($throws, $calls, $message)
            {
                $this->throws  = $throws;
                $this->calls   = $calls;
                $this->message = $message;
            }

            public function fetch($id)
            {
                return $this;
            }

            public function refund($data)
            {
                $this->calls->refund++;

                if ($this->throws === true)
                {
                    throw new Exception($this->message);
                }

                return (object) array('id' => 'rfnd_1');
            }
        };

        // wc_create_refund() rejects a refund larger than the order total, so give the order room for it
        if ((float) $order->get_total() < 1000)
        {
            $order->set_total(1000);
            $order->save();
        }

        $this->refundApi     = (object) array('payment' => $payment);
        $this->refundNotices = array();

        // Register once: Mockery keeps matching the first expectation, so a second registration would be ignored
        if ($this->refundMocksRegistered === false)
        {
            $tracker = Mockery::mock('stdClass');
            $tracker->shouldReceive('rzpTrackDataLake')->andReturn(null);

            $this->instance->shouldReceive('getRazorpayApiInstance')->andReturnUsing(function ()
            {
                return $this->refundApi;
            });
            $this->instance->shouldReceive('newTrackPluginInstrumentation')->andReturn($tracker);
            $this->instance->shouldReceive('add_notice')->andReturnUsing(function ($message, $type = 'notice')
            {
                $this->refundNotices[] = array($message, $type);
            });

            $this->refundMocksRegistered = true;
        }

        if ($interactive === false)
        {
            add_filter('wp_doing_cron', '__return_true');
        }

        add_filter('wp_redirect', function ($location)
        {
            throw new RuntimeException('redirect:' . $location);
        });

        try
        {
            $this->instance->processRefundForOrdersWithGiftCard($order->get_id(), 'pay_X', 30000, '');
            $this->assertFalse($interactive, 'interactive call must redirect');
        }
        catch (RuntimeException $e)
        {
            $this->assertTrue($interactive, 'cron call must not redirect/exit');
            $this->assertStringStartsWith('redirect:' . wc_get_cart_url(), $e->getMessage());
        }

        remove_filter('wp_doing_cron', '__return_true');

        return array(wc_get_order($order->get_id()), $calls, $this->refundNotices);
    }

    private function noteText($order)
    {
        return implode(' ', wp_list_pluck(wc_get_order_notes(array('order_id' => $order->get_id())), 'content'));
    }

    public function testRefundSuccessSetsMetaAndCancels()
    {
        $order = wc_create_order();

        list($fresh, $calls) = $this->runRefund($order, false);

        $this->assertEquals('cancelled', $fresh->get_status());
        $this->assertContains('pay_X', $fresh->get_meta('_rzp_giftcard_refunded'));
        $this->assertStringContainsString('payment refunded', $this->noteText($fresh));
        $this->assertStringContainsString('Refund Id: rfnd_1', $this->noteText($fresh));
        $this->assertEquals(1, $calls->refund);
    }

    public function testAlreadyRefundedErrorIsDefinitiveMetaAndCancel()
    {
        $order = wc_create_order();

        list($fresh, , $notices) = $this->runRefund($order, true, true, 'The payment has been fully refunded already');

        $this->assertEquals('cancelled', $fresh->get_status());
        $this->assertContains('pay_X', $fresh->get_meta('_rzp_giftcard_refunded'));
        $this->assertNotEmpty($notices);
    }

    public function testTransientRefundFailureLeavesPendingNoMetaAndRetries()
    {
        $order = wc_create_order();

        list($fresh, $calls, $notices) = $this->runRefund($order, true, true, 'cURL timeout');

        $this->assertEquals('pending', $fresh->get_status());
        $this->assertSame('', $fresh->get_meta('_rzp_giftcard_refunded'));
        $this->assertStringContainsString('will retry on next callback/webhook', $this->noteText($fresh));
        $this->assertEquals('error', $notices[0][1]);

        // replay retries the refund
        list(, $calls2) = $this->runRefund($order, false);
        $this->assertEquals(1, $calls2->refund);
        $this->assertEquals('cancelled', wc_get_order($order->get_id())->get_status());
    }

    public function testRefundIsIdempotentAndDoesNotExitInCron()
    {
        $order = wc_create_order();

        list(, $calls1) = $this->runRefund($order, false, false);
        list($fresh, $calls2) = $this->runRefund($order, false, false);

        $this->assertEquals(1, $calls1->refund);
        $this->assertEquals(0, $calls2->refund, 'second run must not refund again');
        $this->assertContains('pay_X', $fresh->get_meta('_rzp_giftcard_refunded'));
    }

    public function testRefundOnCancelledOrderInCronIsIdempotent()
    {
        $order = wc_create_order();
        $order->update_status('cancelled');

        list(, $calls1) = $this->runRefund($order, false, false);
        list($fresh, $calls2) = $this->runRefund($order, false, false);

        $this->assertEquals(1, $calls1->refund);
        $this->assertEquals(0, $calls2->refund);
        $this->assertEquals('cancelled', $fresh->get_status());
        $this->assertContains('pay_X', $fresh->get_meta('_rzp_giftcard_refunded'));
    }

    public function testRefundDoesNotChangeProcessingStatus()
    {
        $order = wc_create_order();
        $order->update_status('processing');

        list($fresh) = $this->runRefund($order, false);

        $this->assertEquals('processing', $fresh->get_status());
        $this->assertContains('pay_X', $fresh->get_meta('_rzp_giftcard_refunded'));
    }

    /**
     * COD: nothing captured, so no refund call; flag + cancel and signal failure to the caller.
     */
    public function testCodGiftCardFailureNoRefundCancelsAndSignalsFailure()
    {
        $this->activatePlugin('pw-woocommerce-gift-cards/pw-gift-cards.php');
        $this->pimwickCard(array('1.0000'));

        $order = wc_create_order();

        $this->instance->shouldReceive('processRefundForOrdersWithGiftCard')->never();
        $this->instance->shouldReceive('debitGiftCards')->never();

        $result = $this->instance->handlePromotions($this->razorpayData(30000), $order, $order->get_id(), 'pay_X', 'cod');

        $fresh = wc_get_order($order->get_id());

        $this->assertFalse($result, 'false tells update1ccOrderWC to bail before coupons/prepay');
        $this->assertEquals('cancelled', $fresh->get_status());
        $this->assertContains('pay_X', $fresh->get_meta('_rzp_giftcard_refunded'));
        $this->assertStringContainsString('COD order; cancelled', $this->noteText($fresh));
    }

    public function testHandlePromotionsReturnsFalseOnPrepaidFailure()
    {
        $this->activatePlugin('pw-woocommerce-gift-cards/pw-gift-cards.php');
        $this->pimwickCard(array('1.0000'));

        $order = wc_create_order();

        $this->instance->shouldReceive('processRefundForOrdersWithGiftCard')->once();

        $this->assertFalse($this->instance->handlePromotions($this->razorpayData(30000), $order, $order->get_id(), 'pay_X', 'card'));
    }

    public function testHandlePromotionsReturnsTotalOnSuccess()
    {
        $this->activatePlugin('pw-woocommerce-gift-cards/pw-gift-cards.php');
        $this->pimwickCard(array('500.0000'));

        $order = wc_create_order();

        $this->instance->shouldReceive('debitGiftCards')->once();

        $this->assertSame(30000, $this->instance->handlePromotions($this->razorpayData(30000), $order, $order->get_id(), 'pay_X', 'card'));
    }

    /**
     * Completion guard: flagged order is never completed, whatever its status, note added once.
     */
    private function runUpdateOrder($order)
    {
        $this->instance->shouldReceive('update1ccOrderWC')->andReturn(null);
        $this->instance->shouldReceive('add_notice')->andReturn(null);

        $this->instance->updateOrder($order, true, '', 'pay_X');
    }

    public function testFlaggedOrderIsNotCompletedByUpdateOrder()
    {
        foreach (array('pending', 'cancelled', 'failed') as $status)
        {
            $order = wc_create_order();
            $order->update_status($status);
            $order->update_meta_data('_rzp_giftcard_refunded', array('pay_X'));
            $order->save();

            $this->runUpdateOrder($order);

            $fresh = wc_get_order($order->get_id());
            $this->assertEquals($status, $fresh->get_status(), "status $status must not change");
            $this->assertEmpty($fresh->get_date_paid());
            $this->assertStringNotContainsString('Razorpay payment successful', $this->noteText($fresh));
        }
    }

    public function testFlaggedOrderNoteAddedOnce()
    {
        $order = wc_create_order();
        $order->update_meta_data('_rzp_giftcard_refunded', array('pay_X'));
        $order->save();

        $this->runUpdateOrder($order);
        $this->runUpdateOrder($order);

        $this->assertEquals(1, substr_count($this->noteText(wc_get_order($order->get_id())), 'Order not completed'));
    }

    public function testUnflaggedOrderStillCompletes()
    {
        $order = wc_create_order();
        $order->set_payment_method('razorpay');
        $order->save();

        $this->runUpdateOrder($order);

        $this->assertStringContainsString('Razorpay payment successful', $this->noteText(wc_get_order($order->get_id())));
    }

    public function testFlaggedOrderFailureCallbackDoesNotFlipStatus()
    {
        foreach (array('pending', 'cancelled') as $status)
        {
            $order = wc_create_order();
            $order->update_status($status);
            $order->update_meta_data('_rzp_giftcard_refunded', array('pay_X'));
            $order->save();

            $this->instance->shouldReceive('add_notice')->andReturn(null);
            $this->instance->updateOrder($order, false, 'The payment has failed.', 'pay_X', null, true);

            $this->assertEquals($status, wc_get_order($order->get_id())->get_status(), 'success=false must not flip a flagged order to failed');
        }
    }

    public function testUpdateOrderHonoursUpdate1ccBail()
    {
        $order = wc_create_order();
        $order->set_payment_method('razorpay');
        $order->save();

        $this->instance->shouldReceive('add_notice')->andReturn(null);
        $this->instance->shouldReceive('update1ccOrderWC')->once()->andReturn(false);
        update_option('woocommerce_razorpay_settings', array('enable_1cc' => 'yes')); // is1ccEnabled() reads this
        $order->update_meta_data('is_magic_checkout_order', 'yes');
        $order->save();

        $this->instance->updateOrder($order, true, '', 'pay_X');

        $fresh = wc_get_order($order->get_id());
        $this->assertStringNotContainsString('Razorpay payment successful', $this->noteText($fresh));
        $this->assertEquals('pending', $fresh->get_status());
    }

    private function enable1cc($order)
    {
        update_option('woocommerce_razorpay_settings', array('enable_1cc' => 'yes'));
        $order->update_meta_data('is_magic_checkout_order', 'yes');
        $order->set_payment_method('razorpay');
        $order->save();
    }

    public function testUpdateOrderReturnsWithoutCompletingWhenLockHeld()
    {
        $order = wc_create_order();
        $this->enable1cc($order);
        set_transient('wc_order_under_process_' . $order->get_id(), true, 300);

        $this->instance->shouldReceive('update1ccOrderWC')->never();
        $this->instance->shouldReceive('add_notice')->andReturn(null);

        $this->instance->updateOrder($order, true, '', 'pay_X');

        $fresh = wc_get_order($order->get_id());
        $this->assertEquals('pending', $fresh->get_status());
        $this->assertStringNotContainsString('Razorpay payment successful', $this->noteText($fresh));
        $this->assertTrue($this->instance->giftCardRetryPending);
    }

    public function testUpdateOrderFailsClosedWhenUpdate1ccThrows()
    {
        $order = wc_create_order();
        $this->enable1cc($order);

        $this->instance->shouldReceive('update1ccOrderWC')->andThrow(new Exception('boom'));
        $this->instance->shouldReceive('add_notice')->andReturn(null);

        $this->instance->updateOrder($order, true, '', 'pay_X');

        $fresh = wc_get_order($order->get_id());
        $this->assertEquals('pending', $fresh->get_status());
        $this->assertStringNotContainsString('Razorpay payment successful', $this->noteText($fresh));
        $this->assertTrue($this->instance->giftCardRetryPending);
    }

    /**
     * Drives the REAL failPromotion (via handlePromotions): lock is cleared before the refund,
     * and the order is never completed while or after the lock was held.
     */
    public function testRealFailPromotionClearsLockAndDoesNotComplete()
    {
        $this->activatePlugin('pw-woocommerce-gift-cards/pw-gift-cards.php');
        $this->pimwickCard(array('1.0000'));

        $order = wc_create_order();
        $this->enable1cc($order);
        set_transient('wc_order_under_process_' . $order->get_id(), true, 300);

        $lockSeenDuringRefund = null;
        $orderId              = $order->get_id();

        $this->instance->shouldReceive('processRefundForOrdersWithGiftCard')->once()->andReturnUsing(function () use (&$lockSeenDuringRefund, $orderId)
        {
            $lockSeenDuringRefund = get_transient('wc_order_under_process_' . $orderId);
            return false;
        });

        $result = $this->instance->handlePromotions($this->razorpayData(30000), $order, $orderId, 'pay_X', 'card');

        $this->assertFalse($result);
        $this->assertFalse($lockSeenDuringRefund, 'lock must be cleared before the refund call');
        $this->assertFalse(get_transient('wc_order_under_process_' . $orderId));

        $fresh = wc_get_order($orderId);
        $this->assertStringNotContainsString('Razorpay payment successful', $this->noteText($fresh));
        $this->assertNotEquals('processing', $fresh->get_status());
    }

    public function testRefundPathClearsLockBeforeApiCall()
    {
        $order = wc_create_order();
        set_transient('wc_order_under_process_' . $order->get_id(), true, 300);

        $this->runRefund($order, false);

        $this->assertFalse(get_transient('wc_order_under_process_' . $order->get_id()));
    }

    public function testTransientFailureSetsRetryFlagDefinitiveDoesNot()
    {
        $order = wc_create_order();
        $this->runRefund($order, true, false, 'cURL timeout');
        $this->assertTrue($this->instance->giftCardRetryPending);

        $this->instance->giftCardRetryPending = false;
        $order2 = wc_create_order();
        $this->runRefund($order2, false, false);
        $this->assertFalse($this->instance->giftCardRetryPending);
    }

    public function testTerraWalletFailureInPassOneDebitsNoGiftCard()
    {
        $this->activatePlugin('pw-woocommerce-gift-cards/pw-gift-cards.php');
        $this->pimwickCard(array('500.0000'));

        $order = wc_create_order();

        $this->instance->shouldReceive('terraWalletPromotionFailure')->andReturn('Terra Wallet Insufficient Balance');
        $this->instance->shouldReceive('processRefundForOrdersWithGiftCard')->once();
        $this->instance->shouldReceive('debitGiftCards')->never();

        $data = array(
            'amount_paid' => 60000,
            'promotions'  => array(
                array('type' => 'gift_card', 'code' => 'GC1', 'value' => 30000),
                array('type' => 'terra_wallet', 'code' => 'W', 'value' => 30000),
            ),
        );

        $this->assertFalse($this->instance->handlePromotions($data, $order, $order->get_id(), 'pay_X', 'card'));
    }

    /**
     * Kill switch: setting parsing (default ON, only an explicit 'no' disables).
     */
    public function testKillSwitchSettingParsing()
    {
        $cases = array(
            array('yes', true),
            array('', true),
            array(null, true),
            array(false, true),
            array('no', false),
        );

        foreach ($cases as $case)
        {
            list($value, $expected) = $case;

            $gateway = Mockery::mock('WC_Razorpay')->makePartial();
            $gateway->shouldReceive('getSetting')->with('giftcard_balance_guard')->andReturn($value);

            $this->assertSame($expected, $gateway->isGiftCardGuardEnabled(), 'value ' . var_export($value, true));
        }
    }

    public function testKillSwitchOffRestoresPreviousBehaviour()
    {
        $this->guardOn = false;
        $this->activatePlugin('pw-woocommerce-gift-cards/pw-gift-cards.php');
        $this->pimwickCard(array('1.0000')); // insufficient, but guard is off

        $order = wc_create_order();

        $this->instance->shouldReceive('processRefundForOrdersWithGiftCardLegacy')->never();
        $this->instance->shouldReceive('debitGiftCards')->once();

        // Pre-PR: the broken && chain never refunds, the card is debited as before
        $this->instance->handlePromotions($this->razorpayData(30000), $order, $order->get_id(), 'pay_X', 'card');
    }

    /**
     * Escape-hatch semantics: an ALREADY-flagged order stays blocked even with the guard off.
     */
    public function testKillSwitchOffStillBlocksAlreadyFlaggedOrder()
    {
        $this->guardOn = false;

        $order = wc_create_order();
        $order->set_payment_method('razorpay');
        $order->update_meta_data('_rzp_giftcard_refunded', array('pay_FIRST'));
        $order->save();

        $this->instance->shouldReceive('add_notice')->andReturn(null);
        $this->instance->shouldReceive('processRefundForOrdersWithGiftCard')->never(); // new money action stays gated

        $this->instance->updateOrder($order, true, '', 'pay_OTHER');

        $fresh = wc_get_order($order->get_id());

        $this->assertTrue($this->instance->isGiftCardFlagged($fresh));
        $this->assertStringNotContainsString('Razorpay payment successful', $this->noteText($fresh));
        $this->assertEquals('pending', $fresh->get_status());
    }

    /**
     * Guard OFF keeps master's lock shape: lock held -> skip update1ccOrderWC, order still completes.
     */
    public function testKillSwitchOffLockHeldSkipsPromotionsAndCompletes()
    {
        $this->guardOn = false;

        $order = wc_create_order();
        $this->enable1cc($order);
        set_transient('wc_order_under_process_' . $order->get_id(), true, 300);

        $this->instance->shouldReceive('update1ccOrderWC')->never();
        $this->instance->shouldReceive('add_notice')->andReturn(null);

        $this->instance->updateOrder($order, true, '', 'pay_X');

        $this->assertStringContainsString('Razorpay payment successful', $this->noteText(wc_get_order($order->get_id())));
        $this->assertFalse($this->instance->giftCardRetryPending);
    }

    /**
     * Guard OFF: a false return from update1ccOrderWC is ignored (master ignored the return value).
     */
    public function testKillSwitchOffBailIsIgnoredAndOrderCompletes()
    {
        $this->guardOn = false;

        $order = wc_create_order();
        $this->enable1cc($order);

        $this->instance->shouldReceive('update1ccOrderWC')->once()->andReturn(false);
        $this->instance->shouldReceive('add_notice')->andReturn(null);

        $this->instance->updateOrder($order, true, '', 'pay_X');

        $this->assertStringContainsString('Razorpay payment successful', $this->noteText(wc_get_order($order->get_id())));
    }

    /**
     * Guard OFF: an exception in the 1cc step is swallowed and the order completes (master behaviour).
     */
    public function testKillSwitchOffExceptionIsSwallowedAndOrderCompletes()
    {
        $this->guardOn = false;

        $order = wc_create_order();
        $this->enable1cc($order);

        $this->instance->shouldReceive('update1ccOrderWC')->andThrow(new Exception('boom'));
        $this->instance->shouldReceive('add_notice')->andReturn(null);

        $this->instance->updateOrder($order, true, '', 'pay_X');

        $this->assertStringContainsString('Razorpay payment successful', $this->noteText(wc_get_order($order->get_id())));
        $this->assertFalse($this->instance->giftCardRetryPending);
    }

    /**
     * Idempotent per order: a retry after promotions were applied does not debit twice.
     */
    public function testHandlePromotionsIsIdempotentPerOrder()
    {
        $this->activatePlugin('pw-woocommerce-gift-cards/pw-gift-cards.php');
        $this->pimwickCard(array('500.0000'));

        $order   = wc_create_order();
        $debited = 0;

        $this->instance->shouldReceive('debitGiftCards')->andReturnUsing(function () use (&$debited)
        {
            $debited++;
        });

        $first  = $this->instance->handlePromotions($this->razorpayData(30000), $order, $order->get_id(), 'pay_X', 'card');
        $second = $this->instance->handlePromotions($this->razorpayData(30000), wc_get_order($order->get_id()), $order->get_id(), 'pay_X', 'card');

        $this->assertEquals(1, $debited);
        $this->assertSame(30000, $first);
        $this->assertSame(30000, $second);
    }

    public function testKillSwitchOffRefundPathUsesLegacyAndSetsNoMeta()
    {
        $this->guardOn = false;

        $order = wc_create_order();

        $this->instance->shouldReceive('processRefundForOrdersWithGiftCardLegacy')->once()->andReturn(null);

        $this->instance->processRefundForOrdersWithGiftCard($order->get_id(), 'pay_X', 30000, '');

        $fresh = wc_get_order($order->get_id());
        $this->assertSame('', $fresh->get_meta('_rzp_giftcard_refunded'));
        $this->assertEquals('pending', $fresh->get_status());
    }

    public function testTransientRefundFailureInCronDoesNotCompleteViaHandlePromotions()
    {
        $this->activatePlugin('pw-woocommerce-gift-cards/pw-gift-cards.php');
        $this->pimwickCard(array('1.0000'));

        $order = wc_create_order();
        add_filter('wp_doing_cron', '__return_true');

        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturn((object) array('payment' => new class {
            public function fetch($id) { return $this; }
            public function refund($d) { throw new Exception('cURL timeout'); }
        }));
        $tracker = Mockery::mock('stdClass');
        $tracker->shouldReceive('rzpTrackDataLake')->andReturn(null);
        $this->instance->shouldReceive('newTrackPluginInstrumentation')->andReturn($tracker);
        $this->instance->shouldReceive('add_notice')->andReturn(null);

        $result = $this->instance->handlePromotions($this->razorpayData(30000), $order, $order->get_id(), 'pay_X', 'card');

        remove_filter('wp_doing_cron', '__return_true');

        $this->assertFalse($result, 'false makes update1ccOrderWC bail so updateOrder does not complete');
        $this->assertEquals('pending', wc_get_order($order->get_id())->get_status());
    }

    public function testLockClearedWhenUpdate1ccBails()
    {
        $order = wc_create_order();
        set_transient('wc_order_under_process_' . $order->get_id(), true, 300);

        $this->instance->shouldReceive('handlePromotions')->andReturn(false);
        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturn(
            new class {
                public $order;
                public $payment;
                public function __construct()
                {
                    $this->order   = new class { public function fetch($id) { return array('promotions' => array(), 'notes' => array(), 'line_items_total' => 0, 'shipping_fee' => 0); } };
                    $this->payment = new class { public function fetch($id) { return array('method' => 'card', 'amount' => 100); } };
                }
            }
        );

        $result = $this->instance->update1ccOrderWC($order, $order->get_id(), 'pay_X');

        $this->assertFalse($result);
        $this->assertFalse(get_transient('wc_order_under_process_' . $order->get_id()));
    }

    public function testSecondPaymentOnFlaggedOrderIsRefunded()
    {
        $order = wc_create_order();
        $order->update_meta_data('_rzp_giftcard_refunded', array('pay_FIRST'));
        $order->save();

        list($fresh, $calls) = $this->runRefund($order, false); // pay_X is a different payment id

        $this->assertEquals(1, $calls->refund);
        $this->assertEquals(array('pay_FIRST', 'pay_X'), $fresh->get_meta('_rzp_giftcard_refunded'));
    }

    public function testSamePaymentOnFlaggedOrderNotRefundedTwice()
    {
        $order = wc_create_order();
        $order->update_meta_data('_rzp_giftcard_refunded', array('pay_X'));
        $order->save();

        list(, $calls) = $this->runRefund($order, false);

        $this->assertEquals(0, $calls->refund);
    }

    public function testCodShopperMessageSaysNotPlacedNotRefunded()
    {
        $this->activatePlugin('pw-woocommerce-gift-cards/pw-gift-cards.php');
        $this->pimwickCard(array('1.0000'));

        $order   = wc_create_order();
        $notices = array();
        $this->instance->shouldReceive('add_notice')->andReturnUsing(function ($m, $t = 'notice') use (&$notices)
        {
            $notices[] = $m;
        });

        $this->instance->handlePromotions($this->razorpayData(30000), $order, $order->get_id(), 'pay_X', 'cod');

        $this->assertNotEmpty($notices);
        $this->assertStringContainsString('could not be placed', implode(' ', $notices));
        $this->assertStringNotContainsString('refunded', implode(' ', $notices));
    }

    public function testTerraWalletBailReturnsFalseInCron()
    {
        $this->activatePlugin('woo-wallet/woo-wallet.php');

        $order = wc_create_order();
        add_filter('wp_doing_cron', '__return_true');
        $this->instance->shouldReceive('processRefundForOrdersWithGiftCard')->once()->andReturn(false);

        $data   = array('amount_paid' => 100, 'promotions' => array(array('type' => 'terra_wallet', 'code' => 'W', 'value' => 100)));
        $result = $this->instance->handlePromotions($data, $order, $order->get_id(), 'pay_X', 'card');

        remove_filter('wp_doing_cron', '__return_true');

        $this->assertFalse($result);
    }

    public function testTerraWalletPluginInactiveBailsFalse()
    {
        $order = wc_create_order();
        $this->instance->shouldReceive('processRefundForOrdersWithGiftCard')->once()->andReturn(false);

        $data = array('amount_paid' => 100, 'promotions' => array(array('type' => 'terra_wallet', 'code' => 'W', 'value' => 100)));

        $this->assertFalse($this->instance->handlePromotions($data, $order, $order->get_id(), 'pay_X', 'card'));
    }

    /**
     * Blocked browser callback goes to the cart (not thank-you) with an error notice.
     */
    public function testFlaggedOrderCallbackRedirectsToCartNotThankYou()
    {
        $order = wc_create_order();
        $order->update_meta_data('_rzp_giftcard_refunded', array('pay_X'));
        $order->save();

        $this->instance->shouldReceive('add_notice')->once()->with(Mockery::type('string'), 'error');

        add_filter('wp_redirect', function ($location)
        {
            throw new RuntimeException('redirect:' . $location);
        });

        try
        {
            $this->instance->redirectFlaggedOrderToCart($order->get_id());
            $this->fail('expected redirect');
        }
        catch (RuntimeException $e)
        {
            $this->assertEquals('redirect:' . wc_get_cart_url(), $e->getMessage());
            $this->assertStringNotContainsString('order-received', $e->getMessage());
        }
    }

    public function testUnflaggedOrderDoesNotRedirectToCart()
    {
        $order = wc_create_order();

        $this->assertNull($this->instance->redirectFlaggedOrderToCart($order->get_id()));
    }

    /**
     * Multi-card: card 2 failing must not debit card 1 (validate all, then debit).
     */
    public function testSecondCardFailureDebitsNoCard()
    {
        $this->activatePlugin('pw-woocommerce-gift-cards/pw-gift-cards.php');
        $this->pimwickCard(array('500.0000'));

        $order    = wc_create_order();
        $refunded = 0;
        $debited  = 0;

        $this->instance->shouldReceive('processRefundForOrdersWithGiftCard')->andReturnUsing(function () use (&$refunded)
        {
            $refunded++;
        });
        $this->instance->shouldReceive('debitGiftCards')->andReturnUsing(function () use (&$debited)
        {
            $debited++;
        });

        $data = array(
            'amount_paid' => 60000,
            'promotions'  => array(
                array('type' => 'gift_card', 'code' => 'GC1', 'value' => 30000),
                array('type' => 'gift_card', 'code' => 'MISSING', 'value' => 30000),
            ),
        );

        $this->instance->handlePromotions($data, $order, $order->get_id(), 'pay_X');

        $this->assertEquals(1, $refunded);
        $this->assertEquals(0, $debited);
    }

    public function testBothCardsValidDebitsBoth()
    {
        $this->activatePlugin('pw-woocommerce-gift-cards/pw-gift-cards.php');
        $this->pimwickCard(array('500.0000'));

        $order   = wc_create_order();
        $debited = 0;

        $this->instance->shouldReceive('processRefundForOrdersWithGiftCard')->never();
        $this->instance->shouldReceive('debitGiftCards')->andReturnUsing(function () use (&$debited)
        {
            $debited++;
        });

        $data = array(
            'amount_paid' => 60000,
            'promotions'  => array(
                array('type' => 'gift_card', 'code' => 'GC1', 'value' => 30000),
                array('type' => 'gift_card', 'code' => 'GC1', 'value' => 30000),
            ),
        );

        $this->instance->handlePromotions($data, $order, $order->get_id(), 'pay_X');

        $this->assertEquals(2, $debited);
    }

    private function callProtected($method, array $args = array())
    {
        $reflection = new ReflectionMethod('WC_Razorpay', $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->instance, $args);
    }

    /**
     * Review #1: a retryable stop in updateOrder must leave the webhook row for cron to retry.
     */
    public function testCallbackLeavesWebhookRowWhenRetryPending()
    {
        $this->instance->giftCardRetryPending = true;
        $this->instance->shouldReceive('markWebhookRowProcessedByCallback')->never();

        $this->callProtected('markCallbackWebhookRow', array(123));

        $this->assertTrue($this->instance->giftCardRetryPending);
    }

    public function testCallbackMarksWebhookRowWhenNoRetryPending()
    {
        $this->instance->giftCardRetryPending = false;
        $this->instance->shouldReceive('markWebhookRowProcessedByCallback')->once()->with(123);

        $this->callProtected('markCallbackWebhookRow', array(123));
    }

    /**
     * Review #2: cron/webhook runs have no shopper session, so no notices are added there.
     */
    public function testCronRefundAddsNoShopperNotice()
    {
        $order = wc_create_order();

        list(, , $transientNotices) = $this->runRefund($order, true, false, 'cURL timeout');
        list(, , $successNotices)   = $this->runRefund($order, false, false);

        $this->assertSame(array(), $transientNotices);
        $this->assertSame(array(), $successNotices);
    }

    public function testInteractiveRefundStillAddsShopperNotice()
    {
        $order = wc_create_order();

        list(, , $notices) = $this->runRefund($order, false, true);

        $this->assertEquals(array(array('Payment refunded', 'error')), $notices);
    }

    /**
     * Cron retries a transient refund failure every 5 minutes; the order note is added only once.
     */
    public function testTransientRetryNoteAddedOnce()
    {
        $order = wc_create_order();

        $this->runRefund($order, true, false, 'cURL timeout');
        $this->runRefund($order, true, false, 'cURL timeout');
        list($fresh) = $this->runRefund($order, true, false, 'cURL timeout');

        $this->assertEquals(1, substr_count($this->noteText($fresh), 'refund failed transiently'));
        $this->assertEquals('pending', $fresh->get_status());
    }

    /**
     * Orders without promotions do not get the idempotency marker.
     */
    public function testNoPromotionsWritesNoMarker()
    {
        $order = wc_create_order();

        $result = $this->instance->handlePromotions(array('amount_paid' => 30000, 'promotions' => array()), $order, $order->get_id(), 'pay_X', 'card');

        $this->assertSame(0, $result);
        $this->assertSame('', wc_get_order($order->get_id())->get_meta('_rzp_1cc_promotions_applied'));
    }

    public function testPromotionsWriteMarker()
    {
        $this->activatePlugin('pw-woocommerce-gift-cards/pw-gift-cards.php');
        $this->pimwickCard(array('500.0000'));

        $order = wc_create_order();
        $this->instance->shouldReceive('debitGiftCards')->once();

        $this->instance->handlePromotions($this->razorpayData(30000), $order, $order->get_id(), 'pay_X', 'card');

        $this->assertEquals(1, wc_get_order($order->get_id())->get_meta('_rzp_1cc_promotions_applied'));
    }
}

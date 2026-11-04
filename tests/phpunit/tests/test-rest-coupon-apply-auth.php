<?php

/**
 * shortcut: file name sorts after test-razorpay-route.php because any REST dispatch earlier in the run
 * breaks testrzpAddPluginPage's global $submenu state; upgrade when that test sets up its own admin menu state.
 */
class Test_Coupon_Apply_Auth extends WP_UnitTestCase
{
    private $secret = 'abcdefghij0123456789'; // exactly 20 chars

    public function setUp(): void
    {
        parent::setUp();
        update_option('rzp1cc_hmac_secret', $this->secret, false);
        update_option('woocommerce_enable_coupons', 'yes');
        wp_set_current_user(0);
    }

    /**
     * The test bootstrap pins active_plugins through a pre_option filter, so extend that list.
     */
    private function activateSmartCoupons()
    {
        add_filter('pre_option_active_plugins', function ($plugins)
        {
            $plugins   = is_array($plugins) ? $plugins : array();
            $plugins[] = 'wt-smart-coupons-for-woocommerce/wt-smart-coupon.php';

            return $plugins;
        }, 20);
    }

    /**
     * Build a 1CC order (10000 paise) with a stored Razorpay order id.
     */
    private function makeOrder($razorpayOrderId = 'order_ABC', $customerId = 0)
    {
        $product = new WC_Product_Simple();
        $product->set_name('Test Saree');
        $product->set_regular_price('100');
        $product->save();

        $order = new WC_Order();
        $order->add_product(wc_get_product($product->get_id()), 1);
        $order->set_customer_id($customerId);
        $order->save();

        $orderId = $order->get_id();
        $order->update_meta_data('is_magic_checkout_order', 'yes');
        $order->update_meta_data('razorpay_order_id_1cc' . $orderId, $razorpayOrderId);
        $order->save();

        return $orderId;
    }

    private function makePercentCoupon($code = 'save10')
    {
        $coupon = new WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type('percent');
        $coupon->set_amount(10);
        $coupon->save();
    }

    private function dispatch(array $body, $sign = true, $secret = null)
    {
        $payload = wp_json_encode($body);

        $request = new WP_REST_Request('POST', '/1cc/v1/coupon/apply');
        $request->set_body($payload);
        $request->set_header('Content-Type', 'application/json');

        unset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']);
        if ($sign === true)
        {
            $useSecret = ($secret === null) ? $this->secret : $secret;
            $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] = hash_hmac('sha256', $payload, $useSecret);
        }

        return rest_get_server()->dispatch($request);
    }

    private function failureCode($response)
    {
        $data = $response->get_data();

        return isset($data['failure_code']) ? $data['failure_code'] : '';
    }

    // ---- Auth layer ----

    public function testMissingSignatureIsRejected()
    {
        $orderId = $this->makeOrder();

        $response = $this->dispatch(array('code' => 'save10', 'order_id' => $orderId, 'razorpay_order_id' => 'ABC'), false);

        $this->assertEquals(403, $response->get_status());
    }

    public function testInvalidSignatureIsRejected()
    {
        $orderId = $this->makeOrder();

        $response = $this->dispatch(array('code' => 'save10', 'order_id' => $orderId, 'razorpay_order_id' => 'ABC'), true, 'wrongsecret000000000');

        $this->assertEquals(403, $response->get_status());
    }

    public function testMissingSecretIsRejected()
    {
        $orderId = $this->makeOrder();
        delete_option('rzp1cc_hmac_secret');

        $response = $this->dispatch(array('code' => 'save10', 'order_id' => $orderId, 'razorpay_order_id' => 'ABC'));

        $this->assertEquals(403, $response->get_status());
    }

    // ---- Validation ----

    public function testMissingRazorpayOrderIdIsRejected()
    {
        $orderId = $this->makeOrder();

        $response = $this->dispatch(array('code' => 'save10', 'order_id' => $orderId));

        $this->assertEquals(400, $response->get_status());
        $this->assertEquals('VALIDATION_ERROR', $this->failureCode($response));
    }

    // ---- Ownership (WPScan #45854) ----

    /**
     * The reported attack: a signed caller names someone else's order to read its total via the discount.
     */
    public function testForeignRazorpayOrderIdIsRejectedWithoutDisclosingDiscount()
    {
        $this->makePercentCoupon();
        $victimOrderId = $this->makeOrder('order_VICTIM');

        $response = $this->dispatch(array('code' => 'save10', 'order_id' => $victimOrderId, 'razorpay_order_id' => 'ATTACKER'));

        $this->assertEquals(400, $response->get_status());
        $this->assertEquals('razorpay_order_id_mismatch', $this->failureCode($response));
        $this->assertArrayNotHasKey('promotion', $response->get_data());
    }

    public function testNonexistentOrderIsRejected()
    {
        $response = $this->dispatch(array('code' => 'save10', 'order_id' => 99999999, 'razorpay_order_id' => 'ABC'));

        $this->assertEquals(400, $response->get_status());
        $this->assertEquals('invalid_order', $this->failureCode($response));
    }

    public function testOrderWithoutStoredRazorpayOrderIdIsRejected()
    {
        $order = new WC_Order();
        $order->save();

        $response = $this->dispatch(array('code' => 'save10', 'order_id' => $order->get_id(), 'razorpay_order_id' => 'ABC'));

        $this->assertEquals(400, $response->get_status());
        $this->assertEquals('razorpay_order_not_found', $this->failureCode($response));
    }

    public function testCartIsNotBuiltWhenOwnershipCheckRejects()
    {
        $victimOrderId = $this->makeOrder('order_VICTIM');
        if (is_null(WC()->cart))
        {
            wc_load_cart();
        }
        WC()->cart->empty_cart();

        $this->dispatch(array('code' => 'save10', 'order_id' => $victimOrderId, 'razorpay_order_id' => 'ATTACKER'));

        $this->assertEquals(0, WC()->cart->get_cart_contents_count());
    }

    // ---- Happy path ----

    /**
     * MCS sends the Razorpay order id without the "order_" prefix; the plugin stores it with one.
     */
    public function testSignedRequestForOwnOrderAppliesCoupon()
    {
        $this->makePercentCoupon();
        $orderId = $this->makeOrder('order_ABC');

        $response = $this->dispatch(array('code' => 'save10', 'order_id' => $orderId, 'razorpay_order_id' => 'ABC'));

        $this->assertEquals(200, $response->get_status());
        $this->assertEquals(1000, $response->get_data()['promotion']['value']);
    }

    // ---- WT Smart Coupons user context (WPScan #46468) ----

    public function testSmartCouponsUserComesFromOrderNotRequestEmail()
    {
        $this->activateSmartCoupons();
        $adminId    = $this->factory->user->create(array('role' => 'administrator', 'user_email' => 'admin@victim.test'));
        $customerId = $this->factory->user->create(array('role' => 'customer', 'user_email' => 'shopper@victim.test'));
        $orderId    = $this->makeOrder('order_ABC', $customerId);

        $this->dispatch(array('code' => 'save10', 'order_id' => $orderId, 'razorpay_order_id' => 'ABC', 'email' => 'admin@victim.test'));

        $this->assertNotEquals($adminId, get_current_user_id());
        $this->assertEquals($customerId, get_current_user_id());
    }

    public function testSmartCouponsGuestOrderDoesNotAssumeEmailOwner()
    {
        $this->activateSmartCoupons();
        $this->factory->user->create(array('role' => 'administrator', 'user_email' => 'admin@victim.test'));
        $orderId = $this->makeOrder('order_ABC', 0);

        $this->dispatch(array('code' => 'save10', 'order_id' => $orderId, 'razorpay_order_id' => 'ABC', 'email' => 'admin@victim.test'));

        $this->assertEquals(0, get_current_user_id());
    }
}

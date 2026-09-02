<?php

class Test_Shipping_Info_Auth extends WP_UnitTestCase
{
    private $secret = 'abcdefghij0123456789'; // exactly 20 chars

    public function setUp(): void
    {
        parent::setUp();
        update_option('rzp1cc_hmac_secret', $this->secret, false);
    }

    /**
     * Build a 1CC order with a stored Razorpay order id.
     */
    private function makeOrder($razorpayOrderId = 'order_ABC', $is1cc = true)
    {
        $product = new WC_Product_Simple();
        $product->set_name('Test Saree');
        $product->set_regular_price('1000');
        $product->save();

        $order = new WC_Order();
        $order->add_product(wc_get_product($product->get_id()), 1);
        $order->save();

        $orderId = $order->get_id();

        if ($is1cc === true)
        {
            $order->update_meta_data('is_magic_checkout_order', 'yes');
            $order->update_meta_data('razorpay_order_id_1cc' . $orderId, $razorpayOrderId);
        }
        else
        {
            $order->update_meta_data('razorpay_order_id' . $orderId, $razorpayOrderId);
        }
        $order->save();

        return $orderId;
    }

    private function dispatch(array $body, $sign = true, $secret = null)
    {
        $payload = wp_json_encode($body);

        $request = new WP_REST_Request('POST', '/1cc/v1/shipping/shipping-info');
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

    private function addressPayload()
    {
        return array(
            array(
                'id'         => '1',
                'country'    => 'IN',
                'state_code' => 'MH',
                'zipcode'    => '400001',
                'city'       => 'Mumbai',
            ),
        );
    }

    /**
     * Registers a flat-rate shipping zone covering the country used by
     * addressPayload() (IN), mirroring the WC_Shipping_Zone + flat_rate
     * fixture pattern used by testGetShippingZone() in test-methods.php.
     * Without a matching zone/method, WooCommerce calculates zero shipping
     * rates and the meta-write path this guards never executes.
     */
    private function createFlatRateShippingZone()
    {
        $zone = new WC_Shipping_Zone();
        $zone->set_zone_name('Test Zone - IN');
        $zone->set_zone_order(0);
        $zone->save();
        $zone->add_location('IN', 'country');

        $instanceId = $zone->add_shipping_method('flat_rate');

        update_option('woocommerce_flat_rate_' . $instanceId . '_settings', array(
            'title'      => 'Flat rate',
            'tax_status' => 'none',
            'cost'       => '10',
        ));

        WC_Cache_Helper::get_transient_version('shipping', true);

        return $zone;
    }

    // ---- Auth layer (HMAC now enforced) ----
    //
    // The shipping-info route's permission_callback is checkHmacSignature, so
    // the three tests below now run and enforce the 403 contract for missing,
    // invalid, and unconfigured-secret signatures.

    public function testMissingSignatureIsRejected()
    {
        $orderId = $this->makeOrder();

        $response = $this->dispatch(array(
            'order_id'          => $orderId,
            'razorpay_order_id' => 'order_ABC',
            'addresses'         => $this->addressPayload(),
        ), false);

        $this->assertEquals(403, $response->get_status());
    }

    public function testInvalidSignatureIsRejected()
    {
        $orderId = $this->makeOrder();

        $response = $this->dispatch(array(
            'order_id'          => $orderId,
            'razorpay_order_id' => 'order_ABC',
            'addresses'         => $this->addressPayload(),
        ), true, 'wrongsecret000000000');

        $this->assertEquals(403, $response->get_status());
    }

    public function testMissingSecretIsRejected()
    {
        $orderId = $this->makeOrder();
        delete_option('rzp1cc_hmac_secret');

        $response = $this->dispatch(array(
            'order_id'          => $orderId,
            'razorpay_order_id' => 'order_ABC',
            'addresses'         => $this->addressPayload(),
        ));

        $this->assertEquals(403, $response->get_status());
    }

    // ---- Ownership layer (Task 10 makes these pass) ----

    /**
     * The IDOR regression test. An attacker holding a valid signature for their
     * own Razorpay order must not be able to write to a victim's order.
     */
    public function testMismatchedRazorpayOrderIdIsRejectedAndVictimMetaUnchanged()
    {
        $victimOrderId = $this->makeOrder('order_VICTIM');

        $response = $this->dispatch(array(
            'order_id'          => $victimOrderId,
            'razorpay_order_id' => 'order_ATTACKER',
            'addresses'         => $this->addressPayload(),
        ));

        $this->assertEquals(400, $response->get_status());
        $this->assertEquals('razorpay_order_id_mismatch', $response->get_data()['failure_code']);

        $victim = wc_get_order($victimOrderId);
        $this->assertEmpty($victim->get_meta('1cc_shippinginfo'),
            'victim order shipping metadata must not be written');
    }

    /**
     * MCS sends razorpay_order_id without the "order_" prefix on this route
     * (see gateways/woocommerce/types/dtos.go), while the plugin stores the
     * full id at order creation. Both forms name the same order, so the
     * ownership check must accept either. Observed live: a correctly signed
     * call was rejected with razorpay_order_id_mismatch on every attempt
     * because "order_X" !== "X".
     */
    public function testUnprefixedRazorpayOrderIdIsAccepted()
    {
        $orderId = $this->makeOrder('order_ABC');

        $response = $this->dispatch(array(
            'order_id'          => $orderId,
            'razorpay_order_id' => 'ABC',
            'addresses'         => $this->addressPayload(),
        ));

        $this->assertNotEquals('razorpay_order_id_mismatch',
            isset($response->get_data()['failure_code']) ? $response->get_data()['failure_code'] : '',
            'unprefixed id naming the same order must not be treated as a mismatch');
    }

    /**
     * The reverse skew: stored bare, received prefixed.
     */
    public function testPrefixedRazorpayOrderIdIsAcceptedWhenStoredBare()
    {
        $orderId = $this->makeOrder('ABC');

        $response = $this->dispatch(array(
            'order_id'          => $orderId,
            'razorpay_order_id' => 'order_ABC',
            'addresses'         => $this->addressPayload(),
        ));

        $this->assertNotEquals('razorpay_order_id_mismatch',
            isset($response->get_data()['failure_code']) ? $response->get_data()['failure_code'] : '',
            'prefixed id naming the same order must not be treated as a mismatch');
    }

    /**
     * Normalisation must not weaken the IDOR check: a genuinely different
     * order id is still rejected regardless of which side carries the prefix.
     */
    public function testForeignOrderIdIsStillRejectedAfterNormalisation()
    {
        $orderId = $this->makeOrder('order_ABC');

        foreach (array('order_XYZ', 'XYZ') as $foreignId)
        {
            $response = $this->dispatch(array(
                'order_id'          => $orderId,
                'razorpay_order_id' => $foreignId,
                'addresses'         => $this->addressPayload(),
            ));

            $this->assertEquals(400, $response->get_status());
            $this->assertEquals('razorpay_order_id_mismatch',
                $response->get_data()['failure_code'],
                "foreign id {$foreignId} must still be rejected");
        }
    }

    public function testNonexistentOrderIsRejected()
    {
        $response = $this->dispatch(array(
            'order_id'          => 99999999,
            'razorpay_order_id' => 'order_ABC',
            'addresses'         => $this->addressPayload(),
        ));

        $this->assertEquals(400, $response->get_status());
        $this->assertEquals('invalid_order', $response->get_data()['failure_code']);
    }

    public function testOrderWithoutStoredRazorpayOrderIdIsRejected()
    {
        $product = new WC_Product_Simple();
        $product->set_name('Bare Product');
        $product->set_regular_price('500');
        $product->save();

        $order = new WC_Order();
        $order->add_product(wc_get_product($product->get_id()), 1);
        $order->save();

        $response = $this->dispatch(array(
            'order_id'          => $order->get_id(),
            'razorpay_order_id' => 'order_ABC',
            'addresses'         => $this->addressPayload(),
        ));

        $this->assertEquals(400, $response->get_status());
        $this->assertEquals('razorpay_order_not_found', $response->get_data()['failure_code']);
    }

    public function testCartIsNotMutatedWhenOwnershipCheckRejects()
    {
        $victimOrderId = $this->makeOrder('order_VICTIM');

        if (is_null(WC()->cart))
        {
            wc_load_cart();
        }
        WC()->cart->empty_cart();

        $this->dispatch(array(
            'order_id'          => $victimOrderId,
            'razorpay_order_id' => 'order_ATTACKER',
            'addresses'         => $this->addressPayload(),
        ));

        $this->assertEquals(0, WC()->cart->get_cart_contents_count(),
            'rejected request must not load the victim order into the cart');
    }

    // ---- Validation layer (Task 9 makes this pass) ----

    public function testMissingRazorpayOrderIdIsRejectedWithoutNotice()
    {
        $orderId = $this->makeOrder();

        $response = $this->dispatch(array(
            'order_id'  => $orderId,
            'addresses' => $this->addressPayload(),
        ));

        $this->assertEquals(400, $response->get_status());
        $this->assertEquals('VALIDATION_ERROR', $response->get_data()['failure_code']);
    }

    // ---- Happy paths ----

    public function testValidSignedRequestOn1ccOrderSucceeds()
    {
        $orderId = $this->makeOrder('order_ABC', true);

        $response = $this->dispatch(array(
            'order_id'          => $orderId,
            'razorpay_order_id' => 'order_ABC',
            'addresses'         => $this->addressPayload(),
        ));

        $this->assertNotEquals(403, $response->get_status());
        $this->assertNotEquals(400, $response->get_status());
    }

    public function testValidSignedRequestOnStandardOrderSucceeds()
    {
        $orderId = $this->makeOrder('order_STD', false);

        $response = $this->dispatch(array(
            'order_id'          => $orderId,
            'razorpay_order_id' => 'order_STD',
            'addresses'         => $this->addressPayload(),
        ));

        $this->assertNotEquals(403, $response->get_status());
        $this->assertNotEquals(400, $response->get_status());
    }

    /**
     * Guards the add_post_meta -> update_post_meta fix. Two identical calls must
     * leave exactly one metadata value, not two stacked entries.
     *
     * A flat-rate shipping zone matching addressPayload()'s country (IN) is
     * registered first so shippingCalculatePackages1cc() -> prepareRatesResponse1cc()
     * actually calculates a rate and reaches the meta-write line at
     * includes/api/shipping-info.php:321-327. Without it, $package[0]['rates'] is
     * empty, prepareRatesResponse1cc() returns before the write, and the
     * assertion below would be vacuously satisfied by zero stored values.
     */
    public function testRepeatedCallsDoNotStackShippingMeta()
    {
        $this->createFlatRateShippingZone();

        $orderId = $this->makeOrder('order_ABC');

        $payload = array(
            'order_id'          => $orderId,
            'razorpay_order_id' => 'order_ABC',
            'addresses'         => $this->addressPayload(),
        );

        $this->dispatch($payload);
        $this->dispatch($payload);

        $values = get_post_meta($orderId, '1cc_shippinginfo', false);
        $this->assertCount(1, $values,
            'repeated calls must overwrite, not append, shipping metadata');
    }

    /**
     * A replayed signed request rewrites only its own order's data.
     */
    public function testReplayIsIdempotent()
    {
        $orderId = $this->makeOrder('order_ABC');

        $payload = array(
            'order_id'          => $orderId,
            'razorpay_order_id' => 'order_ABC',
            'addresses'         => $this->addressPayload(),
        );

        $first  = $this->dispatch($payload);
        $second = $this->dispatch($payload);

        $this->assertEquals($first->get_status(), $second->get_status());
    }
}

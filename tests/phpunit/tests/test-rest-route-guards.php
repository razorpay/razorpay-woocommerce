<?php

/**
 * Every 1CC route must have a real guard (razorpay-woocommerce-001): HMAC for the routes
 * Razorpay calls, the wp_rest nonce for the routes the shopper's browser calls.
 *
 * shortcut: file name sorts after test-razorpay-route.php because any REST dispatch earlier in the run
 * breaks testrzpAddPluginPage's global $submenu state; upgrade when that test sets up its own admin menu state.
 */
class Test_Rest_Route_Guards extends WP_UnitTestCase
{
    private $secret = 'abcdefghij0123456789'; // exactly 20 chars

    private $browserRoutes = array(
        '/1cc/v1/order/create',
        '/1cc/v1/abandoned-cart',
        '/1cc/v1/cart/fetch-cart',
        '/1cc/v1/cart/create-cart',
    );

    private $hmacRoutes = array(
        '/1cc/v1/coupon/list',
        '/1cc/v1/coupon/apply',
        '/1cc/v1/shipping/shipping-info',
        '/1cc/v1/cod/order/prepay',
    );

    public function setUp(): void
    {
        parent::setUp();
        update_option('rzp1cc_hmac_secret', $this->secret, false);
        wp_set_current_user(0);
        unset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']);
    }

    public function tearDown(): void
    {
        unset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']);
        parent::tearDown();
    }

    private function permissionCallback($route)
    {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey($route, $routes, $route . ' is not registered');

        return $routes[$route][0]['permission_callback'];
    }

    private function post($route, array $body, array $headers = array())
    {
        $request = new WP_REST_Request('POST', $route);
        $request->set_body(wp_json_encode($body));
        $request->set_header('Content-Type', 'application/json');
        foreach ($headers as $name => $value)
        {
            $request->set_header($name, $value);
        }

        return $request;
    }

    // ---- Registration ----

    /**
     * Catches a route added later with no guard. WordPress's own namespace index handlers
     * (array callbacks) are skipped; every plugin handler is a function name.
     */
    public function testEvery1ccHandlerHasARealGuard()
    {
        $checked = 0;

        foreach (rest_get_server()->get_routes() as $route => $handlers)
        {
            // giftcard/apply gets its HMAC guard in the follow-up PR
            if (strpos($route, '/1cc/v1') !== 0 || $route === '/1cc/v1/giftcard/apply')
            {
                continue;
            }

            foreach ($handlers as $handler)
            {
                if (is_string($handler['callback']) === false)
                {
                    continue;
                }

                $this->assertContains($handler['permission_callback'], array('checkHmacSignature', 'rzp1ccCheckRestNonce'), $route);
                $checked++;
            }
        }

        $this->assertSame(count($this->browserRoutes) + count($this->hmacRoutes), $checked);
    }

    public function testBrowserRoutesUseNonceGuard()
    {
        foreach ($this->browserRoutes as $route)
        {
            $this->assertSame('rzp1ccCheckRestNonce', $this->permissionCallback($route), $route);
        }
    }

    public function testRazorpayCalledRoutesUseHmacGuard()
    {
        foreach ($this->hmacRoutes as $route)
        {
            $this->assertSame('checkHmacSignature', $this->permissionCallback($route), $route);
        }
    }

    // ---- Browser routes ----

    public function testBrowserRoutesWithoutNonceAreRejected()
    {
        foreach ($this->browserRoutes as $route)
        {
            $response = rest_get_server()->dispatch($this->post($route, array('order_id' => 'order_ABC')));

            $this->assertEquals(403, $response->get_status(), $route);
        }
    }

    public function testBrowserRoutesWithBadNonceAreRejected()
    {
        foreach ($this->browserRoutes as $route)
        {
            $response = rest_get_server()->dispatch($this->post($route, array('order_id' => 'order_ABC'), array('X-WP-Nonce' => 'not-a-nonce')));

            $this->assertEquals(403, $response->get_status(), $route);
        }
    }

    public function testNonceForAnotherActionIsRejected()
    {
        $request = $this->post('/1cc/v1/cart/fetch-cart', array(), array('X-WP-Nonce' => wp_create_nonce('some_other_action')));

        $this->assertWPError(rzp1ccCheckRestNonce($request));
    }

    public function testValidNoncePassesTheGuard()
    {
        $request = $this->post('/1cc/v1/cart/fetch-cart', array(), array('X-WP-Nonce' => wp_create_nonce('wp_rest')));

        $this->assertTrue(rzp1ccCheckRestNonce($request));
    }
}

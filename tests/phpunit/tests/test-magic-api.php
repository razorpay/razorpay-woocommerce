<?php

require_once __DIR__ . '/../../../includes/api/auth.php';
require_once __DIR__ . '/../../../includes/razorpay-webhook.php';

class Test_Maigc_Api extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        unset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']);
        delete_option('rzp1cc_hmac_secret');
    }

    public function testMagicApi()
    {
        $this->assertFileExists(PLUGIN_DIR . '/includes/api/order.php');

        $this->assertFileExists(PLUGIN_DIR . '/includes/api/coupon-get.php');

        $this->assertFileExists(PLUGIN_DIR . '/includes/api/coupon-apply.php');

        $this->assertFileExists(PLUGIN_DIR . '/includes/api/shipping-info.php');

        $this->assertFileExists(PLUGIN_DIR . '/includes/api/save-abandonment-data.php');
    }

    public function testCheckAuthCredentialsRejectsRequestWithoutNonceOrSignature()
    {
        $request = new WP_REST_Request('POST', '/wp-json/1cc/v1/order/create');

        $result = checkAuthCredentials($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(403, $result->get_error_data()['status']);
    }

    public function testCheckAuthCredentialsAllowsValidRestNonce()
    {
        wp_set_current_user(1);
        $request = new WP_REST_Request('POST', '/wp-json/1cc/v1/order/create');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        $this->assertTrue(checkAuthCredentials($request));
    }

    public function testCheckAuthCredentialsAllowsValidHmacSignature()
    {
        $payload = '{"order_id":"order_test"}';
        $secret = 'test_secret';
        update_option('rzp1cc_hmac_secret', $secret);
        $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] = hash_hmac('sha256', $payload, $secret);

        $request = new WP_REST_Request('POST', '/wp-json/1cc/v1/order/create');
        $request->set_body($payload);
        $request->set_header('X-Razorpay-Signature', $_SERVER['HTTP_X_RAZORPAY_SIGNATURE']);

        $this->assertTrue(checkAuthCredentials($request));
    }

    public function testRazorpayHmacCredentialsRejectsNonceOnlyRequest()
    {
        wp_set_current_user(1);
        $request = new WP_REST_Request('POST', '/wp-json/1cc/v1/shipping/shipping-info');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        $result = checkRazorpayHmacCredentials($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(403, $result->get_error_data()['status']);
    }

    public function testRazorpayHmacCredentialsAllowsValidHmacSignature()
    {
        $payload = '{"order_id":"order_test"}';
        $secret = 'test_secret';
        update_option('rzp1cc_hmac_secret', $secret);
        $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] = hash_hmac('sha256', $payload, $secret);

        $request = new WP_REST_Request('POST', '/wp-json/1cc/v1/shipping/shipping-info');
        $request->set_body($payload);
        $request->set_header('X-Razorpay-Signature', $_SERVER['HTTP_X_RAZORPAY_SIGNATURE']);

        $this->assertTrue(checkRazorpayHmacCredentials($request));
    }

    public function testServerErrorResponseDoesNotLeakExceptionMessage()
    {
        $response = rzp1ccServerErrorResponse('database password leaked');
        $data = $response->get_data();

        $this->assertSame(500, $response->get_status());
        $this->assertSame('WOOCOMMERCE_SERVER_ERROR', $data['code']);
        $this->assertStringNotContainsString('database password leaked', $data['message']);
    }

    public function testSaveWebhookEventAppendsDataUsingExistingQueueRow()
    {
        global $wpdb;

        $tableName = $wpdb->prefix . 'rzp_webhook_requests';
        $wpdb->query("DROP TABLE IF EXISTS $tableName");
        $wpdb->query(
            "CREATE TABLE $tableName (
                `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `integration` varchar(25) NOT NULL,
                `order_id` int(11) NOT NULL,
                `rzp_order_id` varchar(25) NOT NULL,
                `rzp_webhook_data` text,
                `rzp_webhook_notified_at` int(11),
                `rzp_update_order_cron_status` int(11) DEFAULT 0,
                PRIMARY KEY (`id`)) " . $wpdb->get_charset_collate() . ";"
        );

        $wpdb->insert(
            $tableName,
            [
                'integration'                  => 'woocommerce',
                'order_id'                     => 123,
                'rzp_order_id'                 => 'order_test',
                'rzp_webhook_data'             => json_encode([
                    [
                        'woocommerce_order_id' => 123,
                        'razorpay_payment_id'  => 'pay_existing',
                        'event'                => 'payment.authorized',
                    ],
                ]),
                'rzp_update_order_cron_status' => 0,
            ]
        );

        $webhook = (new ReflectionClass(RZP_Webhook::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(RZP_Webhook::class, 'saveWebhookEvent');
        $method->setAccessible(true);

        $method->invoke($webhook, [
            'woocommerce_order_id' => 123,
            'razorpay_payment_id'  => 'pay_test',
            'event'                => 'payment.authorized',
        ], 'order_test');

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT rzp_webhook_data FROM $tableName WHERE order_id = %d AND rzp_order_id = %s", 123, 'order_test')
        );
        $events = json_decode($row->rzp_webhook_data, true);

        $this->assertCount(2, $events);
        $this->assertSame('pay_existing', $events[0]['razorpay_payment_id']);
        $this->assertSame('pay_test', $events[1]['razorpay_payment_id']);
    }
}

<?php

require_once __DIR__ . '/../../../includes/api/auth.php';
require_once __DIR__ . '/../../../includes/razorpay-webhook.php';

class Test_Maigc_Api extends WP_UnitTestCase
{
    public function testMagicApi()
    {
        $this->assertFileExists(PLUGIN_DIR . '/includes/api/order.php');

        $this->assertFileExists(PLUGIN_DIR . '/includes/api/coupon-get.php');

        $this->assertFileExists(PLUGIN_DIR . '/includes/api/coupon-apply.php');

        $this->assertFileExists(PLUGIN_DIR . '/includes/api/shipping-info.php');

        $this->assertFileExists(PLUGIN_DIR . '/includes/api/save-abandonment-data.php');
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

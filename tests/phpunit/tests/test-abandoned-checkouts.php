<?php

require_once __DIR__ . '/../../../includes/support/abandoned-checkouts.php';

/**
 * @covers ::rzpSaveAbandonedCheckout
 * @covers ::rzpMarkAbandonedCheckoutRecovered
 * @covers ::rzpDeleteAbandonedCheckouts
 */

class Test_Abandoned_Checkouts extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        global $wpdb;

        rzpCreateAbandonedCheckoutsTable();

        $wpdb->query('DELETE FROM ' . rzpGetAbandonedCheckoutsTableName());

        parent::setUp();
    }

    protected function tearDown(): void
    {
        global $wpdb;

        $wpdb->query('DELETE FROM ' . rzpGetAbandonedCheckoutsTableName());

        parent::tearDown();
    }

    protected function createTestOrder()
    {
        $product = new WC_Product_Simple();
        $product->set_name('Test Product');
        $product->set_regular_price('500');
        $product->save();

        $order = wc_create_order();

        $order->add_product(wc_get_product($product->get_id()), 2);
        $order->set_billing_email('wc-customer@example.com');
        $order->set_billing_first_name('WC');
        $order->set_billing_last_name('Customer');
        $order->set_billing_phone('918888888888');
        $order->calculate_totals();
        $order->save();

        return $order;
    }

    protected function buildRazorpayData($wcOrderId)
    {
        return array(
            'id'      => 'order_test_abandoned_1',
            'receipt' => (string) $wcOrderId,
            'amount'  => 50000,
            'currency' => 'INR',
            'customer_details' => array(
                'email'   => 'customer@example.com',
                'contact' => '919999999999',
                'shipping_address' => array(
                    'name'    => 'Test Customer',
                    'line1'   => 'MG Road',
                    'city'    => 'Bengaluru',
                    'state'   => 'Karnataka',
                    'country' => 'IN',
                    'zipcode' => '560001',
                ),
                'billing_address' => array(
                    'name' => 'Test Customer',
                ),
            ),
        );
    }

    public function testSaveAbandonedCheckoutInsertsRecord()
    {
        global $wpdb;

        $order = $this->createTestOrder();

        $result = rzpSaveAbandonedCheckout($this->buildRazorpayData($order->get_id()));

        $tableName = rzpGetAbandonedCheckoutsTableName();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $tableName WHERE rzp_order_id = %s", 'order_test_abandoned_1'),
            ARRAY_A
        );

        $this->assertTrue($result['status']);
        $this->assertNotNull($row);
        $this->assertSame('abandoned', $row['status']);
        $this->assertSame((string) $order->get_id(), $row['wc_order_id']);
        $this->assertSame('customer@example.com', $row['customer_email']);
        $this->assertSame('919999999999', $row['customer_phone']);
        $this->assertSame('Test Customer', $row['customer_name']);
        $this->assertSame('INR', $row['currency']);
        $this->assertEquals(500, (float) $row['cart_total']);

        $cartItems = json_decode($row['cart_items'], true);

        $this->assertCount(1, $cartItems);
        $this->assertSame(2, (int) $cartItems[0]['quantity']);

        $customerDetails = json_decode($row['customer_details'], true);

        $this->assertSame('Bengaluru', $customerDetails['shipping']['city']);
    }

    public function testSaveAbandonedCheckoutUpdatesExistingRecord()
    {
        global $wpdb;

        $order = $this->createTestOrder();
        $razorpayData = $this->buildRazorpayData($order->get_id());

        rzpSaveAbandonedCheckout($razorpayData);

        $razorpayData['customer_details']['email'] = 'updated@example.com';

        $result = rzpSaveAbandonedCheckout($razorpayData);

        $tableName = rzpGetAbandonedCheckoutsTableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $tableName WHERE rzp_order_id = %s", 'order_test_abandoned_1'),
            ARRAY_A
        );

        $this->assertTrue($result['status']);
        $this->assertCount(1, $rows);
        $this->assertSame('updated@example.com', $rows[0]['customer_email']);
    }

    public function testSaveAbandonedCheckoutWithoutContactDetailsFallsBackToOrder()
    {
        global $wpdb;

        $order = $this->createTestOrder();

        $razorpayData = $this->buildRazorpayData($order->get_id());
        unset($razorpayData['customer_details']['email']);
        unset($razorpayData['customer_details']['contact']);
        unset($razorpayData['customer_details']['shipping_address']['name']);
        unset($razorpayData['customer_details']['billing_address']['name']);

        $result = rzpSaveAbandonedCheckout($razorpayData);

        $tableName = rzpGetAbandonedCheckoutsTableName();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $tableName WHERE rzp_order_id = %s", 'order_test_abandoned_1'),
            ARRAY_A
        );

        $this->assertTrue($result['status']);
        $this->assertSame('wc-customer@example.com', $row['customer_email']);
        $this->assertSame('918888888888', $row['customer_phone']);
        $this->assertSame('WC Customer', $row['customer_name']);
    }

    public function testSaveAbandonedCheckoutFailsWithoutOrderId()
    {
        $result = rzpSaveAbandonedCheckout(array('amount' => 10000));

        $this->assertFalse($result['status']);
    }

    public function testMarkAbandonedCheckoutRecoveredUpdatesStatus()
    {
        global $wpdb;

        $order = $this->createTestOrder();

        rzpSaveAbandonedCheckout($this->buildRazorpayData($order->get_id()));

        rzpMarkAbandonedCheckoutRecovered($order->get_id());

        $tableName = rzpGetAbandonedCheckoutsTableName();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $tableName WHERE wc_order_id = %d", $order->get_id()),
            ARRAY_A
        );

        $this->assertSame('recovered', $row['status']);
        $this->assertNotEmpty($row['recovered_at']);
    }

    public function testMarkAbandonedCheckoutRecoveredKeepsRecoveredStatus()
    {
        global $wpdb;

        $order = $this->createTestOrder();

        rzpSaveAbandonedCheckout($this->buildRazorpayData($order->get_id()));
        rzpMarkAbandonedCheckoutRecovered($order->get_id());

        $recoveredAt = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT recovered_at FROM " . rzpGetAbandonedCheckoutsTableName() . " WHERE wc_order_id = %d",
                $order->get_id()
            )
        );

        $this->assertNotEmpty($recoveredAt);

        rzpMarkAbandonedCheckoutRecovered($order->get_id());

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . rzpGetAbandonedCheckoutsTableName() . " WHERE wc_order_id = %d", $order->get_id()),
            ARRAY_A
        );

        $this->assertSame('recovered', $row['status']);
        $this->assertSame($recoveredAt, $row['recovered_at']);
    }

    public function testDeleteAbandonedCheckouts()
    {
        global $wpdb;

        $order = $this->createTestOrder();

        rzpSaveAbandonedCheckout($this->buildRazorpayData($order->get_id()));

        $tableName = rzpGetAbandonedCheckoutsTableName();

        $id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM $tableName WHERE rzp_order_id = %s", 'order_test_abandoned_1')
        );

        rzpDeleteAbandonedCheckouts(array($id));

        $count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $tableName WHERE rzp_order_id = %s", 'order_test_abandoned_1')
        );

        $this->assertSame(0, $count);
    }
}

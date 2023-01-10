<?php

require_once __DIR__ . '/../mockfactory/Request.php';
require_once __DIR__ . '/../../../includes/plugin-instrumentation.php';

use Razorpay\MockApi\MockApi;

class Test_Instrumentation extends WP_UnitTestCase
{
    private $rzpInstrumentation;

    public function setup(): void
    {
        parent::setup();
        $this->rzpInstrumentation = Mockery::mock('TrackPluginInstrumentation',
            ['key_id', 'key_secret'])->makePartial()->shouldAllowMockingProtectedMethods();
    }

    public function testHooks()
    {
        $this->assertSame(10, has_action('activate_' . basename(PLUGIN_DIR) .'/woo-razorpay.php',
            'razorpayPluginActivated'));
        $this->assertSame(10, has_action('deactivate_' . basename(PLUGIN_DIR) .'/woo-razorpay.php',
            'razorpayPluginDeactivated'));
        $this->assertSame(10, has_action('upgrader_process_complete', 'razorpayPluginUpgraded'));
    }

    public function testInstrumentationSegment()
    {
        $this->rzpInstrumentation->api = new MockApi('key_id', 'key_secret');
        $response = $this->rzpInstrumentation->rzpTrackSegment('testing', ['key' => 'value']);
        $this->assertSame('success', $response['status']);
    }

    public function testInstrumentationDataLake()
    {
        $response = $this->rzpInstrumentation->rzpTrackDataLake('testing', ['key' => 'value']);
        $this->assertSame(200, $response['response']['code']);
    }

    public function testGetDefaultPropertiesWithTimeStamp()
    {
        $response = $this->rzpInstrumentation->getDefaultProperties();

        $this->assertSame('WordPress', $response['platform']);
        $this->assertNotNull($response['platform_version']);
        $this->assertNotNull($response['woocommerce_version']);
        $this->assertSame('Razorpay for WooCommerce', $response['plugin_name']);
        $this->assertNotNull($response['plugin_version']);
        $this->assertNotNull($response['unique_id']);
        $this->assertNotNull($response['event_timestamp']);
    }
}

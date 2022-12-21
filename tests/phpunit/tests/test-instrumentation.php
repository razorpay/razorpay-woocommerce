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
        $this->assertSame(10, has_action('activate_' . basename(PLUGIN_DIR) .'/woo-razorpay.php', 'razorpayPluginActivated'));
        $this->assertSame(10, has_action('deactivate_' . basename(PLUGIN_DIR) .'/woo-razorpay.php', 'razorpayPluginDeactivated'));
        $this->assertSame(10, has_action('upgrader_process_complete', 'razorpayPluginUpgraded'));
    }

    public function testInstrumentationSegment()
    {
        $this->rzpInstrumentation->api = new MockApi('key_id', 'key_secret');

        $response = $this->rzpInstrumentation->rzpTrackSegment('event',
            ['key' => 'value']
        );

        $this->assertSame('success', $response['status']);
    }
}

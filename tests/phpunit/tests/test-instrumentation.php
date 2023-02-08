<?php

require_once __DIR__ . '/../mockfactory/Request.php';
require_once __DIR__ . '/../../../includes/plugin-instrumentation.php';

use Razorpay\MockApi\MockApi;

class Test_Instrumentation extends WP_UnitTestCase
{
    private $instrumentationMock;
    private $rzpInstrumentationObj;
    private $instance;

    public function setup(): void
    {
        parent::setup();
        $_POST = array();

        $api = new MockApi('key_id', 'key_secret');
        $this->instrumentationMock = Mockery::mock('TrackPluginInstrumentation',
            [$api, 'key_id'])->makePartial()->shouldAllowMockingProtectedMethods();

        $this->rzpInstrumentationObj = new TrackPluginInstrumentation($api, 'key_id');

        $this->instance = Mockery::mock('WC_Razorpay')->makePartial()->shouldAllowMockingProtectedMethods();
    }

    public function testHooks()
    {
        $this->assertSame(10, has_action('activate_' . basename(PLUGIN_DIR) . '/woo-razorpay.php',
            [$this->rzpInstrumentationObj, 'razorpayPluginActivated']));
        $this->assertSame(10, has_action('deactivate_' . basename(PLUGIN_DIR) . '/woo-razorpay.php',
            [$this->rzpInstrumentationObj, 'razorpayPluginDeactivated']));
        $this->assertSame(10, has_action('upgrader_process_complete',
            [$this->rzpInstrumentationObj, 'razorpayPluginUpgraded']));
    }

    public function testInstrmentationInvalidKeySecret()
    {
        $errorLogTmpfile = tmpfile();
        $errorLogLocationBackup = ini_set('error_log', stream_get_meta_data($errorLogTmpfile)['uri']);
        $this->instance->pluginInstrumentation();
        ini_set('error_log', $errorLogLocationBackup);
        $result = stream_get_contents($errorLogTmpfile);

        $this->assertStringContainsString('Key Id and Key Secret are required.', $result);
    }

    public function testInstrumentationSavingAuthDetailsAndPluginEnableAndPluginEnabled()
    {
        $this->expectNotToPerformAssertions();

        $_POST['woocommerce_razorpay_key_id'] = 'key_id';
        $_POST['woocommerce_razorpay_key_secret'] = 'key_secret';
        $_POST['woocommerce_razorpay_enabled'] = 'yes';
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $_SERVER['HTTP_HOST'] = 'razorpay.com';
        $_SERVER['REQUEST_URI'] = 'wc-settings&tab=checkout=razorpay';

        $this->instance->shouldReceive('getSetting')->andReturnUsing(function ($key) {
            if ($key === 'enabled')
            {
                return 'no';
            }
        });

        $this->instrumentationMock->shouldReceive('rzpTrackSegment')->with('event', [])->andReturn('success');
        $this->instrumentationMock->shouldReceive('rzpTrackDataLake')->with('event', [])->andReturn('success');

        $this->instance->shouldReceive('newTrackPluginInstrumentation')->with('key_id', 'key_secret')->andReturnUsing(
            function () {
                return $this->instrumentationMock;
            });

        $this->instance->pluginInstrumentation();
    }

    public function testInstrumentationUpdatingAuthDetailsAndPluginDisabled()
    {
        $this->expectNotToPerformAssertions();

        $_POST['woocommerce_razorpay_key_id'] = 'key_id';
        $_POST['woocommerce_razorpay_key_secret'] = 'key_secret';
        $_POST['woocommerce_razorpay_enabled'] = '';
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $_SERVER['HTTP_HOST'] = 'razorpay.com';
        $_SERVER['REQUEST_URI'] = 'wc-settings&tab=checkout=razorpay';

        $this->instance->shouldReceive('getSetting')->andReturnUsing(function ($key) {
            if ($key === 'key_id')
            {
                return 'key_id';
            }
            else if ($key === 'key_secret')
            {
                return 'key_secret';
            }
            else if ($key === 'enabled')
            {
                return 'yes';
            }
        });

        $this->instrumentationMock->shouldReceive('rzpTrackSegment')->with('event', [])->andReturn('success');
        $this->instrumentationMock->shouldReceive('rzpTrackDataLake')->with('event', [])->andReturn('success');

        $this->instance->shouldReceive('newTrackPluginInstrumentation')->with('key_id', 'key_secret')->andReturnUsing(
            function () {
                return $this->instrumentationMock;
            });

        $this->instance->pluginInstrumentation();
    }

    public function testNewTrackPluginInstrumentation()
    {
        $response = $this->instance->newTrackPluginInstrumentation('key_id', 'key_secret');

        $this->assertInstanceOf('TrackPluginInstrumentation', $response);
    }

    public function testTriggerValidationInstrumentation()
    {
        $this->instance->shouldReceive('newTrackPluginInstrumentation')->andReturnUsing(function ()
        {
            $mockObj = Mockery::mock('stdClass')->makePartial();
            $mockObj->shouldReceive('rzpTrackSegment')->andReturn(null);
            $mockObj->shouldReceive('rzpTrackDataLake')->andReturn(null);

            return $mockObj;
        });

        $response  = $this->instance->triggerValidationInstrumentation([]);

        $this->assertNull($response);
    }

    public function testInstrumentationPluginActivated()
    {
        $_SERVER['HTTP_REFERER'] = 'razorpay.com';
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $_SERVER['HTTP_HOST'] = 'razorpay.com';
        $_SERVER['REQUEST_URI'] = 'wc-settings';

        $this->instrumentationMock->shouldReceive('rzpTrackSegment')->with('event', []);
        $this->instrumentationMock->shouldReceive('rzpTrackDataLake')->with('event', []);
        $response = $this->instrumentationMock->razorpayPluginActivated();

        $this->assertSame('success', $response);
    }

    public function testInstrumentationPluginDeactivated()
    {
        $_SERVER['HTTP_REFERER'] = 'razorpay.com';

        $this->instrumentationMock->shouldReceive('rzpTrackSegment')->with('event', []);
        $this->instrumentationMock->shouldReceive('rzpTrackDataLake')->with('event', []);
        $response = $this->instrumentationMock->razorpayPluginDeactivated();

        $this->assertSame('success', $response);
    }

    public function testInstrumentationPluginUpgraded()
    {
        $_SERVER['HTTP_REFERER'] = 'razorpay.com';

        $this->instrumentationMock->shouldReceive('rzpTrackSegment')->with('event', []);
        $this->instrumentationMock->shouldReceive('rzpTrackDataLake')->with('event', []);
        $response = $this->instrumentationMock->razorpayPluginUpgraded();

        $this->assertSame('success', $response);
    }

    public function testInstrumentationSegment()
    {
        $response = $this->instrumentationMock->rzpTrackSegment('testing', ['key' => 'value']);
        $this->assertSame('success', $response['status']);
    }

    public function testInstrumentationDataLake()
    {
        $response = $this->instrumentationMock->rzpTrackDataLake('testing', ['key' => 'value']);
        $this->assertSame(200, $response['response']['code']);
    }

    public function testGetDefaultPropertiesWithTimeStamp()
    {
        $response = $this->instrumentationMock->getDefaultProperties();

        $this->assertSame('WordPress', $response['platform']);
        $this->assertNotNull($response['platform_version']);
        $this->assertNotNull($response['woocommerce_version']);
        $this->assertSame('Razorpay for WooCommerce', $response['plugin_name']);
        $this->assertNotNull($response['plugin_version']);
        $this->assertNotNull($response['unique_id']);
        $this->assertNotNull($response['event_timestamp']);
    }
}

<?php

/**
 * @covers \WC_Razorpay::fetch1ccMerchantPreferences
 */
class Test_1cc_Preferences extends WP_UnitTestCase
{
    private $instance;
    private $httpCalls = array();

    public function setup(): void
    {
        parent::setup();
        $this->instance = Mockery::mock('WC_Razorpay')->makePartial()->shouldAllowMockingProtectedMethods();
        $this->instance->shouldReceive('getSetting')->andReturnUsing(function ($key) {
            return $key === 'key_id' ? 'rzp_test_key' : 'secret';
        });
        $this->httpCalls = array();
    }

    public function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        parent::tearDown();
    }

    private function stubHttp($response)
    {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($response) {
            $this->httpCalls[] = array('url' => $url, 'args' => $args);
            return $response;
        }, 10, 3);
    }

    private function legacyApiReturning($preferences)
    {
        $request = Mockery::mock();
        $request->shouldReceive('request')->with('GET', 'merchant/1cc_preferences')->andReturn($preferences);
        $api = new stdClass();
        $api->request = $request;
        return $api;
    }

    public function testUsesNewMcsUrlWithShortTimeoutAndBasicAuth()
    {
        $body = array('mode' => 'live', 'features' => array('one_click_checkout' => true));
        $this->stubHttp(array('response' => array('code' => 200), 'body' => json_encode($body)));
        $this->instance->shouldNotReceive('getRazorpayApiInstance');

        $this->assertSame($body, $this->instance->fetch1ccMerchantPreferences());

        $this->assertCount(1, $this->httpCalls);
        $this->assertStringEndsWith('/v1/magic/merchant/1cc_preferences', $this->httpCalls[0]['url']);
        $this->assertSame(WC_Razorpay::ONE_CC_MERCHANT_PREF_TIMEOUT, $this->httpCalls[0]['args']['timeout']);
        $this->assertSame(
            'Basic ' . base64_encode('rzp_test_key:secret'),
            $this->httpCalls[0]['args']['headers']['Authorization']
        );
    }

    public function testFallsBackToLegacyRouteOnTransportError()
    {
        $legacy = array('mode' => 'live');
        $this->stubHttp(new WP_Error('http_request_failed', 'timeout'));
        $this->instance->shouldReceive('getRazorpayApiInstance')->once()->andReturn($this->legacyApiReturning($legacy));

        $this->assertSame($legacy, $this->instance->fetch1ccMerchantPreferences());
    }

    public function testFallsBackToLegacyRouteOnNon200()
    {
        $legacy = array('mode' => 'live', 'features' => array('one_cc_store_account' => true));
        $this->stubHttp(array('response' => array('code' => 503), 'body' => '{"error":{}}'));
        $this->instance->shouldReceive('getRazorpayApiInstance')->once()->andReturn($this->legacyApiReturning($legacy));

        $this->assertSame($legacy, $this->instance->fetch1ccMerchantPreferences());
    }

    public function testFallsBackToLegacyRouteOnInvalidJson()
    {
        $legacy = array('mode' => 'test');
        $this->stubHttp(array('response' => array('code' => 200), 'body' => 'not json'));
        $this->instance->shouldReceive('getRazorpayApiInstance')->once()->andReturn($this->legacyApiReturning($legacy));

        $this->assertSame($legacy, $this->instance->fetch1ccMerchantPreferences());
    }
}

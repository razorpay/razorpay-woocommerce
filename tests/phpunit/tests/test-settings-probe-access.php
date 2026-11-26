<?php

/**
 * The feature probe inside init_form_fields (Razorpay API calls that rewrite rzp_afd_enable and
 * rzp_rtb_enable) must only run for a store manager. The gateway is also constructed for anonymous
 * requests to admin-post.php (the webhook URL), where is_admin() is true.
 */
class Test_Settings_Probe_Access extends WP_UnitTestCase
{
    protected $backupGlobals = false;

    private $instance;

    private $apiCalls;

    public function setUp(): void
    {
        parent::setUp();

        $this->apiCalls = new ArrayObject();
        $apiCalls       = $this->apiCalls;

        $api          = new stdClass();
        $api->request = new class($apiCalls)
        {
            private $calls;

            public function __construct($calls)
            {
                $this->calls = $calls;
            }

            public function request($method, $url)
            {
                $this->calls[] = $url;

                // The account behind these keys has neither widget
                return array('enabled' => false, 'widget_enabled' => false);
            }
        };

        $this->instance = Mockery::mock('WC_Razorpay')->makePartial()->shouldAllowMockingProtectedMethods();
        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturn($api);
        $this->instance->shouldReceive('getSetting')->with('key_id')->andReturn('rzp_test_unitKey');
        $this->instance->shouldReceive('getSetting')->andReturn('');

        // Same state as an anonymous POST to admin-post.php?action=rzp_wc_webhook&section=razorpay
        set_current_screen('admin-post');
        $_GET['section'] = 'razorpay';

        update_option('rzp_afd_enable', 'yes');
        update_option('rzp_rtb_enable', 'yes');
    }

    public function tearDown(): void
    {
        unset($_GET['section'], $GLOBALS['current_screen']);
        $_POST = array();
        delete_option('rzp_afd_enable');
        delete_option('rzp_rtb_enable');
        parent::tearDown();
    }

    public function testAnonymousRequestDoesNotRunTheProbe()
    {
        wp_set_current_user(0);

        $this->assertTrue(is_admin());

        $this->instance->init_form_fields();

        $this->assertCount(0, $this->apiCalls);
        $this->assertSame('yes', get_option('rzp_afd_enable'));
        $this->assertSame('yes', get_option('rzp_rtb_enable'));
    }

    public function testAnonymousRequestWithPostedKeysDoesNotRunTheProbe()
    {
        wp_set_current_user(0);

        $_POST['woocommerce_razorpay_key_id']     = 'rzp_test_attacker';
        $_POST['woocommerce_razorpay_key_secret'] = 'attackerSecret';

        // Fails the test if the probe tries to reach Razorpay with the posted keys
        add_filter('pre_http_request', function ()
        {
            $this->apiCalls[] = 'http';

            return new WP_Error('blocked', 'no network in tests');
        });

        $this->instance->init_form_fields();

        remove_all_filters('pre_http_request');

        $this->assertCount(0, $this->apiCalls);
        $this->assertSame('yes', get_option('rzp_afd_enable'));
        $this->assertSame('yes', get_option('rzp_rtb_enable'));
    }

    public function testCustomerAccountDoesNotRunTheProbe()
    {
        wp_set_current_user(self::factory()->user->create(array('role' => 'subscriber')));

        $this->instance->init_form_fields();

        $this->assertCount(0, $this->apiCalls);
        $this->assertSame('yes', get_option('rzp_afd_enable'));
        $this->assertSame('yes', get_option('rzp_rtb_enable'));
    }

    public function testStoreManagerStillRunsTheProbe()
    {
        $userId = self::factory()->user->create(array('role' => 'administrator'));
        get_user_by('id', $userId)->add_cap('manage_woocommerce');
        wp_set_current_user($userId);

        $this->instance->init_form_fields();

        $this->assertCount(3, $this->apiCalls);
        $this->assertSame('no', get_option('rzp_afd_enable'));
        $this->assertSame('no', get_option('rzp_rtb_enable'));
    }

    public function testFormFieldsAreStillBuiltForAnonymousRequests()
    {
        wp_set_current_user(0);

        $this->instance->init_form_fields();

        $this->assertArrayHasKey('key_id', $this->instance->form_fields);
        $this->assertArrayHasKey('key_secret', $this->instance->form_fields);
    }
}

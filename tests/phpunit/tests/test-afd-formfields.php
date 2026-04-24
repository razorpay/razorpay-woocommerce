<?php

use Razorpay\MockApi\MockApi;

class Test_AfdFormFields extends WP_UnitTestCase
{
    protected $backupGlobals = FALSE;

    public $instance;

    public function setup():void
    {
        parent::setup();

        $this->instance = Mockery::mock('WC_Razorpay')->makePartial()->shouldAllowMockingProtectedMethods();
    }

    public function testinitformfields()
    {
        $GLOBALS['current_screen'] = new Globals();

        $this->instance->shouldReceive('getRazorpayApiInstance')->andReturnUsing(function () {
            return new MockApi('key_id_2', 'key_secret2');
        });

        $this->instance->init_form_fields();

        $this->assertTrue(has_action('woocommerce_sections_checkout'));
    }
}

class Globals 
{
    public function in_admin()
    {
        return true;
    }
}

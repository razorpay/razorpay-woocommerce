<?php

class Test_Class_Objects extends WP_UnitTestCase
{
    public $razorpayTests;

    public function setup(): void
    {
        parent::setup();
        $this->razorpayTests = new WC_Razorpay();
    }

    public function testClassInstance()
    {
        $this->assertInstanceOf('WC_Razorpay', $this->razorpayTests);
    }

    public function testRazorpayIcon()
    {
        $icon = curl_init($this->razorpayTests->icon);

        curl_setopt($icon, CURLOPT_NOBODY, true);
        curl_exec($icon);
        $this->assertEquals(200, curl_getinfo($icon, CURLINFO_HTTP_CODE));
        curl_close($icon);
    }

    public function testPropertiesNotEmpty()
    {
        $this->assertNotEmpty($this->razorpayTests->icon);
        $this->assertNotEmpty($this->razorpayTests->method_description);
        $this->assertSame('Razorpay', $this->razorpayTests->method_title);
        $this->assertArrayHasKey('key_id', $this->razorpayTests->form_fields);
        $this->assertArrayHasKey('key_secret', $this->razorpayTests->form_fields);
        $this->assertArrayHasKey('payment_action', $this->razorpayTests->form_fields);
    }
}

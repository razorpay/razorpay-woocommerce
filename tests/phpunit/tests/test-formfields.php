<?php

class Test_FormFields extends WP_UnitTestCase
{
    public $razorpayTests;

    public function setup():void
    {
        parent::setup();
        $this->razorpayTests = new WC_Razorpay();
    }

    public function testformfields()
    {
        $form_fields = $this->razorpayTests->{"form_fields"};

        $this->assertSame('Key ID', $form_fields['key_id']['title']);

        $this->assertSame('Key Secret', $form_fields['key_secret']['title']);

        $this->assertSame('Payment Action', $form_fields['payment_action']['title']);

        $this->assertSame('Enable/Disable', $form_fields['enabled']['title']);
    }
}

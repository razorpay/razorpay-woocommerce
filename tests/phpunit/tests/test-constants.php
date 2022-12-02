<?php

class Test_Constants extends WP_UnitTestCase
{
    public $razorpayTests;

    public function setup():void
    {
        parent::setup();
        $this->razorpayTests = new WC_Razorpay();
    }

    public function testPluginConstants()
    {
        $this->assertSame('razorpay_wc_order_id', $this->razorpayTests::SESSION_KEY);

        $this->assertSame('razorpay_order_id_1cc', $this->razorpayTests::RAZORPAY_ORDER_ID_1CC);

        $this->assertSame('razorpay_payment_id', $this->razorpayTests::RAZORPAY_PAYMENT_ID);

        $this->assertSame('razorpay_order_id', $this->razorpayTests::RAZORPAY_ORDER_ID);

        $this->assertSame('razorpay_signature', $this->razorpayTests::RAZORPAY_SIGNATURE);

        $this->assertSame('razorpay_wc_form_submit', $this->razorpayTests::RAZORPAY_WC_FORM_SUBMIT);

        $this->assertSame('INR', $this->razorpayTests::INR);

        $this->assertSame('capture', $this->razorpayTests::CAPTURE);

        $this->assertSame('authorize', $this->razorpayTests::AUTHORIZE);

        $this->assertSame('woocommerce_order_id', $this->razorpayTests::WC_ORDER_ID);

        $this->assertSame('woocommerce_order_number', $this->razorpayTests::WC_ORDER_NUMBER);

        $this->assertSame('Credit Card/Debit Card/NetBanking', $this->razorpayTests::DEFAULT_LABEL);

        $this->assertSame('Pay securely by Credit or Debit card or Internet Banking through Razorpay.', $this->razorpayTests::DEFAULT_DESCRIPTION);

        $this->assertSame('Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be processing your order soon.', $this->razorpayTests::DEFAULT_SUCCESS_MESSAGE);
    }
}
